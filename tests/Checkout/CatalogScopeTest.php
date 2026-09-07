<?php

namespace Bemo\LiveShopping\Tests\Checkout;

use Bemo\LiveShopping\Checkout\CatalogScope;
use PHPUnit\Framework\TestCase;

class CatalogScopeTest extends TestCase
{
    public function testSharedStockUsesTheShopGroupStockScope()
    {
        $scope = new CatalogScope(7, 3, true);

        self::assertSame(array(
            'shopId' => 7,
            'stockShopId' => 0,
            'stockShopGroupId' => 3,
        ), $scope->details());
    }

    public function testShopStockUsesTheSelectedShopScope()
    {
        self::assertSame(array(
            'shopId' => 7,
            'stockShopId' => 7,
            'stockShopGroupId' => 0,
        ), (new CatalogScope(7, 3, false))->details());
    }
}
