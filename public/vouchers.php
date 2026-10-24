<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$scope = array_column(allowedWarehouses($user), 'id');
$type = field($_GET, 'type');
$status = field($_GET, 'status');
$warehouseId = intField($_GET, 'warehouse_id');
$q = field($_GET, 'q');

$rows = array_filter(Store::all('vouchers'), function ($v) use ($scope, $type, $status, $warehouseId, $q) {
    $inScope = in_array($v['warehouse_id'], $scope, true) || in_array($v['to_warehouse_id'], $scope, true);
    return $inScope
        && ($type === '' || $v['type'] === $type)
        && ($status === '' || $v['status'] === $status)
        && ($warehouseId === null || $v['warehouse_id'] === $warehouseId || $v['to_warehouse_id'] === $warehouseId)
        && ($q === '' || stripos($v['code'] . ' ' . $v['note'], $q) !== false);
});
$rows = array_reverse($rows);

$title = 'Chứng từ';
$active = 'vouchers';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Chứng từ</h1><p>Nhập, xuất, điều chuyển và điều chỉnh. Chứng từ đã xác nhận không sửa, không xóa.</p></div>
  <?php if (canCreateVoucher($user)): ?><div class="actions">
    <a class="button" href="/voucher-new.php?type=in">+ Phiếu nhập</a>
    <a class="button button--ghost" href="/voucher-new.php?type=out">Phiếu xuất</a>
    <a class="button button--ghost" href="/voucher-new.php?type=transfer">Điều chuyển</a>
  </div><?php endif; ?>
</header>

<form class="filters" method="get">
  <label>Tìm<input name="q" value="<?= e($q) ?>" placeholder="Mã hoặc ghi chú"></label>
  <label>Loại
    <select name="type">
      <option value="">Tất cả</option>
      <?php foreach (VOUCHER_TYPES as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Trạng thái
    <select name="status">
      <option value="">Tất cả</option>
      <?php foreach (VOUCHER_STATUS as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Kho
    <select name="warehouse_id">
      <option value="">Tất cả kho phụ trách</option>
      <?php foreach (allowedWarehouses($user) as $w): ?>
        <option value="<?= $w['id'] ?>" <?= $warehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button">Lọc</button>
</form>

<p class="result-count"><?= count($rows) ?> chứng từ</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Mã</th><th>Loại</th><th>Kho</th><th>Ngày lập</th><th>Người lập</th><th class="num">Dòng</th><th>Trạng thái</th><?php if ($user['role'] === 'approver'): ?><th>Thao tác</th><?php endif; ?></tr></thead>
  <tbody>
    <?php foreach ($rows as $v): ?>
      <tr>
        <td><a href="/voucher.php?id=<?= $v['id'] ?>"><strong><?= e($v['code']) ?></strong></a></td>
        <td><?= typeBadge($v['type']) ?></td>
        <td><?= e(warehouseName($v['warehouse_id'])) ?><?= $v['to_warehouse_id'] ? ' → ' . e(warehouseName($v['to_warehouse_id'])) : '' ?></td>
        <td><?= e($v['created_at']) ?></td>
        <td><?= e(userName($v['created_by'])) ?></td>
        <td class="num"><?= count(Store::linesOf($v['id'])) ?></td>
        <td><?= statusBadge($v['status']) ?></td>
        <?php if ($user['role'] === 'approver'): ?><td><?php if (canConfirm($user, $v)): ?><a class="text-link" href="/voucher.php?id=<?= $v['id'] ?>">Mở để kiểm tra, duyệt hoặc từ chối</a><?php elseif ($v['status'] === 'draft' && $v['type'] === 'transfer'): ?>Chưa hỗ trợ duyệt điều chuyển<?php else: ?>—<?php endif; ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$rows): ?><p class="empty">Không có chứng từ phù hợp bộ lọc.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
