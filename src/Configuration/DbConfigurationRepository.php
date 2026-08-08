<?php

namespace Bemo\LiveShopping\Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbConfigurationRepository implements ConfigurationRepositoryInterface
{
    const DEFAULT_API_BASE_URL = '';

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getApiBaseUrl($shopId)
    {
        $value = $this->db->getValue(
            'SELECT `api_base_url` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        return is_string($value) && $value !== '' ? $value : self::DEFAULT_API_BASE_URL;
    }

    public function saveApiBaseUrl($shopId, $apiBaseUrl)
    {
        return $this->upsert($shopId, array(
            'api_base_url' => pSQL($apiBaseUrl),
        ));
    }

    public function getWebserviceAccountId($shopId)
    {
        $value = $this->db->getValue(
            'SELECT `webservice_account_id` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    public function getWebserviceAccountIds()
    {
        $rows = $this->db->executeS(
            'SELECT `webservice_account_id` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `webservice_account_id` IS NOT NULL'
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_map(function ($row) {
            return (int) $row['webservice_account_id'];
        }, $rows);
    }

    public function saveProvisionedCredentials(
        $shopId,
        $webserviceAccountId,
        $webserviceKey,
        $pairingToken,
        $webhookSecret,
        $buyLinkSecret
    ) {
        return $this->upsert($shopId, array(
            'webservice_account_id' => (int) $webserviceAccountId,
            'webservice_key' => pSQL($webserviceKey),
            'pairing_token' => pSQL($pairingToken),
            'pairing_token_created_at' => date('Y-m-d H:i:s'),
            'webhook_secret' => pSQL($webhookSecret),
            'buy_link_secret' => pSQL($buyLinkSecret),
            'connection_status' => 'ready_to_pair',
        ));
    }

    private function upsert($shopId, array $values)
    {
        $now = date('Y-m-d H:i:s');
        $existing = (bool) $this->db->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        if ($existing) {
            $values['date_upd'] = $now;

            return (bool) $this->db->update(
                'bemoliveshopping_configuration',
                $values,
                '`id_shop` = ' . (int) $shopId
            );
        }

        $values['id_shop'] = (int) $shopId;
        $values['date_add'] = $now;
        $values['date_upd'] = $now;

        return (bool) $this->db->insert('bemoliveshopping_configuration', $values);
    }
}
