<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();
if (!canCreateVoucher($user)) {
    forbid('VOUCHER_CREATE_FORBIDDEN');
}

$type = field($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'type');
if (!in_array($type, ['in', 'out', 'transfer'], true)) {
    $type = 'in';
}
if ($type === 'transfer' && $user['role'] !== 'staff') {
    forbid('TRANSFER_CREATE_FORBIDDEN');
}
$allowed = allowedWarehouses($user);
$activeWarehouses = array_values(array_filter(
    Store::all('warehouses'),
    fn($warehouse) => $warehouse['active']
));
$balances = StockLedger::balances(Store::all('stock_movements'));
$result = null;
$requestKey = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? field($_POST, 'request_key')
    : bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['voucher_request_key'] = $requestKey;
} elseif (!preg_match('/^[a-f0-9]{48}$/', $requestKey)) {
    $requestKey = bin2hex(random_bytes(24));
    $_SESSION['voucher_request_key'] = $requestKey;
}
$data = [
    'warehouse_id' => $allowed[0]['id'] ?? 0,
    'to_warehouse_id' => null,
    'note' => '',
    'lines' => [],
];
$requestKey = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? field($_POST, 'request_key')
    : bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    // Kiểm tra quyền theo kho nằm trong validateVoucher: gửi thẳng warehouse_id của kho khác vẫn bị từ chối.
    $result = validateVoucher($_POST, $user, $balances);
    if (!preg_match('/^[a-f0-9]{48}$/', field($_POST, 'request_key'))) {
        $result['errors'][] = 'REQUEST_KEY_INVALID';
    }
    $data = $result['data'];
    if (!$result['errors'] && $type === 'transfer') {
        try {
            $saved = TransferService::createDraft($data, $user['id'], $requestKey);
            $query = Database::connection()->prepare('SELECT code FROM vouchers WHERE id = :id');
            $query->execute(['id' => $saved['id']]);
            $code = $query->fetchColumn();
            flash($saved['created']
                ? 'Đã lưu phiếu điều chuyển ' . $code . ' ở trạng thái chờ duyệt.'
                : 'Phiếu điều chuyển ' . $code . ' đã được lưu trước đó; không tạo phiếu trùng.');
            redirect('/voucher.php?id=' . $saved['id']);
        } catch (Throwable $e) {
            $result['errors'][] = 'TRANSFER_SAVE_FAILED';
        }
    }
}

$items = array_filter(Store::indexed('items'), fn($item) => $item['active']);
$batches = array_values(array_filter(Store::all('batches'), fn($batch) => $batch['active'] && isset($items[$batch['item_id']])));
$rows = max(5, count($data['lines']) + 1);
$labels = ['in' => 'Lập phiếu nhập', 'out' => 'Lập phiếu xuất', 'transfer' => 'Điều chuyển giữa hai kho'];
$title = $labels[$type];
$active = ['in' => 'voucher-in', 'out' => 'voucher-out', 'transfer' => 'transfer'][$type];
require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/vouchers.php">Chứng từ</a> / <?= e($title) ?></nav>
<header class="page-head">
  <div>
    <h1><?= e($title) ?></h1>
    <p><?= $type === 'transfer'
        ? 'Kiểm kê xác nhận hàng đã soạn, người duyệt cho xuất; kho đến ghi tăng theo số thực nhận.'
        : 'Chứng từ được lưu ở trạng thái chờ duyệt; số tồn chỉ thay đổi khi người duyệt xác nhận.' ?></p>
  </div>
</header>

