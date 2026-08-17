<?php

namespace Bemo\LiveShopping\Webservice;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ReadOnlyPermissionMap
{
    const RESOURCES = array(
        'products',
        'categories',
        'combinations',
        'stock_availables',
        'specific_prices',
        'cart_rules',
        'images',
        'languages',
        'currencies',
        'shops',
        'taxes',
        'tax_rules',
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
