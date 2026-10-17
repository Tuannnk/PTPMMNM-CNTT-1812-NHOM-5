<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$allowed = allowedWarehouses($user);
$scope = array_column($allowed, 'id');
$itemId = intField($_GET, 'item_id');
$warehouseId = intField($_GET, 'warehouse_id');
if ($warehouseId !== null && !in_array($warehouseId, $scope, true)) {
    forbid();
}

$items = Store::indexed('items');
$batches = Store::indexed('batches');
$vouchers = Store::indexed('vouchers');
$rows = array_values(array_filter(Store::all('stock_movements'), fn($m) =>
    in_array($m['warehouse_id'], $scope, true)
    && ($itemId === null || $m['item_id'] === $itemId)
    && ($warehouseId === null || $m['warehouse_id'] === $warehouseId)
));

// Lũy kế theo thứ tự thời gian để đối chiếu: số cuối cùng phải bằng số tồn hiện tại.
$running = 0;
foreach ($rows as &$row) {
    $running += $row['qty'];
    $row['running'] = $running;
}
unset($row);
$rows = array_reverse($rows);

$title = 'Lịch sử phát sinh';
$active = 'history';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Lịch sử phát sinh</h1><p>Sổ phát sinh chỉ thêm, không sửa, không xóa. Mọi thay đổi tồn đều có chứng từ.</p></div>
</header>

<form class="filters" method="get">
  <label>Mặt hàng
    <select name="item_id">
      <option value="">Tất cả</option>
      <?php foreach ($items as $item): ?>
        <option value="<?= $item['id'] ?>" <?= $itemId === $item['id'] ? 'selected' : '' ?>><?= e($item['sku'] . ' — ' . $item['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Kho
    <select name="warehouse_id">
      <option value="">Tất cả kho phụ trách</option>
      <?php foreach ($allowed as $w): ?>
        <option value="<?= $w['id'] ?>" <?= $warehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button">Lọc</button>
</form>

<?php if ($itemId !== null): ?>
  <section class="reconcile">
    <div><span>Tổng phát sinh</span><strong><?= qty($running) ?></strong></div>
    <?php $lookup = array_sum(array_map(fn($w) => StockLedger::byItem(Store::all('stock_movements'), $w)[$itemId] ?? 0, $warehouseId ? [$warehouseId] : $scope)); ?>
    <div><span>Số tồn tra cứu</span><strong><?= qty($lookup) ?></strong></div>
    <div><span>Đối chiếu</span><?= $lookup === $running ? '<strong class="qty-pos">Khớp</strong>' : '<strong class="qty-neg">Lệch ' . qty($lookup - $running) . '</strong>' ?></div>
  </section>
<?php endif; ?>

<p class="result-count"><?= count($rows) ?> dòng phát sinh</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Thời điểm</th><th>Chứng từ</th><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="num">Phát sinh</th><?php if ($itemId !== null): ?><th class="num">Lũy kế</th><?php endif; ?></tr></thead>
  <tbody>
    <?php foreach ($rows as $m): $v = $vouchers[$m['voucher_id']]; ?>
      <tr>
        <td><?= e($m['at']) ?></td>
        <td><a href="/voucher.php?id=<?= $v['id'] ?>"><?= e($v['code']) ?></a> <?= typeBadge($v['type']) ?></td>
        <td><?= e(warehouseName($m['warehouse_id'])) ?></td>
        <td><?= e($items[$m['item_id']]['name']) ?></td>
        <td><?= e($batches[$m['batch_id']]['code']) ?></td>
        <td class="num"><?= signedQty($m['qty']) ?></td>
        <?php if ($itemId !== null): ?><td class="num"><?= qty($m['running']) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$rows): ?><p class="empty">Chưa có phát sinh.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
