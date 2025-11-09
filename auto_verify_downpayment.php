<?php
header('Content-Type: application/json');
require_once 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Invalid request method']);
  exit;
}

$ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
$entry_pass_id = isset($_POST['entry_pass_id']) ? intval($_POST['entry_pass_id']) : 0;

// If ref_code not provided, attempt to find from entry_pass_id
if ($ref_code === '' && $entry_pass_id > 0) {
  $stmtFind = $con->prepare("SELECT ref_code FROM reservations WHERE entry_pass_id = ? ORDER BY id DESC LIMIT 1");
  if ($stmtFind) {
    $stmtFind->bind_param('i', $entry_pass_id);
    if ($stmtFind->execute()) {
      $res = $stmtFind->get_result();
      $row = $res ? $res->fetch_assoc() : null;
      if ($row && !empty($row['ref_code'])) {
        $ref_code = $row['ref_code'];
      }
    }
    $stmtFind->close();
  }
}

if ($ref_code === '') {
  echo json_encode(['success' => false, 'message' => 'Missing reference code']);
  exit;
}

// Auto-verify payment: set receipt_path stub and mark verified
$now = date('Y-m-d H:i:s');
$stubPath = 'gcash_paid';
$stmt = $con->prepare("UPDATE reservations SET receipt_path = ?, payment_status = 'verified', verification_date = ? WHERE ref_code = ?");
if (!$stmt) {
  echo json_encode(['success' => false, 'message' => 'Failed to prepare statement']);
  exit;
}
$stmt->bind_param('sss', $stubPath, $now, $ref_code);
if ($stmt->execute()) {
  echo json_encode(['success' => true, 'message' => 'Payment auto-verified', 'ref_code' => $ref_code]);
} else {
  echo json_encode(['success' => false, 'message' => 'Failed to update reservation']);
}
$stmt->close();
$con->close();
?>