-- Kho đi xác nhận nguyên nhân hàng nhận thiếu của từng dòng điều chuyển:
--   SHORT_PICKED: soạn thiếu, hàng còn ở kho đi -> lập phiếu điều chỉnh +qty cho kho đi, chờ người duyệt
--   LOST: thất lạc khi vận chuyển -> lưu biên bản; sổ không đổi vì kho đi đã bị trừ khi xuất
-- An toàn khi chạy lặp lại.
BEGIN;

CREATE TABLE IF NOT EXISTS transfer_shortage_resolutions (
    id                bigserial PRIMARY KEY,
    voucher_id        bigint NOT NULL REFERENCES vouchers(id),
    voucher_line_id   bigint NOT NULL UNIQUE REFERENCES voucher_lines(id),
    cause             varchar(20) NOT NULL CHECK (cause IN ('SHORT_PICKED', 'LOST')),
    qty               numeric(14, 3) NOT NULL CHECK (qty > 0),
    note              text NOT NULL CHECK (length(btrim(note)) > 0),
    adjust_voucher_id bigint UNIQUE REFERENCES vouchers(id),
    resolved_by       bigint NOT NULL REFERENCES users(id),
    resolved_at       timestamptz NOT NULL DEFAULT now(),
    CHECK ((cause = 'SHORT_PICKED' AND adjust_voucher_id IS NOT NULL)
        OR (cause = 'LOST' AND adjust_voucher_id IS NULL))
);
CREATE INDEX IF NOT EXISTS idx_shortage_resolutions_voucher ON transfer_shortage_resolutions (voucher_id);

COMMIT;
