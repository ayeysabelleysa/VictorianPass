-- EcoPoint System Database Migration
-- Run this in phpMyAdmin or MySQL CLI

-- 1. Add ecopoint_balance to residents table (if not exists)
ALTER TABLE residents 
ADD COLUMN IF NOT EXISTS ecopoint_balance INT DEFAULT 0 COMMENT 'Current EcoPoint balance (max 3000)';

ALTER TABLE residents 
ADD COLUMN IF NOT EXISTS qr_code VARCHAR(255) UNIQUE COMMENT 'Unique QR code identifier for resident';

-- 2. Create ecopoint_sessions table to track recycling sessions
CREATE TABLE IF NOT EXISTS ecopoint_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,
    material VARCHAR(50) NOT NULL COMMENT 'Plastic, Aluminum, Paper & Cardboard',
    weight_kg DECIMAL(5,2) NOT NULL COMMENT 'Weight in kilograms',
    points_earned INT NOT NULL COMMENT 'Points earned this session',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional table to log session lifecycle events for real-time UI
CREATE TABLE IF NOT EXISTS ecopoint_session_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    resident_id INT NOT NULL,
    event_type VARCHAR(20) NOT NULL COMMENT 'start|stop|weight_update',
    meta JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (resident_id) REFERENCES residents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
