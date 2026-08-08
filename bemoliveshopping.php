<?php

/*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/config/autoload.php';

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;
use Bemo\LiveShopping\Installation\Installer;
use Bemo\LiveShopping\Lock\DbShopLock;
use Bemo\LiveShopping\Pairing\CurlPairingGateway;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\EndpointPolicy;
use Bemo\LiveShopping\Pairing\PairingException;
use Bemo\LiveShopping\Pairing\PairingResponseParser;
use Bemo\LiveShopping\Pairing\PairingService;
use Bemo\LiveShopping\Pairing\PrestaShopShopDetailsProvider;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupService;
use Bemo\LiveShopping\Webservice\PrestaShopWebserviceGateway;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;

class Bemoliveshopping extends Module
{
    const VERSION = '0.3.0';

    /** @var string */
    private $output = '';

    public function __construct()
    {
        $this->name = 'bemoliveshopping';
        $this->tab = 'advertising_marketing';
        $this->version = self::VERSION;
        $this->author = 'Beretag AG';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array(
            'min' => '1.7.6.0',
            'max' => '8.99.99',
        );

        parent::__construct();

        $this->displayName = $this->l('BEMO Live Shopping');
        $this->description = $this->l('Connect your PrestaShop catalog to BEMO live shopping sessions.');
        $this->confirmUninstall = $this->l('Remove BEMO settings and the module-created Webservice key?');
    }

    public function install()
    {
        if (version_compare(PHP_VERSION, '7.2.5', '<')) {
            $this->_errors[] = $this->l('BEMO Live Shopping requires PHP 7.2.5 or newer.');

            return false;
        }

        $installer = new Installer(Db::getInstance());

        return parent::install()
            && $installer->install();
    }

    public function uninstall()
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $gateway = new PrestaShopWebserviceGateway();

        foreach ($repository->getWebserviceAccountIds() as $accountId) {
            if (!$gateway->deleteAccount($accountId)) {
                return false;
            }
        }

        $installer = new Installer(Db::getInstance());

        return $installer->uninstall() && parent::uninstall();
    }

    public function getContent()
    {
        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->displayError(
                $this->l('Select one shop before configuring or activating BEMO. Credentials are isolated per shop.')
            );
        }

        if (Tools::isSubmit('submitBemoActivateAccount')) {
            $this->activateBemoAccount();
        } elseif (Tools::isSubmit('submitBemoConfiguration')) {
            $this->saveConfiguration();
        }

        return $this->output . $this->renderConfigurationForm();
    }

    private function saveConfiguration()
    {
        $endpoints = $this->validatedEndpointsFromRequest();
        if ($endpoints === null) {
            return;
        }

        if (!$this->saveEndpoints($endpoints)) {
            return;
        }

        $this->output .= $this->displayConfirmation($this->l('BEMO configuration saved.'));
    }

    private function activateBemoAccount()
    {
        if (!Tools::getValue('BEMO_CONFIRM_WEBSERVICE')) {
            $this->output .= $this->displayError(
                $this->l('Confirm that you understand the PrestaShop Webservice will be enabled for this shop.')
            );

            return;
        }

        $endpoints = $this->validatedEndpointsFromRequest();
        if ($endpoints === null || !$this->saveEndpoints($endpoints)) {
            return;
        }

        $shopId = (int) $this->context->shop->id;
        $repository = new DbConfigurationRepository(Db::getInstance());
        $webservice = new PrestaShopWebserviceGateway();
        $secrets = new SecretGenerator();
        $endpoints = new EndpointNormalizer();
        $endpointPolicy = new EndpointPolicy($endpoints, $this->isDeveloperMode());
        $lock = new DbShopLock(Db::getInstance());
        $setup = new ConnectionSetupService(
            $repository,
            $webservice,
            new ReadOnlyPermissionMap(),
            $secrets,
            $lock
        );
        $service = new PairingService(
            $repository,
            $setup,
            new CurlPairingGateway(
                $endpoints,
                new PairingResponseParser(),
                'BEMO-PrestaShop/' . self::VERSION
            ),
            new PrestaShopShopDetailsProvider($endpoints),
            $secrets,
            $endpoints,
            $endpointPolicy,
            $lock
        );

        try {
            Tools::redirect($service->start($shopId));
        } catch (PairingException $exception) {
            PrestaShopLogger::addLog('BEMO account activation failed: ' . $exception->getReason(), 3);
            $this->output .= $this->displayError($this->pairingErrorMessage($exception->getReason()));
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO account activation failed', 3);
            $this->output .= $this->displayError(
                $this->l('The BEMO account could not be activated. Verify the settings and try again.')
            );
        }
    }

    private function validatedEndpointsFromRequest()
    {
        $normalizer = new EndpointNormalizer();
        $policy = new EndpointPolicy($normalizer, $this->isDeveloperMode());
        $pair = $this->isDeveloperMode()
            ? $policy->normalizePair(
                Tools::getValue('BEMO_API_BASE_URL'),
                Tools::getValue('BEMO_APP_BASE_URL')
            )
            : $policy->normalizePair(
                EndpointPolicy::PRODUCTION_API_BASE_URL,
                EndpointPolicy::PRODUCTION_APP_BASE_URL
            );

        if ($pair === null) {
            $this->output .= $this->displayError(
                $this->l('Custom BEMO endpoints are available only in PrestaShop developer mode and must be HTTPS base URLs without paths or query strings. HTTP is allowed only for localhost.')
            );

            return;
        }

        return $pair;
    }

    private function saveEndpoints(array $endpoints)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $gateway = new PrestaShopWebserviceGateway();
        try {
            $saved = (new DbShopLock(Db::getInstance()))->synchronized(
                'configuration',
                $shopId,
                function () use ($repository, $gateway, $shopId, $endpoints) {
                    $changed = $repository->getApiBaseUrl($shopId) !== $endpoints[0]
                        || $repository->getAppBaseUrl($shopId) !== $endpoints[1];
                    if ($changed) {
                        $accountId = $repository->getWebserviceAccountId($shopId);
                        if ($accountId !== null && !$gateway->deleteAccount($accountId)) {
                            return false;
                        }
                        if ($accountId !== null && !$repository->clearProvisionedCredentials($shopId)) {
                            return false;
                        }
                    }

                    return $repository->saveEndpoints($shopId, $endpoints[0], $endpoints[1]);
                }
            );
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO endpoint configuration is busy', 2);
            $saved = false;
        }
        if (!$saved) {
            $this->output .= $this->displayError($this->l('The BEMO configuration could not be saved.'));

            return false;
        }

        return true;
    }

    private function pairingErrorMessage($reason)
    {
        if ($reason === PairingException::RATE_LIMITED) {
            return $this->l('Too many pairing attempts were made. Wait a moment and try again.');
        }

        if ($reason === PairingException::NETWORK) {
            return $this->l('BEMO could not be reached. Verify the API URL and the shop network connection.');
        }

        if ($reason === PairingException::CONFIGURATION) {
            return $this->l('The BEMO API or app URL is invalid. Save valid HTTPS base URLs and try again.');
        }

        if ($reason === PairingException::SHOP_CONTEXT) {
            return $this->l('The shop needs an HTTPS URL, an active language, and an active currency before it can connect to BEMO.');
        }

        if ($reason === PairingException::CREDENTIALS || $reason === PairingException::PERSISTENCE) {
            return $this->l('The BEMO credentials could not be prepared safely. Retry activation or contact BEMO support.');
        }

        return $this->l('BEMO rejected the activation request. Retry to create a fresh pairing link.');
    }

    private function renderConfigurationForm()
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitBemoConfiguration';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->fields_value = array(
            'BEMO_API_BASE_URL' => $this->isDeveloperMode()
                ? $repository->getApiBaseUrl($shopId)
                : EndpointPolicy::PRODUCTION_API_BASE_URL,
            'BEMO_APP_BASE_URL' => $this->isDeveloperMode()
                ? $repository->getAppBaseUrl($shopId)
                : EndpointPolicy::PRODUCTION_APP_BASE_URL,
            'BEMO_CONFIRM_WEBSERVICE' => 0,
        );

        return $helper->generateForm(array($this->configurationForm()));
    }

    private function configurationForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('BEMO connection'),
                    'icon' => 'icon-link',
                ),
                'description' => $this->l(
                    'BEMO needs a minimal read-only Webservice account. Provisioning is an explicit action because it enables the shop-wide PrestaShop Webservice.'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('BEMO API URL'),
                        'name' => 'BEMO_API_BASE_URL',
                        'required' => true,
                        'readonly' => !$this->isDeveloperMode(),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('BEMO app URL'),
                        'name' => 'BEMO_APP_BASE_URL',
                        'required' => true,
                        'readonly' => !$this->isDeveloperMode(),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable and provision Webservice access'),
                        'name' => 'BEMO_CONFIRM_WEBSERVICE',
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'bemo_ws_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'bemo_ws_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                ),
                'buttons' => array(
                    array(
                        'title' => $this->l('Activate BEMO account'),
                        'name' => 'submitBemoActivateAccount',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-left',
                        'icon' => 'process-icon-key',
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    private function isDeveloperMode()
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ === true;
    }

}
