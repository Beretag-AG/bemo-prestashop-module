<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use PHPUnit\Framework\TestCase;

class ProductShopAssociationCompatibilityTest extends TestCase
{
    /**
     * @dataProvider productControllerProvider
     */
    public function testProductAssociationUsesTheLoadedProductInstance($relativePath)
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

        self::assertNotFalse($source);
        self::assertStringNotContainsString('Product::isAssociatedToShop', $source);
        self::assertStringContainsString('$product->isAssociatedToShop($shopId)', $source);
    }

    public function productControllerProvider()
    {
        return array(
            'signed checkout' => array('controllers/front/buy.php'),
            'canonical product links' => array('controllers/front/productlinks.php'),
        );
    }
}
