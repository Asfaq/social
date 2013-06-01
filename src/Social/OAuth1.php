<?php
/**
 * Base class for OAUth1 connection.
 * 
 * @license MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social;

/**
 * OAUth1 connection.
 */
abstract class OAuth1 extends Connection
{
    /**
     * Application's consumer key
     * @var string
     */
    protected $consumerKey;

    /**
     * Application's consumer secret
     * @var string
     */
    protected $consumerSecret;
    
    
    /**
     * User's access token
     * @var string
     */
    protected $accessToken;

    /**
     * User's access token secret
     * @var string
     */
    protected $accessSecret;
    
    
    /**
     * Class constructor.
     * 
     * @param string        $consumerKey     Application's consumer key
     * @param string        $consumerSecret  Application's consumer secret
     * @param string|object $access          User's access token or { 'token': string, 'secret': string }
     * @param string        $accessSecret    User's access token secret (supply if $access is a string)
     */
    public function __construct($consumerKey, $consumerSecret, $access=null, $accessSecret=null)
    {
        $this->consumerKey = $consumerKey;
        $this->consumerSecret = $consumerSecret;
        
        if ($access === $_SESSION) {
            $this->authUseSession = true;
            $key = $this->getAuthParam();
            $access = @@$_SESSION[$key];
        }
        
        if (is_array($access)) $access = (object)$access;
        if (is_object($access)) {
            $this->accessToken = $access->token;
            $this->accessSecret = $access->secret;
        } else {
            $this->accessToken = $access;
            $this->accessSecret = $accessSecret;
        }
    }
    
    /**
     * Create a new  connection using the specified access token.
     * 
     * @param string|object $access        User's access token or { 'token': string, 'secret': string }
     * @param int           $accessSecret  User's access token secret (supply if $access is a string)
     */
    public function asUser($access, $accessSecret=null)
    {
        return new static($this->appId, $this->appSecret, $access, $accessSecret);
    }
    
    
    /**
     * Get the application's consumer key.
     * 
     * @return string
     */
    public function getConsumerKey()
    {
        return $this->consumerKey;
    }
    
    /**
     * Get user's access token.
     * 
     * @return string 
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }
    
    /**
     * Get user's access token secret.
     * 
     * @return string 
     */
    public function getAccessSecret()
    {
        return $this->accessSecret;
    }
    
    /**
     * Get the user's access info.
     *
     * @return object  { 'token': token, 'secret': secret }
     */
    public function getAccessInfo()
    {
        if (!isset($this->accessToken)) return null;
        
        $access = (object)array('token' => $this->accessToken, 'secret' => $this->accessSecret);
        return $access;
    }
    
    
    /**
     * Generate a unique oAuth nonce.
     * 
     * @return string
     */
    protected function getNonce()
    {
        return md5(uniqid());
    }

    /**
     * Generate oAuth signature.
     * 
     * @param string $method  Request type: GET, POST or DELETE 
     * @param string $url
     * @param array  $params  Request paramaters + oAuth parameters
     */
    protected function getOAuthSignature($method, $url, array $params)
    {
        // Extract additional paramaters from the URL
        if (strpos($url, '?') !== false) {
            list($url, $query) = explode('?', $url, 2);
            $query_params = null;
            parse_str($query, $query_params);
            $params += $query_params;
        }
        
        $url = $this->processPlaceholders($url, $params);

        // Sign
        $user_secret = isset($params['oauth_token_secret']) ? $params['oauth_token_secret'] : $this->accessSecret;
        unset($params['oauth_token_secret']);

        ksort($params);
        
        $base_string = strtoupper($method) . '&' . rawurlencode($url) . '&' . rawurlencode(self::buildHttpQuery($params));
        $signing_key = rawurlencode($this->consumerSecret) . '&' . rawurlencode($user_secret);

        return base64_encode(hash_hmac('sha1', $base_string, $signing_key, true));
    }
    
    /**
     * Get Authentication header.
     * 
     * @param string $method  GET, POST or DELETE
     * @param string $url
     * @param array  $params  Request parameters
     * @param array  $oauth   Additional/Alternative oAuth values
     * @return string
     */
    protected function getAuthorizationHeader($method, $url, $params, array $oauth=array())
    {
        $oauth += array(
          'oauth_consumer_key' => $this->consumerKey,
          'oauth_nonce' => $this->getNonce(),
          'oauth_signature_method' => "HMAC-SHA1",
          'oauth_timestamp' => time(),
          'oauth_version' => "1.0"
        );
        
        if (isset($this->accessToken) && !isset($oauth['oauth_token'])) $oauth['oauth_token'] = $this->accessToken;
        $oauth['oauth_signature'] = $this->getOAuthSignature($method, $url, $params + $oauth);
        
        unset($oauth['oauth_token_secret']);
        ksort($oauth);
        
        $parts = array();
        foreach ($oauth as $key=>$value) {
            $parts[] = $key . '="' . rawurlencode($value) . '"';
        }

        return 'OAuth ' . join(', ', $parts);
    }
    
    
    /**
     * Do an HTTP request.
     * 
     * @param string   $method   GET, POST or DELETE
     * @param string   $url
     * @param array    $params   Request parameters
     * @param array    $headers  Additional HTTP headers + additional oauth params
     * @param callback $writefunction  Stream content to this function, instead of returning it as result
     */
    protected function httpRequest($method, $url, $params=null, array $headers=array(), $writefunction=null)
    {
        $url = $this->processPlaceholders($url, $params);
        
        $multipart = $method == 'POST' && isset($headers['Content-Type']) && $headers['Content-Type'] == 'multipart/form-data';
        if ($multipart) $url = preg_replace('/\?.*$/', '', $url);
        
        $oauth = isset($headers['oauth']) ? $headers['oauth'] : array();
        unset($headers['oauth']);
        $headers['Authorization'] = $this->getAuthorizationHeader($method, $this->getUrl($url), !$multipart ? $params : array(), $oauth);
        
        return parent::httpRequest($method, $url, $params, $headers, $writefunction);
    }
    
