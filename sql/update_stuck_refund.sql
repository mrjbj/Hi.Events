-- complete a refund that is stuck
-- set these variables before running:
DO $$
DECLARE
  v_payment_intent_id TEXT := 'pi_3T2NX5PY9LXhU3dp2YjNBRbB';
  v_refund_id TEXT := 're_3T2NX5PY9LXhU3dp2YaYgRqC';
  v_refund_amount NUMERIC := 15.00;
BEGIN
  UPDATE orders o
  SET refund_status = 'REFUNDED'
    , total_refunded = v_refund_amount
  FROM
    stripe_payments sp
  WHERE
    sp.order_id = o.id
    AND sp.payment_intent_id = v_payment_intent_id;

  INSERT INTO order_refunds (order_id , payment_provider , refund_id , amount , currency , status , created_at , updated_at)
  SELECT
    o.id
    , 'stripe'
    , v_refund_id
    , v_refund_amount
    , 'usd'
    , 'succeeded'
    , NOW()
    , NOW()
  FROM
    orders o
    JOIN stripe_payments sp ON sp.order_id = o.id
  WHERE
    sp.payment_intent_id = v_payment_intent_id
    AND NOT EXISTS (
      SELECT 1 FROM order_refunds r
      WHERE r.refund_id = v_refund_id
    );
END $$;
