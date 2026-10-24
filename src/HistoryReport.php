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
        $reconciliation = self::reconcileLatestCounts($filters, $scope, $all);
        return ['rows' => array_reverse($rows), 'opening' => $opening, 'closing' => $closing,
            'period_delta' => $closing - $opening, 'ledger_rows' => $period,
            'reconciliation' => $reconciliation];
    }

    private static function reconcileLatestCounts(array $filters, array $scope, array $movements): array
    {
        $asOf = $filters['to'] !== '' ? $filters['to'] : date('Y-m-d');
        $cutoff = new DateTimeImmutable($asOf . ' 23:59:59.999999');
        $latest = [];
        foreach (Store::all('stock_counts') as $count) {
            if ($count['status'] !== 'closed' || !in_array($count['warehouse_id'], $scope, true)
                || ($filters['warehouse_id'] !== null && $count['warehouse_id'] !== $filters['warehouse_id'])) continue;
            $closedAt = $count['closed_at'] ?? null;
            if (!is_string($closedAt) || $closedAt === '' || new DateTimeImmutable($closedAt) > $cutoff) continue;
            foreach ($count['lines'] as $line) {
                if ($filters['item_id'] !== null && $line['item_id'] !== $filters['item_id']) continue;
                if ($filters['batch_id'] !== null && $line['batch_id'] !== $filters['batch_id']) continue;
                if ($line['actual_qty'] === null) continue;
                $key=$count['warehouse_id'].':'.$line['batch_id'];
                if (!isset($latest[$key]) || new DateTimeImmutable($latest[$key]['closed_at']) < new DateTimeImmutable($closedAt)) {
                    $latest[$key]=['closed_at'=>$closedAt,'actual'=>(int)$line['actual_qty']];
                }
            }
        }
        $ledger=[]; $afterCount=[];
        foreach ($movements as $movement) {
            $date=substr($movement['at'],0,10);
            if ($date>$asOf) continue;
            $key=$movement['warehouse_id'].':'.$movement['batch_id'];
            $ledger[$key]=($ledger[$key]??0)+(int)$movement['qty'];
            if (isset($latest[$key]) && new DateTimeImmutable($movement['at'])>new DateTimeImmutable($latest[$key]['closed_at'])) {
                $afterCount[$key]=($afterCount[$key]??0)+(int)$movement['qty'];
            }
        }
        $mismatches=0; $absoluteDifference=0;
        foreach ($latest as $key=>$counted) {
            $expected=$counted['actual']+($afterCount[$key]??0);
            $difference=($ledger[$key]??0)-$expected;
            if ($difference!==0) { $mismatches++; $absoluteDifference+=abs($difference); }
        }
        return ['checked'=>count($latest),'mismatches'=>$mismatches,'absolute_difference'=>$absoluteDifference,'as_of'=>$asOf];
    }

    public static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
