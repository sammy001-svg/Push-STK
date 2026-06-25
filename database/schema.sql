-- ============================================================
-- BulkSTK Pro - Database Schema
-- M-Pesa Bulk STK Push Dashboard
-- ============================================================
-- cPanel / Shared Hosting:
--   1. Create the database in cPanel → MySQL Databases
--      (it will be named like: cpanelusername_mpesa)
--   2. Select that database in phpMyAdmin BEFORE importing this file
--   3. Do NOT run this file with CREATE DATABASE / USE — they are
--      intentionally removed for shared-hosting compatibility.
-- ============================================================

-- ============================================================
-- Admin Users
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_users (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(100)  NOT NULL,
    email        VARCHAR(150)  NOT NULL UNIQUE,
    password     VARCHAR(255)  NOT NULL,
    role         ENUM('super_admin','admin','operator') NOT NULL DEFAULT 'operator',
    avatar       VARCHAR(255)  DEFAULT NULL,
    status       TINYINT(1)    NOT NULL DEFAULT 1,
    last_login   DATETIME      DEFAULT NULL,
    created_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Customers / Recipients
-- ============================================================
CREATE TABLE IF NOT EXISTS customers (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(150)  NOT NULL,
    phone          VARCHAR(20)   NOT NULL,
    phone_formatted VARCHAR(20)  NOT NULL,
    email          VARCHAR(150)  DEFAULT NULL,
    id_number      VARCHAR(30)   DEFAULT NULL,
    account_number VARCHAR(80)   DEFAULT NULL,
    group_name     VARCHAR(80)   DEFAULT NULL,
    notes          TEXT          DEFAULT NULL,
    status         TINYINT(1)    NOT NULL DEFAULT 1,
    created_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_phone (phone_formatted)
) ENGINE=InnoDB;

-- ============================================================
-- Campaigns
-- ============================================================
CREATE TABLE IF NOT EXISTS campaigns (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(200)    NOT NULL,
    description       TEXT            DEFAULT NULL,
    amount            DECIMAL(12,2)   NOT NULL,
    account_ref       VARCHAR(100)    NOT NULL,
    transaction_desc  VARCHAR(200)    NOT NULL DEFAULT 'Payment',
    total_recipients  INT UNSIGNED    NOT NULL DEFAULT 0,
    sent_count        INT UNSIGNED    NOT NULL DEFAULT 0,
    success_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    failed_count      INT UNSIGNED    NOT NULL DEFAULT 0,
    pending_count     INT UNSIGNED    NOT NULL DEFAULT 0,
    cancelled_count   INT UNSIGNED    NOT NULL DEFAULT 0,
    total_amount      DECIMAL(14,2)   NOT NULL DEFAULT 0.00,
    status            ENUM('draft','scheduled','queued','running','paused','completed','failed') NOT NULL DEFAULT 'draft',
    scheduled_at      DATETIME        DEFAULT NULL,
    created_by        INT UNSIGNED    NOT NULL,
    started_at        DATETIME        DEFAULT NULL,
    completed_at      DATETIME        DEFAULT NULL,
    created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_status (status),
    KEY idx_created_by (created_by),
    FOREIGN KEY (created_by) REFERENCES admin_users(id)
) ENGINE=InnoDB;

-- ============================================================
-- Campaign Recipients (per-push tracking)
-- ============================================================
CREATE TABLE IF NOT EXISTS campaign_recipients (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id         INT UNSIGNED  NOT NULL,
    customer_id         INT UNSIGNED  NOT NULL,
    phone               VARCHAR(20)   NOT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    status              ENUM('pending','processing','success','failed','cancelled','timeout') NOT NULL DEFAULT 'pending',
    merchant_request_id VARCHAR(100)  DEFAULT NULL,
    checkout_request_id VARCHAR(100)  DEFAULT NULL,
    result_code         INT           DEFAULT NULL,
    result_desc         VARCHAR(255)  DEFAULT NULL,
    mpesa_receipt       VARCHAR(60)   DEFAULT NULL,
    retry_count         TINYINT       NOT NULL DEFAULT 0,
    error_message       VARCHAR(255)  DEFAULT NULL,
    sent_at             DATETIME      DEFAULT NULL,
    completed_at        DATETIME      DEFAULT NULL,
    KEY idx_campaign (campaign_id),
    KEY idx_customer (customer_id),
    KEY idx_status (status),
    KEY idx_checkout (checkout_request_id),
    FOREIGN KEY (campaign_id)  REFERENCES campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id)  REFERENCES customers(id)
) ENGINE=InnoDB;

