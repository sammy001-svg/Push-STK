-- BulkSTK Pro — Admin Seed
-- Run this in phpMyAdmin if you need to reset the admin account.
-- Password: Admin@2025

INSERT INTO admin_users (name, email, password, role, status)
VALUES ('System Admin', 'sammyopiyo001@gmail.com',
        '$2y$12$Omy54QxRynPwSrdtuo0EXeXYDx4z.0abTkxyEMtB36niGSav97i8K',
        'super_admin', 1)
ON DUPLICATE KEY UPDATE
    name     = VALUES(name),
    password = VALUES(password),
    role     = VALUES(role),
    status   = 1;
