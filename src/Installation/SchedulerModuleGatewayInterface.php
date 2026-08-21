<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface SchedulerModuleGatewayInterface
{
    public function isInstalled();
    public function isEnabled();
    public function install();
    public function enable();
}
