<?php

namespace Bemo\LiveShopping\Tests\Installation {
    use PHPUnit\Framework\TestCase;

    /**
     * @runTestsInSeparateProcesses
     * @preserveGlobalState disabled
     */
    class UpgradeConfigurationAccessTest extends TestCase
    {
        /** @dataProvider deniedRequests */
        public function testRejectsUnauthorizedRecoveryBeforeDatabaseAccess($method, $token, $employee, $permission)
        {
            require_once dirname(__DIR__) . '/fixtures/upgrade-configuration.php';
            require_once dirname(__DIR__, 2) . '/bemoliveshopping.php';
            $_SERVER['REQUEST_METHOD'] = $method;
            \Tools::$token = $token;
            $module = (new \ReflectionClass('Bemoliveshopping'))->newInstanceWithoutConstructor();
            $module->permission = $permission;
            $module->context = (object) array(
                'smarty' => new \UpgradeSmarty(),
                'employee' => (object) array('id' => $employee),
            );
            // No Db class is defined: any attempt to access it fails this test.
            self::assertStringContainsString('permission', $module->getContent());
        }

        public function deniedRequests()
        {
            return array(
                array('GET', 'expected', 1, true),
                array('POST', 'wrong', 1, true),
                array('POST', array('expected'), 1, true),
                array('POST', 'expected', 0, true),
                array('POST', 'expected', 1, false),
            );
        }
    }
}
