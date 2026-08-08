<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface PairingGatewayInterface
{
    public function start($apiBaseUrl, array $payload);
}
