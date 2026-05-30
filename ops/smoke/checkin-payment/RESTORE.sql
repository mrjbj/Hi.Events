-- Restore order 15 (O-6FDWJZ5, event 10) to its pre-smoke state.
-- Captured 2026-05-30 before the checkin-payment smoke run.
-- Run with:
--   docker compose -f docker/development/docker-compose.dev.yml exec -T \
--     -e PGPASSWORD=password pgsql psql -U username -d backend -f - < ops/smoke/checkin-payment/RESTORE.sql

BEGIN;

UPDATE orders SET
    status                    = 'COMPLETED',
    payment_status            = 'PAYMENT_RECEIVED',
    payment_provider          = 'OFFLINE',
    offline_payment_method    = 'CHECK',
    offline_payment_reference = 'Check 2058',
    total_gross               = 1.57,
    total_before_additions    = 1.57
WHERE id = 15;

UPDATE order_items SET
    price                  = 1.57,
    total_gross            = 1.57,
    total_before_additions = 1.57,
    price_before_discount  = NULL
WHERE id = 15;

UPDATE attendees SET status = 'ACTIVE' WHERE id = 13;

-- Un-delete the original check-in (id 3), drop any created during the smoke.
UPDATE attendee_check_ins SET deleted_at = NULL WHERE id = 3;
DELETE FROM attendee_check_ins WHERE attendee_id = 13 AND id <> 3;

-- Drop any ledger rows written during the smoke.
DELETE FROM order_payments WHERE order_id = 15;

COMMIT;
