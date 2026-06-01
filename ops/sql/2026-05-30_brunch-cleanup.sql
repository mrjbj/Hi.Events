
  -- ---- Order 600 (item 609): 20.00 -> 15.00 -------------------------
  UPDATE orders SET total_gross = 15.00, total_before_additions = 15.00 WHERE id = 600 AND total_gross = 20.00;
  UPDATE order_items SET price = 15.00, total_before_additions = 15.00, total_gross = 15.00 WHERE id = 609 AND total_gross = 20.00;

  -- ---- Order 669 (item 679): 20.00 -> 15.00 -------------------------
  UPDATE orders SET total_gross = 15.00, total_before_additions = 15.00 WHERE id = 669 AND total_gross = 20.00;
  UPDATE order_items SET price = 15.00, total_before_additions = 15.00, total_gross = 15.00 WHERE id = 679 AND total_gross = 20.00;

  -- ---- Order 671 (item 681): 20.00 -> 15.00 -------------------------
  UPDATE orders SET total_gross = 15.00, total_before_additions = 15.00 WHERE id = 671 AND total_gross = 20.00;
  UPDATE order_items SET price = 15.00, total_before_additions = 15.00, total_gross = 15.00 WHERE id = 681 AND total_gross = 20.00;

  -- ---- Order 674 (item 684): 0.00 (comped) -> 15.00 ----------------
  UPDATE orders SET total_gross = 15.00, total_before_additions = 15.00 WHERE id = 674 AND total_gross = 0.00;
  UPDATE order_items SET price = 15.00, total_before_additions = 15.00, total_gross = 15.00 WHERE id = 684 AND total_gross = 0.00;

  -- Inspect before committing:
  SELECT o.id, o.short_id, o.total_gross, o.total_before_additions, oi.id AS item_id, oi.price, oi.total_gross AS item_gross
  FROM orders o JOIN order_items oi ON oi.order_id = o.id
   WHERE o.id IN (599, 600, 669, 671, 674)
   ORDER BY o.id;
  





  SELECT o.first_name, o.last_name, o.status, o.payment_status, o.payment_provider, o.offline_payment_method, o.offline_payment_reference,  o.id, o.short_id, o.total_gross, o.total_before_additions, 
    oi.id AS item_id, oi.total_before_additions, oi.product_id, oi.item_name, oi.quantity, oi.price, oi.total_gross AS item_gross
  FROM orders o JOIN order_items oi ON oi.order_id = o.id
   WHERE o.id IN (599, 600, 669, 671, 674) or o.id in (662, 663, 672, 673.)
   ORDER BY o.id;



  SELECT 
--     o.* 
    oi.*
  FROM orders o JOIN order_items oi ON oi.order_id = o.id
   WHERE o.id IN (599, 600, 669, 671, 674)
   ORDER BY o.id;


  select * from order_payments; 


  select * from 
order_payment_adjustments;


  select * from orders where first_name = 'Kathleen' 
  select * from events; 
