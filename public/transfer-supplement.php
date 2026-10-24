<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();
if ($user['role'] !== 'staff') forbid('TRANSFER_CREATE_FORBIDDEN');
$parentId = intField($_GET, 'ref_id') ?? intField($_POST, 'ref_id') ?? 0;
$parent = Store::find('vouchers', $parentId);
if (!$parent || $parent['type'] !== 'transfer' || $parent['status'] !== 'confirmed'
    || $parent['ref_id'] !== null || ($parent['transfer_state'] ?? '') !== 'partial') {
    http_response_code(409); exit('TRANSFER_NOT_PARTIAL');
}
if (!canAccessWarehouse($user, $parent['warehouse_id'])) forbid('TRANSFER_CREATE_FORBIDDEN');

$db = Database::connection();
$query = $db->prepare(
    'SELECT vl.item_id,vl.batch_id,rl.missing_qty,b.code AS batch_code,i.sku,i.name,i.unit '
    . 'FROM transfer_receipt_lines rl JOIN voucher_lines vl ON vl.id=rl.voucher_line_id '
    . 'JOIN batches b ON b.id=vl.batch_id JOIN items i ON i.id=vl.item_id '
    . 'WHERE rl.voucher_id=:parent AND rl.missing_qty>0 ORDER BY vl.id'
);
$query->execute(['parent'=>$parentId]);
$missingLines = $query->fetchAll(PDO::FETCH_ASSOC);
$receivedQuery = $db->prepare(
    "SELECT COALESCE(SUM(rl.received_qty),0) FROM transfer_receipt_lines rl "
    . "JOIN vouchers child ON child.id=rl.voucher_id AND child.ref_id=:parent AND child.type='transfer' "
    . 'JOIN voucher_lines vl ON vl.id=rl.voucher_line_id WHERE vl.item_id=:item AND vl.batch_id=:batch'
);
$reservedQuery = $db->prepare(
    "SELECT COALESCE(SUM(vl.qty),0) FROM vouchers child "
    . "JOIN transfer_workflow tw ON tw.voucher_id=child.id AND tw.state IN ('draft','checked','in_transit') "
    . "JOIN voucher_lines vl ON vl.voucher_id=child.id "
    . "WHERE child.ref_id=:parent AND child.type='transfer' AND vl.item_id=:item AND vl.batch_id=:batch"
);
$outstanding = [];
foreach ($missingLines as $line) {
    $params=['parent'=>$parentId,'item'=>(int)$line['item_id'],'batch'=>(int)$line['batch_id']];
    $receivedQuery->execute($params); $received=(int)$receivedQuery->fetchColumn();
    $reservedQuery->execute($params); $reserved=(int)$reservedQuery->fetchColumn();
    $qty=max(0,(int)$line['missing_qty']-$received-$reserved);
    if ($qty>0) $outstanding[(int)$line['batch_id']]=$line+['remaining'=>$qty];
}
$errors=[];
$requestKey=$_SERVER['REQUEST_METHOD']==='POST'?field($_POST,'request_key'):bin2hex(random_bytes(24));
$data=['warehouse_id'=>$parent['warehouse_id'],'to_warehouse_id'=>$parent['to_warehouse_id'],
    'note'=>'Bổ sung cho '.$parent['code'],'lines'=>[]];
