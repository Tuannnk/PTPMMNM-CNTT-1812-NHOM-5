<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$q = field($_GET, 'q');
$unit = field($_GET, 'unit');
$all = Store::all('items');
$units = array_values(array_unique(array_column($all, 'unit')));
sort($units);
$items = array_filter($all, fn($i) =>
    ($q === '' || stripos($i['sku'] . ' ' . $i['name'], $q) !== false)
    && ($unit === '' || $i['unit'] === $unit));

$title = 'Mặt hàng & lô';
$active = 'items';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Mặt hàng &amp; lô</h1><p>Danh mục mặt hàng, đơn vị tính, mức tồn tối thiểu và các lô hàng.</p></div>
</header>

<form class="filters" method="get">
  <label>Tìm<input name="q" value="<?= e($q) ?>" placeholder="Mã hoặc tên"></label>
  <label>Đơn vị tính
    <select name="unit">
      <option value="">Tất cả</option>
      <?php foreach ($units as $u): ?><option value="<?= e($u) ?>" <?= $unit === $u ? 'selected' : '' ?>><?= e($u) ?></option><?php endforeach; ?>
    </select>
  </label>
  <button class="button">Lọc</button>
</form>

<p class="result-count"><?= count($items) ?> mặt hàng</p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Mã</th><th>Tên mặt hàng</th><th>ĐVT</th><th class="num">Tối thiểu</th><th>Lô hàng</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e($item['sku']) ?></td>
        <td><?= e($item['name']) ?></td>
        <td><?= e($item['unit']) ?></td>
        <td class="num"><?= qty($item['min_stock']) ?></td>
        <td>
          <ul class="chips">
            <?php foreach (Store::batchesOf($item['id']) as $b): ?>
              <li><?= e($b['code']) ?><?= $b['expiry'] ? ' · HSD ' . e($b['expiry']) : '' ?></li>
            <?php endforeach; ?>
          </ul>
        </td>
        <td><a class="text-link" href="/stock.php?<?= e(http_build_query(['q' => $item['sku'], 'view' => 'batch'])) ?>">Tồn</a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$items): ?><p class="empty">Không có mặt hàng phù hợp.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
