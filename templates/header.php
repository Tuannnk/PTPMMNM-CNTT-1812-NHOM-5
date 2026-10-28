<?php
/** @var string $title  @var string $active  @var array|null $user */
$user ??= currentUser();
$active ??= '';
$nav = [
    'Tổng quan' => [['dashboard', '/', 'Bảng điều khiển']],
    'Chứng từ' => [
        ['vouchers', '/vouchers.php', 'Danh sách chứng từ'],
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
if ($user && canCreateVoucher($user)) {
    array_splice($nav['Chứng từ'], 1, 0, [
        ['voucher-in', '/voucher-new.php?type=in', 'Lập phiếu nhập'],
        ['voucher-out', '/voucher-new.php?type=out', 'Lập phiếu xuất'],
        ['transfer', '/voucher-new.php?type=transfer', 'Điều chuyển kho'],
    ]);
}
if ($user && canManageCatalog($user)) {
    $nav['Danh mục'][] = ['access', '/access.php', 'Tài khoản & phân quyền'];
}
$navIcons = [
    'dashboard' => '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.5"/></svg>',
    'vouchers' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 3.5h7l4 4v13H7z"/><path d="M14 3.5v4h4M10 12h5M10 16h5"/></svg>',
    'voucher-in' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5v11m0 0 4-4m-4 4-4-4"/><path d="M5 14.5v5h14v-5"/></svg>',
    'voucher-out' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.5v-11m0 0-4 4m4-4 4 4"/><path d="M5 9.5v-5h14v5"/></svg>',
    'transfer' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h15m0 0-4-4m4 4-4 4M20 16H5m0 0 4-4m-4 4 4 4"/></svg>',
    'stock' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8 4.5v9L12 21l-8-4.5v-9L12 3Z"/><path d="m4.5 7.8 7.5 4.4 7.5-4.4M12 12.2V21"/></svg>',
    'history' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5"/><path d="M12 7v5l3.5 2"/><path d="M4 5v4h4"/></svg>',
    'counts' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4.5h8M9 3h6v3H9zM6 5h-2v16h16V5h-2"/><path d="m8 13 2.5 2.5L16 10"/></svg>',
    'warehouses' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 9 9-5 9 5v11H3z"/><path d="M8 20v-7h8v7M8 9h.01M12 9h.01M16 9h.01"/></svg>',
    'items' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9L12 3Z"/><path d="m3.8 7.7 8.2 4.5 8.2-4.5M12 12.2V21"/></svg>',
    'access' => '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="9" cy="8" r="3.5"/><path d="M3 20v-1.5a6 6 0 0 1 12 0V20zM16 5.2a3.5 3.5 0 0 1 0 6.6m2 2a5.5 5.5 0 0 1 3 4.7V20h-4"/></svg>',
];
$userInitial = 'U';
if ($user && preg_match('/^\s*(.)/u', $user['name'], $initialMatch)) {
    $userInitial = $initialMatch[1];
}
$message = flash();
?>
<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Kho đa điểm') ?> · Kho đa điểm</title>
<script>
(() => {
  let theme = '';
  try { theme = localStorage.getItem('warehouse-theme') || ''; } catch (_) {}
  if (!['light', 'dark'].includes(theme)) {
    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }
  document.documentElement.dataset.theme = theme;
})();
</script>
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
        <a href="<?= e($href) ?>" class="<?= $key === $active ? 'is-active' : '' ?>"<?= $key === $active ? ' aria-current="page"' : '' ?>>
          <span class="nav-icon"><?= $navIcons[$key] ?? '' ?></span><span class="nav-label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="user-card">
    <span class="user-avatar" aria-hidden="true"><?= e($userInitial) ?></span>
    <div class="user-identity">
      <strong><?= e($user['name']) ?></strong>
      <span><?= e(ROLE_LABELS[$user['role']]) ?></span>
      <span class="user-scope"><?= e(implode(', ', array_column(allowedWarehouses($user), 'code'))) ?></span>
    </div>
    <form class="user-actions" method="post" action="/logout.php">
      <?= csrfInput() ?>
      <button class="link-button" aria-label="Đổi tài khoản" title="Đổi tài khoản"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20M9.5 10.5a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM16 11h5m-2.5-2.5L21 11l-2.5 2.5"/></svg><span>Đổi tài khoản</span></button>
    </form>
  </div>
</aside>
<?php endif; ?>
<main id="main" tabindex="-1" class="content<?= $user ? '' : ' content--bare' ?>">
<div class="theme-toolbar">
  <button type="button" class="theme-toggle" id="theme-toggle" aria-pressed="false">
    <span class="theme-toggle-icon" aria-hidden="true">☾</span>
    <span class="theme-toggle-label">Bật giao diện tối</span>
  </button>
</div>
<script>
(() => {
  const button = document.getElementById('theme-toggle');
  if (!button) return;
  const icon = button.querySelector('.theme-toggle-icon');
  const label = button.querySelector('.theme-toggle-label');
  const update = () => {
    const dark = document.documentElement.dataset.theme === 'dark';
    button.setAttribute('aria-pressed', String(dark));
    button.setAttribute('aria-label', dark ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối');
    icon.textContent = dark ? '☼' : '☾';
    label.textContent = dark ? 'Bật giao diện sáng' : 'Bật giao diện tối';
  };
  button.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = theme;
    try { localStorage.setItem('warehouse-theme', theme); } catch (_) {}
    update();
  });
  update();
})();
</script>
<?php if ($message): ?>
  <div class="notice" role="status"><?= e($message) ?></div>
<?php endif; ?>
