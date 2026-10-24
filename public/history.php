<?php
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/HistoryReport.php';
$user = requireLogin();
$allowed = allowedWarehouses($user);
$scope = array_column($allowed, 'id');
$itemId = intField($_GET, 'item_id');
$warehouseId = intField($_GET, 'warehouse_id');
$batchId = intField($_GET, 'batch_id');
$type = field($_GET, 'type');
$from = field($_GET, 'from');
$to = field($_GET, 'to');
if ($warehouseId !== null && !in_array($warehouseId, $scope, true)) forbid();
if ($itemId !== null && !Store::find('items', $itemId)) $itemId = null;
if ($batchId !== null) {
    $selectedBatch = Store::find('batches', $batchId);
    if (!$selectedBatch || ($itemId !== null && $selectedBatch['item_id'] !== $itemId)) $batchId = null;
    elseif ($itemId === null) $itemId = $selectedBatch['item_id'];
}
if (!HistoryReport::validDate($from)) $from = '';
if (!HistoryReport::validDate($to)) $to = '';
if ($from !== '' && $to !== '' && $from > $to) [$from, $to] = [$to, $from];
if (!isset(VOUCHER_TYPES[$type])) $type = '';
$filters = compact('itemId', 'warehouseId', 'batchId', 'type', 'from', 'to');
$report = HistoryReport::build(['item_id'=>$itemId, 'warehouse_id'=>$warehouseId, 'batch_id'=>$batchId,
    'type'=>$type, 'from'=>$from, 'to'=>$to], $scope);
$rows = $report['rows'];
$items = Store::indexed('items');
$batches = Store::indexed('batches');
$vouchers = Store::indexed('vouchers');
$title = 'Lịch sử phát sinh';
$active = 'history';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Lịch sử phát sinh</h1><p>Tra cứu theo kỳ và đối chiếu tồn từ sổ phát sinh. Chứng từ đã ghi nhận được sửa bằng chứng từ điều chỉnh, không xóa lịch sử.</p></div>
  <div class="actions"><a class="button button--ghost" href="/export.php?<?= e(http_build_query(['report'=>'history','item_id'=>$itemId,'warehouse_id'=>$warehouseId,'batch_id'=>$batchId,'type'=>$type,'from'=>$from,'to'=>$to])) ?>">Xuất CSV lịch sử</a></div>
</header>
<form class="filters" method="get">
  <label>Mặt hàng<select name="item_id"><option value="">Tất cả</option><?php foreach (Store::all('items') as $item): ?><option value="<?= $item['id'] ?>" <?= $itemId === $item['id'] ? 'selected' : '' ?>><?= e($item['sku'].' — '.$item['name']) ?></option><?php endforeach; ?></select></label>
  <label>Lô<select name="batch_id"><option value="">Tất cả lô</option><?php foreach (Store::all('batches') as $batch): if ($itemId !== null && $batch['item_id'] !== $itemId) continue; ?><option value="<?= $batch['id'] ?>" <?= $batchId === $batch['id'] ? 'selected' : '' ?>><?= e($items[$batch['item_id']]['sku'].' — '.$batch['code']) ?></option><?php endforeach; ?></select></label>
  <label>Kho<select name="warehouse_id"><option value="">Tất cả kho phụ trách</option><?php foreach ($allowed as $w): ?><option value="<?= $w['id'] ?>" <?= $warehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></label>
  <label>Loại chứng từ<select name="type"><option value="">Tất cả</option><?php foreach (VOUCHER_TYPES as $key=>$label): ?><option value="<?= e($key) ?>" <?= $type===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
  <label>Từ ngày<input type="date" name="from" value="<?= e($from) ?>"></label>
  <label>Đến ngày<input type="date" name="to" value="<?= e($to) ?>"></label>
  <button class="button">Lọc</button>
</form>
<section class="reconcile">
  <div><span>Tồn đầu kỳ</span><strong><?= qty($report['opening']) ?></strong></div>
  <div><span>Phát sinh trong kỳ</span><strong><?= signedQty($report['period_delta']) ?></strong></div>
  <div><span>Tồn cuối kỳ theo sổ</span><strong><?= qty($report['closing']) ?></strong></div>
</section>
<p class="muted">Đối chiếu này cộng phát sinh đã ghi nhận theo bộ lọc kho, mặt hàng và lô; bộ lọc loại chứng từ chỉ giới hạn các dòng hiển thị.</p>
<section class="reconcile">
  <div><span>Nhóm kho/lô đã kiểm kê đối chiếu đến <?= e(dateOnly($report['reconciliation']['as_of'])) ?></span><strong><?= qty($report['reconciliation']['checked']) ?></strong></div>
  <div><span>Nhóm đang lệch</span><strong class="<?= $report['reconciliation']['mismatches'] ? 'qty-neg' : 'qty-pos' ?>"><?= qty($report['reconciliation']['mismatches']) ?></strong></div>
  <div><span>Tổng trị tuyệt đối phần lệch</span><strong><?= qty($report['reconciliation']['absolute_difference']) ?></strong></div>
</section>
<p class="muted">Đối chiếu lấy số thực tế ở đợt kiểm kê đã chốt gần nhất cho từng kho/lô, cộng các phát sinh sau thời điểm chốt rồi so với sổ.</p>
<p class="result-count"><?= count($rows) ?> dòng phát sinh</p>
<div class="table-wrap"><table class="table">
  <thead><tr><th>Thời điểm</th><th>Chứng từ</th><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="num">Phát sinh</th><th class="num">Lũy kế kho/lô</th></tr></thead>
  <tbody><?php foreach ($rows as $m): $v=$vouchers[$m['voucher_id']]; ?><tr>
    <td><?= e(dateOnly($m['at'])) ?></td><td><a href="/voucher.php?id=<?= $v['id'] ?>"><?= e($v['code']) ?></a> <?= typeBadge($v['type']) ?></td>
    <td><?= e(warehouseName($m['warehouse_id'])) ?></td><td><?= e($items[$m['item_id']]['name']) ?></td><td><?= e($batches[$m['batch_id']]['code']) ?></td>
    <td class="num"><?= signedQty($m['qty']) ?></td><td class="num"><?= qty($m['running']) ?></td>
  </tr><?php endforeach; ?></tbody>
</table></div>
<?php if (!$rows): ?><p class="empty">Không có phát sinh phù hợp bộ lọc.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
