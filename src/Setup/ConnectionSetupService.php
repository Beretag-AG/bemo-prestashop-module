<?php

namespace Bemo\LiveShopping\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use Bemo\LiveShopping\Webservice\WebserviceGatewayInterface;
use RuntimeException;

class ConnectionSetupService implements ConnectionSetupInterface
{
    /** @var ConfigurationRepositoryInterface */
    private $configuration;

    /** @var WebserviceGatewayInterface */
    private $webservice;

    /** @var ReadOnlyPermissionMap */
    private $permissionMap;

    /** @var SecretGenerator */
    private $secrets;

    /** @var ShopLockInterface */
    private $lock;

    public function __construct(
        ConfigurationRepositoryInterface $configuration,
        WebserviceGatewayInterface $webservice,
        ReadOnlyPermissionMap $permissionMap,
        SecretGenerator $secrets,
        ShopLockInterface $lock
    ) {
        $this->configuration = $configuration;
        $this->webservice = $webservice;
        $this->permissionMap = $permissionMap;
        $this->secrets = $secrets;
        $this->lock = $lock;
    }

    public function provision($shopId, $apiBaseUrl)
    {
        return $this->lock->synchronized('configuration', $shopId, function () use ($shopId, $apiBaseUrl) {
            return $this->provisionLocked($shopId, $apiBaseUrl);
        });
    }

    private function provisionLocked($shopId, $apiBaseUrl)
    {
        if (!$this->webservice->enableWebservice()) {
            throw new RuntimeException('Unable to enable the PrestaShop Webservice.');
        }

        $permissions = $this->permissionMap->build();
        $existingAccountId = $this->configuration->getWebserviceAccountId($shopId);
        $credentials = $this->configuration->getPairingCredentials($shopId);
        if ($existingAccountId !== null
            && is_array($credentials)
            && isset($credentials['webservice_key'], $credentials['credentials_api_base_url'])
            && hash_equals((string) $credentials['credentials_api_base_url'], (string) $apiBaseUrl)
            && $this->webservice->isAccountValid(
                $existingAccountId,
                $shopId,
                $credentials['webservice_key'],
                $permissions
            )) {
            return $existingAccountId;
        }

        if ($existingAccountId !== null && !$this->webservice->deleteAccount($existingAccountId)) {
            throw new RuntimeException('Unable to replace the stale BEMO Webservice account.');
        }
        if (($existingAccountId !== null || is_array($credentials))
            && !$this->configuration->clearProvisionedCredentials($shopId)) {
            throw new RuntimeException('Unable to clear stale BEMO credentials.');
        }

        $webserviceKey = $this->secrets->webserviceKey();
        $accountId = $this->webservice->createReadOnlyAccount(
            $shopId,
            $webserviceKey,
            $permissions
        );

        $saved = $this->configuration->saveProvisionedCredentials(
            $shopId,
            $accountId,
            $webserviceKey,
            $this->secrets->directionalSecret(),
            $this->secrets->directionalSecret(),
            $apiBaseUrl
        );

        if (!$saved) {
            $this->webservice->deleteAccount($accountId);
            throw new RuntimeException('Unable to persist the BEMO credentials.');
        }

        return $accountId;
    }
}
