<?php
/**
 * VHEcoPoint item-level idempotency helpers
 * ------------------------------------------------------------
 * The Raspberry Pi generates a stable transaction_id BEFORE the
 * first waste-submission attempt and reuses it for every retry.
 * These helpers let submit_waste_data.php reject a replayed
 * request (e.g. the Pi retried after an ambiguous timeout) so a
 * physically-processed item can never be counted twice.
 *
 * This file is intentionally dependency-free (no connect.php) so
 * the pure extraction helper can be unit-tested in isolation.
 */
declare(strict_types=1);

/**
 * Extract a stable transaction id from the request body.
 * Prefers the explicit top-level field, and falls back to the
 * nested raw_hardware_data copy. Returns '' when absent (legacy
 * hardware), in which case callers must skip de-duplication.
 */
function eco_extract_transaction_id(array $input): string {
    $txId = trim((string)($input['transaction_id'] ?? ''));

    if (
        $txId === ''
        && isset($input['raw_hardware_data'])
        && is_array($input['raw_hardware_data'])
    ) {
        $txId = trim((string)($input['raw_hardware_data']['transaction_id'] ?? ''));
    }

    // Keep it a safe, bounded token (uuid4 hex is 32 chars).
    $txId = preg_replace('/[^A-Za-z0-9._:\-]/', '', $txId) ?? '';

    if (strlen($txId) > 64) {
        $txId = substr($txId, 0, 64);
    }

    return $txId;
}

/**
 * Lock + return the session row for a token owned by this station,
 * regardless of its status. session_token is globally UNIQUE, so the
 * locked row is unambiguous and the station_id predicate enforces
 * ownership.
 *
 * MUST be called inside the caller's transaction. Because this reads
 * the row AFTER acquiring the FOR UPDATE lock, it observes the status
 * left by whichever operation (submission / completion / cancel /
 * error) committed first -- the authoritative status to authorize a
 * new WASTE_DATA insert against.
 */
function eco_lock_session_for_station(mysqli $con, array $station, string $token): ?array {
    $token     = trim($token);
    $stationId = (int)($station['station_id'] ?? 0);

    if ($token === '' || $stationId <= 0) {
        return null;
    }

    $stmt = $con->prepare("
        SELECT *
        FROM   ecopoint_waste_sessions
        WHERE  session_token = ?
          AND  station_id    = ?
        LIMIT  1
        FOR UPDATE
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('si', $token, $stationId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * Decide what a waste submission may do, given the session row read
 * UNDER THE LOCK. Pure (no DB) so the authorization matrix is unit
 * testable without MySQL.
 *
 * Returns:
 *   'insert'    -> the locked session is still OPEN; the caller may
 *                  transition it and record a new WASTE_DATA event.
 *   'duplicate' -> the exact transaction_id is already recorded for
 *                  this session+station. The caller must return the
 *                  existing duplicate-success response and MUST NOT
 *                  insert or award again, even if the session has since
 *                  become COMPLETED/CANCELLED/ERROR (a replayed retry
 *                  of an already-accepted item).
 *   'reject'    -> unknown/foreign session, or a non-open session with
 *                  no matching recorded transaction (a NEW transaction
 *                  against a closed session must never be authorized).
 *
 * The status/ownership are taken from $lockedSession (post-lock), never
 * from any pre-lock read.
 */
function eco_submission_decision(?array $lockedSession, int $stationId, bool $txAlreadyRecorded): string {
    if ($lockedSession === null) {
        return 'reject';
    }

    if ((int)($lockedSession['station_id'] ?? 0) !== $stationId) {
        return 'reject';
    }

    // A previously accepted transaction is always safe to acknowledge:
    // the item is already recorded, so no insert/award can happen twice.
    if ($txAlreadyRecorded) {
        return 'duplicate';
    }

    $status = strtoupper((string)($lockedSession['status'] ?? ''));
    if (in_array($status, ECO_SESSION_STATUSES_OPEN, true)) {
        return 'insert';
    }

    return 'reject';
}

/**
 * Decide whether a completion request may finalize/award, given the
 * session row read UNDER THE LOCK (eco_lock_session_for_station()).
 * Pure (no DB) so the authorization rule is unit testable without MySQL.
 *
 * The endpoint's earlier, pre-lock eco_load_session_for_station() read is
 * NOT authoritative: between that read and the row lock a concurrent
 * complete/cancel/error request may have transitioned the session. This
 * decision is therefore computed only from the post-lock row.
 *
 * Returns:
 *   'complete' -> the locked session is still OPEN (WAITING/ACTIVE/
 *                 PROCESSING) and owned by this station; the caller may
 *                 take the final snapshot, award and mark COMPLETED.
 *   'reject'   -> anything else: unknown/foreign token, or a session
 *                 already finalized (COMPLETED/CANCELLED) or left ERROR
 *                 by another request. The caller MUST NOT change the
 *                 status or award points; it answers the existing
 *                 non-eligible contract (and may still attempt the M1
 *                 ERROR retry-recovery path).
 */
function eco_completion_decision(?array $lockedSession, int $stationId): string {
    if ($lockedSession === null) {
        return 'reject';
    }

    if ((int)($lockedSession['station_id'] ?? 0) !== $stationId) {
        return 'reject';
    }

    $status = strtoupper((string)($lockedSession['status'] ?? ''));
    if (in_array($status, ECO_SESSION_STATUSES_OPEN, true)) {
        return 'complete';
    }

    return 'reject';
}

/**
 * Decide whether a CANCEL / hardware-ERROR report may transition the
 * session, given the row read UNDER THE LOCK
 * (eco_lock_session_for_station()). Pure (no DB) so the rule is unit
 * testable without MySQL.
 *
 * Both endpoints are "closing" transitions that may only apply while the
 * session is still OPEN and owned by this station. A stale pre-lock read
 * must never resurrect or overwrite a terminal state (COMPLETED /
 * CANCELLED) or a session already left ERROR by another request.
 *
 * Returns true only when the locked session is owned by this station and
 * still in ECO_SESSION_STATUSES_OPEN. The caller must additionally apply
 * the transition with eco_transition_status(..., ECO_SESSION_STATUSES_OPEN)
 * so the check and the write are one atomic UPDATE.
 */
function eco_terminal_transition_allowed(?array $lockedSession, int $stationId): bool {
    if ($lockedSession === null) {
        return false;
    }

    if ((int)($lockedSession['station_id'] ?? 0) !== $stationId) {
        return false;
    }

    $status = strtoupper((string)($lockedSession['status'] ?? ''));
    return in_array($status, ECO_SESSION_STATUSES_OPEN, true);
}

/**
 * Return the existing WASTE_DATA event row for this transaction id,
 * or null when none exists. Checks both the nested raw copy and the
 * top-level copy of the transaction id.
 *
 * MUST be called after the session row has been locked FOR UPDATE
 * (eco_lock_session_for_station()) so the result cannot change before
 * the caller inserts.
 */
function eco_find_waste_event_by_transaction(mysqli $con, int $sessionId, string $txId): ?array {
    if ($txId === '') {
        return null;
    }

    $stmt = $con->prepare("
        SELECT id, event_payload, created_at
        FROM ecopoint_session_events
        WHERE session_id = ?
          AND event_type = 'WASTE_DATA'
          AND (
                JSON_UNQUOTE(JSON_EXTRACT(event_payload, '$.raw.transaction_id')) = ?
             OR JSON_UNQUOTE(JSON_EXTRACT(event_payload, '$.transaction_id')) = ?
          )
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('iss', $sessionId, $txId, $txId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
