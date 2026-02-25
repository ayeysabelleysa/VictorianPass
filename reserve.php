<?php
ob_start(); // Prevents header issues on redirect
session_start();
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
    $pool_booking_type = isset($_POST['pool_booking_type']) ? trim($_POST['pool_booking_type']) : '';
    $guest_id_post = isset($_POST['guest_id']) ? trim($_POST['guest_id']) : '';
    $guest_ref_code_post = isset($_POST['guest_ref_code']) ? trim($_POST['guest_ref_code']) : '';
    $residentsCount = max(0, intval($_POST['residents_count'] ?? 0));
    $guestsCount = max(0, intval($_POST['guests_count'] ?? 0));
    if ($residentsCount + $guestsCount <= 0) { $guestsCount = max(1, $persons); }
    if ($pool_booking_type !== 'whole_pool') { $pool_booking_type = 'per_person'; }
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
      $rate = 150;
      if ($booking_for === 'resident') { $rate = $rate * 0.5; }
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Clubhouse') {
      $rate = 200;
      if ($booking_for === 'resident') { $rate = $rate * 0.5; }
      $basePrice = max(1, $hours) * $rate;
    } else if ($amenity === 'Pool') {
      $startTime = '09:00';
      $endTime = '18:00';
      if ($pool_booking_type === 'whole_pool') {
        $cap = 20;
        $persons = $cap;
        // Use submitted resident/guest counts, clamp to capacity, and apply per-person discount for residents
        $residentsCount = max(0, min($cap, $residentsCount));
        $guestsCount = max(0, min($cap - $residentsCount, $guestsCount));
        $guestsCount = max(0, $cap - $residentsCount); // enforce total equals capacity
        $basePrice = ($residentsCount * (175 * 0.5)) + ($guestsCount * 175);
      } else {
        $basePrice = ($residentsCount * (175 * 0.5)) + ($guestsCount * 175);
      }
    } else {
      $basePrice = 0;
    }
    $price = round($basePrice, 2);
    $downpayment = round($price * 0.5, 2);
    $allowedAmenities = ['Pool','Clubhouse','Basketball Court','Tennis Court'];
    if (!in_array($amenity, $allowedAmenities, true)) { $errorMsg = 'Please select an amenity.'; }
    $sdObj = $start ? DateTime::createFromFormat('Y-m-d', $start) : false;
    $edObj = $end ? DateTime::createFromFormat('Y-m-d', $end) : false;
    $stObj = $startTime ? DateTime::createFromFormat('H:i', $startTime) : false;
    $etObj = $endTime ? DateTime::createFromFormat('H:i', $endTime) : false;
    if (!$sdObj || !$edObj) {
      $errorMsg = 'Please select a start and end date.';
    } else if ($amenity !== 'Pool' && (!$stObj || !$etObj)) {
      $errorMsg = 'Please select a start and end time.';
    } else if ($sdObj && $edObj && $sdObj > $edObj) {
      $errorMsg = 'Start date must be before end date.';
    } else if ($amenity !== 'Pool' && $start === $end && $stObj && $etObj && $stObj >= $etObj) {
      $errorMsg = 'Start time must be before end time.';
    } else if (($sdObj && $edObj)) {
      $minDate = new DateTime('today');
      $minDate->modify('+1 day');
      if ($sdObj < $minDate || $edObj < $minDate) {
        $errorMsg = 'Reservations must be made at least 1 day in advance.';
      }
    } else {
      $maxPersons = 0;
      if ($amenity === 'Pool') { $maxPersons = 20; }
      else if ($amenity === 'Clubhouse') { $maxPersons = 200; }
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
    if (!$errorMsg && $amenity !== 'Pool' && $stObj && $etObj) {
      $minH = ($amenity === 'Clubhouse') ? 9 : 9;
      $maxH = ($amenity === 'Clubhouse') ? 21 : 18;
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
          if ($amenity === 'Pool') {
            $cap = 20;
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
              if ($pool_booking_type === 'whole_pool') {
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
                $remaining = $cap - $total;
                if ($remaining < $cap) { $errorMsg = 'Whole pool booking requires all 20 slots to be available. Only '.$remaining.' slots are left for the selected date.'; break; }
              } else {
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
            $hourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse'], true);
            $minH = ($amenity === 'Clubhouse') ? 9 : 9;
            $maxH = ($amenity === 'Clubhouse') ? 21 : 18;
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
          $errorMsg = ($amenity === 'Pool') ? 'Community Pool is fully booked for the selected dates.' : 'Selected dates include a fully booked day. Please adjust your range or choose different dates.';
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
            // Store reservation info in session for confirmation/debugging if needed
            $_SESSION['pending_reservation'] = [
              'amenity' => $amenity,
              'start_date' => $start,
              'end_date' => $end,
              'start_time' => $startTime,
              'end_time' => $endTime,
              'persons' => $persons,
              'price' => $price,
              'downpayment' => $downpayment,
              'user_id' => $user_id,
              'entry_pass_id' => $entry_pass_id,
              'booking_for' => $booking_for,
              'pool_booking_type' => $pool_booking_type,
              'guest_id' => $guest_id_post,
              'guest_ref_code' => $guest_ref_code_post,
              'ref_code' => $newRef
            ];
            $generatedCode = $newRef;
            $canSubmit = true;
            // Prevent double submission
            if (isset($_SESSION['reservation_submitted']) && $_SESSION['reservation_submitted'] === $newRef) {
              $errorMsg = 'This reservation has already been submitted.';
            } else {
              $_SESSION['reservation_submitted'] = $newRef;
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
    // Helper function to check overlap
    $checkOverlap = function($row, $sDate, $eDate) {
        if (!$row['start_date'] || !$row['end_date']) return false;
        return max($sDate, $row['start_date']) <= min($eDate, $row['end_date']);
    };
    try {
      if (!($con instanceof mysqli)) { throw new Exception('DB unavailable'); }
      if ($amenity === 'Pool') {
        $capacity = 20;
        // Define pool slots (matches client-side)
        $poolSlots = [
          ['start' => '08:00:00', 'end' => '10:00:00', 'key' => '08:00'],
          ['start' => '10:30:00', 'end' => '12:30:00', 'key' => '10:30'],
          ['start' => '12:30:00', 'end' => '13:30:00', 'key' => '12:30'], // cleaning (kept for completeness)
          ['start' => '13:30:00', 'end' => '15:30:00', 'key' => '13:30'],
          ['start' => '16:00:00', 'end' => '18:00:00', 'key' => '16:00'],
        ];
        foreach ($poolSlots as $ps) { $slotBookings[$ps['key']] = 0; }
        $hasRRRef = false;
        $hasGFRef = false;
        $chkRRRef = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'ref_code'");
        if ($chkRRRef && $chkRRRef->num_rows > 0) { $hasRRRef = true; }
        $chkGFRef = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'ref_code'");
        if ($chkGFRef && $chkGFRef->num_rows > 0) { $hasGFRef = true; }
        $getPoolTotal = function($ds) use ($con, $amenity, $hasRRRef, $hasGFRef) {
          $total = 0;
          $s1 = $con->prepare("SELECT COALESCE(SUM(persons),0) AS total FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date");
          $s1->bind_param('ss', $amenity, $ds);
          $s1->execute();
          $r1 = $s1->get_result();
          $total += ($r1 && ($rw=$r1->fetch_assoc())) ? intval($rw['total']) : 0;
          $s1->close();
          $hasRPersons = false;
          $hasGPersons = false;
          $chkR = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'persons'");
          if ($chkR && $chkR->num_rows > 0) { $hasRPersons = true; }
          $chkG = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'persons'");
          if ($chkG && $chkG->num_rows > 0) { $hasGPersons = true; }
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
          } else {
            if ($hasRRRef) {
              $c2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
              $c2->bind_param('ssss', $amenity, $ds, $amenity, $ds);
            } else {
              $c2 = $con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date");
              $c2->bind_param('ss', $amenity, $ds);
            }
            $c2->execute();
            $r2 = $c2->get_result();
            $count = ($r2 && ($rw=$r2->fetch_assoc())) ? intval($rw['c']) : 0;
            $total += $count;
            $c2->close();
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
          } else {
            if ($hasGFRef) {
              $c3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
              $c3->bind_param('ssss', $amenity, $ds, $amenity, $ds);
            } else {
              $c3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date");
              $c3->bind_param('ss', $amenity, $ds);
            }
            $c3->execute();
            $r3 = $c3->get_result();
            $countG = ($r3 && ($rw=$r3->fetch_assoc())) ? intval($rw['c']) : 0;
            $total += $countG;
            $c3->close();
          }
          return $total;
        };
        $startObj = $startDate ? DateTime::createFromFormat('Y-m-d', $startDate) : false;
        $endObj = $endDate ? DateTime::createFromFormat('Y-m-d', $endDate) : false;
        if ($startObj && $endObj && $startObj <= $endObj) {
          $period = new DatePeriod($startObj, new DateInterval('P1D'), (clone $endObj)->modify('+1 day'));
          foreach ($period as $d) {
            $ds = $d->format('Y-m-d');
            $personsByDate[$ds] = $getPoolTotal($ds);
          }
        }
        $targetDate = $date ?: ($startDate ?: '');
        if ($targetDate) {
          $personsTotal = array_key_exists($targetDate, $personsByDate) ? $personsByDate[$targetDate] : $getPoolTotal($targetDate);
          // Compute slot-specific bookings for the target date (only rows with time columns contribute)
          // reservations table
          $stmtR = $con->prepare("SELECT persons, start_time, end_time FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND start_time IS NOT NULL AND end_time IS NOT NULL");
          if ($stmtR) {
            $stmtR->bind_param('ss', $amenity, $targetDate);
            $stmtR->execute();
            $resR = $stmtR->get_result();
            while ($row = $resR->fetch_assoc()) {
              $st = $row['start_time'] ?: '00:00:00';
              $et = $row['end_time'] ?: '23:59:59';
              $p = intval($row['persons'] ?? 0);
              foreach ($poolSlots as $ps) {
                if ($st < $ps['end'] && $et > $ps['start']) {
                  $slotBookings[$ps['key']] += $p;
                }
              }
            }
            $stmtR->close();
          }
          // resident_reservations table (if it has time columns)
          $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
          $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
          if ($hasRt && $hasRt->num_rows > 0 && $hasRe && $hasRe->num_rows > 0) {
            if ($hasRRRef) {
              $stmtRR = $con->prepare("SELECT persons, start_time, end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND start_time IS NOT NULL AND end_time IS NOT NULL AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
            } else {
              $stmtRR = $con->prepare("SELECT persons, start_time, end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND start_time IS NOT NULL AND end_time IS NOT NULL");
            }
            if ($stmtRR) {
              if ($hasRRRef) {
                $stmtRR->bind_param('ssss', $amenity, $targetDate, $amenity, $targetDate);
              } else {
                $stmtRR->bind_param('ss', $amenity, $targetDate);
              }
              $stmtRR->execute();
              $resRR = $stmtRR->get_result();
              while ($row = $resRR->fetch_assoc()) {
                $st = $row['start_time'] ?: '00:00:00';
                $et = $row['end_time'] ?: '23:59:59';
                $p = intval($row['persons'] ?? 0);
                foreach ($poolSlots as $ps) {
                  if ($st < $ps['end'] && $et > $ps['start']) {
                    $slotBookings[$ps['key']] += $p;
                  }
                }
              }
              $stmtRR->close();
            }
          }
          // guest_forms table (if it has time columns)
          $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
          $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
          if ($hasGt && $hasGt->num_rows > 0 && $hasGe && $hasGe->num_rows > 0) {
            if ($hasGFRef) {
              $stmtGF = $con->prepare("SELECT persons, start_time, end_time FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND start_time IS NOT NULL AND end_time IS NOT NULL AND ref_code NOT IN (SELECT ref_code FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND ? BETWEEN start_date AND end_date AND ref_code IS NOT NULL AND ref_code <> '')");
            } else {
              $stmtGF = $con->prepare("SELECT persons, start_time, end_time FROM guest_forms WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND start_time IS NOT NULL AND end_time IS NOT NULL");
            }
            if ($stmtGF) {
              if ($hasGFRef) {
                $stmtGF->bind_param('ssss', $amenity, $targetDate, $amenity, $targetDate);
              } else {
                $stmtGF->bind_param('ss', $amenity, $targetDate);
              }
              $stmtGF->execute();
              $resGF = $stmtGF->get_result();
              while ($row = $resGF->fetch_assoc()) {
                $st = $row['start_time'] ?: '00:00:00';
                $et = $row['end_time'] ?: '23:59:59';
                $p = intval($row['persons'] ?? 0);
                foreach ($poolSlots as $ps) {
                  if ($st < $ps['end'] && $et > $ps['start']) {
                    $slotBookings[$ps['key']] += $p;
                  }
                }
              }
              $stmtGF->close();
            }
          }
        }
      } else {
        $stmt1 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history'))");
        $stmt1->bind_param("s", $amenity);
        $stmt1->execute();
        $res1 = $stmt1->get_result();
        while ($row = $res1->fetch_assoc()) {
          if ($checkOverlap($row, $startDate, $endDate)) {
            $st = $row['start_time'] ?: '00:00:00';
            $et = $row['end_time'] ?: '23:59:59';
            $has = (!empty($row['start_time']) && !empty($row['end_time']));
            $times[] = [
              'start' => $st, 
              'end' => $et, 
              'has_time' => $has,
              'start_date' => $row['start_date'],
              'end_date' => $row['end_date']
            ];
          }
        }
        $stmt1->close();
        $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
        $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
        if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
          $stmt2 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
        } else {
          $stmt2 = $con->prepare("SELECT start_date, end_date, NULL AS start_time, NULL AS end_time FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved')");
        }
        $stmt2->bind_param("s", $amenity);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        while ($row = $res2->fetch_assoc()) {
          if ($checkOverlap($row, $startDate, $endDate)) {
            $st = $row['start_time'] ?: '00:00:00';
            $et = $row['end_time'] ?: '23:59:59';
            $has = (!empty($row['start_time']) && !empty($row['end_time']));
            $times[] = [
              'start' => $st, 
              'end' => $et, 
              'has_time' => $has,
              'start_date' => $row['start_date'],
              'end_date' => $row['end_date']
            ];
          }
        }
        $stmt2->close();
        $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
        $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
        if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
          $stmt3 = $con->prepare("SELECT start_date, end_date, start_time, end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved'))");
        } else {
          $stmt3 = $con->prepare("SELECT start_date, end_date, NULL AS start_time, NULL AS end_time FROM guest_forms WHERE amenity = ? AND (approval_status IN ('pending','approved'))");
        }
        $stmt3->bind_param("s", $amenity);
        $stmt3->execute();
        $res3 = $stmt3->get_result();
        while ($row = $res3->fetch_assoc()) {
          if ($checkOverlap($row, $startDate, $endDate)) {
            $st = $row['start_time'] ?: '00:00:00';
            $et = $row['end_time'] ?: '23:59:59';
            $has = (!empty($row['start_time']) && !empty($row['end_time']));
            $times[] = [
              'start' => $st, 
              'end' => $et, 
              'has_time' => $has,
              'start_date' => $row['start_date'],
              'end_date' => $row['end_date']
            ];
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
$householdResidents = [];
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident' && isset($_SESSION['user_id']) && ($con instanceof mysqli)) {
  $rid = intval($_SESSION['user_id']);
  $stmtU = $con->prepare("SELECT id, first_name, middle_name, last_name, house_number FROM users WHERE id = ? LIMIT 1");
  if ($stmtU) {
    $stmtU->bind_param('i', $rid);
    $stmtU->execute();
    $resU = $stmtU->get_result();
    if ($resU && $resU->num_rows) { $currentResident = $resU->fetch_assoc(); }
    $stmtU->close();
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
  <link rel="stylesheet" href="CSS/reserve.css?v=5">
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
</header>

<section class="hero">
  <div class="layout">
    <div class="left-panel">
      <div class="top-actions">
        <button type="button" id="accountBackBtn" class="btn-secondary back-account-btn" aria-label="Back" onclick="window.location.href='<?php echo htmlspecialchars($accountLink, ENT_QUOTES); ?>'"><i class="fa-solid fa-arrow-left"></i></button>
      </div>
      
      <?php $isResident = (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident'); ?>

      <div class="section-header" id="amenitiesHeader"><h2>Amenities</h2><p>Select an amenity</p></div>
      <div class="amenities-wrapper">
        <div class="amenities-right">
          <div class="amenities-list" id="amenitiesList">
            <div class="amenity-card" data-amenity="Pool" data-key="pool" data-price="175">
              <div class="amenity-media">
                <img src="images/communitypool.png" alt="Community Pool">
              </div>
              <div class="info">
                <div class="title-block">
                  <div class="name">Community Pool</div>
                  <div class="amenity-short">Relax and enjoy the pool with easy and convenient reservation options.</div>
                </div>
                <div class="meta">
                  <button type="button" class="btn-link" data-action="view-desc">View Details</button>
                </div>
              </div>
              <button type="button" class="btn-main small" data-action="book-now">Book Now</button>
              <div class="schedule-panel" data-schedule-panel></div>
            </div>
            <div class="amenity-card" data-amenity="Clubhouse" data-key="clubhouse" data-price="200">
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
            <div class="booking-steps" aria-label="Booking steps">
              <div class="booking-steps-header">
                <div class="booking-steps-label">Reservation steps</div>
                <button type="button" class="booking-steps-toggle" id="bookingStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
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
                    <div class="step-subtitle">Check your reservation details and partial downpayment</div>
                  </div>
                </div>
              </div>
            </div>
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
          <input type="hidden" id="poolAvailabilityOk" value="1">
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
                  <div class="date-extra">
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
                    <div id="selectedTimeNote" class="selected-time-note" style="display:none;">Note: This is the available time for the Community Pool. Please leave by closing time.</div>
                    <div id="availabilityNotice" class="avail-notice" style="display:none;"></div>
                  </div>
                    <div class="note">Reservations must be made at least 1 day in advance. Same-day bookings are not allowed.</div>
                    <div class="res-item persons">
                      <div class="res-item" id="poolBookingTypeRow" style="display:none;">
                        <div class="res-label"><small>Pool Booking Type</small></div>
                        <select id="poolBookingType" name="pool_booking_type" class="hours-select">
                          <option value="per_person">Per person (shared)</option>
                          <option value="whole_pool">Whole pool (exclusive)</option>
                        </select>
                      </div>
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
                            <div style="color:#555;font-size:0.85rem;">All selected residents receive a 50% discount.</div>
                            <button type="button" class="btn-secondary" data-mode="resident_guest">Residents + Guests</button>
                            <div style="color:#555;font-size:0.85rem;">Residents receive a 50% discount. Guests pay full price.</div>
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
                              <small class="label-help">50% discount per resident</small>
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
                        <div class="price-label">Total Price</div>
                        <div id="price" class="price-amount">₱0.00</div>
                        <div id="priceBreakdown" style="display:none;margin-top:6px;font-size:0.9rem;color:#444;"></div>
                      </div>
                    </div>
                    <div class="res-item price-row">
                      <div class="price-box">
                        <div class="price-label">Downpayment (50% Online)</div>
                        <div id="dpAmountText" class="price-amount">₱0</div>
                      </div>
                      <input type="hidden" name="downpayment" id="downpaymentInput" value="">
                      <small class="dp-info" style="display:block;margin-top:8px;padding:10px 12px;border-radius:10px;background:#f0faf2;border:1.5px solid #cfe6d4;color:#23412e;font-weight:600;">This is a partial payment of 50% of the total price. The remaining balance can be paid onsite at the administration office.</small>
                      <small class="nonrefundable">Downpayment is non-refundable.</small>
                    </div>
                    <div id="submitWrap" class="res-item" style="margin-top:12px; display:none; gap:8px; align-items:center; flex-wrap:wrap;">
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
    <div style="position:relative; background:#fff; border-radius:12px; padding:12px; max-width:90vw; max-height:90vh;">
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
  let selectedStart=null,selectedEnd=null;
  let endDateRangeError=false;
  let bookedDates=new Set();
  let availabilityCache=new Map();
  let availabilityToken=0;
  let selectedAmenity=document.getElementById('amenityField').value||'';
  let hintShown=false;
  const approvedGuestsMax = <?php echo (isset($residentGuests) && is_array($residentGuests)) ? count($residentGuests) : 0; ?>;

  function formatDateToMMDDYYYY(dateStr) {
    if (!dateStr) return '';
    const parts = dateStr.split('-');
    if (parts.length !== 3) return dateStr;
    return `${parts[1]}/${parts[2]}/${String(parts[0]).slice(-2)}`;
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
    const isPool = (document.getElementById('amenityField')?.value || '') === 'Pool';
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
          if(isPool){
            const dow = new Date(year,month,date).getDay();
            if(dow === 0 || dow === 6){
              cell.classList.add('disabled','weekend-closed');
              cell.title = 'Community Pool is open Monday – Friday only.';
            }
          }
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
        const byDate = amen==='Pool' ? null : buildDayIndex(data.times||[], startDs, endDs);
        cached = { data, byDate };
        availabilityCache.set(key, cached);
      }
      if(token !== availabilityToken) return;
      const data = cached.data || {};
      if(amen==='Pool'){
        const personsByDate = data.persons_by_date || {};
        const cap = parseInt(data.capacity||getAmenityMaxPersons(amen),10);
        for(const cell of cells){
          const ds=cell.getAttribute('data-date');
          if(!ds) continue;
          if(cell.classList.contains('weekend-closed')){
            cell.classList.add('disabled');
            cell.title = 'Community Pool is open Monday – Friday only.';
            continue;
          }
          if(ds < minDateStr){
            cell.classList.add('disabled');
            cell.title = ds < todayStr ? 'Past date — cannot be booked.' : 'Reservations must be made at least 1 day in advance.';
            continue;
          }
          cell.classList.remove('disabled','partly','available','fully-booked');
          const total = parseInt(personsByDate[ds]||'0',10);
          if(total>=cap){ cell.classList.add('disabled'); cell.classList.add('fully-booked'); cell.title='Fully Booked'; }
          else if(total>0){ cell.classList.add('partly'); cell.title='Partially Booked'; }
          else { cell.classList.add('available'); cell.title='Available'; }
        }
        return;
      }
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
      if(cell.classList.contains('weekend-closed')){
        showStartDateError('Community Pool is open Monday – Friday only.');
        return;
      }
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
        await updatePoolRemainingNote();
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
        (function(){
          const cells=Array.from(document.querySelectorAll('.calendar td[data-date]'));
          cells.forEach(td=>{ td.classList.remove('active-start'); td.classList.remove('active-end'); });
          if(selectedStart){
            const s=cells.find(td=>td.getAttribute('data-date')===selectedStart);
            if(s){ s.classList.add('active-start'); s.classList.remove('active'); }
          }
          if(selectedEnd){
            const e=cells.find(td=>td.getAttribute('data-date')===selectedEnd);
            if(e){ e.classList.add('active-end'); e.classList.remove('active'); }
          }
        })();
        await updatePoolRemainingNote();
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
        await updatePoolRemainingNote();
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
    await evaluateCalendarAvailability();
    (function(){
      const cells=Array.from(document.querySelectorAll('.calendar td[data-date]'));
      cells.forEach(td=>{ td.classList.remove('active-start'); td.classList.remove('active-end'); });
      if(selectedStart){
        const s=cells.find(td=>td.getAttribute('data-date')===selectedStart);
        if(s){ s.classList.add('active-start'); s.classList.remove('active'); s.classList.remove('available'); }
      }
      if(selectedEnd){
        const e=cells.find(td=>td.getAttribute('data-date')===selectedEnd);
        if(e){ e.classList.add('active-end'); e.classList.remove('active'); e.classList.remove('available'); }
      }
    })();
    await updatePoolRemainingNote();
    computeAvailability();
    renderTimeSlotButtons();
    markDirty('startDateInput');
    showIncompleteWarnings(false);
    updateActionStates();
    updateSelectedTimeRange();
    updateBookingSummary();
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
      (function(){
        const cells=Array.from(document.querySelectorAll('.calendar td[data-date]'));
        cells.forEach(td=>{ td.classList.remove('active-start'); td.classList.remove('active-end'); td.classList.remove('active'); });
        if(selectedStart){
          const st=cells.find(td=>td.getAttribute('data-date')===selectedStart);
          if(st){ st.classList.add('active-start'); }
        }
        if(selectedEnd){
          const en=cells.find(td=>td.getAttribute('data-date')===selectedEnd);
          if(en){ en.classList.add('active-end'); }
        }
      })();
      computeAvailability();
      renderTimeSlotButtons();
      updateActionStates();
      showIncompleteWarnings(false);
      updateSelectedTimeRange();
      updateBookingSummary();
    });
  }

  const amenityData={
    pool:{
      title:'Community Pool',
      value:'Pool',
      img:'images/communitypool.png',
      desc:'Relax and cool off in the Community Pool, ideal for families and small groups. Lifeguard-supervised sessions with limited capacity keep the area safe and comfortable for everyone.',
      days:'Open Monday – Friday (Weekdays only)',
      priceLabel:'₱175 per person per session',
      capacity:20
    },
    clubhouse:{
      title:'Clubhouse',
      value:'Clubhouse',
      img:'images/clubhouse.png',
      desc:'A flexible indoor venue for birthdays, meetings, and celebrations. Air‑conditioned function hall with tables, chairs, and sound‑ready space so you can focus on your event while we provide the venue.',
      days:'Available Monday – Sunday',
      priceLabel:'₱200 per hour',
      capacity:200
    },
    basketball:{
      title:'Basketball Court',
      value:'Basketball Court',
      img:'images/basketballcourt.png',
      desc:'Full outdoor court for pick‑up games, team practice, and training sessions. Ideal for leagues or friendly matches, with clear markings and lighting for late‑afternoon play.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 per hour',
      capacity:30
    },
    tennis:{
      title:'Tennis Court',
      value:'Tennis Court',
      img:'images/tenniscourt.png',
      desc:'Reserve a dedicated court time for casual rallies or competitive singles and doubles. Well‑maintained surface suitable for all skill levels, from beginners to regular players.',
      days:'Available Monday – Sunday',
      priceLabel:'₱150 per hour',
      capacity:60
    }
  };

  function updateAmenityDescription(key){
    const info=amenityData[key]||amenityData.pool;
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
    const pImg=document.getElementById('amenityPreviewImg');
    if(pImg){ pImg.src=info.img; pImg.alt=info.title; }
    const pWrap=document.getElementById('amenityPreview');
    if(pWrap){ pWrap.style.display='flex'; }
  }

  function showInlineAmenityDetails(key){
    const info=amenityData[key]||amenityData.pool;
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
    if(Number.isFinite(info.capacity)){ inner+=`<p class="inline-meta"><strong>Capacity:</strong> ${info.capacity} guests</p>`; }
    body.innerHTML=inner;
    panel.appendChild(body);
    panel.style.display='block';
  }

  function openAmenityImageModal(key){
    try{
      const info=amenityData[key]||amenityData.pool;
      const modal=document.getElementById('amenityImageModal');
      const img=document.getElementById('amenityImageModalImg');
      if(!modal||!img) return;
      img.src=info.img;
      img.alt=info.title;
      modal.style.display='flex';
    }catch(_){ }
  }
  (function initAmenityImageModal(){
    const modal=document.getElementById('amenityImageModal');
    const close=document.getElementById('amenityImageClose');
    if(close){ close.onclick=function(){ if(modal) modal.style.display='none'; }; }
    if(modal){ modal.addEventListener('click',function(e){ if(e.target===modal){ modal.style.display='none'; } }); }
  })();

  function selectAmenityByKey(key){
    const info=amenityData[key]||amenityData.pool;
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
    try{ document.getElementById('reservationCard').style.display='none'; document.getElementById('reservationTitle').textContent='Reserve an Amenity'; document.getElementById('reservationHint').textContent='Select an amenity to continue'; }catch(_){}
  }

  function resetReservationForm(){
    try{
      selectedStart=null; selectedEnd=null;
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
    if(amen==='Pool' && getPoolBookingTypeValue()==='whole_pool'){
      const cap=getAmenityMaxPersons('Pool');
      if(pcEl){ if('value' in pcEl){ pcEl.value=String(cap); } else { pcEl.textContent=String(cap); } }
      document.getElementById('personsInput').value=cap;
      if(currentUserType !== 'resident'){
        const rInput=document.getElementById('residentsCountInput'); if(rInput){ rInput.value='0'; }
        const rText=document.getElementById('residentsCountText'); if(rText){ rText.textContent='0'; }
        const gInput=document.getElementById('guestsCountInput'); if(gInput){ gInput.value=String(cap); }
        const gText=document.getElementById('guestsCountText'); if(gText){ gText.textContent=String(cap); }
      }
      setFieldWarning('personsInput','Whole pool booking fixes participants at 20.');
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      updateActionStates();
      if(amen==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
      return;
    }
    let max=getAmenityMaxPersons(amen);
    let remaining=max;
    if(amen==='Pool'){
      const poolInfo=await getPoolRemainingSlots();
      remaining=poolInfo.remaining;
      max=remaining;
    }
    const minAllowed=(amen==='Pool' && remaining===0) ? 0 : 1;
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
    if(amen==='Pool'){
      if(note){ note.textContent = Number.isFinite(remaining)?`Available: ${remaining} slots`:''; }
      if(desiredCount>remaining){ setFieldWarning('personsInput',`Only ${remaining} slots are available. Please select fewer persons.`); }
      else { setFieldWarning('personsInput',''); }
    } else {
      if(note){ note.textContent = max?(`Maximum: ${max} persons`):''; }
      if(count>=max){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); } else { setFieldWarning('personsInput',''); }
    }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(amen==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
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
    if(document.getElementById('amenityField').value==='Pool' && getPoolBookingTypeValue()==='whole_pool'){
      const cap=getAmenityMaxPersons('Pool');
      let r=parseInt(rEl.textContent||'0',10);
      const nextR=Math.max(0, Math.min(cap, r+delta));
      const nextG=Math.max(0, cap-nextR);
      rEl.textContent=String(nextR);
      rInput.value=String(nextR);
      gInput.value=String(nextG);
      const gText=document.getElementById('guestsCountText'); if(gText) gText.textContent=String(nextG);
      pInput.value=String(cap);
      if(pText) pText.textContent=String(cap);
      setFieldWarning('personsInput','');
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      updateActionStates();
      if(typeof persistForm === 'function') persistForm();
      if(document.getElementById('amenityField').value==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
      return;
    }
    let r=parseInt(rEl.textContent||'0',10);
    const g=parseInt(gInput.value||'0',10);
    const nextR=Math.max(0, r+delta);
    const amen=document.getElementById('amenityField').value;
    let max=getAmenityMaxPersons(amen);
    let remaining=max;
    if(amen==='Pool'){
      const poolInfo=await getPoolRemainingSlots();
      remaining=poolInfo.remaining;
      max=remaining;
      const note=document.getElementById('personsMaxNote');
      if(note){ note.textContent = Number.isFinite(remaining)?`Available: ${remaining} slots`:''; }
    }
    const total=nextR+g;
    if(total < 1){
      setFieldWarning('personsInput','At least 1 participant is required.');
      return;
    }
    if(max!==Infinity && total>max){
      if(amen==='Pool'){ setFieldWarning('personsInput',`Only ${remaining} slots are available. Please select fewer persons.`); }
      else { setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
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
    if(document.getElementById('amenityField').value==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
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
    if(document.getElementById('amenityField').value==='Pool' && getPoolBookingTypeValue()==='whole_pool'){
      const cap=getAmenityMaxPersons('Pool');
      let g=parseInt(gEl.textContent||'0',10);
      let nextG=Math.max(0, Math.min(cap, g+delta));
      if(approvedGuestsMax>=0){ nextG=Math.min(nextG, approvedGuestsMax); }
      const nextR=Math.max(0, cap-nextG);
      gEl.textContent=String(nextG);
      gInput.value=String(nextG);
      rInput.value=String(nextR);
      const rText=document.getElementById('residentsCountText'); if(rText) rText.textContent=String(nextR);
      pInput.value=String(cap);
      if(pText) pText.textContent=String(cap);
      setFieldWarning('personsInput','');
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      updateActionStates();
      if(typeof persistForm === 'function') persistForm();
      if(document.getElementById('amenityField').value==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
      return;
    }
    let g=parseInt(gEl.textContent||'0',10);
    const r=parseInt(rInput.value||'0',10);
    const nextG=Math.max(0, g+delta);
    const amen=document.getElementById('amenityField').value;
    let max=getAmenityMaxPersons(amen);
    let remaining=max;
    if(amen==='Pool'){
      const poolInfo=await getPoolRemainingSlots();
      remaining=poolInfo.remaining;
      max=remaining;
      const note=document.getElementById('personsMaxNote');
      if(note){ note.textContent = Number.isFinite(remaining)?`Available: ${remaining} slots`:''; }
    }
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
      if(amen==='Pool'){ setFieldWarning('personsInput',`Only ${remaining} slots are available. Please select fewer persons.`); }
      else { setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
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
    if(document.getElementById('amenityField').value==='Pool'){ computeAvailability(); evaluateCalendarAvailability(); }
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
    if(st){ computeEndTimeFromHours(); const sh=parseInt(st.split(':')[0],10); const eh=sh+parseInt(hoursInput.value||'0',10); const tr=document.getElementById('selectedTimeRange'); if(tr && hoursInput.value){ tr.textContent=`Selected Time 🕒: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } const tn=document.getElementById('selectedTimeNote'); if(tn && hoursInput.value){ tn.style.display = amen==='Pool' ? 'block' : 'none'; } }
    const dc=document.getElementById('durationContainer'); if(dc){ Array.from(dc.children).forEach(b=>b.classList.remove('selected')); const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(hoursInput.value)); if(sel){ sel.classList.add('selected'); } }
    updateActionStates();
    const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='1'; }
    updateBookingSummary();
  }

  async function fetchBookedTimesFor(date){ if(!document.getElementById('amenityField').value) return {times:[], persons_total:0, capacity:0}; try{ const res=await fetch(`reserve.php?action=booked_times&amenity=${encodeURIComponent(selectedAmenity)}&date=${encodeURIComponent(date)}`); const data=await res.json(); return data||{times:[], persons_total:0, capacity:0}; }catch(_){ return {times:[], persons_total:0, capacity:0}; } }
  async function getPoolRemainingSlots(){
    const max=getAmenityMaxPersons('Pool');
    const ds=document.getElementById('startDateInput')?.value||'';
    if(!ds){ return {remaining:max, capacity:max}; }
    const data=await fetchBookedTimesFor(ds);
    const capacity=parseInt(data.capacity||max,10);
    const total=parseInt(data.persons_total||'0',10);
    const remaining=Math.max(0, capacity-total);
    window.__poolRemainingSlots = remaining;
    return {remaining, capacity};
  }
  async function updatePoolRemainingNote(){
    const amen=document.getElementById('amenityField').value;
    if(amen!=='Pool') return;
    const note=document.getElementById('personsMaxNote');
    if(!note) return;
    const personsInput=document.getElementById('personsInput');
    const countEl=document.getElementById('personCount');
    try{
      const poolInfo=await getPoolRemainingSlots();
      const remaining=poolInfo.remaining;
      const capacity=parseInt(poolInfo.capacity||getAmenityMaxPersons('Pool'),10);
      note.textContent = Number.isFinite(remaining)?`Available: ${remaining} slots`:'';
      const wholePoolLow = (getPoolBookingTypeValue()==='whole_pool' && Number.isFinite(remaining) && remaining < capacity);
      window.__wholePoolLowSlots = wholePoolLow;
      window.__poolRemainingSlots = remaining;
      if(wholePoolLow){
        setFieldWarning('personsInput',`Whole pool booking requires ${capacity} available slots. Only ${remaining} left.`);
        updateActionStates();
        return;
      }
      const current=parseInt((personsInput?.value||countEl?.value||countEl?.textContent||'0'),10);
      if(Number.isFinite(remaining) && current>remaining){
        setFieldWarning('personsInput',`Only ${remaining} slots are available. Please select fewer persons.`);
      } else {
        setFieldWarning('personsInput','');
      }
      updateActionStates();
    }catch(_){
      note.textContent='';
      window.__wholePoolLowSlots = false;
      window.__poolRemainingSlots = null;
    }
  }

  function isHourBasedAmenity(amen){ return amen==='Basketball Court' || amen==='Tennis Court' || amen==='Clubhouse'; }
  function isPersonBasedAmenity(amen){ return amen==='Pool'; }
  function isResidentSelfBooking(){
    const field=document.getElementById('bookingForField');
    return field && field.value === 'resident';
  }
  function getHourlyRate(amen){
    if(amen==='Basketball Court' || amen==='Tennis Court'){ return 150; }
    if(amen==='Clubhouse'){ return 200; }
    return 0;
  }
  function getPerPersonRate(amen){
    if(amen==='Pool'){ return 175; }
    return 0;
  }
  function getPoolBookingTypeValue(){
    const el=document.getElementById('poolBookingType');
    const raw=el ? String(el.value||'').trim() : '';
    return raw==='whole_pool' ? 'whole_pool' : 'per_person';
  }
  function computeDynamicPrice(amen, residentsCount, guestsCount, hours){
    const r = Math.max(0, parseInt(residentsCount||'0',10));
    const g = Math.max(0, parseInt(guestsCount||'0',10));
    const h = Math.max(1, parseInt(hours||'1',10));
    if(amen==='Pool'){
      const poolType=getPoolBookingTypeValue();
      if(poolType==='whole_pool'){
        const cap=getAmenityMaxPersons(amen);
        const per=getPerPersonRate(amen);
        const rClamped=Math.max(0, Math.min(r, cap));
        const gClamped=Math.max(0, Math.min(g, cap - rClamped));
        const resPart = rClamped * (per * 0.5);
        const guestPart = gClamped * per;
        return resPart + guestPart;
      }
      const per = getPerPersonRate(amen);
      const resPart = r * (per * 0.5);
      const guestPart = g * per;
      return resPart + guestPart;
    }
    if(isHourBasedAmenity(amen)){
      const rate = getHourlyRate(amen);
      const perHour = isResidentSelfBooking() ? (rate * 0.5) : rate;
      return h * perHour;
    }
    return 0;
  }
  function formatPesoAmount(val){
    const rounded=Math.round(val*100)/100;
    return Number.isInteger(rounded) ? rounded.toFixed(0) : rounded.toFixed(2);
  }
  function getAmenityPriceLabel(amen){
    if(!amen) return '';
    if(amen==='Pool'){
      const rate=isResidentSelfBooking()?87.5:175;
      return `₱${formatPesoAmount(rate)} per person per session`;
    }
    if(amen==='Clubhouse'){
      const rate=isResidentSelfBooking()?100:200;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    if(amen==='Basketball Court' || amen==='Tennis Court'){
      const rate=isResidentSelfBooking()?75:150;
      return `₱${formatPesoAmount(rate)} per hour`;
    }
    return '';
  }
  function refreshPricingForBookingFor(){
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    const amen=document.getElementById('amenityField')?.value||'';
    const key = amen==='Pool' ? 'pool' : amen==='Clubhouse' ? 'clubhouse' : amen==='Basketball Court' ? 'basketball' : amen==='Tennis Court' ? 'tennis' : '';
    if(key){
      updateAmenityDescription(key);
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`);
      if(card && card.getAttribute('data-details-visible')==='true'){
        showInlineAmenityDetails(key);
      }
    }
  }
  function updateDisplayedPrice(){
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const base=computeDynamicPrice(amen, residents, guests, hours);
    const dpPercent=0.5;
    const downpayment=(base*dpPercent);
    const priceEl=document.getElementById('price'); if(priceEl){ priceEl.textContent = '₱' + base.toFixed(2); }
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    const bd=document.getElementById('priceBreakdown');
    if(bd){
      if(amen==='Pool' && getPoolBookingTypeValue()==='whole_pool'){
        const per=getPerPersonRate('Pool');
        const rAmt = residents * (per * 0.5);
        const gAmt = guests * per;
        bd.style.display='block';
        bd.innerHTML = [
          `<div style="color:#23412e;">Residents: ${residents} × ₱${formatPesoAmount(per)} × 50% = ₱${formatPesoAmount(rAmt)}</div>`,
          `<div style="color:#8a2a2a;">Guests: ${guests} × ₱${formatPesoAmount(per)} = ₱${formatPesoAmount(gAmt)}</div>`,
          `<div style="margin-top:6px;font-weight:600;">Total: ₱${formatPesoAmount(rAmt+gAmt)}</div>`,
          `<div style="color:#345c40;">Downpayment (50%): ₱${formatPesoAmount((rAmt+gAmt)*0.5)}</div>`
        ].join('');
      } else {
        bd.style.display='none';
        bd.innerHTML='';
      }
    }
    updateBookingSummary();
  }
  function updateDownpaymentSuggestion(){
    const dp=document.getElementById('downpaymentInput'); if(!dp) return;
    const amen=document.getElementById('amenityField').value;
    const residents=parseInt(document.getElementById('residentsCountInput')?.value||'0',10);
    const guests=parseInt(document.getElementById('guestsCountInput')?.value||'0',10);
    const hours=parseInt(document.getElementById('hoursInput')?.value||'0',10);
    const base=computeDynamicPrice(amen, residents, guests, hours);
    const dpPercent=0.5;
    const downpayment=(base*dpPercent);
    dp.value = downpayment.toFixed(2);
    const dpText=document.getElementById('dpAmountText'); if(dpText){ dpText.textContent='₱' + downpayment.toFixed(2); }
    updateBookingSummary();
  }

  function applyPoolBookingTypeSelection(){
    const amen=document.getElementById('amenityField')?.value||'';
    const row=document.getElementById('poolBookingTypeRow');
    if(row){ row.style.display = amen==='Pool' ? 'block' : 'none'; }
    if(amen!=='Pool') return;
    const type=getPoolBookingTypeValue();
    const cap=getAmenityMaxPersons(amen);
    const pInput=document.getElementById('personsInput');
    const pText=document.getElementById('personCount');
    const rInput=document.getElementById('residentsCountInput');
    const rText=document.getElementById('residentsCountText');
    const gInput=document.getElementById('guestsCountInput');
    const gText=document.getElementById('guestsCountText');
    const wrap=document.getElementById('participantWrap');
    if(type==='whole_pool'){
      if(pInput) pInput.value=String(cap);
      if(pText){ if('value' in pText){ pText.value=String(cap); } else { pText.textContent=String(cap); } }
      if(wrap) updateParticipantVisibility();
      let rVal=parseInt(rInput?.value||'0',10);
      if(currentUserType==='resident' && rVal<1){ rVal=1; }
      rVal=Math.max(0, Math.min(cap, rVal));
      const gVal=Math.max(0, cap - rVal);
      if(rInput) rInput.value=String(rVal);
      if(rText) rText.textContent=String(rVal);
      if(gInput) gInput.value=String(gVal);
      if(gText) gText.textContent=String(gVal);
    } else {
      if(wrap) updateParticipantVisibility();
      const base=Math.max(1, Math.min(cap, parseInt(pInput?.value||'1',10) || 1));
      if(pInput) pInput.value=String(base);
      if(pText){ if('value' in pText){ pText.value=String(base); } else { pText.textContent=String(base); } }
      if(currentUserType !== 'resident'){
        if(rInput) rInput.value='0';
        if(rText) rText.textContent='0';
        if(gInput) gInput.value=String(base);
        if(gText) gText.textContent=String(base);
      } else if(rInput && gInput){
        let rVal=parseInt(rInput.value||'0',10);
        let gVal=parseInt(gInput.value||'0',10);
        let total=rVal+gVal;
        if(total!==base){
          if(rVal>base){ rVal=base; gVal=0; }
          else { gVal=Math.max(0, base-rVal); }
          rInput.value=String(rVal);
          gInput.value=String(gVal);
          if(rText) rText.textContent=String(rVal);
          if(gText) gText.textContent=String(gVal);
        }
      }
    }
    updateDisplayedPrice();
    updateDownpaymentSuggestion();
    updateBookingSummary();
    updateActionStates();
    if(typeof persistForm === 'function') persistForm();
    try{
      if(amen==='Pool'){
        renderTimeSlotButtons();
        evaluateCalendarAvailability();
        updatePoolRemainingNote();
      }
    }catch(_){}
  }
  function configureFieldsForAmenity(amen){
    if(!amen){
      try{
        document.getElementById('reservationCard').style.display='none';
        document.getElementById('reservationTitle').textContent='Reserve an Amenity';
        document.getElementById('reservationHint').textContent='Select an amenity to continue';
        const prev=document.getElementById('amenityPreview'); if(prev){ prev.style.display='none'; }
        const row=document.getElementById('poolBookingTypeRow'); if(row){ row.style.display='none'; }
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
    } else if(amen==='Pool'){
      if(personsWrap){ personsWrap.style.display='block'; }
      if(hoursLabel){ hoursLabel.style.display='none'; }
      if(hoursCounter){ hoursCounter.style.display='none'; }
      const hs=document.getElementById('hoursSelect'); if(hs){ hs.style.display='none'; }
      const hsl=document.getElementById('hoursSectionLabel'); if(hsl){ hsl.style.display='none'; }
      const tsl=document.getElementById('timeSectionLabel'); if(tsl){ tsl.style.display='none'; }
      if(hoursInput){ hoursInput.value=''; }
      const hc=document.getElementById('hoursChosen'); if(hc){ hc.value='0'; }
      if(startTimeInput){ startTimeInput.value='09:00'; }
      if(endTimeInput){ endTimeInput.value='18:00'; endTimeInput.readOnly=true; }
      if(priceEl){ priceEl.style.display='block'; }
      const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.style.display='none'; tr.textContent=''; }
      const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='none'; }
      const notice=document.getElementById('availabilityNotice'); if(notice){ notice.style.display='none'; notice.textContent=''; }
      const timeSlots=document.getElementById('timeSlotContainer'); if(timeSlots){ timeSlots.style.display='none'; }
      const note=document.getElementById('personsMaxNote'); if(note){ const max=getAmenityMaxPersons(amen); note.textContent = max?(`Maximum: ${max} persons`):''; }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      renderHoursDropdownForAmenity();
      renderTimeSlotButtons();
      if(currentUserType==='resident'){ updateParticipantVisibility(); }
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
    applyPoolBookingTypeSelection();
    updateHoursSelectEnabled();
  }

  function getAmenityMaxPersons(amen){
    if(amen==='Pool') return 20;
    if(amen==='Clubhouse') return 200;
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
    if(amen==='Pool') return {min:'09:00', max:'18:00'};
    if(amen==='Clubhouse') return {min:'09:00', max:'21:00'};
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
    el.textContent=`Selected Time 🕒: ${formatTimeHM(sh,sm)} - ${formatTimeHM(eh,em)}`;
    el.style.display='block';
    if(tn){ tn.style.display = document.getElementById('amenityField').value==='Pool' ? 'block' : 'none'; }
  }

  function updateBookingSummary(){
    const start=document.getElementById('startDateInput')?.value||'';
    const end=document.getElementById('endDateInput')?.value||'';
    const hours=document.getElementById('hoursInput')?.value||'';
    const startTime=document.getElementById('startTimeInput')?.value||'';
    const persons=document.getElementById('personsInput')?.value||'';
    const priceText=document.getElementById('price')?.textContent||'';
    const dpInput=document.getElementById('downpaymentInput');
    const dpRaw=dpInput && dpInput.value ? dpInput.value : '';
    const sdEl=document.getElementById('summaryStartDate');
    if(sdEl){ sdEl.textContent=formatDateToMMDDYYYY(start)||'--'; }
    const edEl=document.getElementById('summaryEndDate');
    if(edEl){ edEl.textContent=formatDateToMMDDYYYY(end)||'--'; }
    const hrsEl=document.getElementById('summaryHours');
    if(hrsEl){ hrsEl.textContent=hours||'--'; }
    const stEl=document.getElementById('summaryStartTime');
    if(stEl){ stEl.textContent=startTime||'--'; }
    const pEl=document.getElementById('summaryPersons');
    if(pEl){ pEl.textContent=persons||'--'; }
    const priceEl=document.getElementById('summaryPrice');
    if(priceEl){ priceEl.textContent=priceText || '₱0.00'; }
    const dpEl=document.getElementById('summaryDownpayment');
    if(dpEl){
      const n=parseFloat(dpRaw||'0');
      const val=isNaN(n)?0:n;
      dpEl.textContent='₱'+val.toFixed(2);
    }
  }

  function getReservedHoursForDay(booked, minH, maxH, ds, amen){
    let reservedHours=0; const marked={};
    (booked||[]).forEach(t=>{
      if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return;
      let bS=0, bE=24;
      if (t.has_time) {
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
    if(amenSel==='Pool'){
      const cap=getAmenityMaxPersons(amenSel);
      let fullyBookedFound=false;
      let partiallyBookedFound=false;
      for(let d=new Date(sd); d<=ed; d.setDate(d.getDate()+1)){
        const ds=`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
        try{
          const data=await fetchBookedTimesFor(ds);
          const total=parseInt(data.persons_total||'0',10);
          if(total>=cap){ fullyBookedFound=true; break; }
          if(total>0){ partiallyBookedFound=true; }
        }catch(_){}
      }
      if(fullyBookedFound){ pill.textContent='Fully Booked'; pill.className='status-pill unavailable'; }
      else if(partiallyBookedFound){ pill.textContent='Partially Booked'; pill.className='status-pill partly'; }
      else { pill.textContent='Available'; pill.className='status-pill available'; }
      return;
    }
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
    if(amenSel==='Pool'){
      computeAvailability();
      const te=document.getElementById('timeError'); if(te){ te.style.display='none'; te.textContent=''; }
      return;
    }
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
    const overlap=times.some(function(t){ if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start); const te=toMinutes(t.end); return !(sMin2>=te || eMin2<=ts); });
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
    if(amen==='Pool'){ showStartDateError(''); showDateError(''); return true; }
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
    if(amen!=='Pool'){
      if(!st){ if(force||isDirty('startTimeInput')) setFieldWarning('startTimeInput','Start time is required.'); } else { setFieldWarning('startTimeInput',''); }
    } else {
      setFieldWarning('startTimeInput','');
    }
    // End time is auto-computed from start time + hours; no manual warning
    if(st && !et){ computeEndTimeFromHours(); }
    if(isHourBasedAmenity(amen)){
      if(hours<1){ if(force||isDirty('hoursInput')) setFieldWarning('hoursInput','Number of hours must be at least 1.'); } else { setFieldWarning('hoursInput',''); }
    } else if(amen==='Pool'){
      setFieldWarning('hoursInput','');
      const max=getAmenityMaxPersons(amen);
      if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); }
      else if(persons>max){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
      else { setFieldWarning('personsInput',''); }
    } else {
      const max=getAmenityMaxPersons(amen);
      if(persons<1){ if(force||isDirty('personsInput')) setFieldWarning('personsInput','Persons must be at least 1.'); }
      else if(persons>max && max!==Infinity){ setFieldWarning('personsInput',`Maximum is ${max} persons.`); }
      else { setFieldWarning('personsInput',''); }
    }
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
    if(amen!=='Pool'){
      if(!st) return false;
      if(st){ if(!et){ computeEndTimeFromHours(); } const [sh,sm]=(st||'').split(':'), [eh,em]=(document.getElementById('endTimeInput').value||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) return false; }
    }
    if(isHourBasedAmenity(amen)){ if(hours<1) return false; }
    if(persons<1) return false;
    const max=getAmenityMaxPersons(amen);
    if(max!==Infinity && persons>max) return false;
    if(amen==='Pool' && getPoolBookingTypeValue()==='whole_pool' && persons!==max) return false;
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
    const pbt=document.getElementById('poolBookingType');
    if(pbt){
      pbt.setAttribute('data-prev', String(pbt.value||''));
      pbt.addEventListener('change',function(){
        const prev=String(pbt.getAttribute('data-prev')||'');
        const next=String(pbt.value||'');
        if(prev==='whole_pool' && next==='per_person'){
          const pHidden=document.getElementById('personsInput');
          if(pHidden) pHidden.value='1';
          const pText=document.getElementById('personCount');
          if(pText){
            if('value' in pText){ pText.value='1'; }
            else { pText.textContent='1'; }
          }
          const rInput=document.getElementById('residentsCountInput');
          const rText=document.getElementById('residentsCountText');
          const gInput=document.getElementById('guestsCountInput');
          const gText=document.getElementById('guestsCountText');
          if(typeof currentUserType !== 'undefined' && currentUserType !== 'resident'){
            if(rInput) rInput.value='0';
            if(rText) rText.textContent='0';
            if(gInput) gInput.value='1';
            if(gText) gText.textContent='1';
          } else {
            if(rInput) rInput.value='1';
            if(rText) rText.textContent='1';
            if(gInput) gInput.value='0';
            if(gText) gText.textContent='0';
          }
        }
        pbt.setAttribute('data-prev', next);
        applyPoolBookingTypeSelection();
        computeAvailability();
        evaluateCalendarAvailability();
      });
    }
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
        const overlap=times.some(function(t){ if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); });
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
          let days=0;
          if(amenVal==='Pool'){
            days = countWeekdaysInclusive(s,eD);
          } else {
            const sDate=new Date(s);
            const eDate=new Date(eD);
            const diff=Math.floor((eDate - sDate)/(1000*60*60*24));
            days = isNaN(diff) ? 0 : (diff + 1);
          }
          if(days > 0){
            durationDisplay = days + ' day' + (days>1?'s':'');
          }
        }
        const summary = [
          ['Amenity', amenVal||'-'],
          ['Start Date', formatDateToMMDDYYYY(s) || '-'],
          ['End Date', formatDateToMMDDYYYY(eD) || '-'],
          ['Duration', durationDisplay],
          ['Time', timeDisplay || '-'],
          ['Persons', String(personsVal)],
          ['Total Price', priceTxt],
          ['Downpayment', (dpVal!==''?('₱'+Number(dpVal).toFixed(2)):'—')]
        ].map(function(x){ return '<div style="display:flex;justify-content:space-between;margin:4px 0"><span style="font-weight:600">'+x[0]+'</span><span>'+x[1]+'</span></div>'; }).join('');
        const sumEl=document.getElementById('verifySummary'); if(sumEl){ sumEl.innerHTML = summary; }
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
      if(amen==='Pool' && getPoolBookingTypeValue()==='whole_pool'){
        const cap=getAmenityMaxPersons(amen);
        let rSel=0;
        const wrap=document.getElementById('participantWrap');
        const rGroup=wrap ? wrap.querySelector('.resident-group') : null;
        if(rGroup && rGroup.style.display!=='none'){ rSel = rGroup.querySelectorAll('.resident-check:checked').length; }
        if(currentUserType==='resident' && rSel<1){ rSel=1; }
        rSel=Math.max(0, Math.min(cap, rSel));
        const gSel=Math.max(0, cap - rSel);
        if(residentsCountInput) residentsCountInput.value=String(rSel);
        if(guestsCountInput) guestsCountInput.value=String(gSel);
      const pInput=document.getElementById('personsInput'); if(pInput){ pInput.value=String(cap); }
      const pText=document.getElementById('personCount'); if(pText){ if('value' in pText){ pText.value=String(cap); } else { pText.textContent=String(cap); } }
        setFieldWarning('personsInput','');
        updateDisplayedPrice();
        updateDownpaymentSuggestion();
        updateBookingSummary();
        updateActionStates();
        if(typeof persistForm === 'function') persistForm();
        return;
      }
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
      } else if(amen!=='Pool' && max!==Infinity && total>max){
        setFieldWarning('personsInput',`Maximum is ${max} persons.`);
        hasPersonsError=true;
      } else {
        setFieldWarning('personsInput','');
      }
      updateDisplayedPrice();
      updateDownpaymentSuggestion();
      updateBookingSummary();
      if(typeof persistForm === 'function') persistForm();
      if(amen==='Pool' && !hasPersonsError){
        updatePoolRemainingNote();
      }
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
        modal.style.display='none';
      });
    }
    if(closeBtn){
      closeBtn.addEventListener('click',function(){
        modal.style.display='none';
      });
    }
    if(confirmBtn){
      confirmBtn.addEventListener('click',function(){
        modal.style.display='none';
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
    if(amenVal==='Pool'){
      if(stInput){ stInput.value='09:00'; }
      if(etInput){ etInput.value='18:00'; }
    }
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
    const requiresTime = amenVal && amenVal !== 'Pool';
    if(requiresTime && (!st||!et)) allowed=false;
    if(s&&eD && eD < s) allowed=false;
    if(s&&eD){ const sDate=new Date(s); const eDate=new Date(eD); const diff=Math.floor((eDate - sDate)/(1000*60*60*24)); if(diff>6) allowed=false; }
    if(requiresTime && st&&et){ const [sh,sm]=(st||'').split(':'), [eh,em]=(et||'').split(':'); const sMin=(parseInt(sh||'0',10)*60)+parseInt(sm||'0',10); const eMin=(parseInt(eh||'0',10)*60)+parseInt(em||'0',10); if(eMin<=sMin) allowed=false; }
    if(isHourBasedAmenity(amenVal)){ if(hours<1) allowed=false; }
    if(persons<1) allowed=false;
    if(max!==Infinity && persons>max) allowed=false;
    if(amenVal==='Pool' && getPoolBookingTypeValue()==='whole_pool' && persons!==max) allowed=false;
    if(amenVal==='Pool' && getPoolBookingTypeValue()==='whole_pool' && window.__wholePoolLowSlots===true) allowed=false;
    const remainingPool=window.__poolRemainingSlots;
    if(amenVal==='Pool' && getPoolBookingTypeValue()!=='whole_pool' && Number.isFinite(remainingPool) && persons>remainingPool) allowed=false;
    
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
        guests_count:document.getElementById('guestsCountInput')?.value||'',
        pool_booking_type:getPoolBookingTypeValue()
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
      if(data.pool_booking_type){
        const pb=document.getElementById('poolBookingType');
        if(pb){ pb.value = data.pool_booking_type==='whole_pool' ? 'whole_pool' : 'per_person'; }
      }
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
      const key = amenName==='Pool' ? 'pool' : amenName==='Clubhouse' ? 'clubhouse' : amenName==='Basketball Court' ? 'basketball' : amenName==='Tennis Court' ? 'tennis' : '';
      document.querySelectorAll('.amenity-card').forEach(c=>c.classList.remove('selected'));
      const card=document.querySelector(`.amenity-card[data-key="${key}"]`); if(card){ card.classList.add('selected'); }
      refreshPricingForBookingFor();
      updateParticipantVisibility();
      selectedAmenity = document.getElementById('amenityField').value || '';
      refreshAvailabilityFromServer();
    }catch(_){}
  }
  ['amenityField','startDateInput','endDateInput','startTimeInput','endTimeInput','personsInput','hoursInput','downpaymentInput'].forEach(id=>{const el=document.getElementById(id); if(el){ el.addEventListener('input',function(){ markDirty(id); persistForm(); updateActionStates(); showIncompleteWarnings(false); }); }});
  async function refreshAvailabilityFromServer(){
    selectedAmenity = document.getElementById('amenityField').value || '';
    availabilityCache.clear();
    await loadBookedDates(true);
    computeAvailability();
    renderTimeSlotButtons();
    if(selectedAmenity === 'Pool'){ updatePoolRemainingNote(); }
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
  function computeMaxDuration(amenity,startHour,booked){ const hrs=getAmenityHours(amenity); const maxHour=parseInt(hrs.max.split(':')[0],10); let max=0; for(let h=1; startHour+h<=maxHour; h++){ const thisStart=`${String(startHour).padStart(2,'0')}:00`; const thisEnd=`${String(startHour+h).padStart(2,'0')}:00`; const sM=toMinutes(thisStart), eM=toMinutes(thisEnd); const overlaps=(booked||[]).some(function(t){ if(isHourBasedAmenity(amenity) && (t.has_time===false || t.has_time===0)) return false; const ts=toMinutes(t.start), te=toMinutes(t.end); return !(eM<=ts || sM>=te); }); if(overlaps) break; max=h; } return max; }

  function renderHoursChipsForAmenity(){ const amen=document.getElementById('amenityField').value; const dc=document.getElementById('durationContainer'); const lbl=document.getElementById('hoursSectionLabel'); if(!dc) return; dc.innerHTML=''; if(!isHourBasedAmenity(amen)){ dc.style.display='none'; if(lbl) lbl.style.display='none'; return; } dc.style.display='flex'; if(lbl) lbl.style.display='block'; dc.style.flexWrap='wrap'; dc.style.gap='8px'; dc.style.margin='8px 0 0 0'; const maxH=amen==='Clubhouse'?12:9; for(let h=1; h<=maxH; h++){ const b=document.createElement('button'); b.type='button'; b.className='dur-btn'; b.textContent=`${h}h`; b.dataset.hours=String(h); b.onclick=function(){ selectDuration(h); }; dc.appendChild(b); } const currentH=parseInt(document.getElementById('hoursInput').value||'',10); if(currentH){ const sel=Array.from(dc.children).find(b=>b.dataset.hours===String(currentH)); if(sel) sel.classList.add('selected'); } }

  function renderTimeSlotButtons(){
    const amen=document.getElementById('amenityField').value;
    const container=document.getElementById('timeSlotContainer');
    const tLbl=document.getElementById('timeSectionLabel');
    const notice=document.getElementById('availabilityNotice');
    if(!container) return;
    container.innerHTML='';
    if(notice){ notice.style.display='none'; notice.textContent=''; }
    if(amen==='Pool'){
      container.style.display='none';
      if(tLbl){ tLbl.style.display='none'; }
      return;
    }
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
    window.__slotRenderTokenCounter=(window.__slotRenderTokenCounter||0)+1; const __token=window.__slotRenderTokenCounter; window.__activeSlotRenderToken=__token; if(!date){ container.innerHTML=''; if(notice){ notice.style.display='none'; notice.textContent=''; } return; } fetchBookedTimesFor(date).then(data=>{ if(window.__activeSlotRenderToken!==__token) return; const booked=data.times||[]; window.__bookedTimesForDate=booked||[]; let anyEnabled=false; let disabledCount=0; slots.forEach(slot=>{ const startHour=parseInt(slot.value.split(':')[0],10); const maxPossible=computeMaxDuration(amen,startHour,booked); const valid=(maxPossible>=hours); const btn=document.createElement('button'); btn.type='button'; btn.className='slot-btn airbnb'; btn.textContent=slot.label; btn.dataset.slot=slot.value; if(!valid){ disabledCount++; btn.classList.add('unavailable'); btn.setAttribute('aria-disabled','true'); btn.onclick=function(){ showToast('This start time cannot fit your selected duration. Try a different start time or duration.','warning'); }; } else { anyEnabled=true; btn.classList.add('available'); btn.onclick=function(){ selectTimeSlot(slot.value); }; } container.appendChild(btn); }); let hasBookedHours=false; (booked||[]).forEach(function(t){ if(isHourBasedAmenity(amen) && (t.has_time===false || t.has_time===0)) return; const bS=parseInt(String(t.start).split(':')[0],10); const bE=parseInt(String(t.end).split(':')[0],10); if(bE>bS){ hasBookedHours=true; } }); if(notice){ if(!anyEnabled){ notice.style.display='block'; notice.textContent = hasBookedHours ? 'Fully Booked — no time slots available for this date.' : ''; } else if(disabledCount>0){ notice.style.display='block'; notice.textContent = hasBookedHours ? 'Partially Booked — some time slots are unavailable.' : ''; } else { notice.style.display='none'; notice.textContent=''; } } if(!anyEnabled){ showTimeError('No start times fit the selected hours. Try a different duration.'); } else { showTimeError(''); } const st=document.getElementById('startTimeInput').value; if(st){ const selBtn=Array.from(container.children).find(b=>b.tagName==='BUTTON' && b.dataset.slot===st); if(selBtn) selBtn.classList.add('selected'); } updateActionStates(); }); }

  function selectTimeSlot(start){ const hInput=document.getElementById('hoursInput'); const hrs=parseInt(hInput?.value||'0',10); if(!hrs || hrs<1){ showTimeError('Please select number of hours before choosing a start time.'); return; } const amen=document.getElementById('amenityField').value; const booked=window.__bookedTimesForDate||[]; const startHour=parseInt(start.split(':')[0],10); if(computeMaxDuration(amen,startHour,booked) < Math.max(1,hrs)){ showTimeError('This start time cannot fit your selected duration. Try a different start time or duration.'); showToast(`⚠️ Not enough free hours starting from this time to complete ${hrs} hour${hrs>1?'s':''}.`,'warning'); return; } document.getElementById('startTimeInput').value=start; computeEndTimeFromHours(); const sh=startHour, eh=sh+hrs; const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected Time 🕒: ${formatTimeSlot(sh)} - ${formatTimeSlot(eh)}`; tr.style.display='block'; } const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display = amen==='Pool' ? 'block' : 'none'; } const cont=document.getElementById('timeSlotContainer'); if(cont){ Array.from(cont.querySelectorAll('.slot-btn')).forEach(function(b){ b.classList.remove('selected'); }); const sel=Array.from(cont.querySelectorAll('.slot-btn')).find(function(b){ return b.dataset.slot===start; }); if(sel){ sel.classList.add('selected'); } } showTimeError(''); updateActionStates(); }
  function selectPoolSlot(start,end){ document.getElementById('startTimeInput').value=start; document.getElementById('endTimeInput').value=end; const sh=parseInt(start.split(':')[0],10); const sm=parseInt(start.split(':')[1]||'0',10); const eh=parseInt(end.split(':')[0],10); const em=parseInt(end.split(':')[1]||'0',10); const tr=document.getElementById('selectedTimeRange'); if(tr){ tr.textContent=`Selected Time 🕒: ${formatTimeHM(sh,sm)} - ${formatTimeHM(eh,em)}`; tr.style.display='block'; } const tn=document.getElementById('selectedTimeNote'); if(tn){ tn.style.display='block'; } showTimeError(''); updateActionStates(); updateBookingSummary(); }
  function renderHoursDropdownForAmenity(){
    const amen=document.getElementById('amenityField').value;
    const sel=document.getElementById('hoursSelect');
    const lbl=document.getElementById('hoursSectionLabel');
    if(!sel) return;
    sel.innerHTML='';
    if(!isHourBasedAmenity(amen)){ sel.style.display='none'; if(lbl) lbl.style.display='none'; return; }
    sel.style.display='inline-block'; if(lbl) lbl.style.display='block';
    const blankOpt=document.createElement('option'); blankOpt.value=''; blankOpt.textContent='Select hours'; blankOpt.disabled=true; blankOpt.selected=true; sel.appendChild(blankOpt);
    const maxH=amen==='Clubhouse'?12:9;
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
      const maxPossible=computeMaxDuration(amen,sh,booked);
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

</body>
</html>