    /**
     * Run multiple HTTP requests in parallel.
     * 
     * @param array $requests  Array of value objects { 'method': string, 'url': string, 'params': array, 'headers': array }
     * @return array
     */
    protected function httpMultiRequest(array $requests)
    {
        foreach ($requests as &$request) {
            if (is_array($request)) $request = (object)$request;
            
            $multipart = $request->method == 'POST' && isset($request->headers['Content-Type']) && $request->headers['Content-Type'] == 'multipart/form-data';
            $url = $multipart ? preg_replace('/\?.*$/', '', $request->url) : $request->url;
            
            $oauth = isset($request->headers['oauth']) ? $request->headers['oauth'] : array();
            unset($request->headers['oauth']);
            $request->headers['Authorization'] = $this->getAuthorizationHeader($request->method, $this->getUrl($url), !$multipart ? $request->params : array(), $oauth);
        }
        
        return parent::httpMultiRequest($requests);
    }
    
    
    /**
     * Get the GET parameter used for authentication.
     * 
     * @return string
     */
    abstract protected function getAuthParam();

    /**
     * Get authentication url.
     * Temporary accesss information is automatically stored to a session.
     *
     * @param int    $level      'authorize', 'authenticate'
     * @param string $returnUrl  The URL to return to after successfully authenticating.
     * @param object $access     Will be filled with the temporary access information.
     * @return string
     */
    public function getAuthUrl($level='authenticate', $returnUrl=null, &$tmpAccess=null)
    {
        if (!isset($returnUrl)) {
            $returnUrl = $this->getCurrentUrl($returnUrl, array($this->getAuthParam() => 'auth'));
            if (!isset($returnUrl)) throw new Exception("Unable to determine the redirect URL, please specify it.");
        }

        $response = $this->httpRequest('POST', 'oauth/request_token', array(), array('oauth'=>array('oauth_callback' => $returnUrl)));
        parse_str($response, $tmpAccess);
        
        $_SESSION[$this->getAuthParam() . ':tmp_access'] = $tmpAccess;
        
        return $this->getUrl('oauth/' . $level, array('oauth_token' => $tmpAccess['oauth_token']));
    }
    
    /**
     * Handle an authentication response and sets the access token.
     * If $oauthVerifier is omitted, it is taken from $_GET.
     * If $tmpAccess is omitted, it is taken from the session.
     * 
     * @param string $oauthVerifier  Returned oauth_verifier generated by Twitter.
     * @param object $tmpAccess      Temp access information.
     */
    public function handleAuthResponse($oauthVerifier=null, $tmpAccess=null)
    {
        if (!isset($oauthVerifier)) {
            if (!isset($_GET['oauth_verifier'])) throw new Exception("Unable to handle authentication response: oauth_verifier wasn't returned by Twitter.");
            $oauthVerifier = $_GET['oauth_verifier'];
        }
        
        $sessionkey = $this->getAuthParam() . ':tmp_access';
        if (!isset($tmpAccess) && isset($_SESSION[$sessionkey])) $tmpAccess = $_SESSION[$sessionkey];
        if (!isset($tmpAccess['oauth_token'])) throw new Exception("Unable to handle authentication response: the temporary access token is unknown.");
        unset($tmpAccess['oauth_callback_confirmed']);

        $response = $this->httpRequest('GET', "oauth/access_token", array(), array('oauth'=>array('oauth_verifier' => $oauthVerifier) + $tmpAccess));
        parse_str($response, $data);

        $this->accessToken = $data['oauth_token'];
        $this->accessSecret = $data['oauth_token_secret'];
        
        if ($this->authUseSession) $_SESSION[$this->getAuthParam()] = $this->getAccessInfo();

        return $this->getAccessInfo();
    }
    
    /**
     * Authenticate using twitter
     */
    public function auth($level='authenticate')
    {
        if ($this->isAuth()) return;
        
        $param = $this->getAuthParam();
        
        if (!empty($_GET[$this->getAuthParam()]) && $_GET[$this->getAuthParam()] == 'auth') {
            $this->handleAuthResponse();
            return self::redirect($this->getCurrentUrl());
        }
  
        return self::redirect($this->getAuthUrl($level));
    }

    /**
     * Check if a user is authenticated.
     * 
     * @return boolean
     */
    public function isAuth()
    {
        return isset($this->accessToken);
    }
}