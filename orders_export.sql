
-- =============================================================================
-- export_orders(p_event_id INT)
--
-- Creates a temp table `orders_export` with one row per order_item (line item).
-- Order-level fields repeat on each row. Dynamically pivots ORDER-level
-- questions into one column per question title.
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
        -- Dynamic question joins
        || v_q_joins
        || format(E'\nWHERE  o.event_id   = %s', p_event_id)
        || E'\n  AND  o.deleted_at IS NULL'
        || E'\n  AND  o.status     != ''RESERVED'''
        || E'\nORDER BY o.id, oi.id;';

    EXECUTE v_sql;
END;
$$;

