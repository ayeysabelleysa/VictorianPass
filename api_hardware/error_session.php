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
$message  = trim((string)($input['error_message'] ?? 'Unknown hardware error'));
$code     = trim((string)($input['error_code'] ?? ''));
$payload  = $input['payload'] ?? null;

if ($message === '') {
    eco_json_response(['success' => false, 'message' => 'error_message is required'], 400);
}

$con->begin_transaction();
try {
    $stationId = (int)$station['station_id'];

    if ($token !== '') {
        $session = eco_load_session_for_station($con, $station, $token, true);
        if ($session) {
            $sessionId = (int)$session['id'];
            eco_transition_status($con, $sessionId, $stationId, 'ERROR', $message);
            eco_log_event($con, $sessionId, $stationId, 'ERROR', [
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
