-- Add user_type column to users table
-- This script can be copied and pasted into phpMyAdmin

USE victorianpass_db;

-- Add user_type column to distinguish between residents and visitors
ALTER TABLE users 
ADD COLUMN user_type ENUM('resident', 'visitor') DEFAULT 'resident' 
AFTER email;

-- Update existing users to be residents by default
UPDATE users SET user_type = 'resident' WHERE user_type IS NULL;

-- Optional: Add index for better query performance
CREATE INDEX idx_user_type ON users(user_type);