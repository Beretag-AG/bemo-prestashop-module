<?php

/*
 * Copyright (c) 2026 Beretag AG
 * Licensed under the Academic Free License version 3.0.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/config/distribution.php';
require_once __DIR__ . '/config/autoload.php';

use Bemo\LiveShopping\Configuration\DbConfigurationRepository;
use Bemo\LiveShopping\Checkout\DbBuyLinkNonceRepository;
use Bemo\LiveShopping\Checkout\CheckoutReadyBridge;
use Bemo\LiveShopping\Installation\Installer;
use Bemo\LiveShopping\Installation\InstalledVersionReconciler;
use Bemo\LiveShopping\Installation\PrestaShopModuleVersionRepository;
use Bemo\LiveShopping\Lock\DbShopLock;
use Bemo\LiveShopping\Pairing\CurlPairingGateway;
use Bemo\LiveShopping\Pairing\CurlPairingStatusGateway;
use Bemo\LiveShopping\Pairing\EndpointEnvironment;
use Bemo\LiveShopping\Pairing\EndpointNormalizer;
use Bemo\LiveShopping\Pairing\EndpointPolicy;
use Bemo\LiveShopping\Pairing\PairingException;
use Bemo\LiveShopping\Pairing\PairingResponseParser;
use Bemo\LiveShopping\Pairing\PairingService;
use Bemo\LiveShopping\Pairing\PairingStatusService;
use Bemo\LiveShopping\Pairing\PrestaShopShopDetailsProvider;
use Bemo\LiveShopping\Presentation\ConfigurationPageState;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Setup\ConnectionSetupService;
use Bemo\LiveShopping\Webservice\PrestaShopWebserviceGateway;
use Bemo\LiveShopping\Webservice\ReadOnlyPermissionMap;
use Bemo\LiveShopping\Webhook\CurlWebhookGateway;
use Bemo\LiveShopping\Webhook\DbOutboxRepository;
use Bemo\LiveShopping\Webhook\WebhookOutbox;

class Bemoliveshopping extends Module
{
    const VERSION = '0.8.4';
    const CRON_CONTROLLER = 'cron';
    const DOCS_URL = 'https://github.com/Beretag-AG/bemo-prestashop-module#readme';

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
            'min' => '1.7.3.1',
            'max' => '8.99.99',
        );

        parent::__construct();

        $this->displayName = $this->l('BEMO Live Shopping');
        $this->description = $this->l('Connect your shops to BEMO and choose what to sell in each live session.');
        $this->confirmUninstall = $this->l('Uninstall BEMO Live Shopping? Your settings are deleted and the read-only catalog key is removed from this shop.');
    }

    public function install()
    {
        if (version_compare(PHP_VERSION, '7.0', '<')) {
            $this->_errors[] = $this->l('BEMO Live Shopping requires PHP 7.0 or newer.');

            return false;
        }
        if (version_compare(PHP_VERSION, '8.2', '>=')) {
            $this->_errors[] = $this->l('BEMO Live Shopping supports PHP 7.0 through 8.1.');

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
            && $this->synchronizeWebservicePermissions();
    }

    public function upgradeToVersion060()
    {
        return $this->registerBemoHooks();
    }

    public function upgradeToVersion063()
    {
        return $this->registerBemoHooks();
    }

    public function upgradeToVersion064()
    {
        return $this->registerBemoHooks();
    }

    public function upgradeToVersion065()
    {
        return true;
    }

    public function upgradeToVersion070()
    {
        return (new Installer(Db::getInstance()))->upgradeToVersion070()
            && $this->registerBemoHooks()
            && $this->synchronizeWebservicePermissions();
    }

    public function upgradeToVersion083()
    {
        return $this->synchronizeWebservicePermissions();
    }

    public function getContent()
    {
        // AdminModules otherwise opens PrestaShop's generic documentation next
        // to this module's own setup instructions.
        $this->context->smarty->clearAssign('help_link');

        if (!(new InstalledVersionReconciler(
            new PrestaShopModuleVersionRepository()
        ))->reconcile($this->name, self::VERSION)) {
            $this->output .= $this->displayError(
                $this->l('PrestaShop could not finish the BEMO update. Your shop data was not changed. Please try the update again or contact your shop administrator.')
            );
        }

        if (Shop::getContext() !== Shop::CONTEXT_SHOP) {
            return $this->renderShopOverview();
        }

        if (Tools::isSubmit('submitBemoActivateAccount')) {
            $this->activateBemoAccount();
        } elseif (Tools::isSubmit('submitBemoDisconnect')) {
            $this->disconnectFromBemo();
        } elseif (Tools::isSubmit('submitBemoConfiguration')) {
            $this->saveConfiguration();
        }

        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $this->reconcilePairingStatus($repository, $shopId);
        $state = ConfigurationPageState::derive(
            $repository,
            $shopId,
            $this->isDeveloperMode()
        );

        return $this->output
            . $this->renderWelcomePanel($state)
            . $this->renderConnectionPanel($state)
            . $this->renderCatalogSyncPanel($state)
            . $this->renderConfigurationForm($state)
            . $this->renderDisconnectPanel($state);
    }

    private function renderShopOverview()
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shops = array();

        foreach (Shop::getShops(false) as $shop) {
            if (!isset($shop['id_shop'], $shop['name'])) {
                continue;
            }
            $shopId = (int) $shop['id_shop'];
            $state = ConfigurationPageState::derive(
                $repository,
                $shopId,
                $this->isDeveloperMode()
            );
            $status = $this->connectionStatusBadge($state);
            $shopModel = new Shop($shopId);
            $storefrontUrl = Validate::isLoadedObject($shopModel)
                ? $shopModel->getBaseURL(true, true)
                : '';
            $shops[] = array(
                'shopId' => $shopId,
                'name' => (string) $shop['name'],
                'storefrontUrl' => $storefrontUrl,
                'status' => $status[0],
                'badge' => $status[1],
                'configurationUrl' => $this->shopConfigurationUrl($shopId),
                'action' => $state->isConnected()
                    ? $this->l('Manage shop')
                    : ($state->isWaiting()
                        ? $this->l('Finish connection')
                        : $this->l('Connect shop')),
            );
        }

        $this->context->smarty->assign(array(
            'bemoTitle' => $this->l('Connect your PrestaShop shops'),
            'bemoIntro' => $this->l(
                'Connect each shop once. Use the same BEMO account to keep them together.'
            ),
            'bemoHelp' => $this->l(
                'In BEMO, choose one shop and its products for each live session.'
            ),
            'bemoShops' => $shops,
        ));

        return $this->display(__FILE__, 'views/templates/admin/shop-overview.tpl');
    }

    private function shopConfigurationUrl($shopId)
    {
        return AdminController::$currentIndex
            . '&configure=' . $this->name
            . '&setShopContext=s-' . (int) $shopId
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
    }

    private function reconcilePairingStatus(DbConfigurationRepository $repository, $shopId)
    {
        $endpoints = new EndpointNormalizer();
        try {
            (new PairingStatusService(
                $repository,
                new CurlPairingStatusGateway($endpoints, 'BEMO-PrestaShop/' . self::VERSION)
            ))->reconcile($shopId);
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO pairing status could not be refreshed', 2);
        }
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

    public function hookDisplayFooter()
    {
        $requestToken = Tools::getValue(CheckoutReadyBridge::QUERY_NAME);
        if (!is_string($requestToken)
            || !isset($this->context->cookie->{CheckoutReadyBridge::COOKIE_NAME})) {
            return '';
        }

        $cookieValue = $this->context->cookie->{CheckoutReadyBridge::COOKIE_NAME};
        unset($this->context->cookie->{CheckoutReadyBridge::COOKIE_NAME});
        $bridge = new CheckoutReadyBridge();
        if (!$bridge->isReadyRequest($cookieValue, $requestToken, time())) {
            return '';
        }

        $shopId = isset($this->context->shop->id) ? (int) $this->context->shop->id : 0;
        $parentOrigin = $bridge->parentOrigin(
            (new DbConfigurationRepository(Db::getInstance()))->getAppBaseUrl($shopId)
        );

        return $parentOrigin === null ? '' : $bridge->messageScript($parentOrigin);
    }

    /** Drains queued notifications for the optional scheduler integrations. */
    public function drainWebhookOutbox($shopId = null, $limit = null)
    {
        try {
            $drained = $shopId === null
                ? $this->webhookOutbox()->drain()
                : $this->webhookOutbox()->drainShop(
                    (int) $shopId,
                    $limit === null ? WebhookOutbox::MAX_BATCH_SIZE : max(1, (int) $limit)
                );
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

        $this->output .= $this->displayConfirmation($this->l('Your settings are saved.'));
    }

    private function disconnectFromBemo()
    {
        $shopId = (int) $this->context->shop->id;
        $repository = new DbConfigurationRepository(Db::getInstance());

        if (!$this->revokeCatalogAccess($shopId)
            || !$this->persistActivationChoices(
                $shopId,
                false,
                $repository->isEmbeddedCheckoutRequested($shopId)
            )) {
            return;
        }

        $this->output .= $this->displayConfirmation(
            $this->l('This shop is disconnected from BEMO. The catalog key was deleted and live shows can no longer sell your products.')
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
                $this->l('Let BEMO read your catalog before you connect. Without it, BEMO cannot show your products in a live show.')
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
        $endpointPolicy = new EndpointPolicy(
            $endpoints,
            $this->isDeveloperMode(),
            BEMO_DISTRIBUTION_ENVIRONMENT
        );
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
                $this->l('This shop could not be connected to BEMO. Check the settings below and try again.')
            );
        }
    }

    private function validatedEndpointsFromRequest()
    {
        $normalizer = new EndpointNormalizer();
        $policy = new EndpointPolicy(
            $normalizer,
            $this->isDeveloperMode(),
            BEMO_DISTRIBUTION_ENVIRONMENT
        );
        $environment = new EndpointEnvironment();
        $environmentValues = $environment->overrideValues($policy);
        $pair = $this->isDeveloperMode()
            ? ($environmentValues !== null
                ? $policy->normalizePair($environmentValues[0], $environmentValues[1])
                : $policy->normalizePair(
                    Tools::getValue('BEMO_API_BASE_URL'),
                    Tools::getValue('BEMO_APP_BASE_URL')
                ))
            : $policy->officialPair();

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
            $this->output .= $this->displayError(
                $this->l('Your settings could not be saved. Try again in a moment.')
            );

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

        $embedded = $this->requestedChoice(
            'BEMO_CONFIRM_EMBEDDED_CHECKOUT',
            $repository->isEmbeddedCheckoutRequested($shopId)
        );
        $changed = $embedded !== $repository->isEmbeddedCheckoutRequested($shopId);
        if (!$this->persistActivationChoices($shopId, $requestedApproval, $embedded)) {
            return false;
        }

        if ($changed) {
            try {
                $queued = $this->webhookOutbox()->enqueueConfiguration($shopId, $embedded, self::VERSION);
            } catch (Exception $exception) {
                $queued = false;
            }
            if (!$queued) {
                PrestaShopLogger::addLog('BEMO configuration notification could not be queued', 3);
                $this->output .= $this->displayError(
                    $this->l('Your settings were saved, but the update could not be queued. BEMO will reconcile it during the next catalog sync.')
                );
            }
        }

        return true;
    }

    private function persistActivationChoices($shopId, $approved, $embedded)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        try {
            $saved = (new DbShopLock(Db::getInstance()))->synchronized(
                'configuration',
                $shopId,
                function () use ($repository, $shopId, $approved, $embedded) {
                    return $repository->saveActivationChoices($shopId, $approved, $embedded, 'cart');
                }
            );
        } catch (Exception $exception) {
            PrestaShopLogger::addLog('BEMO module settings are busy', 2);
            $saved = false;
        }
        if (!$saved) {
            $this->output .= $this->displayError(
                $this->l('Your choices could not be saved. Try again in a moment.')
            );

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
                $this->l('Catalog access could not be turned off. Try again in a moment.')
            );

            return false;
        }

        return true;
    }

    /** Replaces existing key permissions without rotating the key BEMO holds. */
    private function synchronizeWebservicePermissions()
    {
        $gateway = new PrestaShopWebserviceGateway();
        $permissions = (new ReadOnlyPermissionMap())->build();
        $allSynchronized = true;

        foreach ((new DbConfigurationRepository(Db::getInstance()))->getWebserviceAccountIds() as $accountId) {
            try {
                $repaired = $gateway->updatePermissions($accountId, $permissions);
            } catch (Exception $exception) {
                $repaired = false;
            }
            if (!$repaired) {
                $allSynchronized = false;
                PrestaShopLogger::addLog(
                    'BEMO could not synchronize the read-only Webservice permissions of account ' . (int) $accountId
                    . '. Connect the shop to BEMO again.',
                    2
                );
            }
        }

        return $allSynchronized;
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

    private function pairingErrorMessage($reason)
    {
        if ($reason === PairingException::RATE_LIMITED) {
            return $this->l('Too many connection attempts in a short time. Wait a moment and try again.');
        }

        if ($reason === PairingException::NETWORK) {
            return $this->l('BEMO could not be reached. Check that your shop can make outgoing requests, then try again.');
        }

        if ($reason === PairingException::CONFIGURATION) {
            return $this->l('The BEMO addresses are not valid. Save valid HTTPS addresses and try again.');
        }

        if ($reason === PairingException::SHOP_CONTEXT) {
            return $this->l('Your shop needs an HTTPS address, one active language, and one active currency before it can connect to BEMO.');
        }

        if ($reason === PairingException::CREDENTIALS || $reason === PairingException::PERSISTENCE) {
            return $this->l('The connection could not be prepared. Try again, and contact BEMO support if it keeps failing.');
        }

        return $this->l('BEMO turned down the connection request. Try again to get a fresh connection link.');
    }

    private function renderWelcomePanel(ConfigurationPageState $state)
    {
        if (!$state->isWelcome()) {
            return '';
        }

        $shopName = (string) $this->context->shop->name;
        $this->context->smarty->assign(array(
            'bemoTitle' => sprintf($this->l('Connect %s to BEMO'), $shopName),
            'bemoLead' => $this->l(
                'This setup connects only this shop. Your other PrestaShop shops stay separate.'
            ),
            'bemoStepsTitle' => $this->l('What to expect'),
            'bemoSteps' => array(
                array(
                    'title' => $this->l('BEMO reads this shop only.'),
                    'text' => $this->l(
                        'Its products, prices, stock, currency, and checkout stay separate.'
                    ),
                ),
                array(
                    'title' => $this->l('Your shops stay in one BEMO account.'),
                    'text' => $this->l(
                        'Repeat this setup for each shop while signed in to the same account.'
                    ),
                ),
                array(
                    'title' => $this->l('You choose per live session.'),
                    'text' => $this->l(
                        'In BEMO, select one shop and the products you want to sell.'
                    ),
                ),
            ),
            'bemoSetupAnchor' => '#bemo-setup',
            'bemoCta' => sprintf($this->l('Set up %s'), $shopName),
            'bemoDocsUrl' => self::DOCS_URL,
            'bemoDocsLabel' => $this->l('Read the setup guide'),
        ));

        return $this->display(__FILE__, 'views/templates/admin/welcome.tpl');
    }

    private function renderConnectionPanel(ConfigurationPageState $state)
    {
        if (!$state->showsStatusPanel()) {
            return '';
        }

        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $shopName = (string) $this->context->shop->name;
        $status = $this->connectionStatusBadge($state);

        $this->context->smarty->assign(array(
            'bemoTitle' => sprintf($this->l('BEMO connection for %s'), $shopName),
            'bemoIntro' => $state->isWaiting()
                ? $this->l(
                    'Finish in the BEMO account where you want this shop to appear.'
                )
                : '',
            'bemoRows' => array(
                array(
                    'label' => $this->l('Status'),
                    'value' => $status[0],
                    'badge' => $status[1],
                    'link' => false,
                ),
                array(
                    'label' => $this->l('PrestaShop shop'),
                    'value' => $shopName,
                    'badge' => '',
                    'link' => false,
                ),
                array(
                    'label' => $this->l('Shop address'),
                    'value' => $this->context->shop->getBaseURL(true),
                    'badge' => '',
                    'link' => true,
                ),
                array(
                    'label' => $this->l('BEMO'),
                    'value' => $repository->getAppBaseUrl($shopId),
                    'badge' => '',
                    'link' => true,
                ),
            ),
            'bemoRestartLabel' => $state->showsRestartAction() ? $this->l('Restart connection') : '',
            'bemoRefreshWaiting' => $state->isWaiting(),
            'bemoFormAction' => $this->configurationFormAction(),
            // Developer mode is the only case where the endpoints are editable,
            // so the restart post has to carry what the settings form would send.
            'bemoHiddenFields' => $state->showsEndpointFields()
                ? array(
                    'BEMO_API_BASE_URL' => $repository->getApiBaseUrl($shopId),
                    'BEMO_APP_BASE_URL' => $repository->getAppBaseUrl($shopId),
                )
                : array(),
        ));

        return $this->display(__FILE__, 'views/templates/admin/connection-status.tpl');
    }

    private function renderCatalogSyncPanel(ConfigurationPageState $state)
    {
        if (!$state->showsCatalogSyncPanel()) {
            return '';
        }

        $shopId = (int) $this->context->shop->id;
        $cronUrl = $this->cronUrl($shopId);
        $this->context->smarty->assign(array(
            'bemoTitle' => $this->l('Catalog sync'),
            'bemoManualRetryOpen' => false,
            'bemoStatusTitle' => $this->l('Automatic sync is on'),
            'bemoStatusText' => $this->l(
                'BEMO checks this shop automatically. Product, price, stock, and voucher changes also notify BEMO.'
            ),
            'bemoWarning' => $this->l(
                'If updates stop, check this shop\'s status in BEMO.'
            ),
            'bemoRows' => array(
                array(
                    'label' => $this->l('Primary sync'),
                    'value' => $this->l('Configured in BEMO'),
                ),
                array(
                    'label' => $this->l('Notifications waiting for retry'),
                    'value' => (string) (new DbOutboxRepository(Db::getInstance()))->countPending($shopId),
                ),
            ),
            'bemoQueueHelp' => $this->l(
                'Queued notifications only speed up sync. BEMO still checks the catalog itself.'
            ),
            'bemoManualTitle' => $this->l('Optional manual notification retry'),
            'bemoManualIntro' => $this->l(
                'This is not required for normal catalog sync. If you want the shop to retry immediate notifications on a fixed schedule, create a job that opens this address every 5 minutes.'
            ),
            'bemoManualStepOne' => $this->l('Schedule an HTTP GET request every 5 minutes.'),
            'bemoManualStepTwo' => $this->l('Use the private address shown below as the request URL.'),
            'bemoManualStepThree' => $this->l('Do not share this address. Anyone who has it can retry queued notifications for this shop.'),
            'bemoSyncUrl' => $cronUrl === null ? '' : $cronUrl,
            'bemoSyncUrlLabel' => $this->l('Private sync address'),
            'bemoSyncUrlUnavailable' => $this->l(
                'The sync address is not available yet. Save your settings once and it appears here.'
            ),
        ));

        return $this->display(__FILE__, 'views/templates/admin/catalog-sync.tpl');
    }

    private function renderDisconnectPanel(ConfigurationPageState $state)
    {
        if (!$state->showsDisconnectPanel()) {
            return '';
        }

        $this->context->smarty->assign(array(
            'bemoTitle' => $this->l('Disconnect from BEMO'),
            'bemoText' => $this->l(
                'Disconnecting stops live selling for this shop. BEMO deletes the catalog key and stops receiving updates. Your PrestaShop products, orders, and settings stay unchanged.'
            ),
            'bemoButtonLabel' => $this->l('Disconnect from BEMO'),
            'bemoConfirm' => $this->l(
                'Disconnect this shop from BEMO? Live shows can no longer sell your products until you connect again.'
            ),
            'bemoFormAction' => $this->configurationFormAction(),
        ));

        return $this->display(__FILE__, 'views/templates/admin/disconnect.tpl');
    }

    private function connectionStatusBadge(ConfigurationPageState $state)
    {
        if ($state->isWaiting()) {
            return array($this->l('Waiting for BEMO setup'), 'label-warning');
        }

        if ($state->isConnected()) {
            return array($this->l('Connected'), 'label-success');
        }

        return array($this->l('Not connected'), 'label-default');
    }

    private function configurationFormAction()
    {
        return AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules');
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

    private function renderConfigurationForm(ConfigurationPageState $state)
    {
        $repository = new DbConfigurationRepository(Db::getInstance());
        $shopId = (int) $this->context->shop->id;
        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = $state->showsConnectAction()
            ? 'submitBemoActivateAccount'
            : 'submitBemoConfiguration';
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
        );

        return '<div id="bemo-setup">'
            . $helper->generateForm(array($this->configurationForm($state, $environmentValues !== null)))
            . '</div>';
    }

    private function configurationForm(ConfigurationPageState $state, $environmentEndpointsLocked = false)
    {
        $shopName = (string) $this->context->shop->name;
        $inputs = array();
        if ($state->showsEndpointFields()) {
            $inputs[] = array(
                'type' => 'text',
                'label' => $this->l('BEMO API URL'),
                'name' => 'BEMO_API_BASE_URL',
                'required' => true,
                'readonly' => $environmentEndpointsLocked,
            );
            $inputs[] = array(
                'type' => 'text',
                'label' => $this->l('BEMO app URL'),
                'name' => 'BEMO_APP_BASE_URL',
                'required' => true,
                'readonly' => $environmentEndpointsLocked,
            );
        }

        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Let BEMO read your catalog'),
            'name' => 'BEMO_CONFIRM_WEBSERVICE',
            'is_bool' => true,
            'desc' => $state->isWelcome()
                ? $this->l(
                    'Required. BEMO can read this shop\'s catalog, but cannot change it or read orders or customers.'
                )
                : $this->l(
                    'BEMO reads this shop\'s catalog. Turning this off disconnects the shop and deletes its key.'
                ),
            'values' => array(
                array('id' => 'bemo_ws_on', 'value' => 1, 'label' => $this->l('Yes')),
                array('id' => 'bemo_ws_off', 'value' => 0, 'label' => $this->l('No')),
            ),
        );
        $inputs[] = array(
            'type' => 'switch',
            'label' => $this->l('Let viewers buy without leaving the show'),
            'name' => 'BEMO_CONFIRM_EMBEDDED_CHECKOUT',
            'is_bool' => true,
            'desc' => $this->l(
                'Viewers can check out without leaving the show. This requires HTTPS and BEMO approval. Until then, checkout opens in a new tab.'
            ),
            'values' => array(
                array('id' => 'bemo_embed_on', 'value' => 1, 'label' => $this->l('Yes')),
                array('id' => 'bemo_embed_off', 'value' => 0, 'label' => $this->l('No')),
            ),
        );
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $state->isWelcome()
                        ? sprintf($this->l('Connect %s'), $shopName)
                        : sprintf($this->l('Settings for %s'), $shopName),
                    'icon' => 'icon-cogs',
                ),
                'description' => $state->isWelcome()
                    ? $this->l(
                        'BEMO opens next. Sign in to the account where you want this shop to appear.'
                    )
                    : $this->l(
                        'Change how BEMO reads your catalog and how viewers check out.'
                    ),
                'input' => $inputs,
                'submit' => $state->showsConnectAction()
                    ? array(
                        'title' => sprintf($this->l('Connect %s to BEMO'), $shopName),
                        'name' => 'submitBemoActivateAccount',
                        'class' => 'btn btn-primary pull-right',
                        'icon' => 'process-icon-key',
                    )
                    : array(
                        'title' => $this->l('Save settings'),
                        'name' => 'submitBemoConfiguration',
                        'class' => 'btn btn-primary pull-right',
                        'icon' => 'process-icon-save',
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
            'displayFooter',
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
