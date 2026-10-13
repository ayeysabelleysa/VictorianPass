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
 *              "transaction_id": "...",     (optional but recommended:
 *                                            idempotency key, reused
 *                                            on every retry)
 *              "raw_hardware_data": {...}   (optional, any sensor telemetry)
 *            }
 * Response:  { "success": true, "session_status": "PROCESSING",
 *              "duplicate": false, "transaction_id": "...",
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

// Stable client-generated id (empty for legacy callers that do not
// send one) used to make item submission idempotent.
$txId     = eco_extract_transaction_id($input);

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

// Pre-lock lookup. This drives the server-side stale-session timeout
// recovery exactly as before (and returns the normal fast path for open
// sessions). Its result is intentionally NOT used to authorize the
// insert: the authoritative status is re-read UNDER THE ROW LOCK below,
// because a concurrent complete/cancel/error could finalize the session
// in the window between this read and the lock.
eco_load_session_for_station($con, $station, $token, true);

$con->begin_transaction();
try {
    $stationId = (int)$station['station_id'];

    // Re-read the session row AFTER acquiring the lock (token + station
    // scoped, so ownership is enforced here too). Whatever operation held
    // the row lock first has already committed, so this observes the true
    // current status -- never the stale pre-lock snapshot.
    $locked = eco_lock_session_for_station($con, $station, $token);

    // -------------------------------------------------------------
    // Item-level idempotency, evaluated against the LOCKED row.
    // If this exact transaction_id was already recorded for this
    // session, the item was already counted. Acknowledge it WITHOUT
    // inserting a second WASTE_DATA event -- even when the session has
    // since completed -- so a retry after a lost response can never
    // double-award points.
    // -------------------------------------------------------------
    $txAlreadyRecorded = false;
    if ($locked !== null && $txId !== '') {
        $txAlreadyRecorded = eco_find_waste_event_by_transaction(
            $con,
            (int)$locked['id'],
            $txId
        ) !== null;
    }

    $decision = eco_submission_decision($locked, $stationId, $txAlreadyRecorded);

    if ($decision === 'reject') {
        // Unknown/foreign token, or a non-open (COMPLETED/CANCELLED/
        // ERROR) session with no matching recorded transaction. Never
        // mutate it. Same 404 contract as before.
        $con->rollback();
        eco_json_response([
            'success' => false,
            'message' => 'Session not found, already finalized, or does not belong to this station',
        ], 404);
    }

    $sessionId = (int)$locked['id'];
    $status    = strtoupper((string)$locked['status']);

    if ($decision === 'duplicate') {
        $dupCapState = eco_resident_cap_state($con, (int)$locked['user_id']);
        $con->commit();
        eco_json_response([
            'success'        => true,
            'duplicate'      => true,
            'transaction_id' => $txId,
            'session_status' => $status,
            'cap_state'      => $dupCapState,
            'note'           => 'Transaction already recorded (idempotent replay).',
        ]);
    }

    // decision === 'insert': the LOCKED status is still open.
    // Move to PROCESSING (if still ACTIVE).
    if ($status === 'ACTIVE') {
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
        'sddsi',
        $calc['material'],
        $calc['weight_kg'],
        $calc['raw_points'],
        $rawJson,
        $sessionId
    );
    $stmt->execute();
    $stmt->close();

    eco_log_required_event($con, $sessionId, $stationId, 'WASTE_DATA', [
        'material'        => $calc['material'],
        'weight_kg'       => $calc['weight_kg'],
        'rate_pts_per_kg' => $calc['rate_pts_per_kg'],
        'points_calc'     => $calc['raw_points'],
        'transaction_id'  => $txId,
        'raw'             => $rawData,
    ], 'HARDWARE');

    $capState = eco_resident_cap_state($con, (int)$locked['user_id']);

    $con->commit();

    eco_json_response([
        'success'            => true,
        'duplicate'          => false,
        'transaction_id'     => $txId,
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
