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
use Bemo\LiveShopping\Checkout\CheckoutLanding;
use Bemo\LiveShopping\Checkout\DbBuyLinkNonceRepository;
use Bemo\LiveShopping\Installation\Installer;
use Bemo\LiveShopping\Lock\DbShopLock;
use Bemo\LiveShopping\Pairing\CurlPairingGateway;
use Bemo\LiveShopping\Pairing\EndpointEnvironment;
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
use Bemo\LiveShopping\Webhook\CurlWebhookGateway;
use Bemo\LiveShopping\Webhook\DbOutboxRepository;
use Bemo\LiveShopping\Webhook\WebhookOutbox;

class Bemoliveshopping extends Module
{
    const VERSION = '0.5.0';
    const CRON_CONTROLLER = 'cron';

    /** @var string */
    private $output = '';

    public function __construct()
    {
        $this->name = 'bemoliveshopping';
        $this->tab = 'advertising_marketing';
        $this->version = self::VERSION;
        $this->author = 'BEMO';
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

        if (!parent::install()) {
            return false;
        }

        $installer = new Installer(Db::getInstance());
        if (!$installer->install() || !$this->registerBemoHooks()) {
            $installer->uninstall();
            parent::uninstall();

            return false;
        }

        return true;
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

    public function upgradeToVersion030()
    {
        return (new Installer(Db::getInstance()))->upgradeToVersion030()
            && $this->registerBemoHooks();
    }

    public function upgradeToVersion034()
    {
        return (new Installer(Db::getInstance()))->upgradeToVersion034()
            && $this->registerBemoHooks();
    }

    public function upgradeToVersion040()
    {
        return (new Installer(Db::getInstance()))->upgradeToVersion040()
            && $this->registerBemoHooks();
    }

    public function upgradeToVersion050()
    {
        return (new Installer(Db::getInstance()))->upgradeToVersion050()
            && $this->registerBemoHooks()
            && $this->repairWebservicePermissions();
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
        } elseif (Tools::isSubmit('submitBemoDisconnect')) {
            $this->disconnectFromBemo();
        } elseif (Tools::isSubmit('submitBemoConfiguration')) {
            $this->saveConfiguration();
        }

        return $this->output . $this->renderConnectionPanel() . $this->renderConfigurationForm();
    }

