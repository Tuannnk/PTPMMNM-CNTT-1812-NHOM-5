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
$totalByItemByWarehouse = [];
foreach ($scope as $warehouseId) {
    $totalByItemByWarehouse[$warehouseId] = StockLedger::byItem($movements, $warehouseId);
}
$low = array_filter($items, fn($i) => ($totalByItem[$i['id']] ?? 0) < $i['min_stock']);
$counting = array_filter(Store::all('stock_counts'), fn($c) => $c['status'] === 'counting' && in_array($c['warehouse_id'], $scope, true));
$recent = array_slice(array_reverse($movements), 0, 8);
$roleIntro = [
    'admin' => 'Quản lý danh mục, theo dõi tồn kho toàn hệ thống và tra cứu mặt hàng theo từng kho.',
    'staff' => 'Theo dõi tồn kho, chứng từ và các công việc tại kho được phân công.',
    'approver' => 'Ưu tiên xử lý chứng từ chờ duyệt và theo dõi các đợt kiểm kê trong phạm vi phụ trách.',
    'counter' => 'Theo dõi tồn kho và các đợt kiểm kê tại kho được phân công.',
    'central_manager' => 'Theo dõi hoạt động kho tổng, điều phối hàng và xử lý các chứng từ cần thiết.',
    'regional_manager' => 'Theo dõi tồn kho và hoạt động của các kho địa điểm được phân công.',
    'sales_user' => 'Theo dõi đơn phân phối và hàng hóa tại điểm bán được phân quyền.',
];
$roleLinks = [
    'admin' => [['/items.php', 'Quản lý mặt hàng'], ['/warehouses.php', 'Quản lý kho'], ['/access.php', 'Tài khoản & phân quyền']],
    'staff' => [['/stock.php', 'Tra cứu tồn'], ['/vouchers.php', 'Chứng từ kho'], ['/history.php', 'Lịch sử phát sinh']],
    'approver' => [['/vouchers.php?status=draft', 'Duyệt chứng từ'], ['/counts.php', 'Theo dõi kiểm kê'], ['/history.php', 'Lịch sử phát sinh']],
    'counter' => [['/counts.php', 'Kiểm kê kho'], ['/stock.php', 'Tra cứu tồn'], ['/history.php', 'Lịch sử phát sinh']],
    'central_manager' => [['/stock.php', 'Tồn kho các kho'], ['/vouchers.php', 'Chứng từ'], ['/sales-orders.php', 'Đơn phân phối']],
    'regional_manager' => [['/stock.php', 'Tồn kho phụ trách'], ['/vouchers.php', 'Chứng từ'], ['/sales-orders.php', 'Đơn phân phối']],
    'sales_user' => [['/sales-orders.php', 'Đơn phân phối'], ['/sales-stock.php', 'Tồn tại điểm bán']],
];
$stockQuery = field($_GET, 'stock_q');
$stockWarehouseId = intField($_GET, 'warehouse_id');
$stockWarehouses = allowedWarehouses($user);
$stockItems = [];
if ($user['role'] === 'admin') {
    $allWarehouses = Store::all('warehouses');
    $stockWarehouses = array_values(array_filter($allWarehouses, fn($w) => $w['active']));
    if ($stockWarehouseId !== null && !in_array($stockWarehouseId, array_column($stockWarehouses, 'id'), true)) $stockWarehouseId = null;
    foreach (Store::all('items') as $item) {
        if ($stockQuery !== '' && stripos($item['sku'].' '.$item['name'], $stockQuery) === false) continue;
        $itemStock = [];
        foreach ($stockWarehouses as $warehouse) {
            if ($stockWarehouseId !== null && $warehouse['id'] !== $stockWarehouseId) continue;
            $itemStock[$warehouse['id']] = $totalByItemByWarehouse[$warehouse['id']][$item['id']] ?? 0;
        }
        $stockItems[] = ['item' => $item, 'stock' => $itemStock];
    }
}

