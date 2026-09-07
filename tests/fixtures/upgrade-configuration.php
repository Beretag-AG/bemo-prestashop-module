<?php

class Module
{
    public $permission;
    public $context;
    public function l($message) { return $message; }
    public function displayError($message) { return $message; }
    public function getPermission($permission) { return $this->permission; }
}
class Tools
{
    public static $token;
    public static function isSubmit($name) { return $name === 'submitBemoFinishUpdate'; }
    public static function getValue($name) { return self::$token; }
    public static function getAdminTokenLite($controller) { return 'expected'; }
}
if (!class_exists('Validate')) {
    class Validate
    {
        public static function isLoadedObject($object) { return isset($object->id) && $object->id > 0; }
    }
}
class UpgradeSmarty
{
    public function clearAssign($key) {}
}
