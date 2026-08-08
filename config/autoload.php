<?php

if (!defined('_PS_VERSION_')) {
    exit;
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
