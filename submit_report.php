<?php
require_once __DIR__ . '/session_bootstrap.php';
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
  subject VARCHAR(150) NULL,
  address VARCHAR(255) NOT NULL,
  nature VARCHAR(255) NULL,
  other_concern VARCHAR(255) NULL,
  user_id INT NULL,
  report_date DATE NULL,
  status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL,
  INDEX idx_status (status),
  INDEX idx_user_id (user_id)
) ENGINE=InnoDB");

// Add columns if missing (idempotent migrations)
$chk1 = $con->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports' AND COLUMN_NAME = 'subject' LIMIT 1");
if ($chk1 && $chk1->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN subject VARCHAR(150) NULL"); }
if ($chk1) { $chk1->free(); }
$chk2 = $con->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports' AND COLUMN_NAME = 'report_date' LIMIT 1");
if ($chk2 && $chk2->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN report_date DATE NULL"); }
if ($chk2) { $chk2->free(); }

$con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_report_id (report_id)
) ENGINE=InnoDB");

$chkStatus = $con->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports' AND COLUMN_NAME = 'status' LIMIT 1");
if ($chkStatus) {
  $row = $chkStatus->fetch_assoc();
  $colType = $row['COLUMN_TYPE'] ?? '';
  if (strpos($colType, "'cancelled'") === false) {
    $con->query("ALTER TABLE incident_reports MODIFY COLUMN status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new'");
  }
  $chkStatus->free();
}

// Support cancellation by resident
if (isset($_POST['action']) && $_POST['action'] === 'cancel' && isset($_POST['report_id'])) {
  $rid = intval($_POST['report_id']);
  $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
  if ($rid > 0 && $uid > 0) {
    $stmtC = $con->prepare("UPDATE incident_reports SET status='cancelled', updated_at=NOW() WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
    $stmtC->bind_param('ii', $rid, $uid);
    if ($stmtC->execute()) {
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'message' => 'Failed to cancel']);
    }
    $stmtC->close();
    exit;
  }
  echo json_encode(['success' => false, 'message' => 'Invalid cancel request']);
  exit;
}

// Add a proof file to an existing report
if (isset($_POST['action']) && $_POST['action'] === 'add_proof' && isset($_POST['report_id'])) {
  $rid = intval($_POST['report_id']);
  $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
  if ($rid <= 0 || $uid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
  }
  $stmtChk = $con->prepare("SELECT id FROM incident_reports WHERE id = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1");
  $stmtChk->bind_param('ii', $rid, $uid);
  $stmtChk->execute();
  $resChk = $stmtChk->get_result();
  if (!$resChk || $resChk->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Report not found or not owned']);
    $stmtChk->close();
    exit;
  }
  $stmtChk->close();
  if (empty($_FILES['proof']) || !is_array($_FILES['proof']['name'])) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
  }
  $uploadDir = 'uploads/reports/';
  if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
  $allowedTypes = ['image/jpeg','image/jpg','image/png','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
  $names = $_FILES['proof']['name'];
  $tmps  = $_FILES['proof']['tmp_name'];
  $types = $_FILES['proof']['type'];
  $errs  = $_FILES['proof']['error'];
  $sizes = $_FILES['proof']['size'];
  $added = [];
  for ($i = 0; $i < count($names); $i++) {
    if ($errs[$i] !== UPLOAD_ERR_OK) { continue; }
    if (!in_array($types[$i], $allowedTypes)) { continue; }
    if ($sizes[$i] > 5 * 1024 * 1024) { continue; }
    $ext = pathinfo($names[$i], PATHINFO_EXTENSION);
    $basename = 'proof_' . $rid . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $dest = $uploadDir . $basename;
    if (move_uploaded_file($tmps[$i], $dest)) {
      $stmtP = $con->prepare("INSERT INTO incident_proofs (report_id, file_path) VALUES (?, ?)");
      $stmtP->bind_param('is', $rid, $dest);
      if ($stmtP->execute()) {
        $added[] = ['id' => $stmtP->insert_id, 'file_path' => $dest];
      }
      $stmtP->close();
    }
  }
  echo json_encode(['success' => true, 'added' => $added]);
  exit;
}

// Delete a proof file from an existing report
if (isset($_POST['action']) && $_POST['action'] === 'delete_proof' && isset($_POST['report_id']) && isset($_POST['proof_id'])) {
  $rid = intval($_POST['report_id']);
  $pid = intval($_POST['proof_id']);
  $uid = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
  if ($rid <= 0 || $pid <= 0 || $uid <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
  }
  $stmtChk = $con->prepare("SELECT id FROM incident_reports WHERE id = ? AND (user_id = ? OR user_id IS NULL) LIMIT 1");
  $stmtChk->bind_param('ii', $rid, $uid);
  $stmtChk->execute();
  $resChk = $stmtChk->get_result();
  if (!$resChk || $resChk->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Report not found or not owned']);
    $stmtChk->close();
    exit;
  }
  $stmtChk->close();
  $stmtP = $con->prepare("SELECT file_path FROM incident_proofs WHERE id = ? AND report_id = ? LIMIT 1");
  $stmtP->bind_param('ii', $pid, $rid);
  $stmtP->execute();
  $resP = $stmtP->get_result();
  if (!$resP || $resP->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Proof not found']);
    $stmtP->close();
    exit;
  }
  $rowP = $resP->fetch_assoc();
  $file = $rowP['file_path'];
  $stmtP->close();
  $stmtDel = $con->prepare("DELETE FROM incident_proofs WHERE id = ? AND report_id = ?");
  $stmtDel->bind_param('ii', $pid, $rid);
  $ok = $stmtDel->execute();
  $stmtDel->close();
  if ($ok && $file && file_exists($file)) { @unlink($file); }
  echo json_encode(['success' => (bool)$ok]);
  exit;
}

// Inputs
$complainant = trim($_POST['complainant'] ?? '');
$subject     = trim($_POST['subject'] ?? '');
$address     = trim($_POST['address'] ?? '');
$other       = trim($_POST['other'] ?? '');
$natureArr   = $_POST['nature'] ?? [];
$reportDate  = trim($_POST['report_date'] ?? '');

if ($address === '') {
  echo json_encode(['success' => false, 'message' => 'Address is required.']);
  exit;
}

// Compose nature string
$natureStr = '';
if (is_array($natureArr) && count($natureArr) > 0) {
  $natureStr = implode(', ', array_map('strval', $natureArr));
}

$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

// Insert report
$stmtR = $con->prepare("INSERT INTO incident_reports (complainant, subject, address, nature, other_concern, user_id, report_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmtR->bind_param('sssssis', $complainant, $subject, $address, $natureStr, $other, $user_id, $reportDate);
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
    if ($sizes[$i] > 5 * 1024 * 1024) { continue; }

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
$reference = 'RIVH-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

echo json_encode(['success' => true, 'reference' => $reference, 'report_id' => $report_id]);
exit;
?>
