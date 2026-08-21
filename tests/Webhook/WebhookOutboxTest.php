<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use Bemo\LiveShopping\Checkout\CheckoutLanding;
use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
use Bemo\LiveShopping\Lock\LockUnavailableException;
use Bemo\LiveShopping\Lock\ShopLockInterface;
use Bemo\LiveShopping\Pairing\ShopDetailsProviderInterface;
use Bemo\LiveShopping\Security\SecretGenerator;
use Bemo\LiveShopping\Webhook\OutboxRepositoryInterface;
use Bemo\LiveShopping\Webhook\WebhookDeliveryException;
use Bemo\LiveShopping\Webhook\WebhookGatewayInterface;
use Bemo\LiveShopping\Webhook\WebhookOutbox;
use PHPUnit\Framework\TestCase;

class WebhookOutboxTest extends TestCase
{
    public function testConfigurationChangeUsesTheAuthenticatedEnvelope()
    {
        $outbox = new WebhookOutboxRepositoryFake();
        $service = $this->service(new WebhookConfigurationFake(), $outbox, new WebhookGatewayFake(), 1700000000);

        self::assertTrue($service->enqueueConfiguration(7, true, '0.7.0'));
        $payload = json_decode($outbox->rows[0]['payload'], true);
        self::assertSame(array(
            'eventId' => $outbox->rows[0]['event_id'],
            'hook' => 'configuration.updated',
            'occurredAt' => 1700000000000,
            'resourceId' => 7,
            'resourceType' => 'configuration',
            'shopId' => 7,
            'shopUrl' => 'https://shop.example',
            'configuration' => array(
                'embeddedCheckoutRequested' => true,
                'moduleVersion' => '0.7.0',
            ),
        ), $payload);
        self::assertSame('configuration:configuration.updated', $outbox->rows[0]['resource_key']);
    }

    public function testEnqueuePersistsAStableRawEnvelopeWithoutCallingTheNetwork()
    {
        $configuration = new WebhookConfigurationFake();
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $service = $this->service($configuration, $outbox, $gateway, 1700000000);

        self::assertTrue($service->enqueue(7, 'product.updated', 'product', 42));
        self::assertCount(1, $outbox->rows);
        self::assertCount(0, $gateway->deliveries);

        $row = $outbox->rows[0];
        $payload = json_decode($row['payload'], true);
        self::assertSame($row['event_id'], $payload['eventId']);
        self::assertRegExp('/^prestashop:[A-Za-z0-9_-]{22}$/', $payload['eventId']);
        self::assertSame('product.updated', $payload['hook']);
        self::assertSame(1700000000000, $payload['occurredAt']);
        self::assertSame(42, $payload['resourceId']);
        self::assertSame('product', $payload['resourceType']);
        self::assertSame(7, $payload['shopId']);
        self::assertSame('https://shop.example', $payload['shopUrl']);
    }

    public function testUnconfiguredShopDoesNotAccumulateEvents()
    {
        $configuration = new WebhookConfigurationFake();
        $configuration->credentials = null;
        $outbox = new WebhookOutboxRepositoryFake();

        self::assertTrue($this->service(
            $configuration,
            $outbox,
            new WebhookGatewayFake(),
            1700000000
        )->enqueue(7, 'stock.updated', 'stock', 42));
        self::assertSame(array(), $outbox->rows);
    }

    public function testDrainDeletesDeliveredAndCredentiallessEvents()
    {
        $configuration = new WebhookConfigurationFake();
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $service = $this->service($configuration, $outbox, $gateway, 1700000000);
        $service->enqueue(7, 'voucher.added', 'voucher', 9);

        self::assertSame(1, $service->drain());
        self::assertSame(array(), $outbox->rows);
        self::assertCount(1, $gateway->deliveries);
        self::assertSame('https://actions.bemo.now', $gateway->deliveries[0][0]);
        self::assertSame('webhook-secret', $gateway->deliveries[0][2]);
    }

