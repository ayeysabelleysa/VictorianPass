<?php
session_start();
require_once 'connect.php';

header('Content-Type: application/json');

$entry_pass_id = isset($_POST['entry_pass_id']) ? intval($_POST['entry_pass_id']) : 0;
if ($entry_pass_id <= 0) {
  echo json_encode(['success' => false, 'message' => 'Invalid entry pass']);
  exit;
}

if (!isset($_FILES['receipt']) || empty($_FILES['receipt']['name'])) {
  echo json_encode(['success' => false, 'message' => 'Receipt file is required']);
  exit;
}

// Validate mimetype and size
$allowed = ['image/jpeg','image/png','image/gif'];
$mime = mime_content_type($_FILES['receipt']['tmp_name']);
if (!in_array($mime, $allowed)) {
  echo json_encode(['success' => false, 'message' => 'Invalid file type']);
  exit;
}
if ($_FILES['receipt']['size'] > 5 * 1024 * 1024) {
  echo json_encode(['success' => false, 'message' => 'File too large (max 5MB)']);
  exit;
}

// Ensure upload directory
$dir = 'uploads/payments/';
if (!is_dir($dir)) {
  mkdir($dir, 0777, true);
}

// Save file
$extension = pathinfo($_FILES['receipt']['name'], PATHINFO_EXTENSION);
$filename = 'entry_' . $entry_pass_id . '_' . time() . '.' . $extension;
$path = $dir . $filename;
if (!move_uploaded_file($_FILES['receipt']['tmp_name'], $path)) {
  echo json_encode(['success' => false, 'message' => 'Failed to store file']);
  exit;
}

// Ensure columns exist
$col1 = $con->query("SHOW COLUMNS FROM entry_passes LIKE 'downpayment_receipt_path'");
if (!$col1 || $col1->num_rows === 0) {
  $con->query("ALTER TABLE entry_passes ADD COLUMN downpayment_receipt_path VARCHAR(255) NULL");
}
$col2 = $con->query("SHOW COLUMNS FROM entry_passes LIKE 'downpayment_status'");
if (!$col2 || $col2->num_rows === 0) {
  $con->query("ALTER TABLE entry_passes ADD COLUMN downpayment_status ENUM('pending','uploaded','verified','rejected') DEFAULT 'pending'");
}

// Update entry_passes with receipt path and status
$stmt = $con->prepare("UPDATE entry_passes SET downpayment_receipt_path = ?, downpayment_status = 'uploaded' WHERE id = ?");
$stmt->bind_param('si', $path, $entry_pass_id);
if ($stmt->execute()) {
  echo json_encode(['success' => true, 'path' => $path]);
} else {
  echo json_encode(['success' => false, 'message' => 'Failed to update entry pass']);
}
?>