<?php
declare(strict_types=1);
// Nạp chung cho mọi trang trong public/.

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

require __DIR__ . '/Input.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/InventoryGuard.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/StockLedger.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Validation.php';
require __DIR__ . '/VoucherService.php';
require __DIR__ . '/TransferService.php';
require __DIR__ . '/AdjustmentService.php';
require __DIR__ . '/StockCountService.php';

const VOUCHER_TYPES = [
    'in' => 'Phiếu nhập',
    'out' => 'Phiếu xuất',
    'transfer' => 'Điều chuyển',
    'adjust' => 'Điều chỉnh',
];

const VOUCHER_STATUS = [
    'draft' => 'Chờ duyệt',
    'confirmed' => 'Đã xác nhận',
    'rejected' => 'Không duyệt',
];

const TRANSFER_STATE_LABELS = [
    'draft' => 'Chờ kiểm hàng',
    'checked' => 'Chờ duyệt xuất',
    'in_transit' => 'Đang vận chuyển',
    'partial' => 'Đã nhận thiếu',
    'received' => 'Đã nhận đủ',
    'cancelled' => 'Đã hủy',
];

function checkCsrf(array $input): void
{
    $token = $input['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('CSRF_INVALID');
    }
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($value) ? $value : null;
}

function redirect(string $path): never
{
    header('Location: ' . $path, true, 303);
    exit;
}

function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('POST_REQUIRED');
    }
    checkCsrf($_POST);
}

function warehouseName(?int $id): string
{
    if ($id === null) {
        return '—';
    }
    return Store::find('warehouses', $id)['name'] ?? '—';
}

function userName(?int $id): string
{
    if ($id === null) {
        return '—';
    }
    return Store::find('users', $id)['name'] ?? '—';
}

function statusBadge(string $status): string
{
    $class = match ($status) {
        'confirmed' => 'badge badge--ok',
        'rejected' => 'badge badge--rejected',
        default => 'badge badge--wait',
    };
    return '<span class="' . $class . '">' . e(VOUCHER_STATUS[$status] ?? $status) . '</span>';
}

function voucherStatusBadge(array $voucher): string
{
    if ($voucher['type'] === 'transfer') {
        $state = $voucher['transfer_state'] ?? 'draft';
        $class = $state === 'received' ? 'badge badge--ok' : 'badge badge--wait';
        return '<span class="' . $class . '">' . e(TRANSFER_STATE_LABELS[$state] ?? $state) . '</span>';
    }
    return statusBadge($voucher['status']);
}

function typeBadge(string $type): string
{
    return '<span class="badge badge--' . e($type) . '">' . e(VOUCHER_TYPES[$type] ?? $type) . '</span>';
}

function signedQty(int $value): string
{
    $class = $value < 0 ? 'qty-neg' : 'qty-pos';
    return '<span class="' . $class . '">' . ($value > 0 ? '+' : '') . qty($value) . '</span>';
}

function dateOnly(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d/m/Y');
    } catch (Throwable) {
        return $value;
    }
}
