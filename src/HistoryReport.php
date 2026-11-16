<?php
declare(strict_types=1);

/** SQL-backed history queries; paged rows avoid loading the complete stock ledger in PHP. */
final class HistoryReport
{
    public const PAGE_SIZE = 100;

    public static function build(array $filters, array $scope, int $page = 1): array
    {
        $page = max(1, $page);
        [$where, $params] = self::movementFilter($filters, $scope, 'm');
        $db = Database::connection();

        $openingExpression = $filters['from'] === '' ? '0' : 'COALESCE(SUM(qty) FILTER (WHERE occurred_at::date < CAST(:from_compare AS date)),0)';
        $closingExpression = $filters['to'] === '' ? 'COALESCE(SUM(qty),0)' : 'COALESCE(SUM(qty) FILTER (WHERE occurred_at::date <= CAST(:to_compare AS date)),0)';
        $summary = $db->prepare("SELECT $openingExpression AS opening,$closingExpression AS closing FROM stock_movements m WHERE $where");
        $summaryParams = $params;
        if ($filters['from'] !== '') $summaryParams['from_compare'] = $filters['from'];
        if ($filters['to'] !== '') $summaryParams['to_compare'] = $filters['to'];
        $summary->execute($summaryParams);
        $totals = $summary->fetch();
        $opening = (int)$totals['opening'];
        $closing = (int)$totals['closing'];

        $rowConditions = [];
        $rowParams = $params;
        if ($filters['from'] !== '') {
            $rowConditions[] = 'occurred_at::date >= CAST(:row_from AS date)';
            $rowParams['row_from'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $rowConditions[] = 'occurred_at::date <= CAST(:row_to AS date)';
            $rowParams['row_to'] = $filters['to'];
        }
        if ($filters['type'] !== '') {
            $rowConditions[] = 'voucher_type = :row_type';
            $rowParams['row_type'] = $filters['type'];
        }
        $rowWhere = $rowConditions ? 'WHERE ' . implode(' AND ', $rowConditions) : '';
        $count = $db->prepare("SELECT count(*) FROM (
            SELECT m.id,m.occurred_at,v.type AS voucher_type FROM stock_movements m
            JOIN vouchers v ON v.id=m.voucher_id WHERE $where
        ) scoped $rowWhere");
        $count->execute($rowParams);
        $totalRows = (int)$count->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / self::PAGE_SIZE));
        $page = min($page, $totalPages);
        $rows = iterator_to_array(self::rows($filters, $scope, self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE), false);

