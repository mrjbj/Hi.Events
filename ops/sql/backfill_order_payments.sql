-- Optional, MANUAL backfill. Run by hand (not a migration) on a DB where you
-- want pre-ledger offline-paid orders to report a correct balance.
--
-- Synthesises one full-settlement order_payments row for each order that was
-- marked paid via an offline method before the payments ledger existed (i.e. has
-- no order_payments row). Idempotent: re-running skips orders that already have a
-- ledger row. Refunds are intentionally ignored — they live in
-- orders.total_refunded, which OrderBalanceService already reads.
--
-- Review first:
--   SELECT id, total_gross, offline_payment_method, offline_payment_reference
--   FROM orders o
--   WHERE o.status='COMPLETED' AND o.payment_provider='OFFLINE'
--     AND o.payment_status='PAYMENT_RECEIVED' AND o.total_gross>0 AND o.deleted_at IS NULL
--     AND NOT EXISTS (SELECT 1 FROM order_payments op WHERE op.order_id=o.id AND op.deleted_at IS NULL);

INSERT INTO order_payments
    (order_id, type, amount, currency, reference, note, created_at, updated_at)
SELECT
    o.id,
    CASE WHEN o.offline_payment_method = 'CREDIT_CARD' THEN 'CARD'
         ELSE COALESCE(o.offline_payment_method, 'OTHER') END,
    o.total_gross,
    o.currency,
    o.offline_payment_reference,
    'Backfilled from settled offline order',
    NOW(),
    NOW()
FROM orders o
WHERE o.status = 'COMPLETED'
  AND o.payment_provider = 'OFFLINE'
  AND o.payment_status = 'PAYMENT_RECEIVED'
  AND o.total_gross > 0
  AND o.deleted_at IS NULL
  AND NOT EXISTS (
      SELECT 1 FROM order_payments op
      WHERE op.order_id = o.id AND op.deleted_at IS NULL
  );

-- Undo (if needed):
--   DELETE FROM order_payments WHERE note = 'Backfilled from settled offline order';
