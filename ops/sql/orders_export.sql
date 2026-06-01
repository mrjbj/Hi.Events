
-- =============================================================================
-- export_orders(p_event_id INT)
--
-- Creates a temp table `orders_export` with one row per order_item (line item).
-- Order-level fields repeat on each row. Dynamically pivots ORDER-level
-- questions into one column per question title.
--
-- Reconciliation columns (channel, collected, comped, balance) mirror the
-- dashboard's OrderBalanceService / EventReconciliationService exactly:
--   collected = offline ledger receipts (PAYMENT+DONATION, signed) + Stripe
--               amount_received − total_refunded
--   comped    = COMP + WRITE_OFF ledger rows
--   balance   = total_gross − receipts − comps + total_refunded  (a refund
--               re-opens the balance; an overpayment shows negative)
--   channel   = STRIPE for Stripe orders, else the largest settling payment
--               method (a card receipt reconciles under SQUARE)
-- These are ORDER-level and REPEAT on every line — dedupe by order_id before
-- summing in Excel. (The dropped offline_payment_* columns are no longer used.)
--
-- Usage in pgAdmin:
--   SELECT export_orders(5);
--   SELECT * FROM orders_export;

-- Connect to pgAdmin via: 
-- https://hi-events-u50358.vm.elestio.app:64373
-- user: jason@brucejones.biz
-- pass: same pass as is used for the service in elest.io 
-- =============================================================================

CREATE OR REPLACE FUNCTION export_orders(p_event_id INT)
RETURNS VOID
LANGUAGE plpgsql
AS $$
DECLARE
    v_question RECORD;
    v_sql      TEXT;
    v_q_cols   TEXT := '';
    v_q_joins  TEXT := '';
    v_q_num    INT  := 0;
