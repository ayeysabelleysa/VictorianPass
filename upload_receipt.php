<?php
require_once __DIR__ . '/session_bootstrap.php';
$sessionUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0;
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
$reservationId = isset($_POST['reservation_id']) ? intval($_POST['reservation_id']) : 0;
if ($sessionUserId <= 0 || ($reservationId <= 0 && $ref_code === '')) {
    http_response_code($sessionUserId <= 0 ? 401 : 400);
    echo json_encode(['success' => false, 'message' => 'A valid reservation is required']);
    exit;
}

if ($reservationId > 0) {
    $stmtCheck = $con->prepare("SELECT id, ref_code, payment_status FROM reservations WHERE id = ? AND user_id = ? LIMIT 1");
    if ($stmtCheck) { $stmtCheck->bind_param('ii', $reservationId, $sessionUserId); }
} else {
    $stmtCheck = $con->prepare("SELECT id, ref_code, payment_status FROM reservations WHERE ref_code = ? AND user_id = ? LIMIT 1");
    if ($stmtCheck) { $stmtCheck->bind_param('si', $ref_code, $sessionUserId); }
}
$reservation = null;
if ($stmtCheck && $stmtCheck->execute()) {
    $reservationResult = $stmtCheck->get_result();
    $reservation = $reservationResult ? $reservationResult->fetch_assoc() : null;
}
if ($stmtCheck) { $stmtCheck->close(); }
if (!$reservation) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    exit;
}
$reservationId = intval($reservation['id']);
$ref_code = (string)$reservation['ref_code'];

// Check if file was uploaded
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['receipt'];
$allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'application/pdf' => 'pdf'];
$maxSize = 5 * 1024 * 1024; // 5MB

// Validate the file content rather than trusting the browser-provided MIME type.
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($file['tmp_name']);
if (!isset($allowedTypes[$mimeType])) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, GIF, and PDF are allowed']);
    exit;
}

// Validate file size
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'message' => 'File too large. Maximum size is 5MB']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'receipts';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        echo json_encode(['success' => false, 'message' => 'Receipt storage is unavailable']);
        exit;
    }
}

// Unique names prevent simultaneous uploads from replacing any stored receipt.
$fileExtension = $allowedTypes[$mimeType];
$fileName = 'receipt_' . $reservationId . '_' . bin2hex(random_bytes(12)) . '.' . $fileExtension;
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
$storedPath = 'uploads/receipts/' . $fileName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

$currentStatus = strtolower(trim($reservation['payment_status'] ?? ''));
$newStatus = ($currentStatus === 'rejected') ? 'pending_update' : 'pending';

// Update database with receipt path and reset payment verification
$stmt = $con->prepare("UPDATE reservations SET receipt_path = ?, payment_status = ?, verified_by = NULL, verification_date = NULL, receipt_uploaded_at = NOW() WHERE id = ? AND user_id = ?");
if (!$stmt) {
    @unlink($filePath);
    echo json_encode(['success' => false, 'message' => 'Failed to update reservation']);
    exit;
}
$stmt->bind_param('ssii', $storedPath, $newStatus, $reservationId, $sessionUserId);

if ($stmt->execute() && $stmt->affected_rows === 1) {
    if ($newStatus === 'pending_update') {
        try {
            $con->query("CREATE TABLE IF NOT EXISTS notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL COMMENT 'For residents',
                entry_pass_id INT NULL COMMENT 'For visitors',
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                is_read TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
                INDEX idx_user_id (user_id),
                INDEX idx_is_read (is_read)
            ) ENGINE=InnoDB");
            $title = 'Payment proof updated';
            $message = "User updated payment proof for reservation {$ref_code}.";
            $type = 'warning';
            $stmtN = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (NULL, ?, ?, ?, NOW())");
            if ($stmtN) {
                $stmtN->bind_param('sss', $title, $message, $type);
                $stmtN->execute();
                $stmtN->close();
            }
        } catch (Throwable $e) {}
    }
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt uploaded successfully',
        'file_path' => $storedPath,
        'reservation_id' => $reservationId,
        'payment_status' => $newStatus
    ]);
} else {
    // Delete uploaded file if database update fails
    unlink($filePath);
    echo json_encode(['success' => false, 'message' => 'Failed to update database']);
}

$stmt->close();
$con->close();
?>
