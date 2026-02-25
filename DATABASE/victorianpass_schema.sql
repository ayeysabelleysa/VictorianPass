CREATE DATABASE IF NOT EXISTS victorianpass_db;
USE victorianpass_db;
CREATE TABLE IF NOT EXISTS staff (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','guard') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO staff (email, password, role) VALUES
('admin@victorianpass.com', 'admin12345', 'admin'),
('guard@victorianpass.com', 'guard12345', 'guard'),
('guard_Domingogar@victorianpass.com', 'guard12345', 'guard');

CREATE TABLE IF NOT EXISTS houses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  house_number VARCHAR(50) NOT NULL UNIQUE,
  address VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO houses (house_number, address)
SELECT CONCAT('VH-', LPAD(n, 4, '0')), 'Victorian Heights Subdivision'
FROM (
  SELECT a.n + b.n*10 + c.n*100 + d.n*1000 + 1 AS n
  FROM (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) a
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) b
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) c
  CROSS JOIN (SELECT 0 n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) d
) nums
WHERE n BETWEEN 1 AND 2220;

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
  valid_id_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_created_at (created_at),
  INDEX idx_full_name (full_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  ref_code VARCHAR(50) NOT NULL UNIQUE,
  amenity VARCHAR(100) NULL,
  start_date DATE NULL,
  start_time TIME NULL,
  end_date DATE NULL,
  end_time TIME NULL,
  persons INT NULL,
  price DECIMAL(10,2) NULL,
  downpayment DECIMAL(10,2) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  entry_pass_id INT NULL,
  user_id INT NULL,
  purpose VARCHAR(255) NULL,
  approval_status ENUM('pending', 'approved', 'denied') DEFAULT 'pending',
  status ENUM('pending','approved','rejected','expired') DEFAULT 'pending',
  approved_by INT NULL,
  approval_date TIMESTAMP NULL,
  receipt_path VARCHAR(255) NULL,
  qr_path VARCHAR(255) NULL,
  payment_status ENUM('pending', 'submitted', 'verified', 'rejected', 'pending_update') DEFAULT 'pending',
  verified_by INT NULL,
  verification_date DATETIME NULL,
  receipt_uploaded_at DATETIME NULL,
  account_type ENUM('visitor','resident') NULL,
  INDEX idx_ref_code (ref_code),
  INDEX idx_entry_pass_id (entry_pass_id),
  INDEX idx_user_id (user_id),
  INDEX idx_approval_status (approval_status),
  INDEX idx_payment_status (payment_status),
  INDEX idx_created_at (created_at)
) ENGINE=InnoDB;

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
  visitor_address VARCHAR(255) NULL,
  valid_id_path VARCHAR(255) NULL,
  visit_date DATE NULL,
  visit_time VARCHAR(20) NULL,
  purpose VARCHAR(255) NULL,
  wants_amenity TINYINT(1) NOT NULL DEFAULT 0,
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

ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_entry_pass 
  FOREIGN KEY (entry_pass_id) REFERENCES entry_passes(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_user 
  FOREIGN KEY (user_id) REFERENCES users(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_staff_approval 
  FOREIGN KEY (approved_by) REFERENCES staff(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE reservations 
  ADD CONSTRAINT fk_reservations_staff_verification 
  FOREIGN KEY (verified_by) REFERENCES staff(id) 
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE incident_reports
  ADD CONSTRAINT fk_incident_reports_user
  FOREIGN KEY (user_id) REFERENCES users(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE incident_proofs
  ADD CONSTRAINT fk_incident_proofs_report
  FOREIGN KEY (report_id) REFERENCES incident_reports(id)
  ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE guest_forms
  ADD CONSTRAINT fk_guest_forms_user
  FOREIGN KEY (resident_user_id) REFERENCES users(id)
  ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE guest_forms
  ADD CONSTRAINT fk_guest_forms_staff_approval
  FOREIGN KEY (approved_by) REFERENCES staff(id)
  ON DELETE SET NULL ON UPDATE CASCADE;
