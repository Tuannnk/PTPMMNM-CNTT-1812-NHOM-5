<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();
if ($user['role'] !== 'staff') forbid('ADJUSTMENT_CREATE_FORBIDDEN');
$originalId = intField($_GET, 'ref_id') ?? intField($_POST, 'ref_id') ?? 0;
$original = Store::find('vouchers', $originalId);
if (!$original || $original['status'] !== 'confirmed') {
    http_response_code(404); exit('ADJUSTMENT_ORIGINAL_INVALID');
}
if ($original['type'] === 'transfer' && !in_array($original['transfer_state'] ?? '', ['partial','received'], true)) {
    http_response_code(409); exit('TRANSFER_NOT_RECEIVED');
}
$scope = array_column(allowedWarehouses($user), 'id');
$movements = array_values(array_filter(Store::movementsOf($originalId), fn($m) => in_array($m['warehouse_id'], $scope, true)));
if (!$movements) forbid('ADJUSTMENT_NO_ACCESSIBLE_LINES');
$items=Store::indexed('items'); $batches=Store::indexed('batches');
$errors=[]; $requestKey = bin2hex(random_bytes(24));
if ($_SERVER['REQUEST_METHOD']==='POST') {
    checkCsrf($_POST);
    $requestKey = field($_POST, 'request_key');
    $deltas = $_POST['delta'] ?? []; $reasons = $_POST['reason'] ?? [];
    if (!is_array($deltas) || !is_array($reasons)) { $errors[]='Dữ liệu điều chỉnh không hợp lệ.'; $deltas=[]; $reasons=[]; }
    $lines=[]; $validIds=array_fill_keys(array_column($movements,'id'), true);
    foreach ($deltas as $id=>$raw) {
        $movementId=filter_var((string)$id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $raw=is_string($raw)?trim($raw):'';
        if ($movementId===false || !isset($validIds[(int)$movementId]) || !preg_match('/^-?\d+$/',$raw)) { $errors[]='Có dòng điều chỉnh không hợp lệ.'; break; }
        $delta=(int)$raw; if ($delta===0) continue;
        $reason=is_string($reasons[$id]??null)?trim($reasons[$id]):'';
        if ($reason==='' || !textFits($reason,255)) { $errors[]='Mỗi dòng có điều chỉnh phải có lý do tối đa 255 ký tự.'; break; }
        $lines[]=['ref_movement_id'=>(int)$movementId,'qty'=>$delta,'reason'=>$reason];
    }
    if (!$lines) $errors[]='Nhập ít nhất một mức điều chỉnh khác 0.';
    if (!preg_match('/^[a-f0-9]{48}$/',$requestKey)) $errors[]='Biểu mẫu đã hết hạn; tải lại trang rồi thử lại.';
    if (!$errors) {
        try {
            $id=AdjustmentService::createDraft($originalId,$lines,$user,$requestKey);
            flash('Đã lập chứng từ điều chỉnh ở trạng thái chờ duyệt.'); redirect('/voucher.php?id='.$id);
        } catch (DomainException $e) {
            $errors[]='Không thể lập điều chỉnh: chứng từ gốc hoặc các dòng đã thay đổi, hãy tải lại trang.';
        } catch (Throwable $e) {
            error_log('Adjustment draft failed: '.$e->getMessage());
            $errors[]='Không lưu được phiếu điều chỉnh.';
        }
    }
}
$title='Lập chứng từ điều chỉnh'; $active='vouchers'; require __DIR__ . '/../templates/header.php';
?>
<nav class="breadcrumb"><a href="/voucher.php?id=<?= $original['id'] ?>"><?= e($original['code']) ?></a> / Điều chỉnh</nav>
<header class="page-head"><div><h1>Lập chứng từ điều chỉnh</h1><p>Chứng từ gốc được giữ nguyên. Delta có dấu sẽ tạo phát sinh mới khi người duyệt xác nhận.</p></div></header>
<?php if ($errors): ?><div class="error-summary" role="alert"><ul><?php foreach($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="card form">
  <?= csrfInput() ?><input type="hidden" name="ref_id" value="<?= $original['id'] ?>"><input type="hidden" name="request_key" value="<?= e($requestKey) ?>">
  <p><strong>Chứng từ gốc:</strong> <a href="/voucher.php?id=<?= $original['id'] ?>"><?= e($original['code']) ?></a></p>
  <p class="muted">Nhập chênh lệch cần ghi thêm: số âm để giảm tồn, số dương để tăng tồn. Bỏ trống hoặc nhập 0 để không điều chỉnh dòng.</p>
  <div class="table-wrap"><table class="table table--form"><thead><tr><th>Kho</th><th>Mặt hàng</th><th>Lô</th><th class="num">Phát sinh gốc</th><th class="num">Delta điều chỉnh</th><th>Lý do</th></tr></thead><tbody>
  <?php foreach($movements as $m): ?><tr>
    <td><?= e(warehouseName($m['warehouse_id'])) ?></td><td><?= e($items[$m['item_id']]['name']) ?></td><td><?= e($batches[$m['batch_id']]['code']) ?></td>
    <td class="num"><?= signedQty($m['qty']) ?></td><td><input type="number" name="delta[<?= $m['id'] ?>]" step="1" value="<?= e((string)($_POST['delta'][$m['id']]??'0')) ?>" aria-label="Delta điều chỉnh"></td>
    <td><input name="reason[<?= $m['id'] ?>]" maxlength="255" value="<?= e((string)($_POST['reason'][$m['id']]??'')) ?>" placeholder="Lý do nếu điều chỉnh"></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <div class="actions"><button class="button">Lưu phiếu chờ duyệt</button><a class="button button--ghost" href="/voucher.php?id=<?= $original['id'] ?>">Hủy</a></div>
</form>
<?php require __DIR__ . '/../templates/footer.php'; ?>
