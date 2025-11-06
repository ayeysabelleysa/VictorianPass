<?php
session_start();
header('Content-Type: application/json');
require_once 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Invalid request method']);
  exit;
}

// Ensure tables exist (idempotent)
$con->query("CREATE TABLE IF NOT EXISTS incident_reports (
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
) ENGINE=InnoDB");

$con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_report_id (report_id)
) ENGINE=InnoDB");

// Inputs
$complainant = trim($_POST['complainant'] ?? '');
$address     = trim($_POST['address'] ?? '');
$other       = trim($_POST['other'] ?? '');
$natureArr   = $_POST['nature'] ?? [];

if ($complainant === '' || $address === '') {
  echo json_encode(['success' => false, 'message' => 'Complainant and address are required.']);
  exit;
}

// Compose nature string
$natureStr = '';
if (is_array($natureArr) && count($natureArr) > 0) {
  $natureStr = implode(', ', array_map('strval', $natureArr));
}

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// Insert report
$stmtR = $con->prepare("INSERT INTO incident_reports (complainant, address, nature, other_concern, user_id) VALUES (?, ?, ?, ?, ?)");
$stmtR->bind_param('ssssi', $complainant, $address, $natureStr, $other, $user_id);
if (!$stmtR->execute()) {
  echo json_encode(['success' => false, 'message' => 'Failed to save report: ' . $con->error]);
  exit;
}
$report_id = $stmtR->insert_id;
$stmtR->close();

// Handle files
if (!empty($_FILES['proof']) && is_array($_FILES['proof']['name'])) {
  $uploadDir = 'uploads/reports/';
  if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
  $allowedTypes = ['image/jpeg','image/jpg','image/png','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];

  $names = $_FILES['proof']['name'];
  $tmps  = $_FILES['proof']['tmp_name'];
  $types = $_FILES['proof']['type'];
  $errs  = $_FILES['proof']['error'];
  $sizes = $_FILES['proof']['size'];

  for ($i = 0; $i < count($names); $i++) {
    if ($errs[$i] !== UPLOAD_ERR_OK) { continue; }
    if (!in_array($types[$i], $allowedTypes)) { continue; }
    if ($sizes[$i] > 10 * 1024 * 1024) { continue; } // 10MB per file

    $ext = pathinfo($names[$i], PATHINFO_EXTENSION);
    $basename = 'proof_' . $report_id . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $uploadDir . $basename;
    if (move_uploaded_file($tmps[$i], $dest)) {
      $stmtP = $con->prepare("INSERT INTO incident_proofs (report_id, file_path) VALUES (?, ?)");
      $stmtP->bind_param('is', $report_id, $dest);
      $stmtP->execute();
      $stmtP->close();
    }
  }
}

// Generate reference code for user feedback
$reference = 'IR-' . strtoupper(bin2hex(random_bytes(4)));

echo json_encode(['success' => true, 'reference' => $reference, 'report_id' => $report_id]);
exit;
?>