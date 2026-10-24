<?php
declare(strict_types=1);
// Quyền theo kho được kiểm tra phía máy chủ, không chỉ ẩn nút trên giao diện.

const ROLE_LABELS = [
    'admin' => 'Quản trị danh mục',
    'staff' => 'Nhân viên kho',
    'approver' => 'Người duyệt chứng từ',
    'counter' => 'Phụ trách kiểm kê',
];

function canManageCatalog(array $user): bool
{
    return $user['role'] === 'admin';
}

function requireCatalogManager(array $user): void
{
    if (!canManageCatalog($user)) {
        forbid('CATALOG_ADMIN_REQUIRED');
    }
}

function currentUser(): ?array
{
    $id = $_SESSION['user_id'] ?? null;
    $user = is_int($id) ? Store::find('users', $id) : null;
    if ($user !== null && !$user['active']) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function authenticate(string $username, string $password): ?array
{
    $query = Database::connection()->prepare(
        'SELECT id, password_hash FROM users WHERE username = :username AND active = TRUE LIMIT 1'
    );
    $query->execute(['username' => $username]);
    $candidate = $query->fetch();
    if (!$candidate || !is_string($candidate['password_hash'])
        || !password_verify($password, $candidate['password_hash'])) {
        return null;
    }
    return Store::find('users', (int)$candidate['id']);
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
    $warehouse = Store::find('warehouses', $warehouseId);
    return $warehouse !== null && $warehouse['active'] && in_array($warehouseId, $user['warehouses'], true);
}

function canConfirm(array $user, array $voucher): bool
{
    return $user['role'] === 'approver'
        && $voucher['status'] === 'draft'
        && canAccessWarehouse($user, $voucher['warehouse_id'])
        && ($voucher['type'] !== 'transfer' || canAccessWarehouse($user, $voucher['to_warehouse_id']));
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
