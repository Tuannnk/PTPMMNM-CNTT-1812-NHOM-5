<?php
// Quản trị viên: tài khoản, danh mục, chất lượng dữ liệu và tra cứu tồn nhanh.
$overview = Dashboard::adminOverview();
$activeUsers = array_sum(array_column($overview['roles'], 'active'));
$lockedUsers = array_sum(array_column($overview['roles'], 'locked'));
$warnings = [];
if ($overview['users_without_scope']) $warnings[] = [$overview['users_without_scope'] . ' tài khoản nội bộ đang hoạt động chưa được gán kho', '/access.php'];
if ($overview['items_without_batch']) $warnings[] = [$overview['items_without_batch'] . ' mặt hàng đang dùng chưa có lô nào', '/items.php'];
if ($overview['expiring']) $warnings[] = [count($overview['expiring']) . ' lô còn tồn sẽ hết hạn trong 30 ngày', '#lo-sap-het-han'];

$locationStock = Dashboard::stockByLocation();
$itemsAnywhere = Dashboard::itemsInStockAnywhere();
$locationTypes = [
    'CENTRAL' => 'Kho tổng', 'REGIONAL' => 'Kho địa điểm',
    'AGENT' => 'Đại lý', 'STORE' => 'Cửa hàng', 'SUPERMARKET' => 'Siêu thị',
];

$stockQuery = field($_GET, 'stock_q');
$stockWarehouseId = intField($_GET, 'warehouse_id');
$stockWarehouses = array_values(array_filter(Store::all('warehouses'), fn($w) => $w['active']));
if ($stockWarehouseId !== null && !in_array($stockWarehouseId, array_column($stockWarehouses, 'id'), true)) $stockWarehouseId = null;
$shownWarehouses = array_values(array_filter($stockWarehouses, fn($w) => $stockWarehouseId === null || $w['id'] === $stockWarehouseId));
$stockItems = [];
if ($stockQuery !== '' || $stockWarehouseId !== null) {
    $balanceQuery = Database::connection()->prepare(
        'SELECT item_id, warehouse_id, SUM(qty)::bigint AS qty FROM stock_movements WHERE warehouse_id IS NOT NULL GROUP BY item_id, warehouse_id'
    );
    $balanceQuery->execute();
    $balances = [];
    foreach ($balanceQuery->fetchAll() as $b) $balances[(int)$b['item_id']][(int)$b['warehouse_id']] = (int)$b['qty'];
    foreach (Store::all('items') as $item) {
        if ($stockQuery !== '' && stripos($item['sku'] . ' ' . $item['name'], $stockQuery) === false) continue;
        $stockItems[] = ['item' => $item, 'stock' => array_map(fn($w) => $balances[$item['id']][$w['id']] ?? 0, $shownWarehouses)];
    }
}
?>
<section class="stats" aria-label="Chỉ số nhanh">
  <?= dashStat('Tài khoản hoạt động', $activeUsers, '/access.php', '', $lockedUsers ? $lockedUsers . ' đã khóa' : '') ?>
  <?= dashStat('Kho đang hoạt động', $overview['warehouses'], '/warehouses.php') ?>
  <?= dashStat('Mặt hàng', $overview['items'], '/items.php') ?>
  <?= dashStat('Nhà cung cấp', $overview['suppliers'], '/suppliers.php') ?>
  <?= dashStat('Điểm bán', $overview['sales_points'], '/sales-points.php') ?>
  <?= dashStat('Cảnh báo dữ liệu', count($warnings), '#canh-bao', $warnings ? 'warn' : 'ok') ?>
</section>

<section class="card" id="ton-theo-dia-diem">
  <h2>Tồn kho theo kho và điểm bán</h2>
  <p class="muted">Số mặt hàng, số lô và tổng số lượng đang có tại từng địa điểm, tính từ sổ phát sinh. Hàng đang trên đường vận chuyển chưa được tính vào địa điểm nào.</p>
  <div class="table-wrap"><table class="table table--compact">
    <thead><tr><th>Địa điểm</th><th>Loại</th><th class="num">Số mặt hàng</th><th class="num">Số lô</th><th class="num">Tổng số lượng</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($locationStock as $loc):
        $isWarehouse = $loc['kind'] === 'warehouse';
        $href = $isWarehouse ? '/stock.php?warehouse_id=' . (int)$loc['id'] : '/sales-stock.php?sales_point_id=' . (int)$loc['id'];
        $empty = (int)$loc['qty'] === 0; ?>
      <tr>
        <td><strong><?= e($loc['name']) ?></strong> <span class="muted"><?= e($loc['code']) ?></span></td>
        <td><?= e($locationTypes[(string)$loc['subtype']] ?? ($isWarehouse ? 'Kho' : 'Điểm bán')) ?></td>
        <td class="num <?= $empty ? 'muted' : '' ?>"><?= qty((int)$loc['items']) ?></td>
        <td class="num <?= $empty ? 'muted' : '' ?>"><?= qty((int)$loc['batches']) ?></td>
        <td class="num <?= $empty ? 'muted' : '' ?>"><?= qty((int)$loc['qty']) ?></td>
        <td><a class="text-link" href="<?= e($href) ?>">Xem tồn</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot><tr>
      <th>Toàn hệ thống</th><th class="muted"><?= count($locationStock) ?> địa điểm</th>
      <th class="num"><?= qty($itemsAnywhere) ?></th>
      <th class="num"><?= qty(array_sum(array_map(fn($l) => (int)$l['batches'], $locationStock))) ?></th>
      <th class="num"><?= qty(array_sum(array_map(fn($l) => (int)$l['qty'], $locationStock))) ?></th><th></th>
    </tr></tfoot>
  </table></div>