    public function testTransientFailuresUseCappedExponentialBackoff()
    {
        $configuration = new WebhookConfigurationFake();
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $gateway->failure = new WebhookDeliveryException(true);
        $service = $this->service($configuration, $outbox, $gateway, 1700000000);
        $service->enqueue(7, 'price.updated', 'price', 12);

        self::assertSame(0, $service->drain());
        self::assertSame(1, $outbox->rows[0]['attempts']);
        self::assertSame(1700000030, $outbox->rows[0]['available_at']);
        self::assertSame('pending', $outbox->rows[0]['status']);
    }

    public function testPermanentFailuresBecomeDeadLetters()
    {
        $configuration = new WebhookConfigurationFake();
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $gateway->failure = new WebhookDeliveryException(false);
        $service = $this->service($configuration, $outbox, $gateway, 1700000000);
        $service->enqueue(7, 'product.deleted', 'product', 12);

        self::assertSame(0, $service->drain());
        self::assertSame('dead_letter', $outbox->rows[0]['status']);
    }

    public function testEnqueueNeverWaitsOnTheDrainLock()
    {
        $outbox = new WebhookOutboxRepositoryFake();
        $lock = new WebhookLockFake();

        self::assertTrue($this->service(
            new WebhookConfigurationFake(),
            $outbox,
            new WebhookGatewayFake(),
            1700000000,
            $lock
        )->enqueue(7, 'product.updated', 'product', 42));

        self::assertSame(array(), $lock->scopes);
        self::assertSame('product:42:product.updated', $outbox->rows[0]['resource_key']);
    }

    public function testEachShopIsDrainedUnderItsOwnLock()
    {
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $lock = new WebhookLockFake();
        $service = $this->service(new WebhookConfigurationFake(), $outbox, $gateway, 1700000000, $lock);
        $service->enqueue(7, 'product.updated', 'product', 42);
        $service->enqueue(9, 'product.updated', 'product', 42);

        self::assertSame(2, $service->drain());
        self::assertSame(array(array('webhook-outbox', 7), array('webhook-outbox', 9)), $lock->scopes);
    }

    public function testABusyShopIsSkippedWithoutBlockingTheOthers()
    {
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $lock = new WebhookLockFake();
        $lock->unavailableShopIds = array(7);
        $service = $this->service(new WebhookConfigurationFake(), $outbox, $gateway, 1700000000, $lock);
        $service->enqueue(7, 'product.updated', 'product', 42);
        $service->enqueue(9, 'product.updated', 'product', 42);

        self::assertSame(1, $service->drain());
        self::assertCount(1, $outbox->rows);
        self::assertSame(7, $outbox->rows[0]['id_shop']);
    }

    public function testDrainingOneShopLeavesTheOtherShopQueued()
    {
        $outbox = new WebhookOutboxRepositoryFake();
        $gateway = new WebhookGatewayFake();
        $service = $this->service(new WebhookConfigurationFake(), $outbox, $gateway, 1700000000);
        $service->enqueue(7, 'product.updated', 'product', 42);
        $service->enqueue(9, 'product.updated', 'product', 42);

        self::assertSame(1, $service->drainShop(9));
        self::assertCount(1, $outbox->rows);
        self::assertSame(7, $outbox->rows[0]['id_shop']);
    }

    private function service($configuration, $outbox, $gateway, $now, $lock = null)
    {
        return new WebhookOutbox(
            $configuration,
            $outbox,
            $gateway,
            new WebhookShopDetailsFake(),
            new SecretGenerator(),
            $lock === null ? new WebhookLockFake() : $lock,
            function () use ($now) {
                return $now;
            }
        );
    }
}

class WebhookConfigurationFake implements ConfigurationRepositoryInterface
{
    public $credentials = array(
        'credentials_api_base_url' => 'https://actions.bemo.now',
        'webhook_secret' => 'webhook-secret',
    );

