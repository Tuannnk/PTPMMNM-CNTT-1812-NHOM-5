<?php
// Thành phần dùng chung cho bảng điều khiển theo vai trò.

/** Ô chỉ số; $tone: '' | 'warn' | 'danger' | 'ok'. */
function dashStat(string $label, int $value, string $href, string $tone = '', string $hint = ''): string
{
    $class = 'stat' . ($tone !== '' ? ' stat--' . $tone : '');
    return '<a class="' . $class . '" href="' . e($href) . '"><span>' . e($label) . '</span><strong>' . qty($value) . '</strong>'
        . ($hint !== '' ? '<small>' . e($hint) . '</small>' : '') . '</a>';
}

/** Nhãn thời gian chờ: càng lâu càng nổi bật. */
function ageBadge(?string $at, string $prefix = 'chờ'): string
{
    $days = Dashboard::ageDays($at);
    $class = $days >= 7 ? 'badge badge--rejected' : ($days >= 2 ? 'badge badge--wait' : 'badge');
    return '<span class="' . $class . '">' . e($prefix . ' ' . ($days === 0 ? 'hôm nay' : $days . ' ngày')) . '</span>';
}

function voucherRoute(array $v): string
{
    return e(warehouseName($v['warehouse_id'])) . ($v['to_warehouse_id'] ? ' → ' . e(warehouseName($v['to_warehouse_id'])) : '');
}

/** Danh sách chứng từ; $meta(voucher) trả HTML bổ sung cho từng dòng. */
function voucherList(array $vouchers, string $empty, ?callable $meta = null, int $limit = 8): string
{
    if (!$vouchers) return '<p class="empty">' . e($empty) . '</p>';
    $html = '<ul class="list">';
    foreach (array_slice($vouchers, 0, $limit) as $v) {
        $html .= '<li><a href="/voucher.php?id=' . (int)$v['id'] . '"><strong>' . e($v['code']) . '</strong></a> '
            . typeBadge($v['type']) . ' <span class="muted">' . voucherRoute($v) . '</span> '
            . ($meta ? $meta($v) : ageBadge($v['created_at'])) . '</li>';
    }
    $html .= '</ul>';
    if (count($vouchers) > $limit) {
        $html .= '<p class="muted">… và ' . (count($vouchers) - $limit) . ' phiếu khác.</p>';
    }
    return $html;
}

function salesOrderList(array $orders, string $empty): string
{
    if (!$orders) return '<p class="empty">' . e($empty) . '</p>';
    $html = '<ul class="list">';
    foreach ($orders as $o) {
        $html .= '<li><a href="/sales-order.php?id=' . (int)$o['id'] . '"><strong>' . e($o['code']) . '</strong></a> '
            . salesStatusBadge($o['status']) . ' '
            . '<span class="muted">' . e($o['warehouse_code'] . ' → ' . $o['point_name'])
            . ((int)$o['remaining'] > 0 ? ' · còn ' . qty((int)$o['remaining']) . ' cần xuất' : '') . '</span> '
            . ageBadge($o['created_at'], 'tạo') . '</li>';
    }
    return $html . '</ul>';
}

function lowStockTable(array $rows, string $empty = 'Không có mặt hàng dưới mức tối thiểu.'): string
{
    if (!$rows) return '<p class="empty">' . e($empty) . '</p>';
    $html = '<div class="table-wrap"><table class="table table--compact"><thead><tr><th>Kho</th><th>Mặt hàng</th>'
        . '<th class="num">Tồn</th><th class="num">Tối thiểu</th><th>Mức</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $ratio = (int)$r['min_stock'] > 0 ? max(0, min(1, (int)$r['balance'] / (int)$r['min_stock'])) : 0;
        $html .= '<tr><td>' . e($r['warehouse_code']) . '</td><td>' . e($r['sku'] . ' — ' . $r['name']) . '</td>'
            . '<td class="num qty-neg">' . qty((int)$r['balance']) . '</td><td class="num">' . qty((int)$r['min_stock']) . '</td>'
            . '<td><span class="meter' . ($ratio >= .5 ? ' meter--warn' : '') . '" role="img" aria-label="' . round($ratio * 100) . '% mức tối thiểu"><span style="width:' . round($ratio * 100) . '%"></span></span></td></tr>';
    }
    return $html . '</tbody></table></div>';
}

function recentMovementTable(array $rows): string
{
    if (!$rows) return '<p class="empty">Chưa có phát sinh trong phạm vi.</p>';
    $html = '<div class="table-wrap"><table class="table table--compact"><thead><tr><th>Ngày</th><th>Chứng từ</th><th>Kho</th>'
        . '<th>Mặt hàng</th><th class="num">SL</th></tr></thead><tbody>';
    foreach ($rows as $m) {
        $voucher = $m['voucher_id'] ? '<a class="text-link" href="/voucher.php?id=' . (int)$m['voucher_id'] . '">' . e($m['voucher_code']) . '</a>' : '—';
        $html .= '<tr><td>' . e(dateOnly($m['at'])) . '</td><td>' . $voucher . '</td><td>' . e($m['warehouse_code']) . '</td>'
            . '<td>' . e($m['item_name']) . '</td><td class="num">' . signedQty((int)$m['qty']) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}
