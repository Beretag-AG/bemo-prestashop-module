<?php

namespace Bemo\LiveShopping\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ConnectionSetupInterface
{
    public function provision($shopId, $apiBaseUrl);
}
