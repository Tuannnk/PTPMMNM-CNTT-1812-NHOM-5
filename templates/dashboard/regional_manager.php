<?php
// Quản lý kho địa điểm: đơn phân phối, điểm bán và tồn kho của kho phụ trách.
$orderCounts = Dashboard::salesOrderCounts($scope, []);
$pending = Dashboard::salesOrders($scope, [], ['PENDING']);
$toDispatch = array_values(array_filter(Dashboard::salesOrders($scope, [], ['APPROVED', 'DELIVERING', 'PARTIALLY_RECEIVED']),
    fn($o) => (int)$o['remaining'] > 0));
$delivering = Dashboard::salesOrders($scope, [], ['DELIVERING', 'PARTIALLY_RECEIVED']);
$points = Dashboard::salesPointsOf($scope);
$incoming = Dashboard::transfers($scope, 'in_transit', 'to');
$lowCount = Dashboard::lowStockCount($scope);
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Đơn chờ bạn duyệt', count($pending), '#don-cho-duyet', $pending ? 'warn' : 'ok') ?>
  <?= dashStat('Đơn chờ xuất hàng', count($toDispatch), '#cho-xuat', $toDispatch ? 'warn' : '') ?>
  <?= dashStat('Đơn đang giao', count($delivering), '#dang-giao') ?>
  <?= dashStat('Đơn hoàn tất', $orderCounts['COMPLETED'] ?? 0, '/sales-orders.php', 'ok') ?>
  <?= dashStat('Hàng điều chuyển đang đến', count($incoming), '#hang-den') ?>
  <?= dashStat('Dưới mức tối thiểu', $lowCount, '/stock.php?low=1', $lowCount ? 'danger' : 'ok') ?>
</section>

<div class="grid-2">
  <section class="card" id="don-cho-duyet">
    <h2>Đơn phân phối chờ duyệt</h2>
    <?= salesOrderList($pending, 'Không có đơn nào chờ duyệt.') ?>
    <h3 id="cho-xuat">Đã duyệt — chờ xuất hàng</h3>
    <?= salesOrderList($toDispatch, 'Không có đơn chờ xuất.') ?>
  </section>
  <section class="card" id="dang-giao">
    <h2>Đang giao đến điểm bán</h2>
    <?= salesOrderList($delivering, 'Không có đơn đang giao.') ?>
    <h3 id="hang-den">Hàng điều chuyển đang đến kho</h3>
    <?= voucherList($incoming, 'Không có hàng đang đến.', fn($v) => ageBadge($v['transfer_dispatched_at'] ?? $v['created_at'], 'xuất')) ?>
  </section>
</div>

<div class="grid-2">
  <section class="card">
    <h2>Điểm bán phụ trách</h2>
    <?php if (!$points): ?><p class="empty">Chưa có điểm bán nào gắn với kho của bạn.</p><?php else: ?>
      <ul class="list"><?php foreach ($points as $p): ?>
        <li><strong><?= e($p['code']) ?></strong> <?= e($p['name']) ?>
          <span class="badge"><?= e(['AGENT' => 'Đại lý', 'STORE' => 'Cửa hàng', 'SUPERMARKET' => 'Siêu thị'][$p['point_type']] ?? $p['point_type']) ?></span>
          <?php if (!$p['active']): ?><span class="badge badge--rejected">Ngừng hoạt động</span><?php endif; ?></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <a class="text-link" href="/sales-stock.php">Tồn tại điểm bán →</a>
  </section>
  <section class="card">
    <h2>Tồn dưới mức tối thiểu</h2>
    <?= lowStockTable(Dashboard::lowStock($scope, 6)) ?>
  </section>
</div>
