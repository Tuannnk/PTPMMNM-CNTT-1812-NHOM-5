-- Tài khoản quản trị mẫu cho database đã có dữ liệu trước chức năng quản lý danh mục.
-- Chỉ dùng với database demo; mật khẩu mặc định là KhoMau@2026.
\set ON_ERROR_STOP on
BEGIN;

INSERT INTO users (username, full_name, role, password_hash, active)
VALUES ('quantri', 'Quản trị hệ thống', 'admin',
        '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', TRUE)
ON CONFLICT (username) DO NOTHING;

INSERT INTO user_warehouses (user_id, warehouse_id)
SELECT u.id, w.id
FROM users u CROSS JOIN warehouses w
WHERE u.username = 'quantri' AND u.role = 'admin' AND u.active = TRUE AND w.active = TRUE
ON CONFLICT DO NOTHING;

COMMIT;
