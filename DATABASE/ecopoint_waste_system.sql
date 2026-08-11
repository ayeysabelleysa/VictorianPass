-- ============================================================
-- VictorianPass EcoPoint QR Waste Session System Migration
-- ============================================================
-- Run this ONCE in phpMyAdmin (victorianpass_db database)
-- Safe for re-runs: uses CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS
-- ============================================================

-- ------------------------------------------------------------
-- 1. Ensure point_transactions has the EcoPoint material/weight columns
--    (profileresident.php already queries these!)
-- ------------------------------------------------------------
SET @dbname = DATABASE();
SET @tablename = 'point_transactions';

-- Add material_type
SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = 'material_type'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE point_transactions ADD COLUMN material_type VARCHAR(50) DEFAULT NULL COMMENT ''Plastic, Aluminum, Paper, Cardboard, etc.'' AFTER reservation_ref_code',
  'SELECT ''material_type already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add weight_kg
SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = 'weight_kg'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE point_transactions ADD COLUMN weight_kg DECIMAL(6,2) DEFAULT NULL COMMENT ''Kilograms of recyclable material'' AFTER material_type',
  'SELECT ''weight_kg already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add station_id + session_id (link back to EcoPoint sessions for traceability)
SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = 'station_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE point_transactions ADD COLUMN station_id INT DEFAULT NULL COMMENT ''FK to ecopoint_stations'' AFTER weight_kg',
  'SELECT ''station_id already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = 'ecopoint_session_id'
);
SET @sql = IF(@column_exists = 0,
  'ALTER TABLE point_transactions ADD COLUMN ecopoint_session_id INT DEFAULT NULL COMMENT ''FK to ecopoint_waste_sessions'' AFTER station_id',
  'SELECT ''ecopoint_session_id already exists'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Ensure users (residents) table has consistent ref_code / points
-- ------------------------------------------------------------
SET @tablename = 'users';

-- ref_code (resident unique QR token) should exist from qr_view.php usage; make it UNIQUE just in case
SET @column_exists = (
  SELECT COUNT(*)
  FROM information_schema.columns
  WHERE table_schema = @dbname
    AND table_name = @tablename
    AND column_name = 'ref_code'
);
SET @sql = IF(@column_exists = 1,
  'ALTER IGNORE TABLE users ADD UNIQUE INDEX IF NOT EXISTS idx_users_ref_code (ref_code)',
  'SELECT ''ref_code column does not exist on users table—ensure it is added first'''
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. EcoPoint Hardware Stations (Authentication / Registry)
--    Stores station identity + hashed API key for hardware auth
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecopoint_stations (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  station_code        VARCHAR(64)  NOT NULL UNIQUE COMMENT 'Human-readable station ID e.g. VH-ECO-001',
  station_name        VARCHAR(120) NOT NULL COMMENT 'e.g. Clubhouse Main Station',
  location            VARCHAR(200) DEFAULT NULL,
  api_key_hash        VARCHAR(255) NOT NULL COMMENT 'Hashed (password_hash) API key NEVER STORE PLAIN',
  api_key_last4       VARCHAR(4)   DEFAULT NULL COMMENT 'Last 4 chars for helpdesk only',
  status              ENUM('ACTIVE','INACTIVE','MAINTENANCE','REVOKED') NOT NULL DEFAULT 'ACTIVE',
  last_heartbeat_at   TIMESTAMP NULL DEFAULT NULL,
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_stations_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default seed station (replace API key with a secure random string in production!)
-- The plaintext key below is FOR BOOTSTRAP ONLY — change it!
INSERT IGNORE INTO ecopoint_stations (station_code, station_name, location, api_key_hash, api_key_last4, status)
VALUES (
  'VH-ECO-001',
  'Clubhouse Main VHEcoPoint Station',
  'Victorian Heights Clubhouse Entrance',
  '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm', -- plaintext: secret123456
  '2345',
  'ACTIVE'
);

-- ------------------------------------------------------------
-- 4. EcoPoint Waste Sessions — the source of truth for a recycling session
--    States: ACTIVE | PROCESSING | COMPLETED | CANCELLED | ERROR
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecopoint_waste_sessions (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_token       CHAR(64)     NOT NULL UNIQUE COMMENT 'Server-generated opaque token shared with hardware for this session',
  station_id          INT          NOT NULL COMMENT 'FK to ecopoint_stations',
  user_id             INT          NOT NULL COMMENT 'FK to users (resident)',
  qr_ref_code         VARCHAR(120) NOT NULL COMMENT 'Snapshot of the QR ref_code scanned (for audit)',

  status              ENUM('ACTIVE','PROCESSING','COMPLETED','CANCELLED','ERROR') NOT NULL DEFAULT 'ACTIVE',
  error_message       VARCHAR(500) DEFAULT NULL,

  material_type       VARCHAR(50)  DEFAULT NULL COMMENT 'e.g. Plastic, Aluminum, Paper, Cardboard',
  weight_kg           DECIMAL(6,2) DEFAULT 0.00 COMMENT 'Final measured weight in KG',
  raw_hardware_data   JSON         DEFAULT NULL COMMENT 'Original payload from hardware (audit trail)',

  points_calculated   INT          DEFAULT 0 COMMENT 'Backend-calculated points before cap checks',
  points_awarded      INT          DEFAULT 0 COMMENT 'Actual points awarded after daily/weekly/balance caps',
  applied_daily_cap   INT          DEFAULT 100,
  applied_weekly_cap  INT          DEFAULT 250,
  applied_max_balance INT          DEFAULT 3000,

  points_posted       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = point_transactions row + users.points already updated (prevents double awards!)',
  posted_transaction_id INT        DEFAULT NULL COMMENT 'FK to point_transactions.id once awarded',

  completed_at        TIMESTAMP NULL DEFAULT NULL,
  cancelled_at        TIMESTAMP NULL DEFAULT NULL,
  error_at            TIMESTAMP NULL DEFAULT NULL,

  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (station_id) REFERENCES ecopoint_stations(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id)    REFERENCES users(id)             ON DELETE CASCADE,

  -- Prevent duplicate active session per resident!
  UNIQUE KEY uniq_active_user (user_id, status) COMMENT 'Only one ACTIVE/PROCESSING session at a time per resident',
  -- Prevent duplicate session per station+resident within 60 seconds (extra guard)
  INDEX idx_station_time (station_id, created_at),
  INDEX idx_user_status (user_id, status),
  INDEX idx_session_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Note: MySQL unique indexes treat multiple NULL rows as non-duplicate — so only one
-- (user_id, 'WAITING'), one (user_id, 'ACTIVE'), and one (user_id, 'PROCESSING') can exist per user.
-- Additional rows for COMPLETED/CANCELLED/ERROR are allowed because their status is different.

-- ------------------------------------------------------------
-- 4.1 Append missing columns (waiting, totals, started_at) to sessions table
--     + expand status ENUM to include WAITING (idempotent via information_schema check)
-- ------------------------------------------------------------
SET @tablename = 'ecopoint_waste_sessions';

-- 4.1.1 Expand ENUM to include WAITING if missing
SET @enum_def = (
  SELECT COLUMN_TYPE FROM information_schema.columns
  WHERE table_schema = @dbname AND table_name = @tablename AND column_name = 'status'
);
SET @need_enum = (@enum_def IS NULL OR @enum_def NOT LIKE '%WAITING%');
SET @sql = IF(@need_enum = 1,
  "ALTER TABLE ecopoint_waste_sessions MODIFY COLUMN status ENUM('WAITING','ACTIVE','PROCESSING','COMPLETED','CANCELLED','ERROR') NOT NULL DEFAULT 'ACTIVE'",
  "SELECT 'status ENUM already contains WAITING'"
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4.1.2 Add missing timestamp/audit columns
SET @cols = JSON_ARRAY('waiting_at','started_at','total_weight_kg','total_points');
SET @i = 0;
WHILE @i < JSON_LENGTH(@cols) DO
  SET @col = JSON_UNQUOTE(JSON_EXTRACT(@cols, CONCAT('$[', @i, ']')));
  SET @column_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=@dbname AND table_name=@tablename AND column_name=@col);
  SET @col_type = CASE @col
    WHEN 'waiting_at'       THEN 'TIMESTAMP NULL DEFAULT NULL'
    WHEN 'started_at'       THEN 'TIMESTAMP NULL DEFAULT NULL'
    WHEN 'total_weight_kg'  THEN 'DECIMAL(6,2) DEFAULT 0.00 COMMENT ''Total deposit weight across all items for this session'''
    WHEN 'total_points'     THEN 'INT DEFAULT 0 COMMENT ''Total final points awarded for this session (already capped)'''
  END;
  SET @sql = IF(@column_exists = 0,
    CONCAT('ALTER TABLE ecopoint_waste_sessions ADD COLUMN ', @col, ' ', @col_type),
    CONCAT('SELECT ''', @col, ' column already exists''')
  );
  PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
  SET @i = @i + 1;
END WHILE;

-- ------------------------------------------------------------
-- 5. EcoPoint Session Events — immutable audit log
--    Tracks every state change + hardware update for traceability
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecopoint_session_events (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_id          INT          NOT NULL,
  station_id          INT          NOT NULL,
  event_type          VARCHAR(40)  NOT NULL COMMENT 'QR_VERIFIED | SESSION_CREATED | WASTE_DATA | STATE_CHANGE | COMPLETED | CANCELLED | ERROR | POINTS_POSTED',
  event_payload       JSON         DEFAULT NULL COMMENT 'Event-specific data (weight, material, points, etc.)',
  source              VARCHAR(30)  NOT NULL DEFAULT 'HARDWARE' COMMENT 'HARDWARE | BACKEND | ADMIN',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  FOREIGN KEY (session_id) REFERENCES ecopoint_waste_sessions(id) ON DELETE CASCADE,
  FOREIGN KEY (station_id) REFERENCES ecopoint_stations(id)      ON DELETE CASCADE,

  INDEX idx_session_time (session_id, created_at),
  INDEX idx_event_type (event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 6. EcoPoint Waste Items (WasteTransactionItem per user spec)
--    Category-level breakdown for each session — plastic_pet,
--    aluminum_can, paper_cardboard — each with its own weight
--    and calculated/capped points per item.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ecopoint_waste_items (
  id                  INT AUTO_INCREMENT PRIMARY KEY,
  session_id          INT          NOT NULL COMMENT 'FK to ecopoint_waste_sessions',
  waste_type          ENUM('plastic_pet','aluminum_can','paper_cardboard','other') NOT NULL DEFAULT 'other',
  material_label      VARCHAR(50)  DEFAULT NULL COMMENT 'Human-readable label e.g. Plastic / Aluminum / Paper / Cardboard',
  weight_kg           DECIMAL(6,2) DEFAULT 0.00 NOT NULL,
  rate_pts_per_kg     INT          DEFAULT 0 NOT NULL,
  points_calculated   INT          DEFAULT 0 NOT NULL COMMENT 'Raw points for this item only (weight * rate)',
  points_awarded      INT          DEFAULT 0 NOT NULL COMMENT 'Points after per-item cap share (rarely differs but keeps audit)',
  raw_sensor_data     JSON         DEFAULT NULL COMMENT 'Optional raw hardware sensor payload for this weighing',
  created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  FOREIGN KEY (session_id) REFERENCES ecopoint_waste_sessions(id) ON DELETE CASCADE,
  INDEX idx_session_waste (session_id),
  INDEX idx_waste_type (waste_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 7. Fix the default seeded station to use the documented
--    plaintext key (so curl tests from README actually work)
--    Plaintext: vheco-station-VH-ECO-001-default-api-key-secret-change-me
-- ------------------------------------------------------------
UPDATE ecopoint_stations
SET    api_key_hash  = '$2y$10$QNqG9i5v13t8r53t274m1.Yq5cH6F/9YqD7C/VH7zG6C1Q1i6Jq9u',
       api_key_last4 = 'e_me',
       station_name  = 'Clubhouse Main VHEcoPoint Station',
       location      = 'Victorian Heights Clubhouse Entrance (120L bins, LAN-connected)'
WHERE  station_code  = 'VH-ECO-001'
  AND  status        = 'ACTIVE';
