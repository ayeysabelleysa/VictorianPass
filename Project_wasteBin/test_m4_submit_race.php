<?php
/**
 * VHEcoPoint M4 — stale session-status race on waste submission.
 * ------------------------------------------------------------
 * The bug: api/submit_waste_data.php read the session status BEFORE
 * starting its transaction (eco_load_session_for_station, no lock),
 * then locked the row but never re-read status. If complete_session.php
 * committed COMPLETED in that window, the submission still used the
 * stale ACTIVE status and would resurrect the finished session to
 * PROCESSING + insert a WASTE_DATA event into it.
 *
 * The fix: the endpoint now locks the session row by token+station and
 * derives the authorization decision from THAT locked row via the pure
 * helper eco_submission_decision(). A replayed transaction is answered
 * idempotently (duplicate-success, no insert) even after completion; a
 * NEW transaction against a closed session is rejected.
 *
 * Two layers, mirroring test_error_recovery_marker.php:
 *   1. RUNTIME unit tests of the REAL eco_submission_decision() matrix.
 *   2. Interleaving models: feed the helper the row as it would be seen
 *      AFTER the lock is acquired under each ordering, proving the
 *      decision depends only on the post-lock status.
 *   3. STATIC drift guards over the real PHP sources.
 *
 * NOTE: true multi-connection InnoDB locking (FOR UPDATE serialization)
 * cannot be exercised on a host with no MySQL; that requires a MySQL
 * integration test. These tests prove the deterministic decision logic
 * and that the endpoint sources hold the post-lock invariant.
 *
 * Run:  php Project_wasteBin/test_m4_submit_race.php
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$PASS = 0;
$FAIL = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "  PASS  $name\n";
    } else {
        $FAIL++;
        echo "  FAIL  $name  $detail\n";
    }
}

// ---------------------------------------------------------------------------
// Load the REAL ecopoint_core.php with the MySQL bootstrap (connect.php)
// removed, then load the REAL (DB-free) idempotency helpers. This lets the
// pure decision function and the constants run without a database.
// ---------------------------------------------------------------------------
$repoRoot = __DIR__ . '/..';
$srcFile  = $repoRoot . '/ecopoint_core.php';
$src = @file_get_contents($srcFile);
if ($src === false) {
    fwrite(STDERR, "FATAL: cannot read $srcFile\n");
    exit(1);
}

$src = (string)preg_replace(
    "/^require_once\s+__DIR__\s*\.\s*'\/connect\.php';\s*$/m",
    '',
    $src
);
$src = (string)preg_replace(
    "/^require_once\s+__DIR__\s*\.\s*'\/ecopoint_idempotency\.php';\s*$/m",
    '',
    $src
);

$tmp = tempnam(sys_get_temp_dir(), 'vh_ecore_m4_');
file_put_contents($tmp, $src);
try {
    require_once $tmp;
} finally {
    @unlink($tmp);
}
require_once $repoRoot . '/ecopoint_idempotency.php';

// ---------------------------------------------------------------------------
echo "== helpers loaded ==";
t('ECO_SESSION_STATUSES_OPEN is the OPEN set',
    defined('ECO_SESSION_STATUSES_OPEN')
    && ECO_SESSION_STATUSES_OPEN === ['WAITING', 'ACTIVE', 'PROCESSING']);
t('eco_submission_decision exists',
    function_exists('eco_submission_decision'));
t('eco_lock_session_for_station exists',
    function_exists('eco_lock_session_for_station'));

// ---------------------------------------------------------------------------
// Session-row factory. mysqli returns every column as a string, so the
// helper must cope with string station_id / status values too.
// ---------------------------------------------------------------------------
function sess(string $status, $stationId = 7, int $id = 101): array {
    return [
        'id'         => $id,
        'station_id' => $stationId,
        'status'     => $status,
        'user_id'    => 42,
    ];
}

$STATION = 7;

// =====================================================================
// 1. DECISION MATRIX (pure)
// =====================================================================
echo "== 1. decision matrix ==";

// --- 1. New submission while the session is OPEN (scenario 1) ---------
t('1a ACTIVE session, new tx -> insert',
    eco_submission_decision(sess('ACTIVE'), $STATION, false) === 'insert');
t('1b PROCESSING session, new tx -> insert',
    eco_submission_decision(sess('PROCESSING'), $STATION, false) === 'insert');
t('1c WAITING session, new tx -> insert',
    eco_submission_decision(sess('WAITING'), $STATION, false) === 'insert');

// --- 5. A NEW transaction submitted after completion ------------------
t('1d COMPLETED session, new tx -> reject (never insert)',
    eco_submission_decision(sess('COMPLETED'), $STATION, false) === 'reject');

// --- 4. Retrying an already-recorded transaction after completion -----
t('1e COMPLETED session, already-recorded tx -> duplicate',
    eco_submission_decision(sess('COMPLETED'), $STATION, true) === 'duplicate');

// --- 6. Invalid / canceled / ERROR sessions ---------------------------
t('1f CANCELLED session, new tx -> reject',
    eco_submission_decision(sess('CANCELLED'), $STATION, false) === 'reject');
t('1g ERROR session, new tx -> reject',
    eco_submission_decision(sess('ERROR'), $STATION, false) === 'reject');
t('1h CANCELLED session, recorded tx -> duplicate (no mutation)',
    eco_submission_decision(sess('CANCELLED'), $STATION, true) === 'duplicate');
t('1i ERROR session, recorded tx -> duplicate (no mutation)',
    eco_submission_decision(sess('ERROR'), $STATION, true) === 'duplicate');
t('1j unknown status, new tx -> reject',
    eco_submission_decision(sess('FOO'), $STATION, false) === 'reject');

// --- 7. Repeated transaction IDs / existing idempotency ---------------
t('1k OPEN session, repeated tx -> duplicate',
    eco_submission_decision(sess('PROCESSING'), $STATION, true) === 'duplicate');
t('1l OPEN ACTIVE session, repeated tx -> duplicate',
    eco_submission_decision(sess('ACTIVE'), $STATION, true) === 'duplicate');

// --- ownership / existence --------------------------------------------
t('1m null locked row (unknown token) -> reject',
    eco_submission_decision(null, $STATION, false) === 'reject');
t('1n foreign station row -> reject',
    eco_submission_decision(sess('ACTIVE', 99), $STATION, false) === 'reject');
t('1o foreign station + recorded tx -> reject (never leak)',
    eco_submission_decision(sess('COMPLETED', 99), $STATION, true) === 'reject');
t('1p string station_id from mysqli still matches -> insert',
    eco_submission_decision(sess('ACTIVE', '7'), $STATION, false) === 'insert');
t('1q lowercase status normalised -> reject',
    eco_submission_decision(sess('completed'), $STATION, false) === 'reject');

// =====================================================================
// 2. INTERLEAVING MODELS
//
// The decision is computed from the row observed AFTER the lock. We
// model the two orderings by handing the helper the post-lock row.
// =====================================================================
echo "== 2. interleaving models ==";

// Scenario 2: completion happens BEFORE the submission obtains its lock.
// Pre-lock read saw ACTIVE, but the locked read sees COMPLETED.
$preLockStatus      = 'ACTIVE';           // what the old code wrongly used
$postLockRow        = sess('COMPLETED');  // authoritative after the lock
t('2a completion-before-lock: new tx is rejected (no resurrect)',
    $preLockStatus !== $postLockRow['status']
    && eco_submission_decision($postLockRow, $STATION, false) === 'reject');
t('2b completion-before-lock: replay is acknowledged idempotently',
    eco_submission_decision($postLockRow, $STATION, true) === 'duplicate');
t('2c the pre-lock status string is irrelevant to the decision',
    eco_submission_decision($postLockRow, $STATION, false)
        === eco_submission_decision(sess('COMPLETED'), $STATION, false));

// Scenario 3: submission obtains the lock and commits BEFORE completion.
// The locked read still sees an OPEN session, so the item is ingested;
// completion later re-reads (FOR UPDATE) and aggregates this event.
$openRow = sess('PROCESSING');
t('3a submission-before-completion: open session -> insert',
    eco_submission_decision($openRow, $STATION, false) === 'insert');
t('3b after the insert, a replayed retry -> duplicate',
    eco_submission_decision($openRow, $STATION, true) === 'duplicate');

// =====================================================================
// 3. STATIC drift guards over the real sources
// =====================================================================
echo "== 3. source drift guards ==";

$submit = @file_get_contents($repoRoot . '/api/submit_waste_data.php') ?: '';
$idem   = @file_get_contents($repoRoot . '/ecopoint_idempotency.php') ?: '';
$core   = @file_get_contents($srcFile) ?: '';
$comp   = @file_get_contents($repoRoot . '/api/complete_session.php') ?: '';

// Endpoint must derive authorization from the locked row.
t('3a submit locks the session by token+station under the transaction',
    strpos($submit, 'eco_lock_session_for_station($con, $station, $token)') !== false);
t('3b submit calls the pure decision helper',
    strpos($submit, 'eco_submission_decision($locked, $stationId, $txAlreadyRecorded)') !== false);
t('3c submit reads status from the LOCKED row',
    strpos($submit, "strtoupper((string)\$locked['status'])") !== false);
t('3d submit no longer authorizes from the pre-lock session row',
    strpos($submit, "\$session['status']") === false);
t('3e submit no longer uses the pre-lock $session for user_id',
    strpos($submit, "\$session['user_id']") === false);
t('3f the duplicate check runs against the LOCKED session id',
    strpos($submit, "(int)\$locked['id'],") !== false);

// Stale-session timeout recovery must still be driven before the lock.
t('3g submit still invokes the pre-lock loader (stale recovery)',
    strpos($submit, 'eco_load_session_for_station($con, $station, $token, true);') !== false);

// Response contract must be unchanged.
t('3h reject still returns the original 404 contract message',
    strpos($submit,
        "'message' => 'Session not found, already finalized, or does not belong to this station',"
    ) !== false);
t('3i new-item success response unchanged (session_status PROCESSING)',
    strpos($submit, "'session_status'     => 'PROCESSING',") !== false);
t('3j duplicate response unchanged (idempotent replay note)',
    strpos($submit, "'note'           => 'Transaction already recorded (idempotent replay).',") !== false);
t('3k WASTE_DATA event insert preserved',
    strpos($submit, "'WASTE_DATA'") !== false);
t('3l ACTIVE -> PROCESSING transition preserved',
    strpos($submit, "eco_transition_status(\$con, \$sessionId, \$stationId, 'PROCESSING')") !== false);

// Helper lock must be FOR UPDATE and read the full row.
t('3m lock helper uses SELECT * ... FOR UPDATE',
    strpos($idem, 'FOR UPDATE') !== false
    && strpos($idem, 'SELECT *') !== false);
t('3n lock helper scopes by session_token AND station_id',
    strpos($idem, 'session_token = ?') !== false
    && strpos($idem, 'station_id    = ?') !== false);
t('3o decision honours the OPEN status set',
    strpos($idem, 'in_array($status, ECO_SESSION_STATUSES_OPEN, true)') !== false);
t('3p decision roots ownership in the locked row',
    strpos($idem, "(\$lockedSession['station_id'] ?? 0)") !== false);

// The completion path must keep serializing on the same row.
t('3q completion award still locks the session FOR UPDATE',
    strpos($core, 'SELECT * FROM ecopoint_waste_sessions WHERE id = ? FOR UPDATE') !== false);
t('3r completion still calls the idempotent award path',
    strpos($comp, 'eco_award_points_and_finalize($con, $sessionId, $stationId)') !== false);

echo str_repeat('=', 60) . "\n";
echo "RESULT: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
