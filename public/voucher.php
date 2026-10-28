<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$voucher = Store::find('vouchers', intField($_GET, 'id') ?? 0);
if ($voucher === null) {
    http_response_code(404);
    exit('VOUCHER_NOT_FOUND');
}
$canSeeSource = canAccessWarehouse($user, $voucher['warehouse_id']);
$canSeeDestination = $voucher['type'] === 'transfer'
    && canAccessWarehouse($user, $voucher['to_warehouse_id']);
if ($voucher['type'] === 'transfer' ? !$canSeeSource && !$canSeeDestination : !$canSeeSource) {
    forbid();
}

$lines = Store::linesOf($voucher['id']);
if ($voucher['type'] === 'adjust') {
    $lineWarehouses = array_unique(array_column($lines, 'warehouse_id'));
    if (!$lineWarehouses || array_filter($lineWarehouses, fn($id) => !canAccessWarehouse($user, (int)$id))) forbid();
}
$items = Store::indexed('items');
$batches = Store::indexed('batches');
$balances = StockLedger::balances(Store::all('stock_movements'));
$visibleWarehouses = array_column(allowedWarehouses($user), 'id');
$planned = array_values(array_filter(
    StockLedger::movementsFor($voucher, $lines),
    fn($movement) => in_array($movement['warehouse_id'], $visibleWarehouses, true)
));
$negative = [];
$confirmError = '';
$rejectError = '';
$rejectReasonInput = '';
$showRejectForm = false;
$workflowError = '';
$canCheckTransfer = $voucher['type'] === 'transfer'
    && ($voucher['transfer_state'] ?? 'draft') === 'draft'
    && $user['role'] === 'counter' && $canSeeSource;
$canCancelTransfer = $voucher['type'] === 'transfer'
    && ($voucher['transfer_state'] ?? '') === 'checked'
    && $user['role'] === 'approver' && $canSeeSource && $canSeeDestination;
$canReceiveTransfer = $voucher['type'] === 'transfer'
    && ($voucher['transfer_state'] ?? '') === 'in_transit'
    && $user['role'] === 'staff' && $canSeeDestination;

$checkedLines = [];
$receiptLines = [];
if ($voucher['type'] === 'transfer') {
    $checkQuery = Database::connection()->prepare('SELECT * FROM transfer_checks WHERE voucher_id=:id');
    $checkQuery->execute(['id' => $voucher['id']]);
    foreach ($checkQuery->fetchAll() as $checkLine) {
        $checkedLines[(int)$checkLine['voucher_line_id']] = $checkLine;
    }
    $receiptQuery = Database::connection()->prepare('SELECT * FROM transfer_receipt_lines WHERE voucher_id=:id');
    $receiptQuery->execute(['id' => $voucher['id']]);
    foreach ($receiptQuery->fetchAll() as $receiptLine) {
        $receiptLines[(int)$receiptLine['voucher_line_id']] = $receiptLine;
    }
}

