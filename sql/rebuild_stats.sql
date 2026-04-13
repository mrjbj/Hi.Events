-- Rebuild stats for dashboard.
-- Matches application logic in:
--   EventStatisticsIncrementService (order completed)
--   EventStatisticsRefundService (refund processed)
--   EventStatisticsCancellationService (order cancelled)

-- Step 1. Backup your current statistics first
CREATE TABLE IF NOT EXISTS event_statistics_backup
    (LIKE event_statistics INCLUDING ALL);
TRUNCATE event_statistics_backup;
INSERT INTO event_statistics_backup
SELECT * FROM event_statistics;

CREATE TABLE IF NOT EXISTS event_daily_statistics_backup
    (LIKE event_daily_statistics INCLUDING ALL);
TRUNCATE event_daily_statistics_backup;
INSERT INTO event_daily_statistics_backup
SELECT * FROM event_daily_statistics;

-- Step 2. Clear existing statistics
DELETE FROM event_statistics
WHERE event_id IN (SELECT DISTINCT event_id FROM orders);

DELETE FROM event_daily_statistics
WHERE event_id IN (SELECT DISTINCT event_id FROM orders);

-- Step 3. Rebuild event_statistics
-- App logic:
--   COMPLETED → adds financials + counts
--   CANCELLED → decrements counts only (financials stay)
--   REFUNDED  → subtracts refund from gross, adds to total_refunded,
--               proportionally reduces tax and fee
WITH event_refunds AS (
    SELECT
        o.event_id,
        SUM(orf.amount) AS total_refunded,
        -- proportional tax/fee adjustments per order, then summed
        SUM(
            CASE WHEN o.total_gross > 0
            THEN o.total_tax * (orf.amount / o.total_gross)
            ELSE 0 END
        ) AS tax_adjustment,
        SUM(
            CASE WHEN o.total_gross > 0
            THEN o.total_fee * (orf.amount / o.total_gross)
            ELSE 0 END
        ) AS fee_adjustment
    FROM order_refunds orf
    JOIN orders o ON o.id = orf.order_id
    WHERE orf.status = 'succeeded'
        AND orf.deleted_at IS NULL
    GROUP BY o.event_id
),
-- counts from COMPLETED only (app decrements these on cancel)
completed_counts AS (
    SELECT
        o.event_id,
        COALESCE(SUM(oi.quantity), 0) AS products_sold,
        COALESCE(SUM(
            CASE WHEN p.product_type = 'TICKET'
            THEN oi.quantity ELSE 0 END
        ), 0) AS attendees_registered,
        COUNT(DISTINCT o.id) AS orders_created
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.status = 'COMPLETED'
    GROUP BY o.event_id
),
-- financials from COMPLETED + CANCELLED (app keeps financials on cancel)
financials AS (
    SELECT
        o.event_id,
        COALESCE(SUM(o.total_gross), 0) AS sales_total_gross,
        COALESCE(SUM(o.total_before_additions), 0)
            AS sales_total_before_additions,
        COALESCE(SUM(o.total_tax), 0) AS total_tax,
        COALESCE(SUM(o.total_fee), 0) AS total_fee
    FROM orders o
    WHERE o.status IN ('COMPLETED', 'CANCELLED')
    GROUP BY o.event_id
),
cancelled_counts AS (
    SELECT
        event_id,
        COUNT(*) AS orders_cancelled
    FROM orders
    WHERE status = 'CANCELLED'
    GROUP BY event_id
)
INSERT INTO event_statistics (
    event_id, products_sold, attendees_registered,
    sales_total_gross, sales_total_before_additions,
    total_tax, total_fee,
    orders_created, orders_cancelled, total_refunded,
    created_at, updated_at
)
SELECT
    f.event_id,
    COALESCE(cc.products_sold, 0),
    COALESCE(cc.attendees_registered, 0),
    f.sales_total_gross - COALESCE(er.total_refunded, 0),
    f.sales_total_before_additions,
    GREATEST(0, f.total_tax - COALESCE(er.tax_adjustment, 0)),
    GREATEST(0, f.total_fee - COALESCE(er.fee_adjustment, 0)),
    COALESCE(cc.orders_created, 0),
    COALESCE(can.orders_cancelled, 0),
    COALESCE(er.total_refunded, 0),
    NOW(),
    NOW()
FROM financials f
LEFT JOIN completed_counts cc ON cc.event_id = f.event_id
LEFT JOIN event_refunds er ON er.event_id = f.event_id
LEFT JOIN cancelled_counts can ON can.event_id = f.event_id;

