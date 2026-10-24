<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$scope = array_column(allowedWarehouses($user), 'id');
$movements = array_values(array_filter(Store::all('stock_movements'), fn($m) => in_array($m['warehouse_id'], $scope, true)));
$vouchers = array_values(array_filter(Store::all('vouchers'), fn($v) => in_array($v['warehouse_id'], $scope, true)));
$items = Store::indexed('items');

$drafts = array_filter($vouchers, fn($v) => $v['status'] === 'draft');
$shortageRequests = array_filter($vouchers, fn($v) => $v['type'] === 'transfer' && ($v['transfer_state'] ?? '') === 'partial');
$totalByItem = StockLedger::byItem($movements);
$low = array_filter($items, fn($i) => ($totalByItem[$i['id']] ?? 0) < $i['min_stock']);
$counting = array_filter(Store::all('stock_counts'), fn($c) => $c['status'] === 'counting' && in_array($c['warehouse_id'], $scope, true));
$recent = array_slice(array_reverse($movements), 0, 8);

$title = 'Bảng điều khiển';
$active = 'dashboard';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div>
    <h1>Bảng điều khiển</h1>
    <p>Phạm vi: <?= e(implode(', ', array_column(allowedWarehouses($user), 'name'))) ?></p>
  </div>
  <div class="actions">
    <a class="button" href="/voucher-new.php?type=in">+ Phiếu nhập</a>
    <a class="button button--ghost" href="/voucher-new.php?type=out">Phiếu xuất</a>
  </div>
</header>

<section class="stats" aria-label="Chỉ số nhanh">
  <a class="stat" href="/warehouses.php"><span>Kho phụ trách</span><strong><?= count($scope) ?></strong></a>
  <a class="stat" href="/items.php"><span>Mặt hàng</span><strong><?= count($items) ?></strong></a>
  <a class="stat" href="/vouchers.php?status=draft"><span>Chứng từ chờ duyệt</span><strong><?= count($drafts) ?></strong></a>
  <a class="stat stat--warn" href="/stock.php?low=1"><span>Dưới mức tối thiểu</span><strong><?= count($low) ?></strong></a>
  <a class="stat" href="/history.php"><span>Dòng phát sinh</span><strong><?= count($movements) ?></strong></a>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Phiếu chờ xử lý</h2>
    <?php if (!$drafts): ?>
      <p class="empty">Không có chứng từ chờ duyệt.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($drafts as $v): ?>
          <li>
            <a href="/voucher.php?id=<?= $v['id'] ?>"><strong><?= e($v['code']) ?></strong></a>
            <?= typeBadge($v['type']) ?>
            <?= voucherStatusBadge($v) ?>
            <span class="muted"><?= e(warehouseName($v['warehouse_id'])) ?><?= $v['to_warehouse_id'] ? ' → ' . e(warehouseName($v['to_warehouse_id'])) : '' ?> · <?= e($v['created_at']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($counting): ?>
      <h3>Đợt kiểm kê đang mở</h3>
      <ul class="list">
        <?php foreach ($counting as $c): ?>
          <li><a href="/count.php?id=<?= $c['id'] ?>"><strong><?= e($c['code']) ?></strong></a> <span class="muted"><?= e(warehouseName($c['warehouse_id'])) ?> · <?= count($c['lines']) ?> dòng</span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($shortageRequests): ?>
      <h3>Yêu cầu bổ sung từ kho nhận</h3>
      <ul class="list">
        <?php foreach ($shortageRequests as $v): ?>
          <li><a href="/voucher.php?id=<?= $v['id'] ?>"><strong><?= e($v['code']) ?></strong></a>
            <span class="muted"><?= e(warehouseName($v['to_warehouse_id'])) ?> đã ghi nhận thiếu hàng · xem biên bản nhận để xử lý bổ sung</span></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Phát sinh gần đây</h2>
    <table class="table table--compact">
      <thead><tr><th>Thời điểm</th><th>Kho</th><th>Mặt hàng</th><th class="num">SL</th></tr></thead>
      <tbody>
        <?php foreach ($recent as $m): ?>
          <tr>
            <td><?= e($m['at']) ?></td>
            <td><?= e(Store::find('warehouses', $m['warehouse_id'])['code']) ?></td>
            <td><?= e($items[$m['item_id']]['name']) ?></td>
            <td class="num"><?= signedQty($m['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <a class="text-link" href="/history.php">Xem toàn bộ lịch sử →</a>
  </section>
</div>

<?php if ($low): ?>
<section class="card">
  <h2>Mặt hàng dưới mức tối thiểu</h2>
  <table class="table table--compact">
    <thead><tr><th>Mã</th><th>Tên</th><th class="num">Tồn</th><th class="num">Tối thiểu</th></tr></thead>
    <tbody>
      <?php foreach (array_slice($low, 0, 8) as $i): ?>
        <tr><td><?= e($i['sku']) ?></td><td><?= e($i['name']) ?></td><td class="num qty-neg"><?= qty($totalByItem[$i['id']] ?? 0) ?></td><td class="num"><?= qty($i['min_stock']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