$balances=StockLedger::balances(Store::all('stock_movements'));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    checkCsrf($_POST);
    if (!preg_match('/^[a-f0-9]{48}$/',$requestKey)) $errors[]='REQUEST_KEY_INVALID';
    $submittedQty=is_array($_POST['qty']??null)?$_POST['qty']:[];
    foreach ($submittedQty as $batchKey=>$value) {
        if (!preg_match('/^\d+$/',(string)$batchKey) || !isset($outstanding[(int)$batchKey]) || !is_string($value)) {
            $errors[]='TRANSFER_SUPPLEMENT_QTY_INVALID';
            break;
        }
    }
    $synthetic=$_POST;
    $synthetic['type']='transfer';
    $synthetic['warehouse_id']=(string)$parent['warehouse_id'];
    $synthetic['to_warehouse_id']=(string)$parent['to_warehouse_id'];
    $synthetic['batch_id']=[];
    $synthetic['qty']=[];
    foreach ($outstanding as $batchId=>$line) {
        $rawQty=$submittedQty[(string)$batchId]??'';
        $qty=is_string($rawQty)?trim($rawQty):'';
        if ($qty==='' || $qty==='0') continue;
        $synthetic['batch_id'][]=(string)$batchId;
        $synthetic['qty'][]=$qty;
    }
    if (!$synthetic['batch_id']) $errors[]='LINES_EMPTY';
    $candidate=validateVoucher($synthetic,$user,$balances);
    $errors=array_merge($errors,$candidate['errors']);
    $data=$candidate['data'];
    if ($data['warehouse_id']!==$parent['warehouse_id'] || $data['to_warehouse_id']!==$parent['to_warehouse_id']) {
        $errors[]='TRANSFER_SUPPLEMENT_PARENT_INVALID';
    }
    foreach ($data['lines'] as $line) {
        $remaining=$outstanding[$line['batch_id']]['remaining']??0;
        if ($line['qty']>$remaining) $errors[]='TRANSFER_SUPPLEMENT_QTY_INVALID';
    }
    $errors=array_values(array_unique($errors));
    if (!$errors) {
        $data['ref_id']=$parentId;
        try {
            $saved=TransferService::createDraft($data,(int)$user['id'],$requestKey);
            flash('Đã lập phiếu điều chuyển bổ sung, liên kết với phiếu thiếu '.$parent['code'].'.');
            redirect('/voucher.php?id='.$saved['id']);
        } catch (DomainException $e) {
            $errors[]='Phiếu thiếu đã thay đổi hoặc số lượng bổ sung không còn phù hợp. Tải lại trang.';
        } catch (Throwable $e) {
            error_log('Transfer supplement draft failed: '.$e->getMessage());
            $errors[]='Không lưu được phiếu bổ sung.';
        }
    }
}
$items=Store::indexed('items'); $batches=Store::indexed('batches');
$title='Lập phiếu bổ sung'; $active='vouchers'; require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/voucher.php?id=<?= $parent['id'] ?>"><?= e($parent['code']) ?></a> / Bổ sung hàng thiếu</nav>
<header class="page-head"><div><h1>Bổ sung hàng thiếu</h1><p>Phiếu mới sẽ liên kết với chứng từ gốc và đi qua quy trình kiểm hàng, duyệt xuất, xác nhận nhận như một điều chuyển.</p></div></header>
<?php if ($errors): ?><div class="error-summary" role="alert"><ul><?php foreach($errors as $error): ?><li><?= e(ERROR_LABELS[$error]??$error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if (!$outstanding): ?><p class="empty">Không còn số lượng thiếu cần bổ sung hoặc một phiếu bổ sung khác đang xử lý.</p><?php else: ?>
<form method="post" class="card form">
  <?= csrfInput() ?><input type="hidden" name="request_key" value="<?= e($requestKey) ?>"><input type="hidden" name="type" value="transfer"><input type="hidden" name="ref_id" value="<?= $parentId ?>">
  <input type="hidden" name="warehouse_id" value="<?= $parent['warehouse_id'] ?>"><input type="hidden" name="to_warehouse_id" value="<?= $parent['to_warehouse_id'] ?>">
  <label>Ghi chú<input name="note" maxlength="255" value="<?= e((string)($_POST['note']??$data['note'])) ?>"></label>
  <div class="table-wrap"><table class="table table--form"><thead><tr><th>Mặt hàng</th><th>Lô</th><th class="num">Còn thiếu chưa gửi</th><th class="num">Tồn kho đi</th><th class="num">Gửi bổ sung</th></tr></thead><tbody>
  <?php foreach($outstanding as $batchId=>$line): $postedQty=is_array($_POST['qty']??null)?($_POST['qty'][(string)$batchId]??''):''; ?><tr>
    <td><?= e($line['sku'].' — '.$line['name']) ?></td><td><?= e($line['batch_code']) ?></td><td class="num"><?= qty($line['remaining']) ?></td>
    <td class="num"><?= qty(StockLedger::balanceOf($balances,$parent['warehouse_id'],$batchId)) ?></td>
    <td><input type="number" name="qty[<?= $batchId ?>]" min="0" max="<?= $line['remaining'] ?>" step="1" value="<?= e(is_string($postedQty)?$postedQty:'') ?>" aria-label="Số lượng bổ sung"></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <div class="actions"><button class="button">Kiểm tra và lưu phiếu bổ sung</button><a class="button button--ghost" href="/voucher.php?id=<?= $parent['id'] ?>">Hủy</a></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