-- Step 4. Rebuild event_daily_statistics
-- Daily refunds bucketed by ORDER creation date (matches app logic)
WITH daily_refunds AS (
    SELECT
        o.event_id,
        DATE(o.created_at) AS date,
        SUM(orf.amount) AS total_refunded,
        SUM(
            CASE WHEN o.total_gross > 0
            THEN o.total_tax * (orf.amount / o.total_gross)
            ELSE 0 END
        ) AS tax_adjustment,
        SUM(
            CASE WHEN o.total_gross > 0
            THEN o.total_fee * (orf.amount / o.total_gross)
            ELSE 0 END
        ) AS fee_adjustment
    FROM order_refunds orf
    JOIN orders o ON o.id = orf.order_id
    WHERE orf.status = 'succeeded'
        AND orf.deleted_at IS NULL
    GROUP BY o.event_id, DATE(o.created_at)
),
daily_completed_counts AS (
    SELECT
        o.event_id,
        DATE(o.created_at) AS date,
        COALESCE(SUM(oi.quantity), 0) AS products_sold,
        COALESCE(SUM(
            CASE WHEN p.product_type = 'TICKET'
            THEN oi.quantity ELSE 0 END
        ), 0) AS attendees_registered,
        COUNT(DISTINCT o.id) AS orders_created
    FROM orders o
    LEFT JOIN order_items oi ON oi.order_id = o.id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.status = 'COMPLETED'
    GROUP BY o.event_id, DATE(o.created_at)
),
daily_financials AS (
    SELECT
        o.event_id,
        DATE(o.created_at) AS date,
        COALESCE(SUM(o.total_gross), 0) AS sales_total_gross,
        COALESCE(SUM(o.total_before_additions), 0)
            AS sales_total_before_additions,
        COALESCE(SUM(o.total_tax), 0) AS total_tax,
        COALESCE(SUM(o.total_fee), 0) AS total_fee
    FROM orders o
    WHERE o.status IN ('COMPLETED', 'CANCELLED')
    GROUP BY o.event_id, DATE(o.created_at)
),
daily_cancelled AS (
    SELECT
        event_id,
        DATE(created_at) AS date,
        COUNT(*) AS orders_cancelled
    FROM orders
    WHERE status = 'CANCELLED'
    GROUP BY event_id, DATE(created_at)
)
INSERT INTO event_daily_statistics (
    event_id, date, products_sold, attendees_registered,
    sales_total_gross, sales_total_before_additions,
    total_tax, total_fee,
    orders_created, orders_cancelled, total_refunded,
    created_at, updated_at
)
SELECT
    f.event_id,
    f.date,
    COALESCE(dc.products_sold, 0),
    COALESCE(dc.attendees_registered, 0),
    f.sales_total_gross - COALESCE(dr.total_refunded, 0),
    f.sales_total_before_additions,
    GREATEST(0, f.total_tax - COALESCE(dr.tax_adjustment, 0)),
    GREATEST(0, f.total_fee - COALESCE(dr.fee_adjustment, 0)),
    COALESCE(dc.orders_created, 0),
    COALESCE(dcan.orders_cancelled, 0),
    COALESCE(dr.total_refunded, 0),
    NOW(),
    NOW()
FROM daily_financials f
LEFT JOIN daily_completed_counts dc
    ON dc.event_id = f.event_id AND dc.date = f.date
LEFT JOIN daily_refunds dr
    ON dr.event_id = f.event_id AND dr.date = f.date
LEFT JOIN daily_cancelled dcan
    ON dcan.event_id = f.event_id AND dcan.date = f.date;

-- Step 5. Verify results (set event_id here)
SELECT
    'Rebuilt Stats' AS source,
    sales_total_gross AS gross_sales,
    total_refunded,
    total_tax,
    total_fee,
    orders_created,
    orders_cancelled,
    products_sold,
    attendees_registered
FROM event_statistics
WHERE event_id = 6

UNION ALL

SELECT
    'Raw Order Data' AS source,
    COALESCE(SUM(total_gross), 0)
        - COALESCE((
            SELECT SUM(orf.amount)
            FROM order_refunds orf
            JOIN orders o2 ON o2.id = orf.order_id
            WHERE o2.event_id = 6
                AND orf.status = 'succeeded'
                AND orf.deleted_at IS NULL
        ), 0) AS gross_sales,
    COALESCE((
        SELECT SUM(orf.amount)
        FROM order_refunds orf
        JOIN orders o2 ON o2.id = orf.order_id
        WHERE o2.event_id = 6
            AND orf.status = 'succeeded'
            AND orf.deleted_at IS NULL
    ), 0) AS total_refunded,
    COALESCE(SUM(total_tax), 0) AS total_tax,
    COALESCE(SUM(total_fee), 0) AS total_fee,
    SUM(CASE WHEN status = 'COMPLETED' THEN 1 ELSE 0 END)
        AS orders_created,
    SUM(CASE WHEN status = 'CANCELLED' THEN 1 ELSE 0 END)
        AS orders_cancelled,
    0 AS products_sold,
    0 AS attendees_registered
FROM orders
WHERE status IN ('COMPLETED', 'CANCELLED')
    AND event_id = 6;
