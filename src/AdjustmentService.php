<?php
declare(strict_types=1);

/** Immutable correction vouchers: adjustments append signed stock movements. */
final class AdjustmentService
{
    public static function createDraft(int $originalId, array $lines, array $user, string $requestKey): int
    {
        if ($user['role'] !== 'staff' || !preg_match('/^[a-f0-9]{48}$/', $requestKey) || !$lines) {
            throw new DomainException('ADJUSTMENT_INVALID');
        }
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM vouchers WHERE id=:id FOR UPDATE');
            $q->execute(['id' => $originalId]);
            $original = $q->fetch();
            if (!$original || $original['status'] !== 'confirmed') {
                throw new DomainException('ADJUSTMENT_ORIGINAL_INVALID');
            }
            $original['id'] = (int)$original['id'];
            $original['warehouse_id'] = (int)$original['warehouse_id'];
            if ($original['type'] === 'transfer') {
                $st = $db->prepare('SELECT state FROM transfer_workflow WHERE voucher_id=:id');
                $st->execute(['id' => $originalId]);
                if (!in_array($st->fetchColumn(), ['partial', 'received'], true)) {
                    throw new DomainException('ADJUSTMENT_TRANSFER_NOT_RECEIVED');
                }
            }
            $movementsQ = $db->prepare('SELECT * FROM stock_movements WHERE voucher_id=:id ORDER BY id');
            $movementsQ->execute(['id' => $originalId]);
            $originalMovements = [];
            foreach ($movementsQ->fetchAll() as $m) $originalMovements[(int)$m['id']] = $m;

            $firstRefId = (int)($lines[0]['ref_movement_id'] ?? 0);
            $firstWarehouse = (int)($originalMovements[$firstRefId]['warehouse_id'] ?? 0);
            if ($firstWarehouse < 1 || !canAccessWarehouse($user, $firstWarehouse)) throw new DomainException('ADJUSTMENT_FORBIDDEN');
            $inserted = $db->prepare("INSERT INTO vouchers
                (code,type,status,warehouse_id,created_by,note,ref_id,request_key)
                VALUES (:code,'adjust','draft',:warehouse,:user,:note,:ref,:key)
                ON CONFLICT (request_key) DO NOTHING RETURNING id");
            $code = 'DC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(6)));
            $inserted->execute(['code'=>$code, 'warehouse'=>$firstWarehouse, 'user'=>$user['id'],
                'note'=>'Điều chỉnh cho ' . $original['code'], 'ref'=>$originalId, 'key'=>$requestKey]);
            $voucherId = $inserted->fetchColumn();
            if ($voucherId === false) {
                $find = $db->prepare('SELECT id FROM vouchers WHERE request_key=:key');
                $find->execute(['key'=>$requestKey]);
                $voucherId = $find->fetchColumn();
                if ($voucherId === false) throw new RuntimeException('ADJUSTMENT_RETRY_LOOKUP_FAILED');
                $db->commit();
                return (int)$voucherId;
            }
            $lineInsert = $db->prepare('INSERT INTO adjustment_lines
                (voucher_id,warehouse_id,item_id,batch_id,ref_movement_id,qty,reason)
                VALUES (:voucher,:warehouse,:item,:batch,:movement,:qty,:reason)');
            $seen = [];
            foreach ($lines as $line) {
                $refId = (int)$line['ref_movement_id'];
                $m = $originalMovements[$refId] ?? null;
                $delta = (int)$line['qty'];
                $reason = trim((string)$line['reason']);
                if (!$m || isset($seen[$refId]) || $delta === 0 || $reason === '' || !textFits($reason, 255)) {
                    throw new DomainException('ADJUSTMENT_LINE_INVALID');
                }
                $warehouseId = (int)$m['warehouse_id'];
                if (!canAccessWarehouse($user, $warehouseId)) throw new DomainException('ADJUSTMENT_FORBIDDEN');
                $lineInsert->execute(['voucher'=>$voucherId, 'warehouse'=>$warehouseId,
                    'item'=>(int)$m['item_id'], 'batch'=>(int)$m['batch_id'], 'movement'=>$refId,
                    'qty'=>$delta, 'reason'=>$reason]);
                $seen[$refId] = true;
            }
            $db->commit();
            return (int)$voucherId;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function confirm(int $voucherId, array $approver): array
    {
        $db = Database::connection();
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT * FROM vouchers WHERE id=:id FOR UPDATE');
            $q->execute(['id'=>$voucherId]);
            $v = $q->fetch();
            if (!$v || $v['type'] !== 'adjust') throw new DomainException('VOUCHER_NOT_FOUND');
            if ($v['status'] === 'confirmed') { $db->commit(); return ['confirmed'=>true,'already_confirmed'=>true,'negative'=>[]]; }
            if ($v['status'] !== 'draft' || $approver['role'] !== 'approver') throw new DomainException('CONFIRM_FORBIDDEN');
            $lq = $db->prepare('SELECT * FROM adjustment_lines WHERE voucher_id=:id ORDER BY warehouse_id,batch_id,id');
            $lq->execute(['id'=>$voucherId]);
            $lines = $lq->fetchAll();
            if (!$lines) throw new DomainException('ADJUSTMENT_EMPTY');
            foreach ($lines as &$line) {
                foreach (['warehouse_id','item_id','batch_id'] as $key) $line[$key] = (int)$line[$key];
                $line['qty'] = (int)$line['qty'];
                if (!canAccessWarehouse($approver, $line['warehouse_id'])) throw new DomainException('CONFIRM_FORBIDDEN');
            }
            unset($line);
            $locks = [];
            foreach ($lines as $line) $locks[$line['warehouse_id'].':'.$line['batch_id']] = true;
            ksort($locks, SORT_STRING);
            $lock = $db->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:key,0))');
            $transferLock = $db->prepare('SELECT pg_advisory_xact_lock(hashtext(:key))');
            foreach (array_keys($locks) as $key) {
                $lock->execute(['key'=>$key]);
                $transferLock->execute(['key'=>'stock:' . $key]);
            }
            $balanceQ = $db->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE warehouse_id=:warehouse AND batch_id=:batch');
            $after = [];
            foreach ($lines as $line) {
                $key = $line['warehouse_id'].':'.$line['batch_id'];
                if (!array_key_exists($key, $after)) {
                    [$warehouse,$batch] = array_map('intval', explode(':',$key));
                    $balanceQ->execute(['warehouse'=>$warehouse,'batch'=>$batch]);
                    $after[$key] = (int)$balanceQ->fetchColumn();
                }
                $after[$key] += $line['qty'];
            }
            $negative = array_filter($after, static fn($qty) => $qty < 0);
            if ($negative) { $db->commit(); return ['confirmed'=>false,'negative'=>$negative]; }
            $movementInsert = $db->prepare('INSERT INTO stock_movements (voucher_id,warehouse_id,item_id,batch_id,qty)
                VALUES (:voucher,:warehouse,:item,:batch,:qty)');
            foreach ($lines as $line) $movementInsert->execute(['voucher'=>$voucherId,'warehouse'=>$line['warehouse_id'],
                'item'=>$line['item_id'],'batch'=>$line['batch_id'],'qty'=>$line['qty']]);
            $update = $db->prepare("UPDATE vouchers SET status='confirmed',confirmed_by=:user,confirmed_at=now() WHERE id=:id");
            $update->execute(['user'=>$approver['id'],'id'=>$voucherId]);
            $db->commit();
            return ['confirmed'=>true,'negative'=>[]];
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
