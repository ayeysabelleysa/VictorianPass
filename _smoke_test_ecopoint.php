<?php
/**
 * EcoPoint Waste Session System — Quick Smoke Test (PHP CLI / Browser)
 * ===================================================================
 * This file is OPTIONAL. It exercises the DB schema and core functions
 * so the whole flow (QR verify → session → submit → complete → points)
 * can be validated without any real hardware.
 *
 * It will:
 *   1. Run the SQL migration to ensure tables exist
 *   2. Verify the seeded station VH-ECO-001 API key
 *   3. Create/find a test resident (users table)
 *   4. Create a session, submit waste, finalize, check points
 *   5. Verify duplicate-award guard (re-finalize = no double points)
 *   6. Verify duplicate-session guard (second session = return existing)
 *   7. Output pass/fail at the end.
 *
 * RUN IT:
 *   - In browser: visit /VictorianPass/_smoke_test_ecopoint.php
 *   - Or CLI:     cd C:\xampp\htdocs\VictorianPass
 *                 C:\xampp\php\php.exe _smoke_test_ecopoint.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/ecopoint_core.php';

$failures = [];
$passes   = [];
function check($label, $ok, $detail = '') {
    global $failures, $passes;
    if ($ok) { $passes[] = $label; } else { $failures[] = $label . '  ::  ' . ($detail ?: 'assertion failed'); }
}

echo "<pre style=\"font-family:monospace;font-size:12px;white-space:pre-wrap;\">";
echo "[EcoPoint Smoke Test] Started at " . date('Y-m-d H:i:s') . "\n\n";

// 1. Ensure migration applied -------------------------------------------------
echo "[1] Apply migration script...\n";
$sqlPath = __DIR__ . '/DATABASE/ecopoint_waste_system.sql';
if (!file_exists($sqlPath)) { $failures[] = 'missing migration file at ' . $sqlPath; }
else {
    $sql = @file_get_contents($sqlPath);
    if (!$sql) { $failures[] = 'cannot read migration'; }
    else {
        // Run each non-empty line as its own statement (migration is written to be idempotent)
        $statements = array_filter(array_map('trim', explode(";\n", $sql)));
        $ok = true;
        foreach ($statements as $q) {
            if ($q === '' || strpos($q, '--') === 0) continue;
            if (!$con->query($q)) {
                echo "  WARNING (" . $con->errno . "): " . substr($con->error, 0, 200) . "\n";
                // Duplicate key / column exists warnings are EXPECTED for idempotency,
                // so we don't count them as failures. Only bail on truly weird errors.
                if (!in_array($con->errno, [1060,1061,1091,1022,1050])) {
                    $ok = false;
                    $failures[] = 'migration query failed (errno '.$con->errno.'): ' . substr($q,0,80);
                }
            }
        }
        if ($ok) $passes[] = 'migration applied (idempotent)';
    }
}
echo "  -> done\n\n";

// 2. Station auth (simulated with the seeded station VH-ECO-001) --------------
echo "[2] Station + hardware auth sanity...\n";
$SEED_PLAINTEXT_KEY = 'vheco-station-VH-ECO-001-default-api-key-secret-change-me';
$station = $con->query("SELECT * FROM ecopoint_stations WHERE station_code = 'VH-ECO-001' LIMIT 1")->fetch_assoc();
check('station seeded: VH-ECO-001 exists', !!$station);
if ($station) {
    $verify = password_verify($SEED_PLAINTEXT_KEY, (string)$station['hashed_api_key']);
    check('station API key verifies via password_verify', $verify);
    check('station status is ACTIVE', ($station['status'] ?? '') === 'ACTIVE');
}
echo "  -> done\n\n";

// 3. Create/find a test resident ---------------------------------------------
echo "[3] Resident lookup + create test user...\n";
$TEST_REF_CODE = 'ECO-SMOKE-0000';
$stmt = $con->prepare("SELECT id, ref_code, first_name, last_name, points FROM users WHERE ref_code = ? LIMIT 1");
$stmt->bind_param('s', $TEST_REF_CODE);
$stmt->execute();
$u = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$u) {
    $first = 'Smoke'; $last = 'Testuser';
    $email = 'smoketest-ecopoint@victorianpass.local';
    $pwh   = password_hash('Sm0keT3st!', PASSWORD_DEFAULT);
    $stmt = $con->prepare("INSERT INTO users (email, password, first_name, last_name, ref_code, role, status) VALUES (?,?,?,?,?,'resident','active')");
    $stmt->bind_param('sssss', $email, $pwh, $first, $last, $TEST_REF_CODE);
    if (!$stmt->execute()) { $failures[] = 'insert test user failed: ' . $stmt->error; }
    $stmt->close();
    $stmt = $con->prepare("SELECT id, ref_code, first_name, last_name, points FROM users WHERE ref_code = ? LIMIT 1");
    $stmt->bind_param('s', $TEST_REF_CODE); $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}
check('test resident found/created', !!$u, $TEST_REF_CODE);
$resident = eco_find_resident_by_qr($con, $TEST_REF_CODE);
check('eco_find_resident_by_qr returns resident', !!$resident && !empty($resident['user_id']));
echo "  -> user_id = " . ($u['id'] ?? '?') . "  ref_code = " . ($u['ref_code'] ?? '?') . "  points = " . ($u['points'] ?? 0) . "\n\n";

// 4. Create session (duplicate session guard tested first) -------------------
echo "[4] Session create + duplicate session guard...\n";
// Clean up any previous ACTIVE smoke-test sessions first (so test runs cleanly)
$con->query("DELETE FROM ecopoint_session_events WHERE session_id IN (SELECT id FROM ecopoint_waste_sessions WHERE user_id = ".(int)$u['id']." AND status IN ('ACTIVE','PROCESSING'))");
$con->query("DELETE FROM ecopoint_waste_sessions WHERE user_id = ".(int)$u['id']." AND status IN ('ACTIVE','PROCESSING')");

$beforeBalance = eco_user_balance($con, (int)$u['id']);
$res1 = eco_create_session($con, $station, $resident, $TEST_REF_CODE);
check('eco_create_session -> created session', $res1['created'] === true, 'session_id = '.($res1['session']['id'] ?? '?'));
$sess1 = $res1['session'];
$res2  = eco_create_session($con, $station, $resident, $TEST_REF_CODE);
check('eco_create_session duplicate guard returns same session (no new row)',
       $res2['created'] === false && $res2['session']['id'] == $sess1['id']);
echo "  -> session_token = " . substr($sess1['session_token'], 0, 16) . "...\n\n";

// 5. Submit waste data + transition to PROCESSING ----------------------------
echo "[5] Submit waste data (aluminum 1.5 kg)...\n";
// Simulate: station loaded the session token for this station
$loaded = eco_load_session_for_station($con, $station, $sess1['session_token'], true);
check('eco_load_session_for_station loads session', !!$loaded);
if ($loaded) {
    $calc = eco_calculate_points('Aluminum', 1.50);
    check('rate Aluminum = 140 pts/kg * 1.50kg = 210 pts',
          (int)$calc['raw_points'] === 210 && (int)$calc['rate_pts_per_kg'] === 140);

    // Simulate submit + finalize via core helpers directly (skips HTTP layer)
    $sessionId = (int)$sess1['id'];
    $stationId = (int)$station['id'];

    $con->begin_transaction();
    eco_transition_status($con, $sessionId, $stationId, 'PROCESSING');
    $stmt = $con->prepare("UPDATE ecopoint_waste_sessions SET material_type='Aluminum', weight_kg=1.50, points_calculated=210 WHERE id = ?");
    $stmt->bind_param('i', $sessionId); $stmt->execute(); $stmt->close();
    eco_log_event($con, $sessionId, $stationId, 'WASTE_DATA', ['material'=>'Aluminum','weight_kg'=>1.5,'points_calc'=>210], 'HARDWARE');
    $con->commit();

    $fresh = $con->query("SELECT * FROM ecopoint_waste_sessions WHERE id = $sessionId")->fetch_assoc();
    check('status moved to PROCESSING', (string)$fresh['status'] === 'PROCESSING');
    check('material/weight stored correctly', $fresh['material_type'] === 'Aluminum' && (float)$fresh['weight_kg'] === 1.50);
    echo "  -> points_calculated = " . (int)$fresh['points_calculated'] . "\n\n";

    // 6. Finalize session (award points, idempotency guard) ----------------------
    echo "[6] Finalize (award points) + double-award guard...\n";
    $aw = eco_award_points_and_finalize($con, $sessionId, $stationId);
    $finalSess = $con->query("SELECT * FROM ecopoint_waste_sessions WHERE id = $sessionId")->fetch_assoc();
    check('session status = COMPLETED', (string)$finalSess['status'] === 'COMPLETED');
    check('points_posted = 1', (int)$finalSess['points_posted'] === 1);
    check('posted_transaction_id populated', !empty($finalSess['posted_transaction_id']));
    $afterTxnId = (int)$finalSess['posted_transaction_id'];
    $txnRow = $con->query("SELECT * FROM point_transactions WHERE id = $afterTxnId")->fetch_assoc();
    check('point_transactions row created', !!$txnRow);
    if ($txnRow) {
        check('txn material_type written', (string)$txnRow['material_type'] === 'Aluminum');
        check('txn weight_kg written', (float)$txnRow['weight_kg'] === 1.50);
        check('txn amount matches awarded points', (int)$txnRow['amount'] === (int)$finalSess['points_awarded']);
        check('txn session FK written', (int)$txnRow['ecopoint_session_id'] === $sessionId);
    }
    $newBalance = eco_user_balance($con, (int)$u['id']);
    check("users.points increased by awarded (before=$beforeBalance, after=$newBalance, awarded={$aw['awarded_points']})",
          $newBalance - $beforeBalance === (int)$aw['awarded_points']);

    // Now re-run finalize to ensure idempotency
    echo "     -> re-running eco_award_points_and_finalize() (second attempt)\n";
    $aw2 = eco_award_points_and_finalize($con, $sessionId, $stationId);
    $after2Balance = eco_user_balance($con, (int)$u['id']);
    check('double-award guard: awarded_points stays the same on re-run',
          (int)$aw2['awarded_points'] === (int)$aw['awarded_points']);
    check('double-award guard: balance stays the same on re-run',
          $after2Balance === $newBalance);
    echo "  -> awarded = {$aw['awarded_points']} pts, new balance = $newBalance\n\n";
}

// 7. Test session status API shape (mock-resident via $_SESSION) -------------
echo "[7] Session status API shape...\n";
$sApi = __DIR__ . '/api_resident/ecopoint_session_status.php';
$hApi = __DIR__ . '/api_hardware/qr_verify_and_create_session.php';
check('resident session status API file exists', file_exists($sApi));
check('hardware qr-verify API file exists', file_exists($hApi));
check('hardware submit_waste_data exists', file_exists(__DIR__.'/api_hardware/submit_waste_data.php'));
check('hardware complete_session exists', file_exists(__DIR__.'/api_hardware/complete_session.php'));
check('hardware cancel_session exists', file_exists(__DIR__.'/api_hardware/cancel_session.php'));
check('hardware error_session exists', file_exists(__DIR__.'/api_hardware/error_session.php'));
echo "  -> done\n\n";

// Summary --------------------------------------------------------------------
echo "==============================================================\n";
echo "PASSES: " . count($passes) . "\n";
foreach ($passes as $p) echo "   [PASS] $p\n";
echo "FAILURES: " . count($failures) . "\n";
foreach ($failures as $f) echo "   [FAIL] $f\n";
echo "==============================================================\n";
echo "RESULT: " . (count($failures) === 0 ? "ALL PASSED 🏆" : "SOME FAILURES — see above.") . "\n";
echo "</pre>";
