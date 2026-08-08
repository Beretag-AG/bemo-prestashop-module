<?php

namespace Bemo\LiveShopping\Tests\Webservice;

use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use PHPUnit\Framework\TestCase;

class ReadOnlyPermissionMapTest extends TestCase
{
    public function testGrantsOnlyGetForTheResourcesTheProviderReads()
    {
        $permissions = (new ReadOnlyPermissionMap())->build();

        self::assertSame(ReadOnlyPermissionMap::RESOURCES, array_keys($permissions));

        foreach ($permissions as $methods) {
            self::assertSame(array('GET' => true), $methods);
        }

        self::assertArrayNotHasKey('orders', $permissions);
    }
}
