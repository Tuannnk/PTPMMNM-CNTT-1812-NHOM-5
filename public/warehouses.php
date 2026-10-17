<?php
require __DIR__ . '/../src/bootstrap.php';
$user = requireLogin();

$movements = Store::all('stock_movements');
$users = Store::all('users');
$title = 'Kho';
$active = 'warehouses';
require __DIR__ . '/../templates/header.php';
?>
<header class="page-head">
  <div><h1>Kho</h1><p>Danh mục kho và người được phân quyền thao tác tại từng kho.</p></div>
</header>
<section class="cards">
  <?php foreach (Store::all('warehouses') as $w):
      $byItem = StockLedger::byItem($movements, $w['id']);
      $staff = array_filter($users, fn($u) => in_array($w['id'], $u['warehouses'], true));
      $mine = canAccessWarehouse($user, $w['id']); ?>
    <article class="card warehouse<?= $mine ? '' : ' is-locked' ?>">
      <header>
        <h2><?= e($w['name']) ?></h2>
        <span class="badge"><?= e($w['code']) ?></span>
      </header>
      <p class="muted"><?= e($w['address']) ?></p>
      <dl class="meta">
        <div><dt>Mặt hàng có tồn</dt><dd><?= count(array_filter($byItem, fn($q) => $q > 0)) ?></dd></div>
        <div><dt>Tổng số lượng</dt><dd><?= qty(array_sum($byItem)) ?></dd></div>
        <div><dt>Phát sinh</dt><dd><?= count(array_filter($movements, fn($m) => $m['warehouse_id'] === $w['id'])) ?> dòng</dd></div>
      </dl>
      <p class="label">Người được phân quyền</p>
      <ul class="chips">
        <?php foreach ($staff as $u): ?><li><?= e($u['name']) ?> · <?= e(ROLE_LABELS[$u['role']]) ?></li><?php endforeach; ?>
      </ul>
      <?php if (!$mine): ?><p class="muted">Bạn không phụ trách kho này: không xem chi tiết tồn, không lập chứng từ.</p><?php endif; ?>
    </article>
  <?php endforeach; ?>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>
