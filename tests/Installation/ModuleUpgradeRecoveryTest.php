<?php

namespace Bemo\LiveShopping\Tests\Installation;

use Bemo\LiveShopping\Installation\ModuleUpgradeRecovery;
use Bemo\LiveShopping\Installation\ModuleVersionRepositoryInterface;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use PHPUnit\Framework\TestCase;

class ModuleUpgradeRecoveryTest extends TestCase
{
    public function testRunsShippedMigrationsAndRecordsEachSuccessfulVersion()
    {
        $versions = new RecoveryVersions('0.8.2');
        $module = new RecoveryModule();
        $recovery = new ModuleUpgradeRecovery($versions, new RecoveryLock());
        self::assertTrue($recovery->finish($module, '0.8.6'));
        self::assertSame(array('0.8.3', '0.8.5', '0.8.6'), $versions->recorded);
        self::assertSame(array('0.8.3', '0.8.5', '0.8.6'), $module->ran);
        self::assertTrue($recovery->finish($module, '0.8.6'));
        self::assertCount(3, $module->ran);
    }

    public function testFailedMigrationDoesNotAdvanceVersionAndCanBeRetried()
    {
        $versions = new RecoveryVersions('0.8.2');
        $module = new RecoveryModule();
        $module->succeeds = false;
        $recovery = new ModuleUpgradeRecovery($versions, new RecoveryLock());
        self::assertFalse($recovery->finish($module, '0.8.6'));
        self::assertSame(array(), $versions->recorded);
        $module->succeeds = true;
        self::assertTrue($recovery->finish($module, '0.8.6'));
        self::assertSame('0.8.6', $versions->version);
    }

    public function testVersionWriteFailureStopsBeforeNextMigration()
    {
        $versions = new RecoveryVersions('0.8.2');
        $versions->writable = false;
        $module = new RecoveryModule();
        self::assertFalse((new ModuleUpgradeRecovery($versions, new RecoveryLock()))->finish($module, '0.8.6'));
        self::assertSame(array('0.8.3'), $module->ran);
        self::assertSame('0.8.2', $versions->version);
    }

    public function testRetryResumesAfterTheLastSuccessfulMigration()
    {
        $versions = new RecoveryVersions('0.8.2');
        $module = new RecoveryModule();
        $module->failAt085 = true;
        $recovery = new ModuleUpgradeRecovery($versions, new RecoveryLock());
        self::assertFalse($recovery->finish($module, '0.8.6'));
        self::assertSame('0.8.3', $versions->version);
        $module->failAt085 = false;
        self::assertTrue($recovery->finish($module, '0.8.6'));
        self::assertSame(array('0.8.3', '0.8.5', '0.8.5', '0.8.6'), $module->ran);
    }

    public function testMissingTerminalScriptDoesNotRunAnyMigration()
    {
        $versions = new RecoveryVersions('0.8.2');
        $module = new RecoveryModule();
        self::assertFalse((new ModuleUpgradeRecovery($versions, new RecoveryLock()))->finish($module, '0.8.99'));
        self::assertSame(array(), $module->ran);
        self::assertSame(array(), $versions->recorded);
    }

    /** @dataProvider unsupportedVersions */
    public function testDoesNotGuessUnknownUpgradeHistory($version)
    {
        $versions = new RecoveryVersions($version);
        $module = new RecoveryModule();
        self::assertFalse((new ModuleUpgradeRecovery($versions, new RecoveryLock()))->finish($module, '0.8.6'));
        self::assertSame(array(), $module->ran);
        self::assertSame(array(), $versions->recorded);
    }

    public function unsupportedVersions()
    {
        return array(array(null), array(''), array('0.7.0'), array('0.9.0'));
    }
}

class RecoveryVersions implements ModuleVersionRepositoryInterface
{
    public $version;
    public $recorded = array();
    public $writable = true;
    public function __construct($version) { $this->version = $version; }
    public function current($name) { return $this->version; }
    public function record($name, $version)
    {
        if (!$this->writable) { return false; }
        $this->recorded[] = $version;
        $this->version = $version;
        return true;
    }
}

class RecoveryLock implements ShopLockInterface
{
    public function synchronized($scope, $shopId, $callback)
    {
        TestCase::assertSame('upgrade', $scope);
        TestCase::assertSame(0, $shopId);
        return call_user_func($callback);
    }
}

class RecoveryModule
{
    public $name = 'bemoliveshopping';
    public $ran = array();
    public $succeeds = true;
    public $failAt085 = false;
    public function upgradeToVersion083() { $this->ran[] = '0.8.3'; return $this->succeeds; }
    public function upgradeToVersion085() { $this->ran[] = '0.8.5'; return !$this->failAt085; }
    public function upgradeToVersion086() { $this->ran[] = '0.8.6'; return true; }
}
