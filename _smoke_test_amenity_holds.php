<?php
/**
 * Amenity Holds / Waitlist — Smoke Test (PHP CLI / Browser)
 * =========================================================
 * Exercises the race-safe hold + conflict + waitlist primitives in
 * amenity_holds.php against the live DB without touching real bookings.
 *
 * RUN IT:
 *   CLI:     C:\xampp\php\php.exe _smoke_test_amenity_holds.php
 *   Browser: /VictorianPass/_smoke_test_amenity_holds.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';

$failures = [];
$passes   = [];
function ah_check($label, $ok, $detail = '')
{
    global $failures, $passes;
    if ($ok) { $passes[] = $label; } else { $failures[] = $label . '  ::  ' . ($detail ?: 'assertion failed'); }
}

echo "<pre style=\"font-family:monospace;font-size:12px;white-space:pre-wrap;\">";
echo "[Amenity Holds Smoke Test] Started at " . date('Y-m-d H:i:s') . "\n\n";

vpAmenityHoldsEnsureSchema($con);

$AMENITY = 'Tennis Court';
$DAY = date('Y-m-d', strtotime('+30 days'));
$DAY_NEXT = date('Y-m-d', strtotime('+31 days'));
$TAG = 'AH-SMOKE-' . substr(bin2hex(random_bytes(4)), 0, 8);

// Two synthetic actors (user ids that may not exist; no FK on the hold tables).
[$aUid] = [990001];
[$bUid] = [990002];
$aOwner = vpOwnerKeyForUser($aUid);
$bOwner = vpOwnerKeyForUser($bUid);

// Clean slate for our synthetic users.
$con->query("DELETE FROM amenity_slot_holds WHERE user_id IN ($aUid,$bUid)");
$con->query("DELETE FROM amenity_waitlist WHERE user_id IN ($aUid,$bUid)");

$slot = function ($st, $et, $day = null) use ($AMENITY, $DAY) {
    return [
        'amenity'    => $AMENITY,
        'start_date' => $day ?: $DAY,
        'end_date'   => $day ?: $DAY,
        'start_time' => $st,
        'end_time'   => $et,
    ];
};

// 1. A holds 10:00-12:00 ------------------------------------------------------
echo "[1] Actor A holds $DAY 10:00-12:00 ...\n";
$r1 = vpAcquireHold($con, $slot('10:00', '12:00') + ['user_id' => $aUid, 'account_type' => 'resident', 'ref_code' => $TAG]);
ah_check('A acquires hold', !empty($r1['ok']), json_encode($r1));
$tokenA = $r1['hold']['hold_token'] ?? '';
$expA   = $r1['hold']['expires_at'] ?? '';
echo "    -> ok=" . (!empty($r1['ok']) ? 'yes' : 'no') . " token=" . substr($tokenA, 0, 8) . " exp=$expA\n\n";

// 2. A refreshes the same slot -> reused, same token/expiry (no extension) ----
echo "[2] Actor A re-requests the same slot (simulated refresh) ...\n";
$r1b = vpAcquireHold($con, $slot('10:00', '12:00') + ['user_id' => $aUid]);
ah_check('A re-request treated as reused', !empty($r1b['reused']), json_encode($r1b));
ah_check('reused token identical', ($r1b['hold']['hold_token'] ?? '') === $tokenA);
ah_check('reused expiry identical (not extended)', ($r1b['hold']['expires_at'] ?? '') === $expA);
echo "\n";

// 3. B tries the same slot -> conflict ----------------------------------------
echo "[3] Actor B tries the same slot ...\n";
$r2 = vpAcquireHold($con, $slot('10:00', '12:00') + ['user_id' => $bUid]);
ah_check('B is rejected with conflict', empty($r2['ok']) && !empty($r2['conflict']), json_encode($r2));
echo "    -> conflict=" . (!empty($r2['conflict']) ? 'yes' : 'no') . "\n\n";

// 4. B tries an adjacent slot 12:00-14:00 -> allowed --------------------------
echo "[4] Actor B holds adjacent slot 12:00-14:00 ...\n";
$r3 = vpAcquireHold($con, $slot('12:00', '14:00') + ['user_id' => $bUid]);
ah_check('adjacent (touching) slot allowed', !empty($r3['ok']), json_encode($r3));
$tokenB = $r3['hold']['hold_token'] ?? '';
echo "\n";

// 5. B tries a partially overlapping slot 11:00-13:00 -> conflict --------------
echo "[5] Actor B tries overlapping 11:00-13:00 ...\n";
$r4 = vpAcquireHold($con, $slot('11:00', '13:00') + ['user_id' => $bUid]);
ah_check('overlapping slot rejected', empty($r4['ok']) && !empty($r4['conflict']), json_encode($r4));
echo "\n";

// 6. Waitlist FIFO + dedupe ---------------------------------------------------
echo "[6] Waitlist FIFO + dedupe ...\n";
$w1 = vpRecordWaitlist($con, $slot('10:00', '12:00') + ['user_id' => $bUid]);
ah_check('B joins waitlist at position 1', !empty($w1['ok']) && (int)$w1['entry']['position'] === 1, json_encode($w1));
$w2 = vpRecordWaitlist($con, $slot('10:00', '12:00') + ['user_id' => $aUid]);
ah_check('A joins waitlist at position 2', !empty($w2['ok']) && (int)$w2['entry']['position'] === 2, json_encode($w2));
$w3 = vpRecordWaitlist($con, $slot('10:00', '12:00') + ['user_id' => $bUid]);
ah_check('B duplicate join is deduped', !empty($w3['duplicate']) && (int)$w3['entry']['position'] === 1, json_encode($w3));
$tokenWB = $w3['entry']['offer_token'] ?? '';
echo "\n";

// 7. Waitlist offer: slot still held by A -> not free -------------------------
echo "[7] Waitlist offer while A still holds ...\n";
$ws1 = vpWaitlistOfferStatus($con, $tokenWB);
ah_check('head-of-queue not offered while slot held', ($ws1['entry']['status'] ?? '') !== 'offered', json_encode($ws1));
echo "\n";

// 8. Expire A's hold -> B's waitlist entry becomes offered --------------------
echo "[8] A's hold expired -> B offered ...\n";
$con->query("UPDATE amenity_slot_holds SET expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE hold_token='" . $con->real_escape_string($tokenA) . "'");
$ws2 = vpWaitlistOfferStatus($con, $tokenWB);
ah_check('B becomes head and slot free', !empty($ws2['is_head']) && !empty($ws2['slot_free']), json_encode($ws2));
ah_check('B status offered with claim window', ($ws2['entry']['status'] ?? '') === 'offered' && !empty($ws2['entry']['offer_token']), json_encode($ws2));
$offerTokenB = $ws2['entry']['offer_token'] ?? '';
echo "\n";

// 9. Expired hold no longer blocks acquisition --------------------------------
echo "[9] B claims the freed slot via a fresh hold ...\n";
$r5 = vpAcquireHold($con, $slot('10:00', '12:00') + ['user_id' => $bUid]);
ah_check('expired hold no longer blocks', !empty($r5['ok']), json_encode($r5));
$tokenB2 = $r5['hold']['hold_token'] ?? '';
echo "\n";

// 10. Hold validation + conversion --------------------------------------------
echo "[10] Validate + convert B's hold ...\n";
$v = vpValidateHoldForSlot($con, $tokenB2, $AMENITY, $DAY, $DAY, '10:00:00', '12:00:00');
ah_check('hold validates for matching slot', !empty($v[0]), json_encode($v));
$bad = vpValidateHoldForSlot($con, $tokenB2, $AMENITY, $DAY, $DAY, '13:00:00', '15:00:00');
ah_check('hold rejects mismatched time', empty($bad[0]));
ah_check('mark converted', vpMarkHoldConverted($con, $tokenB2, $TAG));
$after = vpFindHoldByToken($con, $tokenB2);
ah_check('converted status persisted', ($after['status'] ?? '') === 'converted' && ($after['converted_ref_code'] ?? '') === $TAG, json_encode($after));
echo "\n";

// 11. A real reservation row blocks the slot ----------------------------------
echo "[11] Reservation conflict detection ...\n";
$con->query("DELETE FROM reservations WHERE ref_code = '$TAG-RES'");
$cols = ['ref_code', 'amenity', 'start_date', 'end_date', 'start_time', 'end_time', 'persons', 'price', 'approval_status', 'status', 'account_type'];
$vals = ["'$TAG-RES'", "'$AMENITY'", "'$DAY'", "'$DAY'", "'14:00:00'", "'16:00:00'", '10', '150.00', "'approved'", "'approved'", "'resident'"];
$con->query("INSERT INTO reservations (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")");
$r6 = vpAcquireHold($con, $slot('14:00', '16:00') + ['user_id' => $aUid]);
ah_check('reservation blocks a new hold', empty($r6['ok']) && !empty($r6['conflict']), json_encode($r6));
$r7 = vpAcquireHold($con, $slot('14:00', '16:00') + ['user_id' => $aUid, 'ref_code' => "$TAG-RES"]);
ah_check('own reservation excluded when ref_code matches', !empty($r7['ok']), json_encode($r7));
echo "\n";

// Cleanup --------------------------------------------------------------------
echo "[cleanup] Removing synthetic rows ...\n";
$con->query("DELETE FROM amenity_slot_holds WHERE user_id IN ($aUid,$bUid)");
$con->query("DELETE FROM amenity_waitlist WHERE user_id IN ($aUid,$bUid)");
$con->query("DELETE FROM reservations WHERE ref_code = '$TAG-RES'");
$con->query("DELETE FROM amenity_slot_locks WHERE lock_key LIKE '" . $con->real_escape_string($AMENITY) . "|%'");
echo "  -> done\n\n";

echo "==============================================================\n";
echo "PASSES: " . count($passes) . "\n";
foreach ($passes as $p) echo "   [PASS] $p\n";
echo "FAILURES: " . count($failures) . "\n";
foreach ($failures as $f) echo "   [FAIL] $f\n";
echo "==============================================================\n";
echo "RESULT: " . (count($failures) === 0 ? "ALL PASSED" : "SOME FAILURES") . "\n";
echo "</pre>";
