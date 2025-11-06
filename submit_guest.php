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
$visitor_last_name  = trim($_POST['visitor_last_name'] ?? '');
$visitor_sex        = trim($_POST['visitor_sex'] ?? '');
$visitor_birthdate  = trim($_POST['visitor_birthdate'] ?? '');
$visitor_contact    = trim($_POST['visitor_contact'] ?? '');
$visitor_email      = trim($_POST['visitor_email'] ?? ''); // required by schema

$visit_date   = trim($_POST['visit_date'] ?? '');
$visit_time   = trim($_POST['visit_time'] ?? '');
$visit_purpose= trim($_POST['visit_purpose'] ?? '');

// Validate required inputs
if ($resident_full_name === '' || $resident_house === '' || $resident_email === '' || $resident_contact === '' ||
    $visitor_first_name === '' || $visitor_last_name === '' || $visitor_sex === '' || $visitor_birthdate === '' ||
    $visitor_contact === '' || $visitor_email === '' || $visit_date === '' || $visit_time === '' || $visit_purpose === '') {
  echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
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

// Insert into entry_passes (visitor personal details)
$full_name = trim($visitor_first_name . ' ' . $visitor_last_name);
$stmtEP = $con->prepare("INSERT INTO entry_passes (full_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
// The form does not collect visitor address; reuse resident_house as placeholder address for gate pass visibility
$visitor_address = $resident_house;
$stmtEP->bind_param('ssssssss', $full_name, $visitor_last_name, $visitor_sex, $visitor_birthdate, $visitor_contact, $visitor_email, $visitor_address, $validIdPath);

if (!$stmtEP->execute()) {
  echo json_encode(['success' => false, 'message' => 'Failed to save entry pass: ' . $con->error]);
  exit;
}
$entry_pass_id = $stmtEP->insert_id;
$stmtEP->close();

// Create a reference code
$ref_code = 'VP-' . strtoupper(bin2hex(random_bytes(4)));

// Insert reservation-like record for tracking the request (amenity left generic)
$amenity = 'Guest Entry';
$persons = 1;
$price   = 0.00;
$stmtR = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, persons, price, entry_pass_id, user_id, approval_status, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')");
$start_date = $visit_date;
$end_date   = $visit_date; // same-day visit
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$stmtR->bind_param('ssssidis', $ref_code, $amenity, $start_date, $end_date, $persons, $price, $entry_pass_id, $user_id);

if (!$stmtR->execute()) {
  echo json_encode(['success' => false, 'message' => 'Failed to save request: ' . $con->error]);
  exit;
}
$stmtR->close();

echo json_encode(['success' => true, 'ref_code' => $ref_code]);
exit;
?>