    public function getPairingCredentials($shopId) { return $this->credentials; }
    public function getApiBaseUrl($shopId) { return 'https://actions.bemo.now'; }
    public function getAppBaseUrl($shopId) { return 'https://bemo.now'; }
    public function saveEndpoints($shopId, $apiBaseUrl, $appBaseUrl) { return true; }
    public function saveActivationChoices($shopId, $approved, $embedded, $landing = CheckoutLanding::CART) { return true; }
    public function isWebserviceAccessApproved($shopId) { return true; }
    public function isEmbeddedCheckoutRequested($shopId) { return false; }
    public function getCheckoutLanding($shopId) { return CheckoutLanding::CART; }
    public function getConnectionStatus($shopId) { return 'ready_to_pair'; }
    public function getCronToken($shopId) { return null; }
    public function saveCronToken($shopId, $cronToken) { return true; }
    public function getWebserviceAccountId($shopId) { return null; }
    public function getWebserviceAccountIds() { return array(); }
    public function getPairingAttempt($shopId) { return null; }
    public function beginPairingAttempt($shopId, $pairingToken) { return true; }
    public function markPairingStarted($shopId, $pairingToken, $expiresAt) { return true; }
    public function markPairingClaimed($shopId, $pairingToken) { return true; }
    public function clearPairingAttempt($shopId, $pairingToken) { return true; }
    public function clearProvisionedCredentials($shopId) { return true; }
    public function saveProvisionedCredentials($shopId, $accountId, $key, $webhook, $buy, $api) { return true; }
}

class WebhookOutboxRepositoryFake implements OutboxRepositoryInterface
{
    public $rows = array();

    public function enqueue($shopId, $eventId, $resourceKey, $rawPayload, $availableAt)
    {
        $this->rows[] = array(
            'id_bemoliveshopping_outbox' => count($this->rows) + 1,
            'id_shop' => $shopId,
            'event_id' => $eventId,
            'resource_key' => $resourceKey,
            'payload' => $rawPayload,
            'attempts' => 0,
            'status' => 'pending',
            'available_at' => $availableAt,
        );

        return true;
    }

    public function getDueShopIds($limit, $now)
    {
        $shopIds = array();
        foreach ($this->due($now) as $row) {
            $shopIds[] = (int) $row['id_shop'];
        }

        return array_values(array_unique($shopIds));
    }

    public function getDueForShop($shopId, $limit, $now)
    {
        return array_values(array_filter($this->due($now), function ($row) use ($shopId) {
            return (int) $row['id_shop'] === (int) $shopId;
        }));
    }

    public function countPending($shopId)
    {
        return count(array_filter($this->rows, function ($row) use ($shopId) {
            return $row['status'] === 'pending' && (int) $row['id_shop'] === (int) $shopId;
        }));
    }

    private function due($now)
    {
        return array_values(array_filter($this->rows, function ($row) use ($now) {
            return $row['status'] === 'pending' && $row['available_at'] <= $now;
        }));
    }

    public function delete($outboxId)
    {
        $this->rows = array_values(array_filter($this->rows, function ($row) use ($outboxId) {
            return $row['id_bemoliveshopping_outbox'] !== $outboxId;
        }));

        return true;
    }

    public function markFailure($outboxId, $attempts, $availableAt, $terminal)
    {
        foreach ($this->rows as &$row) {
            if ($row['id_bemoliveshopping_outbox'] === $outboxId) {
                $row['attempts'] = $attempts;
                $row['available_at'] = $availableAt;
                $row['status'] = $terminal ? 'dead_letter' : 'pending';
            }
        }

        return true;
    }

    public function purgeTerminalBefore($timestamp)
    {
        return true;
    }
}

class WebhookGatewayFake implements WebhookGatewayInterface
{
    public $deliveries = array();
    public $failure;

    public function deliver($apiBaseUrl, $rawPayload, $secret)
    {
        $this->deliveries[] = array($apiBaseUrl, $rawPayload, $secret);
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return true;
    }
}

class WebhookShopDetailsFake implements ShopDetailsProviderInterface
{
    public function get($shopId)
    {
        return array('shopUrl' => 'https://shop.example');
    }
}

class WebhookLockFake implements ShopLockInterface
{
    public $scopes = array();
    public $unavailableShopIds = array();

    public function synchronized($scope, $shopId, $callback)
    {
        $this->scopes[] = array($scope, (int) $shopId);
        if (in_array((int) $shopId, $this->unavailableShopIds, true)) {
            throw new LockUnavailableException('busy');
        }

        return call_user_func($callback);
    }
}
