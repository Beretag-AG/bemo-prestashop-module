<?php

namespace Bemo\LiveShopping\Installation;

use Bemo\LiveShopping\Lock\ShopLockInterface;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ModuleUpgradeRecovery
{
    private $versions;
    private $lock;

    public function __construct(ModuleVersionRepositoryInterface $versions, ShopLockInterface $lock)
    {
        $this->versions = $versions;
        $this->lock = $lock;
    }

    public function finish($module, $releaseVersion)
    {
        if ($module->name !== 'bemoliveshopping' || !preg_match('/^\d+\.\d+\.\d+$/D', $releaseVersion)) {
            return false;
        }

        return $this->lock->synchronized('upgrade', 0, function () use ($module, $releaseVersion) {
            $installedVersion = $this->versions->current($module->name);
            if (!is_string($installedVersion)
                || !preg_match('/^\d+\.\d+\.\d+$/D', $installedVersion)
                || version_compare($installedVersion, '0.8.2', '<')
                || version_compare($installedVersion, $releaseVersion, '>')) {
                return false;
            }
            if ($installedVersion === $releaseVersion) {
                return true;
            }

            $migrations = array();
            foreach (glob(dirname(__DIR__, 2) . '/upgrade/upgrade-*.php') as $file) {
                if (!preg_match('/^upgrade-(\d+\.\d+\.\d+)\.php$/D', basename($file), $match)) {
                    continue;
                }
                $version = $match[1];
                if (version_compare($version, $installedVersion, '>') && version_compare($version, $releaseVersion, '<=')) {
                    $migrations[$version] = $file;
                }
            }
            if (!isset($migrations[$releaseVersion])) {
                return false;
            }
            uksort($migrations, 'version_compare');

            // Use the shipped upgrade scripts, without instantiating unrelated modules.
            foreach ($migrations as $version => $file) {
                $function = 'upgrade_module_' . str_replace('.', '_', $version);
                if (!function_exists($function)) {
                    require_once $file;
                }
                if (!function_exists($function)
                    || realpath((new \ReflectionFunction($function))->getFileName()) !== realpath($file)) {
                    return false;
                }
            }
            foreach ($migrations as $version => $file) {
                $function = 'upgrade_module_' . str_replace('.', '_', $version);
                if (call_user_func($function, $module) !== true
                    || !$this->versions->record($module->name, $version)) {
                    return false;
                }
            }

            return $this->versions->current($module->name) === $releaseVersion;
        });
    }
}
