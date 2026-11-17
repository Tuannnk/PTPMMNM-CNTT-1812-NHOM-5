<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();
$error = '';
$isSalesUser = $user['role'] === 'sales_user';

// Điểm bán được đặt hàng: người dùng điểm bán chỉ thấy điểm bán của mình.
$points = array_values(array_filter(Store::all('sales_points'), fn($p) => $p['active']
    && (!$isSalesUser || canAccessSalesPoint($user, (int)$p['id']))));
$warehouses = allowedWarehouses($user);

// Kho xuất cần biết danh sách lô: kho phụ trách của điểm bán (người dùng điểm bán) hoặc kho được phân quyền.
$orderWarehouseIds = $isSalesUser
    ? array_values(array_unique(array_map(fn($p) => (int)$p['primary_warehouse_id'], $points)))
    : array_column($warehouses, 'id');

/** Lô còn hàng theo từng kho: chỉ những lô này mới được đặt. */
$availableLots = [];
if ($orderWarehouseIds) {
    $holders = [];
    $params = [];
    foreach ($orderWarehouseIds as $i => $warehouseId) {
        $holders[] = ':w' . $i;
        $params['w' . $i] = $warehouseId;
    }
    $lotQuery = Database::connection()->prepare(
        'SELECT m.warehouse_id, b.id AS batch_id, b.code AS batch_code, b.expiry, i.id AS item_id, i.sku, i.name, i.unit,
                SUM(m.qty)::bigint AS available
         FROM stock_movements m JOIN batches b ON b.id=m.batch_id JOIN items i ON i.id=b.item_id
         WHERE m.warehouse_id IN (' . implode(',', $holders) . ') AND b.active AND i.active
         GROUP BY m.warehouse_id, b.id, b.code, b.expiry, i.id, i.sku, i.name, i.unit
         HAVING SUM(m.qty) > 0
         ORDER BY i.name, b.expiry NULLS LAST, b.code'
    );
    $lotQuery->execute($params);
    $availableLots = $lotQuery->fetchAll();
}
$unitNames = array_column(Store::all('units'), 'name', 'code');
$multiWarehouse = count($orderWarehouseIds) > 1;
$lotLabel = function (array $lot) use ($unitNames, $multiWarehouse): string {
    $label = $lot['sku'] . ' — ' . $lot['name'] . ' · lô ' . $lot['batch_code']
        . ' · còn ' . qty((int)$lot['available']) . ' ' . ($unitNames[$lot['unit']] ?? $lot['unit']);
    if ($lot['expiry']) $label .= ' · HSD ' . dateOnly($lot['expiry']);
    if ($multiWarehouse) $label = warehouseName((int)$lot['warehouse_id']) . ': ' . $label;
    return $label;
};

$formLines = [['batch_id' => '', 'qty' => '']];
$formPoint = '';
$formWarehouse = '';
$formNote = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePost();
    $batchIds = listField($_POST, 'batch_id');
    $quantities = listField($_POST, 'qty');
    $formPoint = field($_POST, 'sales_point_id');
    $formWarehouse = field($_POST, 'warehouse_id');
    $formNote = field($_POST, 'note');
    $formLines = [];
    $lines = [];
    foreach ($batchIds as $i => $batchValue) {
        $qtyValue = $quantities[$i] ?? '';
        if ($batchValue === '' && $qtyValue === '') continue;   // dòng trống
        $formLines[] = ['batch_id' => $batchValue, 'qty' => $qtyValue];
        $batch = Store::find('batches', (int)$batchValue);
        $qty = filter_var($qtyValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$batch || $qty === false) {
            $error = ERROR_LABELS['LINE_INVALID'];
            continue;
        }
        $lines[] = ['item_id' => $batch['item_id'], 'batch_id' => (int)$batchValue, 'qty' => $qty];
    }
    if (!$formLines) $formLines = [['batch_id' => '', 'qty' => '']];
    if ($error === '' && !$lines) $error = ERROR_LABELS['LINES_EMPTY'];
    if ($error === '') {
        try {
            $id = SalesOrderService::createDraft($user, (int)$formPoint, (int)$formWarehouse, $lines,
                field($_POST, 'request_key'), $formNote);
            flash('Đã tạo đơn phân phối, đang chờ kho duyệt.');
            redirect('/sales-order.php?id=' . $id);
        } catch (DomainException $e) {
            $error = ERROR_LABELS[$e->getMessage()] ?? 'Không tạo được đơn phân phối. Kiểm tra kho, điểm bán và số lượng.';
        } catch (Throwable $e) {
            error_log('Sales order create failed: ' . $e->getMessage());
            $error = 'Không tạo được đơn phân phối. Vui lòng thử lại.';
        }
    }
}