        return [
            'rows' => $rows,
            'opening' => $opening,
            'closing' => $closing,
            'period_delta' => $closing - $opening,
            'ledger_rows' => $rows,
            'total_rows' => $totalRows,
            'page' => $page,
            'page_size' => self::PAGE_SIZE,
            'total_pages' => $totalPages,
            'reconciliation' => self::reconcileLatestCounts($filters, $scope),
        ];
    }

    /** Yield rows incrementally for CSV output; null limit streams the full result set. */
    public static function rows(array $filters, array $scope, ?int $limit = self::PAGE_SIZE, int $offset = 0): Generator
    {
        [$where, $params] = self::movementFilter($filters, $scope, 'm');
        $conditions = [];
        if ($filters['from'] !== '') {
            $conditions[] = 'occurred_at::date >= CAST(:from_date AS date)';
            $params['from_date'] = $filters['from'];
        }
        if ($filters['to'] !== '') {
            $conditions[] = 'occurred_at::date <= CAST(:to_date AS date)';
            $params['to_date'] = $filters['to'];
        }
        if ($filters['type'] !== '') {
            $conditions[] = 'voucher_type = :voucher_type';
            $params['voucher_type'] = $filters['type'];
        }
        $outer = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT id,voucher_id,voucher_code,voucher_type,warehouse_id,item_id,batch_id,qty,occurred_at AS at,running FROM (
            SELECT m.id,m.voucher_id,v.code AS voucher_code,v.type AS voucher_type,m.warehouse_id,m.item_id,m.batch_id,m.qty,m.occurred_at,
                SUM(m.qty) OVER (PARTITION BY m.warehouse_id,m.batch_id ORDER BY m.occurred_at,m.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS running
            FROM stock_movements m JOIN vouchers v ON v.id=m.voucher_id WHERE $where
        ) scoped $outer ORDER BY at DESC,id DESC";
        if ($limit !== null) {
            $sql .= ' LIMIT :row_limit OFFSET :row_offset';
            $params['row_limit'] = max(1, $limit);
            $params['row_offset'] = max(0, $offset);
        }
        $statement = Database::connection()->prepare($sql);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            foreach (['id','voucher_id','warehouse_id','item_id','batch_id','qty','running'] as $key) {
                if ($row[$key] !== null) $row[$key] = (int)$row[$key];
            }
            yield $row;
        }
    }

    private static function movementFilter(array $filters, array $scope, string $alias): array
    {
        $conditions = [];
        $params = [];
        $ids = array_values(array_unique(array_map('intval', $scope)));
        if (!$ids) return ['FALSE', []];
        $holders = [];
        foreach ($ids as $i => $id) {
            $key = 'scope_' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $conditions[] = "$alias.warehouse_id IN (" . implode(',', $holders) . ')';
        foreach (['warehouse_id' => 'warehouse', 'item_id' => 'item', 'batch_id' => 'batch'] as $filter => $prefix) {
            if ($filters[$filter] !== null) {
                $key = 'filter_' . $prefix;
                $conditions[] = "$alias.$filter=:$key";
                $params[$key] = $filters[$filter];
            }
        }
        return [implode(' AND ', $conditions), $params];
    }

    private static function reconcileLatestCounts(array $filters, array $scope): array
    {
        $asOf = $filters['to'] !== '' ? $filters['to'] : date('Y-m-d');
        $ids = array_values(array_unique(array_map('intval', $scope)));
        if (!$ids) return ['checked'=>0,'mismatches'=>0,'absolute_difference'=>0,'as_of'=>$asOf];
        $holders = [];
        $params = ['cutoff' => $asOf, 'end_exclusive' => $asOf];
        foreach ($ids as $i => $id) {
            $key = 'count_scope_' . $i;
            $holders[] = ':' . $key;
            $params[$key] = $id;
        }
        $conditions = ['c.status=\'closed\'', 'c.warehouse_id IN (' . implode(',', $holders) . ')', 'cl.actual_qty IS NOT NULL',
            'c.closed_at < CAST(:cutoff AS date) + INTERVAL \'1 day\''];
        if ($filters['warehouse_id'] !== null) { $conditions[]='c.warehouse_id=:count_warehouse'; $params['count_warehouse']=$filters['warehouse_id']; }
        if ($filters['item_id'] !== null) { $conditions[]='cl.item_id=:count_item'; $params['count_item']=$filters['item_id']; }
        if ($filters['batch_id'] !== null) { $conditions[]='cl.batch_id=:count_batch'; $params['count_batch']=$filters['batch_id']; }
        $sql = "WITH latest AS (
            SELECT DISTINCT ON (c.warehouse_id,cl.batch_id) c.warehouse_id,cl.batch_id,cl.actual_qty,c.closed_at
            FROM stock_counts c JOIN stock_count_lines cl ON cl.count_id=c.id
            WHERE " . implode(' AND ', $conditions) . "
            ORDER BY c.warehouse_id,cl.batch_id,c.closed_at DESC,c.id DESC
        ), comparison AS (
            SELECT l.warehouse_id,l.batch_id,l.actual_qty,
                COALESCE(SUM(m.qty),0) AS ledger_balance,
                COALESCE(SUM(m.qty) FILTER (WHERE m.occurred_at>l.closed_at),0) AS after_count
            FROM latest l LEFT JOIN stock_movements m
                ON m.warehouse_id=l.warehouse_id AND m.batch_id=l.batch_id
                AND m.occurred_at < CAST(:end_exclusive AS date) + INTERVAL '1 day'
            GROUP BY l.warehouse_id,l.batch_id,l.actual_qty
        )
        SELECT count(*) AS checked,
            count(*) FILTER (WHERE ledger_balance-(actual_qty+after_count)<>0) AS mismatches,
            COALESCE(sum(abs(ledger_balance-(actual_qty+after_count))),0) AS absolute_difference
        FROM comparison";
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        $result = $statement->fetch();
        return ['checked'=>(int)$result['checked'],'mismatches'=>(int)$result['mismatches'],
            'absolute_difference'=>(int)$result['absolute_difference'],'as_of'=>$asOf];
    }

    public static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
