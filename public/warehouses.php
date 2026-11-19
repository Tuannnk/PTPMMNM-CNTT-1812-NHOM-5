<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin(); $manage = canManageCatalog($user); $db = Database::connection(); $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST); requireCatalogManager($user);
    try {
        $id = intField($_POST, 'id'); $code = strtoupper(field($_POST,'code')); $name = field($_POST,'name'); $address = field($_POST,'address');
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/',$code) || $name === '' || !textFits($name,160) || !textFits($address,2000)) throw new InvalidArgumentException('Thông tin kho chưa hợp lệ.');
        $activeFlag = isset($_POST['active']);
        // Kho tổng không có kho cha; kho địa điểm phải trực thuộc một kho tổng đang hoạt động.
        $type = field($_POST, 'type');
        $parentId = $type === 'REGIONAL' ? intField($_POST, 'parent_id') : null;
        if (!array_key_exists($type, WAREHOUSE_TYPES)) throw new InvalidArgumentException('Chọn loại kho.');
        $current = $id !== null ? Store::find('warehouses', $id) : null;
        if ($type === 'REGIONAL') {
            $parent = $parentId !== null ? Store::find('warehouses', $parentId) : null;
            // Kho cha đã ngừng hoạt động chỉ được giữ nguyên (dữ liệu cũ), không được chọn mới.
            $keepsCurrentParent = $current !== null && $parentId === ($current['parent_id'] ?? null);
            if (!$parent || $parentId === $id || ($parent['type'] ?? null) !== 'CENTRAL' || (!$parent['active'] && !$keepsCurrentParent)) throw new InvalidArgumentException(ERROR_LABELS['WAREHOUSE_PARENT_INVALID']);
        }
        if ($id !== null) {
            $children = $db->prepare('SELECT count(*) AS total, count(*) FILTER (WHERE active) AS active_total FROM warehouses WHERE parent_id=:id');
            $children->execute(['id' => $id]);
            $childCount = $children->fetch();
            if ($type === 'REGIONAL' && (int)$childCount['total'] > 0) throw new InvalidArgumentException('Kho này đang có kho địa điểm trực thuộc nên không đổi thành kho địa điểm được.');
            if (!$activeFlag && (int)$childCount['active_total'] > 0) throw new InvalidArgumentException('Kho tổng đang có kho địa điểm hoạt động trực thuộc. Chuyển các kho đó sang kho tổng khác hoặc ngừng chúng trước khi ngừng kho tổng.');
        }
        if ($id === null) $q = $db->prepare('INSERT INTO warehouses (code,name,address,active,type,parent_id) VALUES (:code,:name,:address,:active,:type,:parent)');
        else $q = $db->prepare('UPDATE warehouses SET code=:code,name=:name,address=:address,active=:active,type=:type,parent_id=:parent WHERE id=:id');
        // PDO::execute() treats PHP false as an empty string in PostgreSQL boolean parameters.
        $q->execute(['code'=>$code,'name'=>$name,'address'=>$address,'active'=>$activeFlag ? 'true' : 'false','type'=>$type,'parent'=>$parentId] + ($id === null ? [] : ['id'=>$id]));
        flash('Đã lưu thông tin kho.'); redirect('/warehouses.php');
    } catch (Throwable $e) { $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Không lưu được. Mã kho có thể đã tồn tại.'; }
}
$movements = Store::all('stock_movements'); $users = Store::all('users'); $warehouses = Store::all('warehouses');
$centralWarehouses = array_values(array_filter($warehouses, fn($w) => ($w['type'] ?? null) === 'CENTRAL' && $w['active']));
if (!$manage) {
    $warehouses = array_values(array_filter($warehouses, fn($warehouse) => canAccessWarehouse($user, $warehouse['id'])));
}
$q = field($_GET, 'q');
if ($q !== '') $warehouses = array_filter($warehouses, fn($w) => stripos($w['code'].' '.$w['name'].' '.$w['address'], $q) !== false);
$editing = isset($_GET['edit']) ? Store::find('warehouses', intField($_GET,'edit') ?? 0) : null;
$title='Kho'; $active='warehouses'; require __DIR__ . '/../templates/header.php';
?>
<header class="page-head"><div><h1>Kho</h1><p>Danh mục kho, trạng thái hoạt động và tài khoản được phân quyền.</p></div></header>
<?php if ($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($manage): ?><section class="card"><h2><?= $editing ? 'Sửa kho' : 'Thêm kho' ?></h2><form method="post" class="form"><?= csrfInput() ?><input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>"><div class="form-row"><label class="form-field">Mã kho<input name="code" maxlength="30" required value="<?= e($editing['code'] ?? '') ?>"></label><label class="form-field">Tên kho<input name="name" maxlength="160" required value="<?= e($editing['name'] ?? '') ?>"></label><label class="form-field form-field--wide">Địa chỉ<input name="address" value="<?= e($editing['address'] ?? '') ?>"></label><?php $editType = $_SERVER['REQUEST_METHOD']==='POST' ? field($_POST,'type') : ($editing['type'] ?? 'REGIONAL'); $editParent = $_SERVER['REQUEST_METHOD']==='POST' ? intField($_POST,'parent_id') : ($editing['parent_id'] ?? null); ?><label class="form-field">Loại kho<select name="type" required><?php foreach (WAREHOUSE_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $editType === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label class="form-field">Thuộc kho tổng <span class="muted">(chỉ với kho địa điểm)</span><select name="parent_id"><option value="">—</option><?php $parentChoices = $centralWarehouses; if ($editing && !empty($editing['parent_id']) && !in_array($editing['parent_id'], array_column($parentChoices, 'id'), true) && ($p = Store::find('warehouses', $editing['parent_id']))) $parentChoices[] = $p; foreach ($parentChoices as $c): if ($editing && $c['id'] === $editing['id']) continue; ?><option value="<?= $c['id'] ?>" <?= $editParent === $c['id'] ? 'selected' : '' ?>><?= e($c['code'].' · '.$c['name']) ?><?= $c['active'] ? '' : ' (ngừng hoạt động)' ?></option><?php endforeach; ?></select></label><label><input type="checkbox" name="active" <?= !isset($editing['active']) || $editing['active']?'checked':'' ?>> Đang hoạt động</label></div><button class="button">Lưu kho</button></form></section><?php endif; ?>
<form class="filters" method="get"><label>Tìm kho<input name="q" value="<?= e($q) ?>" placeholder="Mã, tên hoặc địa chỉ"></label><button class="button">Tìm kiếm</button><?php if ($q !== ''): ?><a class="button button--ghost" href="/warehouses.php">Xóa tìm kiếm</a><?php endif; ?></form>
<section class="cards"><?php foreach ($warehouses as $w): $byItem=StockLedger::byItem($movements,$w['id']); $staff=array_filter($users,fn($u)=>in_array($w['id'],$u['warehouses'],true)); $mine=canAccessWarehouse($user,$w['id']); ?>
<article class="card warehouse<?= $mine?'':' is-locked' ?>"><header><h2><?php if($mine): ?><a class="text-link" href="/stock.php?warehouse_id=<?= $w['id'] ?>" aria-label="Xem mặt hàng còn tồn tại <?= e($w['name']) ?>"><?= e($w['name']) ?></a><?php else: ?><?= e($w['name']) ?><?php endif; ?></h2><span class="badge"><?= e($w['code']) ?></span></header><p><?= isset($w['type']) && isset(WAREHOUSE_TYPES[$w['type']]) ? e(WAREHOUSE_TYPES[$w['type']]) : '<span class="muted">Chưa phân loại</span>' ?><?= !empty($w['parent_id']) ? ' · thuộc ' . e(warehouseName($w['parent_id'])) : '' ?></p><p class="muted"><?= e($w['address']) ?></p><p><?= $w['active']?'Đang hoạt động':'Đã ngừng hoạt động' ?></p><dl class="meta"><div><dt>Mặt hàng có tồn</dt><dd><?= count(array_filter($byItem,fn($q)=>$q>0)) ?></dd></div><div><dt>Tổng số lượng</dt><dd><?= qty(array_sum($byItem)) ?></dd></div><div><dt>Phát sinh</dt><dd><?= count(array_filter($movements,fn($m)=>$m['warehouse_id']===$w['id'])) ?> dòng</dd></div></dl><?php if($mine): ?><p><a class="text-link" href="/stock.php?warehouse_id=<?= $w['id'] ?>">Xem mặt hàng còn tồn →</a></p><?php endif; ?><p class="label">Người được phân quyền</p><ul class="chips"><?php foreach($staff as $u): ?><li><?= e($u['name']) ?> · <?= e(ROLE_LABELS[$u['role']]) ?></li><?php endforeach; ?></ul><?php if($manage): ?><a class="text-link" href="/warehouses.php?edit=<?= $w['id'] ?>">Sửa kho</a><?php endif; ?><?php if(!$mine): ?><p class="muted">Bạn không phụ trách kho này.</p><?php endif; ?></article><?php endforeach; ?></section>
<?php if (!$warehouses): ?><p class="empty">Không tìm thấy kho phù hợp.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
