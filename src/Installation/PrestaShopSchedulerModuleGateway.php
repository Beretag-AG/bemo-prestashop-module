<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Module;

class PrestaShopSchedulerModuleGateway implements SchedulerModuleGatewayInterface
{
    const MODULE_NAME = 'cronjobs';

    public function isInstalled()
    {
        return Module::isInstalled(self::MODULE_NAME);
    }

    public function isEnabled()
    {
        return Module::isEnabled(self::MODULE_NAME);
    }

    public function install()
    {
        $module = Module::getInstanceByName(self::MODULE_NAME);

        return $module instanceof Module && $module->install();
    }

    public function enable()
    {
        $module = Module::getInstanceByName(self::MODULE_NAME);

        return $module instanceof Module && $module->enable();
    }
}
