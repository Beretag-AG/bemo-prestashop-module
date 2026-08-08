<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface WebhookGatewayInterface
{
    public function deliver($apiBaseUrl, $rawPayload, $secret);
}
