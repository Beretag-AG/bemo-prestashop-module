<?php

namespace Bemo\LiveShopping\Tests\Installation;

use PHPUnit\Framework\TestCase;

class UpgradeReleaseCoverageTest extends TestCase
{
    public function testUpgradeFrom082RunsThroughTheAdvertisedRelease()
    {
        $root = dirname(__DIR__, 2);
        $moduleSource = file_get_contents($root . '/bemoliveshopping.php');
        self::assertRegExp("/const VERSION = '([^']+)';/", $moduleSource);
        preg_match("/const VERSION = '([^']+)';/", $moduleSource, $versionMatch);
        $releaseVersion = $versionMatch[1];

        self::assertSame('0.8.6', $releaseVersion);
        self::assertStringContainsString(
            '<version><![CDATA[' . $releaseVersion . ']]></version>',
            file_get_contents($root . '/config.xml')
        );

        $upgradeFiles = array_filter(glob($root . '/upgrade/upgrade-*.php'), function ($path) use ($releaseVersion) {
            $version = basename($path, '.php');
            $version = substr($version, strlen('upgrade-'));

            return version_compare($version, '0.8.2', '>')
                && version_compare($version, $releaseVersion, '<=');
        });
        usort($upgradeFiles, function ($left, $right) {
            return version_compare($this->upgradeVersion($left), $this->upgradeVersion($right));
        });

        $module = new ReleaseUpgradeModule();
        foreach ($upgradeFiles as $upgradeFile) {
            require_once $upgradeFile;
            $version = $this->upgradeVersion($upgradeFile);
            $upgradeFunction = 'upgrade_module_' . str_replace('.', '_', $version);
            self::assertTrue(function_exists($upgradeFunction));
            self::assertTrue(call_user_func($upgradeFunction, $module));
        }

        self::assertSame(array('0.8.3', '0.8.5', '0.8.6'), $module->versions);
        self::assertSame($releaseVersion, $this->upgradeVersion(end($upgradeFiles)));
    }

    private function upgradeVersion($path)
    {
        $filename = basename($path, '.php');

        return substr($filename, strlen('upgrade-'));
    }
}

class ReleaseUpgradeModule
{
    public $versions = array();

    public function upgradeToVersion083()
    {
        $this->versions[] = '0.8.3';

        return true;
    }

    public function upgradeToVersion086()
    {
        $this->versions[] = '0.8.6';

        return true;
    }

    public function upgradeToVersion085()
    {
        $this->versions[] = '0.8.5';

        return true;
    }
}
