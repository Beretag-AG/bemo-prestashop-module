<?php

namespace Bemo\LiveShopping\Tests\Installation;

use Bemo\LiveShopping\Installation\SchedulerModuleGatewayInterface;
use Bemo\LiveShopping\Installation\SchedulerModuleSetup;
use PHPUnit\Framework\TestCase;

class SchedulerModuleSetupTest extends TestCase
{
    public function testKeepsAnEnabledSchedulerUntouched()
    {
        $gateway = new SchedulerModuleGatewayStub(true, true, true);

        self::assertTrue((new SchedulerModuleSetup($gateway))->ensureAvailable());
        self::assertSame(0, $gateway->installCalls);
        self::assertSame(0, $gateway->enableCalls);
    }

    public function testInstallsAnAvailableSchedulerPackage()
    {
        $gateway = new SchedulerModuleGatewayStub(false, false, true);

        self::assertTrue((new SchedulerModuleSetup($gateway))->ensureAvailable());
        self::assertSame(1, $gateway->installCalls);
        self::assertSame(0, $gateway->enableCalls);
    }

    public function testEnablesAnInstalledScheduler()
    {
        $gateway = new SchedulerModuleGatewayStub(true, false, true);

        self::assertTrue((new SchedulerModuleSetup($gateway))->ensureAvailable());
        self::assertSame(0, $gateway->installCalls);
        self::assertSame(1, $gateway->enableCalls);
    }

    public function testLeavesManualSchedulingAvailableWhenThePackageIsMissing()
    {
        $gateway = new SchedulerModuleGatewayStub(false, false, false);

        self::assertFalse((new SchedulerModuleSetup($gateway))->ensureAvailable());
        self::assertSame(1, $gateway->installCalls);
    }
}

class SchedulerModuleGatewayStub implements SchedulerModuleGatewayInterface
{
    public $installCalls = 0;
    public $enableCalls = 0;

    private $installed;
    private $enabled;
    private $available;

    public function __construct($installed, $enabled, $available)
    {
        $this->installed = $installed;
        $this->enabled = $enabled;
        $this->available = $available;
    }

    public function isInstalled() { return $this->installed; }
    public function isEnabled() { return $this->enabled; }

    public function install()
    {
        ++$this->installCalls;

        return $this->available;
    }

    public function enable()
    {
        ++$this->enableCalls;

        return true;
    }
}
