<?php

namespace Bemo\LiveShopping\Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

interface ConfigurationRepositoryInterface
{
    public function getApiBaseUrl($shopId);

    public function saveApiBaseUrl($shopId, $apiBaseUrl);

    public function getWebserviceAccountId($shopId);

    public function getWebserviceAccountIds();

    public function saveProvisionedCredentials(
        $shopId,
        $webserviceAccountId,
        $webserviceKey,
        $pairingToken,
        $webhookSecret,
        $buyLinkSecret
    );
}