<?php if ($result !== null): ?>
  <?php $errors = $result['errors']; require __DIR__ . '/../templates/errors.php'; ?>
  <?php if (!empty($data['negative'])): ?>
    <div class="error-summary">
      <ul>
        <?php foreach ($data['negative'] as $key => $after): [$w, $b] = array_map('intval', explode(':', $key)); $batch = Store::find('batches', $b); ?>
          <li>Lô <?= e($batch['code']) ?> (<?= e($items[$batch['item_id']]['name']) ?>) tại <?= e(warehouseName($w)) ?>: thiếu <?= qty(-$after) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
  <?php if (!$result['errors']): ?>
    <section class="preview" role="status">
      <h2><?= in_array($type, ['in', 'out'], true) ? 'Chứng từ hợp lệ' : 'Bản xem trước điều chuyển' ?></h2>
      <p>
        <?= e(VOUCHER_TYPES[$type]) ?> tại <?= e(warehouseName($data['warehouse_id'])) ?><?= $type === 'transfer' ? ' → ' . e(warehouseName($data['to_warehouse_id'])) : '' ?>,
        <?= count($data['lines']) ?> dòng, tổng <?= qty(array_sum(array_column($data['lines'], 'qty'))) ?> đơn vị.
        Sẽ lưu ở trạng thái <strong><?= $type === 'transfer' ? 'Chờ kiểm hàng' : 'Chờ duyệt' ?></strong>.
      </p>
      <?php if ($type !== 'transfer'): ?><p class="muted">Phiếu nhập và xuất hiện vẫn là bản xem trước, chưa ghi CSDL.</p><?php endif; ?>
    </section>
  <?php endif; ?>
<?php endif; ?>

<form method="post" class="card form">
  <?= csrfInput() ?>
  <input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <?php if ($type === 'transfer'): ?><input type="hidden" name="request_key" value="<?= e($requestKey) ?>"><?php endif; ?>
  <div class="form-row">
    <label class="form-field"><?= $type === 'transfer' ? 'Kho đi' : 'Kho' ?>
      <select name="warehouse_id" required>
        <?php foreach ($allowed as $w): ?>
          <option value="<?= $w['id'] ?>" <?= $data['warehouse_id'] === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <small>Chỉ hiện kho bạn phụ trách. Máy chủ vẫn kiểm tra lại.</small>
    </label>
    <?php if ($type === 'transfer'): ?>
      <label class="form-field">Kho đến
        <select name="to_warehouse_id" required>
          <option value="">— Chọn kho đến —</option>
          <?php foreach ($activeWarehouses as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $data['to_warehouse_id'] === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <small>Kho đến có thể là bất kỳ kho đang hoạt động; người duyệt cần quyền ở cả hai kho.</small>
      </label>
    <?php endif; ?>
    <label class="form-field form-field--wide">Ghi chú
      <input name="note" maxlength="255" value="<?= e($data['note']) ?>" placeholder="Lý do, số hóa đơn, bộ phận nhận…">
    </label>
  </div>

  <h2>Dòng hàng</h2>
  <div class="table-wrap">
  <table class="table table--form">
    <thead><tr><th>#</th><th>Lô hàng — mặt hàng</th><th class="num">Số lượng</th></tr></thead>
    <tbody>
      <?php for ($i = 0; $i < $rows; $i++): $line = $data['lines'][$i] ?? null; ?>
        <tr>
          <td><?= $i + 1 ?></td>
          <td>
            <select name="batch_id[]" aria-label="Lô hàng dòng <?= $i + 1 ?>">
              <option value="">— Chọn lô —</option>
              <?php foreach ($batches as $b): $have = StockLedger::balanceOf($balances, $data['warehouse_id'], $b['id']); ?>
                <option value="<?= $b['id'] ?>" <?= $line && $line['batch_id'] === $b['id'] ? 'selected' : '' ?>>
                  <?= e($b['code'] . ' — ' . $items[$b['item_id']]['name'] . ' (' . $items[$b['item_id']]['unit_name'] . ')') ?><?= $type !== 'in' ? ' · tồn ' . qty($have) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </td>
          <td class="num"><input type="number" name="qty[]" min="1" step="1" inputmode="numeric" value="<?= $line && $line['qty'] > 0 ? $line['qty'] : '' ?>" aria-label="Số lượng dòng <?= $i + 1 ?>"></td>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
  </div>
  <?php if ($type !== 'in'): ?><p class="muted">Số tồn trong danh sách tính theo kho đang chọn lúc tải trang.</p><?php endif; ?>
  <div class="form-actions">
    <button class="button"><?= $type === 'transfer' ? 'Kiểm tra và lưu phiếu điều chuyển' : 'Kiểm tra và lưu nháp' ?></button>
    <a class="button button--ghost" href="/vouchers.php">Hủy</a>
  </div>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>
