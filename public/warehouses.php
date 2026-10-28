<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin(); $manage = canManageCatalog($user); $db = Database::connection(); $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST); requireCatalogManager($user);
    try {
        $id = intField($_POST, 'id'); $code = strtoupper(field($_POST,'code')); $name = field($_POST,'name'); $address = field($_POST,'address');
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,29}$/',$code) || $name === '' || !textFits($name,160) || !textFits($address,2000)) throw new InvalidArgumentException('Thông tin kho chưa hợp lệ.');
        $activeFlag = isset($_POST['active']);
        if ($id === null) $q = $db->prepare('INSERT INTO warehouses (code,name,address,active) VALUES (:code,:name,:address,:active)');
        else $q = $db->prepare('UPDATE warehouses SET code=:code,name=:name,address=:address,active=:active WHERE id=:id');
        // PDO::execute() treats PHP false as an empty string in PostgreSQL boolean parameters.
        $q->execute(['code'=>$code,'name'=>$name,'address'=>$address,'active'=>$activeFlag ? 'true' : 'false'] + ($id === null ? [] : ['id'=>$id]));
        flash('Đã lưu thông tin kho.'); redirect('/warehouses.php');
    } catch (Throwable $e) { $error = $e instanceof InvalidArgumentException ? $e->getMessage() : 'Không lưu được. Mã kho có thể đã tồn tại.'; }
}
$movements = Store::all('stock_movements'); $users = Store::all('users'); $warehouses = Store::all('warehouses');
$q = field($_GET, 'q');
if ($q !== '') $warehouses = array_filter($warehouses, fn($w) => stripos($w['code'].' '.$w['name'].' '.$w['address'], $q) !== false);
$editing = isset($_GET['edit']) ? Store::find('warehouses', intField($_GET,'edit') ?? 0) : null;
$title='Kho'; $active='warehouses'; require __DIR__ . '/../templates/header.php';
?>
<header class="page-head"><div><h1>Kho</h1><p>Danh mục kho, trạng thái hoạt động và tài khoản được phân quyền.</p></div></header>
<?php if ($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if ($manage): ?><section class="card"><h2><?= $editing ? 'Sửa kho' : 'Thêm kho' ?></h2><form method="post" class="form"><?= csrfInput() ?><input type="hidden" name="id" value="<?= $editing['id'] ?? '' ?>"><div class="form-row"><label class="form-field">Mã kho<input name="code" maxlength="30" required value="<?= e($editing['code'] ?? '') ?>"></label><label class="form-field">Tên kho<input name="name" maxlength="160" required value="<?= e($editing['name'] ?? '') ?>"></label><label class="form-field form-field--wide">Địa chỉ<input name="address" value="<?= e($editing['address'] ?? '') ?>"></label><label><input type="checkbox" name="active" <?= !isset($editing['active']) || $editing['active']?'checked':'' ?>> Đang hoạt động</label></div><button class="button">Lưu kho</button></form></section><?php endif; ?>
<form class="filters" method="get"><label>Tìm kho<input name="q" value="<?= e($q) ?>" placeholder="Mã, tên hoặc địa chỉ"></label><button class="button">Tìm kiếm</button><?php if ($q !== ''): ?><a class="button button--ghost" href="/warehouses.php">Xóa tìm kiếm</a><?php endif; ?></form>
<section class="cards"><?php foreach ($warehouses as $w): $byItem=StockLedger::byItem($movements,$w['id']); $staff=array_filter($users,fn($u)=>in_array($w['id'],$u['warehouses'],true)); $mine=canAccessWarehouse($user,$w['id']); ?>
<article class="card warehouse<?= $mine?'':' is-locked' ?>"><header><h2><?= e($w['name']) ?></h2><span class="badge"><?= e($w['code']) ?></span></header><p class="muted"><?= e($w['address']) ?></p><p><?= $w['active']?'Đang hoạt động':'Đã ngừng hoạt động' ?></p><dl class="meta"><div><dt>Mặt hàng có tồn</dt><dd><?= count(array_filter($byItem,fn($q)=>$q>0)) ?></dd></div><div><dt>Tổng số lượng</dt><dd><?= qty(array_sum($byItem)) ?></dd></div><div><dt>Phát sinh</dt><dd><?= count(array_filter($movements,fn($m)=>$m['warehouse_id']===$w['id'])) ?> dòng</dd></div></dl><p class="label">Người được phân quyền</p><ul class="chips"><?php foreach($staff as $u): ?><li><?= e($u['name']) ?> · <?= e(ROLE_LABELS[$u['role']]) ?></li><?php endforeach; ?></ul><?php if($manage): ?><a class="text-link" href="/warehouses.php?edit=<?= $w['id'] ?>">Sửa kho</a><?php endif; ?><?php if(!$mine): ?><p class="muted">Bạn không phụ trách kho này.</p><?php endif; ?></article><?php endforeach; ?></section>
<?php if (!$warehouses): ?><p class="empty">Không tìm thấy kho phù hợp.</p><?php endif; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
