<?php

define('_PS_VERSION_', '8.2.6');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

/**
 * Mirrors the PrestaShop helper closely enough to prove escaping: values are
 * slash-escaped, and HTML is stripped unless the caller opts out.
 */
function pSQL($value, $htmlOK = false)
{
    if (is_numeric($value)) {
        return $value;
    }

    $escaped = addslashes((string) $value);

    return $htmlOK ? $escaped : strip_tags($escaped);
}

class PrestaShopLogger
{
    /** @var array */
    public static $logs = array();

    public static function addLog($message, $severity = 1)
    {
        self::$logs[] = array('message' => $message, 'severity' => $severity);

        return true;
    }
}

spl_autoload_register(function ($className) {
    $prefix = 'Bemo\\LiveShopping\\';

    if (strncmp($className, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($className, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relativeClass) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});
