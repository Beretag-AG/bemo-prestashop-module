<?php

namespace Bemo\LiveShopping\Tests\Setup;

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupService;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use Bemo\LiveShopping\Webservice\WebserviceGatewayInterface;
use PHPUnit\Framework\TestCase;

class ConnectionSetupServiceTest extends TestCase
{
    public function testProvisioningIsIdempotentForAnExistingAccount()
    {
        $configuration = new InMemoryConfigurationRepository();
        $configuration->accountId = 42;
        $webservice = new FakeWebserviceGateway();
        $service = $this->service($configuration, $webservice);

        self::assertSame(42, $service->provision(7));
        self::assertSame(0, $webservice->createCalls);
    }

    public function testProvisioningPersistsSeparateSecretsAndReadOnlyPermissions()
    {
        $configuration = new InMemoryConfigurationRepository();
        $webservice = new FakeWebserviceGateway();
        $service = $this->service($configuration, $webservice);

        self::assertSame(73, $service->provision(7));
        self::assertTrue($webservice->enabled);
        self::assertSame(7, $webservice->shopId);
        self::assertSame(32, strlen($webservice->createdKey));
        self::assertSame((new ReadOnlyPermissionMap())->build(), $webservice->permissions);
        self::assertNotSame($configuration->webhookSecret, $configuration->buyLinkSecret);
    }

    public function testProvisioningDeletesTheExactAccountWhenPersistenceFails()
    {
        $configuration = new InMemoryConfigurationRepository();
        $configuration->saveSucceeds = false;
        $webservice = new FakeWebserviceGateway();
        $service = $this->service($configuration, $webservice);

        try {
            $service->provision(7);
            self::fail('Expected provisioning to fail.');
        } catch (\RuntimeException $exception) {
            self::assertSame(array(73), $webservice->deletedAccountIds);
        }
    }

    private function service($configuration, $webservice)
    {
        return new ConnectionSetupService(
            $configuration,
            $webservice,
            new ReadOnlyPermissionMap(),
            new SecretGenerator()
        );
    }
}

class InMemoryConfigurationRepository implements ConfigurationRepositoryInterface
{
    public $accountId;
    public $saveSucceeds = true;
    public $webhookSecret;
    public $buyLinkSecret;

    public function getApiBaseUrl($shopId)
    {
        return '';
    }

    public function saveApiBaseUrl($shopId, $apiBaseUrl)
    {
        return true;
    }

    public function getWebserviceAccountId($shopId)
    {
        return $this->accountId;
    }

    public function getWebserviceAccountIds()
    {
        return $this->accountId === null ? array() : array($this->accountId);
    }

    public function saveProvisionedCredentials(
        $shopId,
        $webserviceAccountId,
        $webserviceKey,
        $pairingToken,
        $webhookSecret,
        $buyLinkSecret
    ) {
        $this->webhookSecret = $webhookSecret;
        $this->buyLinkSecret = $buyLinkSecret;

        return $this->saveSucceeds;
    }
}

class FakeWebserviceGateway implements WebserviceGatewayInterface
{
    public $enabled = false;
    public $createCalls = 0;
    public $shopId;
    public $createdKey;
    public $permissions = array();
    public $deletedAccountIds = array();

    public function enableWebservice()
    {
        $this->enabled = true;

        return true;
    }

    public function createReadOnlyAccount($shopId, $key, array $permissions)
    {
        ++$this->createCalls;
        $this->shopId = $shopId;
        $this->createdKey = $key;
        $this->permissions = $permissions;

        return 73;
    }

    public function deleteAccount($accountId)
    {
        $this->deletedAccountIds[] = $accountId;

        return true;
    }
}
