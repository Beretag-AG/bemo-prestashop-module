<?php

namespace Bemo\LiveShopping\Tests\Webhook;

use Bemo\LiveShopping\Webhook\DbOutboxRepository;
use PHPUnit\Framework\TestCase;

class DbOutboxRepositoryTest extends TestCase
{
    public function testPassesExactRawPayloadToPrestashopDatabaseEscaping()
    {
        $db = new OutboxDbFake();
        $rawPayload = '{"shopUrl":"https://shop.example","title":"A \\"quoted\\" item"}';

        self::assertTrue((new DbOutboxRepository($db))->enqueue(
            7,
            'prestashop:abcdefghijklmnopqrstuv',
            $rawPayload,
            1800000000
        ));

        self::assertSame($rawPayload, $db->values['payload']);
        self::assertStringNotContainsString('LIMIT', $db->getValueQuery);
    }

    public function testReplacesThePendingEventForTheSameShop()
    {
        $db = new OutboxDbFake();
        $db->pendingId = 12;

        self::assertTrue((new DbOutboxRepository($db))->enqueue(
            7,
            'prestashop:latesteventidentifier',
            '{"hook":"product.updated"}',
            1800000001
        ));

        self::assertSame(0, $db->insertCount);
        self::assertSame(12, $db->updatedId);
        self::assertSame('prestashop:latesteventidentifier', $db->values['event_id']);
        self::assertArrayNotHasKey('date_add', $db->values);
    }
}

class OutboxDbFake
{
    public $insertCount = 0;
    public $getValueQuery;
    public $pendingId = 0;
    public $updatedId;
    public $values;

    public function getValue($query)
    {
        $this->getValueQuery = $query;

        return $this->pendingId;
    }

    public function insert($table, array $values)
    {
        ++$this->insertCount;
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
