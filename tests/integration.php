<?php
declare(strict_types=1);
// Integration test chạy trên database PostgreSQL đã cấu hình (cần dữ liệu seed + seed demo).
// Toàn bộ chạy trong MỘT giao dịch ngoài cùng; giao dịch của service được đổi thành SAVEPOINT
// và cuối cùng ROLLBACK, nên database không bị thay đổi. Chạy: php tests/integration.php

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Input.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/InventoryGuard.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/StockLedger.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Validation.php';
require __DIR__ . '/../src/VoucherService.php';
require __DIR__ . '/../src/TransferService.php';
require __DIR__ . '/../src/AdjustmentService.php';
require __DIR__ . '/../src/StockCountService.php';
require_once __DIR__ . '/../src/HistoryReport.php';

/** PDO biến beginTransaction/commit/rollBack lồng nhau thành SAVEPOINT trong giao dịch ngoài. */
final class SavepointPDO extends PDO
{
    private int $depth = 0;
    public function beginTransaction(): bool { $this->exec('SAVEPOINT sp' . ++$this->depth); return true; }
    public function commit(): bool { $this->exec('RELEASE SAVEPOINT sp' . $this->depth--); return true; }
    public function rollBack(): bool { $this->exec('ROLLBACK TO SAVEPOINT sp' . $this->depth--); return true; }
    public function inTransaction(): bool { return $this->depth > 0; }
    public function outerBegin(): void { parent::beginTransaction(); }
    public function outerRollBack(): void { parent::rollBack(); }
}

// Mở kết nối riêng cùng thông số cấu hình rồi thay vào Database::$connection.
$config = new ReflectionClass(Database::class);
$prop = $config->getProperty('connection');
$prop->setValue(null, null);
$method = $config->getMethod('environmentConfig');
$cfg = $method->invoke(null) ?? $config->getMethod('localConfig')->invoke(null);
$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $cfg['PGHOST'], $cfg['PGPORT'], $cfg['PGDATABASE'])
    . (($cfg['PGSSLMODE'] ?? '') !== '' ? ';sslmode=' . $cfg['PGSSLMODE'] : '');
