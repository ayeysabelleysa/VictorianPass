<?php
/**
 * Queue / waiting-line flow smoke test (CLI only).
 * Run: php _smoke_test_amenity_queue.php
 *
 * Exercises the full spec flow:
 *   1. A holds, B joins queue (position reported).
 *   2. A submits (pending); B keeps waiting (not offered).
 *   3. A's booking is approved; B is told it's unavailable + gets alternatives.
 *   4. A's hold expires; B is offered the slot with a fresh claim hold.
 *   5. B claims; C joins behind B and waits.
 *   6. B's offer expires; the queue advances and C is offered.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';

if (!($con instanceof mysqli) || $con->connect_errno) { fwrite(STDERR, "DB unavailable\n"); exit(1); }

$PASS = 0; $FAIL = 0;
function check($label, $cond) {
    global $PASS, $FAIL;
    if ($cond) { $PASS++; echo "   [PASS] $label\n"; }
    else { $FAIL++; echo "   [FAIL] $label\n"; }
}

$amenity = 'Tennis Court';
$date    = '2027-03-15';
$st      = '10:00';
$et      = '11:00';
$A = 900001; $B = 900002; $C = 900003;

function wlClean($con, $amenity, $date) {
    @$con->query("DELETE FROM amenity_waitlist WHERE amenity='" . $con->real_escape_string($amenity) . "' AND start_date='" . $con->real_escape_string($date) . "'");
    @$con->query("DELETE FROM amenity_slot_holds WHERE amenity='" . $con->real_escape_string($amenity) . "' AND start_date='" . $con->real_escape_string($date) . "'");
    @$con->query("DELETE FROM reservations WHERE ref_code LIKE 'TESTQA-%'");
    @$con->query("DELETE FROM notifications WHERE title LIKE 'Queued slot booked | " . $con->real_escape_string($amenity) . " | " . $con->real_escape_string($date) . "%'");
}
wlClean($con, $amenity, $date);
vpExpireHolds($con);

echo "== 1. A holds; B joins queue ==\n";
$h = vpAcquireHold($con, ['user_id'=>$A,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
check('A obtains hold', !empty($h['ok']));
$aHold = $h['hold']['hold_token'] ?? '';
$j = vpRecordWaitlist($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
check('B joins queue', !empty($j['ok']));
$bTok = $j['entry']['offer_token'] ?? '';
[$bp,$bt] = vpWaitlistRank($con, $j['entry']);
check('B position 1', $bp === 1);
check('B total 1', $bt === 1);
$s = vpWaitlistOfferStatus($con, $bTok);
check('B still waiting (slot held)', $s['entry']['status'] === 'waiting');
check('B not offered', empty($s['slot_free']));
check('B message mentions queue', strpos($s['message'], 'queue') !== false);
$dup = vpRecordWaitlist($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
check('duplicate queue entry prevented', !empty($dup['duplicate']));

echo "== 2. A has an unpaid pending booking; B can use the open slot ==\n";
$ref = 'TESTQA-' . bin2hex(random_bytes(4));
$con->query("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, approval_status, status, payment_status)
             VALUES ('$ref','" . $con->real_escape_string($amenity) . "','$date','$date','$st:00','$et:00','pending','pending','pending')");
vpMarkHoldConverted($con, $aHold, $ref);
$s = vpWaitlistOfferStatus($con, $bTok);
check('unpaid pending reservation does not block the slot', !empty($s['slot_free']));
check('B is offered the open slot', $s['entry']['status'] === 'offered');

echo "== 2b. A submits downpayment; B is removed and notified ==\n";
$con->begin_transaction();
$con->query("UPDATE reservations SET payment_status='submitted' WHERE ref_code='$ref'");
vpNotifyQueuedUsersForReservationReference($con, $ref);
$con->commit();
$paidQueue = $con->query("SELECT id FROM amenity_waitlist WHERE offer_token='" . $con->real_escape_string($bTok) . "'");
check('B queue entry removed on payment', !$paidQueue || $paidQueue->num_rows === 0);
$j = vpRecordWaitlist($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
$bTok = $j['entry']['offer_token'];
$con->query("UPDATE reservations SET payment_status='pending' WHERE ref_code='$ref'");

echo "== 3. A approved; B is removed and notified ==\n";
$con->query("UPDATE reservations SET approval_status='approved', status='approved' WHERE ref_code='$ref'");
$s = vpWaitlistOfferStatus($con, $bTok);
check('B marked booked', $s['entry']['status'] === 'booked');
check('B got required message', $s['message'] === 'The slot you queued for has been booked by another user. Please choose another time.');
$remaining = $con->query("SELECT id FROM amenity_waitlist WHERE offer_token='" . $con->real_escape_string($bTok) . "'");
check('B queue entry deleted', !$remaining || $remaining->num_rows === 0);
$notice = $con->query("SELECT id FROM notifications WHERE user_id=$B AND message='The slot you queued for has been booked by another user. Please choose another time.'");
check('B notification persisted', $notice && $notice->num_rows > 0);
$con->query("DELETE FROM reservations WHERE ref_code='$ref'");

echo "== 3b. Overlapping queue is removed; adjacent queue remains ==\n";
wlClean($con, $amenity, $date);
$overlap = vpRecordWaitlist($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>'10:30','end_time'=>'11:30']);
$adjacent = vpRecordWaitlist($con, ['user_id'=>$C,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>'11:00','end_time'=>'12:00']);
$con->begin_transaction();
vpNotifyQueuedUsersOfBookedSlot($con, [
    'user_id'=>$A, 'amenity'=>$amenity, 'start_date'=>$date, 'end_date'=>$date,
    'start_time'=>'10:00', 'end_time'=>'11:00'
]);
$con->commit();
$overlapRemaining = $con->query("SELECT id FROM amenity_waitlist WHERE id=" . (int)$overlap['entry']['id']);
$adjacentRemaining = $con->query("SELECT id FROM amenity_waitlist WHERE id=" . (int)$adjacent['entry']['id']);
check('overlapping queue entry deleted', !$overlapRemaining || $overlapRemaining->num_rows === 0);
check('adjacent non-overlapping queue stays', $adjacentRemaining && $adjacentRemaining->num_rows === 1);

echo "== 4. A2 hold expires; B2 offered a fresh claim hold ==\n";
wlClean($con, $amenity, $date);
vpExpireHolds($con);
$hA = vpAcquireHold($con, ['user_id'=>$A,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
$jB = vpRecordWaitlist($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
$bTok = $jB['entry']['offer_token'];
$con->query("UPDATE amenity_slot_holds SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE hold_token='" . $con->real_escape_string($hA['hold']['hold_token']) . "'");
$s = vpWaitlistOfferStatus($con, $bTok);
check('B offered after A expiry', $s['entry']['status'] === 'offered');
check('B offer has claim hold', !empty($s['entry']['offer_hold_token']));
check('B position 1', ($s['position'] ?? 0) === 1);
$claimToken = $s['entry']['offer_hold_token'];
$hc = vpFindHoldByToken($con, $claimToken);
check('claim hold is active', $hc && $hc['status'] === 'held' && strtotime($hc['expires_at']) > time());

echo "== 5. B claims; C joins behind and waits ==\n";
$bh = vpAcquireHold($con, ['user_id'=>$B,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
check('B claims (reuses claim hold)', !empty($bh['ok']) && !empty($bh['reused']));
$jC = vpRecordWaitlist($con, ['user_id'=>$C,'amenity'=>$amenity,'start_date'=>$date,'end_date'=>$date,'start_time'=>$st,'end_time'=>$et]);
[$cp,$ct] = vpWaitlistRank($con, $jC['entry']);
check('C position 2', $cp === 2);
$sc = vpWaitlistOfferStatus($con, $jC['entry']['offer_token']);
check('C not offered while B holds', $sc['entry']['status'] === 'waiting');

echo "== 6. B offer expires; queue advances to C ==\n";
$con->query("UPDATE amenity_waitlist SET offer_expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE offer_token='" . $con->real_escape_string($bTok) . "'");
$con->query("UPDATE amenity_slot_holds SET expires_at=DATE_SUB(NOW(), INTERVAL 1 MINUTE) WHERE hold_token='" . $con->real_escape_string($claimToken) . "'");
vpExpireWaitlistOffers($con);
$s2 = vpWaitlistOfferStatus($con, $jC['entry']['offer_token']);
check('C now offered after B expired', $s2['entry']['status'] === 'offered');
check('C offer has claim hold', !empty($s2['entry']['offer_hold_token']));

echo "== cleanup ==\n";
wlClean($con, $amenity, $date);

echo "\n==============================================================\n";
echo "PASSED: $PASS   FAILED: $FAIL\n";
echo ($FAIL === 0 ? "RESULT: ALL PASSED\n" : "RESULT: FAILURES PRESENT\n");
exit($FAIL === 0 ? 0 : 1);
