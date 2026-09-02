<?php
/**
 * Endpoint 4 — Cancel Session
 * --------------------------------------------------------
 * Hardware-triggered cancel:
 *   - user walked away,
 *   - invalid material,
 *   - waste container jam / timeout,
 *   - station abort,
 *   - etc.
 *
 * Does NOT award points. Leaves session as CANCELLED for audit.
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');
$station  = eco_authenticate_station($con);
$input    = eco_read_json_input();

$token    = trim((string)($input['session_token'] ?? ''));
$reason   = trim((string)($input['reason'] ?? 'hardware_cancelled'));
if ($token === '') {
    eco_json_response(['success' => false, 'message' => 'session_token is required'], 400);
}

$session = eco_load_session_for_station($con, $station, $token, true);
if (!$session) {
    eco_json_response([
        'success' => false,
        'message' => 'Session not found or already finalized',
    ], 404);
}

$sessionId = (int)$session['id'];
$stationId = (int)$station['station_id'];

$con->begin_transaction();
try {
    eco_transition_status($con, $sessionId, $stationId, 'CANCELLED');
    eco_log_event($con, $sessionId, $stationId, 'CANCELLED', [
        'reason'     => $reason,
        'material'   => $session['material_type'] ?? null,
        'weight_kg'  => $session['weight_kg'] ?? 0,
    ], 'HARDWARE');
    $con->commit();
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    eco_json_response(['success' => false, 'message' => 'Cancel failed: ' . $e->getMessage()], 500);
}

eco_json_response([
    'success'        => true,
    'session_status' => 'CANCELLED',
    'cancelled_at'   => date('Y-m-d H:i:s'),
    'reason'         => $reason,
]);
