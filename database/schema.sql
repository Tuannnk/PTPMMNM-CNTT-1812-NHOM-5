-- Khởi tạo schema cho hệ thống quản lý kho của nhóm 5.
-- Chạy trong database ptpmmnm_cntt_1812_nhom_5.

CREATE TABLE IF NOT EXISTS warehouses (
    id           bigserial PRIMARY KEY,
    code         varchar(30) NOT NULL UNIQUE,
    name         varchar(160) NOT NULL,
    address      text NOT NULL DEFAULT '',
    active       boolean NOT NULL DEFAULT true,
    created_at   timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS users (
    id            bigserial PRIMARY KEY,
    username      varchar(80) NOT NULL UNIQUE,
    full_name     varchar(160) NOT NULL,
    role          varchar(20) NOT NULL CHECK (role IN ('staff', 'approver', 'counter', 'admin')),
    password_hash text,
    active        boolean NOT NULL DEFAULT true,
    created_at    timestamptz NOT NULL DEFAULT now()
);

-- Danh mục đơn vị dùng code làm khóa để giữ tương thích với items.unit.
CREATE TABLE IF NOT EXISTS units (
    id         bigserial PRIMARY KEY,
    code       varchar(40) NOT NULL UNIQUE,
    name       varchar(80) NOT NULL UNIQUE,
    active     boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS user_warehouses (
    user_id      bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    warehouse_id bigint NOT NULL REFERENCES warehouses(id),
    PRIMARY KEY (user_id, warehouse_id)
);

CREATE TABLE IF NOT EXISTS items (
    id         bigserial PRIMARY KEY,
    sku        varchar(60) NOT NULL UNIQUE,
    name       varchar(200) NOT NULL,
    unit       varchar(40) NOT NULL REFERENCES units(code),
    min_stock  numeric(14, 3) NOT NULL DEFAULT 0 CHECK (min_stock >= 0),
    active     boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS batches (
    id        bigserial PRIMARY KEY,
    item_id   bigint NOT NULL REFERENCES items(id),
    code      varchar(80) NOT NULL,
    expiry    date,
    active    boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (item_id, code),
    UNIQUE (id, item_id)
);

-- Nâng cấp an toàn cho database đã được khởi tạo bằng phiên bản schema trước.
ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('staff', 'approver', 'counter', 'admin'));
ALTER TABLE batches ADD COLUMN IF NOT EXISTS active boolean NOT NULL DEFAULT true;
INSERT INTO units (code, name)
SELECT DISTINCT unit, unit FROM items
ON CONFLICT (code) DO NOTHING;
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'items_unit_fkey') THEN
        ALTER TABLE items ADD CONSTRAINT items_unit_fkey FOREIGN KEY (unit) REFERENCES units(code);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS vouchers (
    id              bigserial PRIMARY KEY,
    code            varchar(40) NOT NULL UNIQUE,
    type            varchar(20) NOT NULL CHECK (type IN ('in', 'out', 'transfer', 'adjust')),
    status          varchar(20) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft', 'confirmed', 'rejected')),
    warehouse_id    bigint NOT NULL REFERENCES warehouses(id),
    to_warehouse_id bigint REFERENCES warehouses(id),
    created_by      bigint NOT NULL REFERENCES users(id),
    confirmed_by    bigint REFERENCES users(id),
    rejected_by     bigint REFERENCES users(id),
    created_at      timestamptz NOT NULL DEFAULT now(),
    confirmed_at    timestamptz,
    rejected_at     timestamptz,
    reject_reason   text NOT NULL DEFAULT '',
    note            text NOT NULL DEFAULT '',
    ref_id          bigint REFERENCES vouchers(id),
    request_key     varchar(64),
    CHECK ((type = 'transfer' AND to_warehouse_id IS NOT NULL AND to_warehouse_id <> warehouse_id)
        OR (type <> 'transfer' AND to_warehouse_id IS NULL)),
    CHECK ((status = 'confirmed' AND confirmed_by IS NOT NULL AND confirmed_at IS NOT NULL)
        OR status <> 'confirmed')
);

-- Khóa gửi lại cho các phiếu điều chuyển; NULL giữ tương thích với chứng từ cũ.
ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS request_key varchar(64);
CREATE UNIQUE INDEX IF NOT EXISTS idx_vouchers_request_key ON vouchers (request_key);

CREATE TABLE IF NOT EXISTS voucher_lines (
    id         bigserial PRIMARY KEY,
    voucher_id bigint NOT NULL REFERENCES vouchers(id) ON DELETE CASCADE,
    item_id    bigint NOT NULL REFERENCES items(id),
    batch_id   bigint NOT NULL,
    qty        numeric(14, 3) NOT NULL CHECK (qty > 0),
    FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id)
);

