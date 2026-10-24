<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin(); requireCatalogManager($user);
$db = Database::connection(); $error='';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    try {
        $id = intField($_POST,'id'); $username=field($_POST,'username'); $name=field($_POST,'full_name');
        $role=field($_POST,'role'); $password=$_POST['password'] ?? ''; $password=is_string($password)?$password:'';
        $activeFlag=isset($_POST['active']); $warehouseIds=array_values(array_unique(array_filter(array_map('intval',is_array($_POST['warehouse_id'] ?? null)?$_POST['warehouse_id']:[]),fn($n)=>$n>0)));
        if (!preg_match('/^[a-zA-Z0-9._-]{2,80}$/',$username) || $name==='' || !textFits($name,160)
            || !in_array($role,['staff','approver','counter','admin'],true)
            || ($id===null && strlen($password)<10) || ($password!=='' && strlen($password)<10)) {
            throw new InvalidArgumentException('Thông tin tài khoản chưa hợp lệ. Mật khẩu mới cần ít nhất 10 ký tự.');
        }
        $validWarehouses=array_map('intval',$db->query('SELECT id FROM warehouses WHERE active=TRUE')->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($warehouseIds,$validWarehouses)) throw new InvalidArgumentException('Chỉ có thể gán tài khoản vào kho đang hoạt động.');
        if (!$warehouseIds) throw new InvalidArgumentException('Chọn ít nhất một kho cho tài khoản.');

        $db->beginTransaction();
        if ($id===null) {
            $sql='INSERT INTO users (username,full_name,role,password_hash,active) VALUES (:username,:name,:role,:hash,:active) RETURNING id';
            $q=$db->prepare($sql); $q->execute(['username'=>$username,'name'=>$name,'role'=>$role,'hash'=>password_hash($password,PASSWORD_DEFAULT),'active'=>$activeFlag ? 'true' : 'false']); $id=(int)$q->fetchColumn();
        } else {
            $target=Store::find('users',$id);
            if (!$target) throw new InvalidArgumentException('Tài khoản không tồn tại.');
            if ($target['role']==='admin' && $target['active'] && (!$activeFlag || $role!=='admin')) {
                $admins=(int)$db->query("SELECT count(*) FROM users WHERE role='admin' AND active=TRUE")->fetchColumn();
                if ($admins<=1) throw new InvalidArgumentException('Không thể vô hiệu hóa hoặc đổi vai trò quản trị viên hoạt động cuối cùng.');
            }
            if ($password==='') {
                $q=$db->prepare('UPDATE users SET username=:username,full_name=:name,role=:role,active=:active WHERE id=:id');
                $q->execute(['username'=>$username,'name'=>$name,'role'=>$role,'active'=>$activeFlag ? 'true' : 'false','id'=>$id]);
            } else {
                $q=$db->prepare('UPDATE users SET username=:username,full_name=:name,role=:role,password_hash=:hash,active=:active WHERE id=:id');
                $q->execute(['username'=>$username,'name'=>$name,'role'=>$role,'hash'=>password_hash($password,PASSWORD_DEFAULT),'active'=>$activeFlag ? 'true' : 'false','id'=>$id]);
            }
            $q=$db->prepare('DELETE FROM user_warehouses WHERE user_id=:id'); $q->execute(['id'=>$id]);
        }
        $q=$db->prepare('INSERT INTO user_warehouses (user_id,warehouse_id) VALUES (:user,:warehouse)');
        foreach($warehouseIds as $warehouseId) $q->execute(['user'=>$id,'warehouse'=>$warehouseId]);
        $db->commit(); flash('Đã lưu tài khoản và phạm vi kho.'); redirect('/access.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $error=$e instanceof InvalidArgumentException?$e->getMessage():'Không lưu được. Tên đăng nhập có thể đã tồn tại.';
    }
}
$accounts=Store::all('users'); $warehouses=array_values(array_filter(Store::all('warehouses'),fn($w)=>$w['active']));
$editing=isset($_GET['edit'])?Store::find('users',intField($_GET,'edit')??0):null;
$title='Tài khoản & phân quyền'; $active='access'; require __DIR__.'/../templates/header.php';
?>
<header class="page-head"><div><h1>Tài khoản &amp; phân quyền</h1><p>Gán vai trò và phạm vi kho. Chỉ quản trị viên được thay đổi quyền.</p></div></header>
<?php if($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>
<section class="card"><h2><?= $editing?'Sửa tài khoản':'Tạo tài khoản' ?></h2><form method="post" class="form"><?= csrfInput() ?><input type="hidden" name="id" value="<?= $editing['id']??'' ?>"><div class="form-row"><label class="form-field">Tên đăng nhập<input name="username" required maxlength="80" value="<?= e($editing['username']??'') ?>"></label><label class="form-field">Họ tên<input name="full_name" required maxlength="160" value="<?= e($editing['name']??'') ?>"></label><label class="form-field">Vai trò<select name="role"><?php foreach(ROLE_LABELS as $key=>$label): ?><option value="<?= e($key) ?>" <?= ($editing['role']??'staff')===$key?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><label class="form-field">Mật khẩu <?= $editing?'(để trống nếu không đổi)':'' ?><input type="password" name="password" <?= $editing?'':'required' ?> minlength="10" autocomplete="new-password"></label><label><input type="checkbox" name="active" <?= !isset($editing['active'])||$editing['active']?'checked':'' ?>> Đang hoạt động</label></div><fieldset><legend>Kho được phép thao tác</legend><?php foreach($warehouses as $w): ?><label class="chip"><input type="checkbox" name="warehouse_id[]" value="<?= $w['id'] ?>" <?= in_array($w['id'],$editing['warehouses']??[],true)?'checked':'' ?>> <?= e($w['name']) ?></label><?php endforeach; ?></fieldset><button class="button">Lưu tài khoản</button></form></section>
<section class="card"><h2>Tài khoản hiện có</h2><div class="table-wrap"><table class="table"><thead><tr><th>Tên đăng nhập</th><th>Họ tên</th><th>Vai trò</th><th>Kho được gán</th><th>Trạng thái</th><th></th></tr></thead><tbody><?php foreach($accounts as $account): ?><tr><td><?= e($account['username']) ?></td><td><?= e($account['name']) ?></td><td><?= e(ROLE_LABELS[$account['role']]) ?></td><td><?= e(implode(', ',array_map(fn($id)=>warehouseName($id),$account['warehouses']))) ?></td><td><?= $account['active']?'Đang hoạt động':'Đã khóa' ?></td><td><a class="text-link" href="/access.php?edit=<?= $account['id'] ?>">Sửa</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php require __DIR__.'/../templates/footer.php'; ?>
