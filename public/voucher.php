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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (!canConfirm($user, $voucher)) {
        forbid('CONFIRM_FORBIDDEN');
    }
    $negative = StockLedger::negativeAfter($balances, $planned);
    if (!$negative) {
        // Giai đoạn CSDL: BEGIN; khóa dòng tồn; kiểm tra trạng thái draft; ghi stock_movements; cập nhật status; COMMIT.
        flash(sprintf('%s hợp lệ: khi xác nhận sẽ ghi %d dòng phát sinh trong một giao dịch (giao diện mẫu, chưa lưu CSDL).', $voucher['code'], count($planned)));
        redirect('/voucher.php?id=' . $voucher['id']);
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
    <form method="post" class="actions">
      <?= csrfInput() ?>
      <button class="button">Xác nhận chứng từ</button>
    </form>
  <?php endif; ?>
</header>

<?php if ($negative): ?>
  <div class="error-summary" role="alert">
    <strong>Không thể xác nhận: số tồn sẽ âm</strong>
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
  <h2><?= $voucher['status'] === 'confirmed' ? 'Phát sinh đã ghi sổ' : 'Phát sinh sẽ ghi khi xác nhận' ?></h2>
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
  <?php if ($voucher['status'] === 'confirmed'): ?>
    <p class="muted">Muốn sửa chứng từ đã ghi nhận: lập chứng từ điều chỉnh tham chiếu đến <?= e($voucher['code']) ?>; không xóa lịch sử.</p>
  <?php endif; ?>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
