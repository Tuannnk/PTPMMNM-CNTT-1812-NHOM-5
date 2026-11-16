<?php
declare(strict_types=1);

final class SalesOrderService
{
    public static function createDraft(array $actor, int $pointId, int $warehouseId, array $lines, string $requestKey, string $note = ''): int
    {
        if ($actor['role'] === 'sales_user') {
            if (!self::userPoint((int)$actor['id'], $pointId)) {
                throw new DomainException('SALES_POINT_FORBIDDEN');
            }
        } elseif (!canAccessWarehouse($actor, $warehouseId)) {
            throw new DomainException('WAREHOUSE_FORBIDDEN');
        }
        if (!$lines) {
            throw new DomainException('LINES_EMPTY');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $point = self::point($db, $pointId);
            if (!$point || !$point['active']) {
                throw new DomainException('SALES_POINT_INVALID');
            }
            if ($actor['role'] === 'sales_user') {
                $warehouseId = (int)$point['primary_warehouse_id'];
            } else {
                $route = $db->prepare('SELECT 1 FROM sales_point_warehouses WHERE sales_point_id=:point AND warehouse_id=:warehouse');
                $route->execute(['point' => $pointId, 'warehouse' => $warehouseId]);
                if (!$route->fetchColumn()) throw new DomainException('SALES_POINT_WAREHOUSE_MISMATCH');
            }

            $q = $db->prepare("INSERT INTO sales_orders (code,sales_point_id,warehouse_id,status,created_by,note,request_key)
                VALUES ('SO-'||to_char(clock_timestamp(),'YYYYMMDDHH24MISSMS')||'-'||nextval('sales_orders_id_seq'),:point,:warehouse,'PENDING',:user,:note,:key)
                ON CONFLICT(request_key) DO NOTHING RETURNING id");
            $q->execute(['point' => $pointId, 'warehouse' => $warehouseId, 'user' => $actor['id'], 'note' => $note, 'key' => $requestKey]);
            $id = $q->fetchColumn();
            if ($id === false) {
                $q = $db->prepare('SELECT id FROM sales_orders WHERE request_key=:key');
                $q->execute(['key' => $requestKey]);
                $id = $q->fetchColumn();
                $db->commit();
                return (int)$id;
            }

            $insert = $db->prepare('INSERT INTO sales_order_lines (order_id,item_id,batch_id,requested_qty) VALUES (:order,:item,:batch,:qty)');
            $seen = [];
            foreach ($lines as $line) {
                $batch = (int)$line['batch_id'];
                $qty = (int)$line['qty'];
                $item = (int)$line['item_id'];
                if ($qty <= 0 || isset($seen[$batch])) {
                    throw new DomainException('LINE_INVALID');
                }
                $seen[$batch] = true;
                $insert->execute(['order' => $id, 'item' => $item, 'batch' => $batch, 'qty' => $qty]);
            }
            $db->commit();
            return (int)$id;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function approve(array $actor, int $orderId): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM sales_orders WHERE id=:id FOR UPDATE');
            $q->execute(['id' => $orderId]);
            $order = $q->fetch();
            if (!$order || !canAccessWarehouse($actor, (int)$order['warehouse_id'])
                || !in_array($actor['role'], ['approver', 'admin', 'central_manager', 'regional_manager'], true)) {
                throw new DomainException('ORDER_APPROVE_FORBIDDEN');
            }
            if ($order['status'] !== 'PENDING') throw new DomainException('ORDER_STATE_INVALID');
            $update = $db->prepare("UPDATE sales_orders SET status='APPROVED',approved_by=:user,approved_at=now() WHERE id=:id AND status='PENDING'");
            $update->execute(['user' => $actor['id'], 'id' => $orderId]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function dispatch(array $actor, int $orderId, array $lines, string $requestKey): int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM sales_orders WHERE id=:id FOR UPDATE');
            $q->execute(['id' => $orderId]);
            $order = $q->fetch();
            if (!$order || !canAccessWarehouse($actor, (int)$order['warehouse_id'])
                || !in_array($actor['role'], ['staff', 'regional_manager', 'admin'], true)) {
                throw new DomainException('ORDER_DISPATCH_FORBIDDEN');
            }
            if (!in_array($order['status'], ['APPROVED', 'DELIVERING', 'PARTIALLY_RECEIVED'], true)) {
                throw new DomainException('ORDER_STATE_INVALID');
            }

            $existing = $db->prepare('SELECT id FROM sales_deliveries WHERE request_key=:key');
            $existing->execute(['key' => $requestKey]);
            $found = $existing->fetchColumn();
            if ($found !== false) {
                $db->commit();
                return (int)$found;
            }

            InventoryGuard::lockWarehouses($db, [(int)$order['warehouse_id']]);
            $lineQuery = $db->prepare('SELECT * FROM sales_order_lines WHERE order_id=:order ORDER BY id FOR UPDATE');
            $lineQuery->execute(['order' => $orderId]);
            $orderLines = $lineQuery->fetchAll();
            $byBatch = [];
            foreach ($orderLines as $orderLine) $byBatch[(int)$orderLine['batch_id']] = $orderLine;

            $normalized = [];
            $seen = [];
            $balance = $db->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE warehouse_id=:warehouse AND batch_id=:batch');
            foreach ($lines as $line) {
                $batch = (int)($line['batch_id'] ?? 0);
                $qty = (int)($line['qty'] ?? 0);
                if (!isset($byBatch[$batch]) || $qty <= 0 || isset($seen[$batch])) {
                    throw new DomainException('LINE_INVALID');
                }
                $seen[$batch] = true;
                $orderLine = $byBatch[$batch];
                if ($qty > (int)$orderLine['requested_qty'] - (int)$orderLine['allocated_qty']) {
                    throw new DomainException('ORDER_QTY_EXCEEDED');
                }
                $balance->execute(['warehouse' => $order['warehouse_id'], 'batch' => $batch]);
                if ((float)$balance->fetchColumn() < $qty) throw new DomainException('STOCK_NEGATIVE');
                $normalized[] = ['order_line' => $orderLine, 'qty' => $qty];
            }
            if (!$normalized) throw new DomainException('LINES_EMPTY');

            $code = 'DL-' . date('YmdHis') . '-' . bin2hex(random_bytes(3));
            $insertDelivery = $db->prepare("INSERT INTO sales_deliveries(code,order_id,status,delivered_by,note,request_key) VALUES(:code,:order,'DELIVERING',:user,'',:key) RETURNING id");
            $insertDelivery->execute(['code' => $code, 'order' => $orderId, 'user' => $actor['id'], 'key' => $requestKey]);
            $deliveryId = (int)$insertDelivery->fetchColumn();
            $insertVoucher = $db->prepare("INSERT INTO vouchers(code,type,status,warehouse_id,created_by,confirmed_by,confirmed_at,note,request_key) VALUES(:code,'sale','confirmed',:warehouse,:user,:user,now(),:note,:key) RETURNING id");
            $insertVoucher->execute(['code' => $code, 'warehouse' => $order['warehouse_id'], 'user' => $actor['id'], 'note' => 'Phân phối ' . $code, 'key' => 'sale-' . $code]);
            $voucherId = (int)$insertVoucher->fetchColumn();

            $voucherLine = $db->prepare('INSERT INTO voucher_lines(voucher_id,item_id,batch_id,qty) VALUES(:voucher,:item,:batch,:qty)');
            $movement = $db->prepare("INSERT INTO stock_movements(voucher_id,warehouse_id,item_id,batch_id,qty,sales_delivery_id,ownership) VALUES(:voucher,:warehouse,:item,:batch,:qty,:delivery,'COMPANY_OWNED')");
            $deliveryLine = $db->prepare('INSERT INTO sales_delivery_lines(delivery_id,order_line_id,batch_id,shipped_qty) VALUES(:delivery,:line,:batch,:qty)');
            $allocation = $db->prepare('UPDATE sales_order_lines SET allocated_qty=allocated_qty+:qty WHERE id=:id');
            foreach ($normalized as $entry) {
                $orderLine = $entry['order_line'];
                $qty = $entry['qty'];
                $voucherLine->execute(['voucher' => $voucherId, 'item' => $orderLine['item_id'], 'batch' => $orderLine['batch_id'], 'qty' => $qty]);
                $movement->execute(['voucher' => $voucherId, 'warehouse' => $order['warehouse_id'], 'item' => $orderLine['item_id'], 'batch' => $orderLine['batch_id'], 'qty' => -$qty, 'delivery' => $deliveryId]);
                $deliveryLine->execute(['delivery' => $deliveryId, 'line' => $orderLine['id'], 'batch' => $orderLine['batch_id'], 'qty' => $qty]);
                $allocation->execute(['qty' => $qty, 'id' => $orderLine['id']]);
            }
            $db->prepare("UPDATE sales_orders SET status='DELIVERING' WHERE id=:id")->execute(['id' => $orderId]);
            $db->commit();
            return $deliveryId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Received quantities are cumulative totals for a delivery; only newly received units are posted. */
    public static function receive(array $actor, int $deliveryId, array $received): void
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT d.*,o.sales_point_id,o.warehouse_id,o.id order_id FROM sales_deliveries d JOIN sales_orders o ON o.id=d.order_id WHERE d.id=:id FOR UPDATE');
            $query->execute(['id' => $deliveryId]);
            $delivery = $query->fetch();
            if (!$delivery) throw new DomainException('DELIVERY_NOT_FOUND');
            if (!canAccessSalesPoint($actor, (int)$delivery['sales_point_id'])) {
                throw new DomainException('SALES_POINT_FORBIDDEN');
            }
            if (!in_array($delivery['status'], ['DELIVERING', 'PARTIALLY_RECEIVED'], true)) {
                throw new DomainException('DELIVERY_STATE_INVALID');
            }

            $lineQuery = $db->prepare('SELECT dl.*,ol.item_id FROM sales_delivery_lines dl JOIN sales_order_lines ol ON ol.id=dl.order_line_id WHERE dl.delivery_id=:id ORDER BY dl.id FOR UPDATE');
            $lineQuery->execute(['id' => $deliveryId]);
            $lines = $lineQuery->fetchAll();
            $byBatch = [];
            foreach ($lines as $line) $byBatch[(int)$line['batch_id']] = $line;
            foreach ($received as $batchId => $qty) {
                if (!isset($byBatch[(int)$batchId])) throw new DomainException('RECEIVED_QTY_INVALID');
            }

            $voucherQuery = $db->prepare("SELECT id FROM vouchers WHERE type='sale' AND code=:code LIMIT 1");
            $voucherQuery->execute(['code' => $delivery['code']]);
            $voucherId = $voucherQuery->fetchColumn();
            if ($voucherId === false) throw new DomainException('DELIVERY_STATE_INVALID');
            $findMovement = $db->prepare('SELECT id FROM stock_movements WHERE voucher_id=:voucher AND sales_point_id=:point AND batch_id=:batch FOR UPDATE');
            $insertMovement = $db->prepare("INSERT INTO stock_movements(voucher_id,sales_point_id,item_id,batch_id,qty,sales_delivery_id,ownership) VALUES(:voucher,:point,:item,:batch,:qty,:delivery,'COMPANY_OWNED')");
            $increaseMovement = $db->prepare('UPDATE stock_movements SET qty=qty+:qty WHERE id=:id');
            $updateLine = $db->prepare('UPDATE sales_delivery_lines SET received_qty=:qty,shortage_qty=:shortage WHERE id=:id');
            $complete = true;
            foreach ($lines as $line) {
                $batch = (int)$line['batch_id'];
                if (!array_key_exists($batch, $received)) throw new DomainException('RECEIVED_QTY_MISSING');
                $qty = filter_var($received[$batch], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                if ($qty === false || $qty > (int)$line['shipped_qty']) throw new DomainException('RECEIVED_QTY_INVALID');
                $previous = $line['received_qty'] === null ? 0 : (int)$line['received_qty'];
                if ($qty < $previous) throw new DomainException('RECEIVED_QTY_DECREASE');
                $delta = $qty - $previous;
                if ($delta > 0) {
                    $findMovement->execute(['voucher' => $voucherId, 'point' => $delivery['sales_point_id'], 'batch' => $batch]);
                    $movementId = $findMovement->fetchColumn();
                    if ($movementId === false) {
                        $insertMovement->execute(['voucher' => $voucherId, 'point' => $delivery['sales_point_id'], 'item' => $line['item_id'], 'batch' => $batch, 'qty' => $delta, 'delivery' => $deliveryId]);
                    } else {
                        $increaseMovement->execute(['qty' => $delta, 'id' => $movementId]);
                    }
                }
                $updateLine->execute(['qty' => $qty, 'shortage' => (int)$line['shipped_qty'] - $qty, 'id' => $line['id']]);
                if ($qty !== (int)$line['shipped_qty']) $complete = false;
            }

            $status = $complete ? 'COMPLETED' : 'PARTIALLY_RECEIVED';
            $db->prepare('UPDATE sales_deliveries SET status=:status,received_by=:user,received_at=now() WHERE id=:id')
                ->execute(['status' => $status, 'user' => $actor['id'], 'id' => $deliveryId]);
            $remaining = $db->prepare("SELECT
                count(*) FILTER (WHERE status='DELIVERING') AS delivering,
                count(*) FILTER (WHERE status='PARTIALLY_RECEIVED') AS partial
                FROM sales_deliveries WHERE order_id=:id");
            $remaining->execute(['id' => $delivery['order_id']]);
            $states = $remaining->fetch();
            $orderStatus = (int)$states['delivering'] > 0 ? 'DELIVERING'
                : ((int)$states['partial'] > 0 ? 'PARTIALLY_RECEIVED' : 'COMPLETED');
            $db->prepare('UPDATE sales_orders SET status=:status WHERE id=:id')
                ->execute(['status' => $orderStatus, 'id' => $delivery['order_id']]);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function point(PDO $db, int $id): ?array
    {
        $query = $db->prepare('SELECT * FROM sales_points WHERE id=:id');
        $query->execute(['id' => $id]);
        return $query->fetch() ?: null;
    }

    private static function userPoint(int $userId, int $pointId): bool
    {
        $query = Database::connection()->prepare('SELECT 1 FROM user_sales_points WHERE user_id=:user AND sales_point_id=:point');
        $query->execute(['user' => $userId, 'point' => $pointId]);
        return (bool)$query->fetchColumn();
    }
}
