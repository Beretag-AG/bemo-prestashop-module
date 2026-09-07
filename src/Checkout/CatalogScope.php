<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class CatalogScope
{
    /** @var int */
    private $shopId;

    /** @var int */
    private $shopGroupId;

    /** @var bool */
    private $sharesStock;

    public function __construct($shopId, $shopGroupId, $sharesStock)
    {
        $this->shopId = (int) $shopId;
        $this->shopGroupId = (int) $shopGroupId;
        $this->sharesStock = (bool) $sharesStock;
    }

    public function details()
    {
        return array(
            'shopId' => $this->shopId,
            'stockShopId' => $this->sharesStock ? 0 : $this->shopId,
            'stockShopGroupId' => $this->sharesStock ? $this->shopGroupId : 0,
        );
    }
}