$readIntMap = static function (string $key): array {
    $raw = $_POST[$key] ?? null;
    if (!is_array($raw)) {
        throw new DomainException('TRANSFER_FORM_INVALID');
    }
    $result = [];
    foreach ($raw as $lineId => $value) {
        $id = filter_var((string)$lineId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $number = is_string($value)
            ? filter_var(trim($value), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])
            : false;
        if ($id === false || $number === false) {
            throw new DomainException('TRANSFER_FORM_INVALID');
        }
        $result[(int)$id] = $number;
    }
    return $result;
};
$readStringMap = static function (string $key): array {
    $raw = $_POST[$key] ?? [];
    if (!is_array($raw)) {
        throw new DomainException('TRANSFER_FORM_INVALID');
    }
    $result = [];
    foreach ($raw as $lineId => $value) {
        $id = filter_var((string)$lineId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || !is_string($value)) {
            throw new DomainException('TRANSFER_FORM_INVALID');
        }
        $result[(int)$id] = trim($value);
    }
    return $result;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if ($voucher['type'] === 'transfer') {
        try {
            $action = field($_POST, 'action');
            if ($action === 'check-transfer') {
                if (!$canCheckTransfer) forbid('TRANSFER_CHECK_FORBIDDEN');
                $outcome = TransferService::check($voucher['id'], $user, $readIntMap('checked_qty'), $readStringMap('check_note'));
                flash($outcome['complete']
                    ? 'Đã kiểm đủ hàng; phiếu đã chuyển sang chờ người duyệt.'
                    : 'Đã hủy phiếu vì số lượng thực tế không khớp; kết quả kiểm đếm được lưu kèm phiếu.');
                redirect('/voucher.php?id=' . $voucher['id']);
            } elseif ($action === 'cancel-transfer') {
                if (!$canCancelTransfer) forbid('CONFIRM_FORBIDDEN');
                TransferService::cancel($voucher['id'], $user, field($_POST, 'reason'));
                flash('Đã hủy phiếu điều chuyển trước khi xuất kho.');
                redirect('/voucher.php?id=' . $voucher['id']);
            } elseif ($action === 'confirm-transfer') {
                if (!canConfirm($user, $voucher)) forbid('CONFIRM_FORBIDDEN');
                $outcome = TransferService::confirm($voucher['id'], $user);
                $negative = $outcome['negative'] ?? [];
                if ($outcome['confirmed']) {
                    flash(!empty($outcome['already_confirmed'])
                        ? 'Phiếu đã được duyệt trước đó; không ghi xuất kho lần nữa.'
                        : 'Đã duyệt xuất kho. Hàng đang trên đường đến kho nhận.');
                    redirect('/voucher.php?id=' . $voucher['id']);
                }
            } elseif ($action === 'receive-transfer') {
                if (!$canReceiveTransfer) forbid('TRANSFER_RECEIVE_FORBIDDEN');
                $outcome = TransferService::receive($voucher['id'], $user, $readIntMap('received_qty'), $readStringMap('shortage_note'));
                flash($outcome['state'] === 'received'
                    ? 'Đã xác nhận nhận đủ hàng; tồn kho đích đã được cập nhật.'
                    : 'Đã ghi số thực nhận và yêu cầu bổ sung các mặt hàng còn thiếu cho kho đi.');
                redirect('/voucher.php?id=' . $voucher['id']);
            } else {
                forbid('TRANSFER_ACTION_INVALID');
            }
        } catch (DomainException $e) {
            if (in_array($e->getMessage(), ['CONFIRM_FORBIDDEN', 'TRANSFER_CHECK_FORBIDDEN', 'TRANSFER_RECEIVE_FORBIDDEN'], true)) {
                forbid($e->getMessage());
            }
            http_response_code(409);
            $workflowError = match ($e->getMessage()) {
                'TRANSFER_CHECK_REASON_REQUIRED' => 'Nhập lý do kiểm đếm cho từng dòng có số lượng không khớp rồi gửi lại.',
                'TRANSFER_SHORTAGE_REASON_REQUIRED' => 'Nhập lý do thiếu cho từng dòng nhận thiếu rồi gửi lại.',
                default => 'Thao tác không thể hoàn tất: phiếu có thể đã được xử lý hoặc dữ liệu gửi lên không hợp lệ.',
            };
        } catch (Throwable $e) {
            http_response_code(409);
            $workflowError = 'Không thể lưu thao tác điều chuyển. Giao dịch đã được hủy nếu có lỗi khi ghi dữ liệu.';
        }
    } else {
        $decision = field($_POST, 'decision') ?: 'confirm';
        if (in_array($voucher['status'], ['confirmed', 'rejected'], true)
            && $user['role'] === 'approver' && $canSeeSource) {
            flash($voucher['status'] === 'confirmed'
                ? 'Phiếu đã được xác nhận trước đó.'
                : 'Phiếu đã bị từ chối trước đó.');
            redirect('/voucher.php?id=' . $voucher['id']);
        }
        if (!canConfirm($user, $voucher)) {
            forbid($decision === 'reject' ? 'REJECT_FORBIDDEN' : 'CONFIRM_FORBIDDEN');
        }
        if ($decision === 'reject') {
            $showRejectForm = true;
            $rejectReasonInput = field($_POST, 'reject_reason');
            if ($rejectReasonInput === '' || !textFits($rejectReasonInput, 255)) {
                $rejectError = $rejectReasonInput === ''
                    ? 'Vui lòng nhập lý do không duyệt.'
                    : 'Lý do không được dài quá 255 ký tự.';
            } else {
                try {
                    $outcome = VoucherService::reject($voucher['id'], $user, $rejectReasonInput);
                    flash($outcome['already_rejected']
                        ? 'Phiếu đã bị từ chối trước đó.'
                        : 'Đã từ chối phiếu. Không có phát sinh tồn kho nào được ghi.');
                    redirect('/voucher.php?id=' . $voucher['id']);
                } catch (Throwable $e) {
                    if ($e->getMessage() === 'REJECT_FORBIDDEN') forbid('REJECT_FORBIDDEN');
                    error_log(sprintf('Voucher rejection failed for id=%d: %s', $voucher['id'], $e->getMessage()));
                    $rejectError = 'Không lưu được quyết định từ chối. Vui lòng thử lại.';
                }
            }
        } elseif ($decision === 'confirm') {
            try {
                $outcome = $voucher['type'] === 'adjust'
                    ? AdjustmentService::confirm($voucher['id'], $user)
                    : VoucherService::confirm($voucher['id'], $user);
                $negative = $outcome['negative'];
                if (!$negative && ($voucher['type'] !== 'adjust' || !empty($outcome['confirmed']))) {
                    flash(!empty($outcome['already_confirmed'])
                        ? 'Phiếu đã được xác nhận trước đó; không ghi phát sinh trùng.'
                        : 'Đã phê duyệt phiếu và ghi phát sinh vào sổ kho.');
                    redirect('/voucher.php?id=' . $voucher['id']);
                }
            } catch (Throwable $e) {
                if ($e->getMessage() === 'CONFIRM_FORBIDDEN') forbid('CONFIRM_FORBIDDEN');
                error_log(sprintf('Voucher confirmation failed for id=%d: %s', $voucher['id'], $e->getMessage()));
                $confirmError = 'Không phê duyệt được phiếu do lỗi khi ghi sổ. Vui lòng thử lại.';
            }
        } else {
            http_response_code(400);
            exit('INVALID_VOUCHER_DECISION');
        }
    }
}

