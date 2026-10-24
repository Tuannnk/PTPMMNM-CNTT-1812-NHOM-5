<?php
declare(strict_types=1);

/** Ghi đợt kiểm kê và chứng từ điều chỉnh trong cùng giao dịch. */
final class StockCountService
{
    public static function create(array $user, int $warehouseId): int
    {
        if (!in_array($user['role'], ['counter', 'approver'], true)
            || !canAccessWarehouse($user, $warehouseId)) {
            throw new DomainException('COUNT_CREATE_FORBIDDEN');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $batches = $db->prepare(
                'SELECT b.id,b.item_id,COALESCE(SUM(sm.qty),0) AS system_qty '
                . 'FROM batches b JOIN items i ON i.id=b.item_id AND i.active=TRUE '
                . 'LEFT JOIN stock_movements sm ON sm.batch_id=b.id AND sm.warehouse_id=:warehouse '
                . 'WHERE b.active=TRUE GROUP BY b.id,b.item_id ORDER BY b.id'
            );
            $batches->execute(['warehouse' => $warehouseId]);
            $snapshot = $batches->fetchAll(PDO::FETCH_ASSOC);
            foreach ($snapshot as $row) {
                if ((int)$row['system_qty'] < 0) {
                    throw new DomainException('COUNT_NEGATIVE_SYSTEM_STOCK');
                }
            }

            $id = (int)$db->query("SELECT nextval(pg_get_serial_sequence('stock_counts','id'))")->fetchColumn();
            $insert = $db->prepare(
                "INSERT INTO stock_counts (id,code,warehouse_id,status,created_by) "
                . "VALUES (:id,:code,:warehouse,'counting',:user)"
            );
            $insert->execute([
                'id' => $id,
                'code' => sprintf('KK-%06d', $id),
                'warehouse' => $warehouseId,
                'user' => $user['id'],
            ]);

            $lineInsert = $db->prepare(
                'INSERT INTO stock_count_lines (count_id,item_id,batch_id,system_qty) '
                . 'VALUES (:count,:item,:batch,:qty)'
            );
            foreach ($snapshot as $row) {
                $lineInsert->execute([
                    'count' => $id,
                    'item' => $row['item_id'],
                    'batch' => $row['id'],
                    'qty' => $row['system_qty'],
                ]);
            }
            $db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }

    /** @return array{already_closed:bool,adjust_voucher_id:int} */
    public static function close(int $countId, array $user, array $inputLines): array
    {
        if (!in_array($user['role'], ['counter', 'approver'], true)) {
            throw new DomainException('COUNT_EDIT_FORBIDDEN');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $countQuery = $db->prepare('SELECT * FROM stock_counts WHERE id=:id FOR UPDATE');
            $countQuery->execute(['id' => $countId]);
            $count = $countQuery->fetch(PDO::FETCH_ASSOC);
            if (!$count) throw new DomainException('COUNT_NOT_FOUND');
            $count['warehouse_id'] = (int)$count['warehouse_id'];
            if (!canAccessWarehouse($user, $count['warehouse_id'])) {
                throw new DomainException('COUNT_EDIT_FORBIDDEN');
            }
            if ($count['status'] === 'closed') {
                $db->commit();
                return ['already_closed' => true, 'adjust_voucher_id' => (int)$count['adjust_voucher_id']];
            }

            $query = $db->prepare('SELECT * FROM stock_count_lines WHERE count_id=:id ORDER BY id FOR UPDATE');
            $query->execute(['id' => $countId]);
            $storedLines = $query->fetchAll(PDO::FETCH_ASSOC);
            $submitted = [];
            foreach ($inputLines as $line) {
                $lineId = (int)($line['id'] ?? 0);
                if ($lineId < 1 || isset($submitted[$lineId])) {
                    throw new DomainException('COUNT_LINES_INVALID');
                }
                $submitted[$lineId] = $line;
            }
            if (count($submitted) !== count($storedLines)) {
                throw new DomainException('COUNT_LINES_INVALID');
            }

            $differences = [];
            $saveLine = $db->prepare(
                'UPDATE stock_count_lines SET actual_qty=:actual,reason=:reason WHERE id=:id AND count_id=:count'
            );
            foreach ($storedLines as $line) {
                $lineId = (int)$line['id'];
                if (!isset($submitted[$lineId])) throw new DomainException('COUNT_LINES_INVALID');
                $entry = $submitted[$lineId];
                $actual = filter_var($entry['actual_qty'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                $reason = is_string($entry['reason'] ?? null) ? trim($entry['reason']) : '';
                if ($actual === false || !textFits($reason, 255)) throw new DomainException('COUNT_LINES_INVALID');
                $diff = $actual - (int)$line['system_qty'];
                if ($diff !== 0 && $reason === '') throw new DomainException('REASON_REQUIRED');
                $saveLine->execute(['actual' => $actual, 'reason' => $reason, 'id' => $lineId, 'count' => $countId]);
                if ($diff !== 0) {
                    $differences[] = [
                        'item_id' => (int)$line['item_id'],
                        'batch_id' => (int)$line['batch_id'],
                        'qty' => $diff,
                        'reason' => $reason,
                    ];
                }
            }

            $voucherId = (int)$db->query("SELECT nextval(pg_get_serial_sequence('vouchers','id'))")->fetchColumn();
            $voucher = $db->prepare(
                "INSERT INTO vouchers (id,code,type,status,warehouse_id,created_by,confirmed_by,confirmed_at,note) "
                . "VALUES (:id,:code,'adjust','confirmed',:warehouse,:user,:user,now(),:note)"
            );
            $voucher->execute([
                'id' => $voucherId,
                'code' => sprintf('DC-%06d', $voucherId),
                'warehouse' => $count['warehouse_id'],
                'user' => $user['id'],
                'note' => 'Điều chỉnh theo đợt kiểm kê ' . $count['code'],
            ]);

            $insertLine = $db->prepare(
                'INSERT INTO voucher_lines (voucher_id,item_id,batch_id,qty,reason) '
                . 'VALUES (:voucher,:item,:batch,:qty,:reason)'
            );
            $movement = $db->prepare(
                'INSERT INTO stock_movements (voucher_id,warehouse_id,item_id,batch_id,qty) '
                . 'VALUES (:voucher,:warehouse,:item,:batch,:qty)'
            );
            foreach ($differences as $difference) {
                $params = [
                    'voucher' => $voucherId,
                    'item' => $difference['item_id'],
                    'batch' => $difference['batch_id'],
                    'qty' => $difference['qty'],
                    'reason' => $difference['reason'],
                ];
                $insertLine->execute($params);
                $movement->execute([
                    'voucher' => $voucherId,
                    'warehouse' => $count['warehouse_id'],
                    'item' => $difference['item_id'],
                    'batch' => $difference['batch_id'],
                    'qty' => $difference['qty'],
                ]);
            }

            $close = $db->prepare("UPDATE stock_counts SET status='closed',adjust_voucher_id=:voucher WHERE id=:id AND status='counting'");
            $close->execute(['voucher' => $voucherId, 'id' => $countId]);
            if ($close->rowCount() !== 1) throw new DomainException('COUNT_STATE_CHANGED');
            $db->commit();
            return ['already_closed' => false, 'adjust_voucher_id' => $voucherId];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
