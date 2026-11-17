-- Mỗi lần điểm bán xác nhận nhận hàng được ghi thành một dòng phát sinh mới
-- (sổ chỉ nối thêm), thay vì cộng dồn vào dòng cũ bằng UPDATE.
-- Chỉ giữ ràng buộc duy nhất cho dòng tại kho. An toàn khi chạy lặp lại.
BEGIN;

DROP INDEX IF EXISTS uq_stock_movement_event;
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movement_warehouse_event
    ON stock_movements(voucher_id, warehouse_id, batch_id) WHERE sales_point_id IS NULL;

COMMIT;