-- ============================================================
-- All STK Push Transactions
-- ============================================================
CREATE TABLE IF NOT EXISTS transactions (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id          INT UNSIGNED  DEFAULT NULL,
    recipient_id         INT UNSIGNED  DEFAULT NULL,
    customer_id          INT UNSIGNED  DEFAULT NULL,
    phone                VARCHAR(20)   NOT NULL,
    amount               DECIMAL(12,2) NOT NULL,
    account_ref          VARCHAR(100)  NOT NULL,
    description          VARCHAR(200)  NOT NULL,
    merchant_request_id  VARCHAR(100)  DEFAULT NULL,
    checkout_request_id  VARCHAR(100)  DEFAULT NULL,
    request_id           VARCHAR(100)  DEFAULT NULL,
    response_code        VARCHAR(10)   DEFAULT NULL,
    response_description VARCHAR(255)  DEFAULT NULL,
    customer_message     VARCHAR(255)  DEFAULT NULL,
    result_code          INT           DEFAULT NULL,
    result_description   VARCHAR(255)  DEFAULT NULL,
    mpesa_receipt        VARCHAR(60)   DEFAULT NULL,
    transaction_date     VARCHAR(20)   DEFAULT NULL,
    status               ENUM('initiated','pending','success','failed','timeout','cancelled') NOT NULL DEFAULT 'initiated',
    raw_callback         LONGTEXT      DEFAULT NULL,
    initiated_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at         DATETIME      DEFAULT NULL,
    KEY idx_campaign (campaign_id),
    KEY idx_phone (phone),
    KEY idx_status (status),
    KEY idx_checkout (checkout_request_id),
    KEY idx_receipt (mpesa_receipt),
    KEY idx_initiated (initiated_at)
) ENGINE=InnoDB;

-- ============================================================
-- Application Settings (Key-Value Store)
-- ============================================================
CREATE TABLE IF NOT EXISTS settings (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key   VARCHAR(100)  NOT NULL UNIQUE,
    setting_value LONGTEXT      DEFAULT NULL,
    setting_group VARCHAR(60)   NOT NULL DEFAULT 'general',
    updated_by    INT UNSIGNED  DEFAULT NULL,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Activity / Audit Logs
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED  DEFAULT NULL,
    action     VARCHAR(100)  NOT NULL,
    module     VARCHAR(60)   NOT NULL DEFAULT 'general',
    details    TEXT          DEFAULT NULL,
    ip_address VARCHAR(45)   DEFAULT NULL,
    user_agent TEXT          DEFAULT NULL,
    created_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_user (user_id),
    KEY idx_module (module),
    KEY idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- Customer Groups
-- ============================================================
CREATE TABLE IF NOT EXISTS customer_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100)  NOT NULL UNIQUE,
    description VARCHAR(255)  DEFAULT NULL,
    color       VARCHAR(20)   NOT NULL DEFAULT '#00A651',
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- Import History
-- ============================================================
CREATE TABLE IF NOT EXISTS import_logs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename      VARCHAR(255)  NOT NULL,
    total_rows    INT UNSIGNED  NOT NULL DEFAULT 0,
    imported      INT UNSIGNED  NOT NULL DEFAULT 0,
    updated       INT UNSIGNED  NOT NULL DEFAULT 0,
    skipped       INT UNSIGNED  NOT NULL DEFAULT 0,
    errors        INT UNSIGNED  NOT NULL DEFAULT 0,
    group_name    VARCHAR(80)   DEFAULT NULL,
    imported_by   INT UNSIGNED  DEFAULT NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_created (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- Default seed data
-- ============================================================

-- Default admin (password: Admin@2025)
INSERT INTO admin_users (name, email, password, role) VALUES
('System Admin', 'sammyopiyo001@gmail.com', '$2y$12$Omy54QxRynPwSrdtuo0EXeXYDx4z.0abTkxyEMtB36niGSav97i8K', 'super_admin');

-- Default settings
INSERT INTO settings (setting_key, setting_value, setting_group) VALUES
('app_name',           'BulkSTK Pro',           'general'),
('app_logo',           '',                       'general'),
('company_name',       'Your Company Name',      'general'),
('company_email',      'info@yourcompany.co.ke', 'general'),
('company_phone',      '+254700000000',          'general'),
('mpesa_env',          'sandbox',                'mpesa'),
('mpesa_consumer_key', '',                       'mpesa'),
('mpesa_consumer_secret', '',                    'mpesa'),
('mpesa_shortcode',    '174379',                 'mpesa'),
('mpesa_passkey',      '',                       'mpesa'),
('mpesa_callback_url', '',                       'mpesa'),
('batch_size',         '5',                      'processing'),
('max_retries',        '2',                      'processing'),
('stk_timeout',        '55',                     'processing');

-- Sample customer groups
INSERT INTO customer_groups (name, description, color) VALUES
('All Customers',    'Default group for all customers', '#0D2B55'),
('VIP Members',      'Premium tier customers',          '#00A651'),
('Staff',            'Internal staff members',          '#F59E0B'),
('Suppliers',        'Supplier and vendor accounts',    '#6366F1');
