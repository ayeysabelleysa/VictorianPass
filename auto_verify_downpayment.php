<?php
header('Content-Type: application/json');
require_once 'connect.php';
require_once __DIR__ . '/amenity_holds.php';

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
try {
  vpAmenityHoldsEnsureSchema($con);
  $con->begin_transaction();
  $stmt = $con->prepare("UPDATE reservations SET receipt_path = ?, payment_status = 'verified', verification_date = ? WHERE ref_code = ?");
  if (!$stmt) {
    throw new RuntimeException('Failed to prepare payment verification: ' . $con->error);
  }
  $stmt->bind_param('sss', $stubPath, $now, $ref_code);
  if (!$stmt->execute()) {
    throw new RuntimeException('Failed to update payment status: ' . $stmt->error);
  }
  $stmt->close();
  vpNotifyQueuedUsersForReservationReference($con, $ref_code);
  $con->commit();
  echo json_encode(['success' => true, 'message' => 'Payment auto-verified', 'ref_code' => $ref_code]);
} catch (Throwable $e) {
  $con->rollback();
  error_log('auto_verify_downpayment queue notification failed: ' . $e->getMessage());
  echo json_encode(['success' => false, 'message' => 'Failed to verify payment and notify queued users']);
}
$con->close();
?>