<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaShopModuleVersionRepository implements ModuleVersionRepositoryInterface
{
    public function current($moduleName)
    {
        $version = \Db::getInstance()->getValue(
            'SELECT `version` FROM `' . _DB_PREFIX_ . 'module`'
            . " WHERE `name` = '" . pSQL($moduleName) . "'"
        );

        return is_string($version) ? $version : null;
    }

    public function record($moduleName, $version)
    {
        return (bool) \Module::upgradeModuleVersion($moduleName, $version);
    }
}
