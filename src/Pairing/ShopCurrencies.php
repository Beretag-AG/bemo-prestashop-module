<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class ShopCurrencies
{
    public function activeIsoCodes(array $currencies, $defaultCurrencyId)
    {
        $defaultIsoCode = null;
        $otherIsoCodes = array();

        foreach ($currencies as $currency) {
            $row = is_object($currency) ? get_object_vars($currency) : $currency;
            if (!is_array($row) || empty($row['iso_code'])) {
                continue;
            }
            if ((isset($row['active']) && !(bool) $row['active'])
                || (isset($row['deleted']) && (bool) $row['deleted'])) {
                continue;
            }

            $currencyId = isset($row['id_currency'])
                ? (int) $row['id_currency']
                : (isset($row['id']) ? (int) $row['id'] : 0);
            $isoCode = strtoupper($row['iso_code']);
            if (!preg_match('/^[A-Z]{3}$/', $isoCode)) {
                continue;
            }
            if ($currencyId === (int) $defaultCurrencyId) {
                $defaultIsoCode = $isoCode;
            } else {
                $otherIsoCodes[] = $isoCode;
            }
        }

        if ($defaultIsoCode === null) {
            return array();
        }

        return array_values(array_unique(array_merge(
            array($defaultIsoCode),
            $otherIsoCodes
        )));
    }
}
