<?php
/** @var string $title  @var string $active  @var array|null $user */
$user ??= currentUser();
$active ??= '';
$nav = [
    'Tổng quan' => [['dashboard', '/', 'Bảng điều khiển']],
    'Chứng từ' => [
        ['vouchers', '/vouchers.php', 'Danh sách chứng từ'],
        ['voucher-in', '/voucher-new.php?type=in', 'Lập phiếu nhập'],
        ['voucher-out', '/voucher-new.php?type=out', 'Lập phiếu xuất'],
        ['transfer', '/voucher-new.php?type=transfer', 'Điều chuyển kho'],
    ],
    'Tồn kho' => [
        ['stock', '/stock.php', 'Tra cứu tồn'],
        ['history', '/history.php', 'Lịch sử phát sinh'],
        ['counts', '/counts.php', 'Kiểm kê'],
    ],
    'Danh mục' => [
        ['warehouses', '/warehouses.php', 'Kho'],
        ['items', '/items.php', 'Mặt hàng & lô'],
    ],
];
if ($user && canManageCatalog($user)) {
    $nav['Danh mục'][] = ['access', '/access.php', 'Tài khoản & phân quyền'];
}
$message = flash();
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Kho đa điểm') ?> · Kho đa điểm</title>
<link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
<a class="skip-link" href="#main">Đến nội dung chính</a>
<div class="shell">
<?php if ($user): ?>
<aside class="sidebar" aria-label="Điều hướng chính">
  <a class="brand" href="/">
    <span class="brand-mark" aria-hidden="true">K</span>
    <span>Kho đa điểm<small>Nhóm 5 · PTPMMNM</small></span>
  </a>
  <nav>
    <?php foreach ($nav as $group => $links): ?>
      <p class="nav-group"><?= e($group) ?></p>
      <?php foreach ($links as [$key, $href, $label]): ?>
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="user-card">
    <strong><?= e($user['name']) ?></strong>
    <span><?= e(ROLE_LABELS[$user['role']]) ?></span>
    <span class="user-scope"><?= e(implode(', ', array_column(allowedWarehouses($user), 'code'))) ?></span>
    <form method="post" action="/logout.php"><?= csrfInput() ?><button class="link-button">Đổi tài khoản</button></form>
  </div>
</aside>
<?php endif; ?>
<main id="main" tabindex="-1" class="content<?= $user ? '' : ' content--bare' ?>">
<?php if ($message): ?>
  <div class="notice" role="status"><?= e($message) ?></div>
<?php endif; ?>
