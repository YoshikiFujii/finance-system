-- Lotus Purchase Request Backup
-- Generated: 2025-10-03 04:16:37
-- Database: finance_db
-- Tables: requests, request_items, receipts, departments, members, events

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_no` varchar(20) DEFAULT NULL,
  `member_id` bigint NOT NULL,
  `department_id` bigint NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `summary` varchar(255) NOT NULL DEFAULT '',
  `expects_network` enum('NONE','CONVENIENCE','BANK_TRANSFER') NOT NULL DEFAULT 'NONE',
  `state` enum('NEW','ACCEPTED','REJECTED','CASH_GIVEN','COLLECTED','RECEIPT_DONE') NOT NULL DEFAULT 'NEW',
  `expected_total` decimal(12,2) DEFAULT NULL,
  `cash_given` decimal(12,2) DEFAULT NULL,
  `diff_amount` decimal(12,2) DEFAULT NULL,
  `excel_path` varchar(255) NOT NULL,
  `notes` text,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `member_id` (`member_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `requests_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `requests` (`id`, `request_no`, `member_id`, `department_id`, `submitted_at`, `summary`, `expects_network`, `state`, `expected_total`, `cash_given`, `diff_amount`, `excel_path`, `notes`, `rejected_reason`, `created_at`, `updated_at`) VALUES
('33', '251003469', '35', '14', '2025-10-03 03:51:37', 'test', 'NONE', 'NEW', NULL, NULL, NULL, '/var/www/html/storage/uploads/2025/10/251003469/購入希望届テンプレートver.3_035137_770.xlsx', NULL, NULL, '2025-10-03 03:51:37', '2025-10-03 03:51:37');

DROP TABLE IF EXISTS `request_items`;
CREATE TABLE `request_items` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_id` bigint NOT NULL,
  `purpose` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  CONSTRAINT `request_items_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table request_items is empty

DROP TABLE IF EXISTS `receipts`;
CREATE TABLE `receipts` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `request_id` bigint NOT NULL,
  `kind` enum('RECEIPT','INVOICE','TRANSFER_SLIP','PAYMENT_SLIP') NOT NULL,
  `total` decimal(12,2) NOT NULL,
  `change_returned` decimal(12,2) DEFAULT '0.00',
  `file_path` varchar(255) NOT NULL,
  `taken_at` datetime NOT NULL,
  `memo` varchar(255) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `event_id` bigint DEFAULT NULL,
  `purpose` enum('備品購入','運営用品購入','景品購入','広報物作成','朝・昼・夜食購入','その他') DEFAULT NULL,
  `payer` varchar(100) DEFAULT NULL,
  `receipt_date` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `receipts_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Table receipts is empty

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `departments` (`id`, `name`, `created_at`, `is_active`) VALUES
('14', '財務', '2025-10-02 16:26:54', '1');

DROP TABLE IF EXISTS `members`;
CREATE TABLE `members` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `department_id` bigint NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `members_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=94 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `members` (`id`, `name`, `department_id`, `created_at`, `is_active`) VALUES
('35', '藤井 義己', '14', '2025-10-02 16:26:54', '1');

DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `events` (`id`, `name`, `is_active`, `created_at`) VALUES
('2', '文化祭', '1', '2025-10-03 02:01:22'),
('3', '体育祭', '1', '2025-10-03 02:01:22');

SET FOREIGN_KEY_CHECKS = 1;
