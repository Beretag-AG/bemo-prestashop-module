<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Installation\Installer;

function upgrade_module_0_3_0($module)
{
    return (new Installer(Db::getInstance()))->upgradeToVersion030();
}
