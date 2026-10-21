<?php
require __DIR__ . '/../src/bootstrap.php';

if (currentUser() !== null) {
    redirect('/');
}

$error = '';
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    $username = field($_POST, 'username');
    $password = $_POST['password'] ?? '';
    $password = is_string($password) ? $password : '';

    $user = $username !== '' && $password !== '' ? authenticate($username, $password) : null;
    if ($user !== null) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        redirect('/');
    }
    $error = 'Tên đăng nhập hoặc mật khẩu không đúng.';
}

$title = 'Đăng nhập';
$user = null;
require __DIR__ . '/../templates/header.php';
?>
<section class="login-page" aria-labelledby="login-title">
  <div class="login-panel">
    <aside class="login-aside" aria-label="Giới thiệu hệ thống">
      <a class="login-brand" href="/login.php" aria-label="Kho đa điểm, trang đăng nhập">
        <span class="brand-mark" aria-hidden="true">K</span>
        <span>Kho đa điểm<small>NHÓM 5 · PTPMMNM</small></span>
      </a>
      <div class="login-aside-copy">
        <span class="login-kicker">QUẢN LÝ KHO ĐA ĐIỂM</span>
        <h2>Kiểm soát tồn kho,<br>từ một nơi.</h2>
        <p>Theo dõi hàng hóa, chứng từ và hoạt động kiểm kê trên toàn bộ hệ thống kho.</p>
      </div>
      <div class="login-aside-foot"><span class="login-pulse" aria-hidden="true"></span> Sổ phát sinh minh bạch · Dữ liệu tập trung</div>
    </aside>

    <div class="login-content">
      <div class="login-mobile-brand">
        <span class="brand-mark" aria-hidden="true">K</span>
        <span>Kho đa điểm<small>NHÓM 5 · PTPMMNM</small></span>
      </div>
      <div class="login-heading">
        <span class="login-kicker">CHÀO MỪNG TRỞ LẠI</span>
        <h1 id="login-title">Đăng nhập</h1>
        <p>Nhập thông tin tài khoản để tiếp tục vào hệ thống.</p>
      </div>

      <?php if ($error): ?>
        <div class="error-summary login-error" role="alert"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" class="login-form">
        <?= csrfInput() ?>
        <label for="username">Tên đăng nhập</label>
        <input id="username" name="username" type="text" autocomplete="username" required maxlength="80" value="<?= e($username) ?>" autofocus>

        <label for="password">Mật khẩu</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button class="button login-submit" type="submit">Đăng nhập <span aria-hidden="true">→</span></button>
      </form>

      <p class="login-help">Nếu chưa có tài khoản, hãy liên hệ người quản trị hệ thống.</p>
    </div>
  </div>
  <p class="login-copyright">Hệ thống quản lý kho, điều chuyển hàng và kiểm kê tại nhiều điểm</p>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
