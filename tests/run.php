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
require_once __DIR__ . '/../src/HistoryReport.php';
require_once __DIR__ . '/../src/LoginThrottle.php';

$staffA = Store::find('users', 1); // chỉ phụ trách kho 1
$batch = Store::all('batches')[0];

// Dữ liệu mẫu đúng quy mô đề yêu cầu.
// Migration chuỗi cung ứng tạo thêm kho tổng CENTRAL-01, nên chỉ khẳng định tối thiểu 3 kho nghiệp vụ.
$warehouseCodes = array_column(Store::all('warehouses'), 'code');
same(true, count(array_intersect(['KHO-HN', 'KHO-DN', 'KHO-HCM'], $warehouseCodes)) === 3, 'seed: 3 kho địa điểm');
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
same(['WAREHOUSE_FORBIDDEN'], validateVoucher($post(['type' => 'out', 'warehouse_id' => '2']), $staffA, $balances)['errors'], 'nhân viên kho A không lập phiếu kho B');

// Dữ liệu sai.
same(['TO_WAREHOUSE_INVALID'], validateVoucher($post(['type' => 'transfer', 'to_warehouse_id' => '1']), $staffA, $balances)['errors'], 'điều chuyển trùng kho bị từ chối');
same(['QTY_INVALID'], validateVoucher($post(['type' => 'out', 'qty' => ['0']]), $staffA, $balances)['errors'], 'số lượng 0 bị từ chối');
same(['BATCH_DUPLICATE'], validateVoucher($post(['type' => 'out', 'batch_id' => [(string)$batch['id'], (string)$batch['id']], 'qty' => ['1', '2']]), $staffA, $balances)['errors'], 'trùng lô trong một chứng từ bị từ chối');
same(['LINES_EMPTY'], validateVoucher($post(['type' => 'out', 'batch_id' => [''], 'qty' => ['']]), $staffA, $balances)['errors'], 'chứng từ không có dòng hàng bị từ chối');
same(['BATCH_INVALID'], validateVoucher($post(['type' => 'out', 'batch_id' => ['9999']]), $staffA, $balances)['errors'], 'lô không tồn tại bị từ chối');

// Kiểm kê: lệch phải có lý do; không phụ thuộc ID cứng của fixture.
$counts = Store::all('stock_counts');
$count = $counts[0] ?? null;
same(true, $count !== null && $count['lines'] !== [], 'fixture: có đợt kiểm kê để kiểm tra');
$count ??= ['lines' => []];
$actual = array_map(fn($l) => (string)($l['system_qty'] - 1), $count['lines']);
same(['REASON_REQUIRED'], validateCount(['actual' => $actual, 'reason' => []], $count)['errors'], 'dòng lệch không có lý do bị từ chối');
$reasons = array_fill(0, count($count['lines']), 'Hao hụt');
same([], validateCount(['actual' => $actual, 'reason' => $reasons], $count)['errors'], 'dòng lệch có lý do được chấp nhận');

$closed = array_values(array_filter($counts, fn($candidate) => $candidate['status'] === 'closed'))[0] ?? null;
$closed ??= ['adjust_voucher_id' => null, 'lines' => []];
$adjust = Store::linesOf($closed['adjust_voucher_id']);
$diffs = array_filter(array_map(fn($l) => $l['actual_qty'] - $l['system_qty'], $closed['lines']));
same(array_sum($diffs), array_sum(array_column($adjust, 'qty')), 'chứng từ điều chỉnh khớp tổng chênh lệch kiểm kê');

// SQL history query is paged and returns the same authorized ledger population.
$scope = array_column(array_filter(Store::all('warehouses'), fn($w) => $w['active']), 'id');
$historyFilters = ['item_id'=>null,'warehouse_id'=>null,'batch_id'=>null,'type'=>'','from'=>'','to'=>''];
$history = HistoryReport::build($historyFilters, $scope);
$expectedHistory = array_values(array_filter(Store::all('stock_movements'), fn($m) => in_array($m['warehouse_id'], $scope, true)));
usort($expectedHistory, fn($a,$b) => strcmp($b['at'],$a['at']) ?: ($b['id'] <=> $a['id']));
same(count($expectedHistory), $history['total_rows'], 'history SQL total matches scoped ledger');
same(min(HistoryReport::PAGE_SIZE, count($expectedHistory)), count($history['rows']), 'history SQL page is bounded');
same($expectedHistory[0]['id'] ?? null, $history['rows'][0]['id'] ?? null, 'history SQL order matches newest-first order');

// Throttle is atomic across workers and resets after a successful login.
$throttleUser = 'audit-' . getmypid() . '-' . bin2hex(random_bytes(4));
LoginThrottle::clear('127.0.0.1', $throttleUser);
$attemptResults = [];
for ($i=0; $i<8; $i++) $attemptResults[] = LoginThrottle::begin('127.0.0.1', $throttleUser);
same(true, count(array_filter($attemptResults, fn($retry) => $retry === 0)) === 8, 'login throttle allows configured attempts');
same(true, LoginThrottle::begin('127.0.0.1', $throttleUser) > 0, 'login throttle blocks additional attempts');
LoginThrottle::clear('127.0.0.1', $throttleUser);
same(0, LoginThrottle::begin('127.0.0.1', $throttleUser), 'successful-login reset clears throttle');
LoginThrottle::clear('127.0.0.1', $throttleUser);

same("'=1+1", csvText('=1+1'), 'CSV formula marker is neutralized');

finish();
