-- VictorianPass Consolidated Database Schema
-- Copy/Paste this into phpMyAdmin (SQL tab) to create everything in one go

CREATE DATABASE IF NOT EXISTS victorianpass_db;
USE victorianpass_db;

-- =====================================================
-- STAFF TABLE (Admin and Guard accounts)
-- =====================================================
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','guard') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Pre-registered admin and guard accounts (change passwords after install)
INSERT IGNORE INTO staff (email, password, role) VALUES
('admin@victorianpass.com', 'admin12345', 'admin'),
('guard@victorianpass.com', 'guard12345', 'guard');

-- =====================================================
-- HOUSES TABLE (Pre-registered house numbers)
-- =====================================================
CREATE TABLE IF NOT EXISTS houses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  house_number VARCHAR(50) NOT NULL UNIQUE,
  address VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Sample house data (modify according to your subdivision)
INSERT IGNORE INTO houses (house_number, address) VALUES
('VH-1001', 'Blk 1 Lot 5, Victorian Heights Subdivision'),
('VH-1002', 'Blk 1 Lot 6, Victorian Heights Subdivision'),
('VH-1003', 'Blk 2 Lot 10, Victorian Heights Subdivision'),
('VH-1023', 'Blk 4 Lot 12, Victorian Heights Subdivision'),
('VH-1100', 'Blk 10 Lot 3, Victorian Heights Subdivision'),
('VH-2001', 'Blk 5 Lot 8, Victorian Heights Subdivision'),
('VH-2002', 'Blk 5 Lot 9, Victorian Heights Subdivision'),
('VH-3001', 'Blk 7 Lot 15, Victorian Heights Subdivision');

-- Additional sample houses
INSERT IGNORE INTO houses (house_number, address) VALUES
('VH-3002', 'Blk 7 Lot 16, Victorian Heights Subdivision'),
('VH-3003', 'Blk 7 Lot 17, Victorian Heights Subdivision'),
('VH-3004', 'Blk 7 Lot 18, Victorian Heights Subdivision'),
('VH-3005', 'Blk 7 Lot 19, Victorian Heights Subdivision'),
('VH-3006', 'Blk 7 Lot 20, Victorian Heights Subdivision'),
('VH-3007', 'Blk 8 Lot 1, Victorian Heights Subdivision'),
('VH-3008', 'Blk 8 Lot 2, Victorian Heights Subdivision'),
('VH-4001', 'Blk 9 Lot 4, Victorian Heights Subdivision'),
('VH-4002', 'Blk 9 Lot 5, Victorian Heights Subdivision'),
('VH-5001', 'Blk 11 Lot 2, Victorian Heights Subdivision');

-- =====================================================
-- USERS TABLE (Registered residents)
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(100) NOT NULL,
  middle_name VARCHAR(100),
  last_name VARCHAR(100) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  user_type ENUM('resident') DEFAULT 'resident',
  password VARCHAR(255) NOT NULL,
  sex ENUM('Male', 'Female') NOT NULL,
  birthdate DATE NOT NULL,
  house_number VARCHAR(50) NOT NULL,
  address VARCHAR(255) NOT NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_type (user_type),
  UNIQUE KEY uniq_house_number (house_number),
  INDEX idx_email (email)
) ENGINE=InnoDB;

-- =====================================================
-- ENTRY PASSES TABLE (Visitor personal details)
-- =====================================================
CREATE TABLE IF NOT EXISTS entry_passes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(150) NOT NULL,
  middle_name VARCHAR(100) NULL,
  last_name VARCHAR(100) NOT NULL,
  sex VARCHAR(10) NULL,
  birthdate DATE NULL,
  contact VARCHAR(50) NULL,
  email VARCHAR(120) NOT NULL,
  address VARCHAR(255) NOT NULL,
  valid_id_path VARCHAR(255) NULL COMMENT 'Path to uploaded ID document',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created_at (created_at),
  INDEX idx_full_name (full_name)
) ENGINE=InnoDB;

