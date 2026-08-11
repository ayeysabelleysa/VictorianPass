<?php
/**
 * Endpoint 2 — Submit Waste Data (during processing)
 * --------------------------------------------------------
 * Called by hardware repeatedly as waste is being processed.
 * Updates the session's material/weight snapshot + records a
 * WASTE_DATA event in the audit log. Backend never trusts the
 * "points" value from hardware — it always recalculates.
 *
 * Auth:      X-VHECO-Station + X-VHECO-Api-Key headers
 * Method:    POST
 * JSON Body: {
 *              "session_token": "...",
 *              "material": "Plastic|Aluminum|Paper|Cardboard",
 *              "weight_kg": 1.25,
 *              "raw_hardware_data": {...}   (optional, any sensor telemetry)
 *            }
 * Response:  { "success": true, "session_status": "PROCESSING",
 *              "points_calculated": 83, "cap_state": {...} }
 */
require_once __DIR__ . '/../ecopoint_core.php';

eco_require_method('POST');
$station  = eco_authenticate_station($con);
$input    = eco_read_json_input();

$token    = trim((string)($input['session_token'] ?? ''));
$material = trim((string)($input['material']      ?? ''));
$weight   = (float)($input['weight_kg']           ?? 0);
$rawData  = $input['raw_hardware_data'] ?? null;

if ($token === '' || $material === '' || $weight <= 0) {
    eco_json_response([
        'success' => false,
        'message' => 'session_token, material, and weight_kg (positive) are required',
    ], 400);
}
if (!in_array($material, ECO_ALLOWED_MATERIALS, true)) {
    eco_json_response([
        'success'  => false,
        'message'  => 'Unknown material. Allowed: ' . implode(', ', ECO_ALLOWED_MATERIALS),
        'allowed'  => ECO_ALLOWED_MATERIALS,
    ], 400);
}

$session = eco_load_session_for_station($con, $station, $token, true);
if (!$session) {
    eco_json_response([
        'success' => false,
        'message' => 'Session not found, already finalized, or does not belong to this station',
    ], 404);
}

$sessionId = (int)$session['id'];
$stationId = (int)$station['station_id'];

$con->begin_transaction();
try {
    // Move to PROCESSING (if still ACTIVE)
    if ((string)$session['status'] === 'ACTIVE') {
        eco_transition_status($con, $sessionId, $stationId, 'PROCESSING');
        eco_log_event($con, $sessionId, $stationId, 'STATE_CHANGE', [
            'from' => 'ACTIVE', 'to' => 'PROCESSING'
        ], 'BACKEND');
    }

    $calc = eco_calculate_points($material, $weight);

    // Update session snapshot
    $stmt = $con->prepare("
        UPDATE ecopoint_waste_sessions
        SET    material_type     = ?,
               weight_kg         = ?,
               points_calculated = ?,
               raw_hardware_data = ?
        WHERE  id = ?
    ");
    $rawJson = ($rawData === null) ? null : json_encode($rawData, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param(
        'sdisi',
        $calc['material'],
        $calc['weight_kg'],
        $calc['raw_points'],
        $rawJson,
        $sessionId
    );
    $stmt->execute();
    $stmt->close();

    eco_log_event($con, $sessionId, $stationId, 'WASTE_DATA', [
        'material'        => $calc['material'],
        'weight_kg'       => $calc['weight_kg'],
        'rate_pts_per_kg' => $calc['rate_pts_per_kg'],
        'points_calc'     => $calc['raw_points'],
        'raw'             => $rawData,
    ], 'HARDWARE');

    $capState = eco_resident_cap_state($con, (int)$session['user_id']);

    $con->commit();

    eco_json_response([
        'success'            => true,
        'session_status'     => 'PROCESSING',
        'material'           => $calc['material'],
        'weight_kg'          => $calc['weight_kg'],
        'rate_pts_per_kg'    => $calc['rate_pts_per_kg'],
        'points_calculated'  => $calc['raw_points'],
        'cap_state'          => $capState,
        'note'               => 'Points will be capped at finalization per daily/weekly/balance rules.',
    ]);
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    eco_json_response(['success' => false, 'message' => 'Submit failed: ' . $e->getMessage()], 500);
}
