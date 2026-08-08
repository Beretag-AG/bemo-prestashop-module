<?php

namespace Bemo\LiveShopping\Tests\Configuration;

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;
use PHPUnit\Framework\TestCase;

class DbConfigurationRepositoryTest extends TestCase
{
    public function testUsesTheProductionAppUrlWhenNoOverrideExists()
    {
        $db = new ConfigurationDb();
        $db->values = array(false);

        self::assertSame(
            'https://bemo.now',
            (new DbConfigurationRepository($db))->getAppBaseUrl(7)
        );
    }

    public function testUsesTheProductionApiUrlWhenNoOverrideExists()
    {
        $db = new ConfigurationDb();
        $db->values = array(false);

        self::assertSame(
            'https://actions.bemo.now',
            (new DbConfigurationRepository($db))->getApiBaseUrl(7)
        );
    }

    public function testSavesBothEndpointsTogether()
    {
        $db = new ConfigurationDb();
        $db->values = array(1);
        $repository = new DbConfigurationRepository($db);

        self::assertTrue($repository->saveEndpoints(
            7,
            'https://api.example.com',
            'https://app.example.com'
        ));
        self::assertSame('bemoliveshopping_configuration', $db->updatedTable);
        self::assertSame('https://api.example.com', $db->updatedValues['api_base_url']);
        self::assertSame('https://app.example.com', $db->updatedValues['app_base_url']);
        self::assertSame('`id_shop` = 7', $db->updatedWhere);
    }

    public function testReturnsCredentialsOnlyWhenEveryDirectionIsPresent()
    {
        $db = new ConfigurationDb();
        $db->row = array(
            'webservice_key' => 'key',
            'webhook_secret' => 'webhook',
            'buy_link_secret' => 'buy',
            'credentials_api_base_url' => 'https://actions.bemo.now',
        );
        $repository = new DbConfigurationRepository($db);

        self::assertSame($db->row, $repository->getPairingCredentials(7));

        $db->row['buy_link_secret'] = '';
        self::assertNull($repository->getPairingCredentials(7));
    }

    public function testPairingStateMovesFromFreshTokenToPending()
    {
        $db = new ConfigurationDb();
        $db->values = array(1);
        $repository = new DbConfigurationRepository($db);

        self::assertTrue($repository->beginPairingAttempt(7, 'new-token'));
        self::assertSame('new-token', $db->updatedValues['pairing_token']);
        self::assertSame('pairing_starting', $db->updatedValues['connection_status']);

        self::assertTrue($repository->markPairingStarted(7, 'new-token', 1786200000000));
        self::assertSame('pairing_pending', $db->updatedValues['connection_status']);
        self::assertStringContainsString("`pairing_token` = 'new-token'", $db->updatedWhere);
    }
}

class ConfigurationDb
{
    public $values = array();
    public $row;
    public $updatedTable;
    public $updatedValues;
    public $updatedWhere;

    public function getValue($sql)
    {
        return array_shift($this->values);
    }

    public function getRow($sql)
    {
        return $this->row;
    }

    public function update($table, array $values, $where)
    {
        $this->updatedTable = $table;
        $this->updatedValues = $values;
        $this->updatedWhere = $where;

        return true;
    }

    public function insert($table, array $values)
    {
        return true;
    }
}
