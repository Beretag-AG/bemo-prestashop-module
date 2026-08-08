<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ShopDetailsProviderInterface
{
    public function get($shopId);
}
