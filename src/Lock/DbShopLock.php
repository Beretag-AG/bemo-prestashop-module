<?php

namespace Bemo\LiveShopping\Lock;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbShopLock implements ShopLockInterface
{
    const TIMEOUT_SECONDS = 10;

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function synchronized($scope, $shopId, $callback)
    {
        $name = substr('bemo:' . sha1(_DB_PREFIX_) . ':' . preg_replace('/[^a-z0-9_-]/i', '', $scope) . ':' . (int) $shopId, 0, 64);
        $quotedName = pSQL($name);
        $acquired = $this->db->getValue(
            "SELECT GET_LOCK('" . $quotedName . "', " . self::TIMEOUT_SECONDS . ')'
        );
        if ((string) $acquired !== '1') {
            throw new LockUnavailableException('The BEMO shop configuration is busy. Retry in a moment.');
        }

        try {
            return call_user_func($callback);
        } finally {
            $this->db->getValue("SELECT RELEASE_LOCK('" . $quotedName . "')");
        }
    }
}
