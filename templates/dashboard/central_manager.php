<?php
// Quản lý kho tổng: kho tổng + các kho địa điểm trực thuộc (chỉ số tổng hợp, chỉ đọc).
$children = array_values(array_filter(Store::all('warehouses'),
    fn($w) => $w['active'] && $w['parent_id'] !== null && in_array($w['parent_id'], $scope, true)));
$network = array_values(array_unique(array_merge($scope, array_column($children, 'id'))));
$overviewRows = Dashboard::warehouseOverview($network);
$maxQty = max(1, ...array_map(fn($r) => (int)$r['total_qty'], $overviewRows ?: [['total_qty' => 1]]));
$inTransit = array_values(array_filter(Store::all('vouchers'), fn($v) => $v['type'] === 'transfer'
    && ($v['transfer_state'] ?? '') === 'in_transit'
    && (in_array($v['warehouse_id'], $network, true) || in_array($v['to_warehouse_id'], $network, true))));
$orderCounts = Dashboard::salesOrderCounts($network, []);
$openOrders = Dashboard::salesOrders($network, [], ['PENDING', 'APPROVED', 'DELIVERING', 'PARTIALLY_RECEIVED']);
$lowCentral = Dashboard::lowStockCount($scope);
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Kho trong mạng lưới', count($network), '#mang-luoi') ?>
  <?= dashStat('Dưới mức tại kho tổng', $lowCentral, '/stock.php?low=1', $lowCentral ? 'danger' : 'ok') ?>
  <?= dashStat('Điều chuyển đang vận chuyển', count($inTransit), '#van-chuyen', $inTransit ? 'warn' : '') ?>
  <?= dashStat('Đơn phân phối chờ duyệt', $orderCounts['PENDING'] ?? 0, '#don-phan-phoi', !empty($orderCounts['PENDING']) ? 'warn' : '') ?>
  <?= dashStat('Đơn đang giao', ($orderCounts['DELIVERING'] ?? 0) + ($orderCounts['PARTIALLY_RECEIVED'] ?? 0), '#don-phan-phoi') ?>
</section>

<section class="card" id="mang-luoi">
  <h2>Tồn kho theo kho</h2>
  <p class="muted">Kho tổng và các kho địa điểm trực thuộc. Số liệu tổng hợp để điều phối; thao tác chi tiết vẫn theo quyền từng kho.</p>
  <div class="table-wrap"><table class="table table--compact">
    <thead><tr><th>Kho</th><th>Loại</th><th class="num">Tổng tồn</th><th>Tỷ trọng</th><th class="num">Mặt hàng còn hàng</th><th class="num">Dưới mức</th></tr></thead>
    <tbody><?php foreach ($overviewRows as $r): $pct = round((int)$r['total_qty'] / $maxQty * 100); ?>
      <tr><td><strong><?= e($r['code']) ?></strong> <span class="muted"><?= e($r['name']) ?></span></td>
        <td><?= $r['type'] === 'CENTRAL' ? '<span class="badge badge--transfer">Kho tổng</span>' : '<span class="badge">Địa điểm</span>' ?></td>
        <td class="num"><?= qty((int)$r['total_qty']) ?></td>
        <td><span class="meter meter--primary" role="img" aria-label="<?= $pct ?>% so với kho nhiều hàng nhất"><span style="width:<?= $pct ?>%"></span></span></td>
        <td class="num"><?= qty((int)$r['items_in_stock']) ?></td>
        <td class="num <?= (int)$r['low_items'] ? 'qty-neg' : 'muted' ?>"><?= qty((int)$r['low_items']) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</section>

<div class="grid-2">
  <section class="card" id="van-chuyen">
    <h2>Điều chuyển đang vận chuyển</h2>
    <?= voucherList($inTransit, 'Không có hàng đang trên đường.', fn($v) => ageBadge($v['transfer_dispatched_at'] ?? $v['created_at'], 'xuất')) ?>
  </section>
  <section class="card" id="don-phan-phoi">
    <h2>Đơn phân phối đang xử lý</h2>
    <?= salesOrderList($openOrders, 'Không có đơn phân phối đang xử lý.') ?>
    <a class="text-link" href="/sales-orders.php">Tất cả đơn phân phối →</a>
  </section>
</div>

<section class="card">
  <h2>Tồn dưới mức tối thiểu tại kho tổng</h2>
  <?= lowStockTable(Dashboard::lowStock($scope, 8)) ?>
</section>
