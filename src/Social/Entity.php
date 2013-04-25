<?php
/**
 * Base class for Entities
 * 
 * @license MIT
 * @copyright 2012 Jasny
 */

/** */
namespace Social;

/**
 * An autoexpanding Entity.
 */
abstract class Entity implements Data
{
    /** No stub */
    const NO_STUB = 0;
    
    /** Doesn't contain all of entities properties */
    const STUB = 1;

    /** Autoexpanding stub */
    const AUTOEXPAND = 2;
    
    
    /**
     * Social connection
     * @var Connection
     */
    protected $_connection;

    /**
     * Entity type
     * @var Connection
     */
    protected $_type;
    
    /**
     * Entity is a stub
     * @var int
     */
    protected $_stub = 0;
    
    
    /**
     * Class constructor
     * 
     * @param Connection $connection
     * @param string     $type
     * @param object     $data 
     * @param boolean    $stub
     */
    public function __construct(Connection $connection, $type=null, $data=array(), $stub=false)
    {
        $this->_connection = $connection;
        $this->_type = $type;
        $this->_stub = $stub;
        
        $this->setData($data);
    }
    

    /**
     * Get API connection.
     * 
     * @return Connection
     */
    public function getConnection()
    {
        if (!isset($this->_connection)) throw new Exception('This entity is not connected to an API. Please use $entity->reconnectTo($connection)');
        return $this->_connection;
    }

    /**
     * Get Entity type.
     * 
     * @return string
     */
    public function getType()
    {
        return $this->_type;
    }
    
    /**
     * Check if this Entity is a stub.
     * 
     * @return boolean 
     */
    public function isStub()
    {
        return $this->_stub;
    }

    /**
     * Fetch data of this entity (if this is a stub).
     * 
     * @param boolean $refresh  Fetch new data, even if this isn't a stub
     * @return Entity $this
     */
    abstract public function fetch($refresh=false);
    
    /**
     * Set properties.
     * 
     * @param array   $data 
     * @param boolean $expanded  Entity is no longer a stub
     * @return Entity $this
     */
    public function setData($data, $expanded=false)
    {
        // Data is already converted
        if ($data instanceof self) {
            foreach ($data as $key=>$value) {
                $this->$key = $value;
            }
            
            if (!$data->_stub) $this->_stub = false;
            return;
        }
        
        // Raw data
        foreach ($data as $key=>&$value) {
            $this->$key = $this->getConnection()->convertData($value);
        }
        
        if ($expanded) $this->_stub = false;
        
        return $this;
    }

    /**
     * Check if entity is the same as the provided entity or id.
     * 
     * @param Entity|string $entity
     * @return boolean
     */
    abstract public function is($entity);

    
    /**
     * Expand a stub when trying to get a non existing property.
     * 
     * @param string $name
     * @return mixed
     */
    public function __get($name)
    {
        if ($this->isStub() == self::AUTOEXPAND) $this->fetch();
         elseif ($this->isStub()) trigger_error("This " . get_class() . " is a stub, please call \$entity->fetch() to get all properties.", E_USER_NOTICE);

        return $this->$name;
    }
    
    /**
     * Serialization
     * { @internal Don't serialze the connection }}
     * 
     * @return array
     */
    public function __sleep()
    {
        $props = get_object_vars($this);
        unset($props['_connection']);
        return array_keys($props);
    }
    
    /**
     * Reconnect an unserialized Entity.
     * 
     * @param Connection $connection
     * @return Entity  $this
     */
    public function reconnectTo(Connection $connection)
    {
        if (isset($this->_connection)) throw new Exception("Unable to reconnect Entity: I'm already connected.");
        $this->_connection = $connection;
        
        return $this;
    }
}