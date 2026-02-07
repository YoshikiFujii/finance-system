-- Database Migration for Year Management
-- Please run these SQL commands in your database tool (e.g., phpMyAdmin)

-- 1. Create years table
CREATE TABLE IF NOT EXISTS `years` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Insert initial year (R7) if table is empty
INSERT IGNORE INTO `years` (`name`, `is_active`) 
SELECT 'R7', 1 FROM DUAL WHERE NOT EXISTS (SELECT * FROM `years`);

-- 3. Add year_id to receipts
-- (The following procedure checks if column exists before adding - safe for re-running)
SET @dbname = DATABASE();
SET @tablename = "receipts";
SET @columnname = "year_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE receipts ADD COLUMN year_id INT;"
));
PREPARE stmt1 FROM @preparedStatement;
EXECUTE stmt1;
DEALLOCATE PREPARE stmt1;

-- Add FK for receipts
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (constraint_name = 'fk_receipts_year')
  ) > 0,
  "SELECT 1",
  "ALTER TABLE receipts ADD CONSTRAINT fk_receipts_year FOREIGN KEY (year_id) REFERENCES years(id) ON DELETE SET NULL;"
));
PREPARE stmt2 FROM @preparedStatement;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;


-- 4. Add year_id to requests
SET @tablename = "requests";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE requests ADD COLUMN year_id INT;"
));
PREPARE stmt3 FROM @preparedStatement;
EXECUTE stmt3;
DEALLOCATE PREPARE stmt3;

-- Add FK for requests
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (constraint_name = 'fk_requests_year')
  ) > 0,
  "SELECT 1",
  "ALTER TABLE requests ADD CONSTRAINT fk_requests_year FOREIGN KEY (year_id) REFERENCES years(id) ON DELETE SET NULL;"
));
PREPARE stmt4 FROM @preparedStatement;
EXECUTE stmt4;
DEALLOCATE PREPARE stmt4;

-- 5. Update existing records to link to the active year (R7)
-- Get Active Year ID (Assuming it is R7 and ID is 1 or whatever)
UPDATE `receipts` r 
JOIN `years` y ON y.is_active = 1 
SET r.year_id = y.id 
WHERE r.year_id IS NULL;

UPDATE `requests` req 
JOIN `years` y ON y.is_active = 1 
SET req.year_id = y.id 
WHERE req.year_id IS NULL;


-- 6. Add year_id to income_records
SET @tablename = "income_records";
SET @columnname = "year_id";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  "ALTER TABLE income_records ADD COLUMN year_id INT;"
));
PREPARE stmt5 FROM @preparedStatement;
EXECUTE stmt5;
DEALLOCATE PREPARE stmt5;

-- Add FK for income_records
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (constraint_name = 'fk_income_year')
  ) > 0,
  "SELECT 1",
  "ALTER TABLE income_records ADD CONSTRAINT fk_income_year FOREIGN KEY (year_id) REFERENCES years(id) ON DELETE SET NULL;"
));
PREPARE stmt6 FROM @preparedStatement;
EXECUTE stmt6;
DEALLOCATE PREPARE stmt6;

-- Update existing income records to link to the active year (R7)
UPDATE `income_records` inc 
JOIN `years` y ON y.is_active = 1 
SET inc.year_id = y.id 
WHERE inc.year_id IS NULL;
