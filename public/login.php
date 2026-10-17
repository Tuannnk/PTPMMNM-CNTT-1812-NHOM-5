<?php
require __DIR__ . '/../src/bootstrap.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $userId = intField($_POST, 'user_id');
    if ($userId !== null && Store::find('users', $userId) !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        redirect('/');
    }
    $error = 'Tài khoản không hợp lệ.';
}
$title = 'Đăng nhập';
$user = null;
require __DIR__ . '/../templates/header.php';
?>
<section class="login">
  <h1>Hệ thống quản lý kho đa điểm</h1>
  <p class="lead">Chọn một tài khoản mẫu để xem giao diện theo vai trò và phạm vi kho. Bản giao diện chưa dùng mật khẩu.</p>
  <?php if ($error): ?><div class="error-summary" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="login-grid">
    <?= csrfInput() ?>
    <?php foreach (Store::all('users') as $u): ?>
      <button class="login-card" name="user_id" value="<?= $u['id'] ?>">
        <strong><?= e($u['name']) ?></strong>
        <span><?= e(ROLE_LABELS[$u['role']]) ?></span>
        <span class="muted">@<?= e($u['username']) ?> · <?= e(implode(', ', array_map(fn($id) => Store::find('warehouses', $id)['code'], $u['warehouses']))) ?></span>
      </button>
    <?php endforeach; ?>
  </form>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
