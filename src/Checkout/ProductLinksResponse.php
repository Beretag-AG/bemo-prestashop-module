<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ProductLinksResponse
{
    public function compose(array $products, $embeddedCheckoutRequested, $moduleVersion, array $catalogScope)
    {
        return array(
            'products' => $products,
            'configuration' => array_merge(
                array(
                    'embeddedCheckoutRequested' => (bool) $embeddedCheckoutRequested,
                    'moduleVersion' => (string) $moduleVersion,
                ),
                $catalogScope
            ),
        );
    }
}
