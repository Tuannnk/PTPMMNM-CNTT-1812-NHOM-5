\set ON_ERROR_STOP on
BEGIN;

-- Chỉ nạp vào database trống; không trộn dữ liệu mẫu với dữ liệu khác.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM warehouses)
       OR EXISTS (SELECT 1 FROM users)
       OR EXISTS (SELECT 1 FROM items)
       OR EXISTS (SELECT 1 FROM batches)
       OR EXISTS (SELECT 1 FROM vouchers)
       OR EXISTS (SELECT 1 FROM voucher_lines)
       OR EXISTS (SELECT 1 FROM stock_movements)
       OR EXISTS (SELECT 1 FROM stock_counts)
       OR EXISTS (SELECT 1 FROM stock_count_lines)
       OR EXISTS (SELECT 1 FROM user_warehouses) THEN
        RAISE EXCEPTION 'Database khong trong; dung nap seed.sql de tranh tron/nhan doi du lieu.';
    END IF;
END $$;

INSERT INTO warehouses (id, code, name, address) VALUES
(1, 'KHO-HN', 'Kho Hà Nội', 'Số 12 đường Giả Lập, Hà Nội'),
(2, 'KHO-DN', 'Kho Đà Nẵng', 'Lô B3 khu Mẫu, Đà Nẵng'),
(3, 'KHO-HCM', 'Kho TP.HCM', 'Số 45 đường Thử Nghiệm, TP.HCM');

INSERT INTO users (id, username, full_name, role, password_hash, active) VALUES
(1, 'nv.hn', 'Nguyễn Văn An', 'staff', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
(2, 'nv.dn', 'Trần Thị Bình', 'staff', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
(3, 'duyet', 'Lê Minh Châu', 'approver', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true),
(4, 'kiemke', 'Phạm Quốc Dũng', 'counter', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true);

INSERT INTO user_warehouses (user_id, warehouse_id) VALUES
(1, 1), (2, 2), (3, 1), (3, 2), (3, 3), (4, 1), (4, 3);

INSERT INTO users (id, username, full_name, role, password_hash, active) VALUES
(5, 'quantri', 'Quản trị hệ thống', 'admin', '$2y$10$Ufh8AvQvyQM8JlEITZfzse0n8.zGDBspeet.SyCxN2L9A0TpMnwjO', true);
INSERT INTO user_warehouses (user_id, warehouse_id) VALUES (5, 1), (5, 2), (5, 3);

INSERT INTO units (code, name) VALUES
('ram', 'Ram'), ('hộp', 'Hộp'), ('cuộn', 'Cuộn'), ('cái', 'Cái'), ('kg', 'Kilogram'),
('đôi', 'Đôi'), ('chai', 'Chai'), ('túi', 'Túi'), ('quyển', 'Quyển');

INSERT INTO items (id, sku, name, unit, min_stock) VALUES
(1, 'MH001', 'Giấy in A4 70gsm', 'ram', 15),
(2, 'MH002', 'Giấy in A3', 'ram', 20),
(3, 'MH003', 'Bút bi xanh', 'hộp', 25),
(4, 'MH004', 'Bút dạ quang', 'hộp', 10),
(5, 'MH005', 'Kẹp giấy 32mm', 'hộp', 15),
(6, 'MH006', 'Ghim bấm số 10', 'hộp', 20),
(7, 'MH007', 'Băng keo trong 5cm', 'cuộn', 25),
(8, 'MH008', 'Băng keo giấy', 'cuộn', 10),
(9, 'MH009', 'Thùng carton 40x30', 'cái', 15),
(10, 'MH010', 'Thùng carton 60x40', 'cái', 20),
(11, 'MH011', 'Màng PE quấn hàng', 'cuộn', 25),
(12, 'MH012', 'Xốp chèn hàng', 'kg', 10),
(13, 'MH013', 'Mực in laser đen', 'hộp', 15),
(14, 'MH014', 'Mực in phun màu', 'hộp', 20),
(15, 'MH015', 'Chuột không dây', 'cái', 25),
(16, 'MH016', 'Bàn phím USB', 'cái', 10),
(17, 'MH017', 'Cáp HDMI 2m', 'cái', 15),
(18, 'MH018', 'Ổ cắm điện 6 lỗ', 'cái', 20),
(19, 'MH019', 'Pin AA', 'hộp', 25),
(20, 'MH020', 'Bóng đèn LED 9W', 'cái', 10),
(21, 'MH021', 'Găng tay bảo hộ', 'đôi', 15),
(22, 'MH022', 'Khẩu trang y tế', 'hộp', 20),
(23, 'MH023', 'Nước rửa tay 500ml', 'chai', 25),
(24, 'MH024', 'Khăn giấy hộp', 'hộp', 10),
(25, 'MH025', 'Túi nilon lớn', 'kg', 15),
(26, 'MH026', 'Dây rút nhựa', 'túi', 20),
(27, 'MH027', 'Nhãn dán mã vạch', 'cuộn', 25),
(28, 'MH028', 'Sổ ghi chép A5', 'quyển', 10),
(29, 'MH029', 'Bìa hồ sơ nhựa', 'cái', 15),
(30, 'MH030', 'Thước kẻ 30cm', 'cái', 20);

INSERT INTO batches (id, item_id, code, expiry)
SELECT id, id, 'L' || lpad(id::text, 3, '0') || '-01',
       CASE WHEN id BETWEEN 21 AND 24 THEN DATE '2027-12-31' ELSE NULL END
FROM generate_series(1, 30) AS s(id);

-- 200 phiếu nhập đã xác nhận, mỗi phiếu sinh một dòng phát sinh dương.
INSERT INTO vouchers (id, code, type, status, warehouse_id, created_by, confirmed_by,
                      created_at, confirmed_at, note)
SELECT g, 'PN-' || lpad(g::text, 4, '0'), 'in', 'confirmed', (((g - 1) / 30 + (g - 1) % 3) % 3) + 1,
       3, 3, now() - (g || ' days')::interval,
       now() - (g || ' days')::interval + interval '30 minutes', 'Nhập hàng mẫu'
FROM generate_series(1, 200) AS s(g);

INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty)
SELECT g, ((g - 1) % 30) + 1, ((g - 1) % 30) + 1, 10
FROM generate_series(1, 200) AS s(g);

INSERT INTO stock_movements (voucher_id, warehouse_id, item_id, batch_id, qty, occurred_at)
SELECT g, (((g - 1) / 30 + (g - 1) % 3) % 3) + 1,
       ((g - 1) % 30) + 1, ((g - 1) % 30) + 1, 10,
       now() - (g || ' days')::interval + interval '30 minutes'
FROM generate_series(1, 200) AS s(g);

-- Điều chuyển có đủ hai phát sinh đối ứng ở kho đi và kho đến.
INSERT INTO vouchers (id, code, type, status, warehouse_id, to_warehouse_id,
                      created_by, confirmed_by, confirmed_at, note)
VALUES (201, 'PC-0001', 'transfer', 'confirmed', 1, 2, 1, 3, now(), 'Điều chuyển mẫu');
INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty) VALUES (201, 1, 1, 2);
INSERT INTO stock_movements (voucher_id, warehouse_id, item_id, batch_id, qty) VALUES
(201, 1, 1, 1, -2), (201, 2, 1, 1, 2);

