<?php

namespace Bemo\LiveShopping\Tests\Installation;

use Bemo\LiveShopping\Installation\Installer;
use PHPUnit\Framework\TestCase;

class InstallerTest extends TestCase
{
    public function testFreshInstallIncludesTheBemoAppUrl()
    {
        $db = new InstallerDb();

        self::assertTrue((new Installer($db))->install());
        self::assertStringContainsString('`app_base_url` VARCHAR(255)', $db->executed[0]);
        self::assertStringContainsString('https://bemo.now', $db->executed[0]);
        self::assertStringContainsString('https://actions.bemo.now', $db->executed[0]);
        self::assertStringContainsString('`credentials_api_base_url`', $db->executed[0]);
        self::assertStringContainsString('`pairing_expires_at`', $db->executed[0]);
        self::assertStringContainsString('`webservice_access_approved`', $db->executed[0]);
        self::assertStringContainsString('`embedded_checkout_requested`', $db->executed[0]);
        self::assertStringContainsString('bemoliveshopping_outbox', $db->executed[1]);
        self::assertStringContainsString('UNIQUE KEY `uniq_bemoliveshopping_event`', $db->executed[1]);
        self::assertStringContainsString('`status`, `available_at`', $db->executed[1]);
    }

    public function testUpgradeAddsCredentialOriginAndPairingExpiry()
    {
        $db = new InstallerDb();

        self::assertTrue((new Installer($db))->upgradeToVersion030());
        self::assertStringContainsString('credentials_api_base_url', $db->executed[0]);
        self::assertStringContainsString('pairing_expires_at', $db->executed[1]);
        self::assertStringContainsString('actions.bemo.now', $db->executed[2]);
        self::assertStringContainsString('bemoliveshopping_outbox', $db->executed[3]);
    }

    public function testUpgradeAddsTheAppUrlColumnOnce()
    {
        $db = new InstallerDb();
        $installer = new Installer($db);

        self::assertTrue($installer->upgradeToVersion020());
        self::assertCount(1, $db->executed);
        self::assertStringContainsString('ALTER TABLE', $db->executed[0]);

        $db->columns = array(array('Field' => 'app_base_url'));
        self::assertTrue($installer->upgradeToVersion020());
        self::assertCount(1, $db->executed);
    }

    public function testUpgradeAddsPersistentActivationChoices()
    {
        $db = new InstallerDb();

        self::assertTrue((new Installer($db))->upgradeToVersion034());
        self::assertStringContainsString('webservice_access_approved', $db->executed[0]);
        self::assertStringContainsString('embedded_checkout_requested', $db->executed[1]);
    }

    public function testInstallRollsBackConfigurationTableWhenOutboxCreationFails()
    {
        $db = new InstallerDb();
        $db->results = array(true, false, true);

        self::assertFalse((new Installer($db))->install());
        self::assertStringContainsString('bemoliveshopping_configuration', $db->executed[2]);
        self::assertStringContainsString('DROP TABLE', $db->executed[2]);
    }
}

class InstallerDb
{
    public $columns = array();
    public $executed = array();
    public $results = array();

    public function execute($sql)
    {
        $this->executed[] = $sql;

        return $this->results === array() ? true : array_shift($this->results);
    }

    public function executeS($sql)
    {
        return $this->columns;
    }
}
