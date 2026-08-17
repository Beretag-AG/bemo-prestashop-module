<?php

namespace Bemo\LiveShopping\Configuration;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Checkout\CheckoutLanding;

interface ConfigurationRepositoryInterface
{
    public function getApiBaseUrl($shopId);

    public function getAppBaseUrl($shopId);

    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl);

    public function saveActivationChoices(
        $shopId,
        $webserviceAccessApproved,
        $embeddedCheckoutRequested,
        $checkoutLanding = CheckoutLanding::CART
    );

    public function isWebserviceAccessApproved($shopId);

    public function isEmbeddedCheckoutRequested($shopId);

    public function getCheckoutLanding($shopId);

    public function getConnectionStatus($shopId);

    public function getCronToken($shopId);

    public function saveCronToken($shopId, $cronToken);

    public function getWebserviceAccountId($shopId);

    public function getWebserviceAccountIds();

    public function getPairingCredentials($shopId);

    public function getPairingAttempt($shopId);

    public function beginPairingAttempt($shopId, $pairingToken);

    public function markPairingStarted($shopId, $pairingToken, $expiresAt);

    public function clearPairingAttempt($shopId, $pairingToken);

    public function clearProvisionedCredentials($shopId);

    public function saveProvisionedCredentials(
        $shopId,
        $webserviceAccountId,
        $webserviceKey,
        $webhookSecret,
        $buyLinkSecret,
        $apiBaseUrl
    );
}
