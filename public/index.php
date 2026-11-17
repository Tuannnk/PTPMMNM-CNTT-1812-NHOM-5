<?php
require __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/Dashboard.php';
$user = requireLogin();

// Mỗi vai trò có một bảng điều khiển riêng, ưu tiên "việc cần làm" của vai trò đó.
$role = $user['role'];
$partial = __DIR__ . '/../templates/dashboard/' . $role . '.php';
if (!array_key_exists($role, ROLE_LABELS) || !is_file($partial)) {
    forbid('ROLE_DASHBOARD_MISSING');
}
$scopeWarehouses = allowedWarehouses($user);
$scope = array_column($scopeWarehouses, 'id');
$salesPointIds = $role === 'sales_user' ? Dashboard::userSalesPointIds($user) : [];

$roleIntro = [
    'admin' => 'Theo dõi tài khoản, danh mục và tình trạng dữ liệu của toàn hệ thống.',
    'staff' => 'Việc cần xử lý tại kho bạn phụ trách: nhận hàng, gửi bù, xuất đơn phân phối và các phiếu của bạn.',
    'approver' => 'Hàng đợi chứng từ chờ bạn duyệt, sắp xếp theo thời gian chờ — phiếu chờ lâu nhất ở trên cùng.',
    'counter' => 'Phiếu điều chuyển chờ kiểm hàng và tình trạng kiểm kê của từng kho.',
    'central_manager' => 'Tồn kho của kho tổng và các kho địa điểm trực thuộc, hàng đang luân chuyển và đơn phân phối.',
    'regional_manager' => 'Đơn phân phối, điểm bán và tồn kho của kho địa điểm bạn quản lý.',
    'sales_user' => 'Hàng đang giao đến điểm bán của bạn, đơn đã đặt và tồn tại điểm bán.',
];
$scopeText = $role === 'sales_user'
    ? implode(', ', array_map(fn($id) => Store::find('sales_points', $id)['name'] ?? '—', $salesPointIds))
    : implode(', ', array_column($scopeWarehouses, 'name'));

$title = 'Bảng điều khiển';
$active = 'dashboard';
require __DIR__ . '/../templates/header.php';
require __DIR__ . '/../templates/dashboard/_widgets.php';
?>
<header class="page-head">
  <div>
    <h1>Bảng điều khiển <span class="badge"><?= e(ROLE_LABELS[$role]) ?></span></h1>
    <p><?= e($roleIntro[$role]) ?><?php if ($scopeText !== ''): ?> Phạm vi: <?= e($scopeText) ?>.<?php endif; ?></p>
  </div>
  <?php if (canCreateVoucher($user)): ?><div class="actions">
    <a class="button" href="/voucher-new.php?type=in">+ Phiếu nhập</a>
    <a class="button button--ghost" href="/voucher-new.php?type=out">Phiếu xuất</a>
    <?php if ($role === 'staff'): ?><a class="button button--ghost" href="/voucher-new.php?type=transfer">Điều chuyển</a><?php endif; ?>
  </div><?php elseif ($role === 'counter' || $role === 'approver'): ?><div class="actions">
    <a class="button<?= $role === 'approver' ? ' button--ghost' : '' ?>" href="/counts.php">Kiểm kê</a>
  </div><?php elseif ($role === 'sales_user'): ?><div class="actions">
    <a class="button" href="/sales-orders.php">+ Đặt hàng</a>
  </div><?php endif; ?>
</header>

<?php require $partial; ?>
<?php require __DIR__ . '/../templates/footer.php'; ?>
