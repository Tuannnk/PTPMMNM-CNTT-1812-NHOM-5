<?php
declare(strict_types=1);
// Dữ liệu giả phục vụ giao diện mẫu: 3 kho, 30 mặt hàng, >= 200 dòng phát sinh.
// Sinh tất định (cùng seed luôn ra cùng dữ liệu). Sẽ thay bằng CSDL ở giai đoạn sau.

$warehouses = [
    ['id' => 1, 'code' => 'KHO-HN', 'name' => 'Kho Hà Nội', 'address' => 'Số 12 đường Giả Lập, Hà Nội'],
    ['id' => 2, 'code' => 'KHO-DN', 'name' => 'Kho Đà Nẵng', 'address' => 'Lô B3 khu Mẫu, Đà Nẵng'],
    ['id' => 3, 'code' => 'KHO-HCM', 'name' => 'Kho TP.HCM', 'address' => 'Số 45 đường Thử Nghiệm, TP.HCM'],
];

$users = [
    ['id' => 1, 'username' => 'nv.hn', 'name' => 'Nguyễn Văn An', 'role' => 'staff', 'warehouses' => [1]],
    ['id' => 2, 'username' => 'nv.dn', 'name' => 'Trần Thị Bình', 'role' => 'staff', 'warehouses' => [2]],
    ['id' => 3, 'username' => 'duyet', 'name' => 'Lê Minh Châu', 'role' => 'approver', 'warehouses' => [1, 2, 3]],
    ['id' => 4, 'username' => 'kiemke', 'name' => 'Phạm Quốc Dũng', 'role' => 'counter', 'warehouses' => [1, 3]],
];

$itemNames = [
    ['Giấy in A4 70gsm', 'ram'], ['Giấy in A3', 'ram'], ['Bút bi xanh', 'hộp'], ['Bút dạ quang', 'hộp'],
    ['Kẹp giấy 32mm', 'hộp'], ['Ghim bấm số 10', 'hộp'], ['Băng keo trong 5cm', 'cuộn'], ['Băng keo giấy', 'cuộn'],
    ['Thùng carton 40x30', 'cái'], ['Thùng carton 60x40', 'cái'], ['Màng PE quấn hàng', 'cuộn'], ['Xốp chèn hàng', 'kg'],
    ['Mực in laser đen', 'hộp'], ['Mực in phun màu', 'hộp'], ['Chuột không dây', 'cái'], ['Bàn phím USB', 'cái'],
    ['Cáp HDMI 2m', 'cái'], ['Ổ cắm điện 6 lỗ', 'cái'], ['Pin AA', 'hộp'], ['Bóng đèn LED 9W', 'cái'],
    ['Găng tay bảo hộ', 'đôi'], ['Khẩu trang y tế', 'hộp'], ['Nước rửa tay 500ml', 'chai'], ['Khăn giấy hộp', 'hộp'],
    ['Túi nilon lớn', 'kg'], ['Dây rút nhựa', 'túi'], ['Nhãn dán mã vạch', 'cuộn'], ['Sổ ghi chép A5', 'quyển'],
    ['Bìa hồ sơ nhựa', 'cái'], ['Thước kẻ 30cm', 'cái'],
];

$items = [];
$batches = [];
$batchId = 1;
foreach ($itemNames as $i => [$name, $unit]) {
    $id = $i + 1;
    $items[] = [
        'id' => $id,
        'sku' => sprintf('MH%03d', $id),
        'name' => $name,
        'unit' => $unit,
        'min_stock' => 10 + ($id % 4) * 5,
    ];
    $count = $id % 3 === 0 ? 2 : 1;
    for ($b = 1; $b <= $count; $b++) {
        $batches[] = [
            'id' => $batchId++,
            'item_id' => $id,
            'code' => sprintf('L%03d-%02d', $id, $b),
            'expiry' => $id >= 21 && $id <= 24 ? sprintf('2027-%02d-28', ($id + $b) % 12 + 1) : null,
        ];
    }
}

$batchesByItem = [];
foreach ($batches as $batch) {
    $batchesByItem[$batch['item_id']][] = $batch['id'];
}

