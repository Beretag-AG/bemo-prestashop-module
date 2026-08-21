<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface PairingStatusGatewayInterface
{
    public function status($apiBaseUrl, $pairingToken);
}
