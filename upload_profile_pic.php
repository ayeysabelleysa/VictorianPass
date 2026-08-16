<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$uploadDir = 'uploads/profiles/';

if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['profile_pic']['tmp_name'];
    $fileName = $_FILES['profile_pic']['name'];
    $fileSize = $_FILES['profile_pic']['size'];
    $fileType = $_FILES['profile_pic']['type'];
    $fileNameCmps = explode(".", $fileName);
    $fileExtension = strtolower(end($fileNameCmps));

    $allowedfileExtensions = array('jpg', 'gif', 'png', 'jpeg');

    if (in_array($fileExtension, $allowedfileExtensions)) {
        // limit to 5MB
        if ($fileSize > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'message' => 'File too large. Max 5MB.']);
            exit;
        }

        // Generate a consistent name: user_<id>.jpg (we'll convert or just rename)
        // To avoid caching issues, we might want to store the timestamp or handle it in frontend.
        // For simplicity, we overwrite user_<id>.<ext>
        // But to make it easier to load, let's just save as .jpg or .png or whatever.
        // Actually, to make the frontend logic simple (just checking for file existence), we should probably standardize the extension or check all.
        // Let's just use the original extension and we'll have to search for it, OR convert to JPG.
        // Simpler: Just save as user_<id>.<ext> and return the new URL.
        
        // Remove any existing profile pics for this user to avoid confusion
        $existing = glob($uploadDir . 'user_' . $userId . '.*');
        foreach ($existing as $f) {
            unlink($f);
        }

        $newFileName = 'user_' . $userId . '.' . $fileExtension;
        $dest_path = $uploadDir . $newFileName;

        if(move_uploaded_file($fileTmpPath, $dest_path)) {
            echo json_encode([
                'success' => true, 
                'message' => 'File uploaded successfully',
                'new_url' => $dest_path . '?t=' . time()
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Error moving file to upload directory.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Upload failed. Allowed file types: ' . implode(',', $allowedfileExtensions)]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
}
?>