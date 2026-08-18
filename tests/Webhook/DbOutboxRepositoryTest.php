<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use Bemo\LiveShopping\Webhook\DbOutboxRepository;
use PHPUnit\Framework\TestCase;
use PrestaShopLogger;

class DbOutboxRepositoryTest extends TestCase
{
    protected function setUp(): void
    {
        PrestaShopLogger::$logs = array();
    }

    public function testEscapesTheStoredPayloadWithoutLosingItsSignedBytes()
    {
        $db = new OutboxDbFake();
        $rawPayload = '{"shopUrl":"https://o\'brien.example","title":"A \\"quoted\\" item"}';

        self::assertTrue((new DbOutboxRepository($db))->enqueue(
            7,
            'prestashop:abcdefghijklmnopqrstuv',
            'product:119:product.updated',
            $rawPayload,
            1800000000
        ));

        self::assertNotSame($rawPayload, $db->values['payload']);
        self::assertSame($rawPayload, stripslashes($db->values['payload']));
        self::assertStringNotContainsString('LIMIT', $db->getValueQueries[0]);
    }

    public function testCoalescesOnlyTheSameResourceAndHook()
    {
        $db = new OutboxDbFake();
        $db->pendingId = 12;
        $repository = new DbOutboxRepository($db);

        self::assertTrue($repository->enqueue(
            7,
            'prestashop:latesteventidentifier',
            'product:119:product.updated',
            '{"hook":"product.updated"}',
            1800000001
        ));

        self::assertSame(0, $db->insertCount);
        self::assertSame(12, $db->updatedId);
        self::assertSame('prestashop:latesteventidentifier', $db->values['event_id']);
        self::assertSame('product:119:product.updated', $db->values['resource_key']);
        self::assertArrayNotHasKey('date_add', $db->values);
        self::assertStringContainsString(
            "`resource_key` = 'product:119:product.updated'",
            $db->getValueQueries[0]
        );
    }

    public function testInsertsAnEventForEveryOtherResource()
    {
        $db = new OutboxDbFake();
        $repository = new DbOutboxRepository($db);

        self::assertTrue($repository->enqueue(7, 'prestashop:one', 'product:119:product.updated', '{"a":1}', 1800000000));
        self::assertTrue($repository->enqueue(7, 'prestashop:two', 'product:120:product.updated', '{"a":2}', 1800000001));
        self::assertTrue($repository->enqueue(7, 'prestashop:three', 'stock:119:stock.updated', '{"a":3}', 1800000002));

        self::assertSame(3, $db->insertCount);
        self::assertSame(array('prestashop:one', 'prestashop:two', 'prestashop:three'), $db->insertedEventIds);
    }

    public function testDropsTheOldestPendingEventsWhenTheShopQueueIsFull()
    {
        $db = new OutboxDbFake();
        $db->pendingCount = DbOutboxRepository::MAX_PENDING_PER_SHOP + 2;

        self::assertTrue((new DbOutboxRepository($db))->enqueue(
            7,
            'prestashop:one',
            'product:119:product.updated',
            '{"a":1}',
            1800000000
        ));

        self::assertStringContainsString('DELETE FROM', $db->executed[0]);
        self::assertStringContainsString('ORDER BY `id_bemoliveshopping_outbox` ASC LIMIT 3', $db->executed[0]);
        self::assertSame(1, $db->insertCount);
        self::assertCount(1, PrestaShopLogger::$logs);
        self::assertSame(2, PrestaShopLogger::$logs[0]['severity']);
    }

    public function testKeepsEveryEventWhileTheShopQueueHasRoom()
    {
        $db = new OutboxDbFake();
        $db->pendingCount = DbOutboxRepository::MAX_PENDING_PER_SHOP - 1;

        self::assertTrue((new DbOutboxRepository($db))->enqueue(
            7,
            'prestashop:one',
            'product:119:product.updated',
            '{"a":1}',
            1800000000
        ));

        self::assertSame(array(), $db->executed);
        self::assertSame(array(), PrestaShopLogger::$logs);
    }

    public function testReadsDueEventsOnlyForTheRequestedShop()
    {
        $db = new OutboxDbFake();
        $db->rows = array(array('id_bemoliveshopping_outbox' => 5));

        self::assertSame($db->rows, (new DbOutboxRepository($db))->getDueForShop(7, 25, 1800000000));
        self::assertStringContainsString('`id_shop` = 7', $db->executedS[0]);
        self::assertStringContainsString('LIMIT 25', $db->executedS[0]);
    }

    public function testCountsOnlyThePendingEventsOfOneShop()
    {
        $db = new OutboxDbFake();
        $db->pendingCount = 4;

        self::assertSame(4, (new DbOutboxRepository($db))->countPending(7));
        self::assertCount(1, $db->getValueQueries);
        self::assertStringContainsString('COUNT(*)', $db->getValueQueries[0]);
        self::assertStringContainsString('`id_shop` = 7', $db->getValueQueries[0]);
        self::assertStringContainsString("`status` = 'pending'", $db->getValueQueries[0]);
    }

    public function testListsTheShopsWithDueEvents()
    {
        $db = new OutboxDbFake();
        $db->rows = array(array('id_shop' => '3'), array('id_shop' => '7'));

        self::assertSame(array(3, 7), (new DbOutboxRepository($db))->getDueShopIds(25, 1800000000));
        self::assertStringContainsString('GROUP BY `id_shop`', $db->executedS[0]);
        self::assertStringContainsString('ORDER BY MIN(`id_bemoliveshopping_outbox`) ASC', $db->executedS[0]);
    }
}

class OutboxDbFake
{
    public $insertCount = 0;
    public $insertedEventIds = array();
    public $executed = array();
    public $executedS = array();
    public $getValueQueries = array();
    public $pendingId = 0;
    public $pendingCount = 0;
    public $rows = array();
    public $updatedId;
    public $values;

    public function getValue($query)
    {
        $this->getValueQueries[] = $query;

        return strpos($query, 'COUNT(*)') === false ? $this->pendingId : $this->pendingCount;
    }

    public function executeS($query)
    {
        $this->executedS[] = $query;

        return $this->rows;
    }

    public function execute($query)
    {
        $this->executed[] = $query;

        return true;
    }

    public function insert($table, array $values)
    {
        ++$this->insertCount;
        $this->insertedEventIds[] = $values['event_id'];
        $this->values = $values;

        return true;
    }

    public function update($table, array $values, $where)
    {
        $this->values = $values;
        $this->updatedId = (int) preg_replace('/\D+/', '', $where);

        return true;
    }
}
