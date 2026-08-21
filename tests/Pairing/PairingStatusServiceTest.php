<?php

namespace Bemo\LiveShopping\Tests\Pairing;

use Bemo\LiveShopping\Checkout\CheckoutLanding;
use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Pairing\PairingStatusGatewayInterface;
use Bemo\LiveShopping\Pairing\PairingStatusService;
use PHPUnit\Framework\TestCase;

class PairingStatusServiceTest extends TestCase
{
    public function testMarksAClaimedPairingAsConnected()
    {
        $configuration = new PairingStatusConfiguration();
        $gateway = new PairingStatusGateway('claimed');

        self::assertTrue((new PairingStatusService($configuration, $gateway))->reconcile(7));
        self::assertSame(array(7, 'pairing-token'), $configuration->claimed);
        self::assertSame(array('https://api.example.com', 'pairing-token'), $gateway->request);
    }

    public function testLeavesAPendingPairingUntouched()
    {
        $configuration = new PairingStatusConfiguration();
        $gateway = new PairingStatusGateway('pending');

        self::assertFalse((new PairingStatusService($configuration, $gateway))->reconcile(7));
        self::assertNull($configuration->claimed);
    }

    public function testDoesNotQueryBemoForAnAlreadyConnectedShop()
    {
        $configuration = new PairingStatusConfiguration();
        $configuration->status = 'connected';
        $gateway = new PairingStatusGateway('claimed');

        self::assertFalse((new PairingStatusService($configuration, $gateway))->reconcile(7));
        self::assertNull($gateway->request);
    }
}

class PairingStatusConfiguration implements ConfigurationRepositoryInterface
{
    public $status = 'pairing_pending';
    public $claimed;

    public function getApiBaseUrl($shopId) { return 'https://api.example.com'; }
    public function getAppBaseUrl($shopId) { return 'https://bemo.example.com'; }
    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl) { return true; }
    public function saveActivationChoices($shopId, $approved, $embedded, $landing = CheckoutLanding::CART) { return true; }
    public function isWebserviceAccessApproved($shopId) { return true; }
    public function isEmbeddedCheckoutRequested($shopId) { return false; }
    public function getCheckoutLanding($shopId) { return CheckoutLanding::CART; }
    public function getConnectionStatus($shopId) { return $this->status; }
    public function getCronToken($shopId) { return null; }
    public function saveCronToken($shopId, $cronToken) { return true; }
    public function getWebserviceAccountId($shopId) { return 1; }
    public function getWebserviceAccountIds() { return array(1); }
    public function getPairingCredentials($shopId) { return array(); }
    public function getPairingAttempt($shopId) { return array('pairing_token' => 'pairing-token', 'expires_at' => 1900000); }
    public function beginPairingAttempt($shopId, $pairingToken) { return true; }
    public function markPairingStarted($shopId, $pairingToken, $expiresAt) { return true; }
    public function markPairingClaimed($shopId, $pairingToken) { $this->claimed = array($shopId, $pairingToken); return true; }
    public function clearPairingAttempt($shopId, $pairingToken) { return true; }
    public function clearProvisionedCredentials($shopId) { return true; }
    public function saveProvisionedCredentials($shopId, $accountId, $key, $webhook, $buy, $api) { return true; }
}

class PairingStatusGateway implements PairingStatusGatewayInterface
{
    public $request;
    private $status;

    public function __construct($status)
    {
        $this->status = $status;
    }

    public function status($apiBaseUrl, $pairingToken)
    {
        $this->request = array($apiBaseUrl, $pairingToken);

        return $this->status;
    }
}
