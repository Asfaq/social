<?php
/**
 * Twitter Entity
 * 
 * @license MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social\Twitter;

use Social\Entity as Base;

/**
 * Autoexpending Twitter entity.
 */
abstract class Entity extends Base
{
    /**
     * Class constructor
     * 
     * @param Connection   $connection
     * @param string       $type
     * @param object|mixed $data        Data or ID
     * @param boolean      $stub
     */
    public function __construct(Connection $connection, $data=array(), $stub=self::NO_STUB)
    {
        $this->_connection = $connection;
        $this->_type = strtolower(preg_replace(array('/^.*\\\\/', '/([a-z])([A-Z])/'), array('', '$1_$2'), get_class($this)));
        $this->_stub = $stub || is_null($data) || is_scalar($data);
        
        if (is_scalar($data)) $data = array('id' => $data);
        $this->setProperties($data);
    }

    /**
     * Set properties.
     * 
     * @param array   $data 
     * @param boolean $expanded  Entity is no longer a stub
     */
    public function setProperties($data, $expanded=false)
    {
        if ($expanded) $this->_stub = false;

        // Data is already converted
        if ($data instanceof self) {
            parent::setData($data, $expanded);
            return;
        }
        
        // Raw data
        $conn = $this->getConnection();
        
        if (isset($data->id_str)) $data->id = $data->id_str;
        
        foreach ($data as $key=>&$value) {
            $type = $key == 'user' ? 'user' || $key == 'user_mentions' : ($key == 'status' ? 'tweet' : null);
            $this->$key = $conn->convertData($value, $type);
        }
    }
}