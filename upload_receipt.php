<?php
header('Content-Type: application/json');
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
if (empty($ref_code)) {
    echo json_encode(['success' => false, 'message' => 'Reference code is required']);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['receipt'];
$allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
$maxSize = 5 * 1024 * 1024; // 5MB

// Validate file type
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only JPEG, PNG, and GIF are allowed']);
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
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = $ref_code . '_' . time() . '.' . $fileExtension;
$filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
$storedPath = 'uploads/receipts/' . $fileName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

$currentStatus = '';
if ($con instanceof mysqli) {
    $stmtCheck = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
    if ($stmtCheck) {
        $stmtCheck->bind_param('s', $ref_code);
        if ($stmtCheck->execute()) {
            $res = $stmtCheck->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $currentStatus = strtolower(trim($row['payment_status'] ?? ''));
            }
        }
        $stmtCheck->close();
    }
}
$newStatus = ($currentStatus === 'rejected') ? 'pending_update' : 'pending';

// Update database with receipt path and reset payment verification
$stmt = $con->prepare("UPDATE reservations SET receipt_path = ?, payment_status = ?, verified_by = NULL, verification_date = NULL, receipt_uploaded_at = NOW() WHERE ref_code = ?");
    $stmt->bind_param('sss', $storedPath, $newStatus, $ref_code);

if ($stmt->execute()) {
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
