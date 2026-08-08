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
    }
}

class OutboxDbFake
{
    public $values;

    public function insert($table, array $values)
    {
        $this->values = $values;

        return true;
    }
}
