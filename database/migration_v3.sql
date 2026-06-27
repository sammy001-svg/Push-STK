-- ============================================================
-- BulkSTK Pro – Migration v3
-- Run once in phpMyAdmin after deploying this version.
-- All statements are idempotent (IF NOT EXISTS).
-- ============================================================

-- 1. Index on transactions.customer_id
--    Fixes: getGroupPerformance() full-table-scan on transactions
--    (LEFT JOIN transactions t ON t.customer_id = cu.id was unindexed)
ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_customer_id (customer_id);

-- 2. Index on customers.group_name
--    Fixes: getGroupPerformance() join (ON cu.group_name = g.name) was unindexed
ALTER TABLE customers
  ADD INDEX IF NOT EXISTS idx_group_name (group_name);

-- 3. Composite index: transactions(customer_id, status, initiated_at)
--    Covers the date-filtered group performance query in one index scan
ALTER TABLE transactions
  ADD INDEX IF NOT EXISTS idx_customer_status_date (customer_id, status, initiated_at);
