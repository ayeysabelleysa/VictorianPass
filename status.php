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
                             e.sex AS ep_sex, e.birthdate AS ep_birthdate,
                             u.first_name, u.last_name, u.email, u.phone, u.house_number,
                             u.sex AS user_sex, u.birthdate AS user_birthdate
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
    
    // Determine effective expiration: minimum of DB end_date and 2-day TTL
    $ttlDays = 2;
    $startYmd = !empty($row['start_date']) ? $row['start_date'] : null;
    $createdYmd = !empty($row['created_at']) ? $row['created_at'] : null;
    $dbEndYmd = !empty($row['end_date']) ? $row['end_date'] : null;
    $ttlEndYmd = null;
    if ($startYmd) {
        $ttlEndYmd = date('Y-m-d', strtotime("$startYmd +$ttlDays days"));
    } elseif ($createdYmd) {
        $ttlEndYmd = date('Y-m-d', strtotime("$createdYmd +$ttlDays days"));
    }
    $effectiveEndYmd = $dbEndYmd ? ($ttlEndYmd ? min($dbEndYmd, $ttlEndYmd) : $dbEndYmd) : $ttlEndYmd;

    if (isset($row['approval_status']) && $row['approval_status'] !== '') {
        $statusVal = $row['approval_status'];
        // Expire if past effective end
        if ($statusVal === 'approved' && $effectiveEndYmd && $effectiveEndYmd < $today) {
            $statusVal = 'expired';
        }
    } else {
        // Fallback: check if expired based on effective end
        if ($effectiveEndYmd && $effectiveEndYmd < $today) {
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
            $statusMessage = 'Expired: This pass has reached its validity end.';
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
    $sex = !empty($row['ep_sex']) ? $row['ep_sex'] : ($row['user_sex'] ?? '');
    $birthRaw = !empty($row['ep_birthdate']) ? $row['ep_birthdate'] : ($row['user_birthdate'] ?? null);
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';
    
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
        'contact' => $phone,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'purpose' => isset($row['purpose']) ? $row['purpose'] : '',
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '',
        'expires_at' => $effectiveEndYmd ? date('m/d/y', strtotime($effectiveEndYmd)) : ''
    ]);
    exit;
}

// Not found
echo json_encode(['success' => false, 'message' => 'Invalid status code.']);
exit;