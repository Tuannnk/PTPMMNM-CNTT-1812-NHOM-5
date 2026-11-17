-- Database tạo từ schema cũ thiếu các cột ghi nhận "Không duyệt phiếu"
-- (CREATE TABLE IF NOT EXISTS không thêm cột vào bảng đã có). An toàn khi chạy lặp lại.
BEGIN;

ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS rejected_by bigint REFERENCES users(id);
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS rejected_at timestamptz;
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS reject_reason text NOT NULL DEFAULT '';

COMMIT;
