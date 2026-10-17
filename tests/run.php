<?php
declare(strict_types=1);
// Kiểm thử logic thuần (không cần máy chủ Web). Chạy: php tests/run.php
// Phép thử đồng thời thật (hai yêu cầu cùng lúc) sẽ bổ sung khi có CSDL và giao dịch.

require __DIR__ . '/helper.php';
require __DIR__ . '/../src/Input.php';
require __DIR__ . '/../src/Store.php';
require __DIR__ . '/../src/StockLedger.php';
require __DIR__ . '/../src/Auth.php';
require __DIR__ . '/../src/Validation.php';

$staffA = Store::find('users', 1); // chỉ phụ trách kho 1
$batch = Store::all('batches')[0];

// Dữ liệu mẫu đúng quy mô đề yêu cầu.
same(3, count(Store::all('warehouses')), 'seed: 3 kho');
same(30, count(Store::all('items')), 'seed: 30 mặt hàng');
same(true, count(Store::all('stock_movements')) >= 200, 'seed: ít nhất 200 dòng phát sinh');
$balances = StockLedger::balances(Store::all('stock_movements'));
same(true, min($balances) >= 0, 'seed: không có số tồn âm');

// Điều chuyển: trừ kho đi, cộng kho đến; tổng bằng 0.
$transfer = ['type' => 'transfer', 'warehouse_id' => 1, 'to_warehouse_id' => 2];
$moves = StockLedger::movementsFor($transfer, [['item_id' => $batch['item_id'], 'batch_id' => $batch['id'], 'qty' => 7]]);
same(2, count($moves), 'điều chuyển sinh hai dòng phát sinh');
same(0, array_sum(array_column($moves, 'qty')), 'điều chuyển không làm mất hoặc nhân đôi hàng');

// Kho còn 5, xuất 4 hai lần: lần đầu hợp lệ, lần hai bị chặn (mô phỏng tuần tự).
$five = ['1:' . $batch['id'] => 5];
$out = ['type' => 'out', 'warehouse_id' => 1, 'to_warehouse_id' => null];
$line = [['item_id' => $batch['item_id'], 'batch_id' => $batch['id'], 'qty' => 4]];
same([], StockLedger::negativeAfter($five, StockLedger::movementsFor($out, $line)), 'tồn 5, xuất 4: hợp lệ');
$afterFirst = ['1:' . $batch['id'] => 1];
same(['1:' . $batch['id'] => -3], StockLedger::negativeAfter($afterFirst, StockLedger::movementsFor($out, $line)), 'xuất 4 lần hai: phát hiện tồn âm');

$post = fn(array $extra) => $extra + ['type' => 'out', 'warehouse_id' => '1', 'batch_id' => [(string)$batch['id']], 'qty' => ['4'], 'note' => ''];
same(['STOCK_NEGATIVE'], validateVoucher($post([]), $staffA, $afterFirst)['errors'], 'validateVoucher chặn xuất vượt tồn');
same([], validateVoucher($post([]), $staffA, $five)['errors'], 'validateVoucher cho xuất trong phạm vi tồn');

// Quyền theo kho: tài khoản kho A gửi thẳng warehouse_id của kho B.
same(['WAREHOUSE_FORBIDDEN'], validateVoucher($post(['type' => 'in', 'warehouse_id' => '2']), $staffA, $balances)['errors'], 'nhân viên kho A không lập phiếu kho B');

// Dữ liệu sai.
same(['TO_WAREHOUSE_INVALID'], validateVoucher($post(['type' => 'transfer', 'to_warehouse_id' => '1']), $staffA, $balances)['errors'], 'điều chuyển trùng kho bị từ chối');
same(['QTY_INVALID'], validateVoucher($post(['type' => 'in', 'qty' => ['0']]), $staffA, $balances)['errors'], 'số lượng 0 bị từ chối');
same(['BATCH_DUPLICATE'], validateVoucher($post(['type' => 'in', 'batch_id' => [(string)$batch['id'], (string)$batch['id']], 'qty' => ['1', '2']]), $staffA, $balances)['errors'], 'trùng lô trong một chứng từ bị từ chối');
same(['LINES_EMPTY'], validateVoucher($post(['type' => 'in', 'batch_id' => [''], 'qty' => ['']]), $staffA, $balances)['errors'], 'chứng từ không có dòng hàng bị từ chối');
same(['BATCH_INVALID'], validateVoucher($post(['type' => 'in', 'batch_id' => ['9999']]), $staffA, $balances)['errors'], 'lô không tồn tại bị từ chối');

// Kiểm kê: lệch phải có lý do; điều chỉnh khớp chênh lệch.
$count = Store::find('stock_counts', 2);
$actual = array_map(fn($l) => (string)($l['system_qty'] - 1), $count['lines']);
same(['REASON_REQUIRED'], validateCount(['actual' => $actual, 'reason' => []], $count)['errors'], 'dòng lệch không có lý do bị từ chối');
$reasons = array_fill(0, count($count['lines']), 'Hao hụt');
same([], validateCount(['actual' => $actual, 'reason' => $reasons], $count)['errors'], 'dòng lệch có lý do được chấp nhận');

$closed = Store::find('stock_counts', 1);
$adjust = Store::linesOf($closed['adjust_voucher_id']);
$diffs = array_filter(array_map(fn($l) => $l['actual_qty'] - $l['system_qty'], $closed['lines']));
same(array_sum($diffs), array_sum(array_column($adjust, 'qty')), 'chứng từ điều chỉnh khớp tổng chênh lệch kiểm kê');

finish();
