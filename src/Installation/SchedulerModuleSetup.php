<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SchedulerModuleSetup
{
    /** @var SchedulerModuleGatewayInterface */
    private $gateway;

    public function __construct(SchedulerModuleGatewayInterface $gateway)
    {
        $this->gateway = $gateway;
    }

    public function ensureAvailable()
    {
        if (!$this->gateway->isInstalled()) {
            return $this->gateway->install();
        }

        return $this->gateway->isEnabled() || $this->gateway->enable();
    }
}