$db = new SavepointPDO($dsn, $cfg['PGUSER'], $cfg['PGPASSWORD'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$prop->setValue(null, $db);
$db->outerBegin();

function user(string $username): array
{
    foreach (Store::all('users') as $u) if ($u['username'] === $username) return $u;
    throw new RuntimeException('Thiếu tài khoản demo ' . $username);
}
function balance(int $warehouseId, int $batchId): int
{
    $q = Database::connection()->prepare('SELECT COALESCE(SUM(qty),0) FROM stock_movements WHERE warehouse_id=:w AND batch_id=:b');
    $q->execute(['w' => $warehouseId, 'b' => $batchId]);
    return (int)$q->fetchColumn();
}
function voucherRow(int $id): array
{
    $q = Database::connection()->prepare('SELECT * FROM vouchers WHERE id=:id');
    $q->execute(['id' => $id]);
    return $q->fetch();
}
function errorCode(callable $fn): string
{
    try { $fn(); return 'NO_ERROR'; } catch (Throwable $e) { return $e->getMessage(); }
}
function key48(): string { return bin2hex(random_bytes(24)); }

try {
    // Áp migration 004, 005 nếu database chưa chạy (DDL cũng nằm trong giao dịch, sẽ được rollback).
    foreach (['004_transfer_shortage_resolution.sql', '005_voucher_reject_columns.sql'] as $migration) {
        $db->exec((string)preg_replace('/^\s*(BEGIN|COMMIT);\s*$/mi', '',
            (string)file_get_contents(__DIR__ . '/../database/migrations/' . $migration)));
    }

    $staffSource = user('nv.hn');      // kho 1
    $staffDest = user('nv.dn');        // kho 2
    $counter = user('kiemke');
    $approver = user('duyet');
    $source = 1; $dest = 2;
    $batchRow = $db->query("SELECT b.id,b.item_id FROM batches b JOIN stock_movements m ON m.batch_id=b.id AND m.warehouse_id=1
        WHERE b.active GROUP BY b.id,b.item_id HAVING SUM(m.qty)>=30 ORDER BY b.id LIMIT 1")->fetch();
    if (!$batchRow) throw new RuntimeException('Kho 1 cần một lô có tồn >= 30');
    $batchId = (int)$batchRow['id']; $itemId = (int)$batchRow['item_id'];

    /** Điều chuyển 10, kho đến nhận $received. Trả id phiếu. */
    $partialTransfer = function (int $received) use ($staffSource, $staffDest, $counter, $approver, $source, $dest, $batchId, $itemId, $db): int {
        $saved = TransferService::createDraft(['type' => 'transfer', 'warehouse_id' => $source, 'to_warehouse_id' => $dest,
            'note' => 'integration', 'lines' => [['item_id' => $itemId, 'batch_id' => $batchId, 'qty' => 10]]],
            (int)$staffSource['id'], bin2hex(random_bytes(32)));
        $id = $saved['id'];
        $lineId = (int)$db->query('SELECT id FROM voucher_lines WHERE voucher_id=' . $id)->fetchColumn();
        TransferService::check($id, $counter, [$lineId => 10], []);
        TransferService::confirm($id, $approver);
        TransferService::receive($id, $staffDest, [$lineId => $received], [$lineId => 'thiếu khi nhận']);
        return $id;
    };

    // ===== Mục 1: nguyên nhân hàng thiếu =====
    $before = balance($source, $batchId);
    $t1 = $partialTransfer(8);
    $line1 = (int)$db->query('SELECT id FROM voucher_lines WHERE voucher_id=' . $t1)->fetchColumn();
    same($before - 10, balance($source, $batchId), 'điều chuyển thiếu: kho đi bị trừ đủ 10');
    same('TRANSFER_SHORTAGE_FORBIDDEN', errorCode(fn() => TransferService::resolveShortage($t1, $staffDest, $line1, 'LOST', 'x')),
        'chỉ nhân viên kho đi được xác nhận nguyên nhân');
    same('TRANSFER_SHORTAGE_CAUSE_INVALID', errorCode(fn() => TransferService::resolveShortage($t1, $staffSource, $line1, 'LOST', '  ')),
        'nguyên nhân thiếu bắt buộc có lý do');
    $r1 = TransferService::resolveShortage($t1, $staffSource, $line1, 'SHORT_PICKED', 'còn 2 thùng trên kệ');
    same($before - 10, balance($source, $batchId), 'soạn thiếu: chưa đổi tồn khi phiếu điều chỉnh chờ duyệt');
    same('draft', voucherRow((int)$r1['adjust_voucher_id'])['status'], 'soạn thiếu: phiếu điều chỉnh ở trạng thái chờ duyệt');
    same('TRANSFER_SHORTAGE_RESOLVED', errorCode(fn() => TransferService::resolveShortage($t1, $staffSource, $line1, 'LOST', 'x')),
        'không xác nhận nguyên nhân hai lần');
    AdjustmentService::confirm((int)$r1['adjust_voucher_id'], $approver);
    same($before - 8, balance($source, $batchId), 'soạn thiếu: sau khi duyệt kho đi được cộng lại 2');

    $before = balance($source, $batchId);
    $t2 = $partialTransfer(7);
    $line2 = (int)$db->query('SELECT id FROM voucher_lines WHERE voucher_id=' . $t2)->fetchColumn();
    $r2 = TransferService::resolveShortage($t2, $staffSource, $line2, 'LOST', 'vỡ khi vận chuyển');
    same(null, $r2['adjust_voucher_id'], 'thất lạc: không lập phiếu điều chỉnh');
    same($before - 10, balance($source, $batchId), 'thất lạc: sổ kho đi không đổi');
    same(1, (int)$db->query("SELECT count(*) FROM transfer_shortage_resolutions WHERE voucher_id=$t2 AND cause='LOST' AND qty=3")->fetchColumn(),
        'thất lạc: lưu biên bản đúng số lượng thiếu');

    $t3 = $partialTransfer(9);
    $line3 = (int)$db->query('SELECT id FROM voucher_lines WHERE voucher_id=' . $t3)->fetchColumn();
    $r3 = TransferService::resolveShortage($t3, $staffSource, $line3, 'SHORT_PICKED', 'tưởng còn trong kho');
    VoucherService::reject((int)$r3['adjust_voucher_id'], $approver, 'kiểm lại không thấy hàng');
    same('NO_ERROR', errorCode(fn() => TransferService::resolveShortage($t3, $staffSource, $line3, 'LOST', 'xác nhận thất lạc')),
        'phiếu soạn thiếu bị từ chối thì được xác nhận lại nguyên nhân');

    // ===== Mục 2: điều chỉnh kiểm kê phải qua người duyệt =====
    $countWarehouse = (int)$db->query("SELECT w.id FROM warehouses w WHERE w.id IN (1,2,3) AND NOT EXISTS
        (SELECT 1 FROM stock_counts c WHERE c.warehouse_id=w.id AND c.status='counting') ORDER BY w.id LIMIT 1")->fetchColumn();
    if ($countWarehouse === 0) throw new RuntimeException('Cần một kho 1-3 không có đợt kiểm kê đang mở');
    $countId = StockCountService::create($counter, $countWarehouse);
    $countLines = $db->query("SELECT id,batch_id FROM stock_count_lines WHERE count_id=$countId ORDER BY id")->fetchAll();
    $target = null; $input = [];
    foreach ($countLines as $cl) {
        $bal = balance($countWarehouse, (int)$cl['batch_id']);
        if ($target === null && $bal > 0) { $target = ['batch' => (int)$cl['batch_id'], 'before' => $bal];
            $input[] = ['id' => (int)$cl['id'], 'actual_qty' => (string)($bal - 1), 'reason' => 'hư hỏng']; continue; }
        $input[] = ['id' => (int)$cl['id'], 'actual_qty' => (string)$bal, 'reason' => ''];
    }
    $closed = StockCountService::close($countId, $counter, $input);
    $adjust = voucherRow($closed['adjust_voucher_id']);
    same(true, $closed['needs_approval'], 'chốt kiểm kê có lệch: cần người duyệt');
    same('draft', $adjust['status'], 'chốt kiểm kê: phiếu điều chỉnh chờ duyệt');
    same($target['before'], balance($countWarehouse, $target['batch']), 'chốt kiểm kê: sổ tồn chưa đổi');
    same(false, canConfirm($counter, $adjust), 'người kiểm kê không tự duyệt điều chỉnh');
    same(true, canConfirm($approver, $adjust), 'người duyệt thấy nút duyệt điều chỉnh kiểm kê');
    AdjustmentService::confirm((int)$adjust['id'], $approver);
    same($target['before'] - 1, balance($countWarehouse, $target['batch']), 'duyệt điều chỉnh kiểm kê: tồn giảm 1');
    $reconcile = (new ReflectionMethod(HistoryReport::class, 'reconcileLatestCounts'))->invoke(null,
        ['warehouse_id' => $countWarehouse, 'item_id' => null, 'batch_id' => $target['batch'], 'type' => '', 'from' => '', 'to' => ''],
        [$countWarehouse]);
    same(0, $reconcile['mismatches'], 'đối chiếu lịch sử khớp sau khi duyệt điều chỉnh kiểm kê');

    $selfWarehouse = (int)$db->query("SELECT w.id FROM warehouses w WHERE w.id IN (1,2,3) AND NOT EXISTS
        (SELECT 1 FROM stock_counts c WHERE c.warehouse_id=w.id AND c.status='counting') ORDER BY w.id LIMIT 1")->fetchColumn();
    $selfCount = StockCountService::create($approver, $selfWarehouse);
    $selfInput = [];
    $firstDiff = true;
    foreach ($db->query("SELECT id,batch_id FROM stock_count_lines WHERE count_id=$selfCount ORDER BY id")->fetchAll() as $cl) {
        $bal = balance($selfWarehouse, (int)$cl['batch_id']);
        $selfInput[] = ['id' => (int)$cl['id'], 'actual_qty' => (string)($firstDiff ? $bal + 1 : $bal), 'reason' => $firstDiff ? 'thừa' : ''];
        $firstDiff = false;
    }
    $selfAdjust = voucherRow(StockCountService::close($selfCount, $approver, $selfInput)['adjust_voucher_id']);
    same(false, canConfirm($approver, $selfAdjust), 'người duyệt không tự duyệt điều chỉnh từ đợt mình chốt');
    same('CONFIRM_FORBIDDEN', errorCode(fn() => AdjustmentService::confirm((int)$selfAdjust['id'], $approver)),
        'service chặn tự duyệt điều chỉnh');
} catch (Throwable $e) {
    $GLOBALS['failed']++;
    echo 'LỖI ' . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    $db->outerRollBack();
    echo "ROLLBACK: database không thay đổi\n";
}
finish();
