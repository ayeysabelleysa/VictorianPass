<?php
header('Content-Type: application/json');
include 'connect.php';

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '') {
    echo json_encode(['success' => false, 'message' => 'Status code is required.']);
    exit;
}

// Build query to retrieve reservation and personal details
$stmt = $con->prepare("SELECT r.*, 
                             e.full_name AS ep_full_name, e.email AS ep_email, e.contact AS ep_phone, e.address AS ep_address,
                             u.first_name, u.last_name, u.email, u.phone, u.house_number
                       FROM reservations r
                       LEFT JOIN entry_passes e ON r.entry_pass_id = e.id
                       LEFT JOIN users u ON r.user_id = u.id
                       WHERE r.ref_code = ?");

$stmt->bind_param('s', $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $row = $result->fetch_assoc();
    
    // Use approval_status as the primary status indicator
    $today = date('Y-m-d');
    $statusVal = 'pending';
    
    if (isset($row['approval_status']) && $row['approval_status'] !== '') {
        $statusVal = $row['approval_status'];
        
        // Check if approved reservation has expired
        if ($statusVal === 'approved' && isset($row['end_date']) && $row['end_date'] < $today) {
            $statusVal = 'expired';
        }
    } else {
        // Fallback: check if expired based on end date
        if (isset($row['end_date']) && $row['end_date'] < $today) {
            $statusVal = 'expired';
        } else {
            $statusVal = 'pending';
        }
    }

    // Map status to message
    $statusMessage = '';
    switch ($statusVal) {
        case 'approved':
            $statusMessage = 'Approved: Your reservation is confirmed.';
            break;
        case 'pending':
            $statusMessage = 'Pending: Awaiting admin review.';
            break;
        case 'expired':
            $statusMessage = 'Expired: This reservation has passed its end date.';
            break;
        case 'denied':
            $statusMessage = 'Denied: Your reservation was not approved.';
            break;
        default:
            $statusMessage = ucfirst($statusVal);
    }

    // Prepare personal details
    $fullName = '';
    if (!empty($row['ep_full_name'])) {
        $fullName = $row['ep_full_name'];
    } elseif (!empty($row['first_name']) || !empty($row['last_name'])) {
        $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    } else {
        $fullName = 'Guest';
    }

    $email = !empty($row['ep_email']) ? $row['ep_email'] : ($row['email'] ?? '');
    $phone = !empty($row['ep_phone']) ? $row['ep_phone'] : ($row['phone'] ?? '');
    $address = !empty($row['ep_address']) ? $row['ep_address'] : (($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '');
    
    echo json_encode([
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName,
        'type' => $row['amenity'],
        'status' => $statusVal,
        'qr_path' => (!empty($row['qr_path']) ? $row['qr_path'] : 'mainpage/qr.png'),
        'message' => $statusMessage,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : ''
    ]);
    exit;
}

// Not found
echo json_encode(['success' => false, 'message' => 'Invalid status code.']);
exit;