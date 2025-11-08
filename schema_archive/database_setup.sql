-- VictorianPass Database Complete Setup
-- Run this in phpMyAdmin or MySQL command line
-- This script creates all necessary tables, relationships, and sample data

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
);

-- Insert pre-registered admin and guard accounts
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
);

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
  user_type ENUM('resident', 'visitor') DEFAULT 'resident',
  password VARCHAR(255) NOT NULL,
  sex ENUM('Male', 'Female') NOT NULL,
  birthdate DATE NOT NULL,
  house_number VARCHAR(50) NOT NULL,
  address VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_user_type (user_type),
  INDEX idx_house_number (house_number),
  INDEX idx_email (email)
);

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
);

-- =====================================================
-- RESERVATIONS TABLE (Amenity bookings and entry passes)
-- =====================================================
CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_code VARCHAR(50) NOT NULL UNIQUE,
  amenity VARCHAR(100) NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  persons INT NOT NULL,
  price DECIMAL(10,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  
  -- Linking fields
  entry_pass_id INT NULL COMMENT 'Links to entry_passes table for visitor details',
  user_id INT NULL COMMENT 'Links to users table for registered residents',
  
  -- Status fields
  status ENUM('active', 'expired', 'cancelled') DEFAULT 'active' COMMENT 'Reservation status',
  approval_status ENUM('pending', 'approved', 'denied') DEFAULT 'pending' COMMENT 'Admin approval status',
  approved_by INT NULL COMMENT 'Staff ID who approved/denied the request',
  approval_date TIMESTAMP NULL COMMENT 'When the request was approved/denied',
  
  -- Payment and receipt fields
  receipt_path VARCHAR(255) NULL COMMENT 'Path to uploaded payment receipt',
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
);

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

-- =====================================================
-- SAMPLE DATA (Optional - for testing)
-- =====================================================

-- Sample resident user (password: 'password123')
INSERT IGNORE INTO users (first_name, middle_name, last_name, phone, email, user_type, password, sex, birthdate, house_number, address) VALUES
('John', 'Michael', 'Doe', '+639123456789', 'john.doe@email.com', 'resident', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Male', '1990-05-15', 'VH-1001', 'Blk 1 Lot 5, Victorian Heights Subdivision'),
('Maria', 'Santos', 'Cruz', '+639987654321', 'maria.cruz@email.com', 'resident', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Female', '1985-08-22', 'VH-1002', 'Blk 1 Lot 6, Victorian Heights Subdivision');

-- Sample entry pass for visitor
INSERT IGNORE INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address) VALUES
('Jane', 'Smith', 'Johnson', 'Female', '1992-03-10', '+639111222333', 'jane.johnson@email.com', '123 Main Street, Quezon City'),
('Robert', 'Lee', 'Wilson', 'Male', '1988-12-05', '+639444555666', 'robert.wilson@email.com', '456 Oak Avenue, Makati City');

-- =====================================================
-- COMPLETION MESSAGE
-- =====================================================
-- Database setup completed successfully!
-- 
-- Tables created:
-- 1. staff - Admin and guard accounts
-- 2. houses - Pre-registered house numbers
-- 3. users - Registered residents with user_type field
-- 4. entry_passes - Visitor personal information
-- 5. reservations - Amenity bookings with payment tracking
--
-- All foreign key relationships and indexes have been established.
-- Sample data has been inserted for testing purposes.

