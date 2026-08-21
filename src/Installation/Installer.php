<?php

namespace Bemo\LiveShopping\Installation;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Installer
{
    const OUTBOX_PENDING_INDEX = 'idx_bemoliveshopping_outbox_pending';

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function install()
    {
        $tables = array(
            $this->configurationTableSql(),
            $this->outboxTableSql(),
            $this->buyNonceTableSql(),
        );

        foreach ($tables as $sql) {
            if (!$this->db->execute($sql)) {
                $this->uninstall();

                return false;
            }
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
            `checkout_landing` VARCHAR(16) NOT NULL DEFAULT \'cart\',
            `webservice_account_id` INT UNSIGNED DEFAULT NULL,
            `webservice_key` CHAR(32) DEFAULT NULL,
            `credentials_api_base_url` VARCHAR(255) DEFAULT NULL,
            `pairing_token` VARCHAR(64) DEFAULT NULL,
            `pairing_token_created_at` DATETIME DEFAULT NULL,
            `pairing_expires_at` DATETIME DEFAULT NULL,
            `webhook_secret` VARCHAR(128) DEFAULT NULL,
            `buy_link_secret` VARCHAR(128) DEFAULT NULL,
            `connection_status` VARCHAR(32) NOT NULL DEFAULT \'not_configured\',
            `cron_token` VARCHAR(64) DEFAULT NULL,
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
            `resource_key` VARCHAR(128) NOT NULL DEFAULT \'\',
            `payload` MEDIUMTEXT NOT NULL,
            `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `available_at` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_bemoliveshopping_outbox`),
            UNIQUE KEY `uniq_bemoliveshopping_event` (`event_id`),
            KEY `idx_bemoliveshopping_due` (`status`, `available_at`),
            KEY `idx_bemoliveshopping_outbox_shop` (`id_shop`),
            KEY `' . self::OUTBOX_PENDING_INDEX . '` (`id_shop`, `status`, `resource_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';
    }

    private function buyNonceTableSql()
    {
        return 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_buy_nonce` (
            `id_bemoliveshopping_buy_nonce` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `nonce` VARCHAR(128) NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_bemoliveshopping_buy_nonce`),
            UNIQUE KEY `uniq_bemoliveshopping_buy_nonce` (`id_shop`, `nonce`),
            KEY `idx_bemoliveshopping_buy_nonce_expiry` (`expires_at`)
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

    public function upgradeToVersion040()
    {
        $columns = $this->db->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . ' LIKE \'checkout_landing\''
        );

        if (is_array($columns) && $columns !== array()) {
            return true;
        }

        return (bool) $this->db->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . " ADD `checkout_landing` VARCHAR(16) NOT NULL DEFAULT 'cart'"
            . ' AFTER `embedded_checkout_requested`'
        );
    }

    public function upgradeToVersion050()
    {
        if (!$this->addColumnOnce('bemoliveshopping_configuration', 'cron_token', 'VARCHAR(64) DEFAULT NULL AFTER `connection_status`')
            || !$this->addColumnOnce('bemoliveshopping_outbox', 'resource_key', "VARCHAR(128) NOT NULL DEFAULT '' AFTER `event_id`")) {
            return false;
        }

        if (!$this->hasIndex('bemoliveshopping_outbox', self::OUTBOX_PENDING_INDEX)
            && !$this->db->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
                . ' ADD KEY `' . self::OUTBOX_PENDING_INDEX . '` (`id_shop`, `status`, `resource_key`)'
            )) {
            return false;
        }

        return (bool) $this->db->execute($this->buyNonceTableSql());
    }

    public function upgradeToVersion070()
    {
        return (bool) $this->db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
            . " SET `checkout_landing` = 'cart' WHERE `checkout_landing` <> 'cart'"
        );
    }

    public function uninstall()
    {
        $nonceRemoved = (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_buy_nonce`'
        );
        $outboxRemoved = (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
        );
        $configurationRemoved = (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
        );

        return $nonceRemoved && $outboxRemoved && $configurationRemoved;
    }

    private function addColumnOnce($table, $column, $definition)
    {
        $columns = $this->db->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . $table . '` LIKE \'' . pSQL($column) . '\''
        );
        if (is_array($columns) && $columns !== array()) {
            return true;
        }

        return (bool) $this->db->execute(
            'ALTER TABLE `' . _DB_PREFIX_ . $table . '` ADD `' . $column . '` ' . $definition
        );
    }

    private function hasIndex($table, $indexName)
    {
        $indexes = $this->db->executeS(
            'SHOW INDEX FROM `' . _DB_PREFIX_ . $table . '`'
        );
        if (!is_array($indexes)) {
            return false;
        }

        foreach ($indexes as $index) {
            if (isset($index['Key_name']) && $index['Key_name'] === $indexName) {
                return true;
            }
        }

        return false;
    }
}
