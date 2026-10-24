<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();
$manage = canManageCatalog($user);
$db = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    requireCatalogManager($user);
    $action = field($_POST, 'action');
    try {
        if ($action === 'item-save') {
            $id = intField($_POST, 'id');
            $sku = strtoupper(field($_POST, 'sku'));
            $name = field($_POST, 'name');
            $unit = field($_POST, 'unit');
            $min = field($_POST, 'min_stock');
            $unitQuery = $db->prepare('SELECT 1 FROM units WHERE code = :code AND active = TRUE');
            $unitQuery->execute(['code' => $unit]);
            if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,59}$/', $sku) || $name === '' || !textFits($name, 200)
                || !preg_match('/^\d{1,11}$/', $min) || !$unitQuery->fetchColumn()) {
                throw new InvalidArgumentException('Thông tin mặt hàng chưa hợp lệ.');
            }
            $active = isset($_POST['active']);
            if ($id === null) {
                $q = $db->prepare('INSERT INTO items (sku,name,unit,min_stock,active) VALUES (:sku,:name,:unit,:min,:active)');
            } else {
                $q = $db->prepare('UPDATE items SET sku=:sku,name=:name,unit=:unit,min_stock=:min,active=:active WHERE id=:id');
            }
            // Pass explicit PostgreSQL boolean text; PDO::execute() serializes PHP false as ''.
            $q->execute(['sku'=>$sku,'name'=>$name,'unit'=>$unit,'min'=>$min,'active'=>$active ? 'true' : 'false'] + ($id === null ? [] : ['id'=>$id]));
            flash('Đã lưu mặt hàng.');
        } elseif ($action === 'batch-save') {
            $id = intField($_POST, 'id');
            $itemId = intField($_POST, 'item_id');
            $code = field($_POST, 'code');
            $expiry = field($_POST, 'expiry');
            if ($itemId === null || Store::find('items', $itemId) === null || !textFits($code, 80) || $code === ''
                || ($expiry !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)
                    || !checkdate((int)substr($expiry,5,2),(int)substr($expiry,8,2),(int)substr($expiry,0,4))))) {
                throw new InvalidArgumentException('Thông tin lô hàng chưa hợp lệ.');
            }
            if ($id === null) {
                $q = $db->prepare('INSERT INTO batches (item_id,code,expiry) VALUES (:item,:code,:expiry)');
                $q->execute(['item'=>$itemId,'code'=>$code,'expiry'=>$expiry === '' ? null : $expiry]);
                flash('Đã thêm lô hàng.');
            } else {
                $existingBatch = Store::find('batches', $id);
                if (!$existingBatch || $existingBatch['item_id'] !== $itemId) throw new InvalidArgumentException('Không thể chuyển lô sang mặt hàng khác.');
                $q = $db->prepare('UPDATE batches SET code=:code,expiry=:expiry WHERE id=:id AND item_id=:item');
                $q->execute(['code'=>$code,'expiry'=>$expiry === '' ? null : $expiry,'id'=>$id,'item'=>$itemId]);
                flash('Đã cập nhật lô hàng.');
            }
        } elseif ($action === 'batch-toggle') {
            $id = intField($_POST, 'id');
            if ($id === null) { throw new InvalidArgumentException('Lô hàng không hợp lệ.'); }
            $q = $db->prepare('UPDATE batches SET active = NOT active WHERE id=:id');
            $q->execute(['id'=>$id]);
            flash('Đã cập nhật trạng thái lô hàng.');
        } elseif ($action === 'unit-save') {
            $editingCode = field($_POST, 'editing_code');
            $code = field($_POST, 'code');
            $name = field($_POST, 'name');
            if (!preg_match('/^[\pL\pN._-]{1,40}$/u', $code) || $name === '' || !textFits($name, 80)) {
                throw new InvalidArgumentException('Mã và tên đơn vị tính chưa hợp lệ.');
            }
            if ($editingCode === '') {
                $q = $db->prepare('INSERT INTO units (code,name) VALUES (:code,:name)');
                $q->execute(['code'=>$code,'name'=>$name]);
                flash('Đã thêm đơn vị tính.');
            } else {
                if ($editingCode !== $code) throw new InvalidArgumentException('Mã đơn vị không thể thay đổi sau khi tạo.');
                $q = $db->prepare('UPDATE units SET name=:name WHERE code=:code');
                $q->execute(['code'=>$code,'name'=>$name]);
                flash('Đã cập nhật đơn vị tính.');
            }
        } elseif ($action === 'unit-toggle') {
            $code = field($_POST, 'code');
            $q = $db->prepare('UPDATE units SET active = NOT active WHERE code=:code AND (active=FALSE OR NOT EXISTS (SELECT 1 FROM items WHERE unit=:used_code))');
            $q->execute(['code'=>$code,'used_code'=>$code]);
            if ($q->rowCount() !== 1) { throw new InvalidArgumentException('Không thể ngừng đơn vị đang được mặt hàng sử dụng.'); }
            flash('Đã cập nhật trạng thái đơn vị tính.');
        } else {
            throw new InvalidArgumentException('Thao tác không hợp lệ.');
        }
        redirect('/items.php');
    } catch (Throwable $e) {
        $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Không lưu được dữ liệu. Hãy kiểm tra mã trùng hoặc quan hệ đang sử dụng.';
    }
}

