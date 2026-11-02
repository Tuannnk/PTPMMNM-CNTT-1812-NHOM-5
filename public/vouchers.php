<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$scope = array_column(allowedWarehouses($user), 'id');
$type = field($_GET, 'type');
$status = field($_GET, 'status');
$warehouseId = intField($_GET, 'warehouse_id');
$q = field($_GET, 'q');

$page = intField($_GET, 'page') ?? 1;
$limit = 100;
$conditions = [];
$params = [];
if (!$scope) {
    $conditions[] = 'FALSE';
} else {
    $scopeHoldersSource = [];
    $scopeHoldersDestination = [];
    foreach ($scope as $i => $warehouse) {
        $sourceKey = 'scope_source_' . $i;
        $destinationKey = 'scope_destination_' . $i;
        $scopeHoldersSource[] = ':' . $sourceKey;
        $scopeHoldersDestination[] = ':' . $destinationKey;
        $params[$sourceKey] = $warehouse;
        $params[$destinationKey] = $warehouse;
    }
    $conditions[] = '(v.warehouse_id IN (' . implode(',', $scopeHoldersSource) . ') OR v.to_warehouse_id IN (' . implode(',', $scopeHoldersDestination) . '))';
}
foreach (['type'=>$type,'status'=>$status] as $key=>$value) {
    if ($value !== '') { $conditions[] = "v.$key=:$key"; $params[$key]=$value; }
}
if ($warehouseId !== null) { $conditions[]='(v.warehouse_id=:filter_warehouse_source OR v.to_warehouse_id=:filter_warehouse_destination)'; $params['filter_warehouse_source']=$warehouseId; $params['filter_warehouse_destination']=$warehouseId; }
if ($q !== '') { $conditions[]='(v.code ILIKE :search_code OR v.note ILIKE :search_note)'; $params['search_code']='%'.$q.'%'; $params['search_note']='%'.$q.'%'; }
$where = implode(' AND ', $conditions);
$db = Database::connection();
$count = $db->prepare("SELECT count(*) FROM vouchers v WHERE $where");
$count->execute($params);
$totalRows = (int)$count->fetchColumn();
$query = $db->prepare("SELECT v.*,tw.state AS transfer_state,tw.cancel_reason AS transfer_cancel_reason,
    tw.checked_by AS transfer_checked_by,tw.checked_at AS transfer_checked_at,tw.received_by AS transfer_received_by,
    tw.cancelled_by AS transfer_cancelled_by,tw.cancelled_at AS transfer_cancelled_at,
    tw.dispatched_at AS transfer_dispatched_at,tw.received_at AS transfer_received_at,
    (SELECT count(*) FROM voucher_lines vl WHERE vl.voucher_id=v.id) AS line_count
    FROM vouchers v LEFT JOIN transfer_workflow tw ON tw.voucher_id=v.id
    WHERE $where ORDER BY v.id DESC LIMIT :page_limit OFFSET :page_offset");
foreach ($params as $key=>$value) $query->bindValue(':'.$key,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
$query->bindValue(':page_limit',$limit,PDO::PARAM_INT);
$query->bindValue(':page_offset',($page-1)*$limit,PDO::PARAM_INT);
$query->execute();
$rows = $query->fetchAll();

$title = 'Chứng từ';
$active = 'vouchers';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Chứng từ</h1><p>Nhập, xuất, điều chuyển và điều chỉnh. Chứng từ đã xác nhận không sửa, không xóa.</p></div>
  <?php if (canCreateVoucher($user)): ?><div class="actions">
    <a class="button" href="/voucher-new.php?type=in">+ Phiếu nhập</a>
    <a class="button button--ghost" href="/voucher-new.php?type=out">Phiếu xuất</a>
    <a class="button button--ghost" href="/voucher-new.php?type=transfer">Điều chuyển</a>
  </div><?php endif; ?>
</header>

<form class="filters" method="get">
  <label>Tìm<input name="q" value="<?= e($q) ?>" placeholder="Mã hoặc ghi chú"></label>
  <label>Loại
    <select name="type">
      <option value="">Tất cả</option>
      <?php foreach (VOUCHER_TYPES as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Trạng thái
    <select name="status">
      <option value="">Tất cả</option>
      <?php foreach (VOUCHER_STATUS as $key => $label): ?>
        <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Kho
    <select name="warehouse_id">
      <option value="">Tất cả kho phụ trách</option>
      <?php foreach (allowedWarehouses($user) as $w): ?>
        <option value="<?= $w['id'] ?>" <?= $warehouseId === $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <button class="button">Lọc</button>
</form>

<p class="result-count"><?= $totalRows ?> chứng từ · trang <?= $page ?> / <?= max(1,(int)ceil($totalRows/$limit)) ?></p>
<div class="table-wrap">
<table class="table">
  <thead><tr><th>Mã</th><th>Loại</th><th>Kho</th><th>Ngày lập</th><th>Người lập</th><th class="num">Dòng</th><th>Trạng thái</th><?php if ($user['role'] === 'approver'): ?><th>Thao tác</th><?php endif; ?></tr></thead>
  <tbody>
    <?php foreach ($rows as $v): ?>
      <tr>
        <td><a href="/voucher.php?id=<?= $v['id'] ?>"><strong><?= e($v['code']) ?></strong></a></td>
        <td><?= typeBadge($v['type']) ?></td>
        <td><?= e(warehouseName($v['warehouse_id'])) ?><?= $v['to_warehouse_id'] ? ' → ' . e(warehouseName($v['to_warehouse_id'])) : '' ?></td>
        <td><?= e(dateOnly($v['created_at'])) ?></td>
        <td><?= e(userName($v['created_by'])) ?></td>
        <td class="num"><?= e((string)$v['line_count']) ?></td>
        <td><?= voucherStatusBadge($v) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php if (!$rows): ?><p class="empty">Không có chứng từ phù hợp bộ lọc.</p><?php endif; ?>
<?php $totalPages=max(1,(int)ceil($totalRows/$limit)); if($totalPages>1): $pageQuery=['q'=>$q,'type'=>$type,'status'=>$status,'warehouse_id'=>$warehouseId]; ?>
<nav class="actions" aria-label="Phân trang chứng từ">
  <?php if($page>1):?><a class="button button--ghost" href="/vouchers.php?<?=e(http_build_query($pageQuery+['page'=>$page-1]))?>">Trang trước</a><?php endif;?>
  <?php if($page<$totalPages):?><a class="button button--ghost" href="/vouchers.php?<?=e(http_build_query($pageQuery+['page'=>$page+1]))?>">Trang sau</a><?php endif;?>
</nav>
<?php endif;?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
