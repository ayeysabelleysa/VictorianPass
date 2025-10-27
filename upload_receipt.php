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
$uploadDir = 'uploads/receipts/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Generate unique filename
$fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
$fileName = $ref_code . '_' . time() . '.' . $fileExtension;
$filePath = $uploadDir . $fileName;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save uploaded file']);
    exit;
}

// Update database with receipt path
$stmt = $con->prepare("UPDATE reservations SET receipt_path = ? WHERE ref_code = ?");
$stmt->bind_param('ss', $filePath, $ref_code);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Receipt uploaded successfully',
        'file_path' => $filePath
    ]);
} else {
    // Delete uploaded file if database update fails
    unlink($filePath);
    echo json_encode(['success' => false, 'message' => 'Failed to update database']);
}

$stmt->close();
$con->close();
?>