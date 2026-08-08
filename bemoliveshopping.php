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
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupService;
use Bemo\LiveShopping\Webservice\PrestaShopWebserviceGateway;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;

class Bemoliveshopping extends Module
{
    const VERSION = '0.1.0';

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
        if (Tools::isSubmit('submitBemoConfiguration')) {
            $this->saveConfiguration();
        }

        if (Tools::isSubmit('submitBemoProvisionWebservice')) {
            $this->provisionWebservice();
        }

        return $this->output . $this->renderConfigurationForm();
    }

    private function saveConfiguration()
    {
        $endpoint = trim((string) Tools::getValue('BEMO_API_BASE_URL'));

        if (!$this->isAllowedEndpoint($endpoint)) {
            $this->output .= $this->displayError(
                $this->l('Use an HTTPS BEMO URL. HTTP is allowed only for localhost development.')
            );

            return;
        }

        $repository = new DbConfigurationRepository(Db::getInstance());
        if (!$repository->saveApiBaseUrl((int) $this->context->shop->id, $endpoint)) {
            $this->output .= $this->displayError($this->l('The BEMO configuration could not be saved.'));

            return;
        }

        $this->output .= $this->displayConfirmation($this->l('BEMO configuration saved.'));
    }

    private function provisionWebservice()
    {
        if (!Tools::getValue('BEMO_CONFIRM_WEBSERVICE')) {
            $this->output .= $this->displayError(
                $this->l('Confirm that you understand the PrestaShop Webservice will be enabled for this shop.')
            );

            return;
        }

        $service = new ConnectionSetupService(
            new DbConfigurationRepository(Db::getInstance()),
            new PrestaShopWebserviceGateway(),
            new ReadOnlyPermissionMap(),
            new SecretGenerator()
        );

        try {
            $service->provision((int) $this->context->shop->id);
            $this->output .= $this->displayConfirmation(
                $this->l('The read-only BEMO Webservice account and connection secrets are ready.')
            );
        } catch (Exception $exception) {
            PrestaShopLogger::addLog(
                'BEMO Webservice provisioning failed',
                3,
                null,
                'Module',
                null,
                true
            );
            $this->output .= $this->displayError(
                $this->l('The BEMO Webservice account could not be created. No secret was displayed or logged.')
            );
        }
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
            'BEMO_API_BASE_URL' => $repository->getApiBaseUrl($shopId),
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
                        'title' => $this->l('Provision Webservice access'),
                        'name' => 'submitBemoProvisionWebservice',
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

    private function isAllowedEndpoint($endpoint)
    {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if ($parts['scheme'] === 'https') {
            return true;
        }

        return $parts['scheme'] === 'http'
            && in_array($parts['host'], array('localhost', '127.0.0.1', '::1'), true);
    }
}
