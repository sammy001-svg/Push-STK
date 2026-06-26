-- ============================================================
-- BulkSTK Pro – Migration v2
-- Run this once against an existing v1 database.
-- Safe to run on fresh installs (all statements use IF NOT EXISTS
-- or check column existence via ALTER TABLE … MODIFY).
-- ============================================================

-- 1. Add 'sent' to campaign_recipients.status enum
--    (previously missing — caused silent '' storage in strict mode)
ALTER TABLE campaign_recipients
  MODIFY COLUMN status
    ENUM('pending','processing','sent','success','failed','cancelled','timeout')
    NOT NULL DEFAULT 'pending';

-- 2. Add updated_at to campaign_recipients
--    (required by the cron stuck-processing recovery query)
ALTER TABLE campaign_recipients
  ADD COLUMN IF NOT EXISTS updated_at
    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    AFTER completed_at;

-- 3. Composite index for the hot batch-fetch query:
--    WHERE campaign_id = ? AND status = 'pending'
--    ORDER BY retry_count ASC, id ASC  LIMIT ?
--    Covers filter + sort entirely in-index; no filesort.
ALTER TABLE campaign_recipients
  ADD INDEX IF NOT EXISTS idx_campaign_status_retry (campaign_id, status, retry_count, id);

-- 4. Composite index for stats aggregation queries:
--    WHERE campaign_id = ?  +  GROUP BY / SUM on status
--    Allows covering index scan without hitting row data.
ALTER TABLE campaign_recipients
  ADD INDEX IF NOT EXISTS idx_campaign_status (campaign_id, status);

-- 5. Composite index for cron stale-sent cleanup:
--    WHERE status = 'sent' AND sent_at < NOW() - INTERVAL ? SECOND
ALTER TABLE campaign_recipients
  ADD INDEX IF NOT EXISTS idx_status_sent_at (status, sent_at);

-- 6. Drop old single-column status index (superseded by composites above)
ALTER TABLE campaign_recipients
  DROP INDEX IF EXISTS idx_status;
