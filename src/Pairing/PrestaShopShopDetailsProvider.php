<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

class PrestaShopShopDetailsProvider implements ShopDetailsProviderInterface
{
    /** @var EndpointNormalizer */
    private $endpoints;

    /** @var bool */
    private $embeddedCheckoutConfirmed;

    public function __construct(EndpointNormalizer $endpoints, $embeddedCheckoutConfirmed = false)
    {
        $this->endpoints = $endpoints;
        $this->embeddedCheckoutConfirmed = (bool) $embeddedCheckoutConfirmed;
    }

    public function get($shopId)
    {
        $shop = new \Shop((int) $shopId);
        if (!\Validate::isLoadedObject($shop)) {
            throw new PairingException(PairingException::SHOP_CONTEXT);
        }

        $shopUrl = $this->endpoints->normalizeShopUrl(
            $this->canonicalShopUrl($shop, (int) $shopId)
        );
        $languages = $this->languageIsoCodes((int) $shopId);
        $languageId = (int) \Configuration::get(
            'PS_LANG_DEFAULT',
            null,
            (int) $shop->id_shop_group,
            (int) $shopId
        );
        $currencies = $this->currencyIsoCodes($shop, (int) $shopId);
        $embeddedCheckoutReady = $this->isEmbeddedCheckoutReady($shop, (int) $shopId);

        if ($shopUrl === null || $languageId <= 0 || $languages === array() || $currencies === array()) {
            throw new PairingException(PairingException::SHOP_CONTEXT);
        }

        return array(
            'shopUrl' => $shopUrl,
            'platformVersion' => _PS_VERSION_,
            'languageId' => $languageId,
            'languages' => $languages,
            'currencies' => $currencies,
            'embeddedCheckoutReady' => $embeddedCheckoutReady,
        );
    }

    private function isEmbeddedCheckoutReady($shop, $shopId)
    {
        $sslEnabled = (bool) \Configuration::get(
            'PS_SSL_ENABLED_EVERYWHERE',
            null,
            (int) $shop->id_shop_group,
            $shopId
        );
        $sameSite = \Configuration::get(
            'PS_COOKIE_SAMESITE',
            null,
            (int) $shop->id_shop_group,
            $shopId
        );

        return $this->embeddedCheckoutConfirmed
            && $sslEnabled
            && is_string($sameSite)
            && strtolower($sameSite) === 'none';
    }

    private function canonicalShopUrl($shop, $shopId)
    {
        $sslEnabled = (bool) \Configuration::get(
            'PS_SSL_ENABLED',
            null,
            (int) $shop->id_shop_group,
            $shopId
        );
        if ($sslEnabled && is_string($shop->domain_ssl) && $shop->domain_ssl !== '') {
            return 'https://' . $shop->domain_ssl . $shop->getBaseURI();
        }

        return $shop->getBaseURL(true, true);
    }

    private function languageIsoCodes($shopId)
    {
        $isoCodes = array();
        foreach (\Language::getLanguages(true, $shopId) as $language) {
            if (isset($language['iso_code']) && is_string($language['iso_code'])) {
                $isoCodes[] = strtolower($language['iso_code']);
            }
        }

        return array_values(array_unique($isoCodes));
    }

    private function currencyIsoCodes($shop, $shopId)
    {
        $defaultCurrencyId = (int) \Configuration::get(
            'PS_CURRENCY_DEFAULT',
            null,
            (int) $shop->id_shop_group,
            $shopId
        );
        $currencies = array();

        foreach (\Currency::getCurrenciesByIdShop($shopId) as $currency) {
            $row = is_object($currency) ? get_object_vars($currency) : $currency;
            if (!is_array($row) || empty($row['iso_code'])) {
                continue;
            }

            if ((isset($row['active']) && !(bool) $row['active'])
                || (isset($row['deleted']) && (bool) $row['deleted'])) {
                continue;
            }

            $id = isset($row['id_currency'])
                ? (int) $row['id_currency']
                : (isset($row['id']) ? (int) $row['id'] : 0);
            $isoCode = strtoupper($row['iso_code']);
            if (!preg_match('/^[A-Z]{3}$/', $isoCode)) {
                continue;
            }
            if ($id === $defaultCurrencyId) {
                array_unshift($currencies, $isoCode);
            } else {
                $currencies[] = $isoCode;
            }
        }

        return array_values(array_unique($currencies));
    }
}