mt_srand(20261017);
$vouchers = [];
$voucherLines = [];
$movements = [];
$balance = []; // "kho:lô" => số lượng
$counters = ['in' => 0, 'out' => 0, 'transfer' => 0, 'adjust' => 0];
$prefix = ['in' => 'PN', 'out' => 'PX', 'transfer' => 'PC', 'adjust' => 'DC'];
$approverByWarehouse = [1 => 3, 2 => 3, 3 => 3];
$staffByWarehouse = [1 => 1, 2 => 2, 3 => 3];
$base = strtotime('2026-08-01 08:00:00');

$addVoucher = function (string $type, int $wh, ?int $to, array $lines, int $at, string $status, string $note = '', ?int $ref = null)
    use (&$vouchers, &$voucherLines, &$movements, &$balance, &$counters, $prefix, $staffByWarehouse, $approverByWarehouse): int {
    $id = count($vouchers) + 1;
    $counters[$type]++;
    $vouchers[] = [
        'id' => $id,
        'code' => sprintf('%s-%04d', $prefix[$type], $counters[$type]),
        'type' => $type,
        'status' => $status,
        'warehouse_id' => $wh,
        'to_warehouse_id' => $to,
        'created_by' => $staffByWarehouse[$wh],
        'confirmed_by' => $status === 'confirmed' ? $approverByWarehouse[$wh] : null,
        'created_at' => date('Y-m-d H:i', $at),
        'confirmed_at' => $status === 'confirmed' ? date('Y-m-d H:i', $at + 1800) : null,
        'note' => $note,
        'ref_id' => $ref,
    ];
    foreach ($lines as $line) {
        $voucherLines[] = ['voucher_id' => $id] + $line;
        if ($status !== 'confirmed') {
            continue;
        }
        $signed = match ($type) {
            'in' => [[$wh, $line['qty']]],
            'out' => [[$wh, -$line['qty']]],
            'transfer' => [[$wh, -$line['qty']], [$to, $line['qty']]],
            'adjust' => [[$wh, $line['qty']]],
        };
        foreach ($signed as [$w, $qty]) {
            $movements[] = [
                'id' => count($movements) + 1,
                'voucher_id' => $id,
                'warehouse_id' => $w,
                'item_id' => $line['item_id'],
                'batch_id' => $line['batch_id'],
                'qty' => $qty,
                'at' => date('Y-m-d H:i', $at + 1800),
            ];
            $key = $w . ':' . $line['batch_id'];
            $balance[$key] = ($balance[$key] ?? 0) + $qty;
        }
    }
    return $id;
};

$pickLines = function (int $wh, bool $needStock, int $n) use ($items, $batchesByItem, &$balance): array {
    $lines = [];
    $used = [];
    for ($tries = 0; count($lines) < $n && $tries < 40; $tries++) {
        $item = $items[mt_rand(0, count($items) - 1)];
        $batchIds = $batchesByItem[$item['id']];
        $batch = $batchIds[mt_rand(0, count($batchIds) - 1)];
        if (isset($used[$batch])) {
            continue;
        }
        $have = $balance[$wh . ':' . $batch] ?? 0;
        if ($needStock) {
            if ($have < 2) {
                continue;
            }
            $qty = mt_rand(1, max(1, intdiv($have, 2)));
        } else {
            $qty = mt_rand(2, 12) * 5;
        }
        $used[$batch] = true;
        $lines[] = ['item_id' => $item['id'], 'batch_id' => $batch, 'qty' => $qty];
    }
    return $lines;
};

// Nhập đầu kỳ cho cả ba kho.
for ($day = 0; $day < 9; $day++) {
    $wh = $day % 3 + 1;
    $addVoucher('in', $wh, null, $pickLines($wh, false, 5), $base + $day * 86400, 'confirmed', 'Nhập hàng đầu kỳ');
}

// Phát sinh hằng ngày cho đến khi đủ 200 dòng.
$plan = ['out', 'in', 'out', 'transfer', 'out', 'in'];
for ($day = 9; count($movements) < 200; $day++) {
    $type = $plan[$day % count($plan)];
    $wh = mt_rand(1, 3);
    $at = $base + $day * 86400 + mt_rand(0, 8) * 3600;
    if ($type === 'in') {
        $addVoucher('in', $wh, null, $pickLines($wh, false, mt_rand(2, 4)), $at, 'confirmed', 'Nhập bổ sung từ nhà cung cấp');
    } elseif ($type === 'out') {
        $lines = $pickLines($wh, true, mt_rand(2, 4));
        if ($lines) {
            $addVoucher('out', $wh, null, $lines, $at, 'confirmed', 'Xuất cho bộ phận sử dụng');
        }
    } else {
        $to = $wh % 3 + 1;
        $lines = $pickLines($wh, true, mt_rand(1, 3));
        if ($lines) {
            $addVoucher('transfer', $wh, $to, $lines, $at, 'confirmed', 'Điều chuyển cân đối tồn');
        }
    }
}
$lastDay = $day;

