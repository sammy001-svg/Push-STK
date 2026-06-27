-- ============================================================
-- BulkSTK Pro – Migration v4
-- Run once in phpMyAdmin after deploying this version.
-- ============================================================

CREATE TABLE IF NOT EXISTS campaign_templates (
    id               INT UNSIGNED  AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(200)  NOT NULL,
    description      TEXT          DEFAULT NULL,
    amount           DECIMAL(12,2) NOT NULL,
    account_ref      VARCHAR(100)  NOT NULL,
    transaction_desc VARCHAR(200)  NOT NULL DEFAULT 'Payment',
    created_by       INT UNSIGNED  NOT NULL,
    created_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_created_by (created_by)
) ENGINE=InnoDB;
