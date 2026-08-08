<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use Bemo\LiveShopping\Configuration\ConfigurationRepositoryInterface;
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

    private function service($configuration, $outbox, $gateway, $now)
    {
        return new WebhookOutbox(
            $configuration,
            $outbox,
            $gateway,
            new WebhookShopDetailsFake(),
            new SecretGenerator(),
            new WebhookLockFake(),
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
    public function getWebserviceAccountId($shopId) { return null; }
    public function getWebserviceAccountIds() { return array(); }
    public function getPairingAttempt($shopId) { return null; }
    public function beginPairingAttempt($shopId, $pairingToken) { return true; }
    public function markPairingStarted($shopId, $pairingToken, $expiresAt) { return true; }
    public function clearPairingAttempt($shopId, $pairingToken) { return true; }
    public function clearProvisionedCredentials($shopId) { return true; }
    public function saveProvisionedCredentials($shopId, $accountId, $key, $webhook, $buy, $api) { return true; }
}

class WebhookOutboxRepositoryFake implements OutboxRepositoryInterface
{
    public $rows = array();

    public function enqueue($shopId, $eventId, $rawPayload, $availableAt)
    {
        $this->rows[] = array(
            'id_bemoliveshopping_outbox' => count($this->rows) + 1,
            'id_shop' => $shopId,
            'event_id' => $eventId,
            'payload' => $rawPayload,
            'attempts' => 0,
            'status' => 'pending',
            'available_at' => $availableAt,
        );

        return true;
    }

    public function getDue($limit, $now)
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
    public function synchronized($scope, $shopId, $callback)
    {
        return call_user_func($callback);
    }
}
