<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$scope = array_column(allowedWarehouses($user), 'id');
$counts = array_filter(Store::all('stock_counts'), fn($c) => in_array($c['warehouse_id'], $scope, true));
$canCreate = in_array($user['role'], ['counter', 'approver'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $warehouseId = intField($_POST, 'warehouse_id');
    if (!$canCreate || $warehouseId === null || !canAccessWarehouse($user, $warehouseId)) {
        forbid();
    }
    flash('Đợt kiểm kê mới cho ' . warehouseName($warehouseId) . ' hợp lệ: sẽ chụp số tồn hiện tại của mọi lô (giao diện mẫu, chưa lưu CSDL).');
    redirect('/counts.php');
}

$title = 'Kiểm kê';
$active = 'counts';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Kiểm kê</h1><p>Tạo đợt kiểm kê, nhập số thực tế, tính chênh lệch và lập chứng từ điều chỉnh có lý do.</p></div>
  <?php if ($canCreate): ?>
    <form method="post" class="actions inline-form">
      <?= csrfInput() ?>
      <select name="warehouse_id" aria-label="Kho kiểm kê">
        <?php foreach (allowedWarehouses($user) as $w): ?><option value="<?= $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="button">+ Tạo đợt kiểm kê</button>
    </form>
  <?php endif; ?>
</header>

<div class="table-wrap">
<table class="table">
  <thead><tr><th>Mã</th><th>Kho</th><th>Ngày tạo</th><th>Người phụ trách</th><th class="num">Số dòng</th><th class="num">Dòng lệch</th><th>Trạng thái</th></tr></thead>
  <tbody>
    <?php foreach (array_reverse($counts) as $c):
        $diffs = array_filter($c['lines'], fn($l) => $l['actual_qty'] !== null && $l['actual_qty'] !== $l['system_qty']); ?>
      <tr>
        <td><a href="/count.php?id=<?= $c['id'] ?>"><strong><?= e($c['code']) ?></strong></a></td>
        <td><?= e(warehouseName($c['warehouse_id'])) ?></td>
        <td><?= e($c['created_at']) ?></td>
        <td><?= e(userName($c['created_by'])) ?></td>
        <td class="num"><?= count($c['lines']) ?></td>
        <td class="num"><?= $c['status'] === 'closed' ? count($diffs) : '—' ?></td>
        <td><?= $c['status'] === 'closed' ? '<span class="badge badge--ok">Đã chốt</span>' : '<span class="badge badge--wait">Đang kiểm</span>' ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$counts): ?><p class="empty">Chưa có đợt kiểm kê trong các kho bạn phụ trách.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