-- Giai đoạn kiểm hàng, duyệt xuất và nhận hàng của phiếu điều chuyển.
CREATE TABLE IF NOT EXISTS transfer_workflow (
    voucher_id   bigint PRIMARY KEY REFERENCES vouchers(id) ON DELETE CASCADE,
    state        varchar(24) NOT NULL DEFAULT 'draft'
                 CHECK (state IN ('draft', 'checked', 'in_transit', 'partial', 'received', 'cancelled')),
    checked_by   bigint REFERENCES users(id),
    checked_at   timestamptz,
    dispatched_at timestamptz,
    received_by  bigint REFERENCES users(id),
    received_at  timestamptz,
    cancelled_by bigint REFERENCES users(id),
    cancelled_at timestamptz,
    cancel_reason text NOT NULL DEFAULT ''
);

CREATE TABLE IF NOT EXISTS transfer_checks (
    voucher_line_id bigint PRIMARY KEY REFERENCES voucher_lines(id) ON DELETE CASCADE,
    voucher_id      bigint NOT NULL REFERENCES vouchers(id) ON DELETE CASCADE,
    checked_qty     numeric(14, 3) NOT NULL CHECK (checked_qty >= 0),
    note            text NOT NULL DEFAULT '',
    checked_by      bigint NOT NULL REFERENCES users(id),
    checked_at      timestamptz NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS transfer_receipt_lines (
    voucher_line_id bigint PRIMARY KEY REFERENCES voucher_lines(id) ON DELETE CASCADE,
    voucher_id      bigint NOT NULL REFERENCES vouchers(id) ON DELETE CASCADE,
    received_qty    numeric(14, 3) NOT NULL CHECK (received_qty >= 0),
    missing_qty     numeric(14, 3) NOT NULL CHECK (missing_qty >= 0),
    note            text NOT NULL DEFAULT '',
    received_by     bigint NOT NULL REFERENCES users(id),
    received_at     timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX IF NOT EXISTS idx_transfer_workflow_state ON transfer_workflow (state);
CREATE INDEX IF NOT EXISTS idx_transfer_checks_voucher ON transfer_checks (voucher_id);
CREATE INDEX IF NOT EXISTS idx_transfer_receipts_voucher ON transfer_receipt_lines (voucher_id);

-- Backfill các phiếu điều chuyển đã có trước khi bật quy trình nhận hàng.
INSERT INTO transfer_workflow (voucher_id, state, dispatched_at, received_at)
SELECT id, CASE WHEN status = 'confirmed' THEN 'received' ELSE 'draft' END,
       CASE WHEN status = 'confirmed' THEN confirmed_at ELSE NULL END,
       CASE WHEN status = 'confirmed' THEN confirmed_at ELSE NULL END
FROM vouchers WHERE type = 'transfer'
ON CONFLICT (voucher_id) DO NOTHING;

-- Số tồn được tính từ sổ phát sinh; không lưu số tồn có thể lệch với lịch sử.
CREATE TABLE IF NOT EXISTS stock_movements (
    id           bigserial PRIMARY KEY,
    voucher_id   bigint NOT NULL REFERENCES vouchers(id),
    warehouse_id bigint NOT NULL REFERENCES warehouses(id),
    item_id      bigint NOT NULL REFERENCES items(id),
    batch_id     bigint NOT NULL,
    qty          numeric(14, 3) NOT NULL CHECK (qty <> 0),
    occurred_at  timestamptz NOT NULL DEFAULT now(),
    FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
    UNIQUE (voucher_id, warehouse_id, batch_id)
);

CREATE TABLE IF NOT EXISTS stock_counts (
    id                bigserial PRIMARY KEY,
    code              varchar(40) NOT NULL UNIQUE,
    warehouse_id      bigint NOT NULL REFERENCES warehouses(id),
    status            varchar(20) NOT NULL DEFAULT 'counting' CHECK (status IN ('counting', 'closed')),
    created_by        bigint NOT NULL REFERENCES users(id),
    created_at        timestamptz NOT NULL DEFAULT now(),
    adjust_voucher_id bigint UNIQUE REFERENCES vouchers(id),
    CHECK ((status = 'closed' AND adjust_voucher_id IS NOT NULL) OR status <> 'closed')
);

CREATE TABLE IF NOT EXISTS stock_count_lines (
    id          bigserial PRIMARY KEY,
    count_id    bigint NOT NULL REFERENCES stock_counts(id) ON DELETE CASCADE,
    item_id     bigint NOT NULL REFERENCES items(id),
    batch_id    bigint NOT NULL,
    system_qty  numeric(14, 3) NOT NULL CHECK (system_qty >= 0),
    actual_qty  numeric(14, 3) CHECK (actual_qty >= 0),
    reason      text NOT NULL DEFAULT '',
    FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
    UNIQUE (count_id, batch_id)
);

CREATE INDEX IF NOT EXISTS idx_vouchers_warehouse_created
    ON vouchers (warehouse_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_voucher_lines_voucher
    ON voucher_lines (voucher_id);
CREATE INDEX IF NOT EXISTS idx_movements_stock_lookup
    ON stock_movements (warehouse_id, item_id, batch_id, occurred_at);
CREATE INDEX IF NOT EXISTS idx_movements_voucher
    ON stock_movements (voucher_id);
CREATE INDEX IF NOT EXISTS idx_counts_warehouse_status
    ON stock_counts (warehouse_id, status);