BEGIN
    -- Drop the temp table if it exists from a previous call in this session
    DROP TABLE IF EXISTS orders_export;

    -- -------------------------------------------------------------------------
    -- Build dynamic question columns (one per ORDER-level question for event)
    -- -------------------------------------------------------------------------
    FOR v_question IN
        SELECT q.id AS question_id,
               q.title AS question_title
        FROM   questions q
        WHERE  q.event_id   = p_event_id
          AND  q.belongs_to = 'ORDER'
          AND  q.deleted_at IS NULL
        ORDER BY q.id
    LOOP
        v_q_num := v_q_num + 1;

        -- Column: use question title, sanitised for use as alias
        v_q_cols := v_q_cols || format(
            E',\n       qa%s.answer ->> 0  AS %I',
            v_q_num,
            v_question.question_title
        );

        -- LEFT JOIN to question_answers for this specific question
        v_q_joins := v_q_joins || format(
            E'\n    LEFT JOIN question_answers qa%s\n        ON qa%s.order_id    = o.id\n       AND qa%s.question_id = %s\n       AND qa%s.attendee_id IS NULL',
            v_q_num, v_q_num, v_q_num, v_question.question_id, v_q_num
        );
    END LOOP;

    -- -------------------------------------------------------------------------
    -- Build and execute the main query
    -- -------------------------------------------------------------------------
    v_sql := E'CREATE TEMP TABLE orders_export AS\n'
        || E'SELECT\n'
        -- Order-level columns
        || E'       o.id                          AS order_id,\n'
        || E'       o.public_id,\n'
        || E'       o.status,\n'
        || E'       o.payment_provider     AS paid_via,\n'
        || E'       o.payment_status,\n'
        || E'       o.refund_status,\n'
        || E'       o.first_name,\n'
        || E'       o.last_name,\n'
        || E'       o.email,\n'
        || E'       TO_CHAR(o.created_at, ''YYYY-MM-DD HH24:MI:SS'') AS created_at,\n'
        || E'       o.notes,\n'
        -- Line-item detail columns
        || E'       oi.item_name as item,\n'
        || E'       coalesce(oi.price_before_discount, oi.price) as full_price,\n'
        || E'       o.promo_code,\n'
        || E'       oi.quantity,\n'
        || E'       oi.price as discounted_price,\n'
        || E'       oi.total_gross  AS item_gross,\n'
        || E'       o.total_gross                  AS order_gross,\n'
        || E'       o.total_refunded               AS order_refund,\n'
        -- Reconciliation (order-level; mirrors OrderBalanceService / dashboard).
        -- These repeat on every line of an order — dedupe by order_id before summing.
        || E'       ch.channel                     AS channel,\n'
        || E'       ROUND(bal.cash_receipts + sr.stripe_receipts - o.total_refunded, 2)                          AS collected,\n'
        || E'       ROUND(bal.comps, 2)                                                                          AS comped,\n'
        || E'       ROUND(o.total_gross - (bal.cash_receipts + sr.stripe_receipts) - bal.comps + o.total_refunded, 2) AS balance,\n'
        || E'       o.currency,\n'
        -- Check-in summary
        || E'       COALESCE(ci.total_attendees, 0)  AS registered,\n'
        || E'       COALESCE(ci.checked_in_count, 0) AS checked_in\n'
        -- Dynamic question columns
        || v_q_cols
        || E'\nFROM   orders o\n'
        || E'    JOIN order_items oi\n'
        || E'        ON oi.order_id = o.id\n'
        || E'       AND oi.deleted_at IS NULL\n'
        -- Check-in subquery: count checked-in vs total attendees per order
        || E'    LEFT JOIN LATERAL (\n'
        || E'        SELECT COUNT(*)                                    AS total_attendees,\n'
        || E'               COUNT(aci.id)                               AS checked_in_count\n'
        || E'        FROM   attendees a2\n'
        || E'        LEFT JOIN attendee_check_ins aci\n'
        || E'            ON aci.attendee_id = a2.id\n'
        || E'           AND aci.deleted_at IS NULL\n'
        || E'        WHERE  a2.order_id   = o.id\n'
        || E'          AND  a2.deleted_at IS NULL\n'
        || E'    ) ci ON true\n'
        -- Offline ledger receipts + comps per order (signed; reversals net out)
        || E'    LEFT JOIN LATERAL (\n'
        || E'        SELECT\n'
        || E'            COALESCE(SUM(op.amount) FILTER (WHERE op.transaction_type IN (''PAYMENT'',''DONATION'')), 0) AS cash_receipts,\n'
        || E'            COALESCE(SUM(op.amount) FILTER (WHERE op.transaction_type IN (''COMP'',''WRITE_OFF'')), 0)   AS comps\n'
        || E'        FROM   order_payments op\n'
        || E'        WHERE  op.order_id = o.id AND op.deleted_at IS NULL\n'
        || E'    ) bal ON true\n'
        -- Confirmed Stripe receipts (amount_received is in minor units)
        || E'    LEFT JOIN LATERAL (\n'
        || E'        SELECT COALESCE(SUM(sp.amount_received), 0) / 100.0 AS stripe_receipts\n'
        || E'        FROM   stripe_payments sp\n'
        || E'        WHERE  sp.order_id = o.id AND sp.deleted_at IS NULL AND sp.amount_received > 0\n'
        || E'    ) sr ON true\n'
        -- Reconciliation channel: Stripe orders are STRIPE; else the largest
        -- settling payment method (a card receipt reconciles under Square)
        || E'    LEFT JOIN LATERAL (\n'
        || E'        SELECT CASE\n'
        || E'            WHEN o.payment_provider = ''STRIPE'' THEN ''STRIPE''\n'
        || E'            ELSE COALESCE((\n'
        || E'                SELECT CASE op2.payment_method\n'
        || E'                           WHEN ''CREDIT_CARD''   THEN ''SQUARE''\n'
        || E'                           WHEN ''CASH''          THEN ''CASH''\n'
        || E'                           WHEN ''CHECK''         THEN ''CHECK''\n'
        || E'                           WHEN ''BANK_TRANSFER'' THEN ''BANK_TRANSFER''\n'
        || E'                           ELSE ''OTHER''\n'
        || E'                       END\n'
        || E'                FROM   order_payments op2\n'
        || E'                WHERE  op2.order_id = o.id AND op2.transaction_type = ''PAYMENT'' AND op2.amount > 0 AND op2.deleted_at IS NULL\n'
        || E'                ORDER BY op2.amount DESC LIMIT 1\n'
        || E'            ), ''OTHER'')\n'
        || E'        END AS channel\n'
        || E'    ) ch ON true\n'
        -- Dynamic question joins
        || v_q_joins
        || format(E'\nWHERE  o.event_id   = %s', p_event_id)
        || E'\n  AND  o.deleted_at IS NULL'
        || E'\n  AND  o.status     != ''RESERVED'''
        || E'\nORDER BY o.id, oi.id;';

    EXECUTE v_sql;
END;
$$;

