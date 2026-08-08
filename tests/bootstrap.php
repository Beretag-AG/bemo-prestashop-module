<?php

define('_PS_VERSION_', '8.2.6');
define('_DB_PREFIX_', 'ps_');
define('_MYSQL_ENGINE_', 'InnoDB');

function pSQL($value)
{
    return $value;
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