// Đợt kiểm kê đã hoàn tất tại Kho Hà Nội, sinh chứng từ điều chỉnh có lý do.
$countLines1 = [];
$adjustLines = [];
foreach (array_slice(array_keys(array_filter($balance, fn($q, $k) => str_starts_with($k, '1:') && $q > 0, ARRAY_FILTER_USE_BOTH)), 0, 8) as $n => $key) {
    $batch = (int)explode(':', $key)[1];
    $itemId = 0;
    foreach ($batches as $b) {
        if ($b['id'] === $batch) {
            $itemId = $b['item_id'];
        }
    }
    $system = $balance[$key];
    $diff = [0, -2, 0, 1, 0, -1, 0, 0][$n];
    $reason = match (true) {
        $diff < 0 => $n % 2 === 0 ? 'Hư hỏng khi vận chuyển' : 'Hao hụt tự nhiên',
        $diff > 0 => 'Hàng nhập thừa chưa ghi nhận',
        default => '',
    };
    $countLines1[] = ['item_id' => $itemId, 'batch_id' => $batch, 'system_qty' => $system, 'actual_qty' => $system + $diff, 'reason' => $reason];
    if ($diff !== 0) {
        $adjustLines[] = ['item_id' => $itemId, 'batch_id' => $batch, 'qty' => $diff];
    }
}
$countAt = $base + $lastDay * 86400;
$adjustId = $addVoucher('adjust', 1, null, $adjustLines, $countAt + 7200, 'confirmed', 'Điều chỉnh theo đợt kiểm kê KK-0001');

// Đợt kiểm kê đang làm tại Kho TP.HCM (chưa nhập số thực tế).
$countLines2 = [];
foreach (array_slice(array_keys(array_filter($balance, fn($q, $k) => str_starts_with($k, '3:') && $q > 0, ARRAY_FILTER_USE_BOTH)), 0, 6) as $key) {
    $batch = (int)explode(':', $key)[1];
    $itemId = 0;
    foreach ($batches as $b) {
        if ($b['id'] === $batch) {
            $itemId = $b['item_id'];
        }
    }
    $countLines2[] = ['item_id' => $itemId, 'batch_id' => $batch, 'system_qty' => $balance[$key], 'actual_qty' => null, 'reason' => ''];
}

$stockCounts = [
    ['id' => 1, 'code' => 'KK-0001', 'warehouse_id' => 1, 'status' => 'closed', 'created_by' => 4,
     'created_at' => date('Y-m-d H:i', $countAt), 'adjust_voucher_id' => $adjustId, 'lines' => $countLines1],
    ['id' => 2, 'code' => 'KK-0002', 'warehouse_id' => 3, 'status' => 'counting', 'created_by' => 4,
     'created_at' => date('Y-m-d H:i', $countAt + 86400), 'adjust_voucher_id' => null, 'lines' => $countLines2],
];

// Vài chứng từ nháp đang chờ duyệt (chưa sinh phát sinh).
$draftAt = $countAt + 2 * 86400;
$addVoucher('in', 2, null, $pickLines(2, false, 3), $draftAt, 'draft', 'Chờ đối chiếu hóa đơn');
$addVoucher('out', 1, null, $pickLines(1, true, 2), $draftAt + 3600, 'draft', 'Xuất cho phòng hành chính');
$addVoucher('transfer', 3, 2, $pickLines(3, true, 2), $draftAt + 7200, 'draft', 'Điều chuyển bổ sung kho Đà Nẵng');

return [
    'warehouses' => $warehouses,
    'users' => $users,
    'items' => $items,
    'batches' => $batches,
    'vouchers' => $vouchers,
    'voucher_lines' => $voucherLines,
    'stock_movements' => $movements,
    'stock_counts' => $stockCounts,
];
