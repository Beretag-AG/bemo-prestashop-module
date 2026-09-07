<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\ProductLinksResponse;
use PHPUnit\Framework\TestCase;

class ProductLinksResponseTest extends TestCase
{
    public function testEmptyCatalogResponseStillCarriesCurrentConfiguration()
    {
        self::assertSame(array(
            'products' => array(),
            'configuration' => array(
                'embeddedCheckoutRequested' => true,
                'moduleVersion' => '0.8.4',
                'shopId' => 7,
                'stockShopId' => 7,
                'stockShopGroupId' => 0,
            ),
        ), (new ProductLinksResponse())->compose(
            array(),
            true,
            '0.8.4',
            array(
                'shopId' => 7,
                'stockShopId' => 7,
                'stockShopGroupId' => 0,
            )
        ));
    }
}
