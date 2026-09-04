<?php

namespace Bemo\LiveShopping\Tests\Installation;

use PHPUnit\Framework\TestCase;

class Upgrade083ScriptTest extends TestCase
{
    public function testUpgradeSynchronizesExistingWebservicePermissions()
    {
        require_once dirname(__DIR__, 2) . '/upgrade/upgrade-0.8.3.php';
        $module = new Upgrade083Module();

        self::assertTrue(\upgrade_module_0_8_3($module));
        self::assertSame(1, $module->permissionSynchronizationCalls);
    }
}

class Upgrade083Module
{
    public $permissionSynchronizationCalls = 0;

    public function upgradeToVersion083()
    {
        ++$this->permissionSynchronizationCalls;

        return true;
    }
}
