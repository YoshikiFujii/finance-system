-- Lotus Purchase Request Backup
-- Generated: 2025-10-03 05:34:14
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
  `state` enum('NEW','ACCEPTED','REJECTED','CASH_GIVEN','COLLECTED','RECEIPT_DONE','TRANSFERRED','COMPLETED') NOT NULL DEFAULT 'NEW',
  `expected_total` decimal(12,2) DEFAULT NULL,
  `cash_given` decimal(12,2) DEFAULT NULL,
  `diff_amount` decimal(12,2) DEFAULT NULL,
  `excel_path` varchar(255) NOT NULL,
  `notes` text,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `bank_name` varchar(100) DEFAULT NULL,
  `branch_name` varchar(100) DEFAULT NULL,
  `account_type` varchar(20) DEFAULT NULL,
  `account_number` varchar(20) DEFAULT NULL,
  `account_holder` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `request_no` (`request_no`),
  KEY `member_id` (`member_id`),
  KEY `department_id` (`department_id`),
  CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `requests_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=39 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `requests` (`id`, `request_no`, `member_id`, `department_id`, `submitted_at`, `summary`, `expects_network`, `state`, `expected_total`, `cash_given`, `diff_amount`, `excel_path`, `notes`, `rejected_reason`, `created_at`, `updated_at`, `bank_name`, `branch_name`, `account_type`, `account_number`, `account_holder`) VALUES
('36', '251003842', '102', '22', '2025-10-03 05:00:53', 'test', 'BANK_TRANSFER', 'RECEIPT_DONE', NULL, '3000.00', NULL, '/var/www/html/storage/uploads/2025/10/251003842/購入希望届テンプレートver.3_050053_883.xlsx', NULL, NULL, '2025-10-03 05:00:53', '2025-10-03 05:25:09', '三菱UFJ', '新宿支店', '普通', '123456', 'フジイヨシキ'),
('37', '251003824', '103', '22', '2025-10-03 05:13:15', 'テスト購入', 'BANK_TRANSFER', 'TRANSFERRED', NULL, '3000.00', NULL, '/var/www/html/storage/uploads/2025/10/251003824/購入希望届テンプレートver.3_051315_347.xlsx', NULL, NULL, '2025-10-03 05:13:15', '2025-10-03 05:13:37', '三菱UFJ', '新宿支店', '普通', '123456', 'フジイヨシキ'),
('38', '251003231', '94', '20', '2025-10-03 05:30:27', 'test', 'NONE', 'RECEIPT_DONE', NULL, '3000.00', NULL, '/var/www/html/storage/uploads/2025/10/251003231/購入希望届テンプレートver.3_053027_412.xlsx', NULL, NULL, '2025-10-03 05:30:27', '2025-10-03 05:31:21', NULL, NULL, NULL, NULL, NULL);

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
  `is_completed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '記入済みフラグ',
  PRIMARY KEY (`id`),
  KEY `request_id` (`request_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `receipts_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `receipts_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`),
  CONSTRAINT `receipts_ibfk_3` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `receipts` (`id`, `request_id`, `kind`, `total`, `change_returned`, `file_path`, `taken_at`, `memo`, `subject`, `event_id`, `purpose`, `payer`, `receipt_date`, `is_completed`) VALUES
