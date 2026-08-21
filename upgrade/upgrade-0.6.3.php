<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_6_3($module)
{
    return $module->upgradeToVersion063();
}
