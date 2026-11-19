<?php
declare(strict_types=1);

/**
 * Số liệu cho bảng điều khiển theo vai trò. Mọi truy vấn đều giới hạn trong phạm vi
 * kho/điểm bán được phân quyền và tổng hợp bằng SQL (không nạp toàn bộ sổ phát sinh vào PHP).
 */
final class Dashboard
{
    /** @return array{0:string,1:array} mệnh đề "IN (...)" và tham số; phạm vi rỗng -> FALSE */
    private static function inList(string $column, array $ids, string $prefix): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return ['FALSE', []];
        $holders = []; $params = [];
        foreach ($ids as $i => $id) { $holders[] = ':' . $prefix . $i; $params[$prefix . $i] = $id; }
        return [$column . ' IN (' . implode(',', $holders) . ')', $params];
    }

    private static function rows(string $sql, array $params = []): array
    {
        $q = Database::connection()->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Số ngày kể từ một thời điểm (làm tròn xuống). */
    public static function ageDays(?string $at): int
    {
        if (!$at) return 0;
        try { return max(0, (int)(new DateTimeImmutable($at))->diff(new DateTimeImmutable())->days); }
        catch (Throwable) { return 0; }
    }

    /** Chứng từ người dùng có quyền duyệt ngay, cũ nhất trước. */
    public static function approvalQueue(array $user): array
    {
        $queue = array_values(array_filter(Store::all('vouchers'), fn($v) => canConfirm($user, $v)));
        usort($queue, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));
        return $queue;
    }

    /** Phiếu điều chuyển theo trạng thái workflow; $side = 'from' (kho đi) hoặc 'to' (kho đến). */
    public static function transfers(array $scope, string $state, string $side = 'from'): array
    {
        $column = $side === 'to' ? 'to_warehouse_id' : 'warehouse_id';
        return array_values(array_filter(Store::all('vouchers'), fn($v) => $v['type'] === 'transfer'
            && ($v['transfer_state'] ?? '') === $state && in_array($v[$column], $scope, true)));
    }

    /** Phiếu do chính người dùng lập theo trạng thái; rejected chỉ lấy trong $days ngày gần đây. */
    public static function myVouchers(array $user, string $status, int $days = 14): array
    {
        $mine = array_filter(Store::all('vouchers'), fn($v) => $v['created_by'] === $user['id'] && $v['status'] === $status
            && ($status !== 'rejected' || self::ageDays($v['rejected_at'] ?? $v['created_at']) <= $days));
        return array_reverse(array_values($mine));
    }

    /** Dòng tồn dưới mức tối thiểu theo từng kho (kho, mặt hàng, tồn, tối thiểu). */
    public static function lowStock(array $scope, int $limit = 10): array
    {
        [$in, $params] = self::inList('w.id', $scope, 'ls');
        return self::rows(
            "SELECT w.id AS warehouse_id, w.code AS warehouse_code, i.id AS item_id, i.sku, i.name, i.min_stock,
                    COALESCE(SUM(m.qty),0)::bigint AS balance
             FROM warehouses w CROSS JOIN items i
             LEFT JOIN stock_movements m ON m.warehouse_id=w.id AND m.item_id=i.id
             WHERE $in AND w.active AND i.active AND i.min_stock > 0
             GROUP BY w.id, w.code, i.id, i.sku, i.name, i.min_stock
             HAVING COALESCE(SUM(m.qty),0) < i.min_stock
             ORDER BY COALESCE(SUM(m.qty),0)::numeric / i.min_stock, w.code, i.sku
             LIMIT " . max(1, $limit), $params);
    }

    public static function lowStockCount(array $scope): int
    {
        [$in, $params] = self::inList('w.id', $scope, 'lc');
        $rows = self::rows(
            "SELECT count(*) AS n FROM (
                SELECT 1 FROM warehouses w CROSS JOIN items i
                LEFT JOIN stock_movements m ON m.warehouse_id=w.id AND m.item_id=i.id
                WHERE $in AND w.active AND i.active AND i.min_stock > 0
                GROUP BY w.id, i.id, i.min_stock HAVING COALESCE(SUM(m.qty),0) < i.min_stock) t", $params);
        return (int)($rows[0]['n'] ?? 0);
    }

    /** Tổng quan từng kho: tổng tồn, số mặt hàng còn hàng, số mặt hàng dưới mức. */
    public static function warehouseOverview(array $scope): array
    {
        [$in, $params] = self::inList('w.id', $scope, 'wo');
        return self::rows(
            "WITH bal AS (
                SELECT w.id AS warehouse_id, i.id AS item_id, i.min_stock, COALESCE(SUM(m.qty),0) AS qty
                FROM warehouses w CROSS JOIN items i
                LEFT JOIN stock_movements m ON m.warehouse_id=w.id AND m.item_id=i.id
                WHERE $in AND w.active AND i.active
                GROUP BY w.id, i.id, i.min_stock)
             SELECT w.id, w.code, w.name, w.type,
                    COALESCE(SUM(b.qty),0)::bigint AS total_qty,
                    count(*) FILTER (WHERE b.qty > 0) AS items_in_stock,
                    count(*) FILTER (WHERE b.min_stock > 0 AND b.qty < b.min_stock) AS low_items
             FROM warehouses w LEFT JOIN bal b ON b.warehouse_id=w.id
             WHERE $in AND w.active
             GROUP BY w.id, w.code, w.name, w.type
             ORDER BY (w.type='CENTRAL') DESC, w.code", $params);
    }

    /** Kiểm kê theo kho: đợt đang mở và lần chốt gần nhất. */
    public static function countOverview(array $scope): array
    {
        [$in, $params] = self::inList('w.id', $scope, 'co');
        return self::rows(
            "SELECT w.id, w.code, w.name,
                    (SELECT c.id FROM stock_counts c WHERE c.warehouse_id=w.id AND c.status='counting' ORDER BY c.id DESC LIMIT 1) AS open_count_id,
                    (SELECT c.code FROM stock_counts c WHERE c.warehouse_id=w.id AND c.status='counting' ORDER BY c.id DESC LIMIT 1) AS open_count_code,
                    (SELECT max(c.closed_at) FROM stock_counts c WHERE c.warehouse_id=w.id AND c.status='closed') AS last_closed_at
             FROM warehouses w WHERE $in AND w.active
             ORDER BY last_closed_at NULLS FIRST, w.code", $params);
    }

    /** Đơn phân phối theo kho hoặc điểm bán, kèm số lượng còn phải xuất. */
    public static function salesOrders(array $warehouseIds, array $pointIds, array $statuses, int $limit = 10): array
    {
        [$inW, $pw] = self::inList('o.warehouse_id', $warehouseIds, 'sw');
        [$inP, $pp] = self::inList('o.sales_point_id', $pointIds, 'sp');
        $statusHolders = []; $statusParams = [];
        foreach (array_values($statuses) as $i => $status) { $statusHolders[] = ':st' . $i; $statusParams['st' . $i] = $status; }
        $statusSql = $statusHolders ? 'o.status IN (' . implode(',', $statusHolders) . ')' : 'TRUE';
        return self::rows(
            "SELECT o.id, o.code, o.status, o.created_at, o.warehouse_id, p.name AS point_name, w.code AS warehouse_code,
                    COALESCE((SELECT SUM(l.requested_qty - l.allocated_qty) FROM sales_order_lines l WHERE l.order_id=o.id),0)::bigint AS remaining
             FROM sales_orders o JOIN sales_points p ON p.id=o.sales_point_id JOIN warehouses w ON w.id=o.warehouse_id
             WHERE ($inW OR $inP) AND $statusSql
             ORDER BY o.created_at
             LIMIT " . max(1, $limit), $pw + $pp + $statusParams);
    }

    /** Số đơn phân phối theo trạng thái. */
    public static function salesOrderCounts(array $warehouseIds, array $pointIds): array
    {
        [$inW, $pw] = self::inList('warehouse_id', $warehouseIds, 'cw');
        [$inP, $pp] = self::inList('sales_point_id', $pointIds, 'cp');
        $counts = [];
        foreach (self::rows("SELECT status, count(*) AS n FROM sales_orders WHERE $inW OR $inP GROUP BY status", $pw + $pp) as $r) {
            $counts[$r['status']] = (int)$r['n'];
        }
        return $counts;
    }

    /** Lần giao đến điểm bán đang chờ xác nhận nhận hàng. */
    public static function deliveriesAwaitingReceipt(array $pointIds): array
    {
        [$in, $params] = self::inList('o.sales_point_id', $pointIds, 'dp');
        return self::rows(
            "SELECT d.id, d.code, d.status, d.delivered_at, o.id AS order_id, o.code AS order_code, p.name AS point_name,
                    COALESCE((SELECT SUM(dl.shipped_qty) FROM sales_delivery_lines dl WHERE dl.delivery_id=d.id),0)::bigint AS shipped
             FROM sales_deliveries d JOIN sales_orders o ON o.id=d.order_id JOIN sales_points p ON p.id=o.sales_point_id
             WHERE $in AND d.status IN ('DELIVERING','PARTIALLY_RECEIVED')
             ORDER BY d.delivered_at", $params);
    }

    /** Tồn tại điểm bán, nhiều nhất trước. */
    public static function salesPointStock(array $pointIds, int $limit = 8): array
    {
        [$in, $params] = self::inList('m.sales_point_id', $pointIds, 'ps');
        return self::rows(
            "SELECT p.name AS point_name, i.sku, i.name, b.code AS batch_code, SUM(m.qty)::bigint AS qty
             FROM stock_movements m JOIN sales_points p ON p.id=m.sales_point_id
             JOIN items i ON i.id=m.item_id JOIN batches b ON b.id=m.batch_id
             WHERE $in GROUP BY p.name, i.sku, i.name, b.code HAVING SUM(m.qty) <> 0
             ORDER BY SUM(m.qty) DESC LIMIT " . max(1, $limit), $params);
    }

    /** Điểm bán do các kho trong phạm vi phụ trách. */
    public static function salesPointsOf(array $warehouseIds): array
    {
        [$in, $params] = self::inList('spw.warehouse_id', $warehouseIds, 'pw');
        return self::rows(
            "SELECT DISTINCT p.id, p.code, p.name, p.point_type, p.active
             FROM sales_points p JOIN sales_point_warehouses spw ON spw.sales_point_id=p.id
             WHERE $in ORDER BY p.code", $params);
    }

    /** Phát sinh gần đây trong phạm vi kho, kèm mã chứng từ. */
    public static function recentMovements(array $scope, int $limit = 8): array
    {
        [$in, $params] = self::inList('m.warehouse_id', $scope, 'rm');
        return self::rows(
            "SELECT m.occurred_at AS at, m.qty::bigint AS qty, w.code AS warehouse_code, i.name AS item_name,
                    v.id AS voucher_id, v.code AS voucher_code, v.type AS voucher_type
             FROM stock_movements m JOIN warehouses w ON w.id=m.warehouse_id JOIN items i ON i.id=m.item_id
             LEFT JOIN vouchers v ON v.id=m.voucher_id
             WHERE $in ORDER BY m.occurred_at DESC, m.id DESC LIMIT " . max(1, $limit), $params);
    }

    /** Tổng quan quản trị: tài khoản, danh mục, lô sắp hết hạn. */
    public static function adminOverview(): array
    {
        $db = Database::connection();
        $roles = [];
        foreach ($db->query('SELECT role, count(*) FILTER (WHERE active) AS active, count(*) FILTER (WHERE NOT active) AS locked FROM users GROUP BY role') as $r) {
            $roles[$r['role']] = ['active' => (int)$r['active'], 'locked' => (int)$r['locked']];
        }
        $one = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
        return [
            'roles' => $roles,
            'users_without_scope' => $one("SELECT count(*) FROM users u WHERE u.active AND u.role <> 'sales_user'
                AND NOT EXISTS (SELECT 1 FROM user_warehouses uw WHERE uw.user_id=u.id)"),
            'warehouses' => $one('SELECT count(*) FROM warehouses WHERE active'),
            'items' => $one('SELECT count(*) FROM items WHERE active'),
            'items_without_batch' => $one('SELECT count(*) FROM items i WHERE i.active AND NOT EXISTS (SELECT 1 FROM batches b WHERE b.item_id=i.id AND b.active)'),
            'suppliers' => $one('SELECT count(*) FROM suppliers WHERE active'),
            'sales_points' => $one('SELECT count(*) FROM sales_points WHERE active'),
            'expiring' => self::rows(
                "SELECT b.id, b.code, b.expiry, i.sku, i.name, COALESCE(SUM(m.qty),0)::bigint AS qty
                 FROM batches b JOIN items i ON i.id=b.item_id LEFT JOIN stock_movements m ON m.batch_id=b.id AND m.warehouse_id IS NOT NULL
                 WHERE b.active AND b.expiry IS NOT NULL AND b.expiry <= CURRENT_DATE + 30
                 GROUP BY b.id, b.code, b.expiry, i.sku, i.name HAVING COALESCE(SUM(m.qty),0) > 0
                 ORDER BY b.expiry LIMIT 8"),
        ];
    }

    /**
     * Tồn hiện có tại mọi kho và điểm bán đang hoạt động: số mặt hàng còn hàng, số lô còn hàng, tổng số lượng.
     * Tính từ sổ phát sinh (số dư theo lô); chỉ đếm lô có số dư dương.
     */
    public static function stockByLocation(): array
    {
        return self::rows(
            "WITH bal AS (
                SELECT 'warehouse' AS kind, warehouse_id AS location_id, item_id, batch_id, SUM(qty) AS qty
                FROM stock_movements WHERE warehouse_id IS NOT NULL GROUP BY warehouse_id, item_id, batch_id
                UNION ALL
                SELECT 'sales_point', sales_point_id, item_id, batch_id, SUM(qty)
                FROM stock_movements WHERE sales_point_id IS NOT NULL GROUP BY sales_point_id, item_id, batch_id
             ), loc AS (
                SELECT 'warehouse' AS kind, id, code, name, type AS subtype,
                       CASE WHEN type = 'CENTRAL' THEN 0 ELSE 1 END AS sort_group
                FROM warehouses WHERE active
                UNION ALL
                SELECT 'sales_point', id, code, name, point_type, 2 FROM sales_points WHERE active
             )
             SELECT loc.kind, loc.id, loc.code, loc.name, loc.subtype,
                    count(DISTINCT bal.item_id) FILTER (WHERE bal.qty > 0) AS items,
                    count(*) FILTER (WHERE bal.qty > 0) AS batches,
                    COALESCE(SUM(bal.qty) FILTER (WHERE bal.qty > 0), 0)::bigint AS qty
             FROM loc LEFT JOIN bal ON bal.kind = loc.kind AND bal.location_id = loc.id
             GROUP BY loc.kind, loc.id, loc.code, loc.name, loc.subtype, loc.sort_group
             ORDER BY loc.sort_group, loc.code");
    }

    /** Tổng số mặt hàng khác nhau còn hàng ở ít nhất một kho hoặc điểm bán. */
    public static function itemsInStockAnywhere(): int
    {
        return (int)Database::connection()->query(
            "SELECT count(DISTINCT item_id) FROM (
                SELECT item_id FROM stock_movements GROUP BY item_id, warehouse_id, sales_point_id, batch_id HAVING SUM(qty) > 0
             ) s")->fetchColumn();
    }

    /** Điểm bán liên kết với tài khoản điểm bán. */
    public static function userSalesPointIds(array $user): array
    {
        return array_map('intval', array_column(self::rows(
            'SELECT sales_point_id FROM user_sales_points WHERE user_id=:u', ['u' => $user['id']]), 'sales_point_id'));
    }
}
