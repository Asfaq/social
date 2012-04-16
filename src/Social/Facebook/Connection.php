<?php
/**
 * Facebook Graph API connection.
 * 
 * @license MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social\Facebook;

use Social\Connection as Base;
use Social\Exception;

/**
 * Facebook Graph API connection.
 * @see http://developers.facebook.com/docs/reference/api/
 */
class Connection extends Base
{
    /**
     * Facebook authentication URL
     */
    const authURL = "https://www.facebook.com/dialog/oauth";

    /**
     * Facebook Open Graph API URL
     */
    const graphURL = "https://graph.facebook.com/";
    
    
    /**
     * @var string
     */
    protected $appId;

    /**
     * @var string
     */
    protected $apiSecret;


    /**
     * @var string
     */
    protected $accessToken;

    /**
     * @var int
     */
    protected $accessExpires;

    /**
     * @var int
     */
    protected $accessTimestamp;
    
    
    /**
     * Current user
     * @var Entity
     */
    protected $me;
    

    /**
     * Class constructor.
     * 
     * @param string $appId
     * @param string $secret
     * @param object $access  { 'access_token': token, 'expires': seconds, 'timestamp': unixtime }
     */
    public function __construct($appId, $apiSecret, $access=null)
    {
        $this->appId = $appId;
        $this->apiSecret = $apiSecret;

        // Set access token, expecting stdClass object, but let's be flexible
        if (is_array($access)) $access = (object)$access;
        if (is_object($access)) {
            $this->accessToken = $access->access_token;
            if (isset($access->expires)) $this->accessExpires = $access->expires;
            if (isset($access->timestamp)) $this->accessTimestamp = $access->timestamp;
        } elseif (is_string($access)) {
            $this->accessToken = $access;
        }
    }
    
    /**
     * Get the application ID.
     * 
     * @return string
     */
    public function getAppId()
    {
        return $this->appId;
    }
    
    /**
     * Get the access token.
     * 
     * @return string
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }
    
    /**
     * Get the access info.
     *
     * @return object  { 'access_token': token, 'expires': seconds, 'timestamp': unixtime }
     */
    public function getAccessInfo()
    {
        return isset($this->accessToken) ? (object)array('access_token' => $this->accessToken, 'expires' => $this->accessExpires, 'timestamp' => $this->accessTimestamp) : null;
    }
    
    /**
     * Get Facebook Open Graph API URL
     * 
     * @return string
     */
    protected function getBaseUrl()
    {
        return self::graphURL;
    }
    
