<?php

namespace Bemo\LiveShopping\Webservice;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ReadOnlyPermissionMap
{
    const RESOURCES = array(
        'products',
        'product_option_values',
        'categories',
        'combinations',
        'stock_availables',
    );

    public function build()
    {
        $permissions = array();

        foreach (self::RESOURCES as $resource) {
            $permissions[$resource] = array(
                'GET' => true,
                'HEAD' => true,
            );
        }

        return $permissions;
    }
}
