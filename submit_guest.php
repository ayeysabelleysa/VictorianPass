<?php
require_once __DIR__ . '/session_bootstrap.php';
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
 $visitor_address    = trim($_POST['visitor_address'] ?? '');

if ($visitor_birthdate !== '') {
  $today = date('Y-m-d');
  if ($visitor_birthdate > $today) {
    echo json_encode(['success' => false, 'message' => 'Birthdate cannot be in the future.']);
    exit;
  }
}
$visitor_email      = trim($_POST['visitor_email'] ?? '');


$visit_date    = trim($_POST['visit_date'] ?? '');
$visit_time    = trim($_POST['visit_time'] ?? '');
$visit_purpose = null;
$visit_persons = 1;
$wants_amenity = 0;

if ($resident_full_name === '' || $resident_house === '' || $resident_email === '' || $resident_contact === '' ||
    $visitor_first_name === '' || $visitor_last_name === '' || $visitor_sex === '' || $visitor_birthdate === '' ||
    $visitor_contact === '' || $visitor_address === '' || $visit_date === '' || $visit_time === '') {
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
  echo json_encode(['success' => false, 'message' => 'Guest names must contain letters only.']);
  exit;
}
// Normalize phone numbers to 09 format (11 digits)
$phonesToNormalize = ['resident_contact' => &$resident_contact, 'visitor_contact' => &$visitor_contact];
foreach ($phonesToNormalize as $key => &$pVal) {
    $phoneClean = preg_replace('/[\s\-]/', '', $pVal);
    // Remove +63 or 63 prefix if present
    if (preg_match('/^(\+63|63)(9\d{9})$/', $phoneClean, $matches)) {
        $pVal = '0' . $matches[2];
    } elseif (preg_match('/^0(9\d{9})$/', $phoneClean, $matches)) {
        $pVal = '0' . $matches[1];
    } elseif (preg_match('/^(9\d{9})$/', $phoneClean, $matches)) {
        $pVal = '0' . $matches[1];
    }
}
unset($pVal);

if (!preg_match('/^09\d{9}$/', $resident_contact)) {
  echo json_encode(['success' => false, 'message' => 'Resident phone must be 11 digits starting with 09 (e.g. 09XX...).']);
  exit;
}
if (!preg_match('/^09\d{9}$/', $visitor_contact)) {
  echo json_encode(['success' => false, 'message' => 'Guest phone must be 11 digits starting with 09 (e.g. 09XX...).']);
  exit;
}
if (!filter_var($resident_email, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(['success' => false, 'message' => 'Please provide a valid resident email address.']);
  exit;
}
$rParts = explode('@', $resident_email);
if (ctype_digit($rParts[0])) {
  echo json_encode(['success' => false, 'message' => 'Resident Email Invalid']);
  exit;
}

if ($visitor_email !== '') {
  if (!filter_var($visitor_email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please provide a valid guest email address.']);
    exit;
  }
  $vParts = explode('@', $visitor_email);
  if (ctype_digit($vParts[0])) {
    echo json_encode(['success' => false, 'message' => 'Guest Email Invalid']);
    exit;
  }
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
  echo json_encode(['success' => false, 'message' => 'Guest valid ID is required.']);
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
  visitor_address VARCHAR(255) NULL,
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

$columnCheck = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'visitor_address'");
if ($columnCheck && $columnCheck->num_rows === 0) {
  $con->query("ALTER TABLE guest_forms ADD COLUMN visitor_address VARCHAR(255) NULL");
}

// Generate a reference code for this guest form
$ref_code = 'VP-' . strtoupper(bin2hex(random_bytes(4)));

// Attempt to link to a resident account
$resident_user_id = null;
if (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) {
  $sid = intval($_SESSION['user_id']);
  $stmtV = $con->prepare("SELECT id FROM users WHERE id = ? AND user_type = 'resident' LIMIT 1");
  if ($stmtV) {
    $stmtV->bind_param('i', $sid);
    $stmtV->execute();
    $resV = $stmtV->get_result();
    if ($resV && $resV->num_rows > 0) {
      $resident_user_id = $sid;
    }
    $stmtV->close();
  }
}
if ($resident_user_id === null) {
  // Fallback: match the resident by email first, then by house number
  $stmtU = $con->prepare("SELECT id FROM users WHERE user_type = 'resident' AND (email = ? OR house_number = ?) ORDER BY (email = ?) DESC, id LIMIT 1");
  if ($stmtU) {
    $stmtU->bind_param('sss', $resident_email, $resident_house, $resident_email);
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

$stmtGF = $con->prepare("INSERT INTO guest_forms (
  resident_user_id, resident_house, resident_email,
  visitor_first_name, visitor_middle_name, visitor_last_name,
  visitor_sex, visitor_birthdate, visitor_contact, visitor_email, visitor_address,
  valid_id_path, visit_date, visit_time, purpose, persons, wants_amenity, ref_code, approval_status
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

$types = 'i' . str_repeat('s', 14) . 'ii' . 's';
$stmtGF->bind_param(
  $types,
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
  $visitor_address,
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
