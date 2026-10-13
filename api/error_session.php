<?php
/**
 * Endpoint 5 — Report Hardware / Session Error
 * --------------------------------------------------------
 * Hardware can call this any time a hardware fault occurs
 * (sensor failure, scale error, bin full, etc.). Mark the
 * session ERROR so it won't award points but is still auditable.
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');
$station  = eco_authenticate_station($con);
$input    = eco_read_json_input();

$token    = trim((string)($input['session_token'] ?? ''));
$message  = eco_normalize_hardware_error_message((string)($input['error_message'] ?? 'Unknown hardware error'));
$code     = trim((string)($input['error_code'] ?? ''));
$payload  = $input['payload'] ?? null;

if ($message === '') {
    eco_json_response(['success' => false, 'message' => 'error_message is required'], 400);
}

$stationId = (int)$station['station_id'];

// Pre-lock lookup, OUTSIDE the transaction. This drives the server-side
// stale-session timeout recovery, which starts its OWN transaction; doing it
// inside ours would issue a nested begin_transaction (an implicit commit of
// this transaction in MySQL). Its result is NOT authoritative: the row is
// re-read and locked under the transaction below.
if ($token !== '') {
    eco_load_session_for_station($con, $station, $token, true);
}

$con->begin_transaction();
try {
    if ($token !== '') {
        // Re-read + lock the session by token AND station ownership.
        $locked = eco_lock_session_for_station($con, $station, $token);

        // Mark ERROR only while the session is still OPEN and owned by this
        // station. The allowed-from guard makes the check and the write ONE
        // atomic UPDATE: if a concurrent completion/cancel already committed,
        // the row is no longer OPEN -> 0 rows -> never overwritten, and no
        // ERROR event is recorded for the stale report.
        $applied = eco_terminal_transition_allowed($locked, $stationId)
            && eco_transition_status(
                $con,
                (int)$locked['id'],
                $stationId,
                'ERROR',
                $message,
                ECO_SESSION_STATUSES_OPEN
            );

        if ($applied) {
            eco_log_event($con, (int)$locked['id'], $stationId, 'ERROR', [
                'error_code'    => $code,
                'error_message' => $message,
                'payload'       => $payload,
            ], 'HARDWARE');
        }
    } else {
        // Station-level error without an active session — write event with dummy session_id?
        // Skip writing to session_events since there's no session; we'll just heartbeat the station.
        $stmt = $con->prepare("UPDATE ecopoint_stations SET last_heartbeat_at = NOW() WHERE id = ?");
        $stmt && $stmt->bind_param('i', $stationId) && @$stmt->execute();
    }

    $con->commit();
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    eco_json_response(['success' => false, 'message' => 'Report failed: ' . $e->getMessage()], 500);
}

eco_json_response([
    'success'        => true,
    'reported_at'    => date('Y-m-d H:i:s'),
    'error_code'     => $code,
    'error_message'  => $message,
]);