$q = field($_GET, 'q');
$unit = field($_GET, 'unit');
$all = Store::all('items');
$units = Store::all('units');
$items = array_filter($all, fn($i) => ($q === '' || stripos($i['sku'].' '.$i['name'], $q) !== false) && ($unit === '' || $i['unit'] === $unit));
$editingBatch = isset($_GET['batch_edit']) ? Store::find('batches', intField($_GET,'batch_edit') ?? 0) : null;
$editingUnit = null;
foreach ($units as $candidateUnit) if ($candidateUnit['code'] === field($_GET,'unit_edit')) $editingUnit = $candidateUnit;
$title = 'Mặt hàng & lô'; $active = 'items';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head"><div><h1>Mặt hàng, đơn vị tính &amp; lô</h1><p>Danh mục được lưu trong PostgreSQL. Dữ liệu đã phát sinh chỉ được ngừng sử dụng, không xóa.</p></div></header>
<?php if ($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>
<form class="filters" method="get"><label>Tìm<input name="q" value="<?= e($q) ?>" placeholder="Mã hoặc tên"></label><label>Đơn vị tính<select name="unit"><option value="">Tất cả</option><?php foreach ($units as $u): ?><option value="<?= e($u['code']) ?>" <?= $unit===$u['code']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label><button class="button">Lọc</button></form>
<?php if ($manage): ?>
<section class="card"><h2><?= isset($_GET['edit']) ? 'Sửa mặt hàng' : 'Thêm mặt hàng' ?></h2>
<?php $editing = isset($_GET['edit']) ? Store::find('items', intField($_GET,'edit') ?? 0) : null; ?>
<form method="post" class="form"><?= csrfInput() ?><input type="hidden" name="action" value="item-save"><input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>"><div class="form-row"><label class="form-field">Mã<input name="sku" required maxlength="60" value="<?= e($editing['sku'] ?? '') ?>"></label><label class="form-field">Tên<input name="name" required maxlength="200" value="<?= e($editing['name'] ?? '') ?>"></label><label class="form-field">Đơn vị<select name="unit" required><?php foreach ($units as $u): if (!$u['active'] && ($editing['unit'] ?? '') !== $u['code']) continue; ?><option value="<?= e($u['code']) ?>" <?= ($editing['unit'] ?? '')===$u['code']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?></select></label><label class="form-field">Tồn tối thiểu<input name="min_stock" type="number" min="0" step="1" required value="<?= e((string)($editing['min_stock'] ?? 0)) ?>"></label><label><input type="checkbox" name="active" <?= !isset($editing['active']) || $editing['active']?'checked':'' ?>> Đang kinh doanh</label></div><button class="button">Lưu mặt hàng</button></form></section>
<section class="card"><h2><?= $editingUnit?'Sửa đơn vị tính':'Đơn vị tính' ?></h2><form method="post" class="inline-form"><?= csrfInput() ?><input type="hidden" name="action" value="unit-save"><input type="hidden" name="editing_code" value="<?= e($editingUnit['code']??'') ?>"><label>Mã<input name="code" maxlength="40" required value="<?= e($editingUnit['code']??'') ?>" <?= $editingUnit?'readonly':'' ?>></label><label>Tên<input name="name" maxlength="80" required value="<?= e($editingUnit['name']??'') ?>"></label><button class="button"><?= $editingUnit?'Cập nhật':'Thêm đơn vị' ?></button></form><ul class="chips"><?php foreach ($units as $u): ?><li><?= e($u['name']) ?><?= !$u['active']?' · Ngừng dùng':'' ?> <a class="text-link" href="/items.php?unit_edit=<?= rawurlencode($u['code']) ?>">Sửa</a> <form method="post" class="inline-form"><?= csrfInput() ?><input type="hidden" name="action" value="unit-toggle"><input type="hidden" name="code" value="<?= e($u['code']) ?>"><button class="link-button"><?= $u['active']?'Ngừng':'Kích hoạt' ?></button></form></li><?php endforeach; ?></ul></section>
<section class="card"><h2><?= $editingBatch?'Sửa lô hàng':'Thêm lô hàng' ?></h2><form method="post" class="inline-form"><?= csrfInput() ?><input type="hidden" name="action" value="batch-save"><input type="hidden" name="id" value="<?= $editingBatch['id']??'' ?>"><label>Mặt hàng<select name="item_id" required><?php foreach ($all as $i): if (!$i['active'] && ($editingBatch['item_id']??null)!==$i['id']) continue; ?><option value="<?= $i['id'] ?>" <?= ($editingBatch['item_id']??null)===$i['id']?'selected':'' ?>><?= e($i['sku'].' — '.$i['name']) ?></option><?php endforeach; ?></select></label><label>Mã lô<input name="code" maxlength="80" required value="<?= e($editingBatch['code']??'') ?>"></label><label>Hạn sử dụng<input type="date" name="expiry" value="<?= e($editingBatch['expiry']??'') ?>"></label><button class="button"><?= $editingBatch?'Cập nhật lô':'Thêm lô' ?></button></form></section>
<?php endif; ?>
<p class="result-count"><?= count($items) ?> mặt hàng</p><div class="table-wrap"><table class="table"><thead><tr><th>Mã</th><th>Tên</th><th>ĐVT</th><th class="num">Tồn tối thiểu</th><th>Lô hàng</th><?php if($manage): ?><th>Trạng thái / thao tác</th><?php endif; ?></tr></thead><tbody>
<?php foreach ($items as $item): ?><tr><td><?= e($item['sku']) ?></td><td><?= e($item['name']) ?></td><td><?= e($item['unit_name']) ?></td><td class="num"><?= qty($item['min_stock']) ?></td><td><ul class="chips"><?php foreach (Store::batchesOf($item['id']) as $b): ?><li><?= e($b['code']) ?><?= $b['expiry']?' · HSD '.e($b['expiry']):'' ?><?= !$b['active']?' · Ngừng':'' ?><?php if ($manage): ?> <a class="text-link" href="/items.php?batch_edit=<?= $b['id'] ?>">Sửa</a><form method="post" class="inline-form"><?= csrfInput() ?><input type="hidden" name="action" value="batch-toggle"><input type="hidden" name="id" value="<?= $b['id'] ?>"><button class="link-button"><?= $b['active']?'Ngừng':'Kích hoạt' ?></button></form><?php endif; ?></li><?php endforeach; ?></ul></td><?php if($manage): ?><td><?= $item['active']?'Đang dùng':'Ngừng dùng' ?> · <a class="text-link" href="/items.php?edit=<?= $item['id'] ?>">Sửa</a></td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
<?php if (!$items): ?><p class="empty">Không có mặt hàng phù hợp.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
