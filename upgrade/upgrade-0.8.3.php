<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_8_3($module)
{
    return $module->upgradeToVersion083();
}
