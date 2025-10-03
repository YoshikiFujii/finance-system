-- Lotus Purchase Request Backup
-- Generated: 2025-10-03 04:06:30
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
('12', '三役', '2025-10-02 16:26:54', '1'),
('13', '総務', '2025-10-02 16:26:54', '1'),
('14', '財務', '2025-10-02 16:26:54', '1'),
('15', '風紀', '2025-10-02 16:26:54', '1'),
('16', '厚生', '2025-10-02 16:26:54', '1'),
('17', '企画', '2025-10-02 16:26:54', '1'),
('18', '渉外広報', '2025-10-02 16:26:54', '1'),
('19', 'FM', '2025-10-02 16:26:54', '1');

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
('27', '向井 瑛志', '12', '2025-10-02 16:26:54', '1'),
('28', '岩本 利通', '12', '2025-10-02 16:26:54', '1'),
('29', '横山 楓人', '12', '2025-10-02 16:26:54', '1'),
('30', '青山 和瑚', '12', '2025-10-02 16:26:54', '1'),
('31', '小林 羚偉', '12', '2025-10-02 16:26:54', '1'),
('32', '渡辺 美怜', '12', '2025-10-02 16:26:54', '1'),
('33', '吉田 悠人', '13', '2025-10-02 16:26:54', '1'),
('34', '小泉 杏奈', '13', '2025-10-02 16:26:54', '1'),
('35', '藤井 義己', '14', '2025-10-02 16:26:54', '1'),
('36', '庄子 夢唯', '14', '2025-10-02 16:26:54', '1'),
('37', '姿 颯磨', '15', '2025-10-02 16:26:54', '1'),
('38', '西澤 凛', '15', '2025-10-02 16:26:54', '1'),
('39', '池田 陸人', '16', '2025-10-02 16:26:54', '1'),
('40', '小川 仁瑚', '16', '2025-10-02 16:26:54', '1'),
('41', '谷村 光紀', '17', '2025-10-02 16:26:54', '1'),
('42', '後藤 すみれ', '17', '2025-10-02 16:26:54', '1'),
('43', '千葉 太陽', '18', '2025-10-02 16:26:54', '1'),
('44', '藤田 はな', '18', '2025-10-02 16:26:54', '1'),
('45', '田中 佑哉', '13', '2025-10-02 16:26:54', '1'),
('46', '大内 煌太郎', '13', '2025-10-02 16:26:54', '1'),
('47', '大輪 駿斗', '13', '2025-10-02 16:26:54', '1'),
('48', '菊池 康生', '13', '2025-10-02 16:26:54', '1'),
('49', '下醉尾 朔也', '13', '2025-10-02 16:26:54', '1'),
('50', '荻原 悠輔', '13', '2025-10-02 16:26:54', '1'),
('51', '菊池 あかり', '13', '2025-10-02 16:26:54', '1'),
('52', '竹内 月渚', '13', '2025-10-02 16:26:54', '1'),
('53', '今村 尚輝', '14', '2025-10-02 16:26:54', '1'),
('54', '島 凱人', '14', '2025-10-02 16:26:54', '1'),
('55', '千本木 匠', '14', '2025-10-02 16:26:54', '1'),
('56', '平 あかり', '14', '2025-10-02 16:26:54', '1'),
('57', '牧野 真歩', '14', '2025-10-02 16:26:54', '1'),
('58', '池田 翼', '15', '2025-10-02 16:26:54', '1'),
('59', '澁谷 應介', '15', '2025-10-02 16:26:54', '1'),
('60', '室井 優希', '15', '2025-10-02 16:26:54', '1'),
('61', '小野寺 幸', '15', '2025-10-02 16:26:54', '1'),
('62', '福原 真昊', '15', '2025-10-02 16:26:54', '1'),
('63', '小竹 獅禅', '16', '2025-10-02 16:26:54', '1'),
('64', '櫻井 悠真', '16', '2025-10-02 16:26:54', '1'),
('65', '笹島 啓介', '16', '2025-10-02 16:26:54', '1'),
('66', '眞壁 斗棋', '16', '2025-10-02 16:26:54', '1'),
('67', '田名網 脩人', '16', '2025-10-02 16:26:54', '1'),
('68', '松本 貴沙', '16', '2025-10-02 16:26:54', '1'),
('69', '佐藤 心紅', '16', '2025-10-02 16:26:54', '1'),
('70', '網田 遼介', '17', '2025-10-02 16:26:54', '1'),
('71', '斉藤 大樹', '17', '2025-10-02 16:26:54', '1'),
('72', '本間幸弥', '17', '2025-10-02 16:26:54', '1'),
('73', '小野 竜弥', '17', '2025-10-02 16:26:54', '1'),
('74', '礒野 蓮', '17', '2025-10-02 16:26:54', '1'),
('75', '林 夏漣', '17', '2025-10-02 16:26:54', '1'),
('76', '白川 裕菜', '17', '2025-10-02 16:26:54', '1'),
('77', '比嘉 真愛', '17', '2025-10-02 16:26:54', '1'),
('78', '阿部 宏祐', '18', '2025-10-02 16:26:54', '1'),
('79', '羽山 俊希', '18', '2025-10-02 16:26:54', '1'),
('80', '髙橋 芽依', '18', '2025-10-02 16:26:54', '1'),
('81', '塩原 沙季', '18', '2025-10-02 16:26:54', '1'),
('82', '島崎 誠大', '19', '2025-10-02 16:26:54', '1'),
('83', '澤田 誠路', '19', '2025-10-02 16:26:54', '1'),
('84', '間中 宏尚', '19', '2025-10-02 16:26:54', '1'),
('85', '野村 柊斗', '19', '2025-10-02 16:26:54', '1'),
('86', '伊藤 一成', '19', '2025-10-02 16:26:54', '1'),
('87', '圖師 龍杜', '19', '2025-10-02 16:26:54', '1'),
('88', '米澤 慧師', '19', '2025-10-02 16:26:54', '1'),
('89', '伊藤 凜', '19', '2025-10-02 16:26:54', '1'),
('90', '清水 心愛', '19', '2025-10-02 16:26:54', '1'),
('91', '髙橋 虹光', '19', '2025-10-02 16:26:54', '1'),
('92', '瀬尾 真央', '19', '2025-10-02 16:26:54', '1'),
('93', '比屋根 佳歩', '19', '2025-10-02 16:26:54', '1');

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
