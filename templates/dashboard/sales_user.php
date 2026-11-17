<?php
// Người dùng điểm bán: hàng đang giao đến, đơn đã đặt, tồn tại điểm bán.
$deliveries = Dashboard::deliveriesAwaitingReceipt($salesPointIds);
$orderCounts = Dashboard::salesOrderCounts([], $salesPointIds);
$openOrders = Dashboard::salesOrders([], $salesPointIds, ['PENDING', 'APPROVED', 'DELIVERING', 'PARTIALLY_RECEIVED']);
$stock = Dashboard::salesPointStock($salesPointIds);
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Lần giao cần xác nhận', count($deliveries), '#can-xac-nhan', $deliveries ? 'warn' : 'ok') ?>
  <?= dashStat('Đơn chờ kho duyệt', $orderCounts['PENDING'] ?? 0, '#don-cua-toi') ?>
  <?= dashStat('Đơn đã duyệt / đang giao', ($orderCounts['APPROVED'] ?? 0) + ($orderCounts['DELIVERING'] ?? 0) + ($orderCounts['PARTIALLY_RECEIVED'] ?? 0), '#don-cua-toi') ?>
  <?= dashStat('Đơn hoàn tất', $orderCounts['COMPLETED'] ?? 0, '/sales-orders.php', 'ok') ?>
</section>

<section class="card" id="can-xac-nhan">
  <h2>Hàng đang giao đến — xác nhận số thực nhận</h2>
  <?php if (!$deliveries): ?><p class="empty">Không có lần giao nào đang chờ bạn xác nhận.</p><?php else: ?>
    <ul class="list"><?php foreach ($deliveries as $d): ?>
      <li><a href="/sales-order.php?id=<?= (int)$d['order_id'] ?>"><strong><?= e($d['order_code']) ?></strong></a>
        <span class="muted">Giao #<?= (int)$d['id'] ?> · <?= e($d['point_name']) ?> · <?= qty((int)$d['shipped']) ?> đơn vị</span>
        <?php if ($d['status'] === 'PARTIALLY_RECEIVED'): ?><span class="badge badge--wait">Đã nhận một phần</span><?php endif; ?>
        <?= ageBadge($d['delivered_at'], 'giao') ?>
        <a class="text-link" href="/sales-order.php?id=<?= (int)$d['order_id'] ?>">Xác nhận nhận hàng →</a></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>
</section>

<div class="grid-2">
  <section class="card" id="don-cua-toi">
    <h2>Đơn đang xử lý</h2>
    <?= salesOrderList($openOrders, 'Không có đơn nào đang xử lý.') ?>
    <a class="text-link" href="/sales-orders.php">Tất cả đơn / đặt hàng mới →</a>
  </section>
  <section class="card">
    <h2>Tồn tại điểm bán</h2>
    <?php if (!$stock): ?><p class="empty">Điểm bán chưa có hàng được ghi nhận.</p><?php else: ?>
      <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Tồn</th></tr></thead>
        <tbody><?php foreach ($stock as $s): ?><tr><td><?= e($s['sku'] . ' — ' . $s['name']) ?></td><td><?= e($s['batch_code']) ?></td><td class="num"><?= qty((int)$s['qty']) ?></td></tr><?php endforeach; ?></tbody>
      </table></div>
    <?php endif; ?>
    <a class="text-link" href="/sales-stock.php">Xem tồn đầy đủ →</a>
  </section>
</div>
