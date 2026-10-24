<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$scope = array_column(allowedWarehouses($user), 'id');
$counts = array_filter(Store::all('stock_counts'), fn($c) => in_array($c['warehouse_id'], $scope, true));
$canCreate = in_array($user['role'], ['counter', 'approver'], true);
$createError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $warehouseId = intField($_POST, 'warehouse_id');
    if (!$canCreate || $warehouseId === null || !canAccessWarehouse($user, $warehouseId)) {
        forbid();
    }
    try {
        $countId = StockCountService::create($user, $warehouseId);
        flash('Đã tạo đợt kiểm kê và chụp tồn hiện tại của các lô.');
        redirect('/count.php?id=' . $countId);
    } catch (DomainException $e) {
        $createError = $e->getMessage() === 'COUNT_NEGATIVE_SYSTEM_STOCK'
            ? 'Không thể tạo đợt kiểm kê vì kho đang có số tồn âm. Hãy đối chiếu sổ phát sinh trước.'
            : 'Bạn không có quyền tạo đợt kiểm kê tại kho này.';
    } catch (Throwable $e) {
        error_log('Stock count creation failed: ' . $e->getMessage());
        $createError = 'Không lưu được đợt kiểm kê. Vui lòng thử lại hoặc kiểm tra log máy chủ.';
    }
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

<?php if ($createError !== ''): ?><div class="error-summary" role="alert"><?= e($createError) ?></div><?php endif; ?>

<div class="table-wrap">
<table class="table">
  <thead><tr><th>Mã</th><th>Kho</th><th>Ngày tạo</th><th>Người phụ trách</th><th class="num">Số dòng</th><th class="num">Dòng lệch</th><th>Trạng thái</th></tr></thead>
  <tbody>
    <?php foreach (array_reverse($counts) as $c):
        $diffs = array_filter($c['lines'], fn($l) => $l['actual_qty'] !== null && $l['actual_qty'] !== $l['system_qty']); ?>
      <tr>
        <td><a href="/count.php?id=<?= $c['id'] ?>"><strong><?= e($c['code']) ?></strong></a></td>
        <td><?= e(warehouseName($c['warehouse_id'])) ?></td>
        <td><?= e(dateOnly($c['created_at'])) ?></td>
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