-- =====================================================
-- RESERVATIONS TABLE (Amenity bookings and gate/visitor requests)
-- =====================================================
CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_code VARCHAR(50) NOT NULL UNIQUE,
  amenity VARCHAR(100) NULL COMMENT 'Nullable for visitor placeholder before amenity selection',
  start_date DATE NULL COMMENT 'Nullable until visitor selects dates',
  end_date DATE NULL COMMENT 'Nullable until visitor selects dates',
  persons INT NULL COMMENT 'Nullable until visitor sets party size',
  price DECIMAL(10,2) NULL COMMENT 'Nullable until pricing is computed',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

  -- Linking fields
  entry_pass_id INT NULL COMMENT 'Links to entry_passes table for visitor details',
  user_id INT NULL COMMENT 'Links to users table for registered residents',
  purpose VARCHAR(255) NULL COMMENT 'Purpose of visit or reservation',

  -- Request/approval status
  approval_status ENUM('pending', 'approved', 'denied') DEFAULT 'pending' COMMENT 'Admin approval status',

  -- Reservation lifecycle status (aligned with admin actions)
  status ENUM('pending','approved','rejected','expired') DEFAULT 'pending' COMMENT 'Reservation lifecycle status',

  -- Staff actions
  approved_by INT NULL COMMENT 'Staff ID who approved/denied the request',
  approval_date TIMESTAMP NULL COMMENT 'When the request was approved/denied',

  -- Payment and receipt fields
  receipt_path VARCHAR(255) NULL COMMENT 'Path to uploaded payment receipt',
  qr_path VARCHAR(255) NULL,
  payment_status ENUM('pending', 'verified', 'rejected') DEFAULT 'pending' COMMENT 'Payment verification status',
  verified_by INT NULL COMMENT 'Staff ID who verified payment',
  verification_date DATETIME NULL COMMENT 'When payment was verified',

  -- Indexes for performance
  INDEX idx_ref_code (ref_code),
  INDEX idx_entry_pass_id (entry_pass_id),
  INDEX idx_user_id (user_id),
  INDEX idx_approval_status (approval_status),
  INDEX idx_payment_status (payment_status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

-- =====================================================
-- RESIDENT RESERVATIONS TABLE (Resident amenity bookings)
-- =====================================================
CREATE TABLE IF NOT EXISTS resident_reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  amenity VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  notes TEXT,
  approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
  approved_by INT NULL,
  approval_date DATETIME NULL,
  ref_code VARCHAR(20) NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  qr_path VARCHAR(255) NULL,
  CONSTRAINT fk_rr_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- =====================================================
-- GUEST FORMS TABLE (Visitor gate-entry requests linked to a resident)
-- =====================================================
CREATE TABLE IF NOT EXISTS guest_forms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  resident_user_id INT NULL,
  resident_house VARCHAR(100) NULL,
  resident_email VARCHAR(150) NULL,
  visitor_first_name VARCHAR(100) NOT NULL,
  visitor_middle_name VARCHAR(100) NULL,
  visitor_last_name VARCHAR(100) NOT NULL,
  visitor_sex VARCHAR(20) NULL,
  visitor_birthdate DATE NULL,
  visitor_contact VARCHAR(50) NULL,
  visitor_email VARCHAR(150) NULL,
  valid_id_path VARCHAR(255) NULL,
  visit_date DATE NULL,
  visit_time VARCHAR(20) NULL,
  purpose VARCHAR(255) NULL,
  wants_amenity TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=Amenity reservation chosen from Guest Form',
  amenity VARCHAR(100) NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  persons INT NULL,
  price DECIMAL(10,2) NULL,
  ref_code VARCHAR(50) NOT NULL UNIQUE,
  approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
  approved_by INT NULL,
  approval_date DATETIME NULL,
  qr_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  INDEX idx_resident_user_id (resident_user_id),
  INDEX idx_ref_code (ref_code)
) ENGINE=InnoDB;

-- =====================================================
-- INCIDENT TABLES (Resident incident reports and uploaded proofs)
-- =====================================================
CREATE TABLE IF NOT EXISTS incident_reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  complainant VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  nature VARCHAR(255) NULL,
  other_concern VARCHAR(255) NULL,
  user_id INT NULL,
  status ENUM('new','in_progress','resolved','rejected') DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  INDEX idx_status (status),
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incident_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_report_id (report_id)
) ENGINE=InnoDB;

-- =====================================================
-- FOREIGN KEY CONSTRAINTS
-- =====================================================

-- Link reservations to entry passes (for visitors)
ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_entry_pass 
  FOREIGN KEY (entry_pass_id) REFERENCES entry_passes(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link reservations to users (for registered residents)
ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_user 
  FOREIGN KEY (user_id) REFERENCES users(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link reservations to staff (for approval tracking)
ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_staff_approval 
  FOREIGN KEY (approved_by) REFERENCES staff(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link reservations to staff (for payment verification)
ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_staff_verification 
  FOREIGN KEY (verified_by) REFERENCES staff(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link incident reports to users
ALTER TABLE incident_reports
  ADD CONSTRAINT fk_incident_reports_user
  FOREIGN KEY (user_id) REFERENCES users(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link incident proofs to incident reports
ALTER TABLE incident_proofs
  ADD CONSTRAINT fk_incident_proofs_report
  FOREIGN KEY (report_id) REFERENCES incident_reports(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

-- Link guest forms to users (resident who requested the entry)
ALTER TABLE guest_forms
  ADD CONSTRAINT fk_guest_forms_user
  FOREIGN KEY (resident_user_id) REFERENCES users(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- Link guest forms approvals to staff
ALTER TABLE guest_forms
  ADD CONSTRAINT fk_guest_forms_staff_approval
  FOREIGN KEY (approved_by) REFERENCES staff(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

-- =====================================================
-- SAMPLE DATA (Optional - for testing)
-- Remove if you prefer a clean database
-- =====================================================

-- Sample resident users (password values shown are placeholders)
INSERT IGNORE INTO users (first_name, middle_name, last_name, phone, email, user_type, password, sex, birthdate, house_number, address) VALUES
('John', 'Michael', 'Doe', '09123456789', 'john.doe@email.com', 'resident', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Male', '1990-05-15', 'VH-1001', 'Blk 1 Lot 5, Victorian Heights Subdivision'),
('Maria', 'Santos', 'Cruz', '09987654321', 'maria.cruz@email.com', 'resident', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Female', '1985-08-22', 'VH-1002', 'Blk 1 Lot 6, Victorian Heights Subdivision');

-- Sample entry passes for visitors
INSERT IGNORE INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address) VALUES
('Jane', 'Smith', 'Johnson', 'Female', '1992-03-10', '09111222333', 'jane.johnson@email.com', '123 Main Street, Quezon City'),
('Robert', 'Lee', 'Wilson', 'Male', '1988-12-05', '09444555666', 'robert.wilson@email.com', '456 Oak Avenue, Makati City');

-- Sample resident amenity reservation
INSERT IGNORE INTO resident_reservations (user_id, amenity, start_date, end_date, notes, approval_status, ref_code)
VALUES (1, 'Basketball Court', '2025-11-15', '2025-11-15', 'Evening practice', 'pending', 'RR-TEST001');

-- =====================================================
-- COMPLETION NOTE
-- =====================================================
-- All tables, indexes, foreign keys, and sample data are created.
-- If any tables already exist, this script will skip duplicates safely.
-- You can re-run safely thanks to IF NOT EXISTS and INSERT IGNORE.
