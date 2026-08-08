<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Installation\Installer;

function upgrade_module_0_2_0($module)
{
    return (new Installer(Db::getInstance()))->upgradeToVersion020();
}
