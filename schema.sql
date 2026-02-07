-- Database Schema for Finance System

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Departments Master
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Members Master
CREATE TABLE IF NOT EXISTS `members` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `department_id` INT NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Events Master
CREATE TABLE IF NOT EXISTS `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Accounting Periods Master
CREATE TABLE IF NOT EXISTS `accounting_periods` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4.5 Years Master
CREATE TABLE IF NOT EXISTS `years` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- 5. Passwords (for Role-based Auth)
CREATE TABLE IF NOT EXISTS `passwords` (
    `role` VARCHAR(50) PRIMARY KEY,
    `pass_hash` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initial password setup (example only - should be changed)
-- INSERT IGNORE INTO `passwords` (`role`, `pass_hash`) VALUES ('ADMIN', '$2y$10$...'); 

-- 6. Requests (Main Transaction Table)
CREATE TABLE IF NOT EXISTS `requests` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_no` VARCHAR(20) NOT NULL UNIQUE,
    `member_id` INT NOT NULL,
    `department_id` INT NOT NULL,
    `submitted_at` DATETIME NOT NULL,
    `summary` TEXT NOT NULL,
    `expects_network` ENUM('NONE', 'BANK_TRANSFER') DEFAULT 'NONE',
    `excel_path` VARCHAR(255),
    `bank_name` VARCHAR(255),
    `branch_name` VARCHAR(255),
    `account_type` VARCHAR(50),
    `account_number` VARCHAR(50),
    `account_holder` VARCHAR(255),
    `state` VARCHAR(50) DEFAULT 'NEW', -- NEW, ACCEPTED, CASH_GIVEN, RECEIPT_DONE, FINALIZED, REJECTED
    `cash_given` DECIMAL(10, 2) DEFAULT 0,
    `expected_total` DECIMAL(10, 2) DEFAULT 0,
    `processed_amount` DECIMAL(10, 2) DEFAULT 0,
    `rejected_reason` TEXT,
    `year_id` INT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`year_id`) REFERENCES `years`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Receipts
CREATE TABLE IF NOT EXISTS `receipts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `receipt_no` VARCHAR(50),
    `subject` VARCHAR(255),
    `purpose` TEXT,
    `total` DECIMAL(10, 2) NOT NULL,
    `payer` VARCHAR(255),
    `receipt_date` DATE,
    `is_completed` TINYINT(1) DEFAULT 0,
    `storaged` TINYINT(1) DEFAULT 0,
    `event_id` INT,
    `accounting_period_id` INT,
    `year_id` INT,
    `deleted_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`request_id`) REFERENCES `requests`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`year_id`) REFERENCES `years`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Audit Logs
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT,
    `actor` VARCHAR(50),
    `action` VARCHAR(50),
    `detail` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Funds (Current Balance Snapshot)
CREATE TABLE IF NOT EXISTS `funds` (
    `id` INT PRIMARY KEY,
    `bank_balance` DECIMAL(15, 2) DEFAULT 0,
    `cash_on_hand` DECIMAL(15, 2) DEFAULT 0,
    `actual_cash_on_hand` DECIMAL(15, 2) DEFAULT 0,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize funds if empty
INSERT IGNORE INTO `funds` (`id`, `bank_balance`, `cash_on_hand`) VALUES (1, 0, 0);

-- 10. Finance Logs (Transaction History for Finance)
CREATE TABLE IF NOT EXISTS `finance_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('bank_balance', 'cash_amount') NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Fund Logs (More detailed logs including description)
CREATE TABLE IF NOT EXISTS `fund_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `type` VARCHAR(50) NOT NULL,
    `action` VARCHAR(50) NOT NULL,
    `amount` DECIMAL(15, 2) NOT NULL,
    `description` TEXT,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Income Records
CREATE TABLE IF NOT EXISTS `income_records` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `amount` DECIMAL(10, 2) NOT NULL,
    `description` TEXT NOT NULL,
    `year_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`year_id`) REFERENCES `years`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Request Items (Optional/Unused but referenced in backups)
CREATE TABLE IF NOT EXISTS `request_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT NOT NULL,
    `item_name` VARCHAR(255),
    `amount` DECIMAL(10, 2),
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`request_id`) REFERENCES `requests`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
