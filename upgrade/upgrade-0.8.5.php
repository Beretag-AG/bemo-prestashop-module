<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_8_5($module)
{
    return $module->upgradeToVersion085();
}
