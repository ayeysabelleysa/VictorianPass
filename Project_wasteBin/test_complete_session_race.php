<?php
/**
 * VHEcoPoint — completion-status TOCTOU race on session completion.
 * ------------------------------------------------------------
 * The bug: api/complete_session.php read the session with
 * eco_load_session_for_station() BEFORE starting its transaction (no
 * lock), then updated the snapshot and awarded points without ever
 * re-checking the status UNDER the row lock. If a concurrent
 * cancel_session.php / error_session.php (or a second complete) committed
 * COMPLETED / CANCELLED / ERROR in that window, completion would still:
 *   - overwrite the final material/weight snapshot, and
 *   - set the status back to COMPLETED and award points,
 * clobbering the newer state (a cancelled/errored session could be
 * resurrected and paid out).
 *
 * The fix: the completion endpoint now opens its transaction, locks the
 * session row by token+station via eco_lock_session_for_station(), and
 * derives the authorization to finalize from THAT locked row through the
 * pure helper eco_completion_decision(). If the session is no longer OPEN
 * it is never mutated: the request answers the existing not-eligible
 * contract (and may still run the bounded M1 ERROR retry-recovery path).
 * The failure handler's ERROR stamp is itself guarded to the OPEN statuses
 * so it cannot overwrite a state committed by another request.
 *
 * Layers, mirroring test_m4_submit_race.php:
 *   1. RUNTIME unit tests of the REAL eco_completion_decision() matrix.
 *   2. Interleaving models: feed the helper the row as it would be seen
 *      AFTER the lock under each ordering, proving the decision depends
 *      only on the post-lock status (stale status, cancel/complete,
 *      error/complete, repeated completion).
 *   3. STATIC drift guards over the real PHP sources.
 *
 * NOTE: true multi-connection InnoDB locking (FOR UPDATE serialization)
 * cannot be exercised on a host with no MySQL; that requires a MySQL
 * integration test. These tests prove the deterministic decision logic
 * and that the endpoint sources hold the post-lock invariant.
 *
 * Run:  php Project_wasteBin/test_complete_session_race.php
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
// removed, then load the REAL (DB-free) idempotency helpers. Lets the pure
// decision function and the constants run without a database.
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

$tmp = tempnam(sys_get_temp_dir(), 'vh_ecore_cr_');
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
t('eco_completion_decision exists',
    function_exists('eco_completion_decision'));
t('eco_lock_session_for_station exists',
    function_exists('eco_lock_session_for_station'));

// ---------------------------------------------------------------------------
// Session-row factory. mysqli returns every column as a string, so the helper
// must cope with string station_id / status values too.
// ---------------------------------------------------------------------------
function sess(string $status, $stationId = 7, int $id = 101, int $posted = 0): array {
    return [
        'id'            => $id,
        'station_id'    => $stationId,
        'status'        => $status,
        'user_id'       => 42,
        'points_posted' => $posted,
        'error_message' => null,
    ];
}

$STATION = 7;

// =====================================================================
// 1. DECISION MATRIX (pure)
// =====================================================================
echo "== 1. decision matrix ==";

// --- OPEN sessions owned by this station may be completed --------------
t('1a ACTIVE session -> complete',
    eco_completion_decision(sess('ACTIVE'), $STATION) === 'complete');
t('1b PROCESSING session -> complete',
    eco_completion_decision(sess('PROCESSING'), $STATION) === 'complete');
t('1c WAITING session -> complete',
    eco_completion_decision(sess('WAITING'), $STATION) === 'complete');
t('1d lowercase open status normalised -> complete',
    eco_completion_decision(sess('active'), $STATION) === 'complete');

// --- Already-finalised sessions must never be overwritten -------------
t('1e COMPLETED session -> reject (no re-award)',
    eco_completion_decision(sess('COMPLETED', 7, 101, 1), $STATION) === 'reject');
t('1f CANCELLED session -> reject (no resurrect)',
    eco_completion_decision(sess('CANCELLED'), $STATION) === 'reject');
t('1g ERROR session -> reject (recovery handled separately)',
    eco_completion_decision(sess('ERROR'), $STATION) === 'reject');
t('1h unknown status -> reject',
    eco_completion_decision(sess('FOO'), $STATION) === 'reject');

// --- ownership / existence --------------------------------------------
t('1i null locked row (unknown token) -> reject',
    eco_completion_decision(null, $STATION) === 'reject');
t('1j foreign station row -> reject',
    eco_completion_decision(sess('ACTIVE', 99), $STATION) === 'reject');
t('1k foreign station COMPLETED row -> reject (never leak)',
    eco_completion_decision(sess('COMPLETED', 99), $STATION) === 'reject');
t('1l string station_id from mysqli still matches -> complete',
    eco_completion_decision(sess('ACTIVE', '7'), $STATION) === 'complete');

// =====================================================================
// 2. INTERLEAVING MODELS
//
// The decision is computed from the row observed AFTER the lock. We model
// the orderings by handing the helper the post-lock row.
// =====================================================================
echo "== 2. interleaving models ==";

// Scenario A: a stale pre-lock read saw ACTIVE, but the lock now sees the
// session already COMPLETED by a concurrent request.
$preLockStatus = 'ACTIVE';              // what the old code wrongly trusted
$postLockRow   = sess('COMPLETED', 7, 101, 1);
t('2a stale status: pre-lock ACTIVE but locked COMPLETED -> reject',
    $preLockStatus !== $postLockRow['status']
    && eco_completion_decision($postLockRow, $STATION) === 'reject');
t('2b the pre-lock status string is irrelevant to the decision',
    eco_completion_decision($postLockRow, $STATION)
        === eco_completion_decision(sess('COMPLETED', 7, 101, 1), $STATION));

// Scenario B: cancel wins the lock race before completion.
t('2c cancel-before-lock: locked CANCELLED -> reject (no resurrect)',
    eco_completion_decision(sess('CANCELLED'), $STATION) === 'reject');

// Scenario C: error wins the lock race before completion.
t('2d error-before-lock: locked ERROR -> reject',
    eco_completion_decision(sess('ERROR'), $STATION) === 'reject');

// Scenario D: completion wins its own lock (still open) -> may finalize.
t('2e open-at-lock: locked ACTIVE -> complete',
    eco_completion_decision(sess('ACTIVE'), $STATION) === 'complete');

// Scenario E: repeated completion. The second request locks a COMPLETED row
// and must be rejected; the award path's points_posted guard means it can
// never double-award even if it were reached.
t('2f repeated completion: locked COMPLETED (posted=1) -> reject',
    eco_completion_decision(sess('COMPLETED', 7, 101, 1), $STATION) === 'reject');
t('2g repeated completion: even with posted=0 the closed status rejects',
    eco_completion_decision(sess('COMPLETED', 7, 101, 0), $STATION) === 'reject');
t('2h repeated completion: COMPLETED is not in the OPEN set',
    !in_array('COMPLETED', ECO_SESSION_STATUSES_OPEN, true));

// =====================================================================
// 3. STATIC drift guards over the real sources
// =====================================================================
echo "== 3. source drift guards ==";

$submit = @file_get_contents($repoRoot . '/api/submit_waste_data.php') ?: '';
$idem   = @file_get_contents($repoRoot . '/ecopoint_idempotency.php') ?: '';
$core   = @file_get_contents($srcFile) ?: '';
$comp   = @file_get_contents($repoRoot . '/api/complete_session.php') ?: '';

// --- completion endpoint must serialize on the row and revalidate ------
t('3a completion opens a transaction',
    strpos($comp, '$con->begin_transaction();') !== false);
t('3b completion locks the session by token+station under the transaction',
    strpos($comp, 'eco_lock_session_for_station($con, $station, $token)') !== false);
t('3c completion revalidates via the pure decision helper',
    strpos($comp, 'eco_completion_decision($locked, $stationId)') !== false);
t('3d completion reads the snapshot from the LOCKED row',
    strpos($comp, "(string)(\$locked['material_type']") !== false
    && strpos($comp, "(float)(\$locked['weight_kg']") !== false);
t('3e completion takes the session id from the LOCKED row',
    strpos($comp, "\$sessionId = (int)\$locked['id'];") !== false);
t('3f completion no longer captures the pre-lock session row',
    strpos($comp, '$session = eco_load_session_for_station') === false);
t('3g completion still drives the pre-lock loader for stale recovery',
    strpos($comp, 'eco_load_session_for_station($con, $station, $token, true);') !== false);
t('3h completion only mutates after the OPEN decision',
    strpos($comp, "!== 'complete'") !== false);

// --- non-OPEN handling preserves the contracts ------------------------
t('3i non-eligible completion still answers the original 404 contract',
    strpos($comp, "'message' => 'Session not found or not eligible for completion',") !== false);
t('3j non-eligible completion still attempts M1 ERROR recovery',
    strpos($comp, 'eco_finalize_error_session($con, $station, $token)') !== false);
t('3k recovery success response still returns COMPLETED',
    strpos($comp, "'session_status'        => 'COMPLETED',") !== false);

// --- existing finalize + failure semantics preserved ------------------
t('3l completion still calls the idempotent award path',
    strpos($comp, 'eco_award_points_and_finalize($con, $sessionId, $stationId)') !== false);
t('3m failure handler still stamps the genuine marker',
    strpos($comp, 'ECO_FINALIZE_ERROR_MARKER . $e->getMessage()') !== false);
t('3n failure handler ERROR stamp is guarded to the OPEN statuses',
    strpos($comp, 'ECO_SESSION_STATUSES_OPEN') !== false);
t('3o weight-zero cancel still returns the original 400 contract',
    strpos($comp, "'message' => 'Session cancelled: weight is zero (nothing recycled).',") !== false);

// --- helper contract ---------------------------------------------------
t('3p completion helper lives in ecopoint_idempotency.php',
    strpos($idem, 'function eco_completion_decision(') !== false);
t('3q completion helper honours the OPEN status set',
    strpos($idem, 'in_array($status, ECO_SESSION_STATUSES_OPEN, true)') !== false);
t('3r completion helper roots ownership in the locked row',
    strpos($idem, "(\$lockedSession['station_id'] ?? 0)") !== false);
t('3s lock helper uses SELECT * ... FOR UPDATE',
    strpos($idem, 'FOR UPDATE') !== false
    && strpos($idem, 'SELECT *') !== false);
t('3t lock helper scopes by session_token AND station_id',
    strpos($idem, 'session_token = ?') !== false
    && strpos($idem, 'station_id    = ?') !== false);

// --- transition guard that protects the failure handler ---------------
t('3u eco_transition_status accepts an optional source-status guard',
    strpos($core, '?array $allowFrom = null') !== false);
t('3v the guard is applied inside the same atomic UPDATE',
    strpos($core, 'AND status IN (') !== false);
t('3w award path still locks the session FOR UPDATE',
    strpos($core, 'SELECT * FROM ecopoint_waste_sessions WHERE id = ? FOR UPDATE') !== false);
t('3x award path still keeps the points_posted double-award guard',
    strpos($core, "\$alreadyPosted = ((int)(\$session['points_posted'] ?? 0) === 1);") !== false);

echo str_repeat('=', 60) . "\n";
echo "RESULT: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
