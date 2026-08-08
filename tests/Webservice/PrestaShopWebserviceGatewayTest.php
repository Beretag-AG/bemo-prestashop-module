<?php

namespace Bemo\LiveShopping\Tests\Webservice;

use Bemo\LiveShopping\Webservice\PrestaShopWebserviceGateway;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class PrestaShopWebserviceGatewayTest extends TestCase
{
    public function testMatchesThePermissionShapeReturnedByPrestashop()
    {
        $method = new ReflectionMethod(PrestaShopWebserviceGateway::class, 'sameEnabledMethods');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true);
        }
        $gateway = new PrestaShopWebserviceGateway();

        self::assertTrue($method->invoke($gateway, array('GET'), array('GET' => true)));
        self::assertFalse($method->invoke($gateway, array('GET', 'HEAD'), array('GET' => true)));
        self::assertFalse($method->invoke($gateway, array('POST'), array('GET' => true)));
    }
}