</section>

<div class="grid-2">
  <section class="card">
    <h2>Tài khoản theo vai trò</h2>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>Vai trò</th><th class="num">Hoạt động</th><th class="num">Đã khóa</th></tr></thead>
      <tbody><?php foreach (ROLE_LABELS as $key => $label): $r = $overview['roles'][$key] ?? ['active' => 0, 'locked' => 0]; ?>
        <tr><td><?= e($label) ?></td><td class="num"><?= qty($r['active']) ?></td><td class="num <?= $r['locked'] ? '' : 'muted' ?>"><?= qty($r['locked']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <a class="text-link" href="/access.php">Quản lý tài khoản &amp; phân quyền →</a>
  </section>

  <section class="card" id="canh-bao">
    <h2>Cảnh báo dữ liệu</h2>
    <?php if (!$warnings): ?><p class="empty">Không có cảnh báo. Danh mục và phân quyền đang đầy đủ.</p><?php else: ?>
      <ul class="list"><?php foreach ($warnings as [$text, $href]): ?>
        <li><span class="badge badge--wait">Cần xem</span> <?= e($text) ?> <a class="text-link" href="<?= e($href) ?>">Xử lý →</a></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
    <h3 id="lo-sap-het-han">Lô còn tồn sắp hết hạn (30 ngày)</h3>
    <?php if (!$overview['expiring']): ?><p class="empty">Không có lô nào sắp hết hạn.</p><?php else: ?>
      <ul class="list"><?php foreach ($overview['expiring'] as $b): ?>
        <li><strong><?= e($b['code']) ?></strong> <span class="muted"><?= e($b['sku'] . ' — ' . $b['name']) ?> · tồn <?= qty((int)$b['qty']) ?></span>
          <span class="badge <?= $b['expiry'] < date('Y-m-d') ? 'badge--rejected' : 'badge--wait' ?>"><?= $b['expiry'] < date('Y-m-d') ? 'đã hết hạn' : 'hạn' ?> <?= e(dateOnly($b['expiry'])) ?></span></li>
      <?php endforeach; ?></ul>
    <?php endif; ?>
  </section>
</div>

<section class="card">
  <h2>Tra cứu nhanh tồn kho</h2>
  <p class="muted">Tìm mặt hàng để xem số lượng hiện có tại từng kho. Số liệu được tính từ các phát sinh đã xác nhận.</p>
  <form class="filters" method="get">
    <label>Mã hoặc tên mặt hàng<input name="stock_q" value="<?= e($stockQuery) ?>" placeholder="Ví dụ: SKU hoặc tên"></label>
    <label>Kho<select name="warehouse_id"><option value="">Tất cả kho</option><?php foreach ($stockWarehouses as $w): ?><option value="<?= $w['id'] ?>" <?= $stockWarehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select></label>
    <button class="button">Tra cứu</button>
    <?php if ($stockQuery !== '' || $stockWarehouseId !== null): ?><a class="button button--ghost" href="/">Xóa lọc</a><?php endif; ?>
  </form>
  <?php if ($stockQuery !== '' || $stockWarehouseId !== null): ?>
    <?php if ($stockItems): ?>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>Mã</th><th>Mặt hàng</th><?php foreach ($shownWarehouses as $w): ?><th class="num"><?= e($w['code']) ?></th><?php endforeach; ?><th></th></tr></thead>
      <tbody><?php foreach ($stockItems as $row): ?><tr><td><?= e($row['item']['sku']) ?></td><td><?= e($row['item']['name']) ?></td><?php foreach ($row['stock'] as $q): ?><td class="num <?= $q <= 0 ? 'muted' : '' ?>"><?= qty($q) ?></td><?php endforeach; ?><td><a class="text-link" href="/history.php?item_id=<?= $row['item']['id'] ?>">Lịch sử</a></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php else: ?><p class="empty">Không tìm thấy mặt hàng phù hợp.</p><?php endif; ?>
  <?php else: ?><p class="empty">Nhập mã hoặc tên mặt hàng, hoặc chọn kho để bắt đầu tra cứu.</p><?php endif; ?>
  <a class="text-link" href="/stock.php">Mở trang tra cứu tồn đầy đủ →</a>
</section>
