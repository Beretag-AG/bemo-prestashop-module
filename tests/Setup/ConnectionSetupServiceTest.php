<?php

namespace Bemo\LiveShopping\Tests\Setup;

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupService;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use Bemo\LiveShopping\Webservice\WebserviceGatewayInterface;
use PHPUnit\Framework\TestCase;

class ConnectionSetupServiceTest extends TestCase
{
    public function testHealthyProvisioningIsIdempotentAndValidated()
    {
        $configuration = new InMemoryConfigurationRepository();
        $configuration->accountId = 42;
        $configuration->credentials = $this->credentials();
        $webservice = new FakeWebserviceGateway();

        self::assertSame(42, $this->service($configuration, $webservice)->provision(7, 'https://actions.bemo.now'));
        self::assertSame(1, $webservice->validationCalls);
        self::assertSame(0, $webservice->createCalls);
    }

    public function testStaleProvisioningIsDeletedClearedAndRecreated()
    {
        $configuration = new InMemoryConfigurationRepository();
        $configuration->accountId = 42;
        $configuration->credentials = $this->credentials();
        $webservice = new FakeWebserviceGateway();
        $webservice->valid = false;

        self::assertSame(73, $this->service($configuration, $webservice)->provision(7, 'https://actions.bemo.now'));
        self::assertSame(array(42), $webservice->deletedAccountIds);
        self::assertSame(1, $configuration->clearCalls);
        self::assertSame(1, $webservice->createCalls);
    }

    public function testProvisioningPersistsOriginBoundSeparateSecretsAndMinimalPermissions()
    {
        $configuration = new InMemoryConfigurationRepository();
        $webservice = new FakeWebserviceGateway();

        self::assertSame(73, $this->service($configuration, $webservice)->provision(7, 'https://actions.bemo.now'));
        self::assertTrue($webservice->enabled);
        self::assertSame((new ReadOnlyPermissionMap())->build(), $webservice->permissions);
        self::assertNotSame($configuration->webhookSecret, $configuration->buyLinkSecret);
        self::assertSame('https://actions.bemo.now', $configuration->credentialsApiBaseUrl);
    }

    public function testProvisioningDeletesTheExactAccountWhenPersistenceFails()
    {
        $configuration = new InMemoryConfigurationRepository();
        $configuration->saveSucceeds = false;
        $webservice = new FakeWebserviceGateway();

        try {
            $this->service($configuration, $webservice)->provision(7, 'https://actions.bemo.now');
            self::fail('Expected provisioning to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(array(73), $webservice->deletedAccountIds);
        }
    }

    private function credentials()
    {
        return array(
            'webservice_key' => str_repeat('k', 32),
            'webhook_secret' => str_repeat('w', 32),
            'buy_link_secret' => str_repeat('b', 32),
            'credentials_api_base_url' => 'https://actions.bemo.now',
        );
    }

    private function service($configuration, $webservice)
    {
        return new ConnectionSetupService(
            $configuration,
            $webservice,
            new ReadOnlyPermissionMap(),
            new SecretGenerator(),
            new ImmediateShopLock()
        );
    }
}

class InMemoryConfigurationRepository implements ConfigurationRepositoryInterface
{
    public $accountId;
    public $credentials;
    public $saveSucceeds = true;
    public $clearCalls = 0;
    public $webhookSecret;
    public $buyLinkSecret;
    public $credentialsApiBaseUrl;

    public function getApiBaseUrl($shopId) { return 'https://actions.bemo.now'; }
    public function getAppBaseUrl($shopId) { return 'https://bemo.now'; }
    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl) { return true; }
    public function getWebserviceAccountId($shopId) { return $this->accountId; }
    public function getWebserviceAccountIds() { return $this->accountId === null ? array() : array($this->accountId); }
    public function getPairingCredentials($shopId) { return $this->credentials; }
    public function getPairingAttempt($shopId) { return null; }
    public function beginPairingAttempt($shopId, $pairingToken) { return true; }
    public function markPairingStarted($shopId, $pairingToken, $expiresAt) { return true; }
    public function clearPairingAttempt($shopId, $pairingToken) { return true; }

    public function clearProvisionedCredentials($shopId)
    {
        ++$this->clearCalls;
        $this->accountId = null;
        $this->credentials = null;
        return true;
    }

    public function saveProvisionedCredentials($shopId, $webserviceAccountId, $webserviceKey, $webhookSecret, $buyLinkSecret, $apiBaseUrl)
    {
        $this->webhookSecret = $webhookSecret;
        $this->buyLinkSecret = $buyLinkSecret;
        $this->credentialsApiBaseUrl = $apiBaseUrl;
        return $this->saveSucceeds;
    }
}

class FakeWebserviceGateway implements WebserviceGatewayInterface
{
    public $enabled = false;
    public $valid = true;
    public $validationCalls = 0;
    public $createCalls = 0;
    public $permissions = array();
    public $deletedAccountIds = array();

    public function enableWebservice() { $this->enabled = true; return true; }
    public function createReadOnlyAccount($shopId, $key, array $permissions)
    {
        ++$this->createCalls;
        $this->permissions = $permissions;
        return 73;
    }
    public function isAccountValid($accountId, $shopId, $key, array $permissions)
    {
        ++$this->validationCalls;
        return $this->valid;
    }
    public function deleteAccount($accountId) { $this->deletedAccountIds[] = $accountId; return true; }
}

class ImmediateShopLock implements ShopLockInterface
{
    public function synchronized($scope, $shopId, $callback) { return call_user_func($callback); }
}
