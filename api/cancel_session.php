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

$stationId = (int)$station['station_id'];

// Pre-lock lookup, OUTSIDE the transaction, only to drive the server-side
// stale-session timeout recovery exactly as before. Its result is NOT
// authoritative: the row is re-read and locked under the transaction below,
// so a concurrent completion cannot be overwritten by a stale cancel.
eco_load_session_for_station($con, $station, $token, true);

$con->begin_transaction();
try {
    // Re-read + lock the session by token AND station ownership.
    $locked = eco_lock_session_for_station($con, $station, $token);

    // Cancel only while the session is still OPEN and owned by this station.
    // The allowed-from guard applies the check and the write as ONE atomic
    // UPDATE: if a concurrent completion (or error) already committed, the
    // row is no longer OPEN -> 0 rows -> the stale cancel must not overwrite.
    $applied = eco_terminal_transition_allowed($locked, $stationId)
        && eco_transition_status(
            $con,
            (int)$locked['id'],
            $stationId,
            'CANCELLED',
            null,
            ECO_SESSION_STATUSES_OPEN
        );

    if (!$applied) {
        $con->rollback();
        eco_json_response([
            'success' => false,
            'message' => 'Session not found or already finalized',
        ], 404);
    }

    $sessionId = (int)$locked['id'];
    eco_log_event($con, $sessionId, $stationId, 'CANCELLED', [
        'reason'     => $reason,
        'material'   => $locked['material_type'] ?? null,
        'weight_kg'  => $locked['weight_kg'] ?? 0,
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
