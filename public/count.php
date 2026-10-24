<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$count = Store::find('stock_counts', intField($_GET, 'id') ?? 0);
if ($count === null) {
    http_response_code(404);
    exit('COUNT_NOT_FOUND');
}
if (!canAccessWarehouse($user, $count['warehouse_id'])) {
    forbid();
}
$editable = $count['status'] === 'counting' && in_array($user['role'], ['counter', 'approver'], true);

$result = null;
$lines = array_map(fn($l) => $l + ['diff' => $l['actual_qty'] === null ? null : $l['actual_qty'] - $l['system_qty']], $count['lines']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (!$editable) {
        forbid('COUNT_LOCKED');
    }
    $result = validateCount($_POST, $count);
    $lines = $result['data']['lines'];
}
$adjustLines = array_filter($lines, fn($l) => $l['diff'] !== null && $l['diff'] !== 0);

$items = Store::indexed('items');
$batches = Store::indexed('batches');
$adjust = $count['adjust_voucher_id'] ? Store::find('vouchers', $count['adjust_voucher_id']) : null;
$title = $count['code'];
$active = 'counts';
require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/counts.php">Kiểm kê</a> / <?= e($count['code']) ?></nav>
<header class="page-head">
  <div>
    <h1><?= e($count['code']) ?> <?= $count['status'] === 'closed' ? '<span class="badge badge--ok">Đã chốt</span>' : '<span class="badge badge--wait">Đang kiểm</span>' ?></h1>
    <p><?= e(warehouseName($count['warehouse_id'])) ?> · tạo <?= e(dateOnly($count['created_at'])) ?> bởi <?= e(userName($count['created_by'])) ?></p>
  </div>
  <?php if ($adjust): ?>
    <div class="actions"><a class="button button--ghost" href="/voucher.php?id=<?= $adjust['id'] ?>">Chứng từ điều chỉnh <?= e($adjust['code']) ?></a></div>
  <?php endif; ?>
</header>

<?php if ($result !== null): ?>
  <?php $errors = $result['errors']; require __DIR__ . '/../templates/errors.php'; ?>
  <?php if (!$result['errors']): ?>
    <section class="preview" role="status">
      <h2>Chênh lệch đã xác nhận</h2>
      <?php if ($adjustLines): ?>
        <p>Sẽ lập chứng từ điều chỉnh <?= count($adjustLines) ?> dòng (tổng <?= signedQty(array_sum(array_column($adjustLines, 'diff'))) ?>) và chốt đợt kiểm kê. Sau điều chỉnh, tồn khớp số thực tế.</p>
      <?php else: ?>
        <p>Không có chênh lệch; chốt đợt kiểm kê không cần chứng từ điều chỉnh.</p>
      <?php endif; ?>
      <p class="muted">Giao diện mẫu: chưa ghi CSDL.</p>
    </section>
  <?php endif; ?>
<?php endif; ?>

<form method="post" class="card form">
  <?= csrfInput() ?>
  <p class="muted">Số hệ thống chụp tại thời điểm tạo đợt. Dòng có chênh lệch bắt buộc ghi lý do.</p>
  <div class="table-wrap">
  <table class="table table--form">
    <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Hệ thống</th><th class="num">Thực tế</th><th class="num">Chênh lệch</th><th>Lý do</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $i => $l): ?>
        <tr class="<?= $l['diff'] ? 'row-diff' : '' ?>">
          <td><?= e($items[$l['item_id']]['name']) ?></td>
          <td><?= e($batches[$l['batch_id']]['code']) ?></td>
          <td class="num"><?= qty($l['system_qty']) ?></td>
          <td class="num">
            <?php if ($editable): ?>
              <input type="number" name="actual[]" min="0" step="1" inputmode="numeric" value="<?= $l['actual_qty'] ?? '' ?>" aria-label="Số thực tế dòng <?= $i + 1 ?>">
            <?php else: ?><?= $l['actual_qty'] === null ? '—' : qty($l['actual_qty']) ?><?php endif; ?>
          </td>
          <td class="num"><?= $l['diff'] === null ? '—' : signedQty($l['diff']) ?></td>
          <td>
            <?php if ($editable): ?>
              <input name="reason[]" maxlength="255" value="<?= e($l['reason']) ?>" aria-label="Lý do dòng <?= $i + 1 ?>" placeholder="Bắt buộc khi lệch">
            <?php else: ?><?= e($l['reason'] ?: '—') ?><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($editable): ?>
    <div class="form-actions"><button class="button">Tính chênh lệch và chốt</button></div>
  <?php elseif ($count['status'] === 'counting'): ?>
    <p class="muted">Chỉ người phụ trách kiểm kê hoặc người duyệt được nhập số thực tế.</p>
  <?php endif; ?>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>
