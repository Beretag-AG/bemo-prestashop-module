<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\EndpointPolicy;
use Bemo\LiveShopping\Pairing\PairingException;
use Bemo\LiveShopping\Pairing\PairingGatewayInterface;
use Bemo\LiveShopping\Pairing\PairingService;
use Bemo\LiveShopping\Pairing\ShopDetailsProviderInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupInterface;
use PHPUnit\Framework\TestCase;

class PairingServiceTest extends TestCase
{
    public function testStartsPairingThroughOneDeepInterface()
    {
        $configuration = new PairingConfiguration();
        $setup = new PairingSetup();
        $gateway = new CapturingPairingGateway();

        $claimUrl = $this->service($configuration, $setup, $gateway)->start(7);

        self::assertSame(array(array(7, 'https://api.example.com')), $setup->calls);
        self::assertSame(2, $gateway->payload['languageId']);
        self::assertSame('https://merchant.example/shop', $gateway->payload['shopUrl']);
        self::assertSame($configuration->credentials['webservice_key'], $gateway->payload['webserviceKey']);
        self::assertRegExp('/^[A-Za-z0-9_-]{22}$/', $gateway->payload['pairingToken']);
        self::assertSame(1, $configuration->beginCalls);
        self::assertSame(1, $configuration->markCalls);
        self::assertSame(
            'https://bemo.now/settings/creator/integrations?pair=' . $gateway->payload['pairingToken'],
            $claimUrl
        );
    }

    public function testRejectsUntrustedProductionEndpointBeforeProvisioning()
    {
        $configuration = new PairingConfiguration();
        $setup = new PairingSetup();
        $gateway = new CapturingPairingGateway();

        try {
            $this->service($configuration, $setup, $gateway, false)->start(7);
            self::fail('Expected an untrusted production endpoint to fail.');
        } catch (PairingException $exception) {
            self::assertSame(PairingException::CONFIGURATION, $exception->getReason());
            self::assertSame(array(), $setup->calls);
        }
    }

    public function testRetryReusesThePairingTokenAfterANetworkFailure()
    {
        $configuration = new PairingConfiguration();
        $setup = new PairingSetup();
        $gateway = new CapturingPairingGateway();
        $gateway->failReasons = array(PairingException::NETWORK);
        $service = $this->service($configuration, $setup, $gateway);

        try {
            $service->start(7);
            self::fail('Expected the first request to fail.');
        } catch (PairingException $exception) {
            self::assertSame(PairingException::NETWORK, $exception->getReason());
        }
        $firstToken = $configuration->attempt['pairing_token'];

        $service->start(7);

        self::assertSame($firstToken, $gateway->tokens[1]);
        self::assertSame(1, $configuration->beginCalls);
        self::assertSame(0, $configuration->clearCalls);
    }

    public function testDefinitiveRejectionClearsTheAttempt()
    {
        $configuration = new PairingConfiguration();
        $gateway = new CapturingPairingGateway();
        $gateway->failReasons = array(PairingException::REJECTED);
        $service = $this->service($configuration, new PairingSetup(), $gateway);

        try {
            $service->start(7);
            self::fail('Expected the request to be rejected.');
        } catch (PairingException $exception) {
            self::assertSame(PairingException::REJECTED, $exception->getReason());
        }

        self::assertNull($configuration->attempt);
        self::assertSame(1, $configuration->clearCalls);
    }

    private function service($configuration, $setup, $gateway, $developerMode = true)
    {
        $normalizer = new EndpointNormalizer();

        return new PairingService(
            $configuration,
            $setup,
            $gateway,
            new FixedShopDetails(),
            new SecretGenerator(),
            $normalizer,
            new EndpointPolicy($normalizer, $developerMode),
            new ImmediateShopLock(),
            function () {
                return 1000000;
            }
        );
    }
}

class PairingConfiguration implements ConfigurationRepositoryInterface
{
    public $apiBaseUrl = 'https://api.example.com';
    public $appBaseUrl = 'https://bemo.now';
    public $attempt;
    public $beginCalls = 0;
    public $clearCalls = 0;
    public $markCalls = 0;
    public $credentials = array(
        'webservice_key' => '0123456789abcdef0123456789abcdef',
        'webhook_secret' => 'webhook-secret-webhook-secret-1234567890',
        'buy_link_secret' => 'buy-link-secret-buy-link-secret-1234567890',
        'credentials_api_base_url' => 'https://api.example.com',
    );

    public function getApiBaseUrl($shopId) { return $this->apiBaseUrl; }
    public function getAppBaseUrl($shopId) { return $this->appBaseUrl; }
    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl) { return true; }
    public function getWebserviceAccountId($shopId) { return 73; }
    public function getWebserviceAccountIds() { return array(73); }
    public function getPairingCredentials($shopId) { return $this->credentials; }
    public function getPairingAttempt($shopId) { return $this->attempt; }

    public function beginPairingAttempt($shopId, $pairingToken)
    {
        ++$this->beginCalls;
        $this->attempt = array('pairing_token' => $pairingToken, 'expires_at' => 1900000);
        return true;
    }

    public function markPairingStarted($shopId, $pairingToken, $expiresAt)
    {
        ++$this->markCalls;
        $this->attempt['expires_at'] = $expiresAt;
        return true;
    }

    public function clearPairingAttempt($shopId, $pairingToken)
    {
        ++$this->clearCalls;
        $this->attempt = null;
        return true;
    }

    public function clearProvisionedCredentials($shopId) { return true; }

    public function saveProvisionedCredentials($shopId, $webserviceAccountId, $webserviceKey, $webhookSecret, $buyLinkSecret, $apiBaseUrl)
    {
        return true;
    }
}

class PairingSetup implements ConnectionSetupInterface
{
    public $calls = array();

    public function provision($shopId, $apiBaseUrl)
    {
        $this->calls[] = array($shopId, $apiBaseUrl);
        return 73;
    }
}

class CapturingPairingGateway implements PairingGatewayInterface
{
    public $apiBaseUrl;
    public $payload;
    public $tokens = array();
    public $failReasons = array();

    public function start($apiBaseUrl, array $payload)
    {
        $this->apiBaseUrl = $apiBaseUrl;
        $this->payload = $payload;
        $this->tokens[] = $payload['pairingToken'];
        if ($this->failReasons !== array()) {
            throw new PairingException(array_shift($this->failReasons));
        }
        return 1500000;
    }
}

class FixedShopDetails implements ShopDetailsProviderInterface
{
    public function get($shopId)
    {
        return array(
            'shopUrl' => 'https://merchant.example/shop',
            'platformVersion' => '8.2.6',
            'languageId' => 2,
            'languages' => array('de', 'en'),
            'currencies' => array('EUR', 'CHF'),
        );
    }
}

class ImmediateShopLock implements ShopLockInterface
{
    public function synchronized($scope, $shopId, $callback)
    {
        return call_user_func($callback);
    }
}
