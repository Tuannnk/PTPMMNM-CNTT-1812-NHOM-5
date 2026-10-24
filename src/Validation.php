<?php
declare(strict_types=1);
// Kiểm tra dữ liệu form phía máy chủ. Trả ['errors' => [...], 'data' => [...]] như các lab.

const ERROR_LABELS = [
    'TYPE_INVALID' => 'Loại chứng từ không hợp lệ.',
    'WAREHOUSE_INVALID' => 'Kho không tồn tại.',
    'WAREHOUSE_FORBIDDEN' => 'Bạn không phụ trách kho này.',
    'TO_WAREHOUSE_INVALID' => 'Kho đến không hợp lệ hoặc trùng kho đi.',
    'LINES_EMPTY' => 'Chứng từ cần ít nhất một dòng hàng.',
    'BATCH_INVALID' => 'Có dòng chọn lô hàng không tồn tại.',
    'BATCH_DUPLICATE' => 'Một lô chỉ được xuất hiện một lần trong chứng từ.',
    'QTY_INVALID' => 'Số lượng phải là số nguyên dương.',
    'STOCK_NEGATIVE' => 'Số lượng xuất vượt số tồn hiện có.',
    'NOTE_TOO_LONG' => 'Ghi chú tối đa 255 ký tự.',
    'ACTUAL_INVALID' => 'Số thực tế phải là số nguyên không âm.',
    'REASON_REQUIRED' => 'Dòng có chênh lệch phải ghi lý do.',
    'TRANSFER_SAVE_FAILED' => 'Không lưu được phiếu điều chuyển. Hãy thử gửi lại; hệ thống sẽ tránh tạo phiếu trùng.',
];

/** Đếm theo ký tự UTF-8 (không cần mbstring); chuỗi sai UTF-8 bị từ chối. */
function textFits(string $value, int $max): bool
{
    return preg_match('/^.{0,' . $max . '}$/us', $value) === 1;
}

function validateVoucher(array $input, array $user, array $balances): array
{
    $errors = [];
    $type = field($input, 'type');
    if (!in_array($type, ['in', 'out', 'transfer'], true)) {
        $errors[] = 'TYPE_INVALID';
    }

    $warehouseId = intField($input, 'warehouse_id');
    if ($warehouseId === null || Store::find('warehouses', $warehouseId) === null) {
        $errors[] = 'WAREHOUSE_INVALID';
    } elseif (!canAccessWarehouse($user, $warehouseId)) {
        $errors[] = 'WAREHOUSE_FORBIDDEN';
    }

    $toWarehouseId = null;
    if ($type === 'transfer') {
        $toWarehouseId = intField($input, 'to_warehouse_id');
        $toWarehouse = $toWarehouseId === null ? null : Store::find('warehouses', $toWarehouseId);
        if ($toWarehouseId === null || $toWarehouseId === $warehouseId
            || $toWarehouse === null || !$toWarehouse['active']) {
            $errors[] = 'TO_WAREHOUSE_INVALID';
        }
    }

    $note = field($input, 'note');
    if (!textFits($note, 255)) {
        $errors[] = 'NOTE_TOO_LONG';
    }

    // Mỗi dòng chọn một lô; mặt hàng suy ra từ lô nên không thể gửi cặp mặt hàng/lô lệch nhau.
    $batches = Store::indexed('batches');
    $batchIds = listField($input, 'batch_id');
    $qtys = listField($input, 'qty');
    $lines = [];
    $seen = [];
    $filled = 0;
    foreach ($batchIds as $i => $rawBatch) {
        $rawQty = $qtys[$i] ?? '';
        if ($rawBatch === '' && $rawQty === '') {
            continue; // dòng trống trên form
        }
        $filled++;
        $batchId = (int)$rawBatch;
        $qty = filter_var($rawQty, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!isset($batches[$batchId]) || !$batches[$batchId]['active']) {
            $errors[] = 'BATCH_INVALID';
            continue;
        }
        if (isset($seen[$batchId])) {
            $errors[] = 'BATCH_DUPLICATE';
        }
        if ($qty === false) {
            $errors[] = 'QTY_INVALID';
        }
        $seen[$batchId] = true;
        $lines[] = ['item_id' => $batches[$batchId]['item_id'], 'batch_id' => $batchId, 'qty' => $qty === false ? 0 : $qty];
    }
    if ($filled === 0) {
        $errors[] = 'LINES_EMPTY';
    }

    $data = [
        'type' => $type,
        'warehouse_id' => $warehouseId ?? 0,
        'to_warehouse_id' => $toWarehouseId,
        'note' => $note,
        'lines' => $lines,
    ];

    $errors = array_values(array_unique($errors));
    if (!$errors && $type !== 'in') {
        $negative = StockLedger::negativeAfter($balances, StockLedger::movementsFor($data, $lines));
        if ($negative) {
            $errors[] = 'STOCK_NEGATIVE';
            $data['negative'] = $negative;
        }
    }
    return ['errors' => $errors, 'data' => $data];
}

function validateCount(array $input, array $count): array
{
    $errors = [];
    $actuals = listField($input, 'actual');
    $reasons = listField($input, 'reason');
    $lines = [];
    foreach ($count['lines'] as $i => $line) {
        $actual = filter_var($actuals[$i] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $reason = $reasons[$i] ?? '';
        if ($actual === false) {
            $errors[] = 'ACTUAL_INVALID';
            $lines[] = $line + ['diff' => null];
            continue;
        }
        $diff = $actual - $line['system_qty'];
        if ($diff !== 0 && $reason === '') {
            $errors[] = 'REASON_REQUIRED';
        } elseif (!textFits($reason, 255)) {
            $errors[] = 'NOTE_TOO_LONG';
        }
        $lines[] = ['actual_qty' => $actual, 'reason' => $reason, 'diff' => $diff] + $line;
    }
    return ['errors' => array_values(array_unique($errors)), 'data' => ['lines' => $lines]];
}
