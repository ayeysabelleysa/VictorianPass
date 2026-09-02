<?php
ob_start(); // Prevents header issues on redirect
require_once __DIR__ . '/session_bootstrap.php';
include 'connect.php';
$generatedCode = '';
$errorMsg = '';
$canSubmit = true;
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$resetReservation = isset($_GET['reset_reservation']) && $_GET['reset_reservation'] === '1';
if ($resetReservation) {
  unset($_SESSION['pending_reservation'], $_SESSION['dp_ref_code'], $_SESSION['flash_ref_code'], $_SESSION['reservation_submitted']);
}

// Unified reservation page for residents and visitors

// Ensure reservations has entry_pass_id column to link entry pass info
function ensureReservationEntryPassColumn($con) {
  if (!($con instanceof mysqli)) { return; }
  $col = $con->query("SHOW COLUMNS FROM reservations LIKE 'entry_pass_id'");
  if (!$col || $col->num_rows === 0) {
    $con->query("ALTER TABLE reservations ADD COLUMN entry_pass_id INT NULL");
  }
}

ensureReservationEntryPassColumn($con);

// Ensure reservations columns are nullable, supporting placeholder record before amenity selection
function ensureReservationsNullable($con) {
  if (!($con instanceof mysqli)) { return; }
  @$con->query("ALTER TABLE reservations MODIFY amenity VARCHAR(100) NULL");
  @$con->query("ALTER TABLE reservations MODIFY start_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY end_date DATE NULL");
  @$con->query("ALTER TABLE reservations MODIFY persons INT NULL");
  @$con->query("ALTER TABLE reservations MODIFY price DECIMAL(10,2) NULL");
}
ensureReservationsNullable($con);

// Ensure time and downpayment fields exist
function ensureReservationTimeAndDownpayment($con){
  if (!($con instanceof mysqli)) { return; }
  $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'start_time'");
  if(!$c1 || $c1->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN start_time TIME NULL AFTER start_date"); }
  $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'end_time'");
  if(!$c2 || $c2->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN end_time TIME NULL AFTER end_date"); }
  $c3 = $con->query("SHOW COLUMNS FROM reservations LIKE 'downpayment'");
  if(!$c3 || $c3->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL AFTER price"); }
}
ensureReservationTimeAndDownpayment($con);

// Ensure common columns used by upsert exist (payment_status, account_type, receipt_path)
function ensureReservationCommonColumns($con){
  if (!($con instanceof mysqli)) { return; }
  $cols=['payment_status','account_type','receipt_path','booking_for'];
  foreach($cols as $col){
    $c=$con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'");
    if(!$c || $c->num_rows===0){
      if($col==='payment_status'){@$con->query("ALTER TABLE reservations ADD COLUMN payment_status ENUM('pending','submitted','verified') NULL");}
      else if($col==='account_type'){@$con->query("ALTER TABLE reservations ADD COLUMN account_type ENUM('visitor','resident') NULL");}
      else if($col==='receipt_path'){@$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL");}
      else if($col==='booking_for'){@$con->query("ALTER TABLE reservations ADD COLUMN booking_for ENUM('resident','guest') NULL");}
    }
  }
}
ensureReservationCommonColumns($con);

// Ensure users table has points column
function ensurePointsColumn($con){
  if (!($con instanceof mysqli)) { return; }
  $c=$con->query("SHOW COLUMNS FROM users LIKE 'points'");
  if(!$c || $c->num_rows===0){
    $con->query("ALTER TABLE users ADD COLUMN points INT DEFAULT 0");
  }
}
ensurePointsColumn($con);

// Ensure transaction history table exists
function ensureTransactionHistoryTable($con){
  if (!($con instanceof mysqli)) { return; }
  $exists = $con->query("SHOW TABLES LIKE 'point_transactions'");
  if (!$exists || $exists->num_rows === 0) {
    $con->query("
      CREATE TABLE point_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        transaction_type ENUM('earn', 'redeem') NOT NULL,
        amount INT NOT NULL,
        description TEXT NOT NULL,
        reservation_ref_code VARCHAR(20),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");
  } else {
    // Ensure reservation_ref_code exists if not already there
    $col = $con->query("SHOW COLUMNS FROM point_transactions LIKE 'reservation_ref_code'");
    if (!$col || $col->num_rows === 0) {
      $con->query("ALTER TABLE point_transactions ADD COLUMN reservation_ref_code VARCHAR(20) AFTER description");
    }
  }
}
ensureTransactionHistoryTable($con);

// Ensure reservations table has point redemption columns
function ensureReservationPointColumns($con){
  if (!($con instanceof mysqli)) { return; }
  $col1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'use_points'");
  if (!$col1 || $col1->num_rows === 0) {
    $con->query("ALTER TABLE reservations ADD COLUMN use_points TINYINT(1) DEFAULT 0");
  }
  $col2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'points_used'");
  if (!$col2 || $col2->num_rows === 0) {
    $con->query("ALTER TABLE reservations ADD COLUMN points_used INT DEFAULT 0");
  }
}
ensureReservationPointColumns($con);

/**
 * Single source of truth for a resident's VHEcoPoint balance.
 * Matches profileresident.php exactly: only transactions that are
 * provably VHEcoPoint-sourced (ecopoint_session_id FK set, or
 * description mentions "VHEcoPoint"/"recycling") are included.
 * Net = earn − redeem + adjustment (adjustments are signed).
 */
function reserveCalcVHEcoBalance(mysqli $con, int $userId): int {
    if ($userId <= 0) return 0;
    try {
        $tblChk = $con->query("SHOW TABLES LIKE 'point_transactions'");
        if (!$tblChk || $tblChk->num_rows === 0) return 0;
        $colQ = $con->query("SHOW COLUMNS FROM point_transactions LIKE 'ecopoint_session_id'");
        $hasFk = ($colQ && $colQ->num_rows > 0);
        if ($hasFk) {
            $stmt = $con->prepare("SELECT id, transaction_type, amount, description, ecopoint_session_id FROM point_transactions WHERE user_id = ?");
        } else {
            $stmt = $con->prepare("SELECT id, transaction_type, amount, description, NULL AS ecopoint_session_id FROM point_transactions WHERE user_id = ?");
        }
        if (!$stmt) return 0;
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
    } catch (Throwable $e) {
        return 0;
    }
    $balance = 0;
    while ($row = $res->fetch_assoc()) {
        $desc = (string)($row['description'] ?? '');
        $isEcoTx = (!empty($row['ecopoint_session_id']) && intval($row['ecopoint_session_id']) > 0)
                 || (stripos($desc, 'VHEcoPoint') !== false)
                 || (stripos($desc, 'recycling') !== false)
                 || (stripos($desc, 'Redeemed points') !== false)
                 || (strtolower(trim((string)($row['transaction_type'] ?? ''))) === 'redeem' && !empty($row['reservation_ref_code']));
        if (!$isEcoTx) continue;
        $txType = strtolower(trim((string)($row['transaction_type'] ?? 'earn')));
        $amt = intval($row['amount'] ?? 0);
        if ($txType === 'earn')              $balance += $amt;
        elseif ($txType === 'redeem')        $balance -= $amt;
        elseif ($txType === 'adjustment')    $balance += $amt;
    }
    $stmt->close();
    return max(0, $balance);
}

// Downpayment moved on-page: do not force redirect; users can pay via GCash from the form

function generateUniqueRefCode($con){
  $tries=0; $code='';
  while($tries<6){
    $candidate='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT);
    $exists=false;
    if($con instanceof mysqli){
      $q1=$con->prepare("SELECT 1 FROM reservations WHERE ref_code=? LIMIT 1");
      $q1->bind_param('s',$candidate); $q1->execute(); $r1=$q1->get_result(); $exists = $exists || ($r1 && $r1->num_rows>0); $q1->close();
      $q2=$con->prepare("SELECT 1 FROM guest_forms WHERE ref_code=? LIMIT 1");
      $q2->bind_param('s',$candidate); $q2->execute(); $r2=$q2->get_result(); $exists = $exists || ($r2 && $r2->num_rows>0); $q2->close();
    }
    if(!$exists){ $code=$candidate; break; }
    $tries++;
  }
  if($code===''){ $code='VP-'.str_pad(rand(0,99999),5,'0',STR_PAD_LEFT); }
  return $code;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
  if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
    $errorMsg = 'Invalid form submission.';
  } else {
    $use_points_post = isset($_POST['use_points']) ? intval($_POST['use_points']) : 0;
    $amenity = isset($_POST['amenity']) ? $_POST['amenity'] : '';
    $start   = isset($_POST['startDate']) ? $_POST['startDate'] : '';
    $end     = isset($_POST['endDate']) ? $_POST['endDate'] : '';
    $startTime = isset($_POST['startTime']) ? $_POST['startTime'] : '';
    $endTime   = isset($_POST['endTime']) ? $_POST['endTime'] : '';
    $persons = intval($_POST['persons'] ?? 1);
    $hours = intval($_POST['hours'] ?? 0);
    $downpayment = isset($_POST['downpayment']) ? floatval($_POST['downpayment']) : null;
    $purpose = isset($_POST['purpose']) ? trim($_POST['purpose']) : null;
    $booking_for_post = isset($_POST['booking_for']) ? trim($_POST['booking_for']) : '';
    $guest_id_post = isset($_POST['guest_id']) ? trim($_POST['guest_id']) : '';
    $guest_ref_code_post = isset($_POST['guest_ref_code']) ? trim($_POST['guest_ref_code']) : '';
    $residentsCount = max(0, intval($_POST['residents_count'] ?? 0));
    $guestsCount = max(0, intval($_POST['guests_count'] ?? 0));
    if ($residentsCount + $guestsCount <= 0) { $guestsCount = max(1, $persons); }
    $entry_pass_id = (isset($_POST['entry_pass_id']) && $_POST['entry_pass_id'] !== '') ? intval($_POST['entry_pass_id']) : ((isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : NULL);
    $ref_code = isset($_POST['ref_code']) ? $_POST['ref_code'] : (isset($_GET['ref_code']) ? $_GET['ref_code'] : '');
    $guestResidentId = null;
    if ($ref_code !== '' && ($con instanceof mysqli)) {
      try {
        $stmtG = $con->prepare("SELECT resident_user_id FROM guest_forms WHERE ref_code = ? LIMIT 1");
        $stmtG->bind_param('s', $ref_code);
        $stmtG->execute();
        $resG = $stmtG->get_result();
        if ($resG && ($gRow = $resG->fetch_assoc())) {
          $rid = isset($gRow['resident_user_id']) ? intval($gRow['resident_user_id']) : 0;
          if ($rid > 0) { $guestResidentId = $rid; }
        }
        $stmtG->close();
      } catch (Throwable $e) {
        error_log('reserve.php guest link lookup error: ' . $e->getMessage());
      }
    }
    if ($guestResidentId) { $entry_pass_id = NULL; }
    $user_id = $guestResidentId ?: (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : NULL);
    $acct = ($guestResidentId || (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident' && (empty($entry_pass_id)))) ? 'resident' : 'visitor';
    $booking_for = $booking_for_post;
    if ($booking_for === '') {
      if ($guestResidentId) {
        $booking_for = 'guest';
      } else if ($acct === 'resident') {
        $booking_for = 'resident';
      }
    }
    if ($booking_for === '') { $booking_for = null; }
    if (in_array($amenity, ['Basketball Court','Tennis Court'], true)) {
      $rate = ($booking_for === 'resident') ? 100 : 150;
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Clubhouse') {
      $rate = ($booking_for === 'resident') ? 300 : 450;
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Multi-Purpose Building') {
      $rate = ($booking_for === 'resident') ? 200 : 300;
      $basePrice = max(1, $hours) * $rate;
    } else {
      $basePrice = 0;
    }
    if ($use_points_post && $acct === 'resident') {
      $paidHours = max(0, $hours - 1);
      $price = round($paidHours * $rate, 2);
      $downpayment = 0;
    } else {
      $price = round($basePrice, 2);
      $downpayment = round($price * 0.5, 2);
    }
    $allowedAmenities = ['Clubhouse','Multi-Purpose Building','Basketball Court','Tennis Court'];
    if (!in_array($amenity, $allowedAmenities, true)) { $errorMsg = 'Please select an amenity.'; }
    $sdObj = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    $edObj = $end ? DateTime::createFromFormat('Y-m-d', $end) : false;
    $stObj = $startTime ? DateTime::createFromFormat('H:i', $startTime) : false;
    $etObj = $endTime ? DateTime::createFromFormat('H:i', $endTime) : false;
    // If the client sent a malformed end time (e.g. a stale/cached page that posts a
    // bare hour number or HH:MM:SS which MySQL would mangle into 00:00:NN), derive the
    // end time from the validated start time + selected hours instead of rejecting.
    if (!$etObj && $stObj && $hours > 0) {
      $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
      $endH = (int)$stObj->format('H') + $hours;
      $endM = (int)$stObj->format('i');
      if ($endH > $maxH) { $endH = $maxH; $endM = 0; }
      $endTime = sprintf('%02d:%02d', $endH, $endM);
      $etObj = DateTime::createFromFormat('H:i', $endTime);
    }
    if (!$sdObj || !$edObj) {
      $errorMsg = 'Please select a start and end date.';
    } else if (!$stObj || !$etObj) {
      $errorMsg = 'Please select a start and end time.';
    } else if ($sdObj && $edObj && $sdObj > $edObj) {
      $errorMsg = 'Start date must be before end date.';
    } else if ($start === $end && $stObj && $etObj && $stObj >= $etObj) {
      $errorMsg = 'Start time must be before end time.';
    } else if (($sdObj && $edObj)) {
      $minDate = new DateTime('today');
      $minDate->modify('+1 day');
      if ($sdObj < $minDate || $edObj < $minDate) {
        $errorMsg = 'Reservations must be made at least 1 day in advance.';
      }
    } else {
      $maxPersons = 0;
      if ($amenity === 'Clubhouse') { $maxPersons = 200; }
      else if ($amenity === 'Multi-Purpose Building') { $maxPersons = 50; }
      else if ($amenity === 'Tennis Court') { $maxPersons = 60; }
      else if ($amenity === 'Basketball Court') { $maxPersons = 30; }
      if ($persons < 1) {
        $errorMsg = 'Persons must be at least 1.';
      } else if ($maxPersons > 0 && $persons > $maxPersons) {
        $errorMsg = 'Maximum is '.$maxPersons.' persons.';
      }
    }
    if (!$errorMsg && $sdObj && $edObj) {
      $diffDays = $sdObj->diff($edObj)->days;
      if ($diffDays > 6) { $errorMsg = 'Cannot book more than 1 week.'; }
    }
    if (!$errorMsg && $stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
      if ((int)$stObj->format('H') < $minH || (int)$etObj->format('H') > $maxH) {
        $errorMsg = 'Selected time is outside operating hours.';
      }
    }
    $visitorFlow = (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident');
      if (!$errorMsg) {
        $cnt = 0;
        $singleDay = ($start && $end && $start === $end && $startTime && $endTime);
        try {
          if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
          if (false) {
            $startDateObj = DateTime::createFromFormat('Y-m-d', $start);
            $endDateObj = DateTime::createFromFormat('Y-m-d', $end);
            if (!$startDateObj || !$endDateObj) { throw new Exception('Invalid date range'); }
            $period = new DatePeriod($startDateObj, new DateInterval('P1D'), (clone $endDateObj)->modify('+1 day'));
            $hasRRRef = false;
            $hasGFRef = false;
            $chkRRRef = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'ref_code'"); if ($chkRRRef && $chkRRRef->num_rows>0) { $hasRRRef = true; }
            $chkGFRef = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'ref_code'"); if ($chkGFRef && $chkGFRef->num_rows>0) { $hasGFRef = true; }
            foreach ($period as $d) {
              $ds = $d->format('Y-m-d');
              $total = 0;
              $s1 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date");
              $s1->bind_param('ss', $amenity, $ds);
              $s1->execute();
              $r1 = $s1->get_result();
              $total += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['total']) : 0;
              $s1->close();
              $hasRPersons = false; $hasGPersons = false;
              $chkR = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'persons'"); if ($chkR && $chkR->num_rows>0) { $hasRPersons = true; }
              $chkG = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'persons'"); if ($chkG && $chkG->num_rows>0) { $hasGPersons = true; }
              if ($hasRPersons) {
                if ($hasRRRef) {
                  $s2 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
                  $s2->bind_param('ssss', $amenity, $ds, $amenity, $ds);
                } else {
                  $s2 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date");
                  $s2->bind_param('ss', $amenity, $ds);
                }
                $s2->execute();
                $r2 = $s2->get_result();
                $total += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['total']) : 0;
                $s2->close();
              }
              if ($hasGPersons) {
                if ($hasGFRef) {
                  $s3 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
                  $s3->bind_param('ssss', $amenity, $ds, $amenity, $ds);
                } else {
                  $s3 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date");
                  $s3->bind_param('ss', $amenity, $ds);
                }
                $s3->execute();
                $r3 = $s3->get_result();
                $total += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['total']) : 0;
                $s3->close();
              }
              if ($total + $persons > $cap) { $cnt = 1; break; }
            }
          } else if ($singleDay) {
              $check1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
            $check1->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            $check1->execute(); $r1 = $check1->get_result(); $cnt += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['c']) : 0; $check1->close();
            $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
            $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
            if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
              $check2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
              $check2->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            } else {
              $check2 = $con->prepare("SELECT 0 AS c");
            }
            $check2->execute(); $r2 = $check2->get_result(); $cnt += ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0; $check2->close();
            $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
            $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
            if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
              $check3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
              $check3->bind_param("ssss", $amenity, $start, $startTime, $endTime);
            } else {
              $check3 = $con->prepare("SELECT 0 AS c");
            }
            $check3->execute(); $r3 = $check3->get_result(); $cnt += ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0; $check3->close();
          } else {
            $hourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse','Multi-Purpose Building'], true);
            $minH = 9;
            $maxH = in_array($amenity, ['Clubhouse','Multi-Purpose Building'], true) ? 21 : 18;
            $totalHours = max(0, $maxH - $minH);

            $startDateObj = DateTime::createFromFormat('Y-m-d', $start);
            $endDateObj = DateTime::createFromFormat('Y-m-d', $end);
            if (!$startDateObj || !$endDateObj) { throw new Exception('Invalid date range'); }
            $period = new DatePeriod($startDateObj, new DateInterval('P1D'), (clone $endDateObj)->modify('+1 day'));

            $cnt = 0; // count of fully booked days in range

            foreach ($period as $d) {
              $ds = $d->format('Y-m-d');
              $reservedHours = 0;
              $marked = [];

              // reservations with time overlap on this date
              $q1 = $con->prepare("SELECT start_time, end_time FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date");
              $q1->bind_param('ss', $amenity, $ds);
              $q1->execute();
              $res1 = $q1->get_result();
              while ($row = $res1->fetch_assoc()) {
                $st = !empty($row['start_time']) ? $row['start_time'] : '00:00:00';
                $et = !empty($row['end_time']) ? $row['end_time'] : '23:59:59';
                if ($hourBased && (empty($row['start_time']) || empty($row['end_time']))) { continue; }
                $bS = intval(substr($st, 0, 2));
                $bE = intval(substr($et, 0, 2));
                for ($h = $bS; $h < $bE; $h++) {
                  if ($h >= $minH && $h < $maxH) { if (!isset($marked[$h])) { $marked[$h] = true; $reservedHours++; } }
                }
              }
              $q1->close();

              // resident_reservations (may not have time columns)
              $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
              $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
              if ($hasRt && $hasRt->num_rows > 0 && $hasRe && $hasRe->num_rows > 0) {
                $q2 = $con->prepare("SELECT start_time, end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date");
              } else {
                $q2 = $con->prepare("SELECT NULL AS start_time, NULL AS end_time WHERE 0=1");
              }
              if ($hasRt && $hasRt->num_rows > 0 && $hasRe && $hasRe->num_rows > 0) {
                $q2->bind_param('ss', $amenity, $ds);
              }
              $q2->execute();
              $res2 = $q2->get_result();
              while ($row = $res2->fetch_assoc()) {
                $st = !empty($row['start_time']) ? $row['start_time'] : '00:00:00';
                $et = !empty($row['end_time']) ? $row['end_time'] : '23:59:59';
                if ($hourBased && (empty($row['start_time']) || empty($row['end_time']))) { continue; }
                $bS = intval(substr($st, 0, 2));
                $bE = intval(substr($et, 0, 2));
                for ($h = $bS; $h < $bE; $h++) {
                  if ($h >= $minH && $h < $maxH) { if (!isset($marked[$h])) { $marked[$h] = true; $reservedHours++; } }
                }
              }
              $q2->close();

              // guest_forms (may not have time columns)
              $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
              $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
              if ($hasGt && $hasGt->num_rows > 0 && $hasGe && $hasGe->num_rows > 0) {
                $q3 = $con->prepare("SELECT start_time, end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date");
                $q3->bind_param('ss', $amenity, $ds);
              } else {
                $q3 = $con->prepare("SELECT NULL AS start_time, NULL AS end_time WHERE 0=1");
              }
              $q3->execute();
              $res3 = $q3->get_result();
              while ($row = $res3->fetch_assoc()) {
                $st = !empty($row['start_time']) ? $row['start_time'] : '00:00:00';
                $et = !empty($row['end_time']) ? $row['end_time'] : '23:59:59';
                if ($hourBased && (empty($row['start_time']) || empty($row['end_time']))) { continue; }
                $bS = intval(substr($st, 0, 2));
                $bE = intval(substr($et, 0, 2));
                for ($h = $bS; $h < $bE; $h++) {
                  if ($h >= $minH && $h < $maxH) { if (!isset($marked[$h])) { $marked[$h] = true; $reservedHours++; } }
                }
              }
              $q3->close();

              if ($reservedHours >= $totalHours) { $cnt++; break; }
            }
          }
        } catch (Throwable $e) {
          error_log('reserve.php POST error: ' . $e->getMessage());
          $errorMsg = 'Server error. Please try again later.';
        }
        if (!$errorMsg && $cnt > 0) {
          $errorMsg = 'Selected dates include a fully booked day. Please adjust your range or choose different dates.';
        }
        if (!$errorMsg) {
          $paidOk = false;
          if ($ref_code !== '') {
            try {
              if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
              $s1 = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
              $s1->bind_param('s', $ref_code);
              $s1->execute();
              $g1 = $s1->get_result();
              if ($g1 && ($rr = $g1->fetch_assoc())) {
                $ps = strtolower(trim($rr['payment_status'] ?? ''));
                $paidOk = ($ps === 'verified');
              }
              $s1->close();
            } catch (Throwable $e) {
              error_log('reserve.php payment check error: ' . $e->getMessage());
            }
          }
          $newRef = $ref_code !== '' ? $ref_code : generateUniqueRefCode($con);
          if (!$errorMsg) {
            // Check if using points and validate
            $use_points_post = isset($_POST['use_points']) ? intval($_POST['use_points']) : 0;
            $points_required = 0;
            if ($use_points_post && $acct === 'resident' && isset($_SESSION['user_id'])) {
              // Points cover 1 free hour; remaining hours are paid in cash.
              // Calculate points required (always for 1 hour regardless of total duration).
              switch ($amenity) {
                case 'Basketball Court':
                case 'Tennis Court':
                  $points_required = 300;
                  break;
                case 'Clubhouse':
                  $points_required = 600;
                  break;
                case 'Multi-Purpose Building':
                  $points_required = 750;
                  break;
                default:
                  $errorMsg = "Invalid amenity for point redemption.";
              }
              if (!$errorMsg) {
                $current_points = reserveCalcVHEcoBalance($con, intval($_SESSION['user_id']));
                if ($current_points < $points_required) {
                  $errorMsg = "Insufficient VHEcoPoint Balance: You need " . $points_required . " pts to redeem 1 free hour for this amenity, but your current VHEcoPoint ledger balance is " . $current_points . " pts. Earn more points by recycling eligible materials at the VHEcoPoint Smart Waste Segregation Station.";
                }
              }
              if (!$errorMsg) {
                $paidOk = true;
              }
            }

            // Authoritative end time = start time + selected hours (server-side).
            // The browser-submitted endTime is never trusted here, so a stale/cached
            // client can no longer store a corrupt time (e.g. bare hour number -> 00:00:NN).
            if ($startTime !== '' && $hours > 0 && preg_match('/^(\d{1,2}):(\d{2})/', $startTime, $m)) {
              $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
              $endH = (int)$m[1] + $hours;
              $endM = (int)$m[2];
              if ($endH > $maxH) { $endH = $maxH; $endM = 0; }
              $endTime = sprintf('%02d:%02d', $endH, $endM);
              $etObj = DateTime::createFromFormat('H:i', $endTime);
            }

            // Store reservation info in session for confirmation/debugging if needed
            $_SESSION['pending_reservation'] = [
              'amenity' => $amenity,
              'start_date' => $start,
              'end_date' => $end,
              'start_time' => $startTime,
              'end_time' => $endTime,
              'hours' => $hours,
              'persons' => $persons,
              'price' => $price,
              'downpayment' => $downpayment,
              'user_id' => $user_id,
              'entry_pass_id' => $entry_pass_id,
              'booking_for' => $booking_for,
              'guest_id' => $guest_id_post,
              'guest_ref_code' => $guest_ref_code_post,
              'ref_code' => $newRef,
              'use_points' => $use_points_post,
              'points_used' => $points_required
            ];
            $generatedCode = $newRef;
            $canSubmit = true;
            // Prevent double submission
            if (isset($_SESSION['reservation_submitted']) && $_SESSION['reservation_submitted'] === $newRef) {
              $errorMsg = 'This reservation has already been submitted.';
            } else {
              $_SESSION['reservation_submitted'] = $newRef;
              // If using points, deduct the free hour and proceed
              if ($use_points_post && !$errorMsg) {
                try {
                  $con->begin_transaction();
                  
                  // Deduct points
                  $stmtDeduct = $con->prepare("UPDATE users SET points = points - ? WHERE id = ?");
                  $stmtDeduct->bind_param('ii', $points_required, $_SESSION['user_id']);
                  $stmtDeduct->execute();
                  $stmtDeduct->close();
                  
                  // Record transaction in point_transactions
                  $txnDescription = "Redeemed points for 1 free hour of " . htmlspecialchars($amenity) . " booking (Ref: " . $newRef . ")";
                  $stmtTxn = $con->prepare("INSERT INTO point_transactions (user_id, transaction_type, amount, description, reservation_ref_code) VALUES (?, 'redeem', ?, ?, ?)");
                  $stmtTxn->bind_param('iiss', $_SESSION['user_id'], $points_required, $txnDescription, $newRef);
                  $stmtTxn->execute();
                  $stmtTxn->close();
                  
                  // Insert reservation with use_points and points_used
                  $stmt = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, user_id, entry_pass_id, booking_for, account_type, payment_status, approval_status, status, use_points, points_used) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', ?, ?)");
                  $stmt->bind_param('ssssssiddiissii', $newRef, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $user_id, $entry_pass_id, $booking_for, $acct, $use_points_post, $points_required);
                  $stmt->execute();
                  $stmt->close();
                  
                  $con->commit();
                  
                  // Store pending reservation in session for downpayment page
                  $_SESSION['pending_reservation']['use_points'] = $use_points_post;
                  $_SESSION['pending_reservation']['points_used'] = $points_required;
                  
                  if ($price > 0) {
                    // Remaining balance exists — redirect to downpayment for the paid hours
                    $redir = 'downpayment.php?continue=' . (($acct === 'resident') ? 'reserve_resident' : 'reserve');
                    if (!empty($entry_pass_id)) { $redir .= '&entry_pass_id=' . urlencode((string)$entry_pass_id); }
                    $redir .= '&ref_code=' . urlencode($newRef);
                    header('Location: ' . $redir);
                    exit;
                  } else {
                    // Fully free (1-hour booking with points) — mark verified and redirect
                    $con->query("UPDATE reservations SET payment_status='verified' WHERE ref_code='" . $con->real_escape_string($newRef) . "'");
                    header('Location: profileresident.php?reservation_success=1&points_used=' . $points_required);
                    exit;
                  }
                } catch (Throwable $e) {
                  $con->rollback();
                  error_log('reserve.php point redemption booking error: ' . $e->getMessage());
                  $errorMsg = 'An error occurred while processing your points redemption. Please try again.';
                }
              } else {
                // Defer guest code notification until after downpayment submission
                // Redirect to downpayment page with role-aware flow and ref_code
                $redir = 'downpayment.php?continue=' . (($acct === 'resident') ? 'reserve_resident' : 'reserve');
                if (!empty($entry_pass_id)) { $redir .= '&entry_pass_id=' . urlencode((string)$entry_pass_id); }
                if (!empty($newRef)) { $redir .= '&ref_code=' . urlencode($newRef); }
                header('Location: ' . $redir);
                exit;
              }
            }
          }
        }
      }
    }
  }
// End of POST handler

// Lightweight endpoint to return booked dates for the selected amenity
if (isset($_GET['action']) && $_GET['action'] === 'booked_dates') {
  header('Content-Type: application/json');
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $dates = [];
  if ($amenity === '') { echo json_encode(['dates' => []]); exit; }
  $collect = function($res) use (&$dates) {
    while ($row = $res->fetch_assoc()) {
      if (empty($row['start_date']) || empty($row['end_date'])) continue;
      $start = new DateTime($row['start_date']);
      $end = new DateTime($row['end_date']);
      $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
      foreach ($period as $d) { $dates[] = $d->format('Y-m-d'); }
    }
  };
  try {
    if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
    $stmt1 = $con->prepare("SELECT start_date, end_date FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history'))");
    $stmt1->bind_param("s", $amenity); $stmt1->execute(); $collect($stmt1->get_result()); $stmt1->close();
    $stmt2 = $con->prepare("SELECT start_date, end_date FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
    $stmt2->bind_param("s", $amenity); $stmt2->execute(); $collect($stmt2->get_result()); $stmt2->close();
    $stmt3 = $con->prepare("SELECT start_date, end_date FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')");
    $stmt3->bind_param("s", $amenity); $stmt3->execute(); $collect($stmt3->get_result()); $stmt3->close();
  } catch (Throwable $e) {
    error_log('reserve.php booked_dates error: ' . $e->getMessage());
    $dates = [];
  }
  echo json_encode(['dates' => array_values(array_unique($dates))]);
  exit;
}

// Submission gate: require payment before submission for visitors
$refFromQuery = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
if ($refFromQuery !== '') {
try {
  if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
$stmtGate = $con->prepare("SELECT payment_status, amenity, start_date FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmtGate->bind_param('s', $refFromQuery);
  $stmtGate->execute();
  $resGate = $stmtGate->get_result();
  if ($resGate && ($rw = $resGate->fetch_assoc())) {
    if (!empty($rw['amenity']) && !empty($rw['start_date'])) {
      $_SESSION['flash_notice'] = 'A reservation already exists for this QR reference code. Please wait for email notification.';
      if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
        header('Location: profileresident.php');
      } else {
        header('Location: mainpage.php');
      }
      exit;
    }
    $ps = strtolower(trim($rw['payment_status'] ?? ''));
    $canSubmit = ($ps === 'verified');
  } else {
    $canSubmit = false;
  }
  $stmtGate->close();
} catch (Throwable $e) {
  error_log('reserve.php gate error: ' . $e->getMessage());
  $canSubmit = false;
}
}
// Enforce gate for visitors (no resident session or entry_pass_id provided)
if ((!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') && (!isset($_GET['entry_pass_id']) || $_GET['entry_pass_id'] === '')) {
  if ($refFromQuery === '') { $canSubmit = false; }
}

// Endpoint: booked_times for a specific date and amenity
if (isset($_GET['action']) && $_GET['action'] === 'booked_times') {
  header('Content-Type: application/json');
  $amenity = isset($_GET['amenity']) ? trim($_GET['amenity']) : '';
  $date = $_GET['date'] ?? '';
  $startDate = $_GET['start_date'] ?? $date;
  $endDate = $_GET['end_date'] ?? $date;
  $times = [];
  $personsTotal = 0;
  $capacity = 0;
  $personsByDate = [];
  $slotBookings = [];
  if ($amenity !== '' && ($date || ($startDate && $endDate))) {
    try {
      if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }

      // Filter: bookings whose date range overlaps [startDate, endDate]
      $dateFilter = " AND start_date <= ? AND end_date >= ?";

      // Query 1: reservations table (has start_time/end_time)
      $sql1 = "SELECT start_date, end_date, start_time, end_time, persons, approval_status, status, use_points FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history'))" . $dateFilter;
      $stmt1 = $con->prepare($sql1);
      if ($stmt1) {
        $stmt1->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt1->execute();
        $res1 = $stmt1->get_result();
        while ($row = $res1->fetch_assoc()) {
          $hasTime = !empty($row['start_time']) && !empty($row['end_time']);
          $times[] = [
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'start'      => $hasTime ? $row['start_time'] : null,
            'end'        => $hasTime ? $row['end_time'] : null,
            'has_time'   => $hasTime ? 1 : 0,
            'persons'    => intval($row['persons'] ?? 0),
            'use_points' => intval($row['use_points'] ?? 0),
          ];
          if (!empty($row['persons'])) {
            $personsTotal += intval($row['persons']);
          }
        }
        $stmt1->close();
      }

      // Query 2: resident_reservations table (may not have time columns)
      $sql2 = "SELECT start_date, end_date, approval_status, ref_code FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')" . $dateFilter;
      $stmt2 = $con->prepare($sql2);
      if ($stmt2) {
        $stmt2->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
          $hasTime = false;
          if (!empty($row['ref_code']) && ($con instanceof mysqli)) {
            $stmt2b = $con->prepare("SELECT start_time, end_time, persons, use_points FROM reservations WHERE ref_code = ? LIMIT 1");
            if ($stmt2b) {
              $stmt2b->bind_param("s", $row['ref_code']);
              $stmt2b->execute();
              $res2b = $stmt2b->get_result();
              if ($res2b && ($r2b = $res2b->fetch_assoc())) {
                $hasTime = !empty($r2b['start_time']) && !empty($r2b['end_time']);
                $times[] = [
                  'start_date' => $row['start_date'],
                  'end_date'   => $row['end_date'],
                  'start'      => $hasTime ? $r2b['start_time'] : null,
                  'end'        => $hasTime ? $r2b['end_time'] : null,
                  'has_time'   => $hasTime ? 1 : 0,
                  'persons'    => intval($r2b['persons'] ?? 0),
                  'use_points' => intval($r2b['use_points'] ?? 0),
                ];
                if (!empty($r2b['persons'])) {
                  $personsTotal += intval($r2b['persons']);
                }
              }
              $stmt2b->close();
            }
          }
          if (!$hasTime) {
            $times[] = [
              'start_date' => $row['start_date'],
              'end_date'   => $row['end_date'],
              'start'      => null,
              'end'        => null,
              'has_time'   => 0,
              'persons'    => 0,
              'use_points' => 0,
            ];
          }
        }
        $stmt2->close();
      }

      // Query 3: guest_forms table (may not have time columns)
      $sql3 = "SELECT start_date, end_date, persons, approval_status FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved')" . $dateFilter;
      $stmt3 = $con->prepare($sql3);
      if ($stmt3) {
        $stmt3->bind_param("sss", $amenity, $endDate, $startDate);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) {
          $times[] = [
            'start_date' => $row['start_date'],
            'end_date'   => $row['end_date'],
            'start'      => null,
            'end'        => null,
            'has_time'   => 0,
            'persons'    => intval($row['persons'] ?? 0),
            'use_points' => 0,
          ];
          if (!empty($row['persons'])) {
            $personsTotal += intval($row['persons']);
          }
        }
        $stmt3->close();
      }

    } catch (Throwable $e) {
      error_log('reserve.php booked_times error: ' . $e->getMessage());
      $times = [];
      $personsTotal = 0;
      $capacity = 0;
      $slotBookings = [];
    }
  }
  echo json_encode(['times' => $times, 'persons_total' => $personsTotal, 'capacity' => $capacity, 'persons_by_date' => $personsByDate, 'slot_bookings' => $slotBookings]);
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'check_points') {
  header('Content-Type: application/json');
  if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
    echo json_encode(['ok' => false, 'balance' => 0, 'error' => 'Not authenticated as resident.']);
    exit;
  }
  $uid = intval($_SESSION['user_id']);
  $balance = reserveCalcVHEcoBalance($con, $uid);
  echo json_encode(['ok' => true, 'balance' => $balance]);
  exit;
}
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
  $accountLink = 'profileresident.php';
} elseif (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'visitor') {
  $accountLink = 'dashboardvisitor.php';
} else {
  $accountLink = 'mainpage.php';
}

