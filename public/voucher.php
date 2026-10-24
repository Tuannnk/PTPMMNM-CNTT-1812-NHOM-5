<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$voucher = Store::find('vouchers', intField($_GET, 'id') ?? 0);
if ($voucher === null) {
    http_response_code(404);
    exit('VOUCHER_NOT_FOUND');
}
$related = [$voucher['warehouse_id'], $voucher['to_warehouse_id']];
if (!array_filter($related, fn($id) => $id !== null && canAccessWarehouse($user, $id))) {
    forbid();
}

$lines = Store::linesOf($voucher['id']);
$items = Store::indexed('items');
$batches = Store::indexed('batches');
$balances = StockLedger::balances(Store::all('stock_movements'));
$planned = StockLedger::movementsFor($voucher, $lines);
$negative = [];
$rejectError = '';
$rejectReasonInput = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $decision = field($_POST, 'decision') ?: 'confirm';
    if (in_array($voucher['status'], ['confirmed', 'rejected'], true) && $user['role'] === 'approver'
        && canAccessWarehouse($user, $voucher['warehouse_id'])
        && ($voucher['type'] !== 'transfer' || canAccessWarehouse($user, $voucher['to_warehouse_id']))) {
        flash($voucher['code'] . ($voucher['status'] === 'confirmed' ? ' đã được xác nhận trước đó.' : ' đã bị từ chối trước đó.'));
        redirect('/voucher.php?id=' . $voucher['id']);
    }
    if (!canConfirm($user, $voucher)) {
        forbid($decision === 'reject' ? 'REJECT_FORBIDDEN' : 'CONFIRM_FORBIDDEN');
    }
    if ($decision === 'reject') {
        $rejectReasonInput = field($_POST, 'reject_reason');
        if ($rejectReasonInput === '' || !textFits($rejectReasonInput, 255)) {
            $rejectError = $rejectReasonInput === '' ? 'Vui lòng nhập lý do không duyệt.' : 'Lý do không được dài quá 255 ký tự.';
        } else {
            try {
                $rejection = VoucherService::reject($voucher['id'], $user, $rejectReasonInput);
                flash($rejection['already_rejected']
                    ? $voucher['code'] . ' đã bị từ chối trước đó.'
                    : sprintf('Đã từ chối %s. Phiếu không tạo phát sinh tồn kho.', $voucher['code']));
                redirect('/voucher.php?id=' . $voucher['id']);
            } catch (Throwable $error) {
                if ($error->getMessage() === 'REJECT_FORBIDDEN') {
                    forbid('REJECT_FORBIDDEN');
                }
                error_log(sprintf('Voucher rejection failed for id=%d: %s', $voucher['id'], $error->getMessage()));
                $rejectError = 'Không từ chối được phiếu do lỗi khi lưu. Vui lòng thử lại hoặc kiểm tra log máy chủ.';
            }
        }
    } elseif ($decision !== 'confirm') {
        http_response_code(400);
        exit('INVALID_VOUCHER_DECISION');
    } else {
        try {
            $confirmation = VoucherService::confirm($voucher['id'], $user);
            $negative = $confirmation['negative'];
            if (!$negative) {
                flash($confirmation['already_confirmed']
                    ? $voucher['code'] . ' đã được xác nhận trước đó.'
                    : sprintf('Đã xác nhận %s và ghi phát sinh vào sổ.', $voucher['code']));
                redirect('/voucher.php?id=' . $voucher['id']);
            }
        } catch (Throwable $error) {
            if ($error->getMessage() === 'CONFIRM_FORBIDDEN') {
                forbid('CONFIRM_FORBIDDEN');
            }
            error_log(sprintf('Voucher confirmation failed for id=%d: %s', $voucher['id'], $error->getMessage()));
            flash('Không xác nhận được phiếu do lỗi khi ghi sổ. Vui lòng thử lại hoặc kiểm tra log máy chủ.');
            redirect('/voucher.php?id=' . $voucher['id']);
        }
    }
}

$movements = Store::movementsOf($voucher['id']);
$ref = $voucher['ref_id'] ? Store::find('vouchers', $voucher['ref_id']) : null;
$title = $voucher['code'];
$active = 'vouchers';
require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/vouchers.php">Chứng từ</a> / <?= e($voucher['code']) ?></nav>
<header class="page-head">
  <div>
    <h1><?= e($voucher['code']) ?> <?= typeBadge($voucher['type']) ?> <?= statusBadge($voucher['status']) ?></h1>
    <p><?= e($voucher['note']) ?></p>
  </div>
  <?php if (canConfirm($user, $voucher)): ?>
    <div class="actions">
      <form method="post">
        <?= csrfInput() ?><input type="hidden" name="decision" value="confirm">
        <button class="button">Phê duyệt và ghi sổ</button>
      </form>
      <form method="post" class="reject-form">
        <?= csrfInput() ?><input type="hidden" name="decision" value="reject">
        <label class="label" for="reject_reason">Lý do không duyệt (bắt buộc)</label>
        <textarea id="reject_reason" name="reject_reason" rows="2" maxlength="255" required><?= e($rejectReasonInput) ?></textarea>
        <?php if ($rejectError !== ''): ?><p class="field-error" role="alert"><?= e($rejectError) ?></p><?php endif; ?>
        <button class="button button--ghost">Không duyệt phiếu</button>
      </form>
    </div>
  <?php elseif ($voucher['status'] === 'draft' && $voucher['type'] !== 'transfer' && $user['role'] !== 'approver'): ?>
    <p class="muted">Phiếu chờ người có vai trò “Người duyệt chứng từ” phê duyệt. Đăng nhập bằng tài khoản <strong>duyet</strong> để tiếp tục.</p>
  <?php endif; ?>
