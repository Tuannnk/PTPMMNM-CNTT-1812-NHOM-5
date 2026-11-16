<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$allowed = allowedWarehouses($user);
$q = field($_GET, 'q');
$lowOnly = field($_GET, 'low') === '1';
$byBatch = field($_GET, 'view') === 'batch';
$selectedWarehouseId = intField($_GET, 'warehouse_id');
if ($selectedWarehouseId !== null) {
    $selectedWarehouse = array_values(array_filter($allowed, fn($w) => $w['id'] === $selectedWarehouseId));
    if (!$selectedWarehouse) forbid();
    $allowed = $selectedWarehouse;
}

$movements = Store::all('stock_movements');
$balances = StockLedger::balances($movements);
$perWarehouse = [];
foreach ($allowed as $w) {
    $perWarehouse[$w['id']] = StockLedger::byItem($movements, $w['id']);
}

$items = array_filter(Store::all('items'), function ($item) use ($q, $lowOnly, $perWarehouse, $selectedWarehouseId) {
    $total = array_sum(array_map(fn($byItem) => $byItem[$item['id']] ?? 0, $perWarehouse));
    return ($selectedWarehouseId === null || $total > 0)
        && ($q === '' || stripos($item['sku'] . ' ' . $item['name'], $q) !== false)
        && (!$lowOnly || $total < $item['min_stock']);
});

$title = 'Tra cứu tồn';
$active = 'stock';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1><?= $selectedWarehouseId !== null ? 'Mặt hàng còn tồn · '.e($allowed[0]['name']) : 'Tra cứu tồn' ?></h1><p>Số tồn được cộng từ các dòng phát sinh đã xác nhận, không nhập tay.<?= $selectedWarehouseId !== null ? ' Chỉ hiển thị mặt hàng đang có số lượng lớn hơn 0 tại kho này.' : '' ?></p></div>
  <div class="actions"><a class="button button--ghost" href="/export.php?<?= e(http_build_query(['q' => $q, 'low' => $lowOnly ? '1' : '', 'warehouse_id' => $selectedWarehouseId])) ?>">Xuất CSV</a></div>
</header>

<form class="filters" method="get">
  <?php if ($selectedWarehouseId !== null): ?><input type="hidden" name="warehouse_id" value="<?= $selectedWarehouseId ?>"><?php endif; ?>
  <label>Tìm mặt hàng<input name="q" value="<?= e($q) ?>" placeholder="Mã hoặc tên"></label>
  <label>Hiển thị
    <select name="view">
      <option value="">Theo mặt hàng</option>
      <option value="batch" <?= $byBatch ? 'selected' : '' ?>>Chi tiết theo lô</option>
    </select>
  </label>
  <label class="check"><input type="checkbox" name="low" value="1" <?= $lowOnly ? 'checked' : '' ?>> Dưới mức tối thiểu</label>
  <button class="button">Lọc</button>
</form>

<div class="table-wrap">
<table class="table">
  <thead>
    <tr>
      <th>Mã</th><th>Mặt hàng</th><?php if ($byBatch): ?><th>Lô</th><th>Hạn dùng</th><?php endif; ?><th>ĐVT</th>
      <?php foreach ($allowed as $w): ?><th class="num"><?= e($w['code']) ?></th><?php endforeach; ?>
      <th class="num">Tổng</th><th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php if ($byBatch): ?>
        <?php foreach (Store::batchesOf($item['id']) as $batch): $sum = 0; ?>
          <?php if ($selectedWarehouseId !== null && StockLedger::balanceOf($balances, $selectedWarehouseId, $batch['id']) <= 0) continue; ?>
          <tr>
            <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e($batch['code']) ?></td>
            <td><?= e($batch['expiry'] ?? '—') ?></td><td><?= e($item['unit_name']) ?></td>
            <?php foreach ($allowed as $w): $have = StockLedger::balanceOf($balances, $w['id'], $batch['id']); $sum += $have; ?>
              <td class="num"><?= qty($have) ?></td>
            <?php endforeach; ?>
            <td class="num"><strong><?= qty($sum) ?></strong></td>
            <td><a class="text-link" href="/history.php?item_id=<?= $item['id'] ?>">Lịch sử</a></td>
          </tr>
        <?php endforeach; ?>
      <?php else: $sum = 0; ?>
        <tr>
          <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e($item['unit_name']) ?></td>
          <?php foreach ($allowed as $w): $have = $perWarehouse[$w['id']][$item['id']] ?? 0; $sum += $have; ?>
            <td class="num"><?= qty($have) ?></td>
          <?php endforeach; ?>
          <td class="num <?= $sum < $item['min_stock'] ? 'qty-neg' : '' ?>"><strong><?= qty($sum) ?></strong></td>
          <td><a class="text-link" href="/history.php?item_id=<?= $item['id'] ?>">Lịch sử</a></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$items): ?><p class="empty">Không có mặt hàng phù hợp.</p><?php endif; ?>
<?php if ($selectedWarehouseId !== null): ?><p><a class="text-link" href="/warehouses.php">← Quay lại danh sách kho</a></p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
