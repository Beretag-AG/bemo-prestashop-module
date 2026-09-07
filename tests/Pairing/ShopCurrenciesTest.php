<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Pairing\ShopCurrencies;
use PHPUnit\Framework\TestCase;

class ShopCurrenciesTest extends TestCase
{
    public function testSelectedShopDefaultCurrencyIsAlwaysFirst()
    {
        $currencies = array(
            array('id_currency' => 1, 'iso_code' => 'usd', 'active' => true),
            array('id_currency' => 2, 'iso_code' => 'eur', 'active' => true),
        );

        self::assertSame(
            array('EUR', 'USD'),
            (new ShopCurrencies())->activeIsoCodes($currencies, 2)
        );
    }

    public function testMissingSelectedShopDefaultCurrencyStopsPairing()
    {
        $currencies = array(
            array('id_currency' => 1, 'iso_code' => 'usd', 'active' => true),
            array('id_currency' => 2, 'iso_code' => 'eur', 'active' => true),
        );

        self::assertSame(
            array(),
            (new ShopCurrencies())->activeIsoCodes($currencies, 3)
        );
    }
}
