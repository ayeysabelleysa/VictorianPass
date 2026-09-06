<?php
require_once __DIR__ . '/../session_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$reservationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
if (!$reservationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit;
}

// Use the same connection/configuration as login.php and the admin dashboard.
define('VP_JSON_ERROR_RESPONSE', true);
require_once __DIR__ . '/../connect.php';
if (!($con instanceof mysqli) || $con->connect_errno) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Database temporarily unavailable']);
    exit;
}

$query = "SELECT r.id, r.user_id, r.ref_code, r.amenity, r.start_date, r.end_date,
                 r.start_time, r.end_time, r.persons, r.purpose, r.created_at,
                 r.approval_status, r.approved_by, r.approval_date, r.price,
                 r.downpayment, r.payment_status, r.receipt_path, r.receipt_attempts,
                 r.denial_reason, r.booking_for, r.booked_by_role, r.booked_by_name,
                 r.entry_pass_id, u.first_name, u.middle_name, u.last_name,
                 u.email, u.phone, u.house_number, u.user_type,
                 gf.id AS gf_id, gf.visitor_first_name AS guest_first_name,
                 gf.visitor_middle_name AS guest_middle_name, gf.visitor_last_name AS guest_last_name,
                 gf.visitor_email AS guest_email, gf.visitor_contact AS guest_contact
          FROM reservations r
          LEFT JOIN users u ON u.id = r.user_id
          LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code
          WHERE r.id = ?
          LIMIT 1";

$stmt = $con->prepare($query);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare reservation lookup']);
    exit;
}

$stmt->bind_param('i', $reservationId);
if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load reservation details']);
    exit;
}

$result = $stmt->get_result();
$row = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$row) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Reservation details not found']);
    exit;
}

echo json_encode(['success' => true, 'details' => $row]);
