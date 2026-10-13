<?php
/**
 * VHEcoPoint finalization-recovery marker hardening — regression tests.
 * ------------------------------------------------------------
 * Two layers:
 *
 *   1. RUNTIME unit tests for the REAL `eco_normalize_hardware_error_message()`
 *      from ecopoint_core.php. The MySQL bootstrap (connect.php) is stripped
 *      from a working copy so the pure helper can run on a host with no DB,
 *      exactly so this test can never touch or require production data.
 *
 *   2. STATIC drift guards over the PHP sources asserting the recovery-gate
 *      invariants (station ownership, ERROR-only, FOR UPDATE, points_posted,
 *      genuine-marker assignment, boundary normalization).
 *
 * What still requires a MySQL integration test (out of scope offline):
 *   - eco_finalize_error_session() locking / concurrency behavior;
 *   - real eligibility resolution per session class (canceled/completed/zero).
 *
 * Run:  php Project_wasteBin/test_error_recovery_marker.php
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

$marker = 'finalize_error: ';

// Mirror the real gate used by eco_finalize_error_session() so the test can
// prove, without a DB, whether a given error_message string WOULD be eligible.
function gate_matches(string $errorMsg): bool {
    return strncmp(
        $errorMsg,
        ECO_FINALIZE_ERROR_MARKER,
        strlen(ECO_FINALIZE_ERROR_MARKER)
    ) === 0;
}

// ---------------------------------------------------------------------------
// Load the REAL ecopoint_core.php with the DB bootstrap removed.
// ---------------------------------------------------------------------------
$srcFile = __DIR__ . '/..' . '/ecopoint_core.php';
$src = @file_get_contents($srcFile);
if ($src === false) {
    fwrite(STDERR, "FATAL: cannot read $srcFile\n");
    exit(1);
}

// Strip the MySQL bootstrap (connect.php) and the (also DB-free, but
// unnecessary here) idempotency include so the remaining file is purely
// dependency-free helper/function definitions.
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

$tmp = tempnam(sys_get_temp_dir(), 'vh_ecore_');
file_put_contents($tmp, $src);
try {
    require_once $tmp;
} finally {
    @unlink($tmp);
}

t('marker constant maps to finalize_error: ', defined('ECO_FINALIZE_ERROR_MARKER') && ECO_FINALIZE_ERROR_MARKER === $marker);
t('normalizer exists', function_exists('eco_normalize_hardware_error_message'));

// ---------------------------------------------------------------------------
// 1. RUNTIME: normalizer must protect the reserved marker.
// ---------------------------------------------------------------------------
echo "== normalizer behaviour ==";

$plain = 'Scale calibration error 0x12 (load cell out of range)';
t('ordinary hardware message is preserved unchanged',
    eco_normalize_hardware_error_message($plain) === $plain,
    eco_normalize_hardware_error_message($plain));

$spoof = "finalize_error: DB deadlock while awarding -- hardware claims finalize";
$nz = eco_normalize_hardware_error_message($spoof);
t('marker-prefixed hardware message is neutralized (no longer gate-eligible)',
    gate_matches($nz) === false && strpos($nz, '[hardware] ') === 0,
    $nz);
t('neutralised message keeps the original fault text (auditable)',
    strpos($nz, 'DB deadlock while awarding') !== false,
    $nz);

$spoofWs = "   finalize_error: loose belt sensor   ";
$nzWs = eco_normalize_hardware_error_message($spoofWs);
t('marker with surrounding whitespace is trimmed + neutralized',
    gate_matches($nzWs) === false && strpos($nzWs, 'loose belt sensor') !== false,
    $nzWs);

$markerOnly = 'finalize_error:';
t('marker text without trailing space cannot match the gate and is left as-is',
    eco_normalize_hardware_error_message($markerOnly) === $markerOnly
    && gate_matches($markerOnly) === false);

t('empty message stays empty', eco_normalize_hardware_error_message('') === '');
t('whitespace-only message becomes empty',
    eco_normalize_hardware_error_message("   \t  ") === '');
t('default hardware message survives normalization',
    eco_normalize_hardware_error_message('Unknown hardware error') === 'Unknown hardware error');

foreach ([
    'bin full',
    'weighing drift detected',
    '[hardware] finalize_error: already-neutralised report',
] as $sample) {
    t("round-trip gate-safe: " . $sample,
        gate_matches(eco_normalize_hardware_error_message($sample)) === false);
}

t('GENUINE server marker still gate-eligible (recovery remains possible)',
    gate_matches($marker . 'some server-side failure') === true);

// ---------------------------------------------------------------------------
// 2. STATIC: boundary + gate invariants in the real PHP sources.
// ---------------------------------------------------------------------------
echo "== source drift guards ==";

$core = @file_get_contents($srcFile) ?: '';
$err  = @file_get_contents(__DIR__ . '/..' . '/api/error_session.php') ?: '';
$cmp  = @file_get_contents(__DIR__ . '/..' . '/api/complete_session.php') ?: '';

t('error_session.php calls the normalizer on the incoming message',
    strpos($err, '$message  = eco_normalize_hardware_error_message(') !== false);
t('error_session.php never references the reserved marker',
    strpos($err, 'ECO_FINALIZE_ERROR_MARKER') === false);
t('complete_session.php still stamps the genuine marker in its catch block',
    strpos($cmp, 'ECO_FINALIZE_ERROR_MARKER . $e->getMessage()') !== false);
t('recovery SELECT keeps station ownership filter',
    strpos($core, 'AND  station_id    = ?') !== false);
t('recovery SELECT is ERROR-only (excludes COMPLETED/CANCELLED)',
    strpos($core, "status        = 'ERROR'") !== false);
t('recovery SELECT locks FOR UPDATE',
    strpos($core, 'FOR UPDATE') !== false);
t('recovery keeps the points_posted double-award guard',
    strpos($core, "\$alreadyPosted = ((int)(\$session['points_posted'] ?? 0) === 1);") !== false);
t('recovery still requires awardable WASTE_DATA events',
    strpos($core, 'sumStmt') !== false && strpos($core, 'points_calc') !== false);

echo str_repeat('=', 60) . "\n";
echo "RESULT: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);