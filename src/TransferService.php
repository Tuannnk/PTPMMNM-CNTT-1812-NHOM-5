<?php
declare(strict_types=1);

/** Ghi nhận điều chuyển và hai đầu phát sinh trong PostgreSQL. */
final class TransferService
{
    /**
     * Tạo phiếu nháp điều chuyển. Khóa request_key khiến POST gửi lại trả về
     * đúng phiếu đã tạo, thay vì sinh thêm một phiếu trùng.
     *
     * @return array{id:int, created:bool}
     */
    public static function createDraft(array $data, int $createdBy, string $requestKey): array
    {
        if (!preg_match('/^[a-f0-9]{48}$/', $requestKey)) {
            throw new InvalidArgumentException('TRANSFER_REQUEST_INVALID');
        }
        if ($data['type'] !== 'transfer' || !$data['lines']) {
            throw new InvalidArgumentException('TRANSFER_DATA_INVALID');
        }

        $db = Database::connection();
        $db->beginTransaction();
        try {
            $code = 'PC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(8)));
            $insert = $db->prepare(
                "INSERT INTO vouchers
                    (code, type, status, warehouse_id, to_warehouse_id, created_by, note, request_key)
                 VALUES (:code, 'transfer', 'draft', :warehouse, :destination, :created_by, :note, :request_key)
                 ON CONFLICT (request_key) DO NOTHING
                 RETURNING id"
            );
            $insert->execute([
                'code' => $code,
                'warehouse' => $data['warehouse_id'],
                'destination' => $data['to_warehouse_id'],
                'created_by' => $createdBy,
                'note' => $data['note'],
                'request_key' => $requestKey,
            ]);
            $voucherId = $insert->fetchColumn();
            if ($voucherId === false) {
                $lookup = $db->prepare('SELECT id FROM vouchers WHERE request_key = :request_key');
                $lookup->execute(['request_key' => $requestKey]);
                $voucherId = $lookup->fetchColumn();
                if ($voucherId === false) {
                    throw new RuntimeException('TRANSFER_RETRY_LOOKUP_FAILED');
                }
                $db->commit();
                return ['id' => (int)$voucherId, 'created' => false];
            }

            $lineInsert = $db->prepare(
                'INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty)
                 VALUES (:voucher_id, :item_id, :batch_id, :qty)'
            );
            foreach ($data['lines'] as $line) {
                $lineInsert->execute([
                    'voucher_id' => $voucherId,
                    'item_id' => $line['item_id'],
                    'batch_id' => $line['batch_id'],
                    'qty' => $line['qty'],
                ]);
            }

            $workflow = $db->prepare(
                "INSERT INTO transfer_workflow (voucher_id, state) VALUES (:voucher_id, 'draft')"
            );
            $workflow->execute(['voucher_id' => $voucherId]);

            $db->commit();
            return ['id' => (int)$voucherId, 'created' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Xác nhận điều chuyển bằng một giao dịch: khóa tồn từng lô ở kho đi,
     * kiểm tra số dư mới nhất, ghi phát sinh giảm kho đi rồi chuyển sang vận chuyển.
     *
     * @return array{confirmed:bool, already_confirmed?:bool, negative?:array}
     */
    public static function confirm(int $voucherId, array $approver): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare('SELECT * FROM vouchers WHERE id = :id FOR UPDATE');
            $query->execute(['id' => $voucherId]);
            $voucher = $query->fetch();
            if (!$voucher || $voucher['type'] !== 'transfer') {
                throw new DomainException('VOUCHER_NOT_FOUND');
            }

            $voucher['warehouse_id'] = (int)$voucher['warehouse_id'];
            $voucher['to_warehouse_id'] = (int)$voucher['to_warehouse_id'];
            if ($approver['role'] !== 'approver'
                || !canAccessWarehouse($approver, $voucher['warehouse_id'])
                || !canAccessWarehouse($approver, $voucher['to_warehouse_id'])) {
                throw new DomainException('CONFIRM_FORBIDDEN');
            }
            if ($voucher['status'] === 'confirmed') {
                $db->commit();
                return ['confirmed' => true, 'already_confirmed' => true];
            }
            if ($voucher['status'] !== 'draft') {
                throw new DomainException('VOUCHER_NOT_DRAFT');
            }

            $stateQuery = $db->prepare('SELECT state FROM transfer_workflow WHERE voucher_id = :id FOR UPDATE');
            $stateQuery->execute(['id' => $voucherId]);
            if ($stateQuery->fetchColumn() !== 'checked') {
                throw new DomainException('TRANSFER_NOT_CHECKED');
            }

            $lineQuery = $db->prepare(
                'SELECT item_id, batch_id, qty FROM voucher_lines WHERE voucher_id = :id ORDER BY batch_id FOR UPDATE'
            );
            $lineQuery->execute(['id' => $voucherId]);
            $lines = $lineQuery->fetchAll();
            if (!$lines) {
                throw new DomainException('VOUCHER_LINES_EMPTY');
            }

            // Serialize all outgoing transfers for the same warehouse and batch.
            $lock = $db->prepare('SELECT pg_advisory_xact_lock(hashtext(:lock_key))');
            foreach ($lines as $line) {
                $lock->execute(['lock_key' => 'stock:' . $voucher['warehouse_id'] . ':' . $line['batch_id']]);
            }

            $balanceQuery = $db->prepare(
                'SELECT COALESCE(SUM(qty), 0) FROM stock_movements
                 WHERE warehouse_id = :warehouse AND batch_id = :batch'
            );
            $negative = [];
            foreach ($lines as $line) {
                $balanceQuery->execute([
                    'warehouse' => $voucher['warehouse_id'],
                    'batch' => $line['batch_id'],
                ]);
                $balance = (float)$balanceQuery->fetchColumn();
                $after = $balance - (float)$line['qty'];
                if ($after < 0) {
                    $negative[$voucher['warehouse_id'] . ':' . (int)$line['batch_id']] = (int)$after;
                }
            }
            if ($negative) {
                $db->rollBack();
                return ['confirmed' => false, 'negative' => $negative];
            }

            $movementInsert = $db->prepare(
                'INSERT INTO stock_movements (voucher_id, warehouse_id, item_id, batch_id, qty)
                 VALUES (:voucher_id, :warehouse_id, :item_id, :batch_id, :qty)'
            );
            foreach ($lines as $line) {
                $common = [
                    'voucher_id' => $voucherId,
                    'item_id' => (int)$line['item_id'],
                    'batch_id' => (int)$line['batch_id'],
                ];
                $quantity = (int)$line['qty'];
                $movementInsert->execute($common + [
                    'warehouse_id' => $voucher['warehouse_id'],
                    'qty' => -$quantity,
                ]);
            }

            $update = $db->prepare(
                "UPDATE vouchers SET status = 'confirmed', confirmed_by = :approver,
                    confirmed_at = now() WHERE id = :id AND status = 'draft'"
            );
            $update->execute(['approver' => $approver['id'], 'id' => $voucherId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('VOUCHER_NOT_DRAFT');
            }

            $workflowUpdate = $db->prepare(
                "UPDATE transfer_workflow SET state = 'in_transit', dispatched_at = now()
                 WHERE voucher_id = :id AND state = 'checked'"
            );
            $workflowUpdate->execute(['id' => $voucherId]);
            if ($workflowUpdate->rowCount() !== 1) {
                throw new DomainException('TRANSFER_NOT_CHECKED');
            }

            $db->commit();
            return ['confirmed' => true];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Counter kiểm số đã soạn ở kho đi; thiếu hàng thì ghi nhận và hủy phiếu. */
    public static function check(int $voucherId, array $counter, array $checkedQty, array $notes): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $voucher = self::lockedTransfer($db, $voucherId);
            if ($counter['role'] !== 'counter' || !canAccessWarehouse($counter, (int)$voucher['warehouse_id'])) {
                throw new DomainException('TRANSFER_CHECK_FORBIDDEN');
            }
            if (self::workflowState($db, $voucherId) !== 'draft') {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }

            $lineQuery = $db->prepare('SELECT id, qty FROM voucher_lines WHERE voucher_id = :id ORDER BY id FOR UPDATE');
            $lineQuery->execute(['id' => $voucherId]);
            $lines = $lineQuery->fetchAll();
            if (!$lines) {
                throw new DomainException('VOUCHER_LINES_EMPTY');
            }
            $insert = $db->prepare(
                'INSERT INTO transfer_checks (voucher_line_id, voucher_id, checked_qty, note, checked_by)
                 VALUES (:line_id, :voucher_id, :checked_qty, :note, :checked_by)'
            );
            $complete = true;
            $mismatches = [];
            foreach ($lines as $line) {
                $lineId = (int)$line['id'];
                $checked = $checkedQty[$lineId] ?? null;
                if (!is_int($checked) || $checked < 0 || $checked > (int)$line['qty']) {
                    throw new DomainException('TRANSFER_CHECK_QTY_INVALID');
                }
                $note = is_string($notes[$lineId] ?? null) ? trim($notes[$lineId]) : '';
                if (!textFits($note, 255)) {
                    throw new DomainException('TRANSFER_NOTE_TOO_LONG');
                }
                $planned = (int)$line['qty'];
                $insert->execute([
                    'line_id' => $lineId,
                    'voucher_id' => $voucherId,
                    'checked_qty' => $checked,
                    'note' => $note,
                    'checked_by' => $counter['id'],
                ]);
                if ($checked !== $planned) {
                    $complete = false;
                    $mismatches[] = ['line_id' => $lineId, 'expected' => $planned, 'checked' => $checked, 'note' => $note];
                }
            }

            if ($complete) {
                $workflow = $db->prepare(
                    "UPDATE transfer_workflow SET state='checked', checked_by=:user, checked_at=now()
                     WHERE voucher_id=:id AND state='draft'"
                );
                $workflow->execute(['user' => $counter['id'], 'id' => $voucherId]);
            } else {
                $workflow = $db->prepare(
                    "UPDATE transfer_workflow SET state='cancelled', checked_by=:user, checked_at=now(),
                        cancelled_by=:cancelled_by, cancelled_at=now(), cancel_reason=:reason
                     WHERE voucher_id=:id AND state='draft'"
                );
                $workflow->execute([
                    'user' => $counter['id'],
                    'cancelled_by' => $counter['id'],
                    'reason' => 'Kiểm đếm thực tế không khớp số lượng trên phiếu.',
                    'id' => $voucherId,
                ]);
            }
            if ($workflow->rowCount() !== 1) {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }
            if (!$complete) {
                $reject = $db->prepare("UPDATE vouchers SET status = 'rejected' WHERE id = :id AND status = 'draft'");
                $reject->execute(['id' => $voucherId]);
            }
            $db->commit();
            return ['complete' => $complete, 'mismatches' => $mismatches];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Approver hủy phiếu đã kiểm đủ nhưng chưa xuất khỏi kho. */
    public static function cancel(int $voucherId, array $approver, string $reason): void
    {
        if (trim($reason) === '' || !textFits($reason, 255)) {
            throw new DomainException('TRANSFER_CANCEL_REASON_REQUIRED');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $voucher = self::lockedTransfer($db, $voucherId);
            if ($approver['role'] !== 'approver'
                || !canAccessWarehouse($approver, (int)$voucher['warehouse_id'])
                || !canAccessWarehouse($approver, (int)$voucher['to_warehouse_id'])) {
                throw new DomainException('CONFIRM_FORBIDDEN');
            }
            if (self::workflowState($db, $voucherId) !== 'checked') {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }
            $workflow = $db->prepare(
                "UPDATE transfer_workflow SET state='cancelled', cancelled_by=:user,
                    cancelled_at=now(), cancel_reason=:reason WHERE voucher_id=:id AND state='checked'"
            );
            $workflow->execute(['user' => $approver['id'], 'reason' => trim($reason), 'id' => $voucherId]);
            $reject = $db->prepare("UPDATE vouchers SET status='rejected' WHERE id=:id AND status='draft'");
            $reject->execute(['id' => $voucherId]);
            if ($workflow->rowCount() !== 1 || $reject->rowCount() !== 1) {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /** Ghi số thực nhận ở kho đến; thiếu số lượng sẽ được lưu thành yêu cầu bổ sung. */
    public static function receive(int $voucherId, array $receiver, array $receivedQty, array $notes): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $voucher = self::lockedTransfer($db, $voucherId);
            if ($receiver['role'] !== 'staff' || !canAccessWarehouse($receiver, (int)$voucher['to_warehouse_id'])) {
                throw new DomainException('TRANSFER_RECEIVE_FORBIDDEN');
            }
            if (self::workflowState($db, $voucherId) !== 'in_transit') {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }

            $lineQuery = $db->prepare('SELECT id, item_id, batch_id, qty FROM voucher_lines WHERE voucher_id = :id ORDER BY id FOR UPDATE');
            $lineQuery->execute(['id' => $voucherId]);
            $lines = $lineQuery->fetchAll();
            $receiptInsert = $db->prepare(
                'INSERT INTO transfer_receipt_lines
                    (voucher_line_id, voucher_id, received_qty, missing_qty, note, received_by)
                 VALUES (:line_id, :voucher_id, :received, :missing, :note, :user)'
            );
            $movementInsert = $db->prepare(
                'INSERT INTO stock_movements (voucher_id, warehouse_id, item_id, batch_id, qty)
                 VALUES (:voucher_id, :warehouse_id, :item_id, :batch_id, :qty)'
            );
            $hasMissing = false;
            foreach ($lines as $line) {
                $lineId = (int)$line['id'];
                $received = $receivedQty[$lineId] ?? null;
                $planned = (int)$line['qty'];
                if (!is_int($received) || $received < 0 || $received > $planned) {
                    throw new DomainException('TRANSFER_RECEIPT_QTY_INVALID');
                }
                $missing = $planned - $received;
                $note = is_string($notes[$lineId] ?? null) ? trim($notes[$lineId]) : '';
                if ($missing > 0 && $note === '') {
                    throw new DomainException('TRANSFER_SHORTAGE_REASON_REQUIRED');
                }
                if (!textFits($note, 255)) {
                    throw new DomainException('TRANSFER_NOTE_TOO_LONG');
                }
                $receiptInsert->execute([
                    'line_id' => $lineId,
                    'voucher_id' => $voucherId,
                    'received' => $received,
                    'missing' => $missing,
                    'note' => $note,
                    'user' => $receiver['id'],
                ]);
                if ($received > 0) {
                    $movementInsert->execute([
                        'voucher_id' => $voucherId,
                        'warehouse_id' => $voucher['to_warehouse_id'],
                        'item_id' => (int)$line['item_id'],
                        'batch_id' => (int)$line['batch_id'],
                        'qty' => $received,
                    ]);
                }
                $hasMissing = $hasMissing || $missing > 0;
            }

            $state = $hasMissing ? 'partial' : 'received';
            $update = $db->prepare(
                'UPDATE transfer_workflow SET state=:state, received_by=:user, received_at=now()
                 WHERE voucher_id=:id AND state=\'in_transit\''
            );
            $update->execute(['state' => $state, 'user' => $receiver['id'], 'id' => $voucherId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('TRANSFER_STATE_CHANGED');
            }
            $db->commit();
            return ['state' => $state];
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    private static function lockedTransfer(PDO $db, int $voucherId): array
    {
        $query = $db->prepare('SELECT * FROM vouchers WHERE id=:id AND type=\'transfer\' FOR UPDATE');
        $query->execute(['id' => $voucherId]);
        $voucher = $query->fetch();
        if (!$voucher) {
            throw new DomainException('VOUCHER_NOT_FOUND');
        }
        $voucher['warehouse_id'] = (int)$voucher['warehouse_id'];
        $voucher['to_warehouse_id'] = (int)$voucher['to_warehouse_id'];
        return $voucher;
    }

    private static function workflowState(PDO $db, int $voucherId): string
    {
        $query = $db->prepare('SELECT state FROM transfer_workflow WHERE voucher_id=:id FOR UPDATE');
        $query->execute(['id' => $voucherId]);
        $state = $query->fetchColumn();
        if (!is_string($state)) {
            throw new DomainException('TRANSFER_STATE_MISSING');
        }
        return $state;
    }
}
