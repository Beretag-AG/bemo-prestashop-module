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
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration` (
            `id_bemoliveshopping_configuration` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_shop` INT UNSIGNED NOT NULL,
            `api_base_url` VARCHAR(255) NOT NULL DEFAULT \'\',
            `webservice_account_id` INT UNSIGNED DEFAULT NULL,
            `webservice_key` CHAR(32) DEFAULT NULL,
            `pairing_token` VARCHAR(64) DEFAULT NULL,
            `pairing_token_created_at` DATETIME DEFAULT NULL,
            `webhook_secret` VARCHAR(128) DEFAULT NULL,
            `buy_link_secret` VARCHAR(128) DEFAULT NULL,
            `connection_status` VARCHAR(32) NOT NULL DEFAULT \'not_configured\',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_bemoliveshopping_configuration`),
            UNIQUE KEY `uniq_bemoliveshopping_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        return (bool) $this->db->execute($sql);
    }

    public function uninstall()
    {
        return (bool) $this->db->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'bemoliveshopping_configuration`'
        );
    }
}
