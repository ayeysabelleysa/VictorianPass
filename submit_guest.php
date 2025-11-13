<?php
session_start();
header('Content-Type: application/json');
require_once 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Invalid request method']);
  exit;
}

// Basic validation for required fields
$resident_full_name = trim($_POST['resident_full_name'] ?? '');
$resident_house     = trim($_POST['resident_house'] ?? '');
$resident_email     = trim($_POST['resident_email'] ?? '');
$resident_contact   = trim($_POST['resident_contact'] ?? '');

$visitor_first_name = trim($_POST['visitor_first_name'] ?? '');
$visitor_middle_name = trim($_POST['visitor_middle_name'] ?? '');
$visitor_last_name  = trim($_POST['visitor_last_name'] ?? '');
$visitor_sex        = trim($_POST['visitor_sex'] ?? '');
$visitor_birthdate  = trim($_POST['visitor_birthdate'] ?? '');
$visitor_contact    = trim($_POST['visitor_contact'] ?? '');
$visitor_email      = trim($_POST['visitor_email'] ?? ''); // required by schema

$visit_date    = trim($_POST['visit_date'] ?? '');
$visit_time    = trim($_POST['visit_time'] ?? '');
$visit_purpose = trim($_POST['visit_purpose'] ?? '');
// Persons count (optional on form; default to 1)
$visit_persons = isset($_POST['visit_persons']) ? max(1, intval($_POST['visit_persons'])) : 1;

// Determine amenity intent early
$wants_amenity = isset($_POST['wants_amenity']) ? 1 : 0;

// Validate required inputs
// If reserving an amenity, allow Visit Details (date/time/purpose) to be blank; otherwise require them.
if ($resident_full_name === '' || $resident_house === '' || $resident_email === '' || $resident_contact === '' ||
    $visitor_first_name === '' || $visitor_last_name === '' || $visitor_sex === '' || $visitor_birthdate === '' ||
    $visitor_contact === '' || $visitor_email === '' ||
    (!$wants_amenity && ($visit_date === '' || $visit_time === '' || $visit_purpose === ''))) {
  echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
  exit;
}

// Additional validation: names letters-only, contacts numbers-only with +63
$namePattern = '/^[A-Za-z\s\-\']+$/';
if (!preg_match($namePattern, $resident_full_name)) {
  echo json_encode(['success' => false, 'message' => 'Resident name must contain letters only.']);
  exit;
}
if (!preg_match($namePattern, $visitor_first_name) || !preg_match($namePattern, $visitor_last_name)) {
  echo json_encode(['success' => false, 'message' => 'Visitor names must contain letters only.']);
  exit;
}
if (!preg_match('/^09\d{9}$/', $resident_contact)) {
  echo json_encode(['success' => false, 'message' => 'Resident phone must start with 09 and contain numbers only.']);
  exit;
}
if (!preg_match('/^09\d{9}$/', $visitor_contact)) {
  echo json_encode(['success' => false, 'message' => 'Visitor phone must start with 09 and contain numbers only.']);
  exit;
}
if (!filter_var($resident_email, FILTER_VALIDATE_EMAIL) || !filter_var($visitor_email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(['success' => false, 'message' => 'Please provide valid email addresses.']);
  exit;
}

// Handle valid ID upload
$validIdPath = null;
if (isset($_FILES['visitor_valid_id']) && $_FILES['visitor_valid_id']['error'] === UPLOAD_ERR_OK) {
  $allowed = ['image/jpeg','image/jpg','image/png'];
  $file    = $_FILES['visitor_valid_id'];
  if (!in_array($file['type'], $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID file type. Use JPG/PNG.']);
    exit;
  }
  if ($file['size'] > 5 * 1024 * 1024) { // 5MB
    echo json_encode(['success' => false, 'message' => 'ID file too large (max 5MB).']);
    exit;
  }
  $uploadDir = 'uploads/ids/';
  if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
  $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
  $basename = 'id_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest = $uploadDir . $basename;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save ID file.']);
    exit;
  }
  $validIdPath = $dest;
} else {
  echo json_encode(['success' => false, 'message' => 'Visitor valid ID is required.']);
  exit;
}

// Ensure guest_forms table exists
$con->query("CREATE TABLE IF NOT EXISTS guest_forms (
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
  wants_amenity TINYINT(1) NOT NULL DEFAULT 0,
  persons INT NULL,
  ref_code VARCHAR(50) NOT NULL UNIQUE,
  approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
  approved_by INT NULL,
  approval_date DATETIME NULL,
  qr_path VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  INDEX idx_resident_user_id (resident_user_id),
  INDEX idx_ref_code (ref_code)
) ENGINE=InnoDB");

// Generate a reference code for this guest form
$ref_code = 'VP-' . strtoupper(bin2hex(random_bytes(4)));

// Attempt to link to a resident account
$resident_user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
if ($resident_user_id === null) {
  $stmtU = $con->prepare("SELECT id FROM users WHERE email = ? OR house_number = ? LIMIT 1");
  if ($stmtU) {
    $stmtU->bind_param('ss', $resident_email, $resident_house);
    if ($stmtU->execute()) {
      $resU = $stmtU->get_result();
      if ($resU) {
        $rowU = $resU->fetch_assoc();
        if ($rowU && isset($rowU['id'])) {
          $resident_user_id = intval($rowU['id']);
        }
      }
    }
    $stmtU->close();
  }
}

// Insert into guest_forms
$stmtGF = $con->prepare("INSERT INTO guest_forms (
  resident_user_id, resident_house, resident_email,
  visitor_first_name, visitor_middle_name, visitor_last_name,
  visitor_sex, visitor_birthdate, visitor_contact, visitor_email,
  valid_id_path, visit_date, visit_time, purpose, persons, wants_amenity, ref_code, approval_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

// $wants_amenity already determined above

$stmtGF->bind_param(
  'isssssssssssssiis',
  $resident_user_id,
  $resident_house,
  $resident_email,
  $visitor_first_name,
  $visitor_middle_name,
  $visitor_last_name,
  $visitor_sex,
  $visitor_birthdate,
  $visitor_contact,
  $visitor_email,
  $validIdPath,
  $visit_date,
  $visit_time,
  $visit_purpose,
  $visit_persons,
  $wants_amenity,
  $ref_code
);

if (!$stmtGF->execute()) {
  echo json_encode(['success' => false, 'message' => 'Failed to save guest form: ' . $con->error]);
  exit;
}
$stmtGF->close();

echo json_encode(['success' => true, 'ref_code' => $ref_code]);
exit;
?>