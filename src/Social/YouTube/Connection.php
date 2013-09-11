<?php
/**
 * Jasny Social
 * World's best PHP library for webservice APIs
 * 
 * @license http://www.jasny.net/mit MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social\YouTube;

use Social\Google\Connection as Google;

/**
 * YouTube API connection.
 * @see https://developers.google.com/youtube/v3
 * @package YouTube
 * 
 * Before you start register your application at https://code.google.com/apis/console/#access and retrieve a client ID
 *  and secret.
 * 
 * Scopes:
 *   - youtube                         · Manage your YouTube account
 *   - youtube.readonly                · View your YouTube account
 *   - youtube.upload                  · Manage your YouTube videos
 *   - youtubepartner                  · View and manage your assets and associated content on YouTube
 *   - youtubepartner-channel-audition
 */
class Connection extends Google
{
    /**
     * Google API name
     * @var string 
     */
    protected $apiName = "youtube";
    
    /**
     * Google API version
     * @var string 
     */
    protected $apiVersion = "v1";
}
