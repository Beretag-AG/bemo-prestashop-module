<?php

namespace Bemo\LiveShopping\Pairing;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupInterface;

class PairingService
{
    const CLAIM_PATH = '/settings/creator/integrations';

    /** @var ConfigurationRepositoryInterface */
    private $configuration;

    /** @var ConnectionSetupInterface */
    private $setup;

    /** @var PairingGatewayInterface */
    private $gateway;

    /** @var ShopDetailsProviderInterface */
    private $shopDetails;

    /** @var SecretGenerator */
    private $secrets;

    /** @var EndpointPolicy */
    private $endpointPolicy;

    /** @var EndpointNormalizer */
    private $endpoints;

    /** @var ShopLockInterface */
    private $lock;

    /** @var callable */
    private $clock;

    public function __construct(
        ConfigurationRepositoryInterface $configuration,
        ConnectionSetupInterface $setup,
        PairingGatewayInterface $gateway,
        ShopDetailsProviderInterface $shopDetails,
        SecretGenerator $secrets,
        EndpointNormalizer $endpoints,
        EndpointPolicy $endpointPolicy,
        ShopLockInterface $lock,
        $clock = null
    ) {
        $this->configuration = $configuration;
        $this->setup = $setup;
        $this->gateway = $gateway;
        $this->shopDetails = $shopDetails;
        $this->secrets = $secrets;
        $this->endpoints = $endpoints;
        $this->endpointPolicy = $endpointPolicy;
        $this->lock = $lock;
        $this->clock = $clock === null
            ? function () {
                return (int) floor(microtime(true) * 1000);
            }
            : $clock;
    }

    public function start($shopId)
    {
        $endpointPair = $this->endpointPolicy->normalizePair(
            $this->configuration->getApiBaseUrl($shopId),
            $this->configuration->getAppBaseUrl($shopId)
        );
        if ($endpointPair === null) {
            throw new PairingException(PairingException::CONFIGURATION);
        }
        list($apiBaseUrl, $appBaseUrl) = $endpointPair;

        $payload = $this->shopDetails->get($shopId);
        if (!$this->hasValidShopDetails($payload)) {
            throw new PairingException(PairingException::SHOP_CONTEXT);
        }

        $this->setup->provision($shopId, $apiBaseUrl);
        return $this->lock->synchronized(
            'configuration',
            $shopId,
            function () use ($shopId, $endpointPair, $payload) {
                return $this->startLocked($shopId, $endpointPair, $payload);
            }
        );
    }

    private function startLocked($shopId, array $endpointPair, array $payload)
    {
        $currentPair = $this->endpointPolicy->normalizePair(
            $this->configuration->getApiBaseUrl($shopId),
            $this->configuration->getAppBaseUrl($shopId)
        );
        if ($currentPair !== $endpointPair) {
            throw new PairingException(PairingException::CONFIGURATION);
        }
        list($apiBaseUrl, $appBaseUrl) = $currentPair;

        $credentials = $this->configuration->getPairingCredentials($shopId);
        if (!$this->hasCompleteCredentials($credentials, $apiBaseUrl)) {
            throw new PairingException(PairingException::CREDENTIALS);
        }

        $attempt = $this->configuration->getPairingAttempt($shopId);
        $now = call_user_func($this->clock);
        if (is_array($attempt)
            && isset($attempt['pairing_token'], $attempt['expires_at'])
            && is_string($attempt['pairing_token'])
            && (int) $attempt['expires_at'] > $now) {
            $pairingToken = $attempt['pairing_token'];
        } else {
            if (is_array($attempt) && isset($attempt['pairing_token'])) {
                $this->configuration->clearPairingAttempt($shopId, $attempt['pairing_token']);
            }
            $pairingToken = $this->secrets->pairingToken();
            if (!$this->configuration->beginPairingAttempt($shopId, $pairingToken)) {
                throw new PairingException(PairingException::PERSISTENCE);
            }
        }

        $payload['pairingToken'] = $pairingToken;
        $payload['webserviceKey'] = $credentials['webservice_key'];
        $payload['webhookSecret'] = $credentials['webhook_secret'];
        $payload['buyLinkSecret'] = $credentials['buy_link_secret'];

        try {
            $expiresAt = $this->gateway->start($apiBaseUrl, $payload);
        } catch (PairingException $exception) {
            if ($exception->getReason() === PairingException::REJECTED
                || $exception->getReason() === PairingException::INVALID_RESPONSE) {
                $this->configuration->clearPairingAttempt($shopId, $pairingToken);
            }
            throw $exception;
        }
        if (!$this->configuration->markPairingStarted($shopId, $pairingToken, $expiresAt)) {
            throw new PairingException(PairingException::PERSISTENCE);
        }

        return $appBaseUrl . self::CLAIM_PATH . '?pair=' . rawurlencode($pairingToken);
    }

    private function hasCompleteCredentials($credentials, $apiBaseUrl)
    {
        if (!is_array($credentials)) {
            return false;
        }

        return isset($credentials['webservice_key'], $credentials['webhook_secret'], $credentials['buy_link_secret'], $credentials['credentials_api_base_url'])
            && is_string($credentials['credentials_api_base_url'])
            && hash_equals($credentials['credentials_api_base_url'], $apiBaseUrl)
            && is_string($credentials['webservice_key'])
            && strlen($credentials['webservice_key']) >= 32
            && strlen($credentials['webservice_key']) <= 128
            && is_string($credentials['webhook_secret'])
            && strlen($credentials['webhook_secret']) >= 32
            && strlen($credentials['webhook_secret']) <= 256
            && is_string($credentials['buy_link_secret'])
            && strlen($credentials['buy_link_secret']) >= 32
            && strlen($credentials['buy_link_secret']) <= 256;
    }

    private function hasValidShopDetails($details)
    {
        if (!is_array($details)
            || !isset($details['shopUrl'], $details['platformVersion'], $details['languageId'], $details['languages'], $details['currencies'])
            || !is_string($details['shopUrl'])
            || strlen($details['shopUrl']) > 2048
            || $this->endpoints->normalizeShopUrl($details['shopUrl']) === null
            || !is_string($details['platformVersion'])
            || !is_int($details['languageId'])
            || $details['languageId'] <= 0
            || !preg_match('/^\d+(?:\.\d+){1,3}$/', $details['platformVersion'])) {
            return false;
        }

        return $this->isValidCodeList($details['languages'], '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/')
            && $this->isValidCodeList($details['currencies'], '/^[A-Z]{3}$/');
    }

    private function isValidCodeList($values, $pattern)
    {
        if (!is_array($values) || $values === array() || count($values) > 20) {
            return false;
        }

        if (count(array_unique($values)) !== count($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (!is_string($value) || !preg_match($pattern, $value)) {
                return false;
            }
        }

        return true;
    }
}
