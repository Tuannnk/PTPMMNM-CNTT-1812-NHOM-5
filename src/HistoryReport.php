<?php
declare(strict_types=1);

/** Shared ledger filtering and period balances for the history page and CSV. */
final class HistoryReport
{
    public static function build(array $filters, array $scope): array
    {
        $all = array_values(array_filter(Store::all('stock_movements'), static function ($m) use ($filters, $scope) {
            return in_array($m['warehouse_id'], $scope, true)
                && ($filters['warehouse_id'] === null || $m['warehouse_id'] === $filters['warehouse_id'])
                && ($filters['item_id'] === null || $m['item_id'] === $filters['item_id'])
                && ($filters['batch_id'] === null || $m['batch_id'] === $filters['batch_id']);
        }));
        usort($all, static fn($a, $b) => strcmp($a['at'], $b['at']) ?: ($a['id'] <=> $b['id']));
        $voucherTypes = array_column(Store::all('vouchers'), 'type', 'id');

        $balances = [];
        $opening = 0;
        $closing = 0;
        foreach ($all as &$m) {
            $key = $m['warehouse_id'] . ':' . $m['batch_id'];
            $at = substr($m['at'], 0, 10);
            if ($filters['from'] !== '' && $at < $filters['from']) {
                $opening += $m['qty'];
            }
            $balances[$key] = ($balances[$key] ?? 0) + $m['qty'];
            if ($filters['to'] === '' || $at <= $filters['to']) {
                $closing += $m['qty'];
            }
            $m['running'] = $balances[$key];
        }
        unset($m);

        $period = array_values(array_filter($all, static function ($m) use ($filters) {
            $day = substr($m['at'], 0, 10);
            return ($filters['from'] === '' || $day >= $filters['from'])
                && ($filters['to'] === '' || $day <= $filters['to']);
        }));
        $rows = array_values(array_filter($period, static function ($m) use ($filters, $voucherTypes) {
            return $filters['type'] === '' || ($voucherTypes[$m['voucher_id']] ?? '') === $filters['type'];
        }));
        return ['rows' => array_reverse($rows), 'opening' => $opening, 'closing' => $closing,
            'period_delta' => $closing - $opening, 'ledger_rows' => $period];
    }

    public static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
