-- Add the close timestamp required by history reconciliation.
-- Safe to run repeatedly on databases created before closed_at was added.
BEGIN;

ALTER TABLE stock_counts ADD COLUMN IF NOT EXISTS closed_at timestamptz;

UPDATE stock_counts sc
SET closed_at = COALESCE(v.confirmed_at, sc.created_at)
FROM vouchers v
WHERE sc.adjust_voucher_id = v.id
  AND sc.status = 'closed'
  AND sc.closed_at IS NULL;

COMMIT;
