<?php
/**
 * VHEcoPoint — cancel / hardware-ERROR stale-status race.
 * ------------------------------------------------------------
 * The bug: api/cancel_session.php and api/error_session.php read the
 * session with eco_load_session_for_station() (no lock) and then performed
 * a status UPDATE by id without ever re-checking that the session was still
 * eligible. A concurrent completion could commit COMPLETED in that window
 * and the stale cancel/error would overwrite it back to CANCELLED/ERROR.
 * (error_session.php additionally called the loader INSIDE its transaction,
 * which can trigger a nested eco_recover_stale_session() begin_transaction.)
 *
 * The fix: both endpoints do the pre-lock lookup OUTSIDE the transaction
 * (stale recovery only — result not authoritative), then lock the row by
 * token+station via eco_lock_session_for_station() and decide with the pure
 * helper eco_terminal_transition_allowed(). The status change is applied
 * with eco_transition_status(..., ECO_SESSION_STATUSES_OPEN), so the
 * eligibility check and the write are ONE atomic UPDATE. On a lost race the
 * newer terminal state is never touched, no event is logged, and the
 * existing API response contract is preserved.
 *
 * Layers, mirroring test_complete_session_race.php / test_m4_submit_race.php:
 *   1. RUNTIME unit tests of the REAL eco_terminal_transition_allowed().
 *   2. Interleaving models (cancel/complete, complete/cancel, error/complete,
 *      repeated cancel/error, terminal-overwrite protection).
 *   3. STATIC drift guards over the real PHP sources.
 *
 * NOTE: true multi-connection InnoDB locking (FOR UPDATE serialization and
 * the conditional UPDATE's affected-rows) cannot be exercised on a host with
 * no MySQL; that requires a MySQL integration test. These tests prove the
 * deterministic decision logic and that the endpoint sources hold the
 * post-lock / atomic-guard invariants.
 *
 * Run:  php Project_wasteBin/test_cancel_error_race.php
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
// removed, then load the REAL (DB-free) idempotency helpers.
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

$tmp = tempnam(sys_get_temp_dir(), 'vh_ecore_cx_');
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
t('eco_terminal_transition_allowed exists',
    function_exists('eco_terminal_transition_allowed'));
t('eco_lock_session_for_station exists',
    function_exists('eco_lock_session_for_station'));
t('eco_transition_status accepts the allowed-from guard',
    (new ReflectionFunction('eco_transition_status'))->getNumberOfParameters() >= 6);

// ---------------------------------------------------------------------------
function sess(string $status, $stationId = 7, int $id = 101): array {
    return [
        'id'            => $id,
        'station_id'    => $stationId,
        'status'        => $status,
        'user_id'       => 42,
        'material_type' => 'Plastic',
        'weight_kg'     => 1.25,
    ];
}

$STATION = 7;

// =====================================================================
// 1. DECISION MATRIX (pure)
// =====================================================================
echo "== 1. decision matrix ==";

// --- OPEN sessions owned by this station may be closed ----------------
t('1a ACTIVE session -> allowed',
    eco_terminal_transition_allowed(sess('ACTIVE'), $STATION) === true);
t('1b PROCESSING session -> allowed',
    eco_terminal_transition_allowed(sess('PROCESSING'), $STATION) === true);
t('1c WAITING session -> allowed',
    eco_terminal_transition_allowed(sess('WAITING'), $STATION) === true);
t('1d lowercase open status normalised -> allowed',
    eco_terminal_transition_allowed(sess('active'), $STATION) === true);

// --- terminal / unrelated states must never be overwritten ------------
t('1e COMPLETED session -> not allowed (completed cannot be overwritten)',
    eco_terminal_transition_allowed(sess('COMPLETED'), $STATION) === false);
t('1f CANCELLED session -> not allowed (cancel is idempotent-terminal)',
    eco_terminal_transition_allowed(sess('CANCELLED'), $STATION) === false);
t('1g ERROR session -> not allowed (error report cannot re-stamp)',
    eco_terminal_transition_allowed(sess('ERROR'), $STATION) === false);
t('1h unknown status -> not allowed',
    eco_terminal_transition_allowed(sess('FOO'), $STATION) === false);

// --- ownership / existence --------------------------------------------
t('1i null locked row (unknown token) -> not allowed',
    eco_terminal_transition_allowed(null, $STATION) === false);
t('1j foreign station OPEN row -> not allowed',
    eco_terminal_transition_allowed(sess('ACTIVE', 99), $STATION) === false);
t('1k foreign station COMPLETED row -> not allowed (never leak)',
    eco_terminal_transition_allowed(sess('COMPLETED', 99), $STATION) === false);
t('1l string station_id from mysqli still matches -> allowed',
    eco_terminal_transition_allowed(sess('ACTIVE', '7'), $STATION) === true);

// =====================================================================
// 2. INTERLEAVING MODELS
// =====================================================================
echo "== 2. interleaving models ==";

// Scenario A: cancel wins its lock first. It observes OPEN and cancels;
// a completion that acquires the lock afterwards sees CANCELLED.
$openRow         = sess('ACTIVE');
$cancelledRow    = sess('CANCELLED');
t('2a cancel-before-complete: cancel sees OPEN -> allowed',
    eco_terminal_transition_allowed($openRow, $STATION) === true);
t('2b cancel-before-complete: later completion sees CANCELLED -> reject',
    eco_completion_decision($cancelledRow, $STATION) === 'reject');

// Scenario B: completion wins its lock first. A cancel that acquires the
// lock afterwards sees COMPLETED and must not overwrite it.
$completedRow = sess('COMPLETED');
t('2c complete-before-cancel: completion sees OPEN -> complete',
    eco_completion_decision($openRow, $STATION) === 'complete');
t('2d complete-before-cancel: later cancel sees COMPLETED -> not allowed',
    eco_terminal_transition_allowed($completedRow, $STATION) === false);

// Scenario C: hardware ERROR report races with completion.
t('2e error-before-complete: error sees OPEN -> allowed',
    eco_terminal_transition_allowed($openRow, $STATION) === true);
t('2f complete-before-error: later error sees COMPLETED -> not allowed',
    eco_terminal_transition_allowed($completedRow, $STATION) === false);
t('2g complete-before-error: later completion sees ERROR -> reject',
    eco_completion_decision(sess('ERROR'), $STATION) === 'reject');

// Scenario D: repeated cancel / error are no-ops on a terminal row.
t('2h repeated cancel: second cancel sees CANCELLED -> not allowed',
    eco_terminal_transition_allowed($cancelledRow, $STATION) === false);
t('2i repeated error: second error sees ERROR -> not allowed',
    eco_terminal_transition_allowed(sess('ERROR'), $STATION) === false);

// Scenario E: a stale pre-lock read saw OPEN but the lock now sees a
// terminal state, so the decision ignores the pre-lock string entirely.
$preLockStatus = 'ACTIVE';
t('2j stale pre-lock OPEN but locked COMPLETED -> not allowed',
    $preLockStatus !== $completedRow['status']
    && eco_terminal_transition_allowed($completedRow, $STATION) === false);
t('2k stale pre-lock OPEN but locked CANCELLED -> not allowed',
    eco_terminal_transition_allowed($cancelledRow, $STATION) === false);

// Scenario F: the ERROR row left by a failed finalization stays eligible
// for M1 recovery precisely because a hardware error cannot re-stamp it.
t('2l M1 ERROR row cannot be overwritten by a hardware error report',
    eco_terminal_transition_allowed(sess('ERROR'), $STATION) === false);

// =====================================================================
// 3. STATIC drift guards over the real sources
// =====================================================================
echo "== 3. source drift guards ==";

$cancel = @file_get_contents($repoRoot . '/api/cancel_session.php') ?: '';
$err    = @file_get_contents($repoRoot . '/api/error_session.php') ?: '';
$idem   = @file_get_contents($repoRoot . '/ecopoint_idempotency.php') ?: '';
$core   = @file_get_contents($srcFile) ?: '';

// --- cancel_session.php -----------------------------------------------
t('3a cancel opens a transaction',
    strpos($cancel, '$con->begin_transaction();') !== false);
t('3b cancel does its pre-lock lookup BEFORE the transaction',
    strpos($cancel, 'eco_load_session_for_station($con, $station, $token, true);') !== false
    && strpos($cancel, 'eco_load_session_for_station') < strpos($cancel, '$con->begin_transaction();'));
t('3c cancel locks the session by token+station under the transaction',
    strpos($cancel, 'eco_lock_session_for_station($con, $station, $token)') !== false);
t('3d cancel revalidates via the pure terminal-transition helper',
    strpos($cancel, 'eco_terminal_transition_allowed($locked, $stationId)') !== false);
t('3e cancel applies the atomic allowed-from guard',
    strpos($cancel, 'ECO_SESSION_STATUSES_OPEN') !== false);
t('3f cancel only logs the event after a successful transition',
    strpos($cancel, "if (!\$applied)") !== false
    && strpos($cancel, "if (!\$applied)") < strpos($cancel, 'eco_log_event'));
t('3g cancel answers the original 404 contract on a lost race',
    strpos($cancel, "'message' => 'Session not found or already finalized',") !== false);
t('3h cancel success response contract unchanged',
    strpos($cancel, "'session_status' => 'CANCELLED',") !== false
    && strpos($cancel, "'success'        => true,") !== false);
t('3i cancel no longer authorizes from a captured pre-lock session row',
    strpos($cancel, '$session = eco_load_session_for_station') === false);

// --- error_session.php ------------------------------------------------
t('3j error opens a transaction',
    strpos($err, '$con->begin_transaction();') !== false);
t('3k error does its pre-lock lookup BEFORE the transaction',
    strpos($err, 'eco_load_session_for_station') !== false
    && strpos($err, 'eco_load_session_for_station') < strpos($err, '$con->begin_transaction();'));
t('3l error locks the session by token+station under the transaction',
    strpos($err, 'eco_lock_session_for_station($con, $station, $token)') !== false);
t('3m error revalidates via the pure terminal-transition helper',
    strpos($err, 'eco_terminal_transition_allowed($locked, $stationId)') !== false);
t('3n error applies the atomic allowed-from guard',
    strpos($err, 'ECO_SESSION_STATUSES_OPEN') !== false);
t('3o error only logs the event after a successful transition',
    strpos($err, "if (\$applied)") !== false
    && strpos($err, "if (\$applied)") < strpos($err, 'eco_log_event'));
t('3p error keeps the station-level heartbeat branch',
    strpos($err, 'UPDATE ecopoint_stations SET last_heartbeat_at = NOW()') !== false);
t('3q error success/reported contract unchanged',
    strpos($err, "'reported_at'    => date('Y-m-d H:i:s'),") !== false);
t('3r error still normalizes the incoming message (marker protection)',
    strpos($err, '$message  = eco_normalize_hardware_error_message(') !== false);
t('3s error never references the reserved finalize marker',
    strpos($err, 'ECO_FINALIZE_ERROR_MARKER') === false);

// --- helper contract --------------------------------------------------
t('3t terminal helper lives in ecopoint_idempotency.php',
    strpos($idem, 'function eco_terminal_transition_allowed(') !== false);
t('3u terminal helper honours the OPEN status set',
    strpos($idem, 'in_array($status, ECO_SESSION_STATUSES_OPEN, true)') !== false);
t('3v terminal helper roots ownership in the locked row',
    strpos($idem, "(\$lockedSession['station_id'] ?? 0)") !== false);

// --- core atomic guard ------------------------------------------------
t('3w transition guard applied inside the same atomic UPDATE',
    strpos($core, 'AND status IN (') !== false);
t('3x transition accepts an optional source-status guard',
    strpos($core, '?array $allowFrom = null') !== false);
t('3y completion endpoint still guarded (no regression)',
    (strpos(@file_get_contents($repoRoot . '/api/complete_session.php') ?: '', 'ECO_SESSION_STATUSES_OPEN') !== false));

echo str_repeat('=', 60) . "\n";
echo "RESULT: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
