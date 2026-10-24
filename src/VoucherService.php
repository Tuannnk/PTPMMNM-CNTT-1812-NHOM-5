<?php
declare(strict_types=1);

final class VoucherService
{
    /** Lưu phiếu nháp và các dòng hàng. Khóa yêu cầu ngăn double-submit tạo phiếu trùng. */
    public static function createDraft(array $user, array $data, string $requestKey): int
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $id = (int)$db->query("SELECT nextval(pg_get_serial_sequence('vouchers', 'id'))")->fetchColumn();
            $prefix = ['in' => 'PN', 'out' => 'PX', 'transfer' => 'PC'][$data['type']];
            $code = sprintf('%s-%06d', $prefix, $id);
            $insert = $db->prepare(
            'INSERT INTO vouchers (id,code,type,status,warehouse_id,to_warehouse_id,created_by,note,request_key) '
                . 'VALUES (:id,:code,:type,\'draft\',:warehouse,:to_warehouse,:user,:note,:request_key) '
                . 'ON CONFLICT DO NOTHING RETURNING id'
            );
            $insert->execute([
                'id' => $id,
                'code' => $code,
                'type' => $data['type'],
                'warehouse' => $data['warehouse_id'],
                'to_warehouse' => $data['to_warehouse_id'],
                'user' => $user['id'],
                'note' => $data['note'],
                'request_key' => $requestKey,
            ]);
            $createdId = $insert->fetchColumn();
            if ($createdId === false) {
                $db->rollBack();
                $find = $db->prepare('SELECT id FROM vouchers WHERE request_key=:key');
                $find->execute(['key' => $requestKey]);
                $existingId = $find->fetchColumn();
                if ($existingId !== false) {
                    return (int)$existingId;
                }
                throw new RuntimeException('Không tạo được chứng từ do mã đã tồn tại.');
            }

            $lineInsert = $db->prepare(
                'INSERT INTO voucher_lines (voucher_id,item_id,batch_id,qty) VALUES (:voucher,:item,:batch,:qty)'
            );
            foreach ($data['lines'] as $line) {
                $lineInsert->execute([
                    'voucher' => $id,
                    'item' => $line['item_id'],
                    'batch' => $line['batch_id'],
                    'qty' => $line['qty'],
                ]);
            }
            $db->commit();
            return $id;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** Xác nhận phiếu nhập/xuất trong giao dịch; trả danh sách tồn âm nếu kiểm tra thất bại. */
    public static function confirm(int $voucherId, array $user): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM vouchers WHERE id=:id FOR UPDATE');
            $query->execute(['id' => $voucherId]);
            $voucher = $query->fetch(PDO::FETCH_ASSOC);
            if (!$voucher) {
                throw new RuntimeException('VOUCHER_NOT_FOUND');
            }
            foreach (['id', 'warehouse_id', 'to_warehouse_id'] as $key) {
                if ($voucher[$key] !== null) {
                    $voucher[$key] = (int)$voucher[$key];
                }
            }
            if ($voucher['status'] === 'confirmed') {
                $db->commit();
                return ['already_confirmed' => true, 'negative' => []];
            }
            if (!canConfirm($user, $voucher)) {
                throw new RuntimeException('CONFIRM_FORBIDDEN');
            }

            $lineQuery = $db->prepare('SELECT item_id,batch_id,qty FROM voucher_lines WHERE voucher_id=:id ORDER BY batch_id');
            $lineQuery->execute(['id' => $voucherId]);
            $lines = array_map(static fn(array $line): array => [
                'item_id' => (int)$line['item_id'],
                'batch_id' => (int)$line['batch_id'],
                'qty' => (int)$line['qty'],
            ], $lineQuery->fetchAll(PDO::FETCH_ASSOC));
            if (!$lines) {
                throw new RuntimeException('LINES_EMPTY');
            }

            InventoryGuard::lockWarehouses($db, [$voucher['warehouse_id']]);

            $balanceQuery = $db->prepare(
                'SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE warehouse_id=:warehouse AND batch_id=:batch'
            );
            $negative = [];
            if ($voucher['type'] === 'out') {
                foreach ($lines as $line) {
                    $balanceQuery->execute(['warehouse' => $voucher['warehouse_id'], 'batch' => $line['batch_id']]);
                    $balance = (int)$balanceQuery->fetchColumn();
                    if ($balance < $line['qty']) {
                        $negative[$voucher['warehouse_id'] . ':' . $line['batch_id']] = $balance - $line['qty'];
                    }
                }
            }
            if ($negative) {
                $db->rollBack();
                return ['already_confirmed' => false, 'negative' => $negative];
            }

            $movement = $db->prepare(
                'INSERT INTO stock_movements (voucher_id,warehouse_id,item_id,batch_id,qty,occurred_at) '
                . 'VALUES (:voucher,:warehouse,:item,:batch,:qty,clock_timestamp())'
            );
            foreach ($lines as $line) {
                $qty = $voucher['type'] === 'out' ? -$line['qty'] : $line['qty'];
                $movement->execute([
                    'voucher' => $voucherId,
                    'warehouse' => $voucher['warehouse_id'],
                    'item' => $line['item_id'],
                    'batch' => $line['batch_id'],
                    'qty' => $qty,
                ]);
            }
            $update = $db->prepare(
                "UPDATE vouchers SET status='confirmed',confirmed_by=:user,confirmed_at=now() WHERE id=:id AND status='draft'"
            );
            $update->execute(['user' => $user['id'], 'id' => $voucherId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('VOUCHER_STATE_CHANGED');
            }
            $db->commit();
            return ['already_confirmed' => false, 'negative' => []];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** Từ chối phiếu nháp; lý do và người xử lý được lưu lại, không tạo phát sinh tồn. */
    public static function reject(int $voucherId, array $user, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '' || !textFits($reason, 255)) {
            throw new RuntimeException('REJECT_REASON_INVALID');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM vouchers WHERE id=:id FOR UPDATE');
            $query->execute(['id' => $voucherId]);
            $voucher = $query->fetch(PDO::FETCH_ASSOC);
            if (!$voucher) {
                throw new RuntimeException('VOUCHER_NOT_FOUND');
            }
            $voucher['warehouse_id'] = (int)$voucher['warehouse_id'];
            if ($voucher['status'] === 'rejected') {
                $db->commit();
                return ['already_rejected' => true];
            }
            if (!canConfirm($user, $voucher)) {
                throw new RuntimeException('REJECT_FORBIDDEN');
            }

            $update = $db->prepare(
                "UPDATE vouchers SET status='rejected',rejected_by=:user,rejected_at=now(),reject_reason=:reason WHERE id=:id AND status='draft'"
            );
            $update->execute(['user' => $user['id'], 'reason' => $reason, 'id' => $voucherId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('VOUCHER_STATE_CHANGED');
            }
            $db->commit();
            return ['already_rejected' => false];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }
}