$movements = array_values(array_filter(
    Store::movementsOf($voucher['id']),
    fn($movement) => in_array($movement['warehouse_id'], $visibleWarehouses, true)
));
$movementRows = $movements;
if (!$movementRows && $voucher['type'] === 'transfer'
    && in_array($voucher['transfer_state'] ?? '', ['draft', 'checked'], true)) {
    $movementRows = $planned;
} elseif (!$movementRows && $voucher['type'] !== 'transfer' && $voucher['status'] === 'draft') {
    $movementRows = $planned;
}
$ref = $voucher['ref_id'] ? Store::find('vouchers', $voucher['ref_id']) : null;
$relatedSupplements = $voucher['type'] === 'transfer'
    ? array_values(array_filter(Store::all('vouchers'), fn($candidate) => $candidate['ref_id'] === $voucher['id'] && $candidate['type'] === 'transfer'))
    : [];
$title = $voucher['code'];
$active = 'vouchers';
require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/vouchers.php">Chứng từ</a> / <?= e($voucher['code']) ?></nav>
<header class="page-head">
  <div>
    <h1><?= e($voucher['code']) ?> <?= typeBadge($voucher['type']) ?> <?= voucherStatusBadge($voucher) ?></h1>
    <p><?= e($voucher['note']) ?></p>
  </div>
  <?php if ($voucher['type'] === 'transfer' && canConfirm($user, $voucher)): ?>
    <form method="post" class="actions">
      <?= csrfInput() ?>
      <input type="hidden" name="action" value="confirm-transfer">
      <button class="button">Duyệt xuất kho</button>
    </form>
  <?php elseif ($voucher['type'] !== 'transfer' && canConfirm($user, $voucher)): ?>
    <div class="actions">
      <form method="post">
        <?= csrfInput() ?><input type="hidden" name="decision" value="confirm">
        <button class="button"><?= $voucher['type']==='adjust'?'Xác nhận điều chỉnh và ghi sổ':'Phê duyệt và ghi sổ' ?></button>
      </form>
      <details class="reject-disclosure" <?= $showRejectForm ? 'open' : '' ?>>
        <summary class="button button--ghost">Không duyệt phiếu</summary>
        <form method="post" class="reject-form">
          <?= csrfInput() ?><input type="hidden" name="decision" value="reject">
          <label class="label" for="reject_reason">Lý do không duyệt <span>(bắt buộc)</span></label>
          <textarea id="reject_reason" name="reject_reason" rows="3" maxlength="255" required><?= e($rejectReasonInput) ?></textarea>
          <?php if ($rejectError !== ''): ?><p class="field-error" role="alert"><?= e($rejectError) ?></p><?php endif; ?>
          <button class="button button--danger">Xác nhận không duyệt</button>
        </form>
      </details>
    </div>
  <?php endif; ?>
  <?php if ($voucher['type'] === 'transfer' && $voucher['ref_id'] === null && ($voucher['transfer_state'] ?? '') === 'partial'
      && $voucher['status'] === 'confirmed' && $user['role'] === 'staff' && $canSeeSource): ?>
    <a class="button button--ghost" href="/transfer-supplement.php?ref_id=<?= $voucher['id'] ?>">Lập phiếu gửi bù hàng thiếu</a>
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
<?php if ($confirmError !== ''): ?><div class="error-summary" role="alert"><?= e($confirmError) ?></div><?php endif; ?>
<?php if ($workflowError !== ''): ?><div class="error-summary" role="alert"><?= e($workflowError) ?></div><?php endif; ?>

