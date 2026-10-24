<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$allowed = allowedWarehouses($user);
$q = field($_GET, 'q');
$lowOnly = field($_GET, 'low') === '1';
$movements = Store::all('stock_movements');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="ton-kho-' . date('Ymd') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
fputcsv($out, array_merge(['Mã', 'Mặt hàng', 'ĐVT'], array_column($allowed, 'code'), ['Tổng', 'Tối thiểu']), ',', '"', '');
$perWarehouse = array_map(fn($w) => StockLedger::byItem($movements, $w['id']), $allowed);
foreach (Store::all('items') as $item) {
    $cells = array_map(fn($byItem) => $byItem[$item['id']] ?? 0, $perWarehouse);
    $total = array_sum($cells);
    if (($q !== '' && stripos($item['sku'] . ' ' . $item['name'], $q) === false) || ($lowOnly && $total >= $item['min_stock'])) {
        continue;
    }
    fputcsv($out, array_merge([$item['sku'], $item['name'], $item['unit_name']], $cells, [$total, $item['min_stock']]), ',', '"', '');
}
fclose($out);
