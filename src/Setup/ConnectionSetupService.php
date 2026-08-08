<?php

namespace Bemo\LiveShopping\Setup;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use Bemo\LiveShopping\Webservice\WebserviceGatewayInterface;
use RuntimeException;

class ConnectionSetupService
{
    /** @var ConfigurationRepositoryInterface */
    private $configuration;

    /** @var WebserviceGatewayInterface */
    private $webservice;

    /** @var ReadOnlyPermissionMap */
    private $permissionMap;

    /** @var SecretGenerator */
    private $secrets;

    public function __construct(
        ConfigurationRepositoryInterface $configuration,
        WebserviceGatewayInterface $webservice,
        ReadOnlyPermissionMap $permissionMap,
        SecretGenerator $secrets
    ) {
        $this->configuration = $configuration;
        $this->webservice = $webservice;
        $this->permissionMap = $permissionMap;
        $this->secrets = $secrets;
    }

    public function provision($shopId)
    {
        $existingAccountId = $this->configuration->getWebserviceAccountId($shopId);
        if ($existingAccountId !== null) {
            return $existingAccountId;
        }

        if (!$this->webservice->enableWebservice()) {
            throw new RuntimeException('Unable to enable the PrestaShop Webservice.');
        }

        $webserviceKey = $this->secrets->webserviceKey();
        $accountId = $this->webservice->createReadOnlyAccount(
            $shopId,
            $webserviceKey,
            $this->permissionMap->build()
        );

        $saved = $this->configuration->saveProvisionedCredentials(
            $shopId,
            $accountId,
            $webserviceKey,
            $this->secrets->pairingToken(),
            $this->secrets->directionalSecret(),
            $this->secrets->directionalSecret()
        );

        if (!$saved) {
            $this->webservice->deleteAccount($accountId);
            throw new RuntimeException('Unable to persist the BEMO credentials.');
        }

        return $accountId;
    }
}
