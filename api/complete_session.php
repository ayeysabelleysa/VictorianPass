<?php
/**
 * Endpoint 3 — Complete Session (Finalize + Award Points)
 * --------------------------------------------------------
 * Called by hardware AFTER all waste has been fully processed.
 * - Validates material/weight are sensible
 * - Runs the cap rules (daily / weekly / max balance)
 * - Inserts a point_transactions row, updates users.points
 * - Marks session COMPLETED + points_posted = 1 (double-award guard)
 * - Everything is transactional + row-locked for idempotency.
 *
 * Auth:      X-VHECO-Station + X-VHECO-Api-Key headers
 * Method:    POST
 * JSON Body: {
 *              "session_token": "...",
 *              "final_material": "Plastic",        (optional — use latest snapshot if missing)
 *              "final_weight_kg": 1.25,            (optional — use latest snapshot if missing)
 *              "raw_hardware_data": {...}          (optional final telemetry)
 *            }
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');
$station  = eco_authenticate_station($con);
$input    = eco_read_json_input();

$token    = trim((string)($input['session_token'] ?? ''));
if ($token === '') {
    eco_json_response(['success' => false, 'message' => 'session_token is required'], 400);
}

$session = eco_load_session_for_station($con, $station, $token, true);
if (!$session) {
    eco_json_response([
        'success' => false,
        'message' => 'Session not found or not eligible for completion',
    ], 404);
}

$sessionId = (int)$session['id'];
$stationId = (int)$station['station_id'];

// Allow override of final material/weight by hardware, else use session snapshot
$material = trim((string)($input['final_material'] ?? (string)($session['material_type'] ?? '')));
$weight   = (float)($input['final_weight_kg'] ?? (float)($session['weight_kg'] ?? 0));
$rawData  = $input['raw_hardware_data'] ?? null;

if ($material === '' || !in_array($material, ECO_ALLOWED_MATERIALS, true)) {
    eco_json_response([
        'success' => false,
        'message' => 'Invalid or missing material',
        'allowed' => ECO_ALLOWED_MATERIALS,
    ], 400);
}
if ($weight <= 0) {
    // Try to cancel gracefully if there's no weight
    $con->begin_transaction();
    try {
        eco_transition_status($con, $sessionId, $stationId, 'CANCELLED');
        eco_log_event($con, $sessionId, $stationId, 'CANCELLED', [
            'reason' => 'zero_weight_on_complete'
        ], 'BACKEND');
        $con->commit();
    } catch (Throwable $_) {}
    eco_json_response([
        'success' => false,
        'message' => 'Session cancelled: weight is zero (nothing recycled).',
        'session_status' => 'CANCELLED',
    ], 400);
}

$con->begin_transaction();
try {
    // Store final material/weight snapshot
    $calc = eco_calculate_points($material, $weight);
    $upd = $con->prepare("
        UPDATE ecopoint_waste_sessions
        SET    material_type     = ?,
               weight_kg         = ?,
               points_calculated = ?,
               raw_hardware_data = COALESCE(?, raw_hardware_data)
        WHERE  id = ?
    ");
    $rawJson = ($rawData === null) ? null : json_encode($rawData, JSON_UNESCAPED_UNICODE);
    $upd->bind_param(
        'sdisi',
        $calc['material'],
        $calc['weight_kg'],
        $calc['raw_points'],
        $rawJson,
        $sessionId
    );
    $upd->execute();
    $upd->close();

    // Idempotent award + mark complete
    $awardResult = eco_award_points_and_finalize($con, $sessionId, $stationId);

    $con->commit();

    eco_json_response([
        'success'            => true,
        'session_status'     => 'COMPLETED',
        'material'           => $calc['material'],
        'weight_kg'          => $calc['weight_kg'],
        'points_calculated'  => $calc['raw_points'],
        'points_awarded'     => (int)$awardResult['awarded_points'],
        'new_balance'        => (int)$awardResult['new_balance'],
        'cap_state_after'    => $awardResult['cap_state_after'],
    ]);
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    try {
        // Attempt to mark session ERROR so we don't leave it in PROCESSING
        eco_transition_status($con, $sessionId, $stationId, 'ERROR', 'finalize_error: ' . $e->getMessage());
        eco_log_event($con, $sessionId, $stationId, 'ERROR', [
            'stage' => 'complete_session',
            'error' => $e->getMessage(),
        ], 'BACKEND');
    } catch (Throwable $e2) {}
    eco_json_response(['success' => false, 'message' => 'Finalize failed: ' . $e->getMessage()], 500);
}
