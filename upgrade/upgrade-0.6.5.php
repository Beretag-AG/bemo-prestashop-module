<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_5($module)
{
    return $module->upgradeToVersion065();
}