    /**
     * Generate a unique value, used as 'state' for oauth.
     * 
     * @return string
     */
    protected function getUniqueState()
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['SERVER_ADDR'] : $_SERVER['REMOTE_ADDR'];
        return md5($ip . $this->apiSecret);
    }
    
    /**
     * Get authentication url.
     *
     * Add 'extend_token' to $scope to request a 60 day valid token.
     * 
     * For permssions @see http://developers.facebook.com/docs/authentication/permissions/
     * 
     * @param array  $scope        Permission list
     * @param string $redirectUrl
     * @return string
     */
    public function getAuthUrl($scope=null, $redirectUrl=null)
    {
        if (empty($redirectUrl)) {
            $redirectUrl = $this->getCurrentUrl(array('code'=>null, 'state'=>null));
            if (!isset($redirectUrl)) throw new Exception("Unable to determine the redirect URL, please specify it.");
        }
        
        return $this->getUrl(self::authURL, array('client_id' => $this->appId, 'redirect_uri' => $redirectUrl, 'scope' => $scope, 'state' => $this->getUniqueState()));
    }
    
    /**
     * Handle an authentication response and sets the access token.
     * If $code and $state are omitted, they are taken from $_GET.
     * 
     * @param string $code   Returned code generated by Facebook.
     * @param string $state  Returned state generated by us; false means don't check state
     * @return object  { 'access_token': token, 'expires': seconds, 'timestamp': unixtime }
     */
    public function handleAuthResponse($code=null, $state=null)
    {
        if (!isset($code)) {
            if (isset($_GET['code'])) $code = $_GET['code'];
            if (isset($_GET['state'])) $state = $_GET['state'];
        }
        
        $redirectUrl = $this->getCurrentUrl(array('code'=>null, 'state'=>null));
        
        if ($state !== false && $this->getUniqueState() != $state) {
            throw new Exception('Authentication response not accepted. IP mismatch, possible cross-site request forgery.');
        }
        
        $response = $this->request("oauth/access_token", array('client_id' => $this->appId, 'client_secret' => $this->apiSecret, 'redirect_uri' => $redirectUrl, 'code' => $code));
        parse_str($response, $data);
        if (reset($data) == '') $data = json_decode($response, true);

        if (!isset($data['access_token'])) throw new Exception("Failed to retrieve an access token from Facebook" . (isset($data['error']['message']) ? ': ' . $data['error']['message'] : ''));

        $this->accessToken = $data['access_token'];
        $this->accessExpires = $data['expires'];
        $this->accessTimestamp = time();

        return $this->getAccessInfo();
    }

    /**
     * Request a new access token with an extended lifetime of 60 days from now.
     *
     * @return object { 'access_token': token, 'expires': seconds, 'timestamp': unixtime }
     */
    public function extendAccess()
    {
        if (!isset($this->accessToken)) throw new Exception("Unable to extend access token. Access token isn't set.");
        $response = $this->request("oauth/access_token", array('client_id' => $this->appId, 'client_secret' => $this->apiSecret, 'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => $this->getAccessToken()));

        parse_str($response, $data);
        if (reset($data) == '') $data = json_decode($response, true);

        if (!isset($data['access_token'])) throw new Exception("Failed to extend the access token from Facebook" . (isset($data['error']['message']) ? ': ' . $data['error']['message'] : ''));

        $this->accessToken = $data['access_token'];
        $this->accessExpires = $data['expires'];
        $this->accessTimestamp = time();

        return $this->getAccessInfo();
    }

    /**
     * Check if the access token is expired (or will expire soon).
     *
     * @param int $margin  Number of seconds the session has to be alive.
     * @return bool
     */
    public function isExpired($margin=5)
    {
        return isset($this->accessExpires) && isset($this->accessTimestamp) && ($this->accessTimestamp + $this->accessExpires) < (time() + $margin);
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
    
    
    /**
     * Fetch raw data from facebook.
     * 
     * @param string $id
     * @param array  $params  Get parameters
     * @return array
     */
    public function fetchData($id, array $params=array())
    {
        $response = $this->request($id, ($this->accessToken ? array('access_token' => $this->accessToken) : array('client_id' => $this->appId)) + $params);
        $data = json_decode($response);

	if (!isset($data)) return $response; // Not json

        if (isset($data->error)) throw new Exception("Fetching '$id' from Facebook failed: " . $data->error->message);        
        return $data;
    }

    /**
     * Fetch an entity (or other data) from Facebook.
     * 
     * @param string $id
     * @param array  $params
     * @return Entity
     */
    public function fetch($id, array $params=array())
    {
        $data = $this->fetchData($id, $params);
        return $this->convertData($data, $params + $this->extractParams($id));
    }
    
    /**
     * Get current user profile.
     * 
     * @return Entity
     */
    public function me()
    {
        if (isset($this->me)) return $this->me;
        if (!$this->isAuth()) throw new Exception("There is no current user. Please set the access token.");
        
        $data = $this->fetchData('me');
        $this->me = new Entity($this, 'user', $data);
        return $this->me;
    }

    
    /**
     * Create a new entity
     * 
     * @param string $type
     * @param array  $data
     * @return Entity
     */
    public function create($type, $data=array())
    {
        return new Entity($this, $type, (object)$data);
    }
    
    /**
     * Create a new collection
     * 
     * @param array $data 
     */
    public function collection(array $data=array())
    {
        return new Collection($this, $type, $data);
    }
    
    /**
     * Create a stub.
     * 
     * @param array|string $data  Data or id
     */
    public function stub($data)
    {
        if (is_scalar($data)) $data = array('id' => $data);
        return new Entity($this, null, (object)$data);
    }
    
    
    /**
     * Convert data to Entity, Collection or DateTime.
     * 
     * @param mixed $data
     * @param array $params  Parameters used to fetch data
     * @return Entity|Collection|DateTime|mixed
     */
    public function convertData($data, array $params=array())
    {
        // Don't convert
        if ($data instanceof Entity || $data instanceof Collection || $data instanceof \DateTime) {
            return $data;
        }
        
        // Scalar
        if (is_scalar($data) || is_null($data)) {
            if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d$/', $data)) return new \DateTime($data);
            return $data;
        }

        // Entity
        if ($data instanceof \stdClass && isset($data->id)) return new Entity($this, null, $data, true);
           
        // Collection
        if ($data instanceof \stdClass && isset($data->data) && is_array($data->data)) {
            $nextPage = isset($data->paging->next) ? $data->paging->next = $this->buildUrl($data->paging->next, $params, false) : null; // Make sure the same parameters are used in the next query
            return new Collection($this, $data->data, $nextPage);
        }
        
        // Array or stdClass
        if (is_array($data) || $data instanceof \stdClass) {
            foreach ($data as &$value) {
                $value = $this->convertData($value);
            }
            return $data;
        }
        
        // Probably some other kind of object
        return $data;
    }
    
    
    /**
     * Serialization
     * { @internal Don't serialze cached objects }}
     * 
     * @return array
     */
    public function __sleep()
    {
        return array('appId', 'appSecret', 'accessToken', 'accessExpires', 'accessTimestamp');
    }
}
