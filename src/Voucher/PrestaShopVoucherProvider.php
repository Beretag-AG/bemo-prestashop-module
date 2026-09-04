<?php

namespace Bemo\LiveShopping\Voucher;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaShopVoucherProvider
{
    const MAX_VOUCHERS = 100;

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function listForShop($shopId)
    {
        $rows = $this->db->executeS(
            'SELECT cr.`id_cart_rule`, cr.`code`, cr.`quantity`, cr.`date_from`, cr.`date_to`, cr.`active`'
            . ' FROM `' . _DB_PREFIX_ . 'cart_rule` cr'
            . ' INNER JOIN `' . _DB_PREFIX_ . 'cart_rule_shop` crs'
            . ' ON crs.`id_cart_rule` = cr.`id_cart_rule`'
            . ' WHERE crs.`id_shop` = ' . (int) $shopId
            . ' AND cr.`id_customer` = 0'
            . " AND cr.`code` <> ''"
            . ' ORDER BY cr.`id_cart_rule` ASC'
            . ' LIMIT ' . self::MAX_VOUCHERS
        );
        if (!is_array($rows)) {
            throw new \RuntimeException('Unable to read vouchers');
        }

        return array_map(function ($row) {
            return array(
                'externalVoucherId' => (int) $row['id_cart_rule'],
                'code' => (string) $row['code'],
                'quantity' => isset($row['quantity']) ? (int) $row['quantity'] : null,
                'validFrom' => $this->epochMilliseconds($row['date_from']),
                'validTo' => $this->epochMilliseconds($row['date_to']),
                'active' => (bool) $row['active'],
            );
        }, $rows);
    }

    private function epochMilliseconds($value)
    {
        $timestamp = is_string($value) ? strtotime($value) : false;

        return $timestamp === false ? null : $timestamp * 1000;
    }
}
