<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface BuyLinkNonceRepositoryInterface
{
    public function claim($shopId, $nonce, $expiresAtMs);

    public function purgeExpiredBefore($timestamp);
}