-- Chứng từ điều chỉnh dương gắn với một đợt kiểm kê đã chốt.
INSERT INTO vouchers (id, code, type, status, warehouse_id, created_by, confirmed_by, confirmed_at, note)
VALUES (202, 'DC-0001', 'adjust', 'confirmed', 1, 4, 3, now(), 'Điều chỉnh thừa theo kiểm kê mẫu');
INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty) VALUES (202, 2, 2, 1);

INSERT INTO stock_counts (id, code, warehouse_id, status, created_by, adjust_voucher_id, closed_at)
VALUES (1, 'KK-0001', 1, 'closed', 4, 202, now());
INSERT INTO stock_count_lines (count_id, item_id, batch_id, system_qty, actual_qty, reason, closing_system_qty)
SELECT 1, 2, 2, coalesce(sum(qty), 0), coalesce(sum(qty), 0) + 1, 'Hàng nhập thừa chưa ghi nhận', coalesce(sum(qty),0)
FROM stock_movements WHERE warehouse_id = 1 AND item_id = 2 AND batch_id = 2;
INSERT INTO stock_movements (voucher_id, warehouse_id, item_id, batch_id, qty)
VALUES (202, 1, 2, 2, 1);

-- Một vài chứng từ nháp để minh họa trạng thái chờ duyệt.
INSERT INTO vouchers (id, code, type, status, warehouse_id, to_warehouse_id, created_by, note)
VALUES (203, 'PN-0201', 'in', 'draft', 2, NULL, 2, 'Chờ đối chiếu hóa đơn'),
       (204, 'PX-0001', 'out', 'draft', 1, NULL, 1, 'Xuất cho phòng hành chính'),
       (205, 'PC-0002', 'transfer', 'draft', 3, 2, 3, 'Điều chuyển chờ duyệt');
INSERT INTO voucher_lines (voucher_id, item_id, batch_id, qty) VALUES
(203, 4, 4, 5), (204, 1, 1, 2), (205, 3, 3, 3);

INSERT INTO transfer_workflow (voucher_id, state, dispatched_at, received_at)
SELECT id, CASE WHEN status = 'confirmed' THEN 'received' ELSE 'draft' END,
       CASE WHEN status = 'confirmed' THEN confirmed_at ELSE NULL END,
       CASE WHEN status = 'confirmed' THEN confirmed_at ELSE NULL END
FROM vouchers WHERE type = 'transfer'
ON CONFLICT (voucher_id) DO NOTHING;

-- Đồng bộ sequence sau khi chèn ID tường minh.
SELECT setval(pg_get_serial_sequence('warehouses', 'id'), (SELECT max(id) FROM warehouses), true);
SELECT setval(pg_get_serial_sequence('users', 'id'), (SELECT max(id) FROM users), true);
SELECT setval(pg_get_serial_sequence('items', 'id'), (SELECT max(id) FROM items), true);
SELECT setval(pg_get_serial_sequence('batches', 'id'), (SELECT max(id) FROM batches), true);
SELECT setval(pg_get_serial_sequence('vouchers', 'id'), (SELECT max(id) FROM vouchers), true);
SELECT setval(pg_get_serial_sequence('voucher_lines', 'id'), (SELECT max(id) FROM voucher_lines), true);
SELECT setval(pg_get_serial_sequence('stock_movements', 'id'), (SELECT max(id) FROM stock_movements), true);
SELECT setval(pg_get_serial_sequence('stock_counts', 'id'), (SELECT max(id) FROM stock_counts), true);
SELECT setval(pg_get_serial_sequence('stock_count_lines', 'id'), (SELECT max(id) FROM stock_count_lines), true);

COMMIT;