$residentGuests = [];
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident' && isset($_SESSION['user_id']) && ($con instanceof mysqli)) {
  $rid = intval($_SESSION['user_id']);
  $stmtRG = $con->prepare("SELECT id, visitor_first_name, visitor_middle_name, visitor_last_name, visitor_email, visitor_contact, ref_code FROM guest_forms WHERE resident_user_id = ? AND approval_status = 'approved' ORDER BY created_at DESC");
  if ($stmtRG) {
    $stmtRG->bind_param('i', $rid);
    $stmtRG->execute();
    $resRG = $stmtRG->get_result();
    while ($row = $resRG->fetch_assoc()) {
      $residentGuests[] = $row;
    }
    $stmtRG->close();
  }
}
$currentResident = null;
$residentPoints = 0;
$householdResidents = [];
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident' && isset($_SESSION['user_id']) && ($con instanceof mysqli)) {
  $rid = intval($_SESSION['user_id']);
  
  // Get resident info including points (VHEcoPoint-ONLY ledger — matches profileresident.php)
  $stmtU = $con->prepare("SELECT id, first_name, middle_name, last_name, house_number, points FROM users WHERE id = ? LIMIT 1");
  if ($stmtU) {
    $stmtU->bind_param('i', $rid);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    if ($resU && $resU->num_rows) { 
      $currentResident = $resU->fetch_assoc();
      $residentPoints = reserveCalcVHEcoBalance($con, $rid);
    }
    $stmtU->close();
  }

  // Get resident's booking history
  $bookingCounts = [
    'Clubhouse' => 0,
    'Multi-Purpose Building' => 0,
    'Basketball Court' => 0,
    'Tennis Court' => 0
  ];

  // Check both reservations and resident_reservations tables
  $stmtHistory1 = $con->prepare("SELECT amenity FROM reservations WHERE user_id = ? AND status NOT IN ('cancelled','deleted','moved_to_history') ORDER BY created_at DESC LIMIT 50");
  if ($stmtHistory1) {
    $stmtHistory1->bind_param('i', $rid);
    $stmtHistory1->execute();
    $resHistory1 = $stmtHistory1->get_result();
    while ($row = $resHistory1->fetch_assoc()) {
      if (isset($bookingCounts[$row['amenity']])) {
        $bookingCounts[$row['amenity']]++;
      }
    }
    $stmtHistory1->close();
  }

  $stmtHistory2 = $con->prepare("SELECT amenity FROM resident_reservations WHERE user_id = ? AND approval_status IN ('pending','approved') ORDER BY created_at DESC LIMIT 50");
  if ($stmtHistory2) {
    $stmtHistory2->bind_param('i', $rid);
    $stmtHistory2->execute();
    $resHistory2 = $stmtHistory2->get_result();
    while ($row = $resHistory2->fetch_assoc()) {
      if (isset($bookingCounts[$row['amenity']])) {
        $bookingCounts[$row['amenity']]++;
      }
    }
    $stmtHistory2->close();
  }

  if ($currentResident && !empty($currentResident['house_number'])) {
    $hn = $currentResident['house_number'];
    $stmtH = $con->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE user_type = 'resident' AND status = 'active' AND house_number = ? AND id <> ? ORDER BY created_at DESC");
    if ($stmtH) {
      $stmtH->bind_param('si', $hn, $rid);
      $stmtH->execute();
      $resH = $stmtH->get_result();
      while ($row = $resH->fetch_assoc()) { $householdResidents[] = $row; }
      $stmtH->close();
    }
  }
}
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass - Reserve</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="CSS/reserve.css?v=12">
</head>
<body>
  <div id="notifyLayer" class="toast"></div>
   
<header class="navbar">
  <div class="logo-wrap">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
  </div>
  <div class="ecopoint-badge">
    <span class="ecopoint-icon"><i class="fa-solid fa-recycle"></i></span>
    <span class="ecopoint-text">VHEcoPoint</span>
  </div>
</header>