$scope = array_column($warehouses, 'id');
$orders = array_reverse(array_values(array_filter(Store::all('sales_orders'),
    fn($o) => in_array($o['warehouse_id'], $scope, true) || canAccessSalesPoint($user, (int)$o['sales_point_id']))));
$pointsById = Store::indexed('sales_points');

/** Ô chọn lô; $selected là giá trị đã gửi (giữ lại khi form báo lỗi). */
$lotSelect = function (string $selected = '') use ($availableLots, $lotLabel): string {
    $html = '<select name="batch_id[]" class="lot-select" aria-label="Lô hàng"><option value="">— Chọn mặt hàng / lô —</option>';
    foreach ($availableLots as $lot) {
        $html .= '<option value="' . (int)$lot['batch_id'] . '" data-warehouse="' . (int)$lot['warehouse_id'] . '" data-available="' . (int)$lot['available'] . '"'
            . ($selected !== '' && (int)$selected === (int)$lot['batch_id'] ? ' selected' : '') . '>' . e($lotLabel($lot)) . '</option>';
    }
    return $html . '</select>';
};

$title = 'Đơn phân phối';
$active = 'sales-orders';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head"><div><h1>Đơn phân phối</h1><p>Đơn hàng từ kho địa điểm đến đại lý, cửa hàng và siêu thị.</p></div></header>
<?php if ($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>

<?php if ($points && ($isSalesUser || $warehouses)): ?>
<section class="card">
  <h2>Tạo đơn</h2>
  <form method="post" class="form" id="sales-order-form">
    <?= csrfInput() ?>
    <input type="hidden" name="request_key" value="<?= e(bin2hex(random_bytes(32))) ?>">
    <div class="form-row">
      <?php if (!$isSalesUser): ?>
        <label class="form-field">Kho xuất<select name="warehouse_id" id="order-warehouse" required>
          <?php foreach ($warehouses as $w): ?><option value="<?= $w['id'] ?>" <?= $formWarehouse === (string)$w['id'] ? 'selected' : '' ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?>
        </select></label>
      <?php else: ?>
        <input type="hidden" name="warehouse_id" value="0">
      <?php endif; ?>
      <label class="form-field">Điểm bán<select name="sales_point_id" id="order-point" required>
        <?php foreach ($points as $p): ?><option value="<?= $p['id'] ?>" data-warehouse="<?= (int)$p['primary_warehouse_id'] ?>" <?= $formPoint === (string)$p['id'] ? 'selected' : '' ?>><?= e($p['code'] . ' · ' . $p['name']) ?></option><?php endforeach; ?>
      </select></label>
      <label class="form-field form-field--wide">Ghi chú<input name="note" maxlength="255" value="<?= e($formNote) ?>"></label>
    </div>

    <?php if (!$availableLots): ?>
      <p class="empty">Kho phụ trách hiện không còn lô nào có hàng để đặt.</p>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--form">
      <thead><tr><th class="line-number">#</th><th>Mặt hàng / lô (chỉ hiện lô còn hàng)</th><th class="num line-quantity">Số lượng</th><th class="line-action"></th></tr></thead>
      <tbody id="order-lines">
        <?php foreach ($formLines as $i => $line): ?>
          <tr class="order-line">
            <td class="line-number"><?= $i + 1 ?></td>
            <td><?= $lotSelect((string)$line['batch_id']) ?></td>
            <td class="num"><input type="number" name="qty[]" min="1" step="1" inputmode="numeric" value="<?= e((string)$line['qty']) ?>" aria-label="Số lượng"></td>
            <td class="line-action"><button type="button" class="button button--icon button--remove-line" aria-label="Xóa dòng">×</button></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <template id="order-line-template">
      <tr class="order-line">
        <td class="line-number"></td>
        <td><?= $lotSelect() ?></td>
        <td class="num"><input type="number" name="qty[]" min="1" step="1" inputmode="numeric" aria-label="Số lượng"></td>
        <td class="line-action"><button type="button" class="button button--icon button--remove-line" aria-label="Xóa dòng">×</button></td>
      </tr>
    </template>
    <div class="line-controls">
      <button type="button" class="button button--ghost button--add-line" id="add-order-line"><span aria-hidden="true">＋</span> Thêm dòng</button>
      <span class="muted">Số tồn tính tại thời điểm tải trang; kho kiểm tra lại khi tạo đơn và khi xuất hàng.</span>
    </div>
    <div class="form-actions"><button class="button">Tạo đơn chờ xử lý</button></div>
    <?php endif; ?>
  </form>
</section>
<?php endif; ?>

<section class="card table-wrap">
  <table class="table">
    <thead><tr><th>Mã</th><th>Kho</th><th>Điểm bán</th><th>Trạng thái</th><th>Ngày tạo</th></tr></thead>
    <tbody>
      <?php if (!$orders): ?><tr><td colspan="5" class="empty">Chưa có đơn phân phối nào.</td></tr><?php endif; ?>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td><a class="text-link" href="/sales-order.php?id=<?= $o['id'] ?>"><?= e($o['code']) ?></a></td>
          <td><?= e(warehouseName($o['warehouse_id'])) ?></td>
          <td><?= e($pointsById[$o['sales_point_id']]['name'] ?? '—') ?></td>
          <td><?= salesStatusBadge($o['status']) ?></td>
          <td><?= e(dateOnly($o['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

<script>
(() => {
  const body = document.getElementById('order-lines');
  const template = document.getElementById('order-line-template');
  const addButton = document.getElementById('add-order-line');
  if (!body || !template || !addButton) return;
  const warehouseSelect = document.getElementById('order-warehouse');
  const pointSelect = document.getElementById('order-point');
  // Kho xuất: kho chọn ở form (nội bộ) hoặc kho phụ trách của điểm bán (người dùng điểm bán).
  const currentWarehouse = () => warehouseSelect
    ? warehouseSelect.value
    : pointSelect.selectedOptions[0]?.dataset.warehouse;

  const filterLots = select => {
    const warehouse = currentWarehouse();
    for (const option of select.options) {
      if (!option.value) continue;
      const hide = option.dataset.warehouse !== warehouse;
      option.hidden = hide;
      option.disabled = hide;
      if (hide && option.selected) select.value = '';
    }
  };
  const syncQuantity = row => {
    const option = row.querySelector('.lot-select').selectedOptions[0];
    const input = row.querySelector('input[name="qty[]"]');
    if (option?.dataset.available) input.max = option.dataset.available; else input.removeAttribute('max');
  };
  const refresh = () => {
    const rows = [...body.querySelectorAll('.order-line')];
    rows.forEach((row, index) => {
      row.querySelector('.line-number').textContent = index + 1;
      row.querySelector('.lot-select').setAttribute('aria-label', `Mặt hàng / lô dòng ${index + 1}`);
      row.querySelector('input').setAttribute('aria-label', `Số lượng dòng ${index + 1}`);
      row.querySelector('.button--remove-line').disabled = rows.length === 1;
      filterLots(row.querySelector('.lot-select'));
      syncQuantity(row);
    });
  };

  addButton.addEventListener('click', () => {
    body.append(template.content.cloneNode(true));
    refresh();
    body.lastElementChild.querySelector('select').focus();
  });
  body.addEventListener('click', event => {
    const remove = event.target.closest('.button--remove-line');
    if (remove && body.querySelectorAll('.order-line').length > 1) {
      remove.closest('.order-line').remove();
      refresh();
    }
  });
  body.addEventListener('change', event => {
    if (event.target.matches('.lot-select')) syncQuantity(event.target.closest('.order-line'));
  });
  warehouseSelect?.addEventListener('change', refresh);
  pointSelect?.addEventListener('change', refresh);
  refresh();
})();
</script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
