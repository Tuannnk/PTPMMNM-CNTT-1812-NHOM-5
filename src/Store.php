<?php
declare(strict_types=1);
// Lớp truy cập dữ liệu. Giai đoạn giao diện đọc từ data/seed.php;
// giai đoạn sau thay phần thân bằng truy vấn PostgreSQL/MySQL, giữ nguyên tên phương thức.

final class Store
{
    private static ?array $data = null;

    private static function data(): array
    {
        return self::$data ??= require __DIR__ . '/../data/seed.php';
    }

    public static function all(string $table): array
    {
        return self::data()[$table];
    }

    public static function find(string $table, int $id): ?array
    {
        foreach (self::data()[$table] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<int,array> chỉ mục theo id */
    public static function indexed(string $table): array
    {
        return array_column(self::data()[$table], null, 'id');
    }

    public static function linesOf(int $voucherId): array
    {
        return array_values(array_filter(
            self::data()['voucher_lines'],
            fn($line) => $line['voucher_id'] === $voucherId
        ));
    }

    public static function batchesOf(int $itemId): array
    {
        return array_values(array_filter(
            self::data()['batches'],
            fn($batch) => $batch['item_id'] === $itemId
        ));
    }

    public static function movementsOf(int $voucherId): array
    {
        return array_values(array_filter(
            self::data()['stock_movements'],
            fn($m) => $m['voucher_id'] === $voucherId
        ));
    }
}
