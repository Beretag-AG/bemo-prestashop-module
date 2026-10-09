<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_9_2($module)
{
    return $module->upgradeToVersion092();
}
