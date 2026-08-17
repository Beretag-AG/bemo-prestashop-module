<?php

namespace Bemo\LiveShopping\Checkout;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbBuyLinkNonceRepository implements BuyLinkNonceRepositoryInterface
{
    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function claim($shopId, $nonce, $expiresAtMs)
    {
        // A duplicate nonce is a replay, not an error, so the unique key decides
        // the outcome instead of a read followed by a racing insert.
        $inserted = (bool) $this->db->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'bemoliveshopping_buy_nonce`'
            . ' (`id_shop`, `nonce`, `expires_at`, `date_add`) VALUES ('
            . (int) $shopId
            . ", '" . pSQL($nonce) . "'"
            . ", '" . pSQL(date('Y-m-d H:i:s', (int) floor((int) $expiresAtMs / 1000))) . "'"
            . ", '" . pSQL(date('Y-m-d H:i:s')) . "')"
        );

        return $inserted && (int) $this->db->Affected_Rows() === 1;
    }

    public function purgeExpiredBefore($timestamp)
    {
        return (bool) $this->db->delete(
            'bemoliveshopping_buy_nonce',
            "`expires_at` < '" . pSQL(date('Y-m-d H:i:s', (int) $timestamp)) . "'"
        );
    }
}
