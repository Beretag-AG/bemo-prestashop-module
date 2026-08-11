<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function install()
    {
        if (!$this->db->execute($this->configurationTableSql())) {
            return false;
        }

        if (!$this->db->execute($this->outboxTableSql())) {
            $this->db->execute(
                'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            );

            return false;
        }

        return true;
    }

    private function configurationTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration` (
            `id_bemoliveshopping_configuration` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `api_base_url` VARCHAR(255) NOT NULL DEFAULT \'https://actions.bemo.now\',
            `app_base_url` VARCHAR(255) NOT NULL DEFAULT \'https://bemo.now\',
            `webservice_access_approved` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `embedded_checkout_requested` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
            `webservice_account_id` INT UNSIGNED DEFAULT NULL,
            `webservice_key` CHAR(32) DEFAULT NULL,
            `credentials_api_base_url` VARCHAR(255) DEFAULT NULL,
            `pairing_token` VARCHAR(64) DEFAULT NULL,
            `pairing_token_created_at` DATETIME DEFAULT NULL,
            `pairing_expires_at` DATETIME DEFAULT NULL,
            `webhook_secret` VARCHAR(128) DEFAULT NULL,
            `buy_link_secret` VARCHAR(128) DEFAULT NULL,
            `connection_status` VARCHAR(32) NOT NULL DEFAULT \'not_configured\',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_bemoliveshopping_configuration`),
            UNIQUE KEY `uniq_bemoliveshopping_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    private function outboxTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_outbox` (
            `id_bemoliveshopping_outbox` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `event_id` VARCHAR(64) NOT NULL,
            `payload` MEDIUMTEXT NOT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `available_at` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_bemoliveshopping_outbox`),
            UNIQUE KEY `uniq_bemoliveshopping_event` (`event_id`),
            KEY `idx_bemoliveshopping_due` (`status`, `available_at`),
            KEY `idx_bemoliveshopping_outbox_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    public function upgradeToVersion020()
    {
        $columns = $this->db->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' LIKE \'app_base_url\''
        );

        if (is_array($columns) && $columns !== array()) {
            return true;
        }

        return (bool) $this->db->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' ADD `app_base_url` VARCHAR(255) NOT NULL DEFAULT \'https://bemo.now\''
            . ' AFTER `api_base_url`'
        );
    }

    public function upgradeToVersion030()
    {
        $columns = $this->db->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
        );
        if (!is_array($columns)) {
            return false;
        }

        $existing = array();
        foreach ($columns as $column) {
            if (isset($column['Field'])) {
                $existing[] = $column['Field'];
            }
        }

        if (!in_array('credentials_api_base_url', $existing, true)
            && !$this->db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
                . ' ADD `credentials_api_base_url` VARCHAR(255) DEFAULT NULL AFTER `webservice_key`'
            )) {
            return false;
        }
        if (!in_array('pairing_expires_at', $existing, true)
            && !$this->db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
                . ' ADD `pairing_expires_at` DATETIME DEFAULT NULL AFTER `pairing_token_created_at`'
            )) {
            return false;
        }

        if (!$this->db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . " SET `api_base_url` = 'https://actions.bemo.now'"
            . " WHERE `api_base_url` = ''"
        )) {
            return false;
        }

        return (bool) $this->db->execute($this->outboxTableSql());
    }

    public function upgradeToVersion034()
    {
        $columns = $this->db->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
        );
        if (!is_array($columns)) {
            return false;
        }

        $existing = array();
        foreach ($columns as $column) {
            if (isset($column['Field'])) {
                $existing[] = $column['Field'];
            }
        }

        if (!in_array('webservice_access_approved', $existing, true)
            && !$this->db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
                . ' ADD `webservice_access_approved` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
                . ' AFTER `app_base_url`'
            )) {
            return false;
        }
        if (!in_array('embedded_checkout_requested', $existing, true)
            && !$this->db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
                . ' ADD `embedded_checkout_requested` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0'
                . ' AFTER `webservice_access_approved`'
            )) {
            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $outboxRemoved = (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
        );
        $configurationRemoved = (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
        );

        return $outboxRemoved && $configurationRemoved;
    }
}
