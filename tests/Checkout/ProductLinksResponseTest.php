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
                'moduleVersion' => '0.7.0',
            ),
        ), (new ProductLinksResponse())->compose(array(), true, '0.7.0'));
    }
}
