<?php

namespace Bemo\LiveShopping\Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbConfigurationRepository implements ConfigurationRepositoryInterface
{
    const DEFAULT_API_BASE_URL = 'https://actions.bemo.now';
    const DEFAULT_APP_BASE_URL = 'https://bemo.now';

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getApiBaseUrl($shopId)
    {
        return $this->getEndpoint($shopId, 'api_base_url', self::DEFAULT_API_BASE_URL);
    }

    public function getAppBaseUrl($shopId)
    {
        return $this->getEndpoint($shopId, 'app_base_url', self::DEFAULT_APP_BASE_URL);
    }

    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl)
    {
        return $this->upsert($shopId, array(
            'api_base_url' => pSQL($apiBaseUrl),
            'app_base_url' => pSQL($appBaseUrl),
        ));
    }

    public function saveActivationChoices(
        $shopId,
        $webserviceAccessApproved,
        $embeddedCheckoutRequested,
        $checkoutLanding = 'cart'
    )
    {
        return $this->upsert($shopId, array(
            'webservice_access_approved' => $webserviceAccessApproved ? 1 : 0,
            'embedded_checkout_requested' => $embeddedCheckoutRequested ? 1 : 0,
            'checkout_landing' => 'cart',
        ));
    }

    public function isWebserviceAccessApproved($shopId)
    {
        return $this->getBooleanSetting($shopId, 'webservice_access_approved');
    }

    public function isEmbeddedCheckoutRequested($shopId)
    {
        return $this->getBooleanSetting($shopId, 'embedded_checkout_requested');
    }

    public function getCheckoutLanding($shopId)
    {
        return 'cart';
    }

    public function getConnectionStatus($shopId)
    {
        $value = $this->db->getValue(
            'SELECT `connection_status` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        return is_string($value) && $value !== '' ? $value : ConnectionStatus::NOT_CONFIGURED;
    }

    public function getCronToken($shopId)
    {
        $value = $this->db->getValue(
            'SELECT `cron_token` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function saveCronToken($shopId, $cronToken)
    {
        return $this->upsert($shopId, array(
            'cron_token' => pSQL($cronToken),
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

    public function getPairingCredentials($shopId)
    {
        $row = $this->db->getRow(
            'SELECT `webservice_key`, `webhook_secret`, `buy_link_secret`, `credentials_api_base_url`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        if (!is_array($row)) {
            return null;
        }

        foreach (array('webservice_key', 'webhook_secret', 'buy_link_secret', 'credentials_api_base_url') as $field) {
            if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '') {
                return null;
            }
        }

        return $row;
    }

    public function getPairingAttempt($shopId)
    {
        $row = $this->db->getRow(
            'SELECT `pairing_token`, `pairing_token_created_at`, `pairing_expires_at`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );
        if (!is_array($row) || empty($row['pairing_token']) || empty($row['pairing_token_created_at'])) {
            return null;
        }

        $expiresAt = !empty($row['pairing_expires_at'])
            ? strtotime($row['pairing_expires_at'])
            : strtotime($row['pairing_token_created_at']) + 900;
        if ($expiresAt === false) {
            return null;
        }

        return array(
            'pairing_token' => $row['pairing_token'],
            'expires_at' => $expiresAt * 1000,
        );
    }

    public function beginPairingAttempt($shopId, $pairingToken)
    {
        return $this->upsert($shopId, array(
            'pairing_token' => pSQL($pairingToken),
            'pairing_token_created_at' => date('Y-m-d H:i:s'),
            'pairing_expires_at' => null,
            'connection_status' => ConnectionStatus::PAIRING_STARTING,
        ));
    }

    public function markPairingStarted($shopId, $pairingToken, $expiresAt)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_configuration',
            array(
                'connection_status' => ConnectionStatus::PAIRING_PENDING,
                'pairing_expires_at' => date('Y-m-d H:i:s', (int) floor($expiresAt / 1000)),
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = ' . (int) $shopId . " AND `pairing_token` = '" . pSQL($pairingToken) . "'"
        );
    }

    public function clearPairingAttempt($shopId, $pairingToken)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_configuration',
            array(
                'pairing_token' => null,
                'pairing_token_created_at' => null,
                'pairing_expires_at' => null,
                'connection_status' => ConnectionStatus::READY_TO_PAIR,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = ' . (int) $shopId . " AND `pairing_token` = '" . pSQL($pairingToken) . "'",
            0,
            true
        );
    }

    public function markPairingClaimed($shopId, $pairingToken)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_configuration',
            array(
                'pairing_token' => null,
                'pairing_token_created_at' => null,
                'pairing_expires_at' => null,
                'connection_status' => ConnectionStatus::CONNECTED,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = ' . (int) $shopId . " AND `pairing_token` = '" . pSQL($pairingToken) . "'",
            0,
            true
        );
    }

    public function clearProvisionedCredentials($shopId)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_configuration',
            array(
                'webservice_account_id' => null,
                'webservice_key' => null,
                'credentials_api_base_url' => null,
                'pairing_token' => null,
                'pairing_token_created_at' => null,
                'pairing_expires_at' => null,
                'webhook_secret' => null,
                'buy_link_secret' => null,
                'connection_status' => ConnectionStatus::NOT_CONFIGURED,
                'date_upd' => date('Y-m-d H:i:s'),
            ),
            '`id_shop` = ' . (int) $shopId,
            0,
            true
        );
    }

    public function saveProvisionedCredentials(
        $shopId,
        $webserviceAccountId,
        $webserviceKey,
        $webhookSecret,
        $buyLinkSecret,
        $apiBaseUrl
    ) {
        return $this->upsert($shopId, array(
            'webservice_account_id' => (int) $webserviceAccountId,
            'webservice_key' => pSQL($webserviceKey),
            'credentials_api_base_url' => pSQL($apiBaseUrl),
            'webhook_secret' => pSQL($webhookSecret),
            'buy_link_secret' => pSQL($buyLinkSecret),
            'connection_status' => ConnectionStatus::READY_TO_PAIR,
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
                '`id_shop` = ' . (int) $shopId,
                0,
                in_array(null, $values, true)
            );
        }

        $values['id_shop'] = (int) $shopId;
        $values['date_add'] = $now;
        $values['date_upd'] = $now;

        return (bool) $this->db->insert(
            'bemoliveshopping_configuration',
            $values,
            in_array(null, $values, true)
        );
    }

    private function getEndpoint($shopId, $column, $default)
    {
        $value = $this->db->getValue(
            'SELECT `' . $column . '` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function getBooleanSetting($shopId, $column)
    {
        return (bool) $this->db->getValue(
            'SELECT `' . $column . '` FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' WHERE `id_shop` = ' . (int) $shopId
        );
    }
}
