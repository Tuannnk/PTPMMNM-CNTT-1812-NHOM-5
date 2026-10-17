<?php
declare(strict_types=1);
// Quy tắc tồn kho thuần: không echo, không HTML, không Session, không CSDL.
// Số tồn luôn được tính lại từ sổ phát sinh (stock_movements), không lưu riêng.

final class StockLedger
{
    /** @return array<string,int> khóa "kho:lô" => số tồn */
    public static function balances(array $movements): array
    {
        $result = [];
        foreach ($movements as $m) {
            $key = $m['warehouse_id'] . ':' . $m['batch_id'];
            $result[$key] = ($result[$key] ?? 0) + (int)$m['qty'];
        }
        return $result;
    }

    public static function balanceOf(array $balances, int $warehouseId, int $batchId): int
    {
        return $balances[$warehouseId . ':' . $batchId] ?? 0;
    }

    /** Tổng tồn theo mặt hàng trong một kho (null = mọi kho). */
    public static function byItem(array $movements, ?int $warehouseId = null): array
    {
        $result = [];
        foreach ($movements as $m) {
            if ($warehouseId !== null && $m['warehouse_id'] !== $warehouseId) {
                continue;
            }
            $result[$m['item_id']] = ($result[$m['item_id']] ?? 0) + (int)$m['qty'];
        }
        return $result;
    }

    /**
     * Phát sinh mà một chứng từ sẽ tạo khi được xác nhận.
     * Điều chuyển sinh hai dòng (trừ kho đi, cộng kho đến) — phải ghi trong cùng giao dịch.
     */
    public static function movementsFor(array $voucher, array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $qty = (int)$line['qty'];
            $pairs = match ($voucher['type']) {
                'in' => [[$voucher['warehouse_id'], $qty]],
                'out' => [[$voucher['warehouse_id'], -$qty]],
                'transfer' => [[$voucher['warehouse_id'], -$qty], [$voucher['to_warehouse_id'], $qty]],
                'adjust' => [[$voucher['warehouse_id'], $qty]],
                default => [],
            };
            foreach ($pairs as [$warehouseId, $signed]) {
                $result[] = [
                    'warehouse_id' => (int)$warehouseId,
                    'item_id' => (int)$line['item_id'],
                    'batch_id' => (int)$line['batch_id'],
                    'qty' => $signed,
                ];
            }
        }
        return $result;
    }

    /** Danh sách "kho:lô" sẽ bị âm nếu áp các phát sinh mới. Rỗng = hợp lệ. */
    public static function negativeAfter(array $balances, array $newMovements): array
    {
        $after = $balances;
        foreach ($newMovements as $m) {
            $key = $m['warehouse_id'] . ':' . $m['batch_id'];
            $after[$key] = ($after[$key] ?? 0) + $m['qty'];
        }
        $negative = [];
        foreach ($newMovements as $m) {
            $key = $m['warehouse_id'] . ':' . $m['batch_id'];
            if ($after[$key] < 0) {
                $negative[$key] = $after[$key];
            }
        }
        return $negative;
    }
}
