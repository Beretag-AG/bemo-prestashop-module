<?php

namespace Bemo\LiveShopping\Tests\Voucher;

use Bemo\LiveShopping\Voucher\PrestaShopVoucherProvider;
use PHPUnit\Framework\TestCase;

class PrestaShopVoucherProviderTest extends TestCase
{
    public function testReturnsOnlyThePublicVoucherContractFields()
    {
        $db = new VoucherDb(array(array(
            'id_cart_rule' => '51',
            'code' => 'LIVE10',
            'quantity' => '500',
            'date_from' => '2027-01-15 08:00:00',
            'date_to' => '2027-01-16 08:00:00',
            'active' => '1',
        )));

        $vouchers = (new PrestaShopVoucherProvider($db))->listForShop(7);

        self::assertSame(array(array(
            'externalVoucherId' => 51,
            'code' => 'LIVE10',
            'quantity' => 500,
            'validFrom' => strtotime('2027-01-15 08:00:00') * 1000,
            'validTo' => strtotime('2027-01-16 08:00:00') * 1000,
            'active' => true,
        )), $vouchers);
        self::assertStringContainsString('crs.`id_shop` = 7', $db->query);
        self::assertStringContainsString('cr.`id_customer` = 0', $db->query);
        self::assertStringContainsString("cr.`code` <> ''", $db->query);
        self::assertStringContainsString('ORDER BY cr.`id_cart_rule` ASC', $db->query);
        self::assertStringContainsString('LIMIT 100', $db->query);
        self::assertStringNotContainsString('SELECT cr.*', $db->query);
    }

    public function testThrowsWhenTheDatabaseReadFails()
    {
        $this->expectException(\RuntimeException::class);

        (new PrestaShopVoucherProvider(new VoucherDb(false)))->listForShop(7);
    }

    public function testFixtureMatchesTheExactResponseContract()
    {
        $fixture = json_decode(file_get_contents(
            dirname(__DIR__, 2) . '/docs/contracts/prestashop-vouchers-response.json'
        ), true);

        self::assertSame(array('vouchers'), array_keys($fixture));
        self::assertSame(array(
            'externalVoucherId',
            'code',
            'quantity',
            'validFrom',
            'validTo',
            'active',
        ), array_keys($fixture['vouchers'][0]));
    }
}

class VoucherDb
{
    private $rows;

    public $query;

    public function __construct($rows)
    {
        $this->rows = $rows;
    }

    public function executeS($query)
    {
        $this->query = $query;

        return $this->rows;
    }
}
