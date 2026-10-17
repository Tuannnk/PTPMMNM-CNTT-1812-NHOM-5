<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$allowed = allowedWarehouses($user);
$q = field($_GET, 'q');
$lowOnly = field($_GET, 'low') === '1';
$byBatch = field($_GET, 'view') === 'batch';

$movements = Store::all('stock_movements');
$balances = StockLedger::balances($movements);
$perWarehouse = [];
foreach ($allowed as $w) {
    $perWarehouse[$w['id']] = StockLedger::byItem($movements, $w['id']);
}

$items = array_filter(Store::all('items'), function ($item) use ($q, $lowOnly, $perWarehouse) {
    $total = array_sum(array_map(fn($byItem) => $byItem[$item['id']] ?? 0, $perWarehouse));
    return ($q === '' || stripos($item['sku'] . ' ' . $item['name'], $q) !== false)
        && (!$lowOnly || $total < $item['min_stock']);
});

$title = 'Tra cứu tồn';
$active = 'stock';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Tra cứu tồn</h1><p>Số tồn được cộng từ các dòng phát sinh đã xác nhận, không nhập tay.</p></div>
  <div class="actions"><a class="button button--ghost" href="/export.php?<?= e(http_build_query(['q' => $q, 'low' => $lowOnly ? '1' : ''])) ?>">Xuất CSV</a></div>
</header>

<form class="filters" method="get">
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
      <th class="num">Tổng</th><?php if (!$byBatch): ?><th class="num">Tối thiểu</th><?php endif; ?><th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <?php if ($byBatch): ?>
        <?php foreach (Store::batchesOf($item['id']) as $batch): $sum = 0; ?>
          <tr>
            <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e($batch['code']) ?></td>
            <td><?= e($batch['expiry'] ?? '—') ?></td><td><?= e($item['unit']) ?></td>
            <?php foreach ($allowed as $w): $have = StockLedger::balanceOf($balances, $w['id'], $batch['id']); $sum += $have; ?>
              <td class="num"><?= qty($have) ?></td>
            <?php endforeach; ?>
            <td class="num"><strong><?= qty($sum) ?></strong></td>
            <td><a class="text-link" href="/history.php?item_id=<?= $item['id'] ?>">Lịch sử</a></td>
          </tr>
        <?php endforeach; ?>
      <?php else: $sum = 0; ?>
        <tr>
          <td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e($item['unit']) ?></td>
          <?php foreach ($allowed as $w): $have = $perWarehouse[$w['id']][$item['id']] ?? 0; $sum += $have; ?>
            <td class="num"><?= qty($have) ?></td>
          <?php endforeach; ?>
          <td class="num <?= $sum < $item['min_stock'] ? 'qty-neg' : '' ?>"><strong><?= qty($sum) ?></strong></td>
          <td class="num"><?= qty($item['min_stock']) ?></td>
          <td><a class="text-link" href="/history.php?item_id=<?= $item['id'] ?>">Lịch sử</a></td>
        </tr>
      <?php endif; ?>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$items): ?><p class="empty">Không có mặt hàng phù hợp.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
