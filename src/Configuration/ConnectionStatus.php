<?php

namespace Bemo\LiveShopping\Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ConnectionStatus
{
    const NOT_CONFIGURED = 'not_configured';
    const READY_TO_PAIR = 'ready_to_pair';
    const PAIRING_STARTING = 'pairing_starting';
    const PAIRING_PENDING = 'pairing_pending';
    const CONNECTED = 'connected';
}