</header>

<?php if ($negative): ?>
  <div class="error-summary" role="alert">
    <strong>Không thể xác nhận: số tồn sẽ âm sau khi áp dụng phiếu</strong>
    <ul>
      <?php foreach ($negative as $key => $after): [$w, $b] = array_map('intval', explode(':', $key)); ?>
        <li><?= e(warehouseName($w)) ?> · lô <?= e($batches[$b]['code']) ?>: còn <?= qty($after) ?> sau khi xuất <code>STOCK_NEGATIVE</code></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<section class="card">
  <dl class="meta">
    <div><dt><?= $voucher['type'] === 'transfer' ? 'Kho đi' : 'Kho' ?></dt><dd><?= e(warehouseName($voucher['warehouse_id'])) ?></dd></div>
    <?php if ($voucher['type'] === 'transfer'): ?>
      <div><dt>Kho đến</dt><dd><?= e(warehouseName($voucher['to_warehouse_id'])) ?></dd></div>
    <?php endif; ?>
    <div><dt>Người lập</dt><dd><?= e(userName($voucher['created_by'])) ?> · <?= e($voucher['created_at']) ?></dd></div>
    <div><dt>Người xác nhận</dt><dd><?= e(userName($voucher['confirmed_by'])) ?><?= $voucher['confirmed_at'] ? ' · ' . e($voucher['confirmed_at']) : '' ?></dd></div>
    <?php if ($voucher['status'] === 'rejected'): ?>
      <div><dt>Người không duyệt</dt><dd><?= e(userName($voucher['rejected_by'])) ?><?= $voucher['rejected_at'] ? ' · ' . e($voucher['rejected_at']) : '' ?></dd></div>
      <div><dt>Lý do không duyệt</dt><dd><?= e($voucher['reject_reason']) ?></dd></div>
    <?php endif; ?>
    <?php if ($ref): ?><div><dt>Chứng từ gốc</dt><dd><a href="/voucher.php?id=<?= $ref['id'] ?>"><?= e($ref['code']) ?></a></dd></div><?php endif; ?>
  </dl>
</section>

<section class="card">
  <h2>Dòng hàng</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><th>Mã</th><th>Mặt hàng</th><th>Lô</th><th>ĐVT</th><th class="num">Số lượng</th><th class="num">Tồn hiện tại (kho đi)</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $n => $line): $item = $items[$line['item_id']]; ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <td><?= e($item['sku']) ?></td>
          <td><?= e($item['name']) ?></td>
          <td><?= e($batches[$line['batch_id']]['code']) ?></td>
          <td><?= e($item['unit_name']) ?></td>
          <td class="num"><?= $voucher['type'] === 'adjust' ? signedQty($line['qty']) : qty($line['qty']) ?></td>
          <td class="num"><?= qty(StockLedger::balanceOf($balances, $voucher['warehouse_id'], $line['batch_id'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card">
  <h2><?= match ($voucher['status']) { 'confirmed' => 'Phát sinh đã ghi sổ', 'rejected' => 'Phiếu bị từ chối', default => 'Phát sinh sẽ ghi khi xác nhận' } ?></h2>
  <?php if ($voucher['status'] === 'rejected'): ?>
    <p class="muted">Phiếu bị từ chối nên không có dòng phát sinh nào được ghi vào sổ kho.</p>
  <?php else: ?>
    <p class="muted">
      <?= $voucher['type'] === 'transfer'
          ? 'Điều chuyển trừ kho đi và cộng kho đến trong cùng một giao dịch; tổng hai dòng của mỗi lô luôn bằng 0.'
          : 'Mỗi dòng hàng sinh một dòng phát sinh. Số tồn được tính lại từ các dòng này.' ?>
    </p>
    <div class="table-wrap">
    <table class="table table--compact">
      <thead><tr><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="num">Số lượng</th></tr></thead>
      <tbody>
        <?php foreach ($voucher['status'] === 'confirmed' ? $movements : $planned as $m): ?>
          <tr>
            <td><?= e(warehouseName($m['warehouse_id'])) ?></td>
            <td><?= e($items[$m['item_id']]['name']) ?></td>
            <td><?= e($batches[$m['batch_id']]['code']) ?></td>
            <td class="num"><?= signedQty($m['qty']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
  <?php if ($voucher['status'] === 'confirmed'): ?>
    <p class="muted">Muốn sửa chứng từ đã ghi nhận: lập chứng từ điều chỉnh tham chiếu đến <?= e($voucher['code']) ?>; không xóa lịch sử.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