<section class="hero">
  <div class="layout">
    <div class="left-panel">
      <div class="top-actions">
        <button type="button" id="accountBackBtn" class="btn-secondary back-account-btn" aria-label="Back" onclick="window.location.href='<?php echo htmlspecialchars($accountLink, ENT_QUOTES); ?>'"><i class="fa-solid fa-arrow-left"></i></button>
      </div>
      
      <?php $isResident = (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident'); ?>

      <?php if ($isResident): ?>
        <!-- View Rewards Modal -->
        <div id="viewRewardsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:10000; align-items:center; justify-content:center; padding:20px;">
          <div style="background:#fff; border-radius:20px; max-width:800px; width:100%; max-height:90vh; overflow-y:auto; position:relative;">
            <!-- Modal Header -->
            <div style="padding:24px 24px 0; display:flex; justify-content:space-between; align-items:center;">
              <h2 style="margin:0; color:#23412e; font-size:1.5rem; font-weight:800;"><i class="fa-solid fa-gift" aria-hidden="true"></i> View Rewards</h2>
              <button type="button" id="closeRewardsModal" style="background:#f3f4f6; border:none; width:36px; height:36px; border-radius:50%; font-size:1.25rem; cursor:pointer; color:#4b5563;">
                ×
              </button>
            </div>
            
            <!-- Modal Content -->
            <div style="padding:24px;">

              <!-- Current Points -->
              <div style="background:linear-gradient(135deg,#23412e,#1f3528); color:#fff; padding:20px; border-radius:16px; margin-bottom:24px;">
                <div style="font-size:0.9rem; opacity:0.9; margin-bottom:4px;">Your Current Balance</div>
                <div style="font-size:2.5rem; font-weight:800;"><?php echo number_format($residentPoints); ?> pts</div>
              </div>

              <!-- All Available Amenity Rewards -->
              <div style="margin-bottom:24px;">
                <div style="font-size:1.25rem; font-weight:800; color:#23412e; margin-bottom:16px;">All Available Amenities</div>
                <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px;">
                  <?php 
                    $allAmenities = [
                      ['name' => 'Basketball Court', 'points' => 300, 'img' => 'images/basketballcourt.png'],
                      ['name' => 'Tennis Court', 'points' => 300, 'img' => 'images/tenniscourt.png'],
                      ['name' => 'Clubhouse', 'points' => 600, 'img' => 'images/clubhouse.png'],
                      ['name' => 'Multi-Purpose Building', 'points' => 750, 'img' => 'images/multi purpose building.png']
                    ];
                    foreach ($allAmenities as $amenity): 
                      $isEligible = $residentPoints >= $amenity['points'];
                      $remainingPoints = $residentPoints - $amenity['points'];
                  ?>
                    <div class="amenity-reward-card" style="padding:20px; border-radius:16px; border:2px solid #e5e7eb; background:#ffffff; cursor:pointer; transition:all 0.2s;" data-amenity="<?php echo htmlspecialchars($amenity['name']); ?>" data-points="<?php echo htmlspecialchars($amenity['points']); ?>" data-eligible="<?php echo $isEligible ? 'true' : 'false'; ?>">
                      <div style="display:flex; align-items:flex-start; gap:12px; margin-bottom:12px;">
                        <div style="width:60px; height:60px; border-radius:12px; overflow:hidden; flex-shrink:0;">
                          <img src="<?php echo htmlspecialchars($amenity['img']); ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                        </div>
                        <div style="flex:1;">
                          <div style="font-weight:800; font-size:1rem; color:#111827;"><?php echo htmlspecialchars($amenity['name']); ?></div>
                          <div style="display:flex; gap:8px; align-items:center; font-size:0.8rem; margin-top:4px;">
                            <span style="color:#23412e; font-weight:700;"><?php echo number_format($amenity['points']); ?> pts / hour</span>
                            <?php if ($isEligible): ?>
                              <span style="background:#d1fae5; color:#065f46; padding:2px 8px; border-radius:10px; font-weight:700; font-size:0.75rem;"><i class="fa-solid fa-check" aria-hidden="true"></i> Eligible</span>
                            <?php else: ?>
                              <span style="background:#fee2e2; color:#991b1b; padding:2px 8px; border-radius:10px; font-weight:700; font-size:0.75rem;"><i class="fa-solid fa-xmark" aria-hidden="true"></i> Need <?php echo number_format($amenity['points'] - $residentPoints); ?> more</span>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                      <div style="display:flex; justify-content:space-between; align-items:center; gap:12px;">
                        <div style="display:flex; flex-direction:column;">
                          <span style="color:#6b7280; font-size:0.8rem;">Your balance:</span>
                          <span style="font-weight:800; color:#111827; font-size:0.9rem;"><?php echo number_format($residentPoints); ?> pts</span>
                        </div>
                        <button type="button" style="padding:8px 20px; border-radius:10px; border:none; font-weight:700; font-size:0.85rem; cursor:pointer; transition:all 0.2s; <?php echo $isEligible ? 'background:linear-gradient(135deg,#23412e,#1f5a33); color:#fff; box-shadow:0 2px 8px rgba(35,65,46,0.2);' : 'background:#e5e7eb; color:#6b7280; cursor:not-allowed;'; ?>">
                          <?php echo $isEligible ? 'Redeem' : 'Not Enough Points'; ?>
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>

              <!-- Points Earning Suggestions -->
              <?php 
                $showEarnPoints = false;
                foreach ($allAmenities as $amenity) {
                  if ($residentPoints < $amenity['points']) {
                    $showEarnPoints = true;
                    break;
                  }
                }
                if ($showEarnPoints): 
              ?>
                <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:16px; padding:20px;">
                  <div style="font-weight:800; color:#92400e; margin-bottom:12px; display:flex; align-items:center; gap:8px;">
                     <i class="fa-solid fa-lightbulb"></i> Earn More Points
                   </div>
                   <ul style="margin:0; padding-left:20px; color:#78350f; line-height:2;">
                     <li><i class="fa-solid fa-recycle" style="margin-right:4px;"></i> Recycle PET Plastic Bottles (≤1000ml): <strong>+55 points per kg</strong></li>
                     <li><i class="fa-solid fa-droplet" style="margin-right:4px;"></i> Recycle Aluminum Cans: <strong>+140 points per kg</strong></li>
                     <li><i class="fa-regular fa-file" style="margin-right:4px;"></i> Recycle Paper & Cardboard: <strong>+30 points per kg</strong></li>
                  </ul>
                  <p style="margin:12px 0 0 0; color:#78350f; font-size:0.95rem; line-height:1.5;">
                    Visit VHEcoPoint Smart Waste Segregation Station and recycle eligible materials to earn points that can be redeemed for free amenity reservations.
                  </p>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident'): ?>
      <div class="points-tracker" id="pointsTracker">
        <div class="points-tracker-header">
          <span class="points-tracker-label">Your Points:</span>
          <span class="points-balance-amount" id="current-points-display"><?php echo number_format($residentPoints); ?> pts</span>
        </div>
        <div class="points-tracker-note">These are your VHEcoPoint rewards - earn points by recycling at the Smart Waste Segregation Station.</div>
        <div class="points-tracker-body">
          <button class="view-rewards-btn" id="viewRewardsBtn">View Rewards</button>
        </div>
        <button class="points-tracker-toggle" id="trackerToggleBtn" aria-label="Toggle points tracker">−</button>
      </div>
      <?php endif; ?>

      <div class="section-header" id="amenitiesHeader"><h2>Amenities</h2><p>Choose an amenity below to view availability and reserve your preferred schedule.</p></div>

      <div class="booking-steps" aria-label="Booking steps">
        <div class="booking-steps-header">
          <div class="booking-steps-label">Reservation steps</div>
          <div class="booking-steps-toggle-wrap">
            <button type="button" class="booking-steps-toggle" id="bookingStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
          </div>
        </div>
        <div class="booking-steps-body">
          <div class="booking-step is-active" id="step-amenity">
            <div class="step-index">1</div>
            <div class="step-content">
              <div class="step-title">Select amenity</div>
              <div class="step-subtitle">Choose the VictorianPass facility you want to reserve</div>
            </div>
          </div>
          <div class="booking-step" id="step-schedule">
            <div class="step-index">2</div>
            <div class="step-content">
              <div class="step-title">Set schedule</div>
              <div class="step-subtitle">Pick an available date and time from the calendar</div>
            </div>
          </div>
          <div class="booking-step" id="step-review">
            <div class="step-index">3</div>
            <div class="step-content">
              <div class="step-title">Review &amp; pay</div>
              <div class="step-subtitle">Check your reservation details, cash payment, or point redemption guide</div>
            </div>
          </div>

        </div>
      </div>
      <?php if ($isResident): ?>
      <div class="booking-steps-protip-float" id="proTipFloat">
        <span class="protip-icon"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i></span>
        <span class="protip-text"><strong>Pro Tip:</strong> You can use the points you have collected in VHEcoPoint Smart Waste Segregation Station when booking an amenity for a free 1 hour. Click on an amenity above to see if you're eligible!</span>
        <button type="button" class="protip-dismiss" id="proTipDismiss" aria-label="Dismiss pro tip">&times;</button>
      </div>
      <?php endif; ?>

      <div class="amenities-wrapper">
        <div class="amenities-right">
          <div class="amenities-list" id="amenitiesList">
            <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="450">
              <div class="amenity-media">
                <img src="images/clubhouse.png" alt="Clubhouse">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Clubhouse</div>
                  <div class="amenity-short">A perfect venue for gatherings, celebrations, and community events within the subdivision.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Multi-Purpose Building" data-key="multipurpose" data-price="300">
              <div class="amenity-media">
                <img src="images/multi purpose building.png" alt="Multi-Purpose Building">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Multi-Purpose Building</div>
                  <div class="amenity-short">A versatile space for various community activities, events, and recreational uses.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Basketball Court" data-key="basketball" data-price="150">
              <div class="amenity-media">
                <img src="images/basketballcourt.png" alt="Basketball Court">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Basketball Court</div>
                  <div class="amenity-short">Our outdoor basketball court provides residents a space for recreation, sports, and fitness activities.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Tennis Court" data-key="tennis" data-price="150">
              <div class="amenity-media">
                <img src="images/tenniscourt.png" alt="Tennis Court">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Tennis Court</div>
                  <div class="amenity-short">Our tennis court offers residents a dedicated space for sports, recreation, and friendly matches.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
          </div>
            <div class="booking-shell">
            <?php if (!empty($errorMsg)) { ?><div class="alert-error"><?php echo htmlspecialchars($errorMsg); ?></div><?php } ?>
            <form method="POST">
          <input type="hidden" name="purpose" value="Amenity Reservation">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
          <input type="hidden" name="entry_pass_id" value="<?php echo (isset($_GET['entry_pass_id']) && $_GET['entry_pass_id'] !== '') ? intval($_GET['entry_pass_id']) : ''; ?>">
          <input type="hidden" name="ref_code" id="refCodeField" value="<?php echo htmlspecialchars($_GET['ref_code'] ?? ''); ?>">
          <input type="hidden" name="booking_for" id="bookingForField" value="<?php echo $isResident ? 'resident' : 'guest'; ?>">
          <input type="hidden" name="guest_id" id="guestIdField" value="">
          <input type="hidden" name="guest_ref_code" id="guestRefField" value="">
          <input type="hidden" name="residents_count" id="residentsCountInput" value="<?php echo (isset($_SESSION['user_type']) && $_SESSION['user_type']==='resident') ? '1' : '0'; ?>">
          <input type="hidden" name="guests_count" id="guestsCountInput" value="<?php echo (isset($_SESSION['user_type']) && $_SESSION['user_type']==='resident') ? '0' : '1'; ?>">
          <input type="hidden" id="submitAllowed" value="1">
          <input type="hidden" name="use_points" id="use-points-input" value="0">
            <div class="reservation-card" id="reservationCard" style="display:none;">
            <input type="hidden" name="amenity" id="amenityField" value="">
            <div class="reservation-grid">
              <style>
                .calendar table { width:100%; border-collapse:separate; border-spacing:6px; }
                .calendar td { padding:10px; text-align:center; border-radius:10px; border:1.5px solid #e5e7eb; cursor:pointer; font-weight:600; }
                .calendar td.available { background:#d1fae5; border:2px solid #16a34a; color:#14532d; box-shadow:inset 0 0 0 2px rgba(22,163,74,0.18); }
                .calendar td.partly { background:#ffe7cc; border-color:#f5b575; color:#b45309; }
                .calendar td.fully-booked { background:#dc2626; border-color:#b91c1c; color:#ffffff; }
                .calendar td.disabled { cursor:not-allowed; opacity:0.95; }
                .calendar td.today { outline:2px solid #345c40; }
                .calendar td.active { background:#ffffff; border:2px solid #16a34a; color:#0f172a; box-shadow:none; }
                .calendar td.active-start,
                .calendar td.active-end { background:#ffffff !important; color:#23412e; font-weight:800; border:2px solid #23412e; box-shadow:inset 0 0 0 2px rgba(35,65,46,0.15); }
                .calendar td.range-highlight {
                  background: #fde68a !important;
                  border-color: #f59e0b !important;
                  color: #78350f !important;
                  box-shadow: inset 0 0 0 2px rgba(245, 158, 11, 0.2) !important;
                }
                .calendar td.range-highlight.active-start,
                .calendar td.range-highlight.active-end {
                  background: #fde68a !important;
                  border-color: #23412e !important;
                  color: #23412e !important;
                }
              </style>
              <div class="calendar" style="width:100%">
                <div class="calendar-header">
                  <button type="button" id="prevMonth">&lt;</button>
                  <h3 id="monthAndYear"></h3>
                  <button type="button" id="nextMonth">&gt;</button>
                </div>
                <table>
                  <thead><tr><th>Su</th><th>Mo</th><th>Tu</th><th>We</th><th>Th</th><th>Fr</th><th>Sa</th></tr></thead>
                  <tbody id="calendar-body"></tbody>
                </table>
              </div>
              <div class="amenity-preview" id="amenityPreview" style="display:none;">
                <img src="" alt="" id="amenityPreviewImg" class="amenity-preview-img">
                <div class="amenity-preview-header">
                  <div class="amenity-preview-title" id="amenityPreviewTitle">Amenity</div>
                </div>
                <div class="amenity-preview-meta" id="amenityPreviewDays"></div>
                <div class="amenity-preview-meta" id="amenityPreviewHours"></div>
                <div class="amenity-preview-meta" id="amenityPreviewPrice"></div>
                <div class="amenity-preview-mode" id="amenityPreviewMode" style="display:none;"></div>
                <button type="button" id="amenityReturnBtn" class="btn-secondary amenity-return" style="display:none;">
                  <img src="images/change.png" alt="" class="amenity-change-icon"> Change Amenity
                </button>
              </div>
              <div class="reservation-left">
                <div class="res-item" id="singleDayRow">
                  <label class="single-day"><input type="checkbox" id="singleDayToggle"> Single-day reservation</label>
                </div>
                <div class="date-row" id="dateRow">
                  <div class="res-item date-item" id="startDateGroup">
                    <div class="res-label"><small>Start Date</small></div>
                    <div class="date-line"><p id="startDate">--</p><button type="button" class="clear-date" id="clearStartBtn" title="Clear start date">Clear</button></div>
                    <input type="hidden" name="startDate" id="startDateInput">
                    <div id="startDateError" class="time-error" style="display:none;"></div>
                  </div>
                    <div class="res-item date-item" id="endDateGroup">
                    <div class="res-label"><small>End Date</small></div>
                    <div class="date-line"><p id="endDate">--</p><button type="button" class="clear-date" id="clearEndBtn" title="Clear end date">Clear</button></div>
                    <input type="hidden" name="endDate" id="endDateInput">
                    <div id="dateError" class="time-error" style="display:none;"></div>
                    <input type="time" name="endTime" id="endTimeInput" min="08:00" max="23:00" style="display:none;">
                    <div id="timeError" class="time-error" style="display:none;"></div>
                  </div>
                  </div>
                    <div class="note">Reservations must be made at least 1 day in advance. Same-day bookings are not allowed.</div>
                  <div class="rsv-time-attendees" style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-start; width:100%;">
                    <div class="date-extra" style="flex:1 1 340px; min-width:280px; margin-top:0;">
                    <input type="time" name="startTime" id="startTimeInput" min="08:00" max="23:00" style="display:none;">
                    <div class="res-label" id="hoursLabel" style="margin-top:8px; display:none;"><small>Number of Hours</small></div>
                    <div class="counter" id="hoursCounter" style="display:none;">
                      <button type="button" onclick="changeHours(-1)">-</button>
                      <span id="hoursCount">1</span>
                      <button type="button" onclick="changeHours(1)">+</button>
                    </div>
                    <input type="hidden" name="hours" id="hoursInput">
                    <input type="hidden" id="hoursChosen" value="0">
                    <div class="res-label" id="hoursSectionLabel" style="margin-top:8px; display:none;"><small>How many hours</small></div>
                    <select id="hoursSelect" class="hours-select" style="display:none;" disabled></select>
                    <div id="durationContainer" style="display:none;"></div>
                    <div class="res-label" id="timeSectionLabel" style="margin-top:8px; display:none;"><small>Start Time</small></div>
                    <div id="timeSlotContainer"></div>
                    <div id="selectedTimeRange" class="selected-time-range" style="display:none;"></div>
                    <div id="selectedTimeNote" class="selected-time-note" style="display:none;">Note: This is the available time. Please leave by closing time.</div>
                    <div id="availabilityNotice" class="avail-notice" style="display:none;"></div>
                    <!-- Points Redemption Toggle -->
                    <?php if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident'): ?>
                    <div class="res-item booking-mode-row">
                      <div class="booking-mode-shell">
                        <div class="booking-mode-title">Book with cash or redeem points</div>
                        <div class="booking-mode-grid">
                          <button type="button" class="booking-mode-card is-active" id="bookingModeCash">
                            <span class="booking-mode-label">Cash</span>
                            <span class="booking-mode-value">Pay online downpayment</span>
                            <span class="booking-mode-meta">Use the regular VictorianPass booking flow.</span>
                          </button>
                          <button type="button" class="booking-mode-card" id="bookingModePoints">
                            <span class="booking-mode-label">Redeem Points</span>
                            <span class="booking-mode-value" id="bookingModePointsRequired">750 pts = 1 free hour</span>
                            <span class="booking-mode-meta">Free 1-hour booking when you have enough VHEcoPoint points.</span>
                          </button>
                        </div>
                        <input type="checkbox" id="use-points-toggle" style="display:none;">
                        <div class="booking-mode-guide" id="bookingModeGuide">
                          Choose an amenity to compare the cash amount and the point redemption requirement.
                        </div>
                        <div id="redemption-info" class="redemption-info">
                          <p class="redemption-note">
                            <i class="fa-solid fa-circle-check" aria-hidden="true"></i> <strong>1 free hour will be deducted from your selected duration.</strong>
                          </p>
                          <p class="redemption-line">Current balance: <span id="current-points-guide"><?php echo number_format($residentPoints); ?> pts</span></p>
                          <p class="redemption-line">Points required (1 free hour): <span id="required-points"></span></p>
                          <p class="redemption-line">Remaining balance: <span id="remaining-points"></span></p>
                          <p class="redemption-line">Savings: <span id="savings-guide">Select an amenity first</span></p>
                        </div>
                      </div>
                    </div>
                    <?php endif; ?>
                    <div class="res-item price-row">
                      <div class="price-box">
                        <div class="price-label">Total Price</div>
                        <div id="price" class="price-amount">₱0.00</div>
                        <div id="priceBreakdown" class="price-breakdown" style="display:none;"></div>
                      </div>
                    </div>
                  </div>
                    <div class="res-item right-col" style="flex:1 1 320px; min-width:280px; margin-top:0;">
                    <div class="res-item persons" style="flex:1 1 320px; min-width:280px; margin-top:0;">
                    <div id="personsMaxNote" class="label-help"></div>
                      <?php if (!$isResident): ?>
                      <div class="res-label"><small>Total Participants</small></div>
                      <div class="counter">
                        <button type="button" onclick="changePersons(-1)">-</button>
                        <input type="number" id="personCount" value="0" min="0" step="1" style="width:70px;text-align:center;border:1px solid #e5e7eb;border-radius:8px;padding:6px 10px;font-weight:600;">
                        <button type="button" onclick="changePersons(1)">+</button>
                      </div>
                      <?php endif; ?>
                      <input type="hidden" name="persons" id="personsInput" value="0">
                      
                      <?php if ($isResident): ?>
                      <div id="participantWrap" style="display:block;">
                        <div class="participant-selector" style="margin-top:14px; border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#fafafa;">
                          <div style="font-weight:700; margin-bottom:8px;">Who will attend?</div>
                          <div class="mode-options" style="display:flex;flex-direction:column;gap:8px;">
                            <button type="button" class="btn-secondary" data-mode="resident_only">Residents Only</button>
                            <div style="color:#555;font-size:0.85rem;">All selected residents receive a 33.33% discount.</div>
                            <button type="button" class="btn-secondary" data-mode="resident_guest">Residents + Guests</button>
                            <div style="color:#555;font-size:0.85rem;">Residents receive a 33.33% discount. Guests pay full price.</div>
                            <button type="button" class="btn-secondary" data-mode="guest_only">Guests Only</button>
                            <div style="color:#555;font-size:0.85rem;">Guests are charged the regular rate.</div>
                          </div>
                          <div class="resident-group" style="display:none; margin-top:12px; border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#fff;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                              <div style="width:8px;height:8px;border-radius:50%;background:#1f8a3a;"></div>
                              <div style="font-weight:600;">Residents (Discounted)</div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:8px;max-height:220px;overflow:auto;">
                              <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="resident-check" value="<?php echo isset($currentResident['id']) ? (int)$currentResident['id'] : 0; ?>" data-name="Me (Primary Resident)" checked>
                                <span>Me (Primary Resident)</span>
                              </label>
                              <?php foreach ($householdResidents as $hr): ?>
                              <?php $hrName = trim(($hr['first_name'] ?? '') . ' ' . ($hr['middle_name'] ?? '') . ' ' . ($hr['last_name'] ?? '')); if ($hrName==='') { $hrName='Resident'; } ?>
                              <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="resident-check" value="<?php echo (int)$hr['id']; ?>" data-name="<?php echo htmlspecialchars($hrName); ?>">
                                <span><?php echo htmlspecialchars($hrName); ?> (Resident)</span>
                              </label>
                              <?php endforeach; ?>
                            </div>
                          </div>
                          <div class="guest-group" style="display:none; margin-top:12px; border:1px solid #e5e7eb; border-radius:12px; padding:12px; background:#fff;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
                              <div style="width:8px;height:8px;border-radius:50%;background:#2a4fe5;"></div>
                              <div style="font-weight:600;">Approved Guests (Full Price)</div>
                            </div>
                            <div style="display:flex;flex-direction:column;gap:8px;max-height:220px;overflow:auto;">
                              <?php foreach ($residentGuests as $g): ?>
                              <?php $gName = trim(($g['visitor_first_name'] ?? '') . ' ' . ($g['visitor_middle_name'] ?? '') . ' ' . ($g['visitor_last_name'] ?? '')); if ($gName==='') { $gName='Guest'; } ?>
                              <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" class="guest-check" value="<?php echo (int)$g['id']; ?>" data-ref="<?php echo htmlspecialchars($g['ref_code']); ?>" data-name="<?php echo htmlspecialchars($gName); ?>">
                                <span><?php echo htmlspecialchars($gName); ?></span>
                              </label>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                        <div style="margin-top:10px; border-top:1px dashed #ddd; padding-top:10px;">
                          <div class="res-label"><small>Participants Breakdown</small></div>
                          <div style="display:flex; gap:16px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:180px;">
                              <div style="font-weight:600; color:#23412e; margin-bottom:6px;">Residents</div>
                              <div class="counter">
                                <button type="button" onclick="changeResidents(-1)">-</button>
                                <span id="residentsCountText"><?php echo (isset($_SESSION['user_type']) && $_SESSION['user_type']==='resident') ? '1' : '0'; ?></span>
                                <button type="button" onclick="changeResidents(1)">+</button>
                              </div>
                              <small class="label-help">33.33% discount per resident</small>
                            </div>
                            <div style="flex:1; min-width:180px;">
                              <div style="font-weight:600; color:#8a2a2a; margin-bottom:6px;">Approved Guests</div>
                              <div class="counter">
                                <button type="button" onclick="changeGuests(-1)">-</button>
                                <span id="guestsCountText">0</span>
                                <button type="button" onclick="changeGuests(1)">+</button>
                              </div>
                              <small class="label-help">Full price per guest</small>
                            </div>
                          </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    </div>
                    <div class="res-item price-row">
                      <div class="price-box">
                        <div class="price-label">Downpayment (50% Online)</div>
                        <div id="dpAmountText" class="price-amount">₱0.00</div>
                      </div>
                      <input type="hidden" name="downpayment" id="downpaymentInput" value="">
                      <small class="dp-info">This is a partial payment of 50% of the total price. The remaining balance can be paid onsite at the administration office.</small>
                      <small class="nonrefundable">Downpayment is non-refundable.</small>
                      <small class="booking-mode-hint" id="bookingModeHint"></small>
                    </div>
                    <div id="submitWrap" class="res-item submit-wrap">
                      <button id="submitBtn" class="btn-submit disabled" type="submit" disabled>Next</button>
                    </div>
                    </div>
                    </div>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
  </div>
  <div id="amenityImageModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; align-items:center; justify-content:center;">
    <div class="amia-box" style="position:relative; background:#fff; border-radius:12px; padding:12px; max-width:90vw; max-height:90vh;">
      <button type="button" id="amenityImageClose" class="modal-close" aria-label="Close">&times;</button>
      <img id="amenityImageModalImg" src="" alt="Amenity" style="display:block; max-width:85vw; max-height:80vh;">
    </div>
  </div>
</div>
</section>

<div id="verifyModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="modal-close" id="verifyCloseBtn" aria-label="Close">&times;</button>
    <h2>Confirm Details</h2>
    <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-cancel" id="verifyCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="verifyConfirmBtn">Confirm</button>
    </div>
  </div>
  </div>

<!-- Error Modal -->
<div id="errorModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="modal-close" id="errorModalCloseBtn" aria-label="Close">&times;</button>
    <h2 style="color:#dc2626;">Error</h2>
    <p id="errorModalMessage" style="margin-top:15px; text-align:left;"></p>
    <div style="text-align:center;margin-top:20px;">
      <button type="button" class="btn-confirm" id="errorModalOkBtn">Okay</button>
    </div>
  </div>
</div>

<div id="changeAmenityModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="modal-close" id="changeAmenityCloseBtn" aria-label="Close">&times;</button>
    <h2>Change amenity?</h2>
    <p style="margin:8px 0 16px;color:#4b5563;">Are you sure you want to change amenities? This will reset your current selection.</p>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-cancel" id="changeAmenityCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="changeAmenityConfirmBtn">Yes, change</button>
    </div>
  </div>
</div>
<div id="resetReservationModal" class="modal" style="display:none;">
  <div class="modal-content">
    <button type="button" class="modal-close" id="resetReservationCloseBtn" aria-label="Close">&times;</button>
    <h2>Reservation reset</h2>
    <p style="margin:8px 0 16px;color:#4b5563;">You need to make the reservation again since you clicked back on the downpayment page.</p>
    <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
      <button type="button" class="btn-confirm" id="resetReservationOkBtn">OK</button>
    </div>
  </div>
</div>

<!--
<div id="hintModal" class="modal" style="display:none;">
  <div class="modal-content">
    <h2>Next Step</h2>
    <p>Select date here and fill out the form.</p>
    <div style="text-align:center;margin-top:8px;">
      <button class="close-btn" onclick="closeHint()">OK</button>
    </div>
  </div>
</div>
-->

<script>
  const monthNames=["January","February","March","April","May","June","July","August","September","October","November","December"];
  let today=new Date(),currentMonth=today.getMonth(),currentYear=today.getFullYear();
  const monthAndYear=document.getElementById("monthAndYear"),calendarBody=document.getElementById("calendar-body");
  const todayStr=`${today.getFullYear()}-${String(today.getMonth()+1).padStart(2,'0')}-${String(today.getDate()).padStart(2,'0')}`;
  const minDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1);
  const minDateStr = `${minDate.getFullYear()}-${String(minDate.getMonth()+1).padStart(2,'0')}-${String(minDate.getDate()).padStart(2,'0')}`;
  const currentUserType="<?php echo isset($_SESSION['user_type']) ? htmlspecialchars($_SESSION['user_type'], ENT_QUOTES) : ''; ?>";
  const residentPoints = <?php echo isset($residentPoints) ? intval($residentPoints) : 0; ?>;
  let selectedStart=null,selectedEnd=null;
  let endDateRangeError=false;
  let bookedDates=new Set();
  let availabilityCache=new Map();
  let availabilityToken=0;
  let selectedAmenity=document.getElementById('amenityField').value||'';
  let usePoints = false;

  function vpShowModal(el){
    if(!el) return;
    el.classList.remove('closing');
    el.classList.add('open');
    el.style.display='flex';
  }
  function vpHideModal(el, done){
    if(!el) return;
    if(el.classList.contains('closing')) { if(done) done(); return; }
    if(el.classList.contains('open') || getComputedStyle(el).display!=='none'){
      el.classList.remove('open');
      el.classList.add('closing');
      setTimeout(function(){
        el.style.display='none';
        el.classList.remove('closing');
        if(done) done();
      }, 260);
    } else {
      el.style.display='none';
      if(done) done();
    }
  }

  function getPointsRequired(amenity) {
    switch(amenity) {
      case 'Basketball Court':
      case 'Tennis Court':
        return 300;
      case 'Clubhouse':
        return 600;
      case 'Multi-Purpose Building':
        return 750;
      default:
        return 0;
    }
  }

  function getAmenitySavingsValue(amenity) {
    return amenity ? getHourlyRate(amenity, isResidentSelfBooking()) : 0;
  }

  function updateBookingModeCards() {
    const toggle = document.getElementById('use-points-toggle');
    const cashBtn = document.getElementById('bookingModeCash');
    const pointsBtn = document.getElementById('bookingModePoints');
    const pointsRequiredLabel = document.getElementById('bookingModePointsRequired');
    const guide = document.getElementById('bookingModeGuide');
    const savingsGuide = document.getElementById('savings-guide');
    const currentPointsGuide = document.getElementById('current-points-guide');
    const amenity = document.getElementById('amenityField')?.value || selectedAmenity || '';
    const pointsRequired = getPointsRequired(amenity);
    const savingsValue = getAmenitySavingsValue(amenity);
    if (cashBtn) cashBtn.classList.toggle('is-active', !toggle || !toggle.checked);
    if (pointsBtn) pointsBtn.classList.toggle('is-active', !!(toggle && toggle.checked));
    if (pointsRequiredLabel) {
      pointsRequiredLabel.textContent = amenity ? (pointsRequired.toLocaleString() + ' pts = 1 free hour') : '750 pts = 1 free hour';
    }
    const bookingModeHint = document.getElementById('bookingModeHint');
    if (bookingModeHint) {
      if (!amenity) {
        bookingModeHint.textContent = 'Cash booking is active. You can switch to point redemption and get 1 free hour for 750 pts.';
      } else if (toggle && toggle.checked) {
        bookingModeHint.textContent = 'Point redemption is active. 1 free hour will be deducted from your selected duration.';
      } else {
        bookingModeHint.textContent = 'Cash booking is active. You can switch to point redemption and get 1 free hour for ' + pointsRequired.toLocaleString() + ' pts.';
      }
    }
    if (guide) {
      if (!amenity) {
        guide.textContent = 'Choose an amenity to compare the cash amount and the point redemption requirement.';
      } else if (toggle && toggle.checked) {
        guide.textContent = '1 free hour redeemed with points. Remaining hours are charged at regular rate.';
      } else {
        guide.textContent = 'Cash booking is active. You can switch to point redemption and get 1 free hour for ' + pointsRequired.toLocaleString() + ' pts.';
      }
    }
    if (savingsGuide) {
      savingsGuide.textContent = amenity ? ('Saves ₱' + savingsValue.toLocaleString() + ' (1 free hour)') : 'Select an amenity first';
    }
    if (currentPointsGuide) {
      currentPointsGuide.textContent = residentPoints.toLocaleString() + ' pts';
    }
  }

  // Function to show a popup error
  function showPointsErrorPopup(message, showPayNormally) {
    let popup = document.getElementById('points-error-popup');
    let overlay = document.getElementById('points-error-overlay');
    if (!overlay) {
      overlay = document.createElement('div');
      overlay.id = 'points-error-overlay';
      overlay.className = 'points-error-overlay';
      overlay.addEventListener('click', () => {
        const p = document.getElementById('points-error-popup');
        if (p) p.classList.remove('open');
        overlay.classList.remove('open');
      });
      document.body.appendChild(overlay);
    }
    if (!popup) {
      popup = document.createElement('div');
      popup.id = 'points-error-popup';
      popup.className = 'points-error-popup';
      document.body.appendChild(popup);
    }

    popup.innerHTML = `
      <div class="points-error-inner">
        <h3 class="points-error-title"><i class="fa-solid fa-triangle-exclamation" style="color:#dc2626;"></i> Insufficient VHEcoPoints</h3>
        <p class="points-error-message">${message}</p>
        <div class="points-error-actions" style="display:flex;gap:10px;margin-top:16px;justify-content:center;flex-wrap:wrap;">
          ${showPayNormally ? '<button class="points-error-pay-normal" style="padding:10px 20px;border-radius:8px;border:2px solid #16a34a;background:#d1fae5;color:#065f46;font-weight:700;cursor:pointer;">Pay Normally</button>' : ''}
          <button class="points-error-close" style="padding:10px 20px;border-radius:8px;border:2px solid #dc2626;background:#fee2e2;color:#991b1b;font-weight:700;cursor:pointer;">Cancel</button>
        </div>
      </div>
    `;

    const closeBtn = popup.querySelector('.points-error-close');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => {
        popup.classList.remove('open');
        overlay.classList.remove('open');
      });
    }

    const payNormalBtn = popup.querySelector('.points-error-pay-normal');
    if (payNormalBtn) {
      payNormalBtn.addEventListener('click', () => {
        popup.classList.remove('open');
        overlay.classList.remove('open');
        const toggle = document.getElementById('use-points-toggle');
        if (toggle) toggle.checked = false;
        usePoints = false;
        const redemptionInfo = document.getElementById('redemption-info');
        if (redemptionInfo) redemptionInfo.style.display = 'none';
        const currentPointsEl = document.getElementById('current-points-display');
        if (currentPointsEl) currentPointsEl.textContent = residentPoints.toLocaleString() + ' pts';
        updateDisplayedPrice();
        updateDownpaymentSuggestion();
        updateBookingModeCards();
      });
    }

    // Show popup and overlay
    overlay.classList.add('open');
    popup.classList.add('open');
  }

  function lockHoursControls(lock) {
    // Hours counter
    const hoursCounter = document.getElementById('hoursCounter');
    const minusBtn = hoursCounter ? hoursCounter.querySelector('button[onclick*="changeHours(-1)"]') : null;
    const plusBtn = hoursCounter ? hoursCounter.querySelector('button[onclick*="changeHours(1)"]') : null;
    if (minusBtn) minusBtn.disabled = lock;
    if (plusBtn) plusBtn.disabled = lock;

    // Hours select
    const hoursSelect = document.getElementById('hoursSelect');
    if (hoursSelect) hoursSelect.disabled = lock;

    // Duration container buttons
    const durationContainer = document.getElementById('durationContainer');
    if (durationContainer) {
      Array.from(durationContainer.children).forEach(btn => {
        if (btn.tagName === 'BUTTON' || btn.classList.contains('duration-btn')) {
          btn.disabled = lock;
          if (lock) {
            btn.style.opacity = '0.5';
            btn.style.cursor = 'not-allowed';
          } else {
            btn.style.opacity = '1';
            btn.style.cursor = 'pointer';
          }
        }
      });
    }
  }

  function updateRedemptionInfo() {
    const toggle = document.getElementById('use-points-toggle');
    const redemptionInfo = document.getElementById('redemption-info');
    const requiredPointsEl = document.getElementById('required-points');
    const remainingPointsEl = document.getElementById('remaining-points');
    const currentPointsEl = document.getElementById('current-points-display');

    if (!toggle) return;

    usePoints = toggle.checked;

    if (usePoints) {
      if (!selectedAmenity) {
        toggle.checked = false;
        usePoints = false;
        showPointsErrorPopup("Please select an amenity first before redeeming points.");
      } else {
        const pointsRequired = getPointsRequired(selectedAmenity);
        const remainingPoints = residentPoints - pointsRequired;

        if (remainingPoints < 0) {
        toggle.checked = false;
        usePoints = false;
        showPointsErrorPopup("You do not have enough VHEcoPoints to redeem the 1 free hour for this amenity. Please pay normally or earn more points by recycling at the VHEcoPoint Smart Waste Station.", true);
          if (redemptionInfo) redemptionInfo.style.display = 'none';
        } else {
          if (requiredPointsEl) requiredPointsEl.textContent = pointsRequired.toLocaleString() + ' pts';
          if (remainingPointsEl) remainingPointsEl.textContent = remainingPoints.toLocaleString() + ' pts';
          if (redemptionInfo) redemptionInfo.style.display = 'block';
          if (currentPointsEl) {
            currentPointsEl.textContent = remainingPoints.toLocaleString() + ' pts';
          }
        }
      }
    } else {
      if (redemptionInfo) redemptionInfo.style.display = 'none';
      if (currentPointsEl) {
        currentPointsEl.textContent = residentPoints.toLocaleString() + ' pts';
      }
    }

    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingModeCards();
  }

  document.addEventListener('DOMContentLoaded', function() {
    const toggle = document.getElementById('use-points-toggle');
    const cashBtn = document.getElementById('bookingModeCash');
    const pointsBtn = document.getElementById('bookingModePoints');
    if (toggle) {
      toggle.addEventListener('change', updateRedemptionInfo);
    }
    if (cashBtn && toggle) {
      cashBtn.addEventListener('click', function() {
        toggle.checked = false;
        updateRedemptionInfo();
      });
    }
    if (pointsBtn && toggle) {
      pointsBtn.addEventListener('click', function() {
        toggle.checked = true;
        updateRedemptionInfo();
      });
    }
    updateBookingModeCards();

    // If hours change while points are enabled, recalculate price
    const hoursInputs = [document.getElementById('hoursInput'), document.getElementById('hoursSelect')].filter(el => el);
    hoursInputs.forEach(input => {
      input.addEventListener('change', function() {
        const toggleEl = document.getElementById('use-points-toggle');
        if (toggleEl && toggleEl.checked) {
          updateDisplayedPrice();
          updateDownpaymentSuggestion();
          updateRedemptionInfo();
        }
      });
      input.addEventListener('input', function() {
        const toggleEl = document.getElementById('use-points-toggle');
        if (toggleEl && toggleEl.checked) {
          updateDisplayedPrice();
          updateDownpaymentSuggestion();
          updateRedemptionInfo();
        }
      });
    });
  });
  let hintShown=false;
  const approvedGuestsMax = <?php echo (isset($residentGuests) && is_array($residentGuests)) ? count($residentGuests) : 0; ?>;

  function formatDateToMMDDYYYY(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[1]}/${parts[2]}/${String(parts[0]).slice(-2)}`;
  }
  function formatTimeLabel(t){
    if(!t) return '';
    const parts=String(t).split(':');
    let h=parseInt(parts[0]||'0',10);
    const m=(parts[1]||'00').padStart(2,'0');
    const ampm=h>=12?'PM':'AM';
    if(h===0){ h=12; }
    else if(h>12){ h-=12; }
    return `${h}:${m} ${ampm}`;
  }
  function countWeekdaysInclusive(startStr,endStr){
    if(!startStr || !endStr) return 0;
    const s=new Date(startStr);
    const e=new Date(endStr);
    if(isNaN(s) || isNaN(e)) return 0;
    let count=0;
    const d=new Date(s.getFullYear(),s.getMonth(),s.getDate());
    const end=new Date(e.getFullYear(),e.getMonth(),e.getDate());
    while(d<=end){
      const dow=d.getDay();
      if(dow!==0 && dow!==6){ count++; }
      d.setDate(d.getDate()+1);
    }
    return count;
  }

  async function loadBookedDates(forceRefresh){
    if(!selectedAmenity){ bookedDates=new Set(); renderCalendar(currentMonth,currentYear,forceRefresh); computeAvailability(); return; }
    bookedDates=new Set();
    renderCalendar(currentMonth,currentYear,forceRefresh); computeAvailability();
  }

  function renderCalendar(month,year,forceRefresh){
    calendarBody.innerHTML="";
    let firstDay=(new Date(year,month)).getDay();
    let daysInMonth=32-new Date(year,month,32).getDate();
    monthAndYear.textContent=monthNames[month]+" "+year;
    let date=1;
    for(let i=0;i<6;i++){
      let row=document.createElement("tr");
      for(let j=0;j<7;j++){
        if(i===0&&j<firstDay){row.appendChild(document.createElement("td"));}
        else if(date>daysInMonth){break;}
        else{
          let cell=document.createElement("td");
          cell.textContent=date;
          let ds=`${year}-${String(month+1).padStart(2,'0')}-${String(date).padStart(2,'0')}`;
          cell.setAttribute('data-date', ds);
          if(ds < minDateStr) { cell.classList.add('disabled'); }
          cell.addEventListener('click',()=>handleDateClick(cell,ds));
          if(date===today.getDate()&&year===today.getFullYear()&&month===today.getMonth()) cell.classList.add('today');
          row.appendChild(cell);date++;
        }
      }
      calendarBody.appendChild(row);
    }
    evaluateCalendarAvailability(forceRefresh);
  }

  function toDateString(d){ return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }
  function buildDayIndex(allBooked,startDs,endDs){
    const byDate={};
    if(!Array.isArray(allBooked) || allBooked.length===0){ return byDate; }
    const startObj=new Date(startDs);
    const endObj=new Date(endDs);
    for(const t of allBooked){
      if(!t.start_date || !t.end_date) continue;
      if(t.end_date < startDs || t.start_date > endDs) continue;
      let d=new Date(t.start_date < startDs ? startDs : t.start_date);
      const e=new Date(t.end_date > endDs ? endDs : t.end_date);
      while(d<=e){
        const ds=toDateString(d);
        if(!byDate[ds]) byDate[ds]=[];
        byDate[ds].push(t);
        d.setDate(d.getDate()+1);
      }
    }
    return byDate;
  }
  async function evaluateCalendarAvailability(forceRefresh){
    try{
      const amen=document.getElementById('amenityField').value;
      if(!amen){ return; }
      const cells=Array.from(document.querySelectorAll('.calendar td')).filter(c=>c.hasAttribute('data-date'));
      if(cells.length === 0) return;
      const startDs = cells[0].getAttribute('data-date');
      const endDs = cells[cells.length-1].getAttribute('data-date');
      const key = `${amen}|${startDs}|${endDs}`;
      const token = ++availabilityToken;
      let cached = availabilityCache.get(key);
      if(!cached || forceRefresh){
        const res = await fetch(`reserve.php?action=booked_times&amenity=${encodeURIComponent(amen)}&start_date=${startDs}&end_date=${endDs}`);
        const data = await res.json();
        const byDate = buildDayIndex(data.times||[], startDs, endDs);
        cached = { data, byDate };
        availabilityCache.set(key, cached);
      }
      if(token !== availabilityToken) return;
      const data = cached.data || {};
      const hrsRange=getAmenityHours(amen);
      const minH=parseInt(hrsRange.min.split(':')[0],10);
      const maxH=parseInt(hrsRange.max.split(':')[0],10);
      const totalHours=Math.max(0,maxH-minH);
      const byDate = cached.byDate || {};
      for(const cell of cells){
        const ds=cell.getAttribute('data-date');
        if(!ds) continue;
        if(ds < minDateStr){
          cell.classList.add('disabled');
          cell.title = ds < todayStr ? 'Past date — cannot be booked.' : 'Reservations must be made at least 1 day in advance.';
          continue;
        }
        cell.classList.remove('disabled','partly','available','fully-booked');
        const dayBooked = byDate[ds] || [];
        const reservedHours = getReservedHoursForDay(dayBooked, minH, maxH, ds, amen);
        if(reservedHours>=totalHours){ cell.classList.add('disabled'); cell.classList.add('fully-booked'); cell.title='Fully Booked — no time slots available for this date.'; }
        else if(reservedHours>0){ cell.classList.add('partly'); cell.title='Partially Booked — some time slots are unavailable.'; }
        else { cell.classList.add('available'); cell.title=''; }
      }
    }catch(_){ }
  }

  async function handleDateClick(cell,dateString){
    if(cell.classList.contains('disabled')){
      if(cell.classList.contains('fully-booked')){
        showStartDateError('Fully Booked — no time slots available for this date.');
      } else if(dateString && dateString < todayStr){
        showStartDateError('Past date — cannot be booked.');
      } else if(dateString && dateString < minDateStr){
        showStartDateError('Reservations must be made at least 1 day in advance.');
      } else {
        showStartDateError('Unavailable date — cannot be booked.');
      }
      return;
    }
    const singleToggle = document.getElementById('singleDayToggle');
    let singleActive = singleToggle?.checked;
    const isSameAsStart = selectedStart && dateString === selectedStart;
    const isSameAsEnd = selectedEnd && dateString === selectedEnd;
    var forceStart = false;
    if(singleActive){
      if(isSameAsStart && isSameAsEnd){
        document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
        clearStartDate();

        return;
      }
      if(!isSameAsStart && !isSameAsEnd){
        if(singleToggle){
          singleToggle.checked = false;
          singleToggle.dispatchEvent(new Event('change', { bubbles: true }));
        }
        singleActive = false;
        selectedEnd = null;
        document.getElementById('endDate').textContent='--';
        document.getElementById('endDateInput').value='';
        showDateError('');
        forceStart = true;
      }
    } else {
      if(isSameAsStart && !selectedEnd){
        setEnd(dateString);
        updateSelectedDateRangeHighlight();

        computeAvailability();
        renderTimeSlotButtons();
        markDirty('startDateInput');
        showIncompleteWarnings(false);
        updateActionStates();
        updateSelectedTimeRange();
        updateBookingSummary();
        return;
      }
      if(isSameAsEnd){
        document.querySelectorAll('.calendar td').forEach(td=>td.classList.remove('active'));
        clearEndDate();

        return;
      }
    }
    document.querySelectorAll('.calendar td').forEach(td=>{ td.classList.remove('active'); td.classList.remove('active-start'); td.classList.remove('active-end'); });
    cell.classList.add('active');
    function setStart(ds, ignoreEnd){
      const eVal=document.getElementById('endDateInput').value||'';
      if(!ignoreEnd && eVal && ds > eVal){ showStartDateError('Start date cannot be later than end date.'); return false; }
      selectedStart=ds;
      document.getElementById('startDate').textContent=formatDateToMMDDYYYY(selectedStart);
      document.getElementById('startDateInput').value=selectedStart;
      showStartDateError('');
      updateHoursSelectEnabled();
      return true;
    }
    function setEnd(ds){
      const sVal=document.getElementById('startDateInput').value||'';
      if(sVal && ds < sVal){ endDateRangeError=false; showDateError('End date cannot be earlier than start date.'); return false; }
      if(sVal){ const sD=new Date(sVal); const eD=new Date(ds); const diff=Math.floor((eD - sD)/(1000*60*60*24)); if(diff>6){ endDateRangeError=true; showDateError('Cannot book more than 1 week.'); return false; } }
      endDateRangeError=false;
      selectedEnd=ds;
      document.getElementById('endDate').textContent=formatDateToMMDDYYYY(selectedEnd);
      document.getElementById('endDateInput').value=selectedEnd;
      showDateError('');
      return true;
    }
    if(singleActive){
      setStart(dateString) && setEnd(dateString);
    } else {
      if(forceStart){ selectedStart = null; }
      if(!selectedStart){
        setStart(dateString);
      } else if(!selectedEnd){
        setEnd(dateString);
      } else {
        selectedEnd=null;
        document.getElementById('endDate').textContent='--';
        document.getElementById('endDateInput').value='';
        showDateError('');
        setStart(dateString, true);
      }
    }
    updateSelectedDateRangeHighlight();
    await evaluateCalendarAvailability();
    updateSelectedDateRangeHighlight();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
  }

  function updateSelectedDateRangeHighlight(){
    const cells=Array.from(document.querySelectorAll('.calendar td[data-date]'));
    cells.forEach(function(td){
      const ds=td.getAttribute('data-date');
      td.classList.remove('active','active-start','active-end','in-range');
      if(selectedStart && ds===selectedStart){
        td.classList.add('active-start');
        td.classList.remove('available');
      } else if(selectedEnd && ds===selectedEnd){
        td.classList.add('active-end');
        td.classList.remove('available');
      } else if(selectedStart && selectedEnd && ds>selectedStart && ds<selectedEnd){
        td.classList.add('in-range');
        td.classList.remove('available');
      }
    });
  }

  function clearStartDate(){
    selectedStart=null;
    document.getElementById('startDate').textContent='--';
    document.getElementById('startDateInput').value='';
    const single = document.getElementById('singleDayToggle')?.checked;
    if(single){ selectedEnd=null; document.getElementById('endDate').textContent='--'; document.getElementById('endDateInput').value=''; endDateRangeError=false; }
    document.querySelectorAll('.calendar td').forEach(td=>{ td.classList.remove('active'); td.classList.remove('active-start'); td.classList.remove('active-end'); });
    evaluateCalendarAvailability();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
    updateHoursSelectEnabled();
  }
  function clearEndDate(){
    selectedEnd=null;
    document.getElementById('endDate').textContent='--';
    document.getElementById('endDateInput').value='';
    endDateRangeError=false;
    document.querySelectorAll('.calendar td').forEach(td=>{ td.classList.remove('active'); td.classList.remove('active-start'); td.classList.remove('active-end'); });
    updateSelectedDateRangeHighlight();
    evaluateCalendarAvailability();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('endDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
  }

  function updateHoursSelectEnabled(){
    const hs=document.getElementById('hoursSelect');
    if(!hs) return;
    const s=document.getElementById('startDateInput')?.value||'';
    hs.disabled = !s;
  }

  function initSingleDayToggle(){
    const cb=document.getElementById('singleDayToggle');
    if(!cb) return;
    cb.addEventListener('change', function(){
      const s=document.getElementById('startDateInput').value;
      if(this.checked){
        if(s){ selectedEnd=s; document.getElementById('endDateInput').value=s; document.getElementById('endDate').textContent=formatDateToMMDDYYYY(s); }
      }
      updateSelectedDateRangeHighlight();
      computeAvailability();
      renderTimeSlotButtons();
      updateActionStates();
      showIncompleteWarnings(false);
      updateSelectedTimeRange();
      updateBookingSummary();
    });
  }

  const amenityData={
    clubhouse:{
      title:'Clubhouse',
      value:'Clubhouse',
      img:'images/clubhouse.png',
      desc:'A flexible indoor venue for birthdays, meetings, and celebrations. Air‑conditioned function hall with tables, chairs, and sound‑ready space so you can focus on your event while we provide the venue.',
      days:'Available Monday – Sunday',
      priceLabel:'₱450 visitor | ₱300 resident per hour',
      capacity:200
    },
    multipurpose:{
      title:'Multi-Purpose Building',
      value:'Multi-Purpose Building',
      img:'images/multi purpose building.png',
      desc:'A versatile space for various community activities, events, and recreational uses.',
      days:'Available Monday – Sunday',
      priceLabel:'₱300 visitor | ₱200 resident per hour',
      capacity:50
    },
    basketball:{
      title:'Basketball Court',
      value:'Basketball Court',
      img:'images/basketballcourt.png',
      desc:'Full outdoor court for pick‑up games, team practice, and training sessions. Ideal for leagues or friendly matches, with clear markings and lighting for late‑afternoon play.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 visitor | ₱100 resident per hour',
      capacity:30
    },
    tennis:{
      title:'Tennis Court',
      value:'Tennis Court',
      img:'images/tenniscourt.png',
      desc:'Reserve a dedicated court time for casual rallies or competitive singles and doubles. Well‑maintained surface suitable for all skill levels, from beginners to regular players.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 visitor | ₱100 resident per hour',
      capacity:60
    }
  };

  function updateAmenityDescription(key){
    const info=amenityData[key]||amenityData.clubhouse;
    const titleEl=document.getElementById('amenityDescTitle');
    if(titleEl){ titleEl.textContent=info.title; }
    const descEl=document.getElementById('amenityDescText');
    if(descEl){
      if(info.desc){ descEl.textContent=info.desc; descEl.style.display='block'; }
      else { descEl.textContent=''; descEl.style.display='none'; }
    }
    document.querySelectorAll('.amenity-desc .desc-img').forEach(function(img){ img.style.display='none'; });
    const imgEl=document.querySelector('.amenity-desc .desc-img[data-key="'+key+'"]');
    if(imgEl){ imgEl.style.display='block'; }
    const hn=document.getElementById('hoursNotice');
    if(hn){
      const hrs=getAmenityHours(info.value);
      const minH=parseInt(hrs.min.split(':')[0],10);
      const maxH=parseInt(hrs.max.split(':')[0],10);
      hn.textContent=`Bookable hours: ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}`;
      hn.style.display='block';
    }
    const daysEl=document.getElementById('amenityDescDays');
    if(daysEl){
      if(info.days){ daysEl.textContent=info.days; daysEl.style.display='block'; }
      else { daysEl.textContent=''; daysEl.style.display='none'; }
    }
    const priceEl=document.getElementById('amenityDescPrice');
    if(priceEl){
      const label=getAmenityPriceLabel(info.value);
      if(label){ priceEl.textContent=label; priceEl.style.display='block'; }
      else { priceEl.textContent=''; priceEl.style.display='none'; }
    }
    const capEl=document.getElementById('amenityDescCapacity');
    if(capEl){
      const cap=Number.isFinite(info.capacity)?info.capacity:getAmenityMaxPersons(info.value);
      if(cap && cap!==Infinity){ capEl.textContent=`Capacity: ${cap} max`; capEl.style.display='block'; }
      else { capEl.textContent=''; capEl.style.display='none'; }
    }
    const pTitle=document.getElementById('amenityPreviewTitle');
    if(pTitle){ pTitle.textContent=info.title; }
    const pDays=document.getElementById('amenityPreviewDays');
    if(pDays){
      if(info.days){ pDays.textContent=info.days; pDays.style.display='block'; }
      else { pDays.textContent=''; pDays.style.display='none'; }
    }
    const pHours=document.getElementById('amenityPreviewHours');
    if(pHours){
      try{
        const hrs=getAmenityHours(info.value);
        if(hrs){
          const minH=parseInt(hrs.min.split(':')[0],10);
          const maxH=parseInt(hrs.max.split(':')[0],10);
          pHours.textContent=`Hours: ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}`;
          pHours.style.display='block';
        }else{
          pHours.textContent='';
          pHours.style.display='none';
        }
      }catch(_){
        pHours.textContent='';
        pHours.style.display='none';
      }
    }
    const pPrice=document.getElementById('amenityPreviewPrice');
    if(pPrice){
      const label=getAmenityPriceLabel(info.value);
      if(label){ pPrice.textContent=label; pPrice.style.display='block'; }
      else { pPrice.textContent=''; pPrice.style.display='none'; }
    }
    const pMode=document.getElementById('amenityPreviewMode');
    if(pMode){
      const bookingNote=getAmenityBookingNote(info.value);
      if(bookingNote){ pMode.textContent=bookingNote; pMode.style.display='block'; }
      else { pMode.textContent=''; pMode.style.display='none'; }
    }
    const pImg=document.getElementById('amenityPreviewImg');
    if(pImg){ pImg.src=info.img; pImg.alt=info.title; }
    const pWrap=document.getElementById('amenityPreview');
    if(pWrap){ pWrap.style.display='flex'; }
  }

  function showInlineAmenityDetails(key){
    const info=amenityData[key]||amenityData.clubhouse;
    document.querySelectorAll('.schedule-panel').forEach(function(p){
      p.style.display='none';
      p.innerHTML='';
    });
    document.querySelectorAll('.amenity-card').forEach(function(c){
      c.removeAttribute('data-details-visible');
    });
    const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
    if(!card) return;
    card.setAttribute('data-details-visible','true');
    const panel=card.querySelector('[data-schedule-panel]');
    if(!panel) return;
    panel.innerHTML='';
    const body=document.createElement('div');
    body.className='inline-amenity-details';
    let inner='';
    inner+=`<div class="inline-title">${info.title}</div>`;
    try{
      const hrs=getAmenityHours(info.value);
      if(hrs){
        const minH=parseInt(hrs.min.split(':')[0],10);
        const maxH=parseInt(hrs.max.split(':')[0],10);
        inner+=`<p class="inline-meta"><strong>Hours:</strong> ${formatTimeSlot(minH)} – ${formatTimeSlot(maxH)}</p>`;
      }
    }catch(_){}
    if(info.days){ inner+=`<p class="inline-meta"><strong>Availability:</strong> ${info.days}</p>`; }
    const rateLabel=getAmenityPriceLabel(info.value);
    if(rateLabel){ inner+=`<p class="inline-meta"><strong>Rate:</strong> ${rateLabel}</p>`; }
    const bookingNote=getAmenityBookingNote(info.value);
    if(bookingNote){ inner+=`<p class="inline-note">${bookingNote}</p>`; }
    if(Number.isFinite(info.capacity)){ inner+=`<p class="inline-meta"><strong>Capacity:</strong> ${info.capacity} guests</p>`; }
    body.innerHTML=inner;
    panel.appendChild(body);
    panel.style.display='block';
  }

  function openAmenityImageModal(key){
    try{
      const info=amenityData[key]||amenityData.clubhouse;
      const modal=document.getElementById('amenityImageModal');
      const img=document.getElementById('amenityImageModalImg');
      if(!modal||!img) return;
      img.src=info.img;
      img.alt=info.title;
      vpShowModal(modal);
    }catch(_){ }
  }
  (function initAmenityImageModal(){
    const modal=document.getElementById('amenityImageModal');
    const close=document.getElementById('amenityImageClose');
    if(close){ close.onclick=function(){ vpHideModal(modal); }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ vpHideModal(modal); } }); }
  })();

  function showErrorModal(message) {
    const modal = document.getElementById('errorModal');
    const messageEl = document.getElementById('errorModalMessage');
    if (modal && messageEl) {
      messageEl.textContent = message;
      vpShowModal(modal);
    }
  }

  (function initErrorModal(){
    const modal=document.getElementById('errorModal');
    const closeBtn=document.getElementById('errorModalCloseBtn');
    const okBtn=document.getElementById('errorModalOkBtn');
    if(closeBtn){ closeBtn.onclick=function(){ vpHideModal(modal); }; }
    if(okBtn){ okBtn.onclick=function(){ vpHideModal(modal); }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ vpHideModal(modal); } }); }
    <?php if (!empty($errorMsg)): ?>
      // Show error from PHP
      showErrorModal(<?php echo json_encode($errorMsg); ?>);
    <?php endif; ?>
  })();

  function selectAmenityByKey(key){
    const info=amenityData[key]||amenityData.clubhouse;
    selectedAmenity=info.value;
    document.getElementById('amenityField').value=info.value;
    availabilityCache.clear();
    
    document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
    const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
    if(card) card.classList.add('selected');
    const rc=document.querySelector('.reservation-card');
    // if(!hintShown){ hintShown=true; const hm=document.getElementById('hintModal'); if(hm){ hm.style.display='flex'; } }
    resetReservationForm();
    document.querySelectorAll('.schedule-panel').forEach(p=>p.style.display='none');
    loadBookedDates();
    configureFieldsForAmenity(selectedAmenity);
    renderHoursDropdownForAmenity();
    renderTimeSlotButtons();
    updateRedemptionInfo();
    try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){}
  }

  function resetReservationForm(){
    try{
      selectedStart=null; selectedEnd=null;
      document.querySelectorAll('.calendar td').forEach(function(td){ td.classList.remove('active','active-start','active-end','in-range'); });
      const ids=['startDateInput','endDateInput','startTimeInput','endTimeInput'];
      ids.forEach(function(id){ const el=document.getElementById(id); if(el){ el.value=''; } });
      const sd=document.getElementById('startDate'); if(sd){ sd.textContent='--'; }
      const ed=document.getElementById('endDate'); if(ed){ ed.textContent='--'; }
      const pc=document.getElementById('personCount'); if(pc){ if('value' in pc){ pc.value = currentUserType === 'resident' ? '1' : '0'; } else { pc.textContent = currentUserType === 'resident' ? '1' : '0'; } }
      const pi=document.getElementById('personsInput'); if(pi){ pi.value = currentUserType === 'resident' ? '1' : '0'; }
      const rc=document.getElementById('residentsCountInput'); if(rc){ rc.value = currentUserType === 'resident' ? '1' : '0'; }
      const gc=document.getElementById('guestsCountInput'); if(gc){ gc.value = currentUserType === 'resident' ? '0' : '0'; }
      const rText=document.getElementById('residentsCountText'); if(rText){ rText.textContent = currentUserType === 'resident' ? '1' : '0'; }
      const gText=document.getElementById('guestsCountText'); if(gText){ gText.textContent = currentUserType === 'resident' ? '0' : '0'; }
      const pWrap=document.getElementById('participantWrap'); if(pWrap){ pWrap.style.display='none'; }
      document.querySelectorAll('.resident-check').forEach(function(cb, idx){ cb.checked = idx === 0; });
      document.querySelectorAll('.guest-check').forEach(function(cb){ cb.checked = false; });
      const hi=document.getElementById('hoursInput'); if(hi){ hi.value=''; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.value=''; }
      const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='0'; }
      const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=''; tr.style.display='none'; }
      const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='none'; }
      const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='none'; }
      const tCont=document.getElementById('timeSlotContainer'); if(tCont){ tCont.innerHTML=''; tCont.style.display='none'; }
      const avail=document.getElementById('availabilityNotice'); if(avail){ avail.style.display='none'; avail.textContent=''; }
      const toggle = document.getElementById('use-points-toggle'); if(toggle){ toggle.checked = false; }
      usePoints = false;
      showStartDateError(''); showDateError(''); setFieldWarning('startTimeInput',''); setFieldWarning('endTimeInput',''); setFieldWarning('personsInput',''); setFieldWarning('hoursInput','');
      updateDisplayedPrice(); updateDownpaymentSuggestion();
      updateActionStates();
      updateParticipantVisibility();
    }catch(_){ }
  }

  document.querySelectorAll('.amenity-card img').forEach(function(img){
    img.addEventListener('click',function(e){
      e.stopPropagation();
      const card=img.closest('.amenity-card');
      if(card){
        const key=card.getAttribute('data-key');
        openAmenityImageModal(key);
      }
    });
  });

  const amenitiesList=document.getElementById('amenitiesList');

  function resetAmenitySelection(){
    document.querySelectorAll('.amenity-card').forEach(function(c){
      c.style.display='';
      c.classList.remove('selected');
      c.removeAttribute('data-details-visible');
    });
    document.querySelectorAll('.schedule-panel').forEach(function(p){
      p.style.display='none';
      p.innerHTML='';
    });
    document.querySelectorAll('button[data-action="view-desc"]').forEach(function(btn){
      btn.style.display='';
    });
    document.querySelectorAll('button[data-action="book-now"]').forEach(function(btn){
      btn.classList.remove('visible');
    });
    const rc=document.getElementById('reservationCard');
    if(rc){ rc.style.display='none'; }
    const descBox=document.getElementById('amenityDescBox');
    if(descBox){ descBox.style.display='flex'; }
    const amenitiesHeader=document.getElementById('amenitiesHeader');
    if(amenitiesHeader){ amenitiesHeader.style.display=''; }
    const t=document.getElementById('reservationTitle');
    if(t){ t.textContent='Reserve an Amenity'; }
    const h=document.getElementById('reservationHint');
    if(h){ h.textContent='Select an amenity to continue'; }
    const prev=document.getElementById('amenityPreview');
    if(prev){ prev.style.display='none'; }
    const btn=document.getElementById('amenityReturnBtn');
    if(btn){ btn.style.display='none'; }
  }

  function clearBookingFormState(){
    try{ sessionStorage.removeItem('reserve_form'); }catch(_){}
    selectedAmenity='';
    const amenField=document.getElementById('amenityField'); if(amenField){ amenField.value=''; }
    const bookingForField=document.getElementById('bookingForField'); if(bookingForField){ bookingForField.value=''; }
    const guestIdField=document.getElementById('guestIdField'); if(guestIdField){ guestIdField.value=''; }
    const guestRefField=document.getElementById('guestRefField'); if(guestRefField){ guestRefField.value=''; }
    resetReservationForm();
    resetAmenitySelection();
    document.querySelectorAll('input[name="guest_choice"], input[name="initial_guest_choice"]').forEach(function(r){ r.checked=false; });
    document.querySelectorAll('.guest-option').forEach(function(go){ go.style.borderColor='#e5e7eb'; });
    const confirmGuestBtn=document.getElementById('confirmGuestBtn'); if(confirmGuestBtn){ confirmGuestBtn.disabled=true; }
    updateBookingSummary();
  }

  const amenityReturnBtn=document.getElementById('amenityReturnBtn');
  if(amenityReturnBtn){
    amenityReturnBtn.addEventListener('click',function(){
      const modal=document.getElementById('changeAmenityModal');
      if(modal){ modal.style.display='flex'; }
    });
  }

  async function setPersonsCount(desired){
    const pcEl=document.getElementById('personCount');
    const amen=document.getElementById('amenityField').value;
    const desiredCount=Math.max(0, parseInt(desired||'0',10) || 0);
    let max=getAmenityMaxPersons(amen);
    const minAllowed=1;
    const count=Math.min(max,Math.max(minAllowed,desiredCount));
    if(pcEl){ if('value' in pcEl){ pcEl.value=String(count); } else { pcEl.textContent=String(count); } }
    document.getElementById('personsInput').value=count;
    if(currentUserType !== 'resident'){
      const rInput=document.getElementById('residentsCountInput'); if(rInput){ rInput.value='0'; }
      const rText=document.getElementById('residentsCountText'); if(rText){ rText.textContent='0'; }
      const gInput=document.getElementById('guestsCountInput'); if(gInput){ gInput.value=String(count); }
      const gText=document.getElementById('guestsCountText'); if(gText){ gText.textContent=String(count); }
    }
    const note=document.getElementById('personsMaxNote');
    if(note){ note.textContent = max?(`Maximum: ${max} persons`):''; }
    if(count>=max){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); } else { setFieldWarning('personsInput',''); }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
  }
  async function changePersons(val){
    const pcEl=document.getElementById('personCount');
    let count=parseInt(((pcEl && (pcEl.value||pcEl.textContent))||'0'),10);
    if(!Number.isFinite(count)){ count = 0; }
    await setPersonsCount(count + val);
  }
  async function changeResidents(delta){
    const wrap=document.getElementById('participantWrap');
    const mode=wrap ? (wrap.getAttribute('data-mode')||'') : '';
    if(mode==='guest_only'){
      setFieldWarning('personsInput','Residents cannot be added in Guests Only mode.');
      return;
    }
    const rEl=document.getElementById('residentsCountText');
    const rInput=document.getElementById('residentsCountInput');
    const gInput=document.getElementById('guestsCountInput');
    const pInput=document.getElementById('personsInput');
    const pText=document.getElementById('personCount');
    if(!rEl || !rInput || !gInput || !pInput) return;
    let r=parseInt(rEl.textContent||'0',10);
    const g=parseInt(gInput.value||'0',10);
    const nextR=Math.max(0, r+delta);
    const amen=document.getElementById('amenityField').value;
    let max=getAmenityMaxPersons(amen);
    const total=nextR+g;
    if(total < 1){
      setFieldWarning('personsInput','At least 1 participant is required.');
      return;
    }
    if(max!==Infinity && total>max){
      setFieldWarning('personsInput',`Maximum is ${max} persons.`);
      return;
    }
    setFieldWarning('personsInput','');
    r=nextR;
    rEl.textContent=String(r);
    rInput.value=String(r);
    pInput.value=String(total);
    if(pText) pText.textContent=String(total);
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(typeof persistForm === 'function') persistForm();
  }
  async function changeGuests(delta){
    const wrap=document.getElementById('participantWrap');
    const mode=wrap ? (wrap.getAttribute('data-mode')||'') : '';
    if(mode==='resident_only'){
      setFieldWarning('personsInput','Guests cannot be added in Residents Only mode.');
      return;
    }
    const gEl=document.getElementById('guestsCountText');
    const gInput=document.getElementById('guestsCountInput');
    const rInput=document.getElementById('residentsCountInput');
    const pInput=document.getElementById('personsInput');
    const pText=document.getElementById('personCount');
    if(!gEl || !gInput || !rInput || !pInput) return;
    let g=parseInt(gEl.textContent||'0',10);
    const r=parseInt(rInput.value||'0',10);
    const nextG=Math.max(0, g+delta);
    const amen=document.getElementById('amenityField').value;
    let max=getAmenityMaxPersons(amen);
    const total=r+nextG;
    if(approvedGuestsMax>=0 && nextG>approvedGuestsMax){
      setFieldWarning('personsInput',`You can add up to ${approvedGuestsMax} approved guests.`);
      return;
    }
    if(total < 1){
      setFieldWarning('personsInput','At least 1 participant is required.');
      return;
    }
    if(max!==Infinity && total>max){
      setFieldWarning('personsInput',`Maximum is ${max} persons.`);
      return;
    }
    setFieldWarning('personsInput','');
    g=nextG;
    gEl.textContent=String(g);
    gInput.value=String(g);
    pInput.value=String(total);
    if(pText) pText.textContent=String(total);
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(typeof persistForm === 'function') persistForm();
  }
  function updateParticipantVisibility(){
    const wrap=document.getElementById('participantWrap');
    if(!wrap) return;
    wrap.style.display='block';
  }
  function requireDateBeforeHours(){
    const s=document.getElementById('startDateInput')?.value||'';
    const e=document.getElementById('endDateInput')?.value||'';
    if(!s || !e){
      setFieldWarning('hoursInput','Please select a start date and end date before choosing hours.');
      return false;
    }
    setFieldWarning('hoursInput','');
    return true;
  }
  function changeHours(val){
    if(!requireDateBeforeHours()) return;
    const hoursSpan=document.getElementById('hoursCount');
    if(!hoursSpan) return;
    let hrs=parseInt(hoursSpan.textContent||'1');
    hrs=Math.max(1,hrs+val);
    hoursSpan.textContent=hrs;
    const hid=document.getElementById('hoursInput'); if(hid){ hid.value=hrs; }
    const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='1'; }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
    updateBookingSummary();
  }

  function selectDuration(hours){
    if(!requireDateBeforeHours()) return;
    const hoursInput=document.getElementById('hoursInput');
    const hoursCount=document.getElementById('hoursCount');
    if(hoursInput){ hoursInput.value=String(Math.max(1,parseInt(hours,10)||1)); }
    if(hoursCount){ hoursCount.textContent=String(Math.max(1,parseInt(hours,10)||1)); }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    renderTimeSlotButtons();
    const st=document.getElementById('startTimeInput').value;
    if(st){ computeEndTimeFromHours(); const sh=parseInt(st.split(':')[0],10); const eh=sh+parseInt(hoursInput.value||'0',10); const tr=document.getElementById('selectedTimeRange'); if(tr && hoursInput.value){ tr.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeSlot(sh)+' - '+formatTimeSlot(eh); tr.style.display='block'; } }
    const dc=document.getElementById('durationContainer'); if(dc){ Array.from(dc.children).forEach(b=>b.classList.remove('selected')); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(hoursInput.value)); if(sel){ sel.classList.add('selected'); } }
    updateActionStates();
    const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='1'; }
    updateBookingSummary();
  }

  async function fetchBookedTimesFor(date){ if(!document.getElementById('amenityField').value) return {times:[], persons_total:0, capacity:0}; try{ const res=await fetch(`reserve.php?action=booked_times&amenity=${encodeURIComponent(selectedAmenity)}&date=${encodeURIComponent(date)}`); const data=await res.json(); return data||{times:[], persons_total:0, capacity:0}; }catch(_){ return {times:[], persons_total:0, capacity:0}; } }

  async function fetchLivePointsBalance() {
    try {
      const res = await fetch('reserve.php?action=check_points');
      const data = await res.json();
      if (data.ok && typeof data.balance === 'number') {
        return data.balance;
      }
    } catch (_) {}
    return null;
  }

  function isHourBasedAmenity(amen){ return amen==='Basketball Court' || amen==='Tennis Court' || amen==='Clubhouse' || amen==='Multi-Purpose Building'; }
  function isPersonBasedAmenity(){ return false; }
  function isResidentSelfBooking(){
    const field=document.getElementById('bookingForField');
    return field && field.value === 'resident';
  }
  function getHourlyRate(amen, residentBooking){
    const useResidentRate = typeof residentBooking === 'boolean' ? residentBooking : isResidentSelfBooking();
    if(amen==='Basketball Court' || amen==='Tennis Court'){ return useResidentRate ? 100 : 150; }
    if(amen==='Multi-Purpose Building'){ return useResidentRate ? 200 : 300; }
    if(amen==='Clubhouse'){ return useResidentRate ? 300 : 450; }
    return 0;
  }
  function computeDynamicPrice(amen, residentsCount, guestsCount, hours){
    const h = parseInt(hours||'0',10);
    const hoursCount = Number.isFinite(h) && h > 0 ? h : 0;
    if(!isHourBasedAmenity(amen) || hoursCount <= 0){
      return 0;
    }
    return hoursCount * getHourlyRate(amen, isResidentSelfBooking());
  }
  function formatPesoAmount(val){
    const rounded=Math.round(val*100)/100;
    return Number.isInteger(rounded) ? rounded.toFixed(0) : rounded.toFixed(2);
  }
  function getAmenityPriceLabel(amen){
    if(!amen) return '';
    if(amen==='Clubhouse'){
      const rate=isResidentSelfBooking()?300:450;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    if(amen==='Multi-Purpose Building'){
      const rate=isResidentSelfBooking()?200:300;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    if(amen==='Basketball Court' || amen==='Tennis Court'){
      const rate=isResidentSelfBooking()?100:150;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    return '';
  }
  function getAmenityBookingNote(amen){
    if(!amen) return '';
    if(currentUserType === 'resident'){
      const requiredPoints=getPointsRequired(amen);
      if(requiredPoints > 0){
        return `Cash booking or redeem ${requiredPoints.toLocaleString()} pts for 1 free hour.`;
      }
    }
    return 'Cash booking is available for this amenity.';
  }
  function refreshPricingForBookingFor(){
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    const amen=document.getElementById('amenityField')?.value||'';
    const key = amen==='Clubhouse' ? 'clubhouse' : amen==='Multi-Purpose Building' ? 'multipurpose' : amen==='Basketball Court' ? 'basketball' : amen==='Tennis Court' ? 'tennis' : '';
    if(key){
      updateAmenityDescription(key);
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
      if(card && card.getAttribute('data-details-visible')==='true'){
        showInlineAmenityDetails(key);
      }
    }
  }
  function updateDisplayedPrice(){
    const usePoints = document.getElementById('use-points-toggle')?.checked || false;
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const fullBase = computeDynamicPrice(amen, residents, guests, hours);
    const paidHours = usePoints ? Math.max(0, hours - 1) : hours;
    const base = usePoints ? (paidHours * getHourlyRate(amen, isResidentSelfBooking())) : fullBase;
    const dpPercent=0.5;
    const downpayment = (usePoints && paidHours <= 0) ? 0 : (base*dpPercent);
    const priceEl=document.getElementById('price'); if(priceEl){ priceEl.textContent = '₱' + base.toFixed(2); }
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    const bd=document.getElementById('priceBreakdown');
    if(bd){
      if(amen){
        const requiredPoints = getPointsRequired(amen);
        const hourlyRate = getHourlyRate(amen, isResidentSelfBooking());
        bd.style.display='block';
        if(hours > 0){
          let rows = '';
          rows += '<div class="bd-row"><span>Original Duration: ' + hours + ' hour' + (hours !== 1 ? 's' : '') + '</span><span>₱' + fullBase.toFixed(2) + '</span></div>';
          if(usePoints){
            const discountVal = hourlyRate;
            rows += '<div class="bd-row bd-reward"><span>VHEcoPoint Reward: -1 Free Hour (' + requiredPoints.toLocaleString() + ' pts)</span><span>-₱' + discountVal.toFixed(2) + '</span></div>';
          }else{
            rows += '<div class="bd-row"><span>VHEcoPoint Reward: 0</span><span>₱0.00</span></div>';
          }
          rows += '<div class="bd-row"><span>Paid Duration: ' + paidHours + ' hour' + (paidHours !== 1 ? 's' : '') + '</span><span>₱' + base.toFixed(2) + '</span></div>';
          rows += '<div class="bd-row bd-total"><span>Final Amount</span><span>₱' + base.toFixed(2) + '</span></div>';
          bd.innerHTML = rows;
        }else{
          bd.innerHTML =
            '<div class="bd-row"><span>Original Duration: 0 hours</span><span>₱0.00</span></div>' +
            '<div class="bd-row"><span>VHEcoPoint Reward: 0</span><span>₱0.00</span></div>' +
            '<div class="bd-row"><span>Paid Duration: 0 hours</span><span>₱0.00</span></div>' +
            '<div class="bd-row bd-total"><span>Final Amount</span><span>₱0.00</span></div>';
        }
      }else{
        bd.style.display='none';
        bd.innerHTML='';
      }
    }
    updateBookingModeCards();
    updateBookingSummary();
  }
  function updateDownpaymentSuggestion(){
    const usePoints = document.getElementById('use-points-toggle')?.checked || false;
    const dp=document.getElementById('downpaymentInput'); if(!dp) return;
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const paidHours = usePoints ? Math.max(0, hours - 1) : hours;
    const base = usePoints ? (paidHours * getHourlyRate(amen, isResidentSelfBooking())) : computeDynamicPrice(amen, residents, guests, hours);
    const dpPercent=0.5;
    const downpayment = (usePoints && paidHours <= 0) ? 0 : (base*dpPercent);
    dp.value = downpayment.toFixed(2);
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    updateBookingSummary();
  }

  function configureFieldsForAmenity(amen){
    if(!amen){
      try{
        document.getElementById('reservationCard').style.display='none';
        document.getElementById('reservationTitle').textContent='Reserve an Amenity';
        document.getElementById('reservationHint').textContent='Select an amenity to continue';
        const prev=document.getElementById('amenityPreview'); if(prev){ prev.style.display='none'; }
        updateBookingModeCards();
      }catch(_){}
      return;
    }
    const personsWrap=document.getElementById('personsInput')?.closest('.res-item');
    const hoursLabel=document.getElementById('hoursLabel');
    const hoursInput=document.getElementById('hoursInput');
    const hoursCounter=document.getElementById('hoursCounter');
    const endTimeInput=document.getElementById('endTimeInput');
    const startTimeInput=document.getElementById('startTimeInput');
    const hrs=getAmenityHours(amen);
    if(startTimeInput){ startTimeInput.min=hrs.min; startTimeInput.max=hrs.max; }
    if(endTimeInput){ endTimeInput.min=hrs.min; endTimeInput.max=hrs.max; }
    const hn=document.getElementById('hoursNotice'); if(hn){ hn.style.display='none'; hn.textContent=''; }
    const priceEl=document.getElementById('price');
    if(isHourBasedAmenity(amen)){
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='inline-block'; }
      const hsl=document.getElementById('hoursSectionLabel'); if(hsl){ hsl.style.display='block'; }
      const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='block'; }
      if(hoursInput){ if(!hoursInput.value) hoursInput.value=''; }
      if(endTimeInput){ endTimeInput.readOnly=true; }
      if(startTimeInput && hoursInput){ computeEndTimeFromHours(); }
      if(priceEl){ priceEl.style.display='block'; }
      const note=document.getElementById('personsMaxNote'); if(note){ const max=getAmenityMaxPersons(amen); note.textContent = max?(`Maximum: ${max} persons`):''; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      renderHoursDropdownForAmenity();
      renderTimeSlotButtons();
    } else {
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='none'; }
      if(endTimeInput){ endTimeInput.readOnly=false; }
      if(priceEl){ priceEl.style.display='block'; }
      const note=document.getElementById('personsMaxNote'); if(note){ const max=getAmenityMaxPersons(amen); note.textContent = max?(`Maximum: ${max} persons`):''; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      document.getElementById('hoursSectionLabel').style.display='none';
      document.getElementById('timeSectionLabel').style.display='block';
      renderTimeSlotButtons();
    }
    updateBookingModeCards();
    updateHoursSelectEnabled();
  }

  function getAmenityMaxPersons(amen){
    if(amen==='Clubhouse') return 200;
    if(amen==='Multi-Purpose Building') return 50;
    if(amen==='Tennis Court') return 60;
    if(amen==='Basketball Court') return 30;
    return Infinity;
  }

  function clampToRange(timeStr){
    if(!timeStr) return '';
    const amen=document.getElementById('amenityField').value;
    const hrs=getAmenityHours(amen);
    const [h,m]=(timeStr||'').split(':');
    let hh=parseInt(h||'0',10); let mm=parseInt(m||'0',10);
    const [minH]=hrs.min.split(':'), [maxH]=hrs.max.split(':');
    const minHour=parseInt(minH,10), maxHour=parseInt(maxH,10);
    if(hh<minHour){ hh=minHour; mm=0; }
    if(hh>maxHour){ hh=maxHour; mm=0; }
    return `${String(hh).padStart(2,'0')}:${String(mm).padStart(2,'0')}`;
  }

  function getAmenityHours(amen){
    if(amen==='Clubhouse' || amen==='Multi-Purpose Building') return {min:'09:00', max:'21:00'};
    return {min:'09:00', max:'18:00'};
  }

  function computeEndTimeFromHours(){
    const amen=document.getElementById('amenityField').value;
    const st=document.getElementById('startTimeInput').value;
    const hrs=parseInt(document.getElementById('hoursInput').value||'0',10);
    if(!st) return;
    if(!isHourBasedAmenity(amen)){
      const units=Math.max(1,hrs||1);
      const [sh,sm]=(st||'').split(':');
      let endH=parseInt(sh||'0',10)+units; let endM=parseInt(sm||'0',10);
      const allowed=getAmenityHours(amen);
      const maxHour=parseInt(allowed.max.split(':')[0],10);
      if(endH>maxHour){ endH=maxHour; endM=0; }
      const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
      document.getElementById('endTimeInput').value=et;
      checkTimeAvailability(); updateActionStates(); return;
    }
    if(!hrs||hrs<1) return;
    const [sh,sm]=(clampToRange(st)||'').split(':');
    let h=parseInt(sh||'0',10), m=parseInt(sm||'0',10);
    let endH=h+hrs; let endM=m;
    const allowed=getAmenityHours(amen);
    const maxHour=parseInt(allowed.max.split(':')[0],10);
    if(endH>maxHour){ endH=maxHour; endM=0; }
    const et=`${String(endH).padStart(2,'0')}:${String(endM).padStart(2,'0')}`;
    document.getElementById('startTimeInput').value = clampToRange(st);
    document.getElementById('endTimeInput').value = et;
    checkTimeAvailability();
    updateActionStates();
    updateDisplayedPrice();
    updateSelectedTimeRange();
    updateBookingSummary();
  }

  function updateSelectedTimeRange(){
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const el=document.getElementById('selectedTimeRange');
    if(!el) return;
    const tn=document.getElementById('selectedTimeNote');
    if(!st || !et){ el.style.display='none'; el.textContent=''; if(tn){ tn.style.display='none'; } return; }
    const sh=parseInt(st.split(':')[0],10); const sm=parseInt(st.split(':')[1]||'0',10);
    const eh=parseInt(et.split(':')[0],10); const em=parseInt(et.split(':')[1]||'0',10);
    el.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeHM(sh,sm)+' - '+formatTimeHM(eh,em);
    el.style.display='block';
    if(tn){ tn.style.display = 'none'; }
  }

  function updateBookingSummary(){
    const amenity=document.getElementById('amenityField')?.value||'';
    const start=document.getElementById('startDateInput')?.value||'';
    const end=document.getElementById('endDateInput')?.value||'';
    const hours=document.getElementById('hoursInput')?.value||'';
    const startTime=document.getElementById('startTimeInput')?.value||'';
    const endTime=document.getElementById('endTimeInput')?.value||'';
    const persons=document.getElementById('personsInput')?.value||'';
    const priceText=document.getElementById('price')?.textContent||'';
    const dpInput=document.getElementById('downpaymentInput');
    const dpRaw=dpInput && dpInput.value ? dpInput.value : '';
    const summaryWrapper=document.getElementById('reservationSummary');
    if(summaryWrapper){ summaryWrapper.style.display = amenity ? 'block' : 'none'; }
    const amEl=document.getElementById('summaryAmenity');
    if(amEl){ amEl.textContent = amenity || '--'; }
    const sdEl=document.getElementById('summaryStartDate');
    if(sdEl){ sdEl.textContent = formatDateToMMDDYYYY(start) || '--'; }
    const edEl=document.getElementById('summaryEndDate');
    if(edEl){ edEl.textContent = formatDateToMMDDYYYY(end) || '--'; }
    const hrsEl=document.getElementById('summaryHours');
    if(hrsEl){ hrsEl.textContent = hours ? `${hours} hr${parseInt(hours,10)===1 ? '' : 's'}` : '--'; }
    const stEl=document.getElementById('summaryStartTime');
    if(stEl){ stEl.textContent = startTime ? `${formatTimeLabel(startTime)}${endTime ? ' – ' + formatTimeLabel(endTime) : ''}` : '--'; }
    const modeEl=document.getElementById('summaryBookingMode');
    const usePointsActive=document.getElementById('use-points-toggle')?.checked || false;
    if(modeEl){ modeEl.textContent = usePointsActive ? 'Redeem points' : 'Cash'; }
    const pEl=document.getElementById('summaryPersons');
    if(pEl){ pEl.textContent = persons || '--'; }
    const priceEl=document.getElementById('summaryPrice');
    if(priceEl){ priceEl.textContent = priceText || '₱0.00'; }
    const dpEl=document.getElementById('summaryDownpayment');
    if(dpEl){
      const n=parseFloat(dpRaw||'0');
      const val=isNaN(n)?0:n;
      dpEl.textContent='₱'+val.toFixed(2);
    }
    const pointsEl=document.getElementById('summaryPointsUsed');
    if(pointsEl){
      if(usePointsActive){
        const pointsNeeded = getPointsRequired(amenity);
        pointsEl.textContent = pointsNeeded > 0 ? pointsNeeded.toLocaleString() + ' pts' : '—';
        pointsEl.parentElement.style.display = 'flex';
      } else {
        pointsEl.textContent = '—';
        if(pointsEl.parentElement){ pointsEl.parentElement.style.display = 'none'; }
      }
    }
  }

  function getReservedHoursForDay(booked, minH, maxH, ds, amen){
    let reservedHours=0; const marked={};
    (booked||[]).forEach(t=>{
      if(t.start_date && t.end_date && (t.end_date < ds || t.start_date > ds)) return;
      let bS=minH, bE=maxH;
      if (t.has_time && t.start && t.end) {
        bS=parseInt(String(t.start).split(':')[0],10);
        bE=parseInt(String(t.end).split(':')[0],10);
      }
      if (t.start_date && t.end_date && t.start_date !== t.end_date) {
        if (ds === t.start_date) {
          bE = 24;
        } else if (ds === t.end_date) {
          bS = 0;
        } else {
          bS = 0;
          bE = 24;
        }
      }
      for(let h=bS; h<bE; h++){
        if(h>=minH && h<maxH){ if(!marked[h]){ marked[h]=true; reservedHours++; } }
      }
    });
    return reservedHours;
  }

  async function computeAvailability(){
    const amenSel=document.getElementById('amenityField').value;
    if(!amenSel){ const card=document.querySelector('.amenity-card.selected'); if(card){ const pill=card.querySelector('.status-pill'); if(pill){ pill.textContent='Select amenity'; pill.className='status-pill neutral'; } } return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const card=document.querySelector('.amenity-card.selected');
    if(!card) return;
    const pill=card.querySelector('.status-pill');
    if(!pill) return;
    if(!s||!e){pill.textContent='Select dates';pill.className='status-pill neutral';return}
    const sd=new Date(s),ed=new Date(e);
    pill.textContent='Checking availability…'; pill.className='status-pill neutral';
    const hrsRange=getAmenityHours(amenSel);
    const minH=parseInt(hrsRange.min.split(':')[0],10);
    const maxH=parseInt(hrsRange.max.split(':')[0],10);
    const totalHours=Math.max(0,maxH-minH);
    let fullyBookedFound=false;
    let partiallyBookedFound=false;
    for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){
      const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      try{
        const data=await fetchBookedTimesFor(ds);
        const booked=data.times||[];
        const reservedHours = getReservedHoursForDay(booked, minH, maxH, ds, amenSel);
        if(reservedHours>=totalHours){ fullyBookedFound=true; break; }
        if(reservedHours>0){ partiallyBookedFound=true; }
      }catch(_){ /* ignore and treat as not fully booked */ }
    }
    if(fullyBookedFound){ pill.textContent='Fully Booked'; pill.className='status-pill unavailable'; }
    else if(partiallyBookedFound){ pill.textContent='Partially Booked'; pill.className='status-pill partly'; }
    else { pill.textContent='Available'; pill.className='status-pill available'; }
  }

  function toMinutes(t){
    try{
      if(!t) return 0;
      const s = String(t).trim();
      const m = s.match(/^(\d{1,2})(?::(\d{2})(?::(\d{2}))?)?\s*(AM|PM)?$/i);
      if(!m) return 0;
      let h = parseInt(m[1] || '0', 10);
      const min = parseInt(m[2] || '0', 10);
      const ap = (m[4] || '').toUpperCase();
      if(ap === 'PM' && h < 12) h += 12;
      if(ap === 'AM' && h === 12) h = 0;
      const total = (h * 60) + min;
      return Math.max(0, Math.min(24 * 60, total));
    } catch(_) { return 0; }
  }
  async function checkTimeAvailability(){
    const amenSel=document.getElementById('amenityField').value; if(!amenSel){ computeAvailability(); return; }
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const card=document.querySelector('.amenity-card.selected');
    if(!card) return;
    const pill=card.querySelector('.status-pill');
    if(!pill) { return; }
    if(!s||!e||!st||!et){computeAvailability();return}
    if(s!==e){computeAvailability();return}
    if(st && et){
      const sMin=toMinutes(st);
      const eMin=toMinutes(et);
      const amen=document.getElementById('amenityField').value;
      const allowed=getAmenityHours(amen);
      const minHour=parseInt(allowed.min.split(':')[0],10);
      const maxHour=parseInt(allowed.max.split(':')[0],10);
      const shH=Math.floor(sMin/60); const ehH=Math.floor(eMin/60);
      if(eMin<=sMin || shH<minHour || ehH>maxHour){
        pill.textContent='Invalid time'; pill.className='status-pill unavailable';
        const te=document.getElementById('timeError'); if(te){ te.style.display='block'; te.textContent='Selected time is outside operating hours.'; }
        return;
      }
    }
    const data=await fetchBookedTimesFor(s);
    const times=data.times||[];
    const sMin2=toMinutes(st); const eMin2=toMinutes(et);
    const amen=document.getElementById('amenityField').value;
    const overlap=times.some(function(t){ if(!t.has_time){ return true; } const ts=toMinutes(t.start); const te=toMinutes(t.end); return !(sMin2>=te || eMin2<=ts); });
    if(overlap){pill.textContent='Unavailable';pill.className='status-pill unavailable'}
    else{
      const hrsRange=getAmenityHours(amen);
      const minH=parseInt(hrsRange.min.split(':')[0],10);
      const maxH=parseInt(hrsRange.max.split(':')[0],10);
      const totalHours=Math.max(0,maxH-minH);
      const reservedHours = getReservedHoursForDay(times, minH, maxH, s, amen);
      if(reservedHours>=totalHours){ pill.textContent='Fully Booked'; pill.className='status-pill unavailable'; }
      else if(reservedHours>0){ pill.textContent='Partially Booked'; pill.className='status-pill partly'; }
      else { pill.textContent='Available'; pill.className='status-pill available'; }
    }
    const te=document.getElementById('timeError');
    if(te){
      if(overlap){ te.style.display='block'; te.textContent='Time slot is already booked. Please choose a different time.'; }
      else { te.style.display='none'; te.textContent=''; }
    }
  }

  function isDateBooked(ds){ try { return bookedDates && bookedDates.has(ds); } catch(e){ return false; } }
  function showStartDateError(msg){ const el=document.getElementById('startDateError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ el.style.display='none'; m.textContent=''; }); } } else { el.style.display='none'; const m2=el.querySelector('.msg'); if(m2){ m2.textContent=''; } } }
  function showDateError(msg){ const el=document.getElementById('dateError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ el.style.display='none'; m.textContent=''; }); } } else { el.style.display='none'; const m2=el.querySelector('.msg'); if(m2){ m2.textContent=''; } } }
  function showTimeError(msg){ const el=document.getElementById('timeError'); if(!el) return; if(msg){ el.style.display='block'; let m=el.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; el.appendChild(m);} m.textContent=msg; let close=el.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; close.style.marginLeft='8px'; close.style.background='transparent'; close.style.border='0'; close.style.cursor='pointer'; close.style.color='#888'; el.appendChild(close); close.addEventListener('click',function(){ el.style.display='none'; m.textContent=''; }); } } else { el.style.display='none'; const m2=el.querySelector('.msg'); if(m2){ m2.textContent=''; } } }
  function validateDates(){
    const s=document.getElementById('startDateInput').value;
    const e=document.getElementById('endDateInput').value;
    const amen=document.getElementById('amenityField').value;
    if(!s||!e){ showStartDateError(''); showDateError(''); return false; }
    if(s < minDateStr || e < minDateStr){ showStartDateError('Reservations must be made at least 1 day in advance.'); showDateError(''); return false; }
    if(e < s){ showDateError('End date cannot be earlier than start date.'); showStartDateError(''); return false; }
    if(s > e){ showStartDateError('Start date cannot be later than end date.'); showDateError(''); return false; }
    const sD=new Date(s), eD=new Date(e); const diff=Math.floor((eD - sD)/(1000*60*60*24)); if(diff>6){ showDateError('Cannot book more than 1 week.'); return false; }
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    if(s===e){
      if(st){
        if(!et){ computeEndTimeFromHours(); }
        showStartDateError(''); showDateError('');
        return true;
      }
      showDateError(''); showStartDateError('Start time is required.');
      return false;
    }
    showStartDateError(''); showDateError(''); return true;
  }

  function setFieldWarning(id,msg){
    const container=(id==='startDateInput')?document.getElementById('startDateGroup'):(id==='endDateInput')?document.getElementById('endDateGroup'):(id==='amenityField')?document.querySelector('.amenities-list'):document.getElementById(id)?.closest('.res-item');
    if(!container)return;
    let w=container.querySelector('.field-warning[data-for="'+id+'"]');
    if(msg){
      if(!w){ w=document.createElement('div'); w.className='field-warning'; w.setAttribute('data-for',id); container.appendChild(w);} 
      if(id==='personsInput'){
        const note=document.getElementById('personsMaxNote');
        if(note && note.parentElement===container){
          container.insertBefore(w, note.nextSibling);
        }
      }
      let icon=w.querySelector('.warn-icon'); if(!icon){ icon=document.createElement('span'); icon.className='warn-icon'; icon.textContent='!'; w.appendChild(icon);} 
      let m=w.querySelector('.msg'); if(!m){ m=document.createElement('span'); m.className='msg'; w.appendChild(m);} m.textContent=msg;
      let close=w.querySelector('.close-warn'); if(!close){ close=document.createElement('button'); close.className='close-warn'; close.type='button'; close.textContent='\u00d7'; w.appendChild(close); close.addEventListener('click',function(){ w.remove(); }); }
    } else { if(w) w.remove(); }
  }

  let __dirtyFields = {};
  function markDirty(id){ try{ __dirtyFields[id] = true; }catch(_){} }
  function isDirty(id){ try{ return !!__dirtyFields[id]; }catch(_){ return false; } }
  function showIncompleteWarnings(force){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen){ if(force||isDirty('amenityField')) setFieldWarning('amenityField','Please select an amenity.'); } else { setFieldWarning('amenityField',''); }
    if(!s){ if(force||isDirty('startDateInput')) showStartDateError('Start date is required.'); } else { showStartDateError(''); }
    if(!eD){
      if(force||isDirty('endDateInput')){
        if(endDateRangeError){ showDateError('Cannot book more than 1 week.'); }
        else { showDateError('End date is required.'); }
      }
    }
    else {
      const sDVal=s; const eDVal=eD;
      if(sDVal){
        if(sDVal < minDateStr || eDVal < minDateStr){
          showStartDateError('Reservations must be made at least 1 day in advance.');
          endDateRangeError=false;
          showDateError('');
        } else {
          const sDate=new Date(sDVal); const eDate=new Date(eDVal); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6){ endDateRangeError=true; showDateError('Cannot book more than 1 week.'); } else { endDateRangeError=false; showDateError(''); }
        }
      } else { endDateRangeError=false; showDateError(''); }
    }
    if(!st){ if(force||isDirty('startTimeInput')) setFieldWarning('startTimeInput','Start time is required.'); } else { setFieldWarning('startTimeInput',''); }
    // End time is auto-computed from start time + hours; no manual warning
    if(st && !et){ computeEndTimeFromHours(); }
    if(isHourBasedAmenity(amen)){
      if(hours<1){ if(force||isDirty('hoursInput')) setFieldWarning('hoursInput','Number of hours must be at least 1.'); } else { setFieldWarning('hoursInput',''); }
    }
    const max=getAmenityMaxPersons(amen);
    if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); }
    else if(persons>max && max!==Infinity){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
    else { setFieldWarning('personsInput',''); }
  }

  function formIsComplete(){
    const amen=document.getElementById('amenityField').value;
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const st=document.getElementById('startTimeInput').value;
    const et=document.getElementById('endTimeInput').value;
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    if(!amen||!s||!eD) return false;
    if(s && eD && eD < s) return false;
    if(!st) return false;
    if(st){ if(!et){ computeEndTimeFromHours(); } const [sh,sm]=(st||'').split(':'), [eh,em]=(document.getElementById('endTimeInput').value||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) return false; }
    if(isHourBasedAmenity(amen)){ if(hours<1) return false; }
    if(persons<1) return false;
    const max=getAmenityMaxPersons(amen);
    if(max!==Infinity && persons>max) return false;
    return true;
  }

  document.getElementById("prevMonth").onclick=()=>{currentMonth=currentMonth===0?11:currentMonth-1;currentYear=currentMonth===11?currentYear-1:currentYear;renderCalendar(currentMonth,currentYear)};
  document.getElementById("nextMonth").onclick=()=>{currentMonth=currentMonth===11?0:currentMonth+1;currentYear=currentMonth===0?currentYear+1:currentYear;renderCalendar(currentMonth,currentYear)};
  loadBookedDates();

  document.querySelectorAll('[data-action="book-now"]').forEach(btn=>{
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      const card=this.closest('.amenity-card');
      if(card){
        this.style.display='none';
        const key=card.getAttribute('data-key');
        selectAmenityByKey(key);
        try{
          updateAmenityDescription(key);
          const descBox=document.getElementById('amenityDescBox');
          if(descBox){ descBox.style.display='flex'; }
          const descText=document.getElementById('amenityDescText');
          if(descText){ descText.textContent=''; descText.style.display='none'; }
        }catch(_){}
        const viewBtn=card.querySelector('button[data-action="view-desc"]');
        if(viewBtn){ viewBtn.style.display='none'; }
        document.querySelectorAll('.amenity-card').forEach(function(c){
          c.style.display='none';
        });
        const amenitiesHeader=document.getElementById('amenitiesHeader');
        if(amenitiesHeader){ amenitiesHeader.style.display='none'; }
        const ret=document.getElementById('amenityReturnBtn');
        if(ret){ ret.style.display='inline-flex'; }
        try{
          const rc=document.getElementById('reservationCard');
          if(rc){
            rc.style.display='flex';
            document.getElementById('reservationTitle').textContent='Reservation';
            document.getElementById('reservationHint').textContent='Select date, time, and persons';
            refreshAvailabilityFromServer();
            rc.scrollIntoView({behavior:'smooth',block:'start'});
          }
        }catch(_){}
      }
    });
  });
  
  document.querySelectorAll('button[data-action="view-desc"]').forEach(function(btn){
    btn.addEventListener('click',function(e){
      e.stopPropagation();
      const card=btn.closest('.amenity-card');
      if(card){
        document.querySelectorAll('button[data-action="view-desc"]').forEach(function(b){
          b.style.display='';
        });
        document.querySelectorAll('.amenity-card').forEach(function(c){
          c.removeAttribute('data-details-visible');
        });
        document.querySelectorAll('button[data-action="book-now"]').forEach(function(b){
          b.classList.remove('visible');
        });
        const bookNowBtn=card.querySelector('button[data-action="book-now"]');
        if(bookNowBtn){ bookNowBtn.classList.add('visible'); }
        const key=card.getAttribute('data-key');
        selectAmenityByKey(key);
        showInlineAmenityDetails(key);
        try{
          updateAmenityDescription(key);
          const descBox=document.getElementById('amenityDescBox');
          if(descBox){ descBox.style.display='flex'; }
        }catch(_){}
        btn.style.display='none';
      }
    });
  });
  

  ['startTimeInput','endTimeInput'].forEach(id=>{const el=document.getElementById(id);if(el){el.addEventListener('input',function(){ if(isHourBasedAmenity(document.getElementById('amenityField').value) && id==='startTimeInput'){ computeEndTimeFromHours(); } else { checkTimeAvailability(); } })}});
  const hoursEl=document.getElementById('hoursInput'); if(hoursEl){ hoursEl.addEventListener('input',function(){ computeEndTimeFromHours(); updateDisplayedPrice(); updateDownpaymentSuggestion(); }); }
  const hoursSelect=document.getElementById('hoursSelect'); if(hoursSelect){ hoursSelect.addEventListener('change',function(){ if(!requireDateBeforeHours()) return; const val=parseInt(hoursSelect.value||'0',10); if(!val) return; const hid=document.getElementById('hoursInput'); if(hid){ hid.value=String(val); const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(val); } }
    const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='block'; }
    computeEndTimeFromHours();
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    const chosen=document.getElementById('hoursChosen'); if(chosen) chosen.value='1';
    renderTimeSlotButtons();
  }); }
  document.addEventListener('DOMContentLoaded',function(){
    renderHoursDropdownForAmenity();
    renderTimeSlotButtons();

    const hs=document.getElementById('hoursSelect');
    if(hs){
      hs.addEventListener('mousedown',function(e){
        const s=document.getElementById('startDateInput')?.value||'';
        if(!s){
          e.preventDefault();
          setFieldWarning('hoursInput','You must pick a date first.');
        }
      });
    }
    const personInput=document.getElementById('personCount');
    if(personInput){
      personInput.addEventListener('input', async function(){
        const desired=parseInt(personInput.value||'0',10);
        await setPersonsCount(desired);
      });
    }
  });
  const cs=document.getElementById('clearStartBtn'); if(cs){ cs.addEventListener('click',clearStartDate); }
  const ce=document.getElementById('clearEndBtn'); if(ce){ ce.addEventListener('click',clearEndDate); }
  const formEl=document.querySelector('form');
  if(formEl){
    let submitting = false;
    formEl.addEventListener('submit', async function(e){
      e.preventDefault();
      if(submitting){ return; }
      persistForm();
      let verifyAllowed=true;
      const gateEl=document.getElementById('submitAllowed');
      if(gateEl && gateEl.value==='0'){ verifyAllowed=false; setFieldWarning('amenityField','Payment pending. Complete downpayment to continue.'); }
      const amen=document.getElementById('amenityField').value;
      const s=document.getElementById('startDateInput').value;
      const eD=document.getElementById('endDateInput').value;
      const st=document.getElementById('startTimeInput').value;
      const et=document.getElementById('endTimeInput').value;
      const dpVal=document.getElementById('downpaymentInput')?document.getElementById('downpaymentInput').value:'';
      const persons=parseInt(document.getElementById('personsInput').value||'0');
      const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
      showIncompleteWarnings(true);
      
      // Client-side + server-side points validation
      const usePointsToggle = document.getElementById('use-points-toggle');
      if (usePointsToggle && usePointsToggle.checked && typeof residentPoints !== 'undefined') {
        let reqPoints = 0;
        switch (amen) {
          case 'Basketball Court':
          case 'Tennis Court':
            reqPoints = 300;
            break;
          case 'Clubhouse':
            reqPoints = 600;
            break;
          case 'Multi-Purpose Building':
            reqPoints = 750;
            break;
        }
        // First check the cached balance
        if (residentPoints < reqPoints) {
          showPointsErrorPopup("You do not have enough VHEcoPoints to redeem the 1 free hour for this amenity. You need " + reqPoints.toLocaleString() + " pts but only have " + residentPoints.toLocaleString() + " pts. Please pay normally or earn more points.", true);
          return;
        }
        // Then do a live server check to catch race conditions or stale data
        const liveBalance = await fetchLivePointsBalance();
        if (liveBalance !== null && liveBalance < reqPoints) {
          // Update the cached balance so UI reflects reality
          window.__residentPoints = liveBalance;
          showPointsErrorPopup("You do not have enough VHEcoPoints to redeem the 1 free hour for this amenity. You need " + reqPoints.toLocaleString() + " pts but your current balance is " + liveBalance.toLocaleString() + " pts. Please pay normally or earn more points.", true);
          return;
        }
      }

      if(s && eD && (s < minDateStr || eD < minDateStr)){
        showStartDateError('Reservations must be made at least 1 day in advance.');
        showDateError('');
        return;
      }
      if(s && eD){ const sDate=new Date(s); const eDate=new Date(eD); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6){ showDateError('Cannot book more than 1 week.'); return; } }
      if(s && eD && s===eD && st && et){
        const [sh,sm]=(st||'').split(':');
        const [eh,em]=(et||'').split(':');
        const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10);
        const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10);
        if(eMin<=sMin){ setFieldWarning('endTimeInput','End time must be after start time (24-hour).'); verifyAllowed=false; }
      }
      if(dpVal!=='' && !isNaN(Number(dpVal))){ if(Number(dpVal)<0){ setFieldWarning('downpaymentInput','Downpayment cannot be negative.'); verifyAllowed=false; } }
      if(dpVal!=='' && !isNaN(Number(dpVal))){
        const dpNum=Number(dpVal);
        const rCount=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
        const gCount=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
        let basePrice=computeDynamicPrice(amen, rCount, gCount, hours);
        if(dpNum>basePrice){ setFieldWarning('downpaymentInput','Downpayment cannot exceed total price.'); verifyAllowed=false; }
      }
      if(isPersonBasedAmenity(amen) && persons<1){ setFieldWarning('personsInput','Persons must be at least 1.'); verifyAllowed=false; }
      if(s && eD && s===eD && st && et){
        const data=await fetchBookedTimesFor(s);
        const times=(data && data.times) ? data.times : [];
        const sM=toMinutes(st), eM=toMinutes(et);
        const overlap=times.some(function(t){ if(!t.has_time){ return true; } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); });
        if(overlap){
          showTimeError('Selected time overlaps an existing booking. Please choose a different time.');
          return;
        }
      }
      const amenVal=document.getElementById('amenityField').value;
      if(!amenVal || !s || !eD){ verifyAllowed=false; }
      if(isHourBasedAmenity(amenVal)){
        if(!st || !et){ verifyAllowed=false; }
        if(hours<1) verifyAllowed=false;
      } else {
        if(persons<1) verifyAllowed=false;
      }
      if(!verifyAllowed){ showToast('Please complete all fields accurately before proceeding.','warning'); return; }
      if(!window.__verifyConfirmed){
        const rCount=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
        const gCount=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
        let basePriceForSummary=computeDynamicPrice(amenVal, rCount, gCount, hours);
        const priceTxt = '₱'+basePriceForSummary.toFixed(2);
        const hoursRaw = document.getElementById('hoursInput').value||'';
        const hoursVal = hoursRaw ? parseInt(hoursRaw,10) : null;
        const personsVal = parseInt(document.getElementById('personsInput').value||'1', 10);
        function formatTimeLabel(t){
          if(!t) return '';
          const parts=String(t).split(':');
          let h=parseInt(parts[0]||'0',10);
          const m=(parts[1]||'00').padStart(2,'0');
          const ampm=h>=12?'PM':'AM';
          if(h===0){ h=12; }
          else if(h>12){ h=h-12; }
          return h+':'+m+' '+ampm;
        }
        const startTimeLabel=formatTimeLabel(st);
        const endTimeLabel=formatTimeLabel(et);
        let timeDisplay='';
        if(startTimeLabel){
          timeDisplay=startTimeLabel;
          if(endTimeLabel){
            timeDisplay+=' to '+endTimeLabel;
          }
        }
        if(hoursVal){
          const hoursText=hoursVal+' hour'+(hoursVal>1?'s':'');
          timeDisplay=hoursText+(timeDisplay ? ' — '+timeDisplay : '');
        }
        let durationDisplay='-';
        if(s && eD){
          const sDate=new Date(s);
          const eDate=new Date(eD);
          const diffDays = Math.floor((eDate - sDate)/(1000*60*60*24));
          const days = isNaN(diffDays) ? 0 : (diffDays + 1);
          if(days > 0){
            if(days === 1 && hoursVal){
              durationDisplay = hoursVal + ' hour' + (hoursVal > 1 ? 's' : '');
            } else {
              durationDisplay = days + ' day' + (days > 1 ? 's' : '');
            }
          }
        }
        const displayPrice = usePoints ? '₱0.00' : priceTxt;
        let displayDownpayment = (dpVal!==''?('₱'+Number(dpVal).toFixed(2)):'—');

        function vsRow(label, value, extraClass){
          return '<div class="vs-row'+(extraClass?' '+extraClass:'')+'"><span class="vs-lbl">'+label+'</span><span class="vs-val">'+value+'</span></div>';
        }

        let summaryHTML = '';

        if (usePoints) {
          const pointsNeeded = getPointsRequired(amenVal);
          const hourlyRate = getHourlyRate(amenVal, isResidentSelfBooking());
          const fullBase = computeDynamicPrice(amenVal, rCount, gCount, hoursVal);
          const paidHours = Math.max(0, hoursVal - 1);
          const discountVal = hourlyRate;
          const chargedAmount = paidHours * hourlyRate;
          displayDownpayment = paidHours > 0 ? '₱' + (chargedAmount * 0.5).toFixed(2) : '₱0.00';

          summaryHTML += '<div class="vs-banner"><i class="fa-solid fa-circle-check"></i> 1 Free Hour Redeemed with VHEcoPoint Points!</div>';

          summaryHTML += '<div class="vs-section">'
            + '<div class="vs-section-title">Reservation Details</div>'
            + vsRow('Amenity', amenVal||'-')
            + vsRow('Start Date', formatDateToMMDDYYYY(s) || '-')
            + vsRow('End Date', formatDateToMMDDYYYY(eD) || '-')
            + vsRow('Time', timeDisplay || '-')
            + vsRow('Persons', String(personsVal))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-reward">'
            + '<div class="vs-section-title">VHEcoPoint Reward</div>'
            + vsRow('Original Duration', hoursVal + ' hour' + (hoursVal > 1 ? 's' : ''))
            + vsRow('VHEcoPoint Reward', '-1 Free Hour (' + pointsNeeded.toLocaleString() + ' pts)', 'vs-good')
            + vsRow('Paid Duration', paidHours + ' hour' + (paidHours !== 1 ? 's' : ''))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-payment">'
            + '<div class="vs-section-title">Payment Summary</div>'
            + vsRow('Original Amount', '₱' + fullBase.toFixed(2))
            + vsRow('VHEcoPoint Discount', '-₱' + discountVal.toFixed(2), 'vs-good')
            + vsRow('Final Amount', '₱' + chargedAmount.toFixed(2), 'vs-final-row')
            + vsRow('Downpayment', displayDownpayment, 'vs-dp-row')
            + '</div>';
        } else {
          summaryHTML += '<div class="vs-section">'
            + '<div class="vs-section-title">Reservation Details</div>'
            + vsRow('Amenity', amenVal||'-')
            + vsRow('Mode', usePoints ? 'Redeem Points' : 'Cash')
            + vsRow('Start Date', formatDateToMMDDYYYY(s) || '-')
            + vsRow('End Date', formatDateToMMDDYYYY(eD) || '-')
            + vsRow('Duration', durationDisplay)
            + vsRow('Time', timeDisplay || '-')
            + vsRow('Persons', String(personsVal))
            + '</div>';

          summaryHTML += '<div class="vs-section vs-payment">'
            + '<div class="vs-section-title">Payment Summary</div>'
            + vsRow('Total Price', displayPrice)
            + vsRow('Downpayment', displayDownpayment, 'vs-dp-row')
            + '</div>';
        }

        const sumEl=document.getElementById('verifySummary'); if(sumEl){ sumEl.innerHTML = summaryHTML; }
        const vm=document.getElementById('verifyModal'); if(vm){ vm.style.display='flex'; }
        return;
      } else {
        window.__verifyConfirmed=false;
        submitting=true;
        formEl.submit();
        return;
      }
    });
    formEl.addEventListener('keydown',function(e){
      if(e.key==='Enter'){
        const target=e.target||e.srcElement;
        const tag=(target && target.tagName)?String(target.tagName).toUpperCase():'';
        const type=(target && target.getAttribute)?String(target.getAttribute('type')||'').toLowerCase():'';
        if(tag!=='TEXTAREA' && tag!=='BUTTON' && type!=='button' && type!=='submit'){
          e.preventDefault();
        }
      }
    });
  }
  (function(){
    const vm=document.getElementById('verifyModal');
    const cBtn=document.getElementById('verifyCancelBtn');
    const xBtn=document.getElementById('verifyCloseBtn');
    const pBtn=document.getElementById('verifyConfirmBtn');
    window.__verifyConfirmed=false;
    if(cBtn){ cBtn.addEventListener('click', function(){ if(vm){ vm.style.display='none'; } }); }
    if(xBtn){ xBtn.addEventListener('click', function(){ if(vm){ vm.style.display='none'; } }); }
    if(pBtn){
      pBtn.addEventListener('click', function(){
        showIncompleteWarnings();
        if(!formIsComplete()){
          showToast('Please fix the highlighted fields before proceeding.','warning');
          return;
        }
        var bookingForField = document.getElementById('bookingForField');
        var userType = "<?php echo isset($_SESSION['user_type']) ? htmlspecialchars($_SESSION['user_type'], ENT_QUOTES) : ''; ?>";
        if (bookingForField) {
          if (userType === 'resident') {
            var wrap = document.getElementById('participantWrap');
            var mode = wrap ? (wrap.getAttribute('data-mode') || '') : '';
            bookingForField.value = mode === 'guest_only' ? 'guest' : 'resident';
          } else {
            bookingForField.value = 'guest';
          }
        }
        refreshPricingForBookingFor();
    window.__verifyConfirmed=true;
    try{ document.getElementById('clientConfirmed').value='1'; }catch(_){}
    // Set use points input
    const usePointsInput = document.getElementById('use-points-input');
    if (usePointsInput) {
      usePointsInput.value = usePoints ? '1' : '0';
    }
        if(vm){ vm.style.display='none'; }
        showToast('Details confirmed.','success');
        const f = (typeof formEl !== 'undefined' && formEl) ? formEl : document.querySelector('form');
        if(f){
          if(typeof f.requestSubmit === 'function'){
            f.requestSubmit();
          } else {
            f.submit();
          }
        }
      });
    }
  })();

  (function(){
    if(currentUserType !== 'resident') return;
    const selectors=document.querySelectorAll('.participant-selector');
    if(!selectors.length) return;
    const residentsCountInput=document.getElementById('residentsCountInput');
    const guestsCountInput=document.getElementById('guestsCountInput');
    function updateCounts(){
      const amen=document.getElementById('amenityField')?.value||'';
      let rSel=0, gSel=0;
      const wrap=document.getElementById('participantWrap');
      const mode=wrap ? (wrap.getAttribute('data-mode')||'') : '';
      selectors.forEach(function(sel){
        const rGroup=sel.querySelector('.resident-group');
        const gGroup=sel.querySelector('.guest-group');
        if(rGroup && rGroup.style.display!=='none'){ rSel += sel.querySelectorAll('.resident-check:checked').length; }
        if(gGroup && gGroup.style.display!=='none'){
          if(mode==='resident_guest'){
            const gInput=document.getElementById('guestsCountInput');
            const val=Math.max(0, parseInt(gInput?.value||'0',10));
            gSel = Math.min(val, approvedGuestsMax>=0 ? approvedGuestsMax : val);
            sel.querySelectorAll('.guest-check').forEach(function(cb){ cb.checked=false; cb.disabled=true; });
          } else {
            const checks=sel.querySelectorAll('.guest-check');
            const selected=Array.from(checks).filter(cb=>cb.checked);
            if(approvedGuestsMax>=0 && selected.length>approvedGuestsMax){
              selected.slice(approvedGuestsMax).forEach(function(cb){ cb.checked=false; });
            }
            gSel = Math.min(selected.length, approvedGuestsMax>=0 ? approvedGuestsMax : selected.length);
            Array.from(checks).forEach(function(cb){
              cb.disabled = (!cb.checked && approvedGuestsMax>=0 && gSel>=approvedGuestsMax);
            });
          }
        }
      });
      if(residentsCountInput) residentsCountInput.value=String(rSel);
      if(guestsCountInput) guestsCountInput.value=String(gSel);
      const total=rSel+gSel;
      const pInput=document.getElementById('personsInput'); if(pInput){ pInput.value=String(total); }
      const pText=document.getElementById('personCount'); if(pText){ if('value' in pText){ pText.value=String(total); } else { pText.textContent=String(total); } }
      const max=getAmenityMaxPersons(amen);
      let hasPersonsError=false;
      if(total < 1){
        setFieldWarning('personsInput','At least 1 participant is required.');
        hasPersonsError=true;
      } else if(max!==Infinity && total>max){
        setFieldWarning('personsInput',`Maximum is ${max} persons.`);
        hasPersonsError=true;
      } else {
        setFieldWarning('personsInput','');
      }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      if(typeof persistForm === 'function') persistForm();
    }
    function applyModeTo(sel, m){
      const rGroup=sel.querySelector('.resident-group');
      const gGroup=sel.querySelector('.guest-group');
      if(!rGroup || !gGroup) return;
      const wrap=document.getElementById('participantWrap');
      if(wrap){ wrap.setAttribute('data-mode', m); }
      if(m==='resident_only'){
        rGroup.style.display='block';
        gGroup.style.display='none';
        const me=sel.querySelector('.resident-check')||null;
        if(me){ me.checked=true; }
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.checked=false; });
      } else if(m==='resident_guest'){
        rGroup.style.display='block';
        gGroup.style.display='block';
        const me=sel.querySelector('.resident-check')||null;
        if(me){ me.checked=true; }
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.checked=false; x.disabled=true; });
      } else {
        rGroup.style.display='none';
        gGroup.style.display='block';
        sel.querySelectorAll('.resident-check').forEach(function(x){ x.checked=false; });
        sel.querySelectorAll('.guest-check').forEach(function(x){ x.disabled=false; });
      }
      updateCounts();
    }
    selectors.forEach(function(sel){
      sel.querySelectorAll('.mode-options [data-mode]').forEach(function(btn){
        btn.addEventListener('click',function(){
          applyModeTo(sel, btn.getAttribute('data-mode')||'resident_only');
        });
      });
      sel.addEventListener('change',function(e){
        if(e.target.classList.contains('resident-check')||e.target.classList.contains('guest-check')){
          updateCounts();
        }
      });
      applyModeTo(sel, 'resident_only');
    });
    updateCounts();
    updateParticipantVisibility();
  })();

  (function(){
    if(currentUserType === 'resident') return;
    const wrap=document.getElementById('participantWrap');
    if(!wrap) return;
    wrap.style.display='none';
    wrap.setAttribute('data-mode','guest_only');
    const bookingForField=document.getElementById('bookingForField');
    if(bookingForField) bookingForField.value='guest';
    const rInput=document.getElementById('residentsCountInput');
    const rText=document.getElementById('residentsCountText');
    const gInput=document.getElementById('guestsCountInput');
    const gText=document.getElementById('guestsCountText');
    const pInput=document.getElementById('personsInput');
    const pText=document.getElementById('personCount');
    if(rInput) rInput.value='0';
    if(rText) rText.textContent='0';
    const baseCount=parseInt((pInput && pInput.value) || (gText && gText.textContent) || '1',10) || 1;
    if(gInput) gInput.value=String(Math.max(1, baseCount));
    if(gText) gText.textContent=String(Math.max(1, baseCount));
    if(pInput) pInput.value=String(Math.max(1, baseCount));
    if(pText) pText.textContent=String(Math.max(1, baseCount));
    const btn=wrap.querySelector('.mode-options [data-mode="guest_only"]');
    if(btn){
      btn.addEventListener('click',function(){
        wrap.setAttribute('data-mode','guest_only');
      });
    }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(typeof persistForm === 'function') persistForm();
    updateParticipantVisibility();
  })();

  (function(){
    const modal=document.getElementById('changeAmenityModal');
    if(!modal) return;
    const cancelBtn=document.getElementById('changeAmenityCancelBtn');
    const closeBtn=document.getElementById('changeAmenityCloseBtn');
    const confirmBtn=document.getElementById('changeAmenityConfirmBtn');
    if(cancelBtn){
      cancelBtn.addEventListener('click',function(){
        vpHideModal(modal);
      });
    }
    if(closeBtn){
      closeBtn.addEventListener('click',function(){
        vpHideModal(modal);
      });
    }
    if(confirmBtn){
      confirmBtn.addEventListener('click',function(){
        vpHideModal(modal);
        resetAmenitySelection();
      });
    }
  })();
  function showToast(message,type){
    const nl=document.getElementById('notifyLayer'); if(!nl) return;
    nl.innerHTML = '<span class="msg">'+message+'</span><button type="button" class="toast-close" aria-label="Close">\u00d7</button>';
    nl.style.background= type==='warning' ? '#8a2a2a' : type==='success' ? '#23412e' : '#345c40';
    nl.style.display='block';
    const btn=nl.querySelector('.toast-close'); if(btn){ btn.onclick=function(){ nl.style.display='none'; if(nl.__t){ clearTimeout(nl.__t); } }; }
    clearTimeout(nl.__t);
    nl.__t=setTimeout(function(){ nl.style.display='none'; }, 4000);
  }
  function updateActionStates(){
    const s=document.getElementById('startDateInput').value;
    const eD=document.getElementById('endDateInput').value;
    const amenVal=document.getElementById('amenityField').value;
    const stInput=document.getElementById('startTimeInput');
    const etInput=document.getElementById('endTimeInput');
    const st=stInput ? stInput.value : '';
    const et=etInput ? etInput.value : '';
    const persons=parseInt(document.getElementById('personsInput').value||'0');
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0');
    const submitBtn=document.getElementById('submitBtn');
    const gate=document.getElementById('submitAllowed');
    let allowed=true;
    const max=getAmenityMaxPersons(amenVal);
    if(!amenVal) allowed=false;
    if(!s||!eD) allowed=false;
    const requiresTime = true;
    if(requiresTime && (!st||!et)) allowed=false;
    if(s&&eD && eD < s) allowed=false;
    if(s&&eD){ const sDate=new Date(s); const eDate=new Date(eD); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6) allowed=false; }
    if(requiresTime && st&&et){ const [sh,sm]=(st||'').split(':'), [eh,em]=(et||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) allowed=false; }
    if(isHourBasedAmenity(amenVal)){ if(hours<1) allowed=false; }
    if(persons<1) allowed=false;
    if(max!==Infinity && persons>max) allowed=false;
    
    if(submitBtn){ if(allowed){ submitBtn.classList.remove('disabled'); submitBtn.removeAttribute('disabled'); } else { submitBtn.classList.add('disabled'); submitBtn.setAttribute('disabled','disabled'); } }
    const sw=document.getElementById('submitWrap'); if(sw){ sw.style.display = 'flex'; }
  }
  function persistForm(){
    try{
      const data={
        amenity:document.getElementById('amenityField').value||'',
        start_date:document.getElementById('startDateInput').value||'',
        end_date:document.getElementById('endDateInput').value||'',
        start_time:document.getElementById('startTimeInput').value||'',
        end_time:document.getElementById('endTimeInput').value||'',
        persons:document.getElementById('personsInput').value||'1',
        hours:document.getElementById('hoursInput')?.value||'',
        downpayment:document.getElementById('downpaymentInput')?.value||'',
        booking_for:document.getElementById('bookingForField')?.value||'',
        guest_id:document.getElementById('guestIdField')?.value||'',
        guest_ref:document.getElementById('guestRefField')?.value||'',
        residents_count:document.getElementById('residentsCountInput')?.value||'',
        guests_count:document.getElementById('guestsCountInput')?.value||''
      };
      sessionStorage.setItem('reserve_form', JSON.stringify(data));
    }catch(_){}
  }
  function restoreFormFromSession(){
    try{
      const raw=sessionStorage.getItem('reserve_form'); if(!raw) return;
      const data=JSON.parse(raw||'{}');
      if(data.amenity){ document.getElementById('amenityField').value=data.amenity; }
      if(data.start_date){ document.getElementById('startDateInput').value=data.start_date; }
      if(data.end_date){ document.getElementById('endDateInput').value=data.end_date; }
      if(data.start_time){ document.getElementById('startTimeInput').value=data.start_time; }
      if(data.end_time){ document.getElementById('endTimeInput').value=data.end_time; }
      if(data.persons){ document.getElementById('personsInput').value=data.persons; document.getElementById('personCount').textContent=String(data.persons); }
      if(data.booking_for){
        const bookingForField=document.getElementById('bookingForField');
        if(bookingForField) bookingForField.value=data.booking_for;
      }
      if(data.guest_id){
        const guestIdField=document.getElementById('guestIdField');
        if(guestIdField) guestIdField.value=data.guest_id;
      }
      if(data.guest_ref){
        const guestRefField=document.getElementById('guestRefField');
        if(guestRefField) guestRefField.value=data.guest_ref;
      }
      if(data.residents_count && document.getElementById('residentsCountInput')){
        document.getElementById('residentsCountInput').value=data.residents_count;
        const rt=document.getElementById('residentsCountText'); if(rt){ rt.textContent=String(data.residents_count); }
      }
      if(data.guests_count && document.getElementById('guestsCountInput')){
        document.getElementById('guestsCountInput').value=data.guests_count;
        const gt=document.getElementById('guestsCountText'); if(gt){ gt.textContent=String(data.guests_count); }
      }
      if(data.hours && document.getElementById('hoursInput')){ document.getElementById('hoursInput').value=data.hours; const hc=document.getElementById('hoursCount'); if(hc){ hc.textContent=String(data.hours); } }
      if(data.downpayment && document.getElementById('downpaymentInput')){ document.getElementById('downpaymentInput').value=data.downpayment; }
      configureFieldsForAmenity(document.getElementById('amenityField').value);
      const amenName=(document.getElementById('amenityField').value||'');
      const key = amenName==='Clubhouse' ? 'clubhouse' : amenName==='Multi-Purpose Building' ? 'multipurpose' : amenName==='Basketball Court' ? 'basketball' : amenName==='Tennis Court' ? 'tennis' : '';
      document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card){ card.classList.add('selected'); }
      refreshPricingForBookingFor();
      updateParticipantVisibility();
      selectedAmenity = document.getElementById('amenityField').value || '';
      refreshAvailabilityFromServer();
    }catch(_){}
  }
  ['amenityField','startDateInput','endDateInput','startTimeInput','endTimeInput','personsInput','hoursInput','downpaymentInput'].forEach(id=>{const el=document.getElementById(id); if(el){ el.addEventListener('input',function(){ markDirty(id); persistForm(); updateActionStates(); showIncompleteWarnings(false); if(typeof updateBookingSummary === 'function'){ updateBookingSummary(); } }); }});
  async function refreshAvailabilityFromServer(){
    selectedAmenity = document.getElementById('amenityField').value || '';
    availabilityCache.clear();
    await loadBookedDates(true);
    computeAvailability();
    renderTimeSlotButtons();
    if(document.getElementById('startDateInput').value){ checkTimeAvailability(); }
  }
  document.addEventListener('DOMContentLoaded',function(){ restoreFormFromSession(); updateActionStates(); updateDisplayedPrice(); updateDownpaymentSuggestion(); updateBookingSummary(); initSingleDayToggle(); updateHoursSelectEnabled(); try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){} });
  window.addEventListener('pageshow',function(){ refreshAvailabilityFromServer(); });
  window.addEventListener('focus',function(){ refreshAvailabilityFromServer(); });
  window.addEventListener('storage',function(e){
    try{
      if(!e || !e.key) return;
      if(String(e.key).indexOf('cancelled:')===0){
        refreshAvailabilityFromServer();
      }
    }catch(_){}
  });
  document.addEventListener('DOMContentLoaded',function(){ const s=document.getElementById('startTimeInput'); const e=document.getElementById('endTimeInput'); if(s){ s.value=''; } if(e){ e.value=''; } });
  document.addEventListener('DOMContentLoaded',function(){
     const hs=document.getElementById('hoursSelect');
     if(hs){
       const check=function(e){
         if(!requireDateBeforeHours()){
           e.preventDefault();
           e.stopPropagation();
           this.blur();
           return false;
         }
       };
       hs.addEventListener('mousedown', check);
       hs.addEventListener('click', check);
     }
     ['startDateInput','endDateInput'].forEach(function(id){
       const el=document.getElementById(id);
       if(el){
         el.addEventListener('input', function(){
            const s=document.getElementById('startDateInput').value;
            const e=document.getElementById('endDateInput').value;
            if(s && e){
              setFieldWarning('hoursInput','');
            }
         });
       }
     });
   });
  document.addEventListener('DOMContentLoaded',function(){
    var panel=document.querySelector('.booking-steps');
    var toggle=document.getElementById('bookingStepsToggle');
    if(panel&&toggle){
      toggle.addEventListener('click',function(){
        var collapsed=panel.classList.toggle('is-collapsed');
        toggle.textContent=collapsed?'+':'−';
        toggle.setAttribute('aria-expanded',collapsed?'false':'true');
      });
    }
    var proTip=document.getElementById('proTipFloat');
    var proTipDismiss=document.getElementById('proTipDismiss');
    if(proTip&&proTipDismiss){
      proTipDismiss.addEventListener('click',function(){
        proTip.style.transition='opacity .2s,transform .2s';
        proTip.style.opacity='0';
        proTip.style.transform='translateY(-6px)';
        setTimeout(function(){ proTip.remove(); },200);
      });
    }
  });
  function goBack(){ persistForm(); if(document.referrer){ window.history.back(); } else { window.location.href = 'mainpage.php'; } }
  function closeModal(){document.getElementById('refModal').style.display='none'}
  function closeHint(){document.getElementById('hintModal').style.display='none'}
</script>
<?php if ($resetReservation) { ?>
<script>
  (function(){
    try{ sessionStorage.removeItem('reserve_form'); }catch(_){}
    if(typeof clearBookingFormState === 'function'){
      document.addEventListener('DOMContentLoaded', function(){
        clearBookingFormState();
        if(typeof updateBookingSummary === 'function'){ updateBookingSummary(); }
        if(typeof updateActionStates === 'function'){ updateActionStates(); }
      });
    }
    document.addEventListener('DOMContentLoaded', function(){
      var modal = document.getElementById('resetReservationModal');
      var okBtn = document.getElementById('resetReservationOkBtn');
      var closeBtn = document.getElementById('resetReservationCloseBtn');
      if(modal){ modal.style.display='flex'; }
      if(okBtn){ okBtn.addEventListener('click', function(){ if(modal) modal.style.display='none'; }); }
      if(closeBtn){ closeBtn.addEventListener('click', function(){ if(modal) modal.style.display='none'; }); }
      if(modal){ modal.addEventListener('click', function(e){ if(e.target === modal){ modal.style.display='none'; } }); }
    });
  })();
</script>
<?php } ?>

<script>
  function formatTimeSlot(h){ const ampm = h>=12 ? 'PM' : 'AM'; let hh=h%12; if(hh===0) hh=12; return `${hh}:00 ${ampm}`; }
  function formatTimeHM(h,m){ const ap=h>=12?'PM':'AM'; let hh=h%12; if(hh===0) hh=12; const mm=String(m).padStart(2,'0'); return `${hh}:${mm} ${ap}`; }
  function generateTimeSlots(amenity){ const hrs=getAmenityHours(amenity); const min=parseInt(hrs.min.split(':')[0],10); const max=parseInt(hrs.max.split(':')[0],10); const out=[]; for(let h=min; h<max; h++){ out.push({ label: formatTimeSlot(h), value: `${String(h).padStart(2,'0')}:00` }); } return out; }
  function computeMaxDuration(amenity,startHour,booked,selectedDate){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); let max=0; for(let h=1; startHour+h<=maxHour; h++){ const thisStart=`${String(startHour).padStart(2,'0')}:00`; const thisEnd=`${String(startHour+h).padStart(2,'0')}:00`; const sM=toMinutes(thisStart), eM=toMinutes(thisEnd); const overlaps=(booked||[]).some(function(t){ if(selectedDate && t.start_date && t.end_date && (t.end_date < selectedDate || t.start_date > selectedDate)) return false; if(!t.has_time){ const bS=parseInt(hrs.min.split(':')[0],10); return !(eM<=bS || sM>=maxHour); } const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); if(overlaps) break; max=h; } return max; }

  function renderHoursChipsForAmenity(){ const amen=document.getElementById('amenityField').value; const dc=document.getElementById('durationContainer'); const lbl=document.getElementById('hoursSectionLabel'); if(!dc) return; dc.innerHTML=''; if(!isHourBasedAmenity(amen)){ dc.style.display='none'; if(lbl) lbl.style.display='none'; return; } dc.style.display='flex'; if(lbl) lbl.style.display='block'; dc.style.flexWrap='wrap'; dc.style.gap='8px'; dc.style.margin='8px 0 0 0'; const maxH=(amen==='Clubhouse' || amen==='Multi-Purpose Building')?12:9; for(let h=1; h<=maxH; h++){ const b=document.createElement('button'); b.type='button'; b.className='dur-btn'; b.textContent=`${h}h`; b.dataset.hours=String(h); b.onclick=function(){ selectDuration(h); }; dc.appendChild(b); } const currentH=parseInt(document.getElementById('hoursInput').value||'',10); if(currentH){ const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(currentH)); if(sel) sel.classList.add('selected'); } }

  function renderTimeSlotButtons(){
    const amen=document.getElementById('amenityField').value;
    const container=document.getElementById('timeSlotContainer');
    const tLbl=document.getElementById('timeSectionLabel');
    const notice=document.getElementById('availabilityNotice');
    if(!container) return;
    container.innerHTML='';
    if(notice){ notice.style.display='none'; notice.textContent=''; }

    if(!isHourBasedAmenity(amen)){
      container.style.display='none';
      if(tLbl) tLbl.style.display='none';
      return;
    }
    const slots=generateTimeSlots(amen);
    const date=document.getElementById('startDateInput').value;
    const hours=parseInt(document.getElementById('hoursInput').value||'0',10);
    const hoursChosenEl=document.getElementById('hoursChosen');
    const hasChosenHours=hoursChosenEl && hoursChosenEl.value==='1';
    if(!date){
      container.style.display='none';
      if(tLbl) tLbl.style.display='none';
      return;
    }
    container.style.display='grid';
    try{
      const w = window.innerWidth || document.documentElement.clientWidth || 1366;
      let cols = 5;
      if(w < 1280) cols = 4;
      if(w < 1100) cols = 3;
      if(w < 860) cols = 2;
      container.style.gridTemplateColumns = `repeat(${cols},minmax(0,1fr))`;
    }catch(_){
      container.style.gridTemplateColumns='repeat(5,minmax(0,1fr))';
    }
    container.style.gap='8px';
    container.style.margin='8px 0 0 0';
    if(tLbl) tLbl.style.display='block';
    if(!hasChosenHours){
      slots.forEach(function(slot){
        const btn=document.createElement('button');
        btn.type='button';
        btn.className='slot-btn unavailable';
        btn.textContent=slot.label;
        btn.onclick=function(){
          showTimeError('Select number of hours first to pick a start time.');
        };
        container.appendChild(btn);
      });
      return;
    }
    window.__slotRenderTokenCounter=(window.__slotRenderTokenCounter||0)+1; const __token=window.__slotRenderTokenCounter; window.__activeSlotRenderToken=__token; if(!date){ container.innerHTML=''; if(notice){ notice.style.display='none'; notice.textContent=''; } return; } fetchBookedTimesFor(date).then(data=>{ if(window.__activeSlotRenderToken!==__token) return; const booked=data.times||[]; window.__bookedTimesForDate=booked||[]; let anyEnabled=false; let disabledCount=0; slots.forEach(slot=>{ const startHour=parseInt(slot.value.split(':')[0],10); const maxPossible=computeMaxDuration(amen,startHour,booked,date); const valid=(maxPossible>=hours); const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb'; btn.textContent=slot.label; btn.dataset.slot=slot.value; if(!valid){ disabledCount++; btn.classList.add('unavailable'); btn.setAttribute('aria-disabled','true'); btn.onclick=function(){ showToast('This start time cannot fit your selected duration. Try a different start time or duration.','warning'); }; } else { anyEnabled=true; btn.classList.add('available'); btn.onclick=function(){ selectTimeSlot(slot.value); }; } container.appendChild(btn); }); let hasBookedHours=false; (booked||[]).forEach(function(t){ if(!t.has_time){ hasBookedHours=true; return; } const bS=parseInt(String(t.start).split(':')[0],10); const bE=parseInt(String(t.end).split(':')[0],10); if(bE>bS){ hasBookedHours=true; } }); if(notice){ if(!anyEnabled){ notice.style.display='block'; notice.textContent = hasBookedHours ? 'Fully Booked — no time slots available for this date.' : ''; } else if(disabledCount>0){ notice.style.display='block'; notice.textContent = hasBookedHours ? 'Partially Booked — some time slots are unavailable.' : ''; } else { notice.style.display='none'; notice.textContent=''; } } if(!anyEnabled){ showTimeError('No start times fit the selected hours. Try a different duration.'); } else { showTimeError(''); } const st=document.getElementById('startTimeInput').value; if(st){ const selBtn=Array.from(container.children).find(b=>b.tagName==='BUTTON' && b.dataset.slot===st); if(selBtn) selBtn.classList.add('selected'); } updateActionStates(); }); }

  function selectTimeSlot(start){ const hInput=document.getElementById('hoursInput'); const hrs=parseInt(hInput?.value||'0',10); if(!hrs || hrs<1){ showTimeError('Please select number of hours before choosing a start time.'); return; } const amen=document.getElementById('amenityField').value; const booked=window.__bookedTimesForDate||[]; const startHour=parseInt(start.split(':')[0],10); const selDate=document.getElementById('startDateInput')?.value||''; if(computeMaxDuration(amen,startHour,booked,selDate) < Math.max(1,hrs)){ showTimeError('This start time cannot fit your selected duration. Try a different start time or duration.'); showToast(`<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Not enough free hours starting from this time to complete ${hrs} hour${hrs>1?'s':''}.`,'warning'); return; } document.getElementById('startTimeInput').value=start; computeEndTimeFromHours(); const sh=startHour, eh=sh+hrs; const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.innerHTML='<i class="fa-regular fa-clock" aria-hidden="true"></i> Selected Time: '+formatTimeSlot(sh)+' - '+formatTimeSlot(eh); tr.style.display='block'; } const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='none'; } const cont=document.getElementById('timeSlotContainer'); if(cont){ Array.from(cont.querySelectorAll('.slot-btn')).forEach(function(b){ b.classList.remove('selected'); }); const sel=Array.from(cont.querySelectorAll('.slot-btn')).find(function(b){ return b.dataset.slot===start; }); if(sel){ sel.classList.add('selected'); } } showTimeError(''); updateActionStates(); }
  function renderHoursDropdownForAmenity(){
    const amen=document.getElementById('amenityField').value;
    const sel=document.getElementById('hoursSelect');
    const lbl=document.getElementById('hoursSectionLabel');
    if(!sel) return;
    sel.innerHTML='';
    if(!isHourBasedAmenity(amen)){ sel.style.display='none'; if(lbl) lbl.style.display='none'; return; }
    sel.style.display='inline-block'; if(lbl) lbl.style.display='block';
    const blankOpt=document.createElement('option'); blankOpt.value=''; blankOpt.textContent='Select hours'; blankOpt.disabled=true; blankOpt.selected=true; sel.appendChild(blankOpt);
    const maxH=(amen==='Clubhouse' || amen==='Multi-Purpose Building')?12:9;
    for(let h=1; h<=maxH; h++){ const opt=document.createElement('option'); opt.value=String(h); opt.textContent=`${h} hour${h>1?'s':''}`; sel.appendChild(opt); }
    const currentH=parseInt(document.getElementById('hoursInput').value||'',10);
    if(currentH) sel.value=String(currentH);
  }

  function decorateSlotButtons(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const amen=document.getElementById('amenityField').value;
    const hours=parseInt(document.getElementById('hoursInput').value||'0',10);
    const booked=window.__bookedTimesForDate||[];
    const selectedDate=document.getElementById('startDateInput').value||'';
    const now = new Date();
    const todayStrLocal = `${now.getFullYear()}-${String(now.getMonth()+1).padStart(2,'0')}-${String(now.getDate()).padStart(2,'0')}`;
    const currentHour=now.getHours();
    const currentMinute=now.getMinutes();
    Array.from(container.querySelectorAll('.slot-btn')).forEach(function(btn){
      const ds=btn.dataset.slot; if(!ds) return;
      const sh=parseInt(ds.split(':')[0],10);
      const maxPossible=computeMaxDuration(amen,sh,booked,selectedDate);
      const isPastOnToday = (selectedDate===todayStrLocal) && (sh<currentHour || (sh===currentHour && currentMinute>0));
      if(btn.disabled || isPastOnToday || maxPossible<Math.max(1,hours)){
        btn.disabled=true;
        btn.classList.add('unavailable');
        btn.dataset.past = isPastOnToday ? '1' : '0';
        if(isPastOnToday){ btn.title=''; }
        else { btn.title=''; }
      } else {
        btn.title='';
      }
    });
  }

  (function observeSlotContainer(){
    const container=document.getElementById('timeSlotContainer');
    if(!container) return;
    const obs=new MutationObserver(function(){ decorateSlotButtons(); });
    obs.observe(container,{childList:true});
    container.addEventListener('pointerdown',function(e){ const b=e.target.closest('.slot-btn'); if(!b) return; if(b.disabled || b.classList.contains('unavailable')){ const isPast=(b.dataset.past==='1'); showTimeError(isPast ? 'This time has already passed and cannot be booked.' : 'This start time cannot fit your selected duration. Try a different start time or duration.'); e.preventDefault(); } });
  })();

</script>

<style>
/* Your Points card: wide horizontal rectangle, centered above the amenities section */
.points-tracker {
  width: 100%;
  max-width: 860px;
  margin: 0 auto;
  background: linear-gradient(135deg, #23412e 0%, #1f5a33 100%);
  color: #fff;
  padding: 10px 16px;
  border-radius: 14px;
  box-shadow: 0 6px 18px rgba(0,0,0,0.12);
  display: grid;
  grid-template-columns: auto 1fr auto auto;
  align-items: center;
  gap: 6px 18px;
  font-family: 'Poppins', sans-serif;
}

.points-tracker-header {
  display: flex;
  flex-direction: row;
  align-items: baseline;
  gap: 6px;
  white-space: nowrap;
  min-width: 0;
}

.points-tracker-label {
  font-size: .75rem;
  font-weight: 600;
  opacity: 0.85;
  letter-spacing: .06em;
  text-transform: uppercase;
}

.points-balance-amount {
  font-weight: 800;
  font-size: 1.3rem;
  line-height: 1.1;
}

.points-tracker-note {
  font-size: 0.75rem;
  line-height: 1.35;
  font-weight: 500;
  opacity: 0.95;
  background: rgba(255,255,255,0.12);
  padding: 4px 8px;
  border-radius: 8px;
  min-width: 0;
}

.points-tracker-body {
  min-width: 0;
}

.points-tracker-toggle {
  width: 26px;
  height: 26px;
  border-radius: 50%;
  border: none;
  background: rgba(255,255,255,0.16);
  color: white;
  font-size: .95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  line-height: 1;
  flex-shrink: 0;
  transition: all 0.2s;
}

.points-tracker-toggle:hover {
  background: rgba(255,255,255,0.28);
}

.points-tracker.is-collapsed .points-tracker-body,
.points-tracker.is-collapsed .points-tracker-note {
  display: none;
}

.view-rewards-btn {
  padding: 9px 16px;
  border-radius: 10px;
  background: rgba(255,255,255,0.15);
  border: 1px solid rgba(255,255,255,0.22);
  color: white;
  font-size: .8rem;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.2s;
  white-space: nowrap;
}

.view-rewards-btn:hover {
  background: rgba(255,255,255,0.22);
}

@media (max-width: 1023px) {
  .points-tracker {
    grid-template-columns: 1fr auto;
    gap: 10px 12px;
    max-width: 720px;
  }
  .points-tracker-header { grid-column: 1; grid-row: 1; }
  .points-tracker-toggle { grid-column: 2; grid-row: 1; }
  .points-tracker-note { grid-column: 1 / -1; grid-row: 2; width: 100%; }
  .points-tracker-body { grid-column: 1 / -1; grid-row: 3; width: 100%; }
  .view-rewards-btn { width: 100%; }
}

/* Points redemption sidebar (center modal) */
.points-redemption-sidebar {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.8);
  width: 90%;
  max-width: 420px;
  max-height: 85vh;
  background: white;
  border-radius: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  z-index: 1001;
  opacity: 0;
  visibility: hidden;
  transition: all 0.3s ease;
  overflow-y: auto;
}
.points-redemption-sidebar.open {
  transform: translate(-50%, -50%) scale(1);
  opacity: 1;
  visibility: visible;
}
.sidebar-header {
  background: #23412e;
  color: white;
  padding: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.sidebar-header h3 {
  margin: 0;
}
.sidebar-close-btn {
  background: none;
  border: none;
  color: white;
  font-size: 24px;
  cursor: pointer;
}
.sidebar-content {
  padding: 20px;
}
.redemption-amenity-card {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 16px;
  margin-bottom: 16px;
  display: flex;
  gap: 16px;
  cursor: pointer;
  transition: all 0.2s;
}
.redemption-amenity-card:hover {
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
.redemption-amenity-card img {
  width: 100px;
  height: 75px;
  object-fit: cover;
  border-radius: 8px;
}
.redemption-amenity-info h4 {
  margin: 0 0 8px 0;
}
.redemption-amenity-info .points-cost {
  color: #23412e;
  font-weight: 700;
}

/* Overlay when sidebar is open */
.sidebar-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  z-index: 1000;
  display: none;
}
.sidebar-overlay.open {
  display: block;
}
/* Points error popup (responsive) */
.points-error-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 10000;
  display: none;
}
.points-error-overlay.open {
  display: block;
}
.points-error-popup {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.95);
  background: #fff;
  border-radius: 14px;
  box-shadow: 0 12px 40px rgba(0,0,0,0.25);
  z-index: 10001;
  max-width: 92vw;
  width: 420px;
  padding: 0;
  opacity: 0;
  visibility: hidden;
  transition: all 0.18s ease;
  overflow: hidden;
}
.points-error-popup.open {
  transform: translate(-50%, -50%) scale(1);
  opacity: 1;
  visibility: visible;
}
.points-error-inner { padding: 18px; }
.points-error-title { margin: 0 0 10px 0; color: #dc2626; font-size: 1.05rem; }
.points-error-message { margin: 0; color: #374151; line-height: 1.45; }
.points-error-close {
  margin-top: 14px;
  width: 100%;
  padding: 10px 14px;
  border-radius: 10px;
  border: none;
  background: linear-gradient(135deg,#23412e,#1f5a33);
  color: #fff;
  font-weight: 700;
  cursor: pointer;
}
@media (max-width: 480px) {
  .points-error-popup { width: 94vw; padding: 0; border-radius: 12px; }
  .points-error-inner { padding: 14px; }
  .points-error-title { font-size: 1rem; }
}
</style>


<script>
// Tracker toggle functionality
const tracker = document.getElementById('pointsTracker');
const trackerToggleBtn = document.getElementById('trackerToggleBtn');
const bookingStepsPanel = document.querySelector('.booking-steps');

function updateBookingStepsOffset() {
  if (!tracker || !bookingStepsPanel) return;
  const trackerRect = tracker.getBoundingClientRect();
  const offset = trackerRect.bottom + 16;
  bookingStepsPanel.style.top = `${Math.max(offset, 150)}px`;
}

if (trackerToggleBtn) {
  trackerToggleBtn.addEventListener('click', () => {
    if (tracker.classList.contains('is-collapsed')) {
      tracker.classList.remove('is-collapsed');
      trackerToggleBtn.textContent = '−';
    } else {
      tracker.classList.add('is-collapsed');
      trackerToggleBtn.textContent = '+';
    }
    updateBookingStepsOffset();
  });
}

window.addEventListener('resize', updateBookingStepsOffset);
window.addEventListener('load', updateBookingStepsOffset);


// View Rewards Modal and Amenity Clicks
document.addEventListener('DOMContentLoaded', function() {
  // Modal elements
  const viewRewardsBtn = document.getElementById('viewRewardsBtn');
  const viewRewardsModal = document.getElementById('viewRewardsModal');
  const closeRewardsModal = document.getElementById('closeRewardsModal');
  const usePointsToggle = document.getElementById('use-points-toggle');

  // Helper function: map amenity name (e.g., "Basketball Court") to key (e.g., "basketball")
  function getAmenityKeyFromName(amenityName) {
    for (const [key, info] of Object.entries(amenityData)) {
      if (info.value === amenityName) return key;
    }
    return 'clubhouse'; // default fallback
  }

  // Open modal
  if (viewRewardsBtn && viewRewardsModal) {
    viewRewardsBtn.addEventListener('click', function() {
      viewRewardsModal.style.display = 'flex';
    });
  }

  // Close modal with X button
  if (closeRewardsModal && viewRewardsModal) {
    closeRewardsModal.addEventListener('click', function() {
      viewRewardsModal.style.display = 'none';
    });
  }

  // Close modal when clicking outside
  if (viewRewardsModal) {
    viewRewardsModal.addEventListener('click', function(e) {
      if (e.target === viewRewardsModal) {
        viewRewardsModal.style.display = 'none';
      }
    });
  }

  // Handle amenity reward card clicks (and "Redeem" button clicks)
  function handleRewardCardClick(card) {
    const amenityName = card.getAttribute('data-amenity');
    const eligible = card.getAttribute('data-eligible') === 'true';
    const key = getAmenityKeyFromName(amenityName);
    const amenityCard = document.querySelector(`.amenity-card[data-key="${key}"]`);

    // Close the modal FIRST
    if (viewRewardsModal) {
      viewRewardsModal.style.display = 'none';
    }

    // Now do EXACTLY what Book Now button does!
    if (amenityCard) {
      const bookNowBtn = amenityCard.querySelector('button[data-action="book-now"]');
      if (bookNowBtn) bookNowBtn.style.display = 'none';
      selectAmenityByKey(key);
      try{
        updateAmenityDescription(key);
        const descBox=document.getElementById('amenityDescBox');
        if(descBox){ descBox.style.display='flex'; }
        const descText=document.getElementById('amenityDescText');
        if(descText){ descText.textContent=''; descText.style.display='none'; }
      }catch(_){}
      const viewBtn=amenityCard.querySelector('button[data-action="view-desc"]');
      if(viewBtn){ viewBtn.style.display='none'; }
      document.querySelectorAll('.amenity-card').forEach(function(c){
        c.style.display='none';
      });
      const amenitiesHeader=document.getElementById('amenitiesHeader');
      if(amenitiesHeader){ amenitiesHeader.style.display='none'; }
      const ret=document.getElementById('amenityReturnBtn');
      if(ret){ ret.style.display='inline-flex'; }
      try{
        const rc=document.getElementById('reservationCard');
        if(rc){
          rc.style.display='flex';
          document.getElementById('reservationTitle').textContent='Reservation';
          document.getElementById('reservationHint').textContent='Select date, time, and persons';
          refreshAvailabilityFromServer();
          rc.scrollIntoView({behavior:'smooth',block:'start'});
        }
      }catch(_){}
    }

    // Auto-toggle "Use Points" if eligible
    if (usePointsToggle && eligible) {
      usePointsToggle.checked = true;
      usePoints = true;
      updateRedemptionInfo();
    } else if (usePointsToggle) {
      usePointsToggle.checked = false;
      usePoints = false;
    }
  }

  // Attach handlers to all amenity-reward-card elements
  const amenityRewardCards = document.querySelectorAll('.amenity-reward-card');
  amenityRewardCards.forEach(card => {
    // When clicking anywhere on the card
    card.addEventListener('click', function(e) {
      // If not clicking the button itself
      if (!e.target.closest('button')) {
        handleRewardCardClick(card);
      }
    });
    // When clicking the "Redeem" button specifically
    const btn = card.querySelector('button');
    if (btn) {
      btn.addEventListener('click', function(e) {
        e.stopPropagation();
        const eligible = card.getAttribute('data-eligible') === 'true';
        if (eligible) { // only allow clicking if eligible!
          handleRewardCardClick(card);
        }
      });
    }
  });

  // Handle old AI recommendation cards (just in case)
  const aiCards = document.querySelectorAll('.ai-recommendation-card');
  aiCards.forEach(card => {
    card.addEventListener('click', function() {
      const amenityName = this.getAttribute('data-amenity');
      const eligible = this.getAttribute('data-eligible') === 'true';
      const key = getAmenityKeyFromName(amenityName);
      const amenityCard = document.querySelector(`.amenity-card[data-key="${key}"]`);

      // Close modal
      if (viewRewardsModal) {
        viewRewardsModal.style.display = 'none';
      }

      if (amenityCard) {
        const bookNowBtn = amenityCard.querySelector('button[data-action="book-now"]');
        if (bookNowBtn) bookNowBtn.style.display = 'none';
        selectAmenityByKey(key);
        try{
          updateAmenityDescription(key);
          const descBox=document.getElementById('amenityDescBox');
          if(descBox){ descBox.style.display='flex'; }
          const descText=document.getElementById('amenityDescText');
          if(descText){ descText.textContent=''; descText.style.display='none'; }
        }catch(_){}
        const viewBtn=amenityCard.querySelector('button[data-action="view-desc"]');
        if(viewBtn){ viewBtn.style.display='none'; }
        document.querySelectorAll('.amenity-card').forEach(function(c){
          c.style.display='none';
        });
        const amenitiesHeader=document.getElementById('amenitiesHeader');
        if(amenitiesHeader){ amenitiesHeader.style.display='none'; }
        const ret=document.getElementById('amenityReturnBtn');
        if(ret){ ret.style.display='inline-flex'; }
        try{
          const rc=document.getElementById('reservationCard');
          if(rc){
            rc.style.display='flex';
            document.getElementById('reservationTitle').textContent='Reservation';
            document.getElementById('reservationHint').textContent='Select date, time, and persons';
            refreshAvailabilityFromServer();
            rc.scrollIntoView({behavior:'smooth',block:'start'});
          }
        }catch(_){}
      }

      if (usePointsToggle && eligible) {
        usePointsToggle.checked = true;
        usePoints = true;
        updateRedemptionInfo();
      } else if (usePointsToggle) {
        usePointsToggle.checked = false;
        usePoints = false;
      }
    });
  });
});
</script>

</body>
</html>