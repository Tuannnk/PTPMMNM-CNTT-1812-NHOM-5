<?php
// Nhân viên kho: việc cần làm tại kho phụ trách.
$incoming = Dashboard::transfers($scope, 'in_transit', 'to');
$toSupplement = array_values(array_filter(Dashboard::transfers($scope, 'partial', 'from'), fn($v) => $v['ref_id'] === null));
$toDispatch = array_values(array_filter(
    Dashboard::salesOrders($scope, [], ['APPROVED', 'DELIVERING', 'PARTIALLY_RECEIVED']),
    fn($o) => (int)$o['remaining'] > 0
));
$myDrafts = Dashboard::myVouchers($user, 'draft');
$myRejected = Dashboard::myVouchers($user, 'rejected');
$lowCount = Dashboard::lowStockCount($scope);
$todo = count($incoming) + count($toSupplement) + count($toDispatch);
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Hàng đang đến cần nhận', count($incoming), '#viec-can-lam', $incoming ? 'warn' : '') ?>
  <?= dashStat('Phiếu thiếu cần gửi bù', count($toSupplement), '#viec-can-lam', $toSupplement ? 'warn' : '') ?>
  <?= dashStat('Đơn phân phối cần xuất', count($toDispatch), '/sales-orders.php', $toDispatch ? 'warn' : '') ?>
  <?= dashStat('Phiếu của tôi chờ duyệt', count($myDrafts), '#phieu-cua-toi') ?>
  <?= dashStat('Dưới mức tối thiểu', $lowCount, '/stock.php?low=1', $lowCount ? 'danger' : 'ok') ?>
</section>

<div class="grid-2">
  <section class="card" id="viec-can-lam">
    <h2>Việc cần làm <?php if ($todo): ?><span class="badge badge--wait"><?= $todo ?></span><?php endif; ?></h2>
    <?php if (!$todo): ?><p class="empty">Không có việc nào đang chờ bạn. 🎉</p><?php endif; ?>
    <?php if ($incoming): ?>
      <h3>Hàng đang đến — xác nhận số thực nhận</h3>
      <?= voucherList($incoming, '', fn($v) => ageBadge($v['transfer_dispatched_at'] ?? $v['created_at'], 'xuất')) ?>
    <?php endif; ?>
    <?php if ($toSupplement): ?>
      <h3>Kho nhận báo thiếu — lập phiếu gửi bù</h3>
      <?= voucherList($toSupplement, '', fn($v) => ageBadge($v['transfer_received_at'] ?? $v['created_at'], 'báo')
          . ' <a class="text-link" href="/transfer-supplement.php?ref_id=' . (int)$v['id'] . '">Gửi bù →</a>') ?>
    <?php endif; ?>
    <?php if ($toDispatch): ?>
      <h3>Đơn phân phối đã duyệt — chờ xuất hàng</h3>
      <?= salesOrderList($toDispatch, '') ?>
    <?php endif; ?>
  </section>

  <section class="card" id="phieu-cua-toi">
    <h2>Phiếu của tôi</h2>
    <h3>Đang chờ duyệt</h3>
    <?= voucherList($myDrafts, 'Bạn không có phiếu nào đang chờ duyệt.', fn($v) => voucherStatusBadge($v) . ' ' . ageBadge($v['created_at'])) ?>
    <h3>Bị từ chối / hủy (14 ngày gần đây)</h3>
    <?= voucherList($myRejected, 'Không có phiếu bị từ chối.', fn($v) => '<span class="muted">' . e(($v['reject_reason'] ?? '') ?: ($v['transfer_cancel_reason'] ?? '') ?: 'không ghi lý do') . '</span>') ?>
  </section>
</div>

<section class="card">
  <h2>Tồn dưới mức tối thiểu tại kho phụ trách</h2>
  <?= lowStockTable(Dashboard::lowStock($scope, 8)) ?>
  <?php if ($lowCount > 8): ?><a class="text-link" href="/stock.php?low=1">Xem tất cả <?= $lowCount ?> dòng →</a><?php endif; ?>
</section>

<section class="card">
  <h2>Phát sinh gần đây</h2>
  <?= recentMovementTable(Dashboard::recentMovements($scope)) ?>
  <a class="text-link" href="/history.php">Xem toàn bộ lịch sử →</a>
</section>
