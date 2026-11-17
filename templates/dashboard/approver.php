<?php
// Người duyệt: hàng đợi duyệt theo thời gian chờ.
$queue = Dashboard::approvalQueue($user);
$byType = ['in' => 0, 'out' => 0, 'transfer' => 0, 'adjust' => 0];
foreach ($queue as $v) $byType[$v['type']] = ($byType[$v['type']] ?? 0) + 1;
$oldestDays = $queue ? Dashboard::ageDays($queue[0]['created_at']) : 0;
$pendingOrders = Dashboard::salesOrders($scope, [], ['PENDING']);
$transfersInTransit = Dashboard::transfers($scope, 'in_transit', 'from');
$typeAction = ['in' => 'Duyệt nhập', 'out' => 'Duyệt xuất', 'transfer' => 'Duyệt xuất điều chuyển', 'adjust' => 'Duyệt điều chỉnh'];
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Tổng chờ bạn duyệt', count($queue), '#hang-doi', $queue ? 'warn' : 'ok') ?>
  <?= dashStat('Nhập / xuất', $byType['in'] + $byType['out'], '/vouchers.php?status=draft') ?>
  <?= dashStat('Điều chuyển đã kiểm đủ', $byType['transfer'], '#hang-doi') ?>
  <?= dashStat('Điều chỉnh', $byType['adjust'], '#hang-doi') ?>
  <?= dashStat('Phiếu chờ lâu nhất (ngày)', $oldestDays, '#hang-doi', $oldestDays >= 7 ? 'danger' : ($oldestDays >= 2 ? 'warn' : '')) ?>
  <?= dashStat('Đơn phân phối chờ duyệt', count($pendingOrders), '#don-phan-phoi', $pendingOrders ? 'warn' : '') ?>
</section>

<div class="grid-2">
  <section class="card" id="hang-doi">
    <h2>Hàng đợi duyệt</h2>
    <p class="muted">Chỉ gồm phiếu bạn có quyền duyệt ở mọi kho liên quan, không gồm phiếu do chính bạn lập. Phiếu chờ lâu nhất ở trên cùng.</p>
    <?= voucherList($queue, 'Không có chứng từ nào chờ bạn duyệt.', fn($v) => ageBadge($v['created_at'])
        . ' <span class="muted">lập bởi ' . e(userName($v['created_by'])) . '</span>'
        . ' <a class="text-link" href="/voucher.php?id=' . (int)$v['id'] . '">' . e($typeAction[$v['type']] ?? 'Xem') . ' →</a>', 12) ?>
  </section>

  <section class="card" id="don-phan-phoi">
    <h2>Đơn phân phối chờ duyệt</h2>
    <?= salesOrderList($pendingOrders, 'Không có đơn phân phối chờ duyệt.') ?>
    <h3>Điều chuyển đang vận chuyển</h3>
    <p class="muted">Đã duyệt xuất, chờ kho đến xác nhận nhận hàng.</p>
    <?= voucherList($transfersInTransit, 'Không có phiếu đang vận chuyển.', fn($v) => ageBadge($v['transfer_dispatched_at'] ?? $v['created_at'], 'xuất')) ?>
  </section>
</div>

<section class="card">
  <h2>Kiểm kê theo kho</h2>
  <?php require __DIR__ . '/_counts.php'; ?>
</section>
