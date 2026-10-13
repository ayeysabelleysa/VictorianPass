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

$stationId = (int)$station['station_id'];
$rawData   = $input['raw_hardware_data'] ?? null;
$sessionId = 0;

// ---------------------------------------------------------------------
// Pre-lock lookup. This drives the server-side stale-session timeout
// recovery exactly as before, but its result is intentionally NOT used
// to authorize anything below: the authoritative status is re-read UNDER
// THE ROW LOCK, because a concurrent complete/cancel/error request could
// finalize the session in the window between this read and the lock.
// ---------------------------------------------------------------------
eco_load_session_for_station($con, $station, $token, true);

$con->begin_transaction();
try {
    // -----------------------------------------------------------------
    // Re-read + lock the session by its token AND this station's
    // ownership. Whatever operation held the row lock first has already
    // committed, so this observes the true current status.
    // -----------------------------------------------------------------
    $locked = eco_lock_session_for_station($con, $station, $token);

    if (eco_completion_decision($locked, $stationId) !== 'complete') {
        // The session is not (or is no longer) eligible for completion:
        // unknown/foreign token, or a COMPLETED / CANCELLED / ERROR
        // session. Another request may have finalized it first — never
        // overwrite the newer state.
        $con->rollback();

        // -------------------------------------------------------------
        // RETRY RECOVERY AFTER A FAILED FINALIZATION (M1)
        //
        // A previous complete request may have failed AFTER rollback by
        // marking this session ERROR (eco_load_session_for_station only
        // returns OPEN statuses, so the session "disappears" and would
        // otherwise strand the resident's recorded points under a 404).
        //
        // Recovery is bounded and idempotent: only an ERROR session owned
        // by this station whose error_message carries the finalize marker
        // (i.e. a finalize failure, NOT a deliberate hardware abort) and
        // that still has awardable WASTE_DATA events may be completed.
        // eco_award_points_and_finalize() locks the row and guards on
        // points_posted, so points can never be awarded twice.
        // -------------------------------------------------------------
        try {
            $recovery = eco_finalize_error_session($con, $station, $token);
        } catch (Throwable $e) {
            eco_json_response([
                'success' => false,
                'message' => 'Finalize recovery failed: ' . $e->getMessage(),
            ], 500);
        }

        if ($recovery === null) {
            eco_json_response([
                'success' => false,
                'message' => 'Session not found or not eligible for completion',
            ], 404);
        }

        $session     = $recovery['session'];
        $awardResult = $recovery['result'];

        eco_json_response([
            'success'               => true,
            'session_status'        => 'COMPLETED',
            'material'              => (string)($session['material_type'] ?? ''),
            'weight_kg'             => (float)($session['weight_kg'] ?? 0),
            'points_calculated'     => (float)($session['points_calculated'] ?? 0),
            'points_awarded'        => (float)$awardResult['awarded_points'],
            'new_balance'           => (float)$awardResult['new_balance'],
            'cap_state_after'       => $awardResult['cap_state_after'],
            'max_balance'           => (float)($awardResult['max_balance'] ?? ECO_MAX_BALANCE),
            'points_limit_reached'  => (bool)($awardResult['points_limit_reached'] ?? false),
            'points_limit_message'  => (string)($awardResult['points_limit_message'] ?? ''),
        ]);
    }

    $sessionId = (int)$locked['id'];

    // Allow override of final material/weight by hardware, else use the
    // authoritative (locked) session snapshot.
    $material = trim((string)($input['final_material'] ?? (string)($locked['material_type'] ?? '')));
    $weight   = (float)($input['final_weight_kg'] ?? (float)($locked['weight_kg'] ?? 0));

    if ($material === '' || !in_array($material, ECO_ALLOWED_MATERIALS, true)) {
        $con->rollback();
        eco_json_response([
            'success' => false,
            'message' => 'Invalid or missing material',
            'allowed' => ECO_ALLOWED_MATERIALS,
        ], 400);
    }
    if ($weight <= 0) {
        // Try to cancel gracefully if there's no weight. Done under the
        // same row lock so a concurrent completion cannot be clobbered.
        try {
            eco_transition_status($con, $sessionId, $stationId, 'CANCELLED');
            eco_log_event($con, $sessionId, $stationId, 'CANCELLED', [
                'reason' => 'zero_weight_on_complete'
            ], 'BACKEND');
            $con->commit();
        } catch (Throwable $_) {
            try { $con->rollback(); } catch (Throwable $__) {}
        }
        eco_json_response([
            'success' => false,
            'message' => 'Session cancelled: weight is zero (nothing recycled).',
            'session_status' => 'CANCELLED',
        ], 400);
    }

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
        'points_awarded'     => (float)$awardResult['awarded_points'],
        'new_balance'        => (float)$awardResult['new_balance'],
        'cap_state_after'    => $awardResult['cap_state_after'],
        'max_balance'        => (float)($awardResult['max_balance'] ?? ECO_MAX_BALANCE),
        'points_limit_reached' => (bool)($awardResult['points_limit_reached'] ?? false),
        'points_limit_message' => (string)($awardResult['points_limit_message'] ?? ''),
    ]);
} catch (Throwable $e) {
    try { $con->rollback(); } catch (Throwable $_) {}
    try {
        // Mark the session ERROR so we don't leave it in PROCESSING and a
        // retry can recover it (M1). The transition is guarded to the
        // still-OPEN statuses so a genuine finalize failure can never
        // overwrite a COMPLETED/CANCELLED state committed by a concurrent
        // request after our rollback released the row lock.
        if (eco_transition_status(
            $con,
            $sessionId,
            $stationId,
            'ERROR',
            ECO_FINALIZE_ERROR_MARKER . $e->getMessage(),
            ECO_SESSION_STATUSES_OPEN
        )) {
            eco_log_event($con, $sessionId, $stationId, 'ERROR', [
                'stage' => 'complete_session',
                'error' => $e->getMessage(),
            ], 'BACKEND');
        }
    } catch (Throwable $e2) {}
    eco_json_response(['success' => false, 'message' => 'Finalize failed: ' . $e->getMessage()], 500);
}