    public function hookActionObjectProductAddAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'product.added', 'product');
    }

    public function hookActionObjectProductUpdateAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'product.updated', 'product');
    }

    public function hookActionObjectProductDeleteAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'product.deleted', 'product');
    }

    public function hookActionUpdateQuantity($params)
    {
        $productId = isset($params['id_product']) ? (int) $params['id_product'] : 0;

        return $this->enqueueWebhook('stock.updated', 'stock', $productId);
    }

    public function hookActionObjectSpecificPriceAddAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'price.added', 'price');
    }

    public function hookActionObjectSpecificPriceUpdateAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'price.updated', 'price');
    }

    public function hookActionObjectSpecificPriceDeleteAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'price.deleted', 'price');
    }

    public function hookActionObjectCartRuleAddAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'voucher.added', 'voucher');
    }

    public function hookActionObjectCartRuleUpdateAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'voucher.updated', 'voucher');
    }

    public function hookActionObjectCartRuleDeleteAfter($params)
    {
        return $this->enqueueObjectWebhook($params, 'voucher.deleted', 'voucher');
    }

    public function hookActionCronJob()
    {
        return $this->drainWebhookOutbox();
    }

    /**
     * Shared by the Cron tasks manager hook and the token-authenticated cron
     * controller, so a shop without that module still drains its queue.
     */
    public function drainWebhookOutbox($shopId = null)
    {
        try {
            $drained = $shopId === null
                ? $this->webhookOutbox()->drain()
                : $this->webhookOutbox()->drainShop((int) $shopId);
            (new DbBuyLinkNonceRepository(Db::getInstance()))->purgeExpiredBefore(time());

            return $drained;
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO webhook outbox processing failed', 3);

            return false;
        }
    }

    public function getCronFrequency()
    {
        return array(
            'hour' => -1,
            'day' => -1,
            'month' => -1,
            'day_of_week' => -1,
        );
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

        if (!$this->saveActivationChoices()) {
            return;
        }

        $this->output .= $this->displayConfirmation($this->l('BEMO module settings saved.'));
    }

    private function disconnectFromBemo()
    {
        $shopId = (int) $this->context->shop->id;
        $repository = new DbConfigurationRepository(Db::getInstance());

        if (!$this->revokeCatalogAccess($shopId)
            || !$this->persistActivationChoices(
                $shopId,
                false,
                $repository->isEmbeddedCheckoutRequested($shopId),
                $repository->getCheckoutLanding($shopId)
            )) {
            return;
        }

        $this->output .= $this->displayConfirmation(
            $this->l('This shop is disconnected from BEMO. The read-only catalog key was deleted and the stored credentials were cleared.')
        );
    }

    private function activateBemoAccount()
    {
        if (!$this->requestedChoice(
            'BEMO_CONFIRM_WEBSERVICE',
            (new DbConfigurationRepository(Db::getInstance()))->isWebserviceAccessApproved(
                (int) $this->context->shop->id
            )
        )) {
            $this->output .= $this->displayError(
                $this->l('Confirm that you understand the PrestaShop Webservice will be enabled for this shop.')
            );

            return;
        }

        $endpoints = $this->validatedEndpointsFromRequest();
        if ($endpoints === null || !$this->saveEndpoints($endpoints) || !$this->saveActivationChoices()) {
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
            new PrestaShopShopDetailsProvider(
                $endpoints,
                $repository->isEmbeddedCheckoutRequested($shopId)
            ),
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
        $environment = new EndpointEnvironment();
        $environmentValues = $environment->overrideValues($policy);
        $pair = $this->isDeveloperMode()
            ? ($environmentValues !== null
                ? $policy->normalizePair($environmentValues[0], $environmentValues[1])
                : $policy->normalizePair(
                    Tools::getValue('BEMO_API_BASE_URL'),
                    Tools::getValue('BEMO_APP_BASE_URL')
                ))
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

    private function saveActivationChoices()
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $approved = $repository->isWebserviceAccessApproved($shopId);
        $requestedApproval = $this->requestedChoice('BEMO_CONFIRM_WEBSERVICE', $approved);

        if ($approved && !$requestedApproval && !$this->revokeCatalogAccess($shopId)) {
            return false;
        }

        return $this->persistActivationChoices(
            $shopId,
            $requestedApproval,
            $this->requestedChoice(
                'BEMO_CONFIRM_EMBEDDED_CHECKOUT',
                $repository->isEmbeddedCheckoutRequested($shopId)
            ),
            $this->requestedLanding($repository->getCheckoutLanding($shopId))
        );
    }

    private function persistActivationChoices($shopId, $approved, $embedded, $checkoutLanding)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        try {
            $saved = (new DbShopLock(Db::getInstance()))->synchronized(
                'configuration',
                $shopId,
                function () use ($repository, $shopId, $approved, $embedded, $checkoutLanding) {
                    return $repository->saveActivationChoices($shopId, $approved, $embedded, $checkoutLanding);
                }
            );
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO module settings are busy', 2);
            $saved = false;
        }
        if (!$saved) {
            $this->output .= $this->displayError($this->l('The BEMO module settings could not be saved.'));

            return false;
        }

        return true;
    }

    private function revokeCatalogAccess($shopId)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $gateway = new PrestaShopWebserviceGateway();
        try {
            $revoked = (new DbShopLock(Db::getInstance()))->synchronized(
                'configuration',
                $shopId,
                function () use ($repository, $gateway, $shopId) {
                    $accountId = $repository->getWebserviceAccountId($shopId);
                    if ($accountId !== null && !$gateway->deleteAccount($accountId)) {
                        return false;
                    }

                    return $repository->clearProvisionedCredentials($shopId);
                }
            );
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO catalog access could not be revoked', 3);
            $revoked = false;
        }
        if (!$revoked) {
            $this->output .= $this->displayError(
                $this->l('The BEMO catalog access could not be revoked. Retry in a moment.')
            );

            return false;
        }

        return true;
    }

    /**
     * Widens an already-provisioned key in place. Rotating it instead would
     * break the BEMO connection that still holds the previous key, and a key
     * that cannot be repaired is fixed by pairing the shop again.
     */
    private function repairWebservicePermissions()
    {
        $gateway = new PrestaShopWebserviceGateway();
        $permissions = (new ReadOnlyPermissionMap())->build();

        foreach ((new DbConfigurationRepository(Db::getInstance()))->getWebserviceAccountIds() as $accountId) {
            try {
                $repaired = $gateway->updatePermissions($accountId, $permissions);
            } catch (Exception $exception) {
                $repaired = false;
            }
            if (!$repaired) {
                PrestaShopLogger::addLog(
                    'BEMO could not widen the read-only Webservice permissions of account ' . (int) $accountId
                    . '. Connect the shop to BEMO again.',
                    2
                );
            }
        }

        return true;
    }

    /**
     * A field missing from the submit keeps its stored value: an absent switch
     * is a partial form post, not a request to turn the setting off.
     */
    private function requestedChoice($name, $persisted)
    {
        $value = Tools::getValue($name, null);

        return $value === null ? (bool) $persisted : (string) $value === '1';
    }

    private function requestedLanding($persisted)
    {
        $value = Tools::getValue('BEMO_CHECKOUT_LANDING', null);

        return CheckoutLanding::normalize($value === null ? $persisted : $value);
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
            return $this->l('The BEMO credentials could not be prepared. Retry activation or contact BEMO support.');
        }

        return $this->l('BEMO rejected the activation request. Retry to create a fresh pairing link.');
    }

    private function renderConnectionPanel()
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $cronUrl = $this->cronUrl($shopId);
        $rows = array(
            $this->l('Connection status') => $this->connectionStatusLabel(
                $repository->getConnectionStatus($shopId),
                is_array($repository->getPairingCredentials($shopId))
            ),
            $this->l('Shop endpoint') => $this->context->shop->getBaseURL(true),
            $this->l('BEMO app') => $repository->getAppBaseUrl($shopId),
            $this->l('Catalog event drain URL') => $cronUrl === null
                ? $this->l('Unavailable until the module settings can be saved.')
                : $cronUrl,
        );

        $html = '<div class="panel"><h3><i class="icon-link"></i> ' . $this->l('BEMO connection') . '</h3><ul>';
        foreach ($rows as $label => $value) {
            $html .= '<li><strong>' . $label . ':</strong> ' . Tools::safeOutput($value) . '</li>';
        }
        $html .= '</ul><p>' . $this->l(
            'Call the drain URL from a scheduler every few minutes so queued catalog events reach BEMO. Treat it as a secret: its token is the only thing that authorizes the drain.'
        ) . '</p>';

        if (!$this->isCronModuleActive()) {
            $html .= '<p class="alert alert-warning">' . $this->l(
                'The PrestaShop Cron tasks manager module is not installed or not active. Schedule the drain URL above with your own scheduler, otherwise catalog changes stay queued.'
            ) . '</p>';
        }

        return $html . '</div>';
    }

    private function connectionStatusLabel($status, $hasCredentials)
    {
        if (!$hasCredentials) {
            return $this->l('Not connected');
        }

        if ($status === 'pairing_starting' || $status === 'pairing_pending') {
            return $this->l('Waiting for a BEMO account to claim this shop');
        }

        return $this->l('Connected with read-only catalog access');
    }

    private function isCronModuleActive()
    {
        return Module::isInstalled('cronjobs') && Module::isEnabled('cronjobs');
    }

    private function cronUrl($shopId)
    {
        $token = $this->cronToken($shopId);
        if ($token === null) {
            return null;
        }

        return $this->context->link->getModuleLink(
            $this->name,
            self::CRON_CONTROLLER,
            array('token' => $token),
            true
        );
    }

    private function cronToken($shopId)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $token = $repository->getCronToken($shopId);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = (new SecretGenerator())->directionalSecret();

        return $repository->saveCronToken($shopId, $token) ? $token : null;
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
        $normalizer = new EndpointNormalizer();
        $policy = new EndpointPolicy($normalizer, $this->isDeveloperMode());
        $environmentValues = (new EndpointEnvironment())->overrideValues($policy);
        $helper->fields_value = array(
            'BEMO_API_BASE_URL' => $environmentValues !== null
                ? $environmentValues[0]
                : ($this->isDeveloperMode()
                    ? $repository->getApiBaseUrl($shopId)
                    : EndpointPolicy::PRODUCTION_API_BASE_URL),
            'BEMO_APP_BASE_URL' => $environmentValues !== null
                ? $environmentValues[1]
                : ($this->isDeveloperMode()
                    ? $repository->getAppBaseUrl($shopId)
                    : EndpointPolicy::PRODUCTION_APP_BASE_URL),
            'BEMO_CONFIRM_WEBSERVICE' => $repository->isWebserviceAccessApproved($shopId) ? 1 : 0,
            'BEMO_CONFIRM_EMBEDDED_CHECKOUT' => $repository->isEmbeddedCheckoutRequested($shopId) ? 1 : 0,
            'BEMO_CHECKOUT_LANDING' => $repository->getCheckoutLanding($shopId),
        );

        return $helper->generateForm(array($this->configurationForm($environmentValues !== null)));
    }

    private function configurationForm($environmentEndpointsLocked = false)
    {
        $endpointsReadonly = !$this->isDeveloperMode() || $environmentEndpointsLocked;

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Connect this shop to BEMO'),
                    'icon' => 'icon-link',
                ),
                'description' => $this->l(
                    'Choose the checkout experience, save your preferences, then connect this shop to the BEMO account that should use its catalog.'
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('BEMO API URL'),
                        'name' => 'BEMO_API_BASE_URL',
                        'required' => true,
                        'readonly' => $endpointsReadonly,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('BEMO app URL'),
                        'name' => 'BEMO_APP_BASE_URL',
                        'required' => true,
                        'readonly' => $endpointsReadonly,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Allow read-only catalog access'),
                        'name' => 'BEMO_CONFIRM_WEBSERVICE',
                        'is_bool' => true,
                        'desc' => $this->l(
                            'Required to connect. BEMO enables the PrestaShop Webservice and creates a module-owned read-only key only when you click Save and connect to BEMO.'
                        ),
                        'values' => array(
                            array('id' => 'bemo_ws_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'bemo_ws_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Open the shop inside BEMO'),
                        'name' => 'BEMO_CONFIRM_EMBEDDED_CHECKOUT',
                        'is_bool' => true,
                        'desc' => $this->l(
                            'Choose Yes to request the embedded cart and checkout. Your shop must use HTTPS, cross-site checkout cookies, and framing headers that allow BEMO. BEMO reviews this once per shop; until approval, the shop safely opens in a new tab.'
                        ),
                        'values' => array(
                            array('id' => 'bemo_embed_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'bemo_embed_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                    ),
                    array(
                        'type' => 'radio',
                        'label' => $this->l('After adding a product'),
                        'name' => 'BEMO_CHECKOUT_LANDING',
                        'desc' => $this->l(
                            'Open the cart so viewers can keep shopping, or continue directly to checkout. This choice applies immediately to new BEMO product clicks.'
                        ),
                        'values' => array(
                            array(
                                'id' => 'bemo_checkout_landing_cart',
                                'value' => CheckoutLanding::CART,
                                'label' => $this->l('Open the cart (recommended)'),
                            ),
                            array(
                                'id' => 'bemo_checkout_landing_checkout',
                                'value' => CheckoutLanding::CHECKOUT,
                                'label' => $this->l('Continue directly to checkout'),
                            ),
                        ),
                    ),
                ),
                'buttons' => array(
                    array(
                        'title' => $this->l('Save and connect to BEMO'),
                        'name' => 'submitBemoActivateAccount',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-left',
                        'icon' => 'process-icon-key',
                    ),
                    array(
                        'title' => $this->l('Disconnect from BEMO'),
                        'name' => 'submitBemoDisconnect',
                        'type' => 'submit',
                        'class' => 'btn btn-default pull-left',
                        'icon' => 'process-icon-delete',
                        'js' => 'onclick="return confirm(\'' . $this->l(
                            'Disconnect this shop from BEMO? The read-only catalog key is deleted and BEMO stops receiving catalog events until you connect again.'
                        ) . '\');"',
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save settings'),
                ),
            ),
        );
    }

    private function isDeveloperMode()
    {
        return defined('_PS_MODE_DEV_') && _PS_MODE_DEV_ === true;
    }

    private function registerBemoHooks()
    {
        $hooks = array(
            'actionObjectProductAddAfter',
            'actionObjectProductUpdateAfter',
            'actionObjectProductDeleteAfter',
            'actionUpdateQuantity',
            'actionObjectSpecificPriceAddAfter',
            'actionObjectSpecificPriceUpdateAfter',
            'actionObjectSpecificPriceDeleteAfter',
            'actionObjectCartRuleAddAfter',
            'actionObjectCartRuleUpdateAfter',
            'actionObjectCartRuleDeleteAfter',
            'actionCronJob',
        );

        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        return true;
    }

    private function enqueueObjectWebhook($params, $hook, $resourceType)
    {
        $object = isset($params['object']) ? $params['object'] : null;
        $resourceId = is_object($object) && isset($object->id) ? (int) $object->id : 0;

        return $this->enqueueWebhook($hook, $resourceType, $resourceId);
    }

    private function enqueueWebhook($hook, $resourceType, $resourceId)
    {
        if ($resourceId <= 0 || !isset($this->context->shop->id)) {
            return false;
        }

        try {
            $queued = $this->webhookOutbox()->enqueue(
                (int) $this->context->shop->id,
                $hook,
                $resourceType,
                $resourceId
            );
            if (!$queued) {
                PrestaShopLogger::addLog('BEMO webhook event could not be queued', 3);
            }

            return $queued;
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO webhook event could not be queued', 3);

            return false;
        }
    }

    private function webhookOutbox()
    {
        $db = Db::getInstance();
        $endpoints = new EndpointNormalizer();

        return new WebhookOutbox(
            new DbConfigurationRepository($db),
            new DbOutboxRepository($db),
            new CurlWebhookGateway($endpoints, 'BEMO-PrestaShop/' . self::VERSION),
            new PrestaShopShopDetailsProvider($endpoints),
            new SecretGenerator(),
            new DbShopLock($db)
        );
    }

}
