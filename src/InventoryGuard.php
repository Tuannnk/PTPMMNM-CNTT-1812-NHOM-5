<?php
declare(strict_types=1);

/** Serializes inventory writes for a warehouse using one shared advisory lock. */
final class InventoryGuard
{
    public static function lockWarehouses(PDO $db, array $warehouseIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $warehouseIds)));
        sort($ids, SORT_NUMERIC);
        $lock = $db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))');
        foreach ($ids as $id) {
            $lock->execute(['lock_key' => 'inventory-warehouse:' . $id]);
        }
    }
}
