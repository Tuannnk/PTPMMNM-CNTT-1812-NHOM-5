<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$type = field($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET, 'type');
if (!in_array($type, ['in', 'out', 'transfer'], true)) {
    $type = 'in';
}
$allowed = allowedWarehouses($user);
$balances = StockLedger::balances(Store::all('stock_movements'));
$result = null;
$data = [
    'warehouse_id' => $allowed[0]['id'] ?? 0,
    'to_warehouse_id' => null,
    'note' => '',
    'lines' => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    // Kiểm tra quyền theo kho nằm trong validateVoucher: gửi thẳng warehouse_id của kho khác vẫn bị từ chối.
    $result = validateVoucher($_POST, $user, $balances);
    $data = $result['data'];
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
        ? 'Kho đi bị trừ và kho đến được cộng trong cùng một giao dịch khi chứng từ được xác nhận.'
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
      <h2>Chứng từ hợp lệ</h2>
      <p>
        <?= e(VOUCHER_TYPES[$type]) ?> tại <?= e(warehouseName($data['warehouse_id'])) ?><?= $type === 'transfer' ? ' → ' . e(warehouseName($data['to_warehouse_id'])) : '' ?>,
        <?= count($data['lines']) ?> dòng, tổng <?= qty(array_sum(array_column($data['lines'], 'qty'))) ?> đơn vị.
        Sẽ lưu ở trạng thái <strong>Chờ duyệt</strong>.
      </p>
      <p class="muted">Giao diện mẫu: chưa ghi CSDL. Giai đoạn sau, form gửi kèm khóa yêu cầu để gửi lại không tạo chứng từ thứ hai.</p>
    </section>
  <?php endif; ?>
<?php endif; ?>

<form method="post" class="card form">
  <?= csrfInput() ?>
  <input type="hidden" name="type" value="<?= e($type) ?>">
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
          <?php foreach ($allowed as $w): ?>
            <option value="<?= $w['id'] ?>" <?= $data['to_warehouse_id'] === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
          <?php endforeach; ?>
        </select>
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
    <button class="button">Kiểm tra và lưu nháp</button>
    <a class="button button--ghost" href="/vouchers.php">Hủy</a>
  </div>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>
