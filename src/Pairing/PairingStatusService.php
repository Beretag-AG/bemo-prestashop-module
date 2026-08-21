<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Configuration\DbConfigurationRepository;

class PairingStatusService
{
    /** @var ConfigurationRepositoryInterface */
    private $configuration;

    /** @var PairingStatusGatewayInterface */
    private $gateway;

    public function __construct(
        ConfigurationRepositoryInterface $configuration,
        PairingStatusGatewayInterface $gateway
    ) {
        $this->configuration = $configuration;
        $this->gateway = $gateway;
    }

    public function reconcile($shopId)
    {
        if ($this->configuration->getConnectionStatus($shopId) !== DbConfigurationRepository::STATUS_PAIRING_PENDING) {
            return false;
        }

        $attempt = $this->configuration->getPairingAttempt($shopId);
        if (!is_array($attempt) || !isset($attempt['pairing_token'])) {
            return false;
        }

        $pairingToken = $attempt['pairing_token'];
        $status = $this->gateway->status(
            $this->configuration->getApiBaseUrl($shopId),
            $pairingToken
        );

        if ($status === 'claimed') {
            return $this->configuration->markPairingClaimed($shopId, $pairingToken);
        }

        if ($status === 'expired') {
            return $this->configuration->clearPairingAttempt($shopId, $pairingToken);
        }

        return false;
    }
}
