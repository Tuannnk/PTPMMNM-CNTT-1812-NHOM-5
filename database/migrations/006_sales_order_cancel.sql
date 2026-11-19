-- Ghi nhận người hủy, thời điểm và lý do hủy đơn phân phối. An toàn khi chạy lặp lại.
BEGIN;

ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS cancelled_by bigint REFERENCES users(id);
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS cancelled_at timestamptz;
ALTER TABLE sales_orders ADD COLUMN IF NOT EXISTS cancel_reason text NOT NULL DEFAULT '';

COMMIT;
