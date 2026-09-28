<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_9_1($module)
{
    return $module->upgradeToVersion091();
}
