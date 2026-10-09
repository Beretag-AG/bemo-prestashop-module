<?php

class Module
{
    public $context;
    public $hooks = array();
    public $hookRegistrationSucceeds = true;

    public function registerHook($hook)
    {
        $this->hooks[] = $hook;

        return $this->hookRegistrationSucceeds;
    }
}

class Tools
{
    public static $secure = true;
    public static function usingSecureMode() { return self::$secure; }
}

class Db
{
    public static $embeddedCheckoutRequested = true;
    public static $fail = false;
    public static $queries = array();

    public static function getInstance() { return new self(); }

    public function getValue($query)
    {
        self::$queries[] = $query;
        if (self::$fail) {
            throw new Exception('Shop configuration unavailable');
        }

        return self::$embeddedCheckoutRequested;
    }
}
