-- Migration mở rộng chuỗi cung ứng. Chạy sau schema.sql, trong transaction.
-- Migration không tự chọn kho tổng và không di chuyển tồn kho hiện hữu.
BEGIN;

CREATE TABLE IF NOT EXISTS suppliers (
    id bigserial PRIMARY KEY,
    code varchar(40) NOT NULL UNIQUE,
    company_name varchar(200) NOT NULL,
    tax_code varchar(40) NOT NULL DEFAULT '',
    contact_name varchar(160) NOT NULL DEFAULT '',
    phone varchar(40) NOT NULL DEFAULT '',
    email varchar(160) NOT NULL DEFAULT '',
    address text NOT NULL DEFAULT '',
    active boolean NOT NULL DEFAULT true,
    note text NOT NULL DEFAULT '',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS type varchar(12);
ALTER TABLE warehouses ADD COLUMN IF NOT EXISTS parent_id bigint REFERENCES warehouses(id);
ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS warehouses_type_check;
ALTER TABLE warehouses ADD CONSTRAINT warehouses_type_check
    CHECK (type IS NULL OR type IN ('CENTRAL', 'REGIONAL'));
ALTER TABLE warehouses DROP CONSTRAINT IF EXISTS warehouses_parent_check;
ALTER TABLE warehouses ADD CONSTRAINT warehouses_parent_check
    CHECK (parent_id IS NULL OR parent_id <> id);
CREATE INDEX IF NOT EXISTS idx_warehouses_parent ON warehouses(parent_id);

-- Mapping đã được nghiệp vụ xác nhận: tạo kho tổng mới, giữ nguyên ba kho cũ
-- thành kho địa điểm. Chạy sau backup và preflight trên môi trường an toàn.
INSERT INTO warehouses (code, name, address, type, parent_id)
VALUES ('CENTRAL-01', 'Kho tổng', '', 'CENTRAL', NULL)
ON CONFLICT (code) DO UPDATE SET type='CENTRAL', parent_id=NULL;
UPDATE warehouses
SET type='REGIONAL', parent_id=(SELECT id FROM warehouses WHERE code='CENTRAL-01')
WHERE code <> 'CENTRAL-01' AND type IS NULL;

CREATE TABLE IF NOT EXISTS sales_points (
    id bigserial PRIMARY KEY,
    code varchar(40) NOT NULL UNIQUE,
    name varchar(200) NOT NULL,
    point_type varchar(20) NOT NULL CHECK (point_type IN ('AGENT','STORE','SUPERMARKET')),
    address text NOT NULL DEFAULT '',
    contact_name varchar(160) NOT NULL DEFAULT '',
    phone varchar(40) NOT NULL DEFAULT '',
    email varchar(160) NOT NULL DEFAULT '',
    primary_warehouse_id bigint NOT NULL REFERENCES warehouses(id),
    active boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    note text NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS sales_point_warehouses (
    sales_point_id bigint NOT NULL REFERENCES sales_points(id) ON DELETE CASCADE,
    warehouse_id bigint NOT NULL REFERENCES warehouses(id),
    is_primary boolean NOT NULL DEFAULT false,
    PRIMARY KEY (sales_point_id, warehouse_id)
);
-- Keep exactly one primary route, matching sales_points.primary_warehouse_id.
UPDATE sales_point_warehouses SET is_primary=false WHERE is_primary;
UPDATE sales_point_warehouses spw
SET is_primary=true
FROM sales_points p
WHERE p.id = spw.sales_point_id AND spw.warehouse_id = p.primary_warehouse_id;
INSERT INTO sales_point_warehouses (sales_point_id, warehouse_id, is_primary)
SELECT id, primary_warehouse_id, true FROM sales_points
ON CONFLICT (sales_point_id, warehouse_id) DO UPDATE SET is_primary=true;
CREATE UNIQUE INDEX IF NOT EXISTS uq_sales_point_primary_warehouse
    ON sales_point_warehouses(sales_point_id) WHERE is_primary;
CREATE TABLE IF NOT EXISTS user_sales_points (
    user_id bigint NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    sales_point_id bigint NOT NULL REFERENCES sales_points(id) ON DELETE CASCADE,
    PRIMARY KEY (user_id, sales_point_id)
);

ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check;
ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN
    ('staff','approver','counter','admin','central_manager','regional_manager','sales_user'));

CREATE TABLE IF NOT EXISTS sales_orders (
    id bigserial PRIMARY KEY,
    code varchar(40) NOT NULL UNIQUE,
    sales_point_id bigint NOT NULL REFERENCES sales_points(id),
    warehouse_id bigint NOT NULL REFERENCES warehouses(id),
    status varchar(24) NOT NULL DEFAULT 'DRAFT'
        CHECK (status IN ('DRAFT','PENDING','APPROVED','DELIVERING','PARTIALLY_RECEIVED','COMPLETED','CANCELLED')),
    created_by bigint NOT NULL REFERENCES users(id),
    approved_by bigint REFERENCES users(id),
    approved_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT now(),
    note text NOT NULL DEFAULT '',
    request_key varchar(64) UNIQUE
);
CREATE TABLE IF NOT EXISTS sales_order_lines (
    id bigserial PRIMARY KEY,
    order_id bigint NOT NULL REFERENCES sales_orders(id) ON DELETE CASCADE,
    item_id bigint NOT NULL REFERENCES items(id),
    batch_id bigint NOT NULL,
    requested_qty numeric(14,3) NOT NULL CHECK (requested_qty > 0),
    allocated_qty numeric(14,3) NOT NULL DEFAULT 0 CHECK (allocated_qty >= 0),
    FOREIGN KEY (batch_id,item_id) REFERENCES batches(id,item_id),
    UNIQUE (order_id,batch_id)
);
CREATE TABLE IF NOT EXISTS sales_deliveries (
    id bigserial PRIMARY KEY,
    code varchar(40) NOT NULL UNIQUE,
    order_id bigint NOT NULL REFERENCES sales_orders(id),
    status varchar(20) NOT NULL DEFAULT 'DELIVERING'
        CHECK (status IN ('DELIVERING','PARTIALLY_RECEIVED','COMPLETED','CANCELLED')),
    delivered_by bigint NOT NULL REFERENCES users(id),
    received_by bigint REFERENCES users(id),
    delivered_at timestamptz NOT NULL DEFAULT now(),
    received_at timestamptz,
    note text NOT NULL DEFAULT '',
    request_key varchar(64) UNIQUE
);
CREATE TABLE IF NOT EXISTS sales_delivery_lines (
    id bigserial PRIMARY KEY,
    delivery_id bigint NOT NULL REFERENCES sales_deliveries(id) ON DELETE CASCADE,
    order_line_id bigint NOT NULL REFERENCES sales_order_lines(id),
    batch_id bigint NOT NULL,
    shipped_qty numeric(14,3) NOT NULL CHECK (shipped_qty > 0),
    received_qty numeric(14,3) CHECK (received_qty >= 0),
    shortage_qty numeric(14,3) NOT NULL DEFAULT 0 CHECK (shortage_qty >= 0),
    note text NOT NULL DEFAULT '',
    FOREIGN KEY (batch_id) REFERENCES batches(id),
    UNIQUE (delivery_id,order_line_id)
);

ALTER TABLE vouchers ADD COLUMN IF NOT EXISTS supplier_id bigint REFERENCES suppliers(id);
ALTER TABLE vouchers DROP CONSTRAINT IF EXISTS vouchers_type_check;
ALTER TABLE vouchers ADD CONSTRAINT vouchers_type_check CHECK (type IN ('in','out','transfer','adjust','sale'));
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS sales_point_id bigint REFERENCES sales_points(id);
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS sales_delivery_id bigint REFERENCES sales_deliveries(id);
ALTER TABLE stock_movements ADD COLUMN IF NOT EXISTS ownership varchar(20) NOT NULL DEFAULT 'COMPANY_OWNED';
ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_location_check;
ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_location_check
    CHECK ((warehouse_id IS NOT NULL AND sales_point_id IS NULL)
        OR (warehouse_id IS NULL AND sales_point_id IS NOT NULL));
ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_ownership_check;
ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_ownership_check
    CHECK (ownership IN ('COMPANY_OWNED','SOLD','CONSIGNMENT'));
ALTER TABLE stock_movements ALTER COLUMN warehouse_id DROP NOT NULL;
ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_voucher_id_warehouse_id_batch_id_key;
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movement_event
    ON stock_movements(voucher_id, COALESCE(warehouse_id,0), COALESCE(sales_point_id,0), batch_id);
CREATE INDEX IF NOT EXISTS idx_movements_sales_point ON stock_movements(sales_point_id, item_id, batch_id);
CREATE INDEX IF NOT EXISTS idx_movements_history_lookup ON stock_movements(warehouse_id, batch_id, occurred_at, id);
CREATE INDEX IF NOT EXISTS idx_sales_orders_scope ON sales_orders(sales_point_id, warehouse_id, status);
CREATE INDEX IF NOT EXISTS idx_sales_deliveries_order ON sales_deliveries(order_id, status);

COMMIT;
