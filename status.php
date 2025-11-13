<?php
header('Content-Type: application/json');
include 'connect.php';

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
if ($code === '') {
    echo json_encode(['success' => false, 'message' => 'Status code is required.']);
    exit;
}

// First, try to retrieve from guest_forms (new guest entry flow)
$stmtGF = $con->prepare("SELECT gf.*, 
                                u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name,
                                u.email AS res_email, u.phone AS res_phone
                         FROM guest_forms gf
                         LEFT JOIN users u ON gf.resident_user_id = u.id
                         WHERE gf.ref_code = ?");
$stmtGF->bind_param('s', $code);
$stmtGF->execute();
$resGF = $stmtGF->get_result();
if ($resGF && $resGF->num_rows > 0) {
    $row = $resGF->fetch_assoc();
    $today = date('Y-m-d');
    $statusVal = ($row['approval_status'] ?? 'pending');
    $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
    $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;
    if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) {
        $statusVal = 'expired';
    }

    $statusMessage = '';
    switch ($statusVal) {
        case 'approved': $statusMessage = 'Approved: Your guest entry is confirmed.'; break;
        case 'pending': $statusMessage = 'Pending: Awaiting admin review.'; break;
        case 'expired': $statusMessage = 'Expired: This pass has reached its validity end.'; break;
        case 'denied': $statusMessage = 'Denied: Your request was not approved.'; break;
        default: $statusMessage = ucfirst($statusVal);
    }

    $fullName = trim(($row['visitor_first_name'] ?? '') . ' ' . ($row['visitor_last_name'] ?? ''));
    if ($fullName === '') $fullName = 'Guest';
    $email = $row['visitor_email'] ?? '';
    $phone = $row['visitor_contact'] ?? '';
    // Normalize phone to 09 format if stored as +63
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = $row['resident_house'] ?? (($row['res_house_number'] ?? '') ? ('Block ' . $row['res_house_number']) : '');
    $sex = $row['visitor_sex'] ?? '';
    $birthRaw = $row['visitor_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';

    $isAmenity = !empty($row['amenity']);
    $resp = [
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName,
        'type' => $isAmenity ? $row['amenity'] : 'Guest Entry',
        'status' => $statusVal,
        'qr_path' => (!empty($row['qr_path']) ? $row['qr_path'] : 'mainpage/qr.png'),
        'message' => $statusMessage,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'contact' => $phone,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'purpose' => $row['purpose'] ?? '',
        'persons' => isset($row['persons']) ? intval($row['persons']) : null,
        'price' => $isAmenity && isset($row['price']) ? floatval($row['price']) : null,
        'start_date' => $isAmenity && !empty($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : (isset($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : ''),
        'end_date' => $isAmenity && !empty($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : (isset($row['visit_date']) ? date('m/d/y', strtotime($row['visit_date'])) : ''),
        'expires_at' => $expireAfterApprovalYmd ? date('m/d/y', strtotime($expireAfterApprovalYmd)) : ''
    ];
    echo json_encode($resp);
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
    
    // Expiration logic: only expire 1 day after approval
    $approvalDateYmd = !empty($row['approval_date']) ? date('Y-m-d', strtotime($row['approval_date'])) : null;
    $expireAfterApprovalYmd = $approvalDateYmd ? date('Y-m-d', strtotime($approvalDateYmd . ' +1 day')) : null;

    if (isset($row['approval_status']) && $row['approval_status'] !== '') {
        $statusVal = $row['approval_status'];
        if ($statusVal === 'approved' && $expireAfterApprovalYmd && $today > $expireAfterApprovalYmd) {
            $statusVal = 'expired';
        }
    } else {
        $statusVal = 'pending';
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
        'persons' => isset($row['persons']) ? intval($row['persons']) : null,
        'price' => isset($row['price']) ? floatval($row['price']) : null,
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '',
        'expires_at' => $expireAfterApprovalYmd ? date('m/d/y', strtotime($expireAfterApprovalYmd)) : ''
    ]);
    exit;
}

// Not found
// Fallback: check resident_reservations by ref_code
$stmt2 = $con->prepare("SELECT rr.*, u.first_name, u.last_name, u.email, u.phone, u.house_number, u.sex AS user_sex, u.birthdate AS user_birthdate
                        FROM resident_reservations rr
                        LEFT JOIN users u ON rr.user_id = u.id
                        WHERE rr.ref_code = ?");
$stmt2->bind_param('s', $code);
$stmt2->execute();
$res2 = $stmt2->get_result();
if ($res2 && $res2->num_rows > 0) {
    $row = $res2->fetch_assoc();

    $today = date('Y-m-d');
    $statusVal = isset($row['approval_status']) && $row['approval_status'] !== '' ? $row['approval_status'] : 'pending';
    // Expire based on end_date after approval
    if ($statusVal === 'approved' && !empty($row['end_date']) && $row['end_date'] < $today) {
        $statusVal = 'expired';
    }
    $statusMessage = '';
    switch ($statusVal) {
        case 'approved': $statusMessage = 'Approved: Your reservation is confirmed.'; break;
        case 'pending': $statusMessage = 'Pending: Awaiting admin review.'; break;
        case 'expired': $statusMessage = 'Expired: This reservation has ended.'; break;
        case 'denied': $statusMessage = 'Denied: Your reservation was not approved.'; break;
        default: $statusMessage = ucfirst($statusVal);
    }

    $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
    $email = $row['email'] ?? '';
    $phone = $row['phone'] ?? '';
    // Normalize phone to 09 format if stored as +63
    if (preg_match('/^\+63(9\d{9})$/', $phone)) { $phone = '0' . substr($phone, 3); }
    $address = ($row['house_number'] ?? '') ? ('Block ' . $row['house_number']) : '';
    $sex = $row['user_sex'] ?? '';
    $birthRaw = $row['user_birthdate'] ?? null;
    $birthdate = $birthRaw ? date('m/d/y', strtotime($birthRaw)) : '';

    echo json_encode([
        'success' => true,
        'code' => $row['ref_code'],
        'name' => $fullName !== '' ? $fullName : 'Resident',
        'type' => $row['amenity'],
        'status' => $statusVal,
        'qr_path' => 'mainpage/qr.png',
        'message' => $statusMessage,
        'email' => $email,
        'phone' => $phone,
        'address' => $address,
        'contact' => $phone,
        'sex' => $sex,
        'birthdate' => $birthdate,
        'purpose' => $row['notes'] ?? '',
        'persons' => null,
        'price' => null,
        'start_date' => isset($row['start_date']) ? date('m/d/y', strtotime($row['start_date'])) : '',
        'end_date' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : '',
        'expires_at' => isset($row['end_date']) ? date('m/d/y', strtotime($row['end_date'])) : ''
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid status code.']);
exit;
