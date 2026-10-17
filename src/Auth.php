<?php
declare(strict_types=1);
// Quyền theo kho được kiểm tra phía máy chủ, không chỉ ẩn nút trên giao diện.

const ROLE_LABELS = [
    'staff' => 'Nhân viên kho',
    'approver' => 'Người duyệt chứng từ',
    'counter' => 'Phụ trách kiểm kê',
];

function currentUser(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    return is_int($id) ? Store::find('users', $id) : null;
}

function requireLogin(): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: /login.php', true, 303);
        exit;
    }
    return $user;
}

function canAccessWarehouse(array $user, int $warehouseId): bool
{
    return in_array($warehouseId, $user['warehouses'], true);
}

function canConfirm(array $user, array $voucher): bool
{
    return $user['role'] === 'approver'
        && $voucher['status'] === 'draft'
        && canAccessWarehouse($user, $voucher['warehouse_id']);
}

function allowedWarehouses(array $user): array
{
    return array_values(array_filter(
        Store::all('warehouses'),
        fn($w) => canAccessWarehouse($user, $w['id'])
    ));
}

function forbid(string $code = 'FORBIDDEN_WAREHOUSE'): never
{
    http_response_code(403);
    exit($code);
}
