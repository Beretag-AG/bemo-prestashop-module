<?php

namespace Bemo\LiveShopping\Webhook;

if (!defined('_PS_VERSION_')) {
    exit;
}

class DbOutboxRepository implements OutboxRepositoryInterface
{
    const MAX_PENDING_PER_SHOP = 500;

    /** @var \Db */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function enqueue($shopId, $eventId, $resourceKey, $rawPayload, $availableAt)
    {
        $now = date('Y-m-d H:i:s');
        $values = array(
            'id_shop' => (int) $shopId,
            'event_id' => pSQL($eventId),
            'resource_key' => pSQL($resourceKey),
            // The stored bytes are the exact bytes signed at delivery time, so the
            // payload is only SQL-escaped and never passed through the HTML
            // filtering pSQL applies by default.
            'payload' => pSQL($rawPayload, true),
            'attempts' => 0,
            'status' => 'pending',
            'available_at' => pSQL(date('Y-m-d H:i:s', (int) $availableAt)),
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        );

        $pendingId = (int) $this->db->getValue(
            'SELECT `id_bemoliveshopping_outbox`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . " AND `status` = 'pending'"
            . " AND `resource_key` = '" . pSQL($resourceKey) . "'"
            . ' ORDER BY `id_bemoliveshopping_outbox` ASC'
        );
        if ($pendingId > 0) {
            unset($values['date_add']);

            return (bool) $this->db->update(
                'bemoliveshopping_outbox',
                $values,
                '`id_bemoliveshopping_outbox` = ' . $pendingId
            );
        }

        $this->purgePendingOverflow($shopId);

        return (bool) $this->db->insert('bemoliveshopping_outbox', $values);
    }

    public function getDueShopIds($limit, $now)
    {
        // Oldest waiting event first, so a busy shop cannot starve the others
        // when more shops have due events than one drain may visit.
        $rows = $this->db->executeS(
            'SELECT `id_shop`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . " WHERE `status` = 'pending'"
            . " AND `available_at` <= '" . pSQL(date('Y-m-d H:i:s', (int) $now)) . "'"
            . ' GROUP BY `id_shop`'
            . ' ORDER BY MIN(`id_bemoliveshopping_outbox`) ASC'
            . ' LIMIT ' . $this->boundedLimit($limit)
        );

        if (!is_array($rows)) {
            return array();
        }

        return array_map(function ($row) {
            return (int) $row['id_shop'];
        }, $rows);
    }

    public function getDueForShop($shopId, $limit, $now)
    {
        $rows = $this->db->executeS(
            'SELECT `id_bemoliveshopping_outbox`, `id_shop`, `payload`, `attempts`'
            . ' FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . " AND `status` = 'pending'"
            . " AND `available_at` <= '" . pSQL(date('Y-m-d H:i:s', (int) $now)) . "'"
            . ' ORDER BY `id_bemoliveshopping_outbox` ASC'
            . ' LIMIT ' . $this->boundedLimit($limit)
        );

        return is_array($rows) ? $rows : array();
    }

    public function delete($outboxId)
    {
        return (bool) $this->db->delete(
            'bemoliveshopping_outbox',
            '`id_bemoliveshopping_outbox` = ' . (int) $outboxId
        );
    }

    public function markFailure($outboxId, $attempts, $availableAt, $terminal)
    {
        return (bool) $this->db->update(
            'bemoliveshopping_outbox',
            array(
                'attempts' => (int) $attempts,
                'status' => $terminal ? 'dead_letter' : 'pending',
                'available_at' => pSQL(date('Y-m-d H:i:s', (int) $availableAt)),
                'date_upd' => pSQL(date('Y-m-d H:i:s')),
            ),
            '`id_bemoliveshopping_outbox` = ' . (int) $outboxId
        );
    }

    public function purgeTerminalBefore($timestamp)
    {
        return (bool) $this->db->delete(
            'bemoliveshopping_outbox',
            "`status` = 'dead_letter' AND `date_upd` < '"
            . pSQL(date('Y-m-d H:i:s', (int) $timestamp)) . "'"
        );
    }

    private function purgePendingOverflow($shopId)
    {
        $pending = (int) $this->db->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . " AND `status` = 'pending'"
        );
        if ($pending < self::MAX_PENDING_PER_SHOP) {
            return;
        }

        $excess = $pending - self::MAX_PENDING_PER_SHOP + 1;
        $purged = (bool) $this->db->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'bemoliveshopping_outbox`'
            . ' WHERE `id_shop` = ' . (int) $shopId
            . " AND `status` = 'pending'"
            . ' ORDER BY `id_bemoliveshopping_outbox` ASC'
            . ' LIMIT ' . (int) $excess
        );
        if ($purged) {
            \PrestaShopLogger::addLog(
                'BEMO webhook outbox dropped ' . (int) $excess . ' undelivered events for shop '
                . (int) $shopId . ' because the queue reached its limit. Check that the outbox is drained.',
                2
            );
        }
    }

    private function boundedLimit($limit)
    {
        return max(1, min(100, (int) $limit));
    }
}