<section class="card">
  <dl class="meta">
    <div><dt><?= $voucher['type'] === 'transfer' ? 'Kho đi' : 'Kho' ?></dt><dd><?= e(warehouseName($voucher['warehouse_id'])) ?></dd></div>
    <?php if ($voucher['type'] === 'transfer'): ?>
      <div><dt>Kho đến</dt><dd><?= e(warehouseName($voucher['to_warehouse_id'])) ?></dd></div>
    <?php endif; ?>
    <div><dt>Người lập</dt><dd><?= e(userName($voucher['created_by'])) ?> · <?= e(dateOnly($voucher['created_at'])) ?></dd></div>
    <div><dt>Người xác nhận</dt><dd><?= e(userName($voucher['confirmed_by'])) ?><?= $voucher['confirmed_at'] ? ' · ' . e(dateOnly($voucher['confirmed_at'])) : '' ?></dd></div>
    <?php if ($voucher['status'] === 'rejected' && $voucher['type'] !== 'transfer'): ?>
      <div><dt>Người không duyệt</dt><dd><?= e(userName($voucher['rejected_by'])) ?><?= $voucher['rejected_at'] ? ' · ' . e(dateOnly($voucher['rejected_at'])) : '' ?></dd></div>
      <div><dt>Lý do không duyệt</dt><dd><?= e($voucher['reject_reason']) ?></dd></div>
    <?php endif; ?>
    <?php if ($voucher['type'] === 'transfer' && $voucher['transfer_checked_by']): ?><div><dt>Người kiểm hàng</dt><dd><?= e(userName($voucher['transfer_checked_by'])) ?><?= $voucher['transfer_checked_at'] ? ' · ' . e(dateOnly($voucher['transfer_checked_at'])) : '' ?></dd></div><?php endif; ?>
    <?php if ($voucher['type'] === 'transfer' && $voucher['transfer_received_by']): ?><div><dt>Người nhận hàng</dt><dd><?= e(userName((int)$voucher['transfer_received_by'])) ?><?= $voucher['transfer_received_at'] ? ' · ' . e(dateOnly($voucher['transfer_received_at'])) : '' ?></dd></div><?php endif; ?>
    <?php if ($voucher['type'] === 'transfer' && $voucher['confirmed_by']): ?><div><dt>Người duyệt xuất</dt><dd><?= e(userName($voucher['confirmed_by'])) ?><?= $voucher['confirmed_at'] ? ' · ' . e(dateOnly($voucher['confirmed_at'])) : '' ?></dd></div><?php elseif ($voucher['type'] === 'transfer' && ($voucher['transfer_state'] ?? '') === 'cancelled'): ?><div><dt>Người duyệt xuất</dt><dd>Chưa duyệt — phiếu đã bị hủy trước khi xuất</dd></div><?php endif; ?>
    <?php if ($voucher['type'] === 'transfer' && ($voucher['transfer_state'] ?? '') === 'cancelled'): ?>
      <div><dt>Người hủy phiếu</dt><dd><?= e(userName($voucher['transfer_cancelled_by'])) ?><?= $voucher['transfer_cancelled_at'] ? ' · ' . e(dateOnly($voucher['transfer_cancelled_at'])) : '' ?></dd></div>
      <div><dt>Lý do hủy</dt><dd><?= e($voucher['transfer_cancel_reason'] ?: 'Không có lý do được lưu.') ?></dd></div>
    <?php endif; ?>
    <?php if ($ref): ?><div><dt>Chứng từ gốc</dt><dd><a href="/voucher.php?id=<?= $ref['id'] ?>"><?= e($ref['code']) ?></a></dd></div><?php endif; ?>
  </dl>
