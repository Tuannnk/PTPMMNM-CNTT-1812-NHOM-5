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
$closeError = '';
$lines = array_map(fn($l) => $l + ['diff' => $l['actual_qty'] === null ? null : $l['actual_qty'] - $l['system_qty']], $count['lines']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (!$editable) {
        forbid('COUNT_LOCKED');
    }
    $result = validateCount($_POST, $count);
    $lines = $result['data']['lines'];
    if (!$result['errors']) {
        try {
            $outcome = StockCountService::close($count['id'], $user, $lines);
            flash($outcome['already_closed']
                ? 'Đợt kiểm kê đã được chốt trước đó.'
                : 'Đã chốt kiểm kê, tạo chứng từ điều chỉnh và cập nhật sổ tồn trong cùng giao dịch.');
            redirect('/count.php?id=' . $count['id']);
        } catch (DomainException $e) {
            if ($e->getMessage() === 'COUNT_EDIT_FORBIDDEN') forbid('COUNT_EDIT_FORBIDDEN');
            $closeError = match ($e->getMessage()) {
                'REASON_REQUIRED' => 'Mỗi dòng có chênh lệch cần ghi lý do.',
                'COUNT_LINES_INVALID' => 'Dữ liệu kiểm kê không hợp lệ. Tải lại trang và nhập lại số thực tế.',
                default => 'Đợt kiểm kê đã thay đổi hoặc không thể chốt. Tải lại trang rồi thử lại.',
            };
        } catch (Throwable $e) {
            error_log(sprintf('Stock count close failed for id=%d: %s', $count['id'], $e->getMessage()));
            $closeError = 'Không chốt được kiểm kê. Giao dịch đã được hoàn tác; vui lòng thử lại.';
        }
    }
}
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
    <p><?= e(warehouseName($count['warehouse_id'])) ?> · tạo <?= e($count['created_at']) ?> bởi <?= e(userName($count['created_by'])) ?></p>
  </div>
  <?php if ($adjust): ?>
    <div class="actions"><a class="button button--ghost" href="/voucher.php?id=<?= $adjust['id'] ?>">Chứng từ điều chỉnh <?= e($adjust['code']) ?></a></div>
  <?php endif; ?>
</header>

<?php if ($result !== null): ?>
  <?php $errors = $result['errors']; require __DIR__ . '/../templates/errors.php'; ?>
<?php endif; ?>
<?php if ($closeError !== ''): ?><div class="error-summary" role="alert"><?= e($closeError) ?></div><?php endif; ?>

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
