<?php

namespace Bemo\LiveShopping\Tests\Webservice;

use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use PHPUnit\Framework\TestCase;

class ReadOnlyPermissionMapTest extends TestCase
{
    public function testGrantsOnlyReadMethodsForTheResourcesTheProviderReads()
    {
        $permissions = (new ReadOnlyPermissionMap())->build();

        self::assertSame(ReadOnlyPermissionMap::RESOURCES, array_keys($permissions));

        foreach ($permissions as $methods) {
            self::assertSame(array('GET' => true, 'HEAD' => true), $methods);
        }

        self::assertArrayNotHasKey('orders', $permissions);
    }

    public function testCoversEveryCatalogResourceBemoReads()
    {
        $resources = ReadOnlyPermissionMap::RESOURCES;
        sort($resources);

        self::assertSame(array(
            'cart_rules',
            'categories',
            'combinations',
            'currencies',
            'images',
            'languages',
            'products',
            'shops',
            'specific_prices',
            'stock_availables',
            'tax_rules',
            'taxes',
        ), $resources);
    }
}
