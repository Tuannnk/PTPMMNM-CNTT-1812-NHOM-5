<?php
// Người phụ trách kiểm kê: kiểm hàng điều chuyển và kiểm kê định kỳ.
$toCheck = Dashboard::transfers($scope, 'draft', 'from');
usort($toCheck, fn($a, $b) => strcmp((string)$a['created_at'], (string)$b['created_at']));
$countRows = Dashboard::countOverview($scope);
$openCounts = count(array_filter($countRows, fn($r) => $r['open_count_id']));
$overdue = count(array_filter($countRows, fn($r) => !$r['open_count_id']
    && (!$r['last_closed_at'] || Dashboard::ageDays($r['last_closed_at']) > 30)));
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Phiếu điều chuyển chờ kiểm hàng', count($toCheck), '#kiem-hang', $toCheck ? 'warn' : 'ok') ?>
  <?= dashStat('Đợt kiểm kê đang mở', $openCounts, '/counts.php', $openCounts ? 'warn' : '') ?>
  <?= dashStat('Kho quá 30 ngày chưa kiểm kê', $overdue, '#kiem-ke', $overdue ? 'danger' : 'ok') ?>
  <?= dashStat('Kho phụ trách', count($scope), '/stock.php') ?>
</section>

<section class="card" id="kiem-hang">
  <h2>Phiếu điều chuyển chờ kiểm hàng</h2>
  <p class="muted">Kiểm số hàng đã soạn ở kho đi. Đủ thì phiếu chuyển sang người duyệt; thiếu thì phiếu bị hủy kèm kết quả kiểm đếm.</p>
  <?= voucherList($toCheck, 'Không có phiếu nào chờ kiểm hàng.', fn($v) => ageBadge($v['created_at'])
      . ' <span class="muted">lập bởi ' . e(userName($v['created_by'])) . '</span>'
      . ' <a class="text-link" href="/voucher.php?id=' . (int)$v['id'] . '">Kiểm hàng →</a>', 12) ?>
</section>

<section class="card" id="kiem-ke">
  <h2>Kiểm kê theo kho</h2>
  <p class="muted">Kho chưa kiểm kê hơn 30 ngày được đánh dấu "Nên kiểm kê". Mở đợt mới ở trang <a class="text-link" href="/counts.php">Kiểm kê</a>.</p>
  <?php require __DIR__ . '/_counts.php'; ?>
</section>
