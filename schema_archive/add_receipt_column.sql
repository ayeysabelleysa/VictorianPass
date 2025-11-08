-- Add receipt_path column to reservations table for storing uploaded payment receipts
ALTER TABLE `reservations` 
ADD COLUMN `receipt_path` VARCHAR(255) NULL COMMENT 'Path to uploaded payment receipt' 
AFTER `approval_date`;