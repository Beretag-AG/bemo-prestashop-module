<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

class InstalledVersionReconciler
{
    const RELEASE_VERSION = '0.8.2';
    const LAST_MIGRATION_VERSION = '0.7.0';

    /** @var ModuleVersionRepositoryInterface */
    private $versions;

    public function __construct(ModuleVersionRepositoryInterface $versions)
    {
        $this->versions = $versions;
    }

    public function reconcile($moduleName, $releaseVersion)
    {
        $installedVersion = $this->versions->current($moduleName);
        if (!is_string($installedVersion)) {
            return false;
        }

        if (version_compare($installedVersion, $releaseVersion, '>=')) {
            return true;
        }

        if ($releaseVersion !== self::RELEASE_VERSION
            || version_compare($installedVersion, self::LAST_MIGRATION_VERSION, '<')) {
            return false;
        }

        return $this->versions->record($moduleName, $releaseVersion);
    }
}
