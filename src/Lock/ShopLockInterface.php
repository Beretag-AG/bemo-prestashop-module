<?php

namespace Bemo\LiveShopping\Lock;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ShopLockInterface
{
    public function synchronized($scope, $shopId, $callback);
}
