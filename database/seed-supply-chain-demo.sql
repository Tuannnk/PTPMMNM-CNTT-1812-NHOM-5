-- Seed development/test/demo cho chuỗi cung ứng.
-- Bắt buộc chạy với: psql -v demo_seed=1 -f database/seed-supply-chain-demo.sql
-- Không chạy trên production hoặc database chứa dữ liệu nghiệp vụ thật.
\set ON_ERROR_STOP on
\if :{?demo_seed}
\else
\echo 'ERROR: phải truyền -v demo_seed=1 để xác nhận môi trường demo'
\quit 2
\endif

BEGIN;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM warehouses WHERE code = 'CENTRAL-01') THEN
        RAISE EXCEPTION 'Chưa chạy migration supply-chain hoặc thiếu kho CENTRAL-01';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM users WHERE username = 'quantri' AND role = 'admin') THEN
        RAISE EXCEPTION 'Không tìm thấy tài khoản nền quantri; dừng để bảo vệ database';
    END IF;
END $$;

INSERT INTO users (username, full_name, role, password_hash, active)
VALUES
 ('ql.tong', 'Quản lý kho tổng', 'central_manager', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('ql.hn', 'Quản lý kho Hà Nội', 'regional_manager', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('ql.dn', 'Quản lý kho Đà Nẵng', 'regional_manager', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('ql.hcm', 'Quản lý kho TP.HCM', 'regional_manager', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('nv.hcm', 'Nhân viên kho TP.HCM', 'staff', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('daily.hn', 'Người dùng Đại lý Hà Nội', 'sales_user', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('cuahang.dn', 'Người dùng Cửa hàng Đà Nẵng', 'sales_user', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
 ('sieuthi.hcm', 'Người dùng Siêu thị TP.HCM', 'sales_user', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true)
ON CONFLICT (username) DO NOTHING;

INSERT INTO user_warehouses (user_id, warehouse_id)
SELECT u.id, w.id FROM users u CROSS JOIN warehouses w
WHERE (u.username = 'ql.tong' AND w.code = 'CENTRAL-01')
   OR (u.username = 'ql.hn' AND w.code = 'KHO-HN')
   OR (u.username = 'ql.dn' AND w.code = 'KHO-DN')
   OR (u.username IN ('ql.hcm','nv.hcm') AND w.code = 'KHO-HCM')
ON CONFLICT DO NOTHING;

INSERT INTO suppliers (code, company_name, tax_code, contact_name, phone, email, address, active, note)
SELECT x.code, x.company_name, x.tax_code, x.contact_name, x.phone, x.email, x.address, true,
       'Dữ liệu demo chuỗi cung ứng'
FROM (VALUES
 ('NCC-01', 'Công ty Cổ phần Vật tư Minh Long', '0101234567', 'Nguyễn Minh Long', '02431234567', 'kinhdoanh@minhlong.example', 'Số 8 đường Trường Chinh, Hà Nội'),
 ('NCC-02', 'Công ty TNHH Giấy Phương Nam', '0301234568', 'Trần Thị Phương', '02831234568', 'cungung@phuongnam.example', 'Lô 12 KCN Tân Bình, TP.HCM'),
 ('NCC-03', 'Công ty Thương mại Đông Á', '0401234569', 'Lê Đông Á', '02361234569', 'sales@donga.example', 'Số 25 đường Nguyễn Văn Linh, Đà Nẵng')
) AS x(code, company_name, tax_code, contact_name, phone, email, address)
ON CONFLICT (code) DO NOTHING;

INSERT INTO sales_points (code, name, point_type, address, primary_warehouse_id, active, note)
SELECT x.code, x.name, x.point_type, x.address, w.id, true, 'Dữ liệu demo chuỗi cung ứng'
FROM (VALUES
 ('DL-HN','Đại lý Hà Nội','AGENT','Hà Nội'),
 ('CH-DN','Cửa hàng Đà Nẵng','STORE','Đà Nẵng'),
 ('ST-HCM','Siêu thị TP.HCM','SUPERMARKET','TP.HCM')
) AS x(code,name,point_type,address)
JOIN warehouses w ON w.code = CASE x.code WHEN 'DL-HN' THEN 'KHO-HN' WHEN 'CH-DN' THEN 'KHO-DN' WHEN 'ST-HCM' THEN 'KHO-HCM' END
ON CONFLICT (code) DO NOTHING;

INSERT INTO sales_point_warehouses (sales_point_id, warehouse_id, is_primary)
SELECT p.id, w.id, true FROM sales_points p JOIN warehouses w
ON w.code = CASE p.code WHEN 'DL-HN' THEN 'KHO-HN' WHEN 'CH-DN' THEN 'KHO-DN' WHEN 'ST-HCM' THEN 'KHO-HCM' END
ON CONFLICT (sales_point_id, warehouse_id) DO UPDATE SET is_primary = true;

INSERT INTO user_sales_points (user_id, sales_point_id)
SELECT u.id, p.id FROM users u CROSS JOIN sales_points p
WHERE (u.username = 'daily.hn' AND p.code = 'DL-HN')
   OR (u.username = 'cuahang.dn' AND p.code = 'CH-DN')
   OR (u.username = 'sieuthi.hcm' AND p.code = 'ST-HCM')
ON CONFLICT DO NOTHING;

COMMIT;