</section>

<section class="card">
  <h2>Dòng hàng</h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>#</th><?php if ($voucher['type']==='adjust'): ?><th>Kho</th><?php endif; ?><th>Mã</th><th>Mặt hàng</th><th>Lô</th><th>ĐVT</th><th class="num"><?= $voucher['type']==='adjust'?'Delta':'Số lượng' ?></th><?php if ($voucher['type']==='adjust'): ?><th>Lý do</th><?php endif; ?><th class="num">Tồn hiện tại</th></tr></thead>
    <tbody>
      <?php foreach ($lines as $n => $line): $item = $items[$line['item_id']]; ?>
        <tr>
          <td><?= $n + 1 ?></td>
          <?php if ($voucher['type']==='adjust'): ?><td><?= e(warehouseName($line['warehouse_id'])) ?></td><?php endif; ?>
          <td><?= e($item['sku']) ?></td>
          <td><?= e($item['name']) ?></td>
          <td><?= e($batches[$line['batch_id']]['code']) ?></td>
          <td><?= e($item['unit_name']) ?></td>
          <td class="num"><?= $voucher['type'] === 'adjust' ? signedQty($line['qty']) : qty($line['qty']) ?></td>
          <?php if ($voucher['type']==='adjust'): ?><td><?= e($line['reason']) ?></td><?php endif; ?>
          <td class="num"><?= ($voucher['type']==='adjust' ? canAccessWarehouse($user, $line['warehouse_id']) : $canSeeSource) ? qty(StockLedger::balanceOf($balances, $voucher['type']==='adjust'?$line['warehouse_id']:$voucher['warehouse_id'], $line['batch_id'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($voucher['type'] === 'transfer'): ?>
<section class="card">
  <h2>Kiểm hàng và nhận hàng</h2>
  <?php if ($canCheckTransfer): ?>
    <p class="muted">Người kiểm kê nhập số lượng đã soạn thực tế tại kho đi. Nếu một dòng thiếu, phiếu sẽ bị hủy và kết quả kiểm đếm được lưu.</p>
    <form method="post" class="form">
      <?= csrfInput() ?><input type="hidden" name="action" value="check-transfer">
      <div class="table-wrap"><table class="table table--form">
        <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Theo phiếu</th><th class="num">Đã soạn</th><th>Lý do nếu số lượng không khớp</th></tr></thead>
        <tbody><?php foreach ($lines as $line): $item = $items[$line['item_id']]; ?>
          <tr>
            <td><?= e($item['name']) ?></td><td><?= e($batches[$line['batch_id']]['code']) ?></td>
            <td class="num"><?= qty($line['qty']) ?></td>
            <td class="num"><input type="number" name="checked_qty[<?= $line['id'] ?>]" min="0" max="<?= $line['qty'] ?>" step="1" required value="<?= $line['qty'] ?>" aria-label="Số lượng đã soạn"></td>
            <td><input name="check_note[<?= $line['id'] ?>]" maxlength="255" aria-label="Lý do kiểm đếm" placeholder="Bắt buộc nếu thiếu hoặc thừa"></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <button class="button">Lưu kiểm đếm và gửi duyệt</button>
    </form>
  <?php endif; ?>

  <?php if ($checkedLines): ?>
    <h3>Kết quả kiểm hàng tại kho đi</h3>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Theo phiếu</th><th class="num">Đã soạn</th><th>Ghi chú</th></tr></thead>
      <tbody><?php foreach ($lines as $line): $check = $checkedLines[$line['id']] ?? null; if (!$check) continue; ?>
        <tr><td><?= e($items[$line['item_id']]['name']) ?></td><td><?= e($batches[$line['batch_id']]['code']) ?></td><td class="num"><?= qty($line['qty']) ?></td><td class="num"><?= qty((int)$check['checked_qty']) ?></td><td><?= e($check['note'] ?: ((int)$check['checked_qty'] !== (int)$line['qty'] ? 'Số lượng kiểm đếm không khớp với phiếu.' : '—')) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

  <?php if ($canCancelTransfer): ?>
    <p class="muted">Hàng đã kiểm đủ. Người duyệt có thể duyệt xuất hoặc hủy phiếu trước khi hàng rời kho.</p>
    <form method="post" class="inline-form">
      <?= csrfInput() ?><input type="hidden" name="action" value="cancel-transfer">
      <label>Lý do hủy<input name="reason" maxlength="255" required></label>
      <button class="button button--ghost">Hủy phiếu</button>
    </form>
  <?php endif; ?>

  <?php if ($canReceiveTransfer): ?>
    <p class="muted">Nhân viên kho đến nhập số lượng đã nhận. Nếu thiếu, ghi số thực nhận và lý do; phần thiếu sẽ được gửi làm yêu cầu bổ sung cho kho đi.</p>
    <form method="post" class="form">
      <?= csrfInput() ?><input type="hidden" name="action" value="receive-transfer">
      <div class="table-wrap"><table class="table table--form">
        <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Đã gửi</th><th class="num">Thực nhận</th><th>Lý do thiếu</th></tr></thead>
        <tbody><?php foreach ($lines as $line): ?>
          <tr>
            <td><?= e($items[$line['item_id']]['name']) ?></td><td><?= e($batches[$line['batch_id']]['code']) ?></td>
            <td class="num"><?= qty($line['qty']) ?></td>
            <td class="num"><input type="number" name="received_qty[<?= $line['id'] ?>]" min="0" max="<?= $line['qty'] ?>" step="1" required value="<?= $line['qty'] ?>" aria-label="Số lượng thực nhận"></td>
            <td><input name="shortage_note[<?= $line['id'] ?>]" maxlength="255" placeholder="Bắt buộc nếu nhận thiếu" aria-label="Lý do thiếu"></td>
          </tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <button class="button">Xác nhận đã nhận</button>
    </form>
  <?php endif; ?>

  <?php if ($receiptLines): ?>
    <h3>Biên bản nhận hàng tại kho đến</h3>
    <?php $missingTotal = array_sum(array_map(fn($receipt) => (int)$receipt['missing_qty'], $receiptLines)); ?>
    <?php if ($missingTotal > 0): ?><p class="<?= ($voucher['transfer_state'] ?? '') === 'received' ? 'notice' : 'error-summary' ?>">
      <?= ($voucher['transfer_state'] ?? '') === 'received'
          ? 'Đã nhận thiếu ở lần giao đầu; phần thiếu đã được bù đủ qua phiếu điều chuyển liên kết.'
          : 'Kho đến ghi nhận thiếu tổng ' . qty($missingTotal) . ' đơn vị. Kho đi có thể lập phiếu gửi bù; yêu cầu cũng hiện trên bảng điều khiển kho đi.' ?>
    </p><?php endif; ?>
    <div class="table-wrap"><table class="table table--compact">
      <thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Đã gửi</th><th class="num">Đã nhận</th><th class="num">Thiếu / yêu cầu bổ sung</th><th>Ghi chú</th></tr></thead>
      <tbody><?php foreach ($lines as $line): $receipt = $receiptLines[$line['id']] ?? null; if (!$receipt) continue; ?>
        <tr><td><?= e($items[$line['item_id']]['name']) ?></td><td><?= e($batches[$line['batch_id']]['code']) ?></td><td class="num"><?= qty($line['qty']) ?></td><td class="num"><?= qty((int)$receipt['received_qty']) ?></td><td class="num"><?= qty((int)$receipt['missing_qty']) ?></td><td><?= e($receipt['note'] ?: '—') ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>

  <?php if (($voucher['transfer_state'] ?? '') === 'cancelled'): ?>
    <p class="error-summary">Phiếu đã hủy: <?= e($voucher['transfer_cancel_reason'] ?: 'Số lượng kiểm đếm không khớp.') ?></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($relatedSupplements): ?>
<section class="card"><h2>Phiếu gửi bù liên kết</h2><ul class="list">
  <?php foreach ($relatedSupplements as $supplement): ?><li>
    <a href="/voucher.php?id=<?= $supplement['id'] ?>"><strong><?= e($supplement['code']) ?></strong></a>
    <?= voucherStatusBadge($supplement) ?> · <?= e($supplement['created_at'] ? dateOnly($supplement['created_at']) : '') ?>
  </li><?php endforeach; ?>
</ul></section>
<?php endif; ?>

<section class="card">
  <h2><?= $voucher['status'] === 'rejected' || ($voucher['transfer_state'] ?? '') === 'cancelled'
      ? 'Phiếu đã từ chối/hủy — không ghi phát sinh'
      : ($movements ? 'Phát sinh đã ghi sổ' : 'Phát sinh dự kiến') ?></h2>
  <p class="muted">
    <?= $voucher['status'] === 'rejected'
        ? 'Phiếu bị từ chối nên không tạo dòng phát sinh trong sổ kho.'
        : ($voucher['type'] === 'transfer'
        ? 'Kho đi giảm khi người duyệt duyệt xuất; kho đến chỉ tăng theo số thực nhận đã được xác nhận.'
        : 'Mỗi dòng hàng sinh một dòng phát sinh. Số tồn được tính lại từ các dòng này.') ?>
  </p>
  <div class="table-wrap">
  <table class="table table--compact">
    <thead><tr><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="num">Số lượng</th></tr></thead>
    <tbody>
      <?php foreach ($movementRows as $m): ?>
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
  <?php if ($voucher['status'] === 'confirmed' && $user['role']==='staff'
      && ($voucher['type'] !== 'transfer' || in_array($voucher['transfer_state']??'', ['partial','received'], true))): ?>
    <p class="muted">Muốn sửa chứng từ đã ghi nhận, lập chứng từ điều chỉnh tham chiếu chứng từ này; lịch sử gốc được giữ nguyên.</p>
    <a class="button button--ghost" href="/adjust-new.php?ref_id=<?= $voucher['id'] ?>">Lập chứng từ điều chỉnh</a>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
