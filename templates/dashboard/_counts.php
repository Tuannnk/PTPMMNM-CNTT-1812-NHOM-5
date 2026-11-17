<?php
// Bảng tình trạng kiểm kê theo kho (dùng cho người kiểm kê và người duyệt).
$countRows = Dashboard::countOverview($scope);
?>
<?php if (!$countRows): ?><p class="empty">Không có kho trong phạm vi.</p><?php else: ?>
<div class="table-wrap"><table class="table table--compact">
  <thead><tr><th>Kho</th><th>Đợt đang mở</th><th>Lần chốt gần nhất</th><th>Tình trạng</th></tr></thead>
  <tbody><?php foreach ($countRows as $r): $days = $r['last_closed_at'] ? Dashboard::ageDays($r['last_closed_at']) : null; ?>
    <tr>
      <td><?= e($r['code'] . ' · ' . $r['name']) ?></td>
      <td><?= $r['open_count_id'] ? '<a class="text-link" href="/count.php?id=' . (int)$r['open_count_id'] . '">' . e($r['open_count_code']) . '</a>' : '—' ?></td>
      <td><?= $r['last_closed_at'] ? e(dateOnly($r['last_closed_at'])) . ' <span class="muted">(' . $days . ' ngày trước)</span>' : '<span class="muted">Chưa từng kiểm kê</span>' ?></td>
      <td><?php if ($r['open_count_id']): ?><span class="badge badge--wait">Đang kiểm</span>
        <?php elseif ($days === null || $days > 30): ?><span class="badge badge--rejected">Nên kiểm kê</span>
        <?php else: ?><span class="badge badge--ok">Ổn</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
