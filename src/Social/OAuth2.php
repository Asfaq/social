<?php
/**
 * Jasny Social
 * World's best PHP library for Social APIs
 * 
 * @license http://www.jasny.net/mit MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social;

/**
 * Trait to be used by a connection to implement OAuth 2.
 */
trait OAuth2
{
    /**
     * Use $_SESSION for authentication
     * @var boolean
     */
    protected $authUseSession = false;

    /**
     * Application's client ID
     * @var string
     */
    protected $clientId;

    /**
     * Application secret
     * @var string
     */
    protected $clientSecret;

    /**
     * User's access token
     * @var string
     */
    protected $accessToken;

    /**
     * Timestamp for when access token will expire
     * @var int
     */
    protected $accessExpires;

    /**
     * The requested permissions
     * Note: It's not certain the authenticated user has given these permissions
     *
     * @var string|array
     */
    protected $scope;

    
    /**
     * Set the application's client credentials
     * 
     * @param string $clientId
     * @param string $clientSecret
     */
    protected function setCredentials($clientId, $clientSecret)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
    }
    
    /**
     * Get the application client ID.
     * 
     * @return string
     */
    public function getClientId()
    {
        return $this->clientId;
    }
    
    /**
     * Get the user's access token.
     * 
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }
    
    /**
     * Get the timestamp of when the access token will expire.
     * 
     * @return int
     */
    public function getAccessExpires()
    {
        return $this->accessExpires;
    }
    
    /**
     * Set the access info.
     * 
     * @param array|object $access [ user's access token, expire timestamp, user id ] or { 'access_token': string, 'expires': unixtime, 'user': user id }
     */
    protected function setAccessInfo($access)
    {
        if (!isset($access)) return;
        
        if (isset($_SESSION) && $access === $_SESSION) {
            $this->authUseSession = true;
            $access = @$_SESSION[static::apiName . ':access'];
        }
        
        if (is_array($access) && is_int(key($access))) {
            list($this->accessToken, $this->accessExpires, $user) = $access + array(null, null, null);
        } elseif (isset($access)) {
            $access = (object)$access;
            $this->accessToken = $access->access_token;
            if (isset($access->expires)) $this->accessExpires = $access->expires;
            if (isset($access->user)) $user = $access->user;
        }
        
        if (isset($user)) {
            if ($user instanceof Entity) {
                $this->me = $user->reconnectTo($this);
            } elseif (is_scalar($user)) {
                $this->me = $this->entity('user', array('id' => $user), Entity::AUTOEXPAND);
            } else {
                $type = (is_object($user) ? get_class($user) : get_type($user));
                throw new \Exception("Was expecting an ID (int) or Entity for user, but got a $type");
            }
        }
    }
    
    /**
     * Get the access info.
     *
     * @return object  { 'access_token': string, 'expires': unixtime }
     */
    public function getAccessInfo()
    {
        if (!isset($this->accessToken)) return null;
        return (object)['access_token' => $this->accessToken, 'expires' => $this->accessExpires];
    }
    
    /**
     * Generate a unique value, used as 'state' for oauth.
     * 
     * @return string
     */
    protected function getUniqueState()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['REMOTE_ADDR'];
        return md5($ip . $this->clientSecret);
    }
    
    /**
     * Create a new connection using the specified access token.
     * 
     * @param array|object $access [ user's access token, expire timestamp, user id ] or { 'access_token': string, 'expires': unixtime, 'user': user id }
     */
    public function asUser($access)
    {
        return new static($this->clientId, $this->clientSecret, $access);
    }
    
    
    /**
     * Initialise an HTTP request object.
     *
     * @param object|string  $request  url or { 'method': string, 'url': string, 'params': array, 'headers': array, 'convert': mixed }
     * @return object
     */
    protected function initRequest($request)
    {
        $request = parent::initRequest($request);
        
        if (isset($request->params['oauth_token']) || isset($request->params['client_id'])); // do nothing
         elseif ($this->accessToken) $request->params['oauth_token'] = $this->accessToken;
         else $request->params['client_id'] = $this->clientId;
        
        return $request;
    }
    
    
     /**
     * Get the URL of the current script.
     *
     * @param string $page    Relative path to page
     * @param array  $params
     * @return string
     */
    static public function getCurrentUrl($page=null, array $params=[])
    {
        $params['code'] = null;
        $params['state'] = null;

        return parent::getCurrentUrl($page, $params);
    }
    
    /**
     * Get authentication url.
     * 
     * @param array  $scope        Permission list
     * @param string $redirectUrl  Redirect to this URL after authentication
     * @param array  $params       Additional URL parameters
     * @return string
     */
    public function getAuthUrl($scope=null, $redirectUrl=null, $params=[])
    {
        $redirectUrl = $this->getCurrentUrl($redirectUrl, [static::apiName . '-auth'=>'auth']);
        if (!isset($redirectUrl)) throw new Exception("Unable to determine the redirect URL, please specify it.");

        $this->scope = $scope;       
        if (is_array($scope)) $scope = join(',', $scope);
 
        return $this->getUrl(static::authURL, ['client_id'=>$this->clientId, 'redirect_uri'=>$redirectUrl,
            'scope'=>$scope, 'state'=>$this->getUniqueState()] + $params + ['response_type'=>'code']);
    }

    /**
     * Fetch the OAuth2 access token.
     *
     * @params array $params  Parameters
     * @return object
     */
    abstract protected function fetchAccessToken(array $params);
    
    /**
     * Handle an authentication response and sets the access token.
     * If $code and $state are omitted, they are taken from $_GET.
     * 
     * @param string $code   Returned code generated by API.
     * @param string $state  Returned state generated by us; false means don't check state
     * @return Connection $this
     */
    public function handleAuthResponse($code=null, $state=null)
    {
        if (!isset($code)) {
            if (!isset($_GET['code'])) {
		if (isset($_GET['error_description'])) throw new \Exception(static::apiName . " says: " . $_GET['error_description']);
		if (isset($_GET['error'])) throw new \Exception(static::apiName . " says: " . $_GET['error']);
                throw new \Exception("Unable to handle authentication response: " . static::apiName . " API didn't return a code.");
            }
            
            $code = $_GET['code'];
            if (isset($_GET['state'])) $state = $_GET['state'];
        }
        
        $redirectUrl = $this->getCurrentUrl();
        
        if ($state !== false && $this->getUniqueState() != $state) {
            throw new \Exception('Authentication response not accepted. IP mismatch, possible cross-site request'
                . 'forgery.');
        }

        $data = $this->fetchAccessToken(['client_id'=>$this->clientId, 'client_secret'=>$this->clientSecret,
            'redirect_uri'=>$redirectUrl, 'grant_type'=>'authorization_code', 'code'=>$code]);

        if (!isset($data->access_token)) {
            $error = isset($data->error) ? $data->error : (is_scalar($data) ? $data : json_encode($data));
            if (is_scalar($error)) {
                $error = (array)$error;
                $error = reset($error);
	    }
            throw new \Exception("Failed to retrieve an access token: $error");
        }

	$expires_in = isset($data->expires) ? $data->expires : (isset($data->expires_in) ? $data->expires_in : null);

        $this->accessToken = $data->access_token;
        $this->accessExpires = isset($expires_in) ? time() + $expires_in : null;
        
        if ($this->authUseSession) $_SESSION[static::apiName . ':access'] = $this->getAccessInfo();
        
        return $this;
    }

    /**
     * Get authentication url.
     * 
     * @param array  $scope        Permission list
     * @param string $redirectUrl  Redirect to this URL after authentication
     * @param array  $params       Additional URL parameters
     * @return Connection $this
     */
    public function auth($scope=null, $redirectUrl=null, $params=[])
    {
        $this->scope = $scope;

        if ($this->isAuth()) return $this;
        
        if (!empty($_GET[static::apiName . '-auth']) && $_GET[static::apiName . '-auth'] == 'auth') {
            $this->handleAuthResponse();
            self::redirect($this->getCurrentUrl(null, [static::apiName . '-auth'=>null]));
        } else {
            if (isset($this->accessToken)) $params['grant_type'] = 'refresh_token';
            self::redirect($this->getAuthUrl($scope, $redirectUrl, $params));
        }
    }

    /**
     * Check if the authenticated user has given the requested permissions.
     * If the user doesn't have these permissions, redirect him back to the auth dialog.
     *
     * @return Connection $this
     */
    public function checkScope()
    {
        return $this;
    }


   /**
     * Check if the access token is expired (or will expire soon).
     *
     * @param int $margin  Number of seconds the session has to be alive.
     * @return boolean
     */
    public function isExpired($margin=5)
    {
        return isset($this->accessExpires) && $this->accessExpires < (time() + $margin);
    }
    
    /**
     * Check if a user is authenticated.
     * 
     * @return boolean
     */
    public function isAuth()
    {
        return isset($this->accessToken) && !$this->isExpired();
    }
}
