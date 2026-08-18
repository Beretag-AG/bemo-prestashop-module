<?php

namespace Bemo\LiveShopping\Tests\Presentation;

use Bemo\LiveShopping\Checkout\CheckoutLanding;
use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Presentation\ConfigurationPageState;
use PHPUnit\Framework\TestCase;

class ConfigurationPageStateTest extends TestCase
{
    public function testAFreshShopIsWelcomedAndOnlyOfferedTheConnectAction()
    {
        $state = $this->state(new StubConfigurationRepository());

        self::assertSame(ConfigurationPageState::STEP_WELCOME, $state->step());
        self::assertTrue($state->isWelcome());
        self::assertTrue($state->showsConnectAction());
        self::assertFalse($state->showsStatusPanel());
        self::assertFalse($state->showsCatalogSyncPanel());
        self::assertFalse($state->showsRestartAction());
        self::assertFalse($state->showsDisconnectPanel());
    }

    public function testConsentWithoutProvisionedCredentialsStaysOnTheWelcomeStep()
    {
        $configuration = new StubConfigurationRepository();
        $configuration->approved = true;
        $configuration->status = 'ready_to_pair';

        self::assertTrue($this->state($configuration)->isWelcome());
    }

    public function testAShopWaitingForAClaimOffersARestartInsteadOfADisconnect()
    {
        foreach (array('pairing_starting', 'pairing_pending') as $status) {
            $configuration = new StubConfigurationRepository();
            $configuration->approved = true;
            $configuration->credentials = $this->credentials();
            $configuration->status = $status;
            $state = $this->state($configuration);

            self::assertSame(ConfigurationPageState::STEP_WAITING, $state->step());
            self::assertTrue($state->showsStatusPanel());
            self::assertTrue($state->showsRestartAction());
            self::assertFalse($state->showsConnectAction());
            self::assertFalse($state->showsCatalogSyncPanel());
            self::assertFalse($state->showsDisconnectPanel());
        }
    }

    public function testAClaimedShopSeesItsHealthAndTheDisconnectPanel()
    {
        $configuration = new StubConfigurationRepository();
        $configuration->approved = true;
        $configuration->credentials = $this->credentials();
        $configuration->status = 'ready_to_pair';
        $state = $this->state($configuration);

        self::assertSame(ConfigurationPageState::STEP_CONNECTED, $state->step());
        self::assertTrue($state->showsStatusPanel());
        self::assertTrue($state->showsCatalogSyncPanel());
        self::assertTrue($state->showsDisconnectPanel());
        self::assertFalse($state->showsConnectAction());
        self::assertFalse($state->showsRestartAction());
    }

    public function testEndpointFieldsAreDeveloperModeOnlyInEveryStep()
    {
        $connected = new StubConfigurationRepository();
        $connected->credentials = $this->credentials();
        $connected->status = 'ready_to_pair';

        foreach (array(new StubConfigurationRepository(), $connected) as $configuration) {
            self::assertFalse($this->state($configuration)->showsEndpointFields());
            self::assertTrue($this->state($configuration, true)->showsEndpointFields());
        }
    }

    private function state($configuration, $developerMode = false)
    {
        return ConfigurationPageState::derive($configuration, 7, $developerMode);
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
}

class StubConfigurationRepository implements ConfigurationRepositoryInterface
{
    public $approved = false;
    public $credentials = null;
    public $status = 'not_configured';

    public function getApiBaseUrl($shopId) { return 'https://actions.bemo.now'; }
    public function getAppBaseUrl($shopId) { return 'https://bemo.now'; }
    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl) { return true; }
    public function saveActivationChoices($shopId, $approved, $embedded, $landing = CheckoutLanding::CART) { return true; }
    public function isWebserviceAccessApproved($shopId) { return $this->approved; }
    public function isEmbeddedCheckoutRequested($shopId) { return false; }
    public function getCheckoutLanding($shopId) { return CheckoutLanding::CART; }
    public function getConnectionStatus($shopId) { return $this->status; }
    public function getCronToken($shopId) { return null; }
    public function saveCronToken($shopId, $cronToken) { return true; }
    public function getWebserviceAccountId($shopId) { return null; }
    public function getWebserviceAccountIds() { return array(); }
    public function getPairingCredentials($shopId) { return $this->credentials; }
    public function getPairingAttempt($shopId) { return null; }
    public function beginPairingAttempt($shopId, $pairingToken) { return true; }
    public function markPairingStarted($shopId, $pairingToken, $expiresAt) { return true; }
    public function clearPairingAttempt($shopId, $pairingToken) { return true; }
    public function clearProvisionedCredentials($shopId) { return true; }
    public function saveProvisionedCredentials($shopId, $accountId, $key, $webhook, $buy, $api) { return true; }
}
