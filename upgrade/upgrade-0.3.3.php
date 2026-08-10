<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_0_3_3($module)
{
    return $module instanceof Bemoliveshopping;
}