$title = 'Bảng điều khiển';
$active = 'dashboard';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div>
    <h1>Bảng điều khiển</h1>
    <p><?= e($roleIntro[$user['role']] ?? 'Tổng quan hoạt động và tồn kho trong phạm vi được phân quyền.') ?> Phạm vi: <?= e(implode(', ', array_column(allowedWarehouses($user), 'name'))) ?></p>
  </div>
  <?php if (canCreateVoucher($user)): ?><div class="actions">
    <a class="button" href="/voucher-new.php?type=in">+ Phiếu nhập</a>
    <a class="button button--ghost" href="/voucher-new.php?type=out">Phiếu xuất</a>
  </div><?php endif; ?>
</header>

<section class="stats" aria-label="Chỉ số nhanh">
  <a class="stat" href="/warehouses.php"><span>Kho phụ trách</span><strong><?= count($scope) ?></strong></a>
  <a class="stat" href="/items.php"><span>Mặt hàng</span><strong><?= count($items) ?></strong></a>
  <a class="stat" href="/vouchers.php?status=draft"><span>Chứng từ chờ duyệt</span><strong><?= count($drafts) ?></strong></a>
  <a class="stat stat--warn" href="/stock.php?low=1"><span>Dưới mức tối thiểu</span><strong><?= count($low) ?></strong></a>
  <a class="stat" href="/history.php"><span>Dòng phát sinh</span><strong><?= count($movements) ?></strong></a>
</section>

<?php if (!empty($roleLinks[$user['role']])): ?>
<section class="card">
  <h2>Truy cập nhanh · <?= e(ROLE_LABELS[$user['role']]) ?></h2>
  <div class="actions"><?php foreach ($roleLinks[$user['role']] as [$href, $label]): ?><a class="button button--ghost" href="<?= e($href) ?>"><?= e($label) ?></a><?php endforeach; ?></div>
</section>
<?php endif; ?>

<?php if ($user['role'] === 'admin'): ?>
<section class="card">
  <h2>Tra cứu nhanh tồn kho</h2>
  <p class="muted">Tìm mặt hàng để xem số lượng hiện có tại từng kho. Số liệu được tính từ các phát sinh đã xác nhận.</p>
  <form class="filters" method="get">
    <label>Mã hoặc tên mặt hàng<input name="stock_q" value="<?= e($stockQuery) ?>" placeholder="Ví dụ: SKU hoặc tên"></label>
    <label>Kho<select name="warehouse_id"><option value="">Tất cả kho</option><?php foreach ($stockWarehouses as $w): ?><option value="<?= $w['id'] ?>" <?= $stockWarehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['code'].' · '.$w['name']) ?></option><?php endforeach; ?></select></label>
    <button class="button">Tra cứu</button>
    <?php if ($stockQuery !== '' || $stockWarehouseId !== null): ?><a class="button button--ghost" href="/">Xóa lọc</a><?php endif; ?>
  </form>
  <?php if ($stockQuery !== '' || $stockWarehouseId !== null): ?>
  <?php if ($stockItems): ?>
  <div class="table-wrap"><table class="table table--compact">
    <thead><tr><th>Mã</th><th>Mặt hàng</th><?php foreach ($stockWarehouses as $w): if ($stockWarehouseId !== null && $w['id'] !== $stockWarehouseId) continue; ?><th class="num"><?= e($w['code']) ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody><?php foreach ($stockItems as $row): ?><tr><td><?= e($row['item']['sku']) ?></td><td><?= e($row['item']['name']) ?></td><?php foreach ($row['stock'] as $qty): ?><td class="num <?= $qty <= 0 ? 'muted' : '' ?>"><?= qty($qty) ?></td><?php endforeach; ?><td><a class="text-link" href="/history.php?item_id=<?= $row['item']['id'] ?>">Lịch sử</a></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php else: ?><p class="empty">Không tìm thấy mặt hàng phù hợp.</p><?php endif; ?>
  <?php else: ?><p class="empty">Nhập mã hoặc tên mặt hàng, hoặc chọn kho để bắt đầu tra cứu.</p><?php endif; ?>
  <a class="text-link" href="/stock.php">Mở trang tra cứu tồn đầy đủ →</a>
</section>
<?php endif; ?>

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
            <span class="muted"><?= e(warehouseName($v['warehouse_id'])) ?><?= $v['to_warehouse_id'] ? ' → ' . e(warehouseName($v['to_warehouse_id'])) : '' ?> · <?= e(dateOnly($v['created_at'])) ?></span>
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
            <td><?= e(dateOnly($m['at'])) ?></td>
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
