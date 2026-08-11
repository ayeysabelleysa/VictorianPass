<?php
/**
 * Endpoint 1 — QR Verify + Create Session
 * --------------------------------------------------------
 * Called by hardware when the resident's QR code is scanned.
 * Verifies the resident, checks limits, creates a new ACTIVE
 * session (or returns the existing one if duplicate guard fires).
 *
 * Auth:      X-VHECO-Station + X-VHECO-Api-Key headers
 * Method:    POST
 * JSON Body: { "qr_code": "<resident ref_code or scanned value>" }
 * Response:  { "success": true,
 *              "session_token": "...",
 *              "session": {...},
 *              "resident": {...},
 *              "cap_state": {...} }
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');
$station  = eco_authenticate_station($con);
$input    = eco_read_json_input();

$qrCode   = trim((string)($input['qr_code'] ?? ''));
if ($qrCode === '') {
    eco_json_response(['success' => false, 'message' => 'qr_code is required'], 400);
}

// Verify resident (DB is source of truth)
$resident = eco_find_resident_by_qr($con, $qrCode);
if (!$resident) {
    eco_json_response(['success' => false, 'message' => 'QR code not found or invalid resident'], 404);
}

$capState = eco_resident_cap_state($con, (int)$resident['user_id']);
if ($capState['daily_sessions_left'] <= 0) {
    eco_json_response([
        'success'   => false,
        'message'   => 'Daily session limit reached (max ' . ECO_DAILY_SESSION_CAP . ' per day)',
        'resident'  => [
            'user_id'   => $resident['user_id'],
            'full_name' => $resident['full_name'],
        ],
        'cap_state' => $capState,
    ], 403);
}

if ($capState['daily_points_left'] <= 0 && $capState['weekly_points_left'] <= 0) {
    eco_json_response([
        'success'   => false,
        'message'   => 'No points capacity remaining (daily/weekly caps reached). Try again later.',
        'resident'  => [
            'user_id'   => $resident['user_id'],
            'full_name' => $resident['full_name'],
        ],
        'cap_state' => $capState,
    ], 403);
}

// Create session (duplicate guard inside — returns existing if user already has ACTIVE)
$result  = eco_create_session($con, $station, $resident, $qrCode);
$session = $result['session'];

eco_json_response([
    'success'       => true,
    'created_new'   => (bool)$result['created'],
    'session_token' => (string)$session['session_token'],
    'session'       => [
        'id'          => (int)$session['id'],
        'status'      => (string)$session['status'],
        'created_at'  => (string)$session['created_at'],
    ],
    'resident'      => [
        'user_id'   => (int)$resident['user_id'],
        'full_name' => (string)$resident['full_name'],
        'balance'   => (int)$resident['balance'],
    ],
    'cap_state'     => $result['cap_state'] ?? $capState,
    'rates'         => ECO_MATERIAL_RATES,
    'rules'         => [
        'daily_point_cap'    => ECO_DAILY_POINT_CAP,
        'daily_session_cap'  => ECO_DAILY_SESSION_CAP,
        'weekly_point_cap'   => ECO_WEEKLY_POINT_CAP,
        'max_balance'        => ECO_MAX_BALANCE,
        'session_timeout_sec'=> ECO_SESSION_TIMEOUT_SEC,
    ],
]);
