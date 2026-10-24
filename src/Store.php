<?php
declare(strict_types=1);
// Đọc dữ liệu PostgreSQL theo các cấu trúc đang dùng trong giao diện.
require_once __DIR__ . '/Database.php';

final class Store
{
    private const TABLES = [
        'warehouses', 'users', 'items', 'units', 'batches', 'vouchers',
        'voucher_lines', 'stock_movements', 'stock_counts',
    ];

    private static array $cache = [];

    public static function all(string $table): array
    {
        if (!in_array($table, self::TABLES, true)) {
            throw new InvalidArgumentException('Bảng không được hỗ trợ: ' . $table);
        }
        if (array_key_exists($table, self::$cache)) {
            return self::$cache[$table];
        }

        $db = Database::connection();
        $sql = $table === 'vouchers'
            ? 'SELECT v.*, tw.state AS transfer_state, tw.cancel_reason AS transfer_cancel_reason,
                      tw.checked_by AS transfer_checked_by, tw.received_by AS transfer_received_by,
                      tw.dispatched_at AS transfer_dispatched_at, tw.received_at AS transfer_received_at
               FROM vouchers v LEFT JOIN transfer_workflow tw ON tw.voucher_id = v.id ORDER BY v.id'
            : 'SELECT * FROM ' . $table . ' ORDER BY id';
        $rows = $db->query($sql)->fetchAll();
        $rows = array_map([self::class, 'normalize'], $rows);

        if ($table === 'users') {
            $byId = array_column($rows, null, 'id');
            foreach ($byId as &$user) {
                $user['warehouses'] = [];
            }
            unset($user);
            $memberships = $db->query('SELECT user_id, warehouse_id FROM user_warehouses ORDER BY user_id, warehouse_id');
            foreach ($memberships as $membership) {
                $userId = (int)$membership['user_id'];
                if (isset($byId[$userId])) {
                    $byId[$userId]['warehouses'][] = (int)$membership['warehouse_id'];
                }
            }
            $rows = array_values($byId);
        } elseif ($table === 'items') {
            $unitNames = array_column(self::all('units'), 'name', 'code');
            foreach ($rows as &$item) {
                $item['unit_name'] = $unitNames[$item['unit']] ?? $item['unit'];
            }
            unset($item);
        } elseif ($table === 'stock_counts') {
            $lines = $db->query('SELECT * FROM stock_count_lines ORDER BY id')->fetchAll();
            $byCount = [];
            foreach ($lines as $line) {
                $line = self::normalize($line);
                $byCount[$line['count_id']][] = $line;
            }
            foreach ($rows as &$count) {
                $count['lines'] = $byCount[$count['id']] ?? [];
            }
            unset($count);
        }

        return self::$cache[$table] = $rows;
    }

    public static function find(string $table, int $id): ?array
    {
        foreach (self::all($table) as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<int,array> chỉ mục theo id */
    public static function indexed(string $table): array
    {
        return array_column(self::all($table), null, 'id');
    }

    public static function linesOf(int $voucherId): array
    {
        return array_values(array_filter(
            self::all('voucher_lines'),
            fn($line) => $line['voucher_id'] === $voucherId
        ));
    }

    public static function batchesOf(int $itemId): array
    {
        return array_values(array_filter(
            self::all('batches'),
            fn($batch) => $batch['item_id'] === $itemId
        ));
    }

    public static function movementsOf(int $voucherId): array
    {
        return array_values(array_filter(
            self::all('stock_movements'),
            fn($movement) => $movement['voucher_id'] === $voucherId
        ));
    }

    private static function normalize(array $row): array
    {
        foreach (['id', 'user_id', 'warehouse_id', 'item_id', 'batch_id', 'voucher_id', 'count_id',
            'created_by', 'confirmed_by', 'to_warehouse_id', 'ref_id', 'adjust_voucher_id'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        foreach (['qty', 'min_stock', 'system_qty', 'actual_qty'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                $row[$key] = (int)$row[$key];
            }
        }
        if (isset($row['full_name'])) {
            $row['name'] = $row['full_name'];
            unset($row['full_name']);
        }
        if (isset($row['occurred_at'])) {
            $row['at'] = $row['occurred_at'];
            unset($row['occurred_at']);
        }
        if (array_key_exists('active', $row)) {
            $row['active'] = in_array($row['active'], [true, 1, '1', 't', 'true'], true);
        }
        unset($row['password_hash']);
        return $row;
    }
}
