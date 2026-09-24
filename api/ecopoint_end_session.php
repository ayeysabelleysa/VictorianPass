<?php
/**
 * Resident "End Session Early" API
 * --------------------------------------------------------
 * Ends the resident's currently active VHEcoPoint session,
 * saving all materials/weight/points recorded up to that
 * moment (reuses the existing idempotent finalize logic).
 *
 * - Authentication: resident's existing PHP session (same as
 *   api/ecopoint_session_status.php).
 * - CSRF: required (vpCsrfVerify).
 * - Method: POST only.
 * - No dummy data: points are recomputed from the WASTE_DATA
 *   events the station already logged.
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../session_bootstrap.php';

// --- Resolve resident user id (same fallbacks as session status API) ---
$userId = null;
$possible = ['user_id', 'uid', 'userId', 'resident_id'];
foreach ($possible as $k) {
    if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}
if ($userId === null) {
    eco_json_response(['success' => false, 'message' => 'Not authenticated'], 401);
}

// --- CSRF protection ---
$input = eco_read_json_input();
$postedCsrf = isset($input['csrf_token']) ? trim((string)$input['csrf_token']) : '';
if ($postedCsrf === '' || !function_exists('vpCsrfVerify') || !vpCsrfVerify($postedCsrf)) {
    eco_json_response(['success' => false, 'message' => 'Invalid CSRF token'], 403);
}

// --- Locate the resident's active session ---
$session = eco_get_active_session_for_user($con, $userId);
if (!$session) {
    eco_json_response(['success' => false, 'message' => 'No active session to end'], 400);
}

$sessionStatus = strtoupper((string)($session['status'] ?? ''));
if (!in_array($sessionStatus, ECO_SESSION_STATUSES_OPEN, true)) {
    eco_json_response([
        'success' => false,
        'message' => 'Session is not in a running state (' . $sessionStatus . ').',
    ], 409);
}

$sessionId = (int)$session['id'];
$stationId = (int)$session['station_id'];

// --- Finalize: record materials/weight/points up to now, award, close ---
$con->begin_transaction();
try {
    // Audit trail: resident requested early end
    eco_log_event($con, $sessionId, $stationId, 'EARLY_END_REQUESTED', [
        'user_id'  => $userId,
        'source'   => 'RESIDENT',
    ], 'RESIDENT');

    $result = eco_award_points_and_finalize($con, $sessionId, $stationId);

    eco_log_event($con, $sessionId, $stationId, 'SESSION_ENDED_EARLY', [
        'awarded_points' => (float)$result['awarded_points'],
        'initiated_by'   => 'RESIDENT',
    ], 'RESIDENT');

    $con->commit();
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    eco_json_response([
        'success' => false,
        'message' => 'Failed to end session: ' . $e->getMessage(),
    ], 500);
}

eco_json_response([
    'success'           => true,
    'message'           => 'Session ended successfully',
    'session_status'    => 'COMPLETED',
    'awarded_points'    => (float)$result['awarded_points'],
    'new_balance'       => (float)$result['new_balance'],
    'cap_state_after'   => $result['cap_state_after'],
]);