('5', '36', 'RECEIPT', '2000.00', '0.00', '/var/www/html/storage/uploads/2025/10/251003842/4.png', '2025-10-03 05:06:12', NULL, 'あ', '6', '備品購入', '藤井 義己', '2025-10-03', '0'),
('6', '37', 'RECEIPT', '1500.00', '0.00', '/var/www/html/storage/uploads/2025/10/251003824/4.png', '2025-10-03 05:14:13', NULL, 'い', '6', '備品購入', '庄子 夢唯', '2025-10-03', '0'),
('7', '37', 'RECEIPT', '1500.00', '0.00', '/var/www/html/storage/uploads/2025/10/251003824/20240830_160915.jpg', '2025-10-03 05:23:41', NULL, 'い', '6', '備品購入', '庄子 夢唯', '2025-10-03', '0'),
('8', '38', 'RECEIPT', '3000.00', '0.00', '/var/www/html/storage/uploads/2025/10/251003231/4.png', '2025-10-03 05:31:04', NULL, 'い', '6', '備品購入', '向井 瑛志', '2025-10-03', '0');

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `departments` (`id`, `name`, `created_at`, `is_active`) VALUES
('20', '三役', '2025-10-03 04:20:58', '1'),
('21', '総務', '2025-10-03 04:20:58', '1'),
('22', '財務', '2025-10-03 04:20:58', '1'),
('23', '風紀', '2025-10-03 04:20:58', '1'),
('24', '厚生', '2025-10-03 04:20:58', '1'),
('25', '企画', '2025-10-03 04:20:58', '1'),
('26', '渉外広報', '2025-10-03 04:20:58', '1'),
('27', 'FM', '2025-10-03 04:20:58', '1');

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
) ENGINE=InnoDB AUTO_INCREMENT=161 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `members` (`id`, `name`, `department_id`, `created_at`, `is_active`) VALUES
('94', '向井 瑛志', '20', '2025-10-03 04:20:58', '1'),
('95', '岩本 利通', '20', '2025-10-03 04:20:58', '1'),
('96', '横山 楓人', '20', '2025-10-03 04:20:58', '1'),
('97', '青山 和瑚', '20', '2025-10-03 04:20:58', '1'),
('98', '小林 羚偉', '20', '2025-10-03 04:20:58', '1'),
('99', '渡辺 美怜', '20', '2025-10-03 04:20:58', '1'),
('100', '吉田 悠人', '21', '2025-10-03 04:20:58', '1'),
('101', '小泉 杏奈', '21', '2025-10-03 04:20:58', '1'),
('102', '藤井 義己', '22', '2025-10-03 04:20:58', '1'),
('103', '庄子 夢唯', '22', '2025-10-03 04:20:58', '1'),
('104', '姿 颯磨', '23', '2025-10-03 04:20:58', '1'),
('105', '西澤 凛', '23', '2025-10-03 04:20:58', '1'),
('106', '池田 陸人', '24', '2025-10-03 04:20:58', '1'),
('107', '小川 仁瑚', '24', '2025-10-03 04:20:58', '1'),
('108', '谷村 光紀', '25', '2025-10-03 04:20:58', '1'),
('109', '後藤 すみれ', '25', '2025-10-03 04:20:58', '1'),
('110', '千葉 太陽', '26', '2025-10-03 04:20:58', '1'),
('111', '藤田 はな', '26', '2025-10-03 04:20:58', '1'),
('112', '田中 佑哉', '21', '2025-10-03 04:20:58', '1'),
('113', '大内 煌太郎', '21', '2025-10-03 04:20:58', '1'),
('114', '大輪 駿斗', '21', '2025-10-03 04:20:58', '1'),
('115', '菊池 康生', '21', '2025-10-03 04:20:58', '1'),
('116', '下醉尾 朔也', '21', '2025-10-03 04:20:58', '1'),
('117', '荻原 悠輔', '21', '2025-10-03 04:20:58', '1'),
('118', '菊池 あかり', '21', '2025-10-03 04:20:58', '1'),
('119', '竹内 月渚', '21', '2025-10-03 04:20:58', '1'),
('120', '今村 尚輝', '22', '2025-10-03 04:20:58', '1'),
('121', '島 凱人', '22', '2025-10-03 04:20:58', '1'),
('122', '千本木 匠', '22', '2025-10-03 04:20:58', '1'),
('123', '平 あかり', '22', '2025-10-03 04:20:58', '1'),
('124', '牧野 真歩', '22', '2025-10-03 04:20:58', '1'),
('125', '池田 翼', '23', '2025-10-03 04:20:58', '1'),
('126', '澁谷 應介', '23', '2025-10-03 04:20:58', '1'),
('127', '室井 優希', '23', '2025-10-03 04:20:58', '1'),
('128', '小野寺 幸', '23', '2025-10-03 04:20:58', '1'),
('129', '福原 真昊', '23', '2025-10-03 04:20:58', '1'),
('130', '小竹 獅禅', '24', '2025-10-03 04:20:58', '1'),
('131', '櫻井 悠真', '24', '2025-10-03 04:20:58', '1'),
('132', '笹島 啓介', '24', '2025-10-03 04:20:58', '1'),
('133', '眞壁 斗棋', '24', '2025-10-03 04:20:58', '1'),
('134', '田名網 脩人', '24', '2025-10-03 04:20:58', '1'),
('135', '松本 貴沙', '24', '2025-10-03 04:20:58', '1'),
('136', '佐藤 心紅', '24', '2025-10-03 04:20:58', '1'),
('137', '網田 遼介', '25', '2025-10-03 04:20:58', '1'),
('138', '斉藤 大樹', '25', '2025-10-03 04:20:58', '1'),
('139', '本間幸弥', '25', '2025-10-03 04:20:58', '1'),
('140', '小野 竜弥', '25', '2025-10-03 04:20:58', '1'),
('141', '礒野 蓮', '25', '2025-10-03 04:20:58', '1'),
('142', '林 夏漣', '25', '2025-10-03 04:20:58', '1'),
('143', '白川 裕菜', '25', '2025-10-03 04:20:58', '1'),
('144', '比嘉 真愛', '25', '2025-10-03 04:20:58', '1'),
('145', '阿部 宏祐', '26', '2025-10-03 04:20:58', '1'),
('146', '羽山 俊希', '26', '2025-10-03 04:20:58', '1'),
('147', '髙橋 芽依', '26', '2025-10-03 04:20:58', '1'),
('148', '塩原 沙季', '26', '2025-10-03 04:20:58', '1'),
('149', '島崎 誠大', '27', '2025-10-03 04:20:58', '1'),
('150', '澤田 誠路', '27', '2025-10-03 04:20:58', '1'),
('151', '間中 宏尚', '27', '2025-10-03 04:20:58', '1'),
('152', '野村 柊斗', '27', '2025-10-03 04:20:58', '1'),
('153', '伊藤 一成', '27', '2025-10-03 04:20:58', '1'),
('154', '圖師 龍杜', '27', '2025-10-03 04:20:58', '1'),
('155', '米澤 慧師', '27', '2025-10-03 04:20:58', '1'),
('156', '伊藤 凜', '27', '2025-10-03 04:20:58', '1'),
('157', '清水 心愛', '27', '2025-10-03 04:20:58', '1'),
('158', '髙橋 虹光', '27', '2025-10-03 04:20:58', '1'),
('159', '瀬尾 真央', '27', '2025-10-03 04:20:58', '1'),
('160', '比屋根 佳歩', '27', '2025-10-03 04:20:58', '1');

DROP TABLE IF EXISTS `events`;
CREATE TABLE `events` (
  `id` bigint NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO `events` (`id`, `name`, `is_active`, `created_at`) VALUES
('6', '寮祭', '1', '2025-10-03 04:20:04');

SET FOREIGN_KEY_CHECKS = 1;
