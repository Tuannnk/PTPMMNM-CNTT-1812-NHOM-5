<?php
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/HistoryReport.php';
$user = requireLogin();

if (field($_GET, 'report') === 'history') {
    $scope = array_column(allowedWarehouses($user), 'id');
    $warehouseId = intField($_GET, 'warehouse_id');
    if ($warehouseId !== null && !in_array($warehouseId, $scope, true)) forbid();
    $itemId = intField($_GET, 'item_id');
    $batchId = intField($_GET, 'batch_id');
    if ($batchId !== null) {
        $batch = Store::find('batches', $batchId);
        if (!$batch || ($itemId !== null && $batch['item_id'] !== $itemId)) $batchId = null;
        elseif ($itemId === null) $itemId = $batch['item_id'];
    }
    $type = field($_GET, 'type');
    if (!isset(VOUCHER_TYPES[$type])) $type = '';
    $from = field($_GET, 'from'); $to = field($_GET, 'to');
    if (!HistoryReport::validDate($from)) $from = '';
    if (!HistoryReport::validDate($to)) $to = '';
    if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
    $report = HistoryReport::build(['item_id'=>$itemId,'warehouse_id'=>$warehouseId,'batch_id'=>$batchId,
        'type'=>$type,'from'=>$from,'to'=>$to], $scope);
    $items = Store::indexed('items'); $batches = Store::indexed('batches'); $vouchers = Store::indexed('vouchers');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="lich-su-kho-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    $csvText = static function ($value): string {
        $value = (string)$value;
        return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
    };
    fputcsv($out, ['Thời điểm','Mã chứng từ','Loại','Kho','Mã mặt hàng','Mặt hàng','Lô','Phát sinh','Lũy kế kho/lô'], ',', '"', '');
    foreach ($report['rows'] as $m) {
        $v = $vouchers[$m['voucher_id']]; $item = $items[$m['item_id']];
        fputcsv($out, [dateOnly($m['at']),$csvText($v['code']),$csvText(VOUCHER_TYPES[$v['type']] ?? $v['type']),
            $csvText(warehouseName($m['warehouse_id'])),$csvText($item['sku']),$csvText($item['name']),
            $csvText($batches[$m['batch_id']]['code']),$m['qty'],$m['running']], ',', '"', '');
    }
    fclose($out); exit;
}

$allowed = allowedWarehouses($user);
$q = field($_GET, 'q');
$lowOnly = field($_GET, 'low') === '1';
$movements = Store::all('stock_movements');
$csvText = static function ($value): string {
    $value = (string)$value;
    return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'" . $value : $value;
};

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ton-kho-' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
fputcsv($out, array_merge(['Mã', 'Mặt hàng', 'ĐVT'], array_map($csvText, array_column($allowed, 'code')), ['Tổng', 'Tối thiểu']), ',', '"', '');
$perWarehouse = array_map(fn($w) => StockLedger::byItem($movements, $w['id']), $allowed);
foreach (Store::all('items') as $item) {
    $cells = array_map(fn($byItem) => $byItem[$item['id']] ?? 0, $perWarehouse);
    $total = array_sum($cells);
    if (($q !== '' && stripos($item['sku'] . ' ' . $item['name'], $q) === false) || ($lowOnly && $total >= $item['min_stock'])) {
        continue;
    }
    fputcsv($out, array_merge([$csvText($item['sku']), $csvText($item['name']), $csvText($item['unit_name'])], $cells, [$total, $item['min_stock']]), ',', '"', '');
}
fclose($out);
