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
        self::assertStringContainsString("`checkout_landing` VARCHAR(16) NOT NULL DEFAULT 'cart'", $db->executed[0]);
        self::assertStringContainsString('`cron_token` VARCHAR(64)', $db->executed[0]);
        self::assertStringContainsString('bemoliveshopping_outbox', $db->executed[1]);
        self::assertStringContainsString('UNIQUE KEY `uniq_bemoliveshopping_event`', $db->executed[1]);
        self::assertStringContainsString('`status`, `available_at`', $db->executed[1]);
        self::assertStringContainsString('`resource_key` VARCHAR(128)', $db->executed[1]);
        self::assertStringContainsString('(`id_shop`, `status`, `resource_key`)', $db->executed[1]);
        self::assertStringContainsString('bemoliveshopping_buy_nonce', $db->executed[2]);
        self::assertStringContainsString('UNIQUE KEY `uniq_bemoliveshopping_buy_nonce` (`id_shop`, `nonce`)', $db->executed[2]);
    }

    public function testUpgradeAddsTheDrainTokenCoalescingKeyAndReplayTableOnce()
    {
        $db = new InstallerDb();
        $installer = new Installer($db);

        self::assertTrue($installer->upgradeToVersion050());
        self::assertStringContainsString('`cron_token`', $db->executed[0]);
        self::assertStringContainsString('`resource_key`', $db->executed[1]);
        self::assertStringContainsString(Installer::OUTBOX_PENDING_INDEX, $db->executed[2]);
        self::assertStringContainsString('bemoliveshopping_buy_nonce', $db->executed[3]);
        self::assertCount(4, $db->executed);

        $db->executed = array();
        $db->columns = array(array('Field' => 'cron_token'));
        $db->indexes = array(array('Key_name' => Installer::OUTBOX_PENDING_INDEX));
        self::assertTrue($installer->upgradeToVersion050());
        self::assertCount(1, $db->executed);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS', $db->executed[0]);
    }

    public function testVersionSevenMigratesEveryShopToTheNativeCartLanding()
    {
        $db = new InstallerDb();

        self::assertTrue((new Installer($db))->upgradeToVersion070());
        self::assertStringContainsString("SET `checkout_landing` = 'cart'", $db->executed[0]);
    }

    public function testUninstallRemovesEveryModuleTable()
    {
        $db = new InstallerDb();

        self::assertTrue((new Installer($db))->uninstall());
        self::assertStringContainsString('bemoliveshopping_buy_nonce', $db->executed[0]);
        self::assertStringContainsString('bemoliveshopping_outbox', $db->executed[1]);
        self::assertStringContainsString('bemoliveshopping_configuration', $db->executed[2]);
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

    public function testUpgradeAddsCartLandingPreferenceOnce()
    {
        $db = new InstallerDb();
        $installer = new Installer($db);

        self::assertTrue($installer->upgradeToVersion040());
        self::assertCount(1, $db->executed);
        self::assertStringContainsString('checkout_landing', $db->executed[0]);
        self::assertStringContainsString("DEFAULT 'cart'", $db->executed[0]);

        $db->columns = array(array('Field' => 'checkout_landing'));
        self::assertTrue($installer->upgradeToVersion040());
        self::assertCount(1, $db->executed);
    }

    public function testInstallRollsBackEveryTableWhenOneCreationFails()
    {
        $db = new InstallerDb();
        $db->results = array(true, false, true, true, true);

        self::assertFalse((new Installer($db))->install());
        self::assertStringContainsString('DROP TABLE', $db->executed[2]);
        self::assertStringContainsString('bemoliveshopping_buy_nonce', $db->executed[2]);
        self::assertStringContainsString('bemoliveshopping_configuration', $db->executed[4]);
    }
}

class InstallerDb
{
    public $columns = array();
    public $indexes = array();
    public $executed = array();
    public $results = array();

    public function execute($sql)
    {
        $this->executed[] = $sql;

        return $this->results === array() ? true : array_shift($this->results);
    }

    public function executeS($sql)
    {
        return strpos($sql, 'SHOW INDEX') === 0 ? $this->indexes : $this->columns;
    }
}
