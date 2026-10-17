<?php /** @var array $errors */ ?>
<?php if ($errors): ?>
<div class="error-summary" role="alert">
  <strong>Chưa thể ghi nhận chứng từ</strong>
  <ul>
    <?php foreach ($errors as $code): ?>
      <li><?= e(ERROR_LABELS[$code] ?? $code) ?> <code><?= e($code) ?></code></li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
