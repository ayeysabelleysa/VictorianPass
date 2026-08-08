<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../connect.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => '', 'data' => null];

if (!isset($input['qr_code']) || empty($input['qr_code'])) {
    $response['message'] = 'QR code is required';
    echo json_encode($response);
    exit;
}

$qr_code = trim($input['qr_code']);

// Query resident by QR code (adjust table/column names as per your DB)
// Assume residents table has: id, full_name, ecopoint_balance, qr_code, etc.
$stmt = $con->prepare("
    SELECT 
        id, 
        full_name, 
        ecopoint_balance 
    FROM residents 
    WHERE qr_code = ? 
    LIMIT 1
");
$stmt->bind_param('s', $qr_code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $resident = $result->fetch_assoc();

    // Calculate weekly points remaining (max 250/week)
    $start_of_week = date('Y-m-d', strtotime('monday this week'));
    $stmt_weekly = $con->prepare("
        SELECT COALESCE(SUM(points_earned), 0) as total_weekly 
        FROM ecopoint_sessions 
        WHERE resident_id = ? 
        AND created_at >= ?
    ");
    $stmt_weekly->bind_param('is', $resident['id'], $start_of_week);
    $stmt_weekly->execute();
    $weekly_result = $stmt_weekly->get_result()->fetch_assoc();
    $weekly_earned = $weekly_result['total_weekly'];
    $weekly_remaining = max(0, 250 - $weekly_earned);

    // Calculate daily sessions remaining (max 3/day)
    $today = date('Y-m-d');
    $stmt_daily = $con->prepare("
        SELECT COUNT(*) as daily_sessions 
        FROM ecopoint_sessions 
        WHERE resident_id = ? 
        AND DATE(created_at) = ?
    ");
    $stmt_daily->bind_param('is', $resident['id'], $today);
    $stmt_daily->execute();
    $daily_result = $stmt_daily->get_result()->fetch_assoc();
    $daily_used = $daily_result['daily_sessions'];
    $daily_remaining = max(0, 3 - $daily_used);

    $response['success'] = true;
    $response['message'] = 'Resident verified';
    $response['data'] = [
        'resident_id' => $resident['id'],
        'name' => $resident['full_name'],
        'balance' => (int)$resident['ecopoint_balance'],
        'weeklyRemaining' => $weekly_remaining,
        'dailyRemaining' => $daily_remaining
    ];
} else {
    $response['message'] = 'Resident not found';
}

echo json_encode($response);
exit;
?>