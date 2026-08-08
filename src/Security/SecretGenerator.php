<?php

namespace Bemo\LiveShopping\Security;

if (!defined('_PS_VERSION_')) {
    exit;
}

class SecretGenerator
{
    public function pairingToken()
    {
        return $this->base64Url(random_bytes(16));
    }

    public function directionalSecret()
    {
        return $this->base64Url(random_bytes(32));
    }

    public function webserviceKey()
    {
        return bin2hex(random_bytes(16));
    }

    public function eventId()
    {
        return 'prestashop:' . $this->base64Url(random_bytes(16));
    }

    private function base64Url($bytes)
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
