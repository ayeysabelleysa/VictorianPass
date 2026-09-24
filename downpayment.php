<?php
require_once __DIR__ . '/session_bootstrap.php';
include 'connect.php';

function downpaymentColumnExists($con, string $col): bool {
    if (!($con instanceof mysqli)) return false;
    static $cache = [];
    if (isset($cache[$col])) return $cache[$col];
    $r = @$con->query("SHOW COLUMNS FROM reservations LIKE '" . $con->real_escape_string($col) . "'");
    return $cache[$col] = ($r !== false && $r->num_rows > 0);
}

// FETCH USER EMAIL FROM ENTRYPASS
$entry_pass_id = intval($_GET['entry_pass_id'] ?? 0);
$user_email_prefill = '';
$user_email = '';
if($entry_pass_id > 0){
    $stmt = $con->prepare("SELECT email FROM entry_passes WHERE id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param("i", $entry_pass_id);
        $stmt->execute();
        $stmt->bind_result($user_email);
        if($stmt->fetch()){
            $user_email_prefill = $user_email;
        }
        $stmt->close();
    }
}

// Helpers and CSRF
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
function ensureReservationsCommonColumns($con){ if(!($con instanceof mysqli)) return; $cols=['downpayment','receipt_path','payment_status','account_type','booking_for','receipt_uploaded_at','gcash_reference_number','pool_booking_type']; foreach($cols as $col){ $c=$con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'"); if(!$c || $c->num_rows===0){ if($col==='downpayment'){ @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL"); } else if($col==='receipt_path'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_path VARCHAR(255) NULL"); } else if($col==='payment_status'){ @$con->query("ALTER TABLE reservations ADD COLUMN payment_status ENUM('pending','submitted','verified') NULL"); } else if($col==='account_type'){ @$con->query("ALTER TABLE reservations ADD COLUMN account_type ENUM('visitor','resident') NULL"); } else if($col==='booking_for'){ @$con->query("ALTER TABLE reservations ADD COLUMN booking_for ENUM('resident','guest') NULL"); } else if($col==='receipt_uploaded_at'){ @$con->query("ALTER TABLE reservations ADD COLUMN receipt_uploaded_at DATETIME NULL"); } else if($col==='gcash_reference_number'){ @$con->query("ALTER TABLE reservations ADD COLUMN gcash_reference_number VARCHAR(30) NULL"); } else if($col==='pool_booking_type'){ @$con->query("ALTER TABLE reservations ADD COLUMN pool_booking_type ENUM('per_person','whole_pool') NULL"); } } } }
ensureReservationsCommonColumns($con);

function ensureReservationBookerColumns($con){
    if(!($con instanceof mysqli)) return;
    $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_role'");
    if(!$c1 || $c1->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_role ENUM('resident','guest','co_owner') NULL AFTER booking_for");
    }
    $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_name'");
    if(!$c2 || $c2->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_name VARCHAR(255) NULL AFTER booked_by_role");
    }
}
ensureReservationBookerColumns($con);
// Pull pending reservation context
$continue = isset($_GET['continue']) ? $_GET['continue'] : 'reserve';
$userType = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';
$backTarget = 'reserve.php';
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    unset($_SESSION['pending_reservation'], $_SESSION['dp_ref_code'], $_SESSION['flash_ref_code'], $_SESSION['reservation_submitted']);
    $to = isset($_GET['to']) ? basename($_GET['to']) : $backTarget;
    $allowedTargets = ['dashboardvisitor.php', 'profileresident.php', 'mainpage.php', 'reserve.php'];
    if (!in_array($to, $allowedTargets, true)) { $to = $backTarget; }
    if ($to === 'reserve.php') {
        header('Location: reserve.php?reset_reservation=1');
    } else {
        header('Location: ' . $to);
    }
    exit;
}
// capture ref_code from URL once, then remove from address bar
$ref_code_url = isset($_GET['ref_code']) ? trim($_GET['ref_code']) : '';
if ($ref_code_url !== '') {
    $_SESSION['dp_ref_code'] = $ref_code_url;
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = $_SERVER['SCRIPT_NAME'] ?? '/VictorianPass/downpayment.php';
    $qs = $_GET; unset($qs['ref_code']);
    $query = http_build_query($qs);
    header('Location: ' . $scheme . '://' . $host . $path . ($query ? ('?' . $query) : ''));
    exit;
}
$ref_code = isset($_SESSION['dp_ref_code']) ? $_SESSION['dp_ref_code'] : '';
$user_id = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$pending = isset($_SESSION['pending_reservation']) ? $_SESSION['pending_reservation'] : null;

if ((!is_array($pending) || empty($pending)) && $ref_code !== '' && ($con instanceof mysqli)) {
    $colsC = ['amenity', 'start_date', 'end_date', 'start_time', 'end_time', 'persons', 'price', 'downpayment', 'entry_pass_id'];
    $bookingForExists = downpaymentColumnExists($con, 'booking_for');
    if ($bookingForExists) { $colsC[] = 'booking_for'; }
    $usePointsExists = downpaymentColumnExists($con, 'use_points');
    $pointsUsedExists = downpaymentColumnExists($con, 'points_used');
    if ($usePointsExists) { $colsC[] = 'use_points'; }
    if ($pointsUsedExists) { $colsC[] = 'points_used'; }
    $stmtC = $con->prepare("SELECT " . implode(',', $colsC) . " FROM reservations WHERE ref_code = ? LIMIT 1");
    if ($stmtC) {
        $stmtC->bind_param('s', $ref_code);
        $stmtC->execute();
        $resC = $stmtC->get_result();
        if ($resC && ($rwC = $resC->fetch_assoc())) {
            $pending = [
                'amenity' => $rwC['amenity'] ?? '',
                'start_date' => $rwC['start_date'] ?? null,
                'end_date' => $rwC['end_date'] ?? null,
                'start_time' => $rwC['start_time'] ?? null,
                'end_time' => $rwC['end_time'] ?? null,
                'persons' => isset($rwC['persons']) ? intval($rwC['persons']) : null,
                'price' => isset($rwC['price']) ? floatval($rwC['price']) : null,
                'downpayment' => isset($rwC['downpayment']) ? floatval($rwC['downpayment']) : null,
                'entry_pass_id' => isset($rwC['entry_pass_id']) ? intval($rwC['entry_pass_id']) : null,
                'booking_for' => $rwC['booking_for'] ?? null,
                'use_points' => $usePointsExists ? intval($rwC['use_points'] ?? 0) : 0,
                'points_used' => $pointsUsedExists ? intval($rwC['points_used'] ?? 0) : 0
            ];
            $_SESSION['pending_reservation'] = $pending;
            if ($entry_pass_id <= 0 && !empty($pending['entry_pass_id'])) { $entry_pass_id = intval($pending['entry_pass_id']); }
        }
        $stmtC->close();
    }
}

function format_time_ap($t){
    $s = trim((string)$t);
    if ($s === '') return '--';
    $dt = DateTime::createFromFormat('H:i:s', $s);
    if (!$dt) { $dt = DateTime::createFromFormat('H:i', $s); }
    if ($dt) { return $dt->format('g:i A'); }
    $p = explode(':', $s);
    $h = intval($p[0] ?? 0);
    $m = intval($p[1] ?? 0);
    $ap = ($h >= 12) ? 'PM' : 'AM';
    $hh = $h % 12; if ($hh === 0) $hh = 12;
    return $hh . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ' . $ap;
}

function deriveReservationHours($amenity, $bookingFor, $price){
    if (!$amenity || $price <= 0) return 0;
    $rate = 0;
    if ($amenity === 'Basketball Court' || $amenity === 'Tennis Court') { $rate = ($bookingFor === 'resident') ? 100 : 150; }
    else if ($amenity === 'Clubhouse') { $rate = ($bookingFor === 'resident') ? 300 : 450; }
    else if ($amenity === 'Multi-Purpose Building') { $rate = ($bookingFor === 'resident') ? 200 : 300; }
    if ($rate <= 0) return 0;
    $h = (int)round($price / $rate);
    return $h > 0 ? $h : 0;
}

function normalizeEndTimeFromHours($startTime, $hours, $amenity){
    $start = trim((string)$startTime);
    if ($start === '' || $hours <= 0 || !preg_match('/^(\d{1,2}):(\d{2})/', $start, $m)) return $startTime;
    $maxH = ($amenity === 'Clubhouse' || $amenity === 'Multi-Purpose Building') ? 21 : 18;
    $endH = (int)$m[1] + $hours;
    $endM = (int)$m[2];
    if ($endH > $maxH) { $endH = $maxH; $endM = 0; }
    return sprintf('%02d:%02d', $endH, $endM);
}

// HANDLE FORM SUBMISSION
$msg = '';
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
    $gcashReferenceNumber = isset($_POST['gcashreferencenumber']) ? trim($_POST['gcashreferencenumber']) : '';
    if ($gcashReferenceNumber === '') {
      $msg = 'Please enter your GCash reference number before confirming.';
    } else if (!preg_match('/^\d{13}$/', $gcashReferenceNumber) || preg_match('/^(\d)\1{12}$/', $gcashReferenceNumber)) {
      $msg = 'Invalid GCash reference number. It must contain 13 digits.';
    }
    $continue_post = isset($_POST['continue']) ? $_POST['continue'] : $continue;
    $entry_pass_id_post_form = isset($_POST['entry_pass_id']) ? intval($_POST['entry_pass_id']) : $entry_pass_id;
    if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
      $msg = 'Invalid submission.';
    } else if ($ref_code !== '' && empty($msg)) {
      $receiptPath = null;
      if(!isset($_FILES['receipt']) || !is_array($_FILES['receipt']) || ($_FILES['receipt']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK){
        $msg = 'Please upload your payment receipt before confirming.';
      } else {
        $allowedExt=['png','jpg','jpeg','pdf'];
        $origName=$_FILES['receipt']['name']??'';
        $ext=strtolower(pathinfo($origName,PATHINFO_EXTENSION));
        if(!in_array($ext,$allowedExt,true)){
          $msg='Unsupported receipt file type. Please upload a JPG, PNG, or PDF.';
        } else if(($_FILES['receipt']['size']??0) > 5*1024*1024){
          $msg='Receipt file is too large (max 5MB).';
        } else {
          $uploadsDir=__DIR__.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'receipts'; if(!is_dir($uploadsDir)) { @mkdir($uploadsDir,0775,true); }
          $base=preg_replace('/[^a-zA-Z0-9_-]/','_', $ref_code);
          $fname=$base.'-'.date('YmdHis').'.'.$ext;
          $target=$uploadsDir.DIRECTORY_SEPARATOR.$fname;
          $relative='uploads/receipts/'.$fname;
          if(@move_uploaded_file($_FILES['receipt']['tmp_name'],$target)){ $receiptPath=$relative; } else { $msg='Unable to save receipt upload. Please try again.'; }
        }
      }
      $amenity = isset($pending['amenity']) ? $pending['amenity'] : null;
      $start   = isset($pending['start_date']) ? $pending['start_date'] : null;
      $end     = isset($pending['end_date']) ? $pending['end_date'] : null;
      $startTime = isset($pending['start_time']) ? $pending['start_time'] : null;
      $endTime   = isset($pending['end_time']) ? $pending['end_time'] : null;
      $persons = isset($pending['persons']) ? intval($pending['persons']) : null;
      $price = isset($pending['price']) ? floatval($pending['price']) : null;
      $downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
      $entry_pass_id_post = isset($pending['entry_pass_id']) ? intval($pending['entry_pass_id']) : ($entry_pass_id_post_form ?: null);
      $booking_for = isset($pending['booking_for']) ? trim($pending['booking_for']) : '';
      if ($booking_for === '') { $booking_for = null; }
      $guest_id = isset($pending['guest_id']) ? trim($pending['guest_id']) : '';
      $guest_ref_code = isset($pending['guest_ref_code']) ? trim($pending['guest_ref_code']) : '';
      $booked_by_role = null;
      $booked_by_name = null;
      if ($booking_for === 'guest') {
        $booked_by_role = 'guest';
        try {
          if ($con instanceof mysqli) {
            if ($guest_id !== '') {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE id = ? LIMIT 1");
              $gid = intval($guest_id);
              $stmtG->bind_param('i', $gid);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            } else if ($guest_ref_code !== '') {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE ref_code = ? LIMIT 1");
              $stmtG->bind_param('s', $guest_ref_code);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            } else if (!empty($ref_code)) {
              $stmtG = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name FROM guest_forms WHERE ref_code = ? LIMIT 1");
              $stmtG->bind_param('s', $ref_code);
              $stmtG->execute();
              $resG = $stmtG->get_result();
              if ($resG && ($rwG = $resG->fetch_assoc())) {
                $parts = [];
                if (!empty($rwG['visitor_first_name'])) $parts[] = $rwG['visitor_first_name'];
                if (!empty($rwG['visitor_middle_name'])) $parts[] = $rwG['visitor_middle_name'];
                if (!empty($rwG['visitor_last_name'])) $parts[] = $rwG['visitor_last_name'];
                $booked_by_name = trim(implode(' ', $parts));
              }
              $stmtG->close();
            }
          }
        } catch (Throwable $_) { /* ignore */ }
      } else if ($booking_for === 'co_owner') {
        $booked_by_role = 'co_owner';
      }
      $uid = ($user_id && $user_id>0) ? $user_id : null;
      // Authoritative end time = start time + hours (server-side), so a stale/legacy
      // pending session can never write a corrupt end time into the database.
      $hoursPending = isset($pending['hours']) ? intval($pending['hours']) : 0;
      if ($hoursPending <= 0) { $hoursPending = deriveReservationHours($amenity, $booking_for, $price); }
      if ($hoursPending > 0) { $endTime = normalizeEndTimeFromHours($startTime, $hoursPending, $amenity); }
      if(empty($msg)){
        $acct = ($continue_post === 'reserve_resident') ? 'resident' : 'visitor';
        $hadLegacy = false;
        if($con instanceof mysqli){
          $chk=$con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
          if ($chk) {
            $chk->bind_param('s',$ref_code); $chk->execute(); $cr=$chk->get_result();
            $hadLegacy = ($cr && $cr->num_rows>0);
            $chk->close();
          }
        }
        // Adaptive UPDATE: only set columns that actually exist in the deployed DB.
        $colOk = function($col) use ($con){ return downpaymentColumnExists($con, $col); };
        $updSets = [];
        $updVals = [];
        $updTypes = '';
        $addSet = function($col, $type, $val) use (&$updSets, &$updVals, &$updTypes, $colOk) {
          if (!$colOk($col)) return;
          $updSets[] = $col . ' = COALESCE(?, ' . $col . ')';
          $updVals[] = $val;
          $updTypes .= $type;
        };
        $addSet('amenity', 's', $amenity);
        $addSet('start_date', 's', $start);
        $addSet('end_date', 's', $end);
        $addSet('start_time', 's', $startTime);
        $addSet('end_time', 's', $endTime);
        $addSet('persons', 'i', $persons);
        $addSet('price', 'd', $price);
        $addSet('downpayment', 'd', $downpayment);
        $addSet('receipt_path', 's', $receiptPath);
        $addSet('gcash_reference_number', 's', $gcashReferenceNumber);
        $addSet('user_id', 'i', $uid);
        $addSet('entry_pass_id', 'i', $entry_pass_id_post);
        $addSet('booking_for', 's', $booking_for);
        $addSet('booked_by_role', 's', $booked_by_role);
        $addSet('booked_by_name', 's', $booked_by_name);
        $updSets[] = 'account_type = COALESCE(account_type, ?)';
        $updVals[] = $acct;
        $updTypes .= 's';
        $updSets[] = "payment_status='submitted'";
        $updSets[] = "approval_status='pending'";
        $updSets[] = 'receipt_uploaded_at = COALESCE(receipt_uploaded_at, NOW())';

        $stmt = $con->prepare('UPDATE reservations SET ' . implode(', ', $updSets) . " WHERE ref_code = ?");
        $affected = 0;
        if ($stmt) {
          $valsAll = $updVals;
          $valsAll[] = $ref_code;
          $refs = [$updTypes . 's'];
          foreach ($valsAll as $k => $v) { $refs[] = &$valsAll[$k]; }
          call_user_func_array([$stmt, 'bind_param'], $refs);
          $stmt->execute();
          $affected = $stmt->affected_rows;
          $stmt->close();
        }
        if ($affected === 0) {
          $insCols = ['ref_code','amenity','start_date','end_date','start_time','end_time','persons','price','downpayment','receipt_path'];
          $insTypes = 'ssssssidds';
          $insVals = [$ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $receiptPath];
          if ($colOk('gcash_reference_number')) { $insCols[] = 'gcash_reference_number'; $insTypes .= 's'; $insVals[] = $gcashReferenceNumber; }
          $insCols[] = 'user_id'; $insTypes .= 'i'; $insVals[] = $uid;
          $insCols[] = 'entry_pass_id'; $insTypes .= 'i'; $insVals[] = $entry_pass_id_post;
          if ($colOk('booking_for')) { $insCols[] = 'booking_for'; $insTypes .= 's'; $insVals[] = $booking_for; }
          if ($colOk('booked_by_role')) { $insCols[] = 'booked_by_role'; $insTypes .= 's'; $insVals[] = $booked_by_role; }
          if ($colOk('booked_by_name')) { $insCols[] = 'booked_by_name'; $insTypes .= 's'; $insVals[] = $booked_by_name; }
          $insCols[] = 'account_type'; $insTypes .= 's'; $insVals[] = $acct;
          $insCols[] = 'payment_status'; $insTypes .= 's'; $insVals[] = 'submitted';
          $insCols[] = 'approval_status'; $insTypes .= 's'; $insVals[] = 'pending';
          $insCols[] = 'receipt_uploaded_at';
          $ins = $con->prepare('INSERT INTO reservations (' . implode(', ', $insCols) . ') VALUES (' . implode(', ', array_pad(array_fill(0, count($insCols) - 1, '?'), count($insCols), 'NOW()')) . ')');
          if ($ins) {
            $refsIns = [$insTypes];
            foreach ($insVals as $k => $v) { $refsIns[] = &$insVals[$k]; }
            call_user_func_array([$ins, 'bind_param'], $refsIns);
            $ins->execute();
            $ins->close();
          }
        }
        if ($acct === 'resident') {
          try {
            $chkRR = $con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
            if ($chkRR) {
              $chkRR->bind_param('s', $ref_code);
              $chkRR->execute(); $resRR = $chkRR->get_result(); $existsRR = ($resRR && $resRR->num_rows>0); $chkRR->close();
              if ($existsRR) {
                $uRR = $con->prepare("UPDATE resident_reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), approval_status = 'pending', updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE ref_code = ?");
                if ($uRR) {
                  $uRR->bind_param('sssis', $amenity, $start, $end, $uid, $ref_code);
                  $uRR->execute(); $uRR->close();
                }
              } else {
                $iRR = $con->prepare("INSERT INTO resident_reservations (user_id, amenity, start_date, end_date, approval_status, ref_code, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
                if ($iRR) {
                  $iRR->bind_param('issss', $uid, $amenity, $start, $end, $ref_code);
                  $iRR->execute(); $iRR->close();
                }
              }
            }
          } catch (Throwable $_) { }
        }
      }
      $_SESSION['pending_reservation'] = null;
      if(empty($msg)){
        $_SESSION['flash_notice'] = 'Request submitted, waiting for approval';
        unset($_SESSION['flash_ref_code']);
      }
      // Resolve recipient name/email
      $full_name = '';
      $email = '';
      if ($entry_pass_id_post) {
        $stmtInfo = $con->prepare("SELECT full_name, middle_name, last_name, email FROM entry_passes WHERE id = ? LIMIT 1");
        if ($stmtInfo) {
          $stmtInfo->bind_param('i', $entry_pass_id_post);
          $stmtInfo->execute();
          $stmtInfo->bind_result($fn, $mn, $ln, $em);
          if ($stmtInfo->fetch()) {
            $full_name = trim(($fn ?: '') . ' ' . ($mn ?: '') . ' ' . ($ln ?: ''));
            $email = $em ?: '';
          }
          $stmtInfo->close();
        }
      }
      if ($email === '' && $uid) {
        $stmtU = $con->prepare("SELECT first_name, middle_name, last_name, email FROM users WHERE id = ? LIMIT 1");
        if ($stmtU) {
          $stmtU->bind_param('i', $uid);
          $stmtU->execute();
          $stmtU->bind_result($uf, $um, $ul, $ue);
          if ($stmtU->fetch()) {
            $full_name = trim(($uf ?: '') . ' ' . ($um ?: '') . ' ' . ($ul ?: ''));
            $email = $ue ?: '';
          }
          $stmtU->close();
        }
      }
      if ($email === '' && $user_email_prefill !== '') { $email = $user_email_prefill; }
      if ($full_name === '') { $full_name = 'Guest'; }

    }
    $residentPaymentFlow = (($continue_post ?? $continue) === 'reserve_resident' || $userType === 'resident');
    if (empty($msg) && $residentPaymentFlow) {
      header('Location: profileresident.php?section=panel-requests&reservation_success=1');
    } else if (empty($msg)) {
      header('Location: dashboardvisitor.php');
    }
    if (empty($msg)) { exit; }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Downpayment - GCash</title>
    <link rel="icon" type="image/png" href="images/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/navbar.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/navbar.css') ?: '', 0, 12); ?>">
  <style>
    *{font-family:'Poppins',sans-serif}
    body{margin:0;background:#fafbfc;color:#111827}
    .wrap{max-width:720px;margin:60px auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 4px 16px rgba(15,23,42,0.08)}
    .title{font-weight:700;font-size:1.5rem;margin:0 0 6px;color:#111827}
    .meta{color:#4b5563;font-size:.95rem;margin-bottom:8px}
    .qr{display:flex;justify-content:center;margin:18px 0}
    .btn{background:#23412e;color:#fff;border:none;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;font-size:.95rem}
    .btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(35,65,46,0.4);opacity:.95}
    .btn[disabled]{opacity:.6;cursor:not-allowed;box-shadow:none;transform:none}
    .btn-outline{background:#e5e7eb;color:#111827;border:1px solid #d1d5db}
    .code{background:#f3f4f6;border-radius:10px;padding:8px 12px;display:inline-block;margin-top:8px;color:#111827;font-weight:600}
    .break{margin-top:16px;padding-top:12px;border-top:1px solid #e5e7eb}
    .row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:.9rem}
    .row:last-child{border-bottom:none}
    .row .label{color:#6b7280;font-weight:500}
    .row .amount{font-weight:600;color:#111827}
    .pay-callout{display:flex;justify-content:center;align-items:center;background:#f0faf2;border:1.5px solid #cfe6d4;color:#23412e;border-radius:12px;padding:12px 14px;margin:14px 0;font-weight:700;font-size:.95rem}
    .pay-callout .num{font-size:1.4rem;margin-left:8px}
    .downpayment-summary{margin-top:16px}
    .vs-banner{display:flex;align-items:center;gap:8px;background:#d1fae5;color:#065f46;border:1px solid #34d399;border-radius:10px;padding:9px 12px;font-weight:700;font-size:.88rem;line-height:1.4;margin:10px 0 14px;text-align:left}
    .vs-banner i{flex-shrink:0}
    .vs-section{background:#f8fafc;border:1px solid #e5e7eb;border-radius:12px;padding:11px 13px;text-align:left}
    .vs-section + .vs-section{margin-top:12px}
    .vs-section-title{font-size:.7rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#6b7280;margin-bottom:7px}
    .vs-reward{background:#f0fdf4;border-color:#bbf7d0}
    .vs-reward .vs-section-title{color:#15803d}
    .vs-row{display:flex;align-items:baseline;justify-content:space-between;gap:12px;padding:4px 0;font-size:.88rem;line-height:1.45}
    .vs-lbl{color:#4b5563;font-weight:600;white-space:nowrap}
    .vs-val{color:#111827;text-align:right;min-width:0}
    .vs-good .vs-val{color:#15803d;font-weight:700}
    .vs-final-row{border-top:1px dashed #d1d5db;margin-top:7px;padding-top:9px}
    .vs-final-row .vs-lbl{color:#14532d;font-weight:800}
    .vs-final-row .vs-val{color:#14532d;font-weight:800;font-size:1.12rem}
    .vs-dp-row{margin-top:7px;padding:8px 10px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:9px}
    .vs-dp-row .vs-lbl{color:#065f46;font-weight:800}
    .vs-dp-row .vs-val{color:#065f46;font-weight:800;font-size:1.02rem}
    @media (max-width:640px){.vs-row{align-items:flex-start;flex-wrap:wrap}.vs-lbl{white-space:normal}.vs-val{margin-left:auto}}
    .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000}
    .upload-area{border:1.5px dashed #d1d5db;background:#f9fafb;padding:18px;border-radius:12px;margin-top:16px;display:flex;flex-direction:column;gap:10px}
    .upload-area .label{color:#111827;font-weight:600;font-size:.95rem}
    #receiptInput{padding:6px 10px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;font-size:.82rem;font-family:'Poppins',sans-serif;max-width:280px}
    #receiptInput:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.1);outline:none}
    #receiptInput::file-selector-button{padding:4px 12px;border:1px solid #d1d5db;border-radius:6px;background:#e5e7eb;color:#111827;font-size:.78rem;font-weight:600;font-family:'Poppins',sans-serif;cursor:pointer;transition:background .2s}
    #receiptInput::file-selector-button:hover{background:#d1d5db}
    .field-label{color:#111827;font-weight:600;font-size:.95rem;display:block;margin-top:6px}
    .field-label .ref-note{display:block;font-weight:400;font-size:.78rem;color:#6b7280;margin-top:2px}
    .field-input{width:100%;padding:.75rem;border:1px solid #ccc;border-radius:8px;font-size:.95rem;background:#fff;color:#111827;font-family:'Poppins',sans-serif;box-sizing:border-box;margin-top:6px}
    .field-input:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.1);outline:none}
    .ref-warning{display:none;align-items:center;gap:6px;background:#fee2e2;color:#b30000;border:1px solid #fecaca;border-radius:8px;padding:6px 10px;font-size:.8rem;font-weight:600;margin-top:8px}
    .ref-warning i{font-size:.85rem}
    .field-warning{display:none;align-items:flex-start;gap:8px;margin-top:6px;padding:8px 10px;background:#fff;border-left:4px solid #c0392b;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.12);color:#333;font-size:.85rem}
    .field-warning.is-visible{display:flex}
    .field-warning .warn-icon{width:18px;height:18px;border-radius:50%;background:#c0392b;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:.75rem;font-weight:700;flex-shrink:0;line-height:1}
    #removeFileBtn{padding:5px 14px;font-size:.78rem;border-radius:6px;align-self:flex-start;line-height:1.4}
    #confirmBtn{padding:12px 20px;font-size:1rem;margin-top:8px;align-self:flex-end}
    .upload-preview{display:flex;flex-direction:column;gap:6px;align-items:flex-start;justify-content:flex-start;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:8px;max-width:180px;width:min(180px,100%);box-sizing:border-box}
    .upload-preview img{width:100%;max-width:160px;max-height:120px;min-height:80px;height:auto;object-fit:contain;border-radius:6px;cursor:pointer;transition:opacity .2s;border:1px solid #e5e7eb;background:#fff;align-self:center}
    .upload-preview img:hover{opacity:.85}
    .upload-preview .file-name{color:#111827;font-weight:600;font-size:.78rem;word-break:break-all}
    @keyframes modalPop{from{opacity:0;transform:scale(0.92)}to{opacity:1;transform:scale(1)}}
    @keyframes modalClose{from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(0.92)}}
    @keyframes modalFadeOut{from{opacity:1}to{opacity:0}}
    #imgModal{display:none;position:fixed;z-index:2000;left:0;top:0;width:100%;height:100%;background:rgba(15,23,42,0.55);backdrop-filter:blur(5px);align-items:center;justify-content:center}
    #imgModal.open{display:flex}
    #imgModal.closing{animation:modalFadeOut 0.25s ease-in forwards}
    #imgModal .modal-content{background:#ffffff;border-radius:18px;padding:16px;position:relative;transform-origin:center;animation:modalPop 0.28s cubic-bezier(0.2,0.8,0.2,1);box-shadow:0 24px 70px rgba(15,23,42,0.35);border:1px solid #e5e7eb;max-width:92vw;max-height:90vh;display:flex;align-items:center;justify-content:center}
    #imgModal.closing .modal-content{animation:modalClose 0.25s ease-in forwards}
    #imgModal .modal-content img{max-width:88vw;max-height:82vh;object-fit:contain;border-radius:10px}
    #imgModal .modal-close{position:absolute;top:12px;right:12px;width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#eef2f0;color:#23412e;border:none;font-size:16px;cursor:pointer;line-height:1;z-index:2}
    #warningModal{display:none;position:fixed;z-index:9999;left:0;top:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);opacity:0;visibility:hidden;pointer-events:none;transition:opacity 0.22s ease,visibility 0.22s ease}
    #warningModal.is-visible{display:flex;align-items:center;justify-content:center;opacity:1;visibility:visible;pointer-events:auto}
    #warningModal .modal-content{background:#fff;padding:28px 24px;border-radius:12px;width:90%;max-width:380px;text-align:center;position:relative;box-shadow:0 12px 30px rgba(0,0,0,0.18);transform:translateY(8px) scale(0.98);transition:transform 0.22s ease}
    #warningModal.is-visible .modal-content{transform:translateY(0) scale(1)}
    #warningModal .modal-close{position:absolute;right:12px;top:10px;background:transparent;border:0;font-size:22px;cursor:pointer;color:#666;line-height:1}
    #warningModal .modal-title{font-size:1.15rem;font-weight:700;color:#c0392b;margin:0 0 10px}
    #warningModal .modal-message{color:#444;font-size:0.95rem;margin:0 0 18px;line-height:1.5}
    #warningModal .modal-btn{border:0;background:#23412e;color:#fff;padding:10px 18px;border-radius:8px;cursor:pointer;font-weight:600;width:100%;font-family:'Poppins',sans-serif}
    .nonrefundable{background:#fee2e2;color:#b30000;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-weight:700;margin-top:10px;display:block;font-size:.9rem;border-left:4px solid #dc2626}
    body.modal-open{overflow:hidden}
    .proceed-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);align-items:center;justify-content:center;z-index:2000}
    .proceed-content{background:#fff;border-radius:14px;padding:22px 24px;width:92%;max-width:360px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.25);position:relative}
    .proceed-content h3{margin:0;color:#111827;font-size:1.1rem}
    .proceed-content p{margin:8px 0 0;color:#4b5563;font-size:.9rem}
    .proceed-actions{display:flex;gap:10px;justify-content:center;margin-top:18px}
    .proceed-actions .btn{background:#23412e;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:700;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
    .proceed-actions .btn:hover{transform:translateY(-2px);box-shadow:0 8px 16px rgba(15,23,42,.12)}
    .proceed-actions .btn.btn-outline{background:#e5e7eb;color:#111}
    .proceed-close{position:absolute;top:10px;right:12px;width:28px;height:28px;border-radius:50%;background:#f3f4f6;color:#111827;border:none;display:inline-flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer}
    .back-row{max-width:720px;margin:24px auto 0;padding:0 16px;position:relative;z-index:1001}
    .back-btn{position:relative;z-index:1002;display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;background:#d4a017;color:#fff;border:none;border-radius:999px;font-weight:700;text-decoration:none;font-size:1.1rem;box-shadow:0 6px 14px rgba(212, 160, 23, 0.35);transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease}
    .back-btn i{color:#ffffff;}
    .back-btn:hover{opacity:.95;transform:translateY(-1px);box-shadow:0 8px 16px rgba(212, 160, 23, 0.4);background:#b68912}
    html{overflow-x:hidden;scroll-behavior:smooth}
    body{overflow-x:hidden;position:relative;width:100%}
    @media (max-width:640px){
      html{overflow-x:hidden;-webkit-text-size-adjust:100%;text-size-adjust:100%}
      body{overflow-x:hidden;min-height:100dvh;padding-top:58px}
      .back-row{
        margin:20px auto 0;
        padding:0 max(14px, env(safe-area-inset-left));
        display:flex;
        justify-content:flex-start;
        position:relative;
        z-index:1;
      }
      .back-btn{
        width:42px;
        height:42px;
        flex-shrink:0;
        z-index:1;
        touch-action:manipulation;
        -webkit-tap-highlight-color:rgba(212,160,23,0.35);
      }
      .wrap{margin:20px auto;padding:0 12px;scroll-margin-top:74px;max-width:100%}
      .card{padding:18px;overflow-y:auto;-webkit-overflow-scrolling:touch}
      .pay-callout{flex-direction:column;align-items:flex-start}
      .pay-callout .num{margin-left:0;margin-top:4px}
      #receiptInput{max-width:100%}
      .upload-preview{max-width:140px;padding:6px;width:min(140px,100%)}
      .upload-preview img{max-width:130px;max-height:110px;min-height:70px}
      #gcashReferenceNumber{
        scroll-margin-top:84px;
        -webkit-appearance:none;
        appearance:none;
        font-size:16px !important;
        touch-action:manipulation;
      }
      #gcashReferenceNumber:focus{
        position:relative;
        z-index:1;
      }
      .field-label{
        scroll-margin-top:74px;
      }
      .upload-area{
        scroll-margin-top:74px;
      }
      #imgModal .modal-content{max-width:96vw;padding:10px}
      #imgModal .modal-content img{max-width:94vw;max-height:80vh}
      #imgModal .modal-close{top:8px;right:8px;width:28px;height:28px;font-size:15px}
      #warningModal .modal-content{padding:24px 18px;max-width:96vw}
      #warningModal .modal-close{top:8px;right:8px;font-size:20px}
      .proceed-actions{flex-direction:column;gap:8px}
      .proceed-actions .btn{width:100%;padding:10px 12px;font-size:0.9rem}
      .proceed-content{padding:16px 14px;width:92%;max-width:300px;border-radius:12px}
      .proceed-content h3{font-size:0.98rem}
      .proceed-content p{font-size:0.84rem;margin:6px 0 0}
    }
  </style>
  </head>
<body>
  <?php
    // compute pricing breakdown
    $amenity = isset($pending['amenity']) ? $pending['amenity'] : '';
    $price   = isset($pending['price']) ? floatval($pending['price']) : 0.0;
    $downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
    $isHourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse','Multi-Purpose Building'], true);
    $isPersonBased = in_array($amenity, [], true);
    if ($downpayment === null || $downpayment <= 0) { $downpayment = round($price * 0.5, 2); }
    $remaining = max(0, round($price - $downpayment, 2));
    $durationText = '--';
    $sd = $pending['start_date'] ?? null;
    $ed = $pending['end_date'] ?? null;
    if ($sd && $ed) {
      try {
        $sdObj = new DateTime($sd);
        $edObj = new DateTime($ed);
        $days = 0;
        if (false && $amenity === 'Pool') {
          $period = new DatePeriod($sdObj, new DateInterval('P1D'), (clone $edObj)->modify('+1 day'));
          foreach ($period as $d) {
            $dow = intval($d->format('N'));
            if ($dow >= 1 && $dow <= 5) { $days++; }
          }
        } else {
          $diffDays = $sdObj->diff($edObj)->days;
          $days = $diffDays + 1;
        }
        if ($days > 0) { $durationText = $days . ' day' . ($days > 1 ? 's' : ''); }
      } catch (Throwable $_) { }
    }
    $refDisplay = 'N/A';
    $qrUrl = 'images/downpayment.jpg';
    if ($ref_code === '' && $continue !== 'reserve_resident') { $ref_code = 'VP-' . str_pad(rand(0, 99999), 5, '0', STR_PAD_LEFT); }
    $backParams = ['reset' => 1, 'to' => $backTarget];
    $backLink = 'downpayment.php?' . http_build_query($backParams);
  ?>
  <?php include __DIR__ . '/navbar.php'; ?>
  <div class="back-row">
    <a href="<?php echo htmlspecialchars($backLink); ?>" class="back-btn" id="backBtn" aria-label="Back"><i class="fa-solid fa-arrow-left"></i></a>
  </div>
  <div class="wrap">
    <div class="card">
      <h2 class="title">Downpayment</h2>
      <p class="meta">Use the GCash details shown to pay your partial payment. Upload the receipt and click Confirm.</p>
      <div class="qr"><img src="<?php echo htmlspecialchars($qrUrl); ?>" alt="GCash Downpayment" style="max-width:280px;border-radius:8px;border:1px solid rgba(255,255,255,.2)" onerror="this.style.display='none'"></div>
      <div class="pay-callout">You will pay now:<span class="num">₱<?php echo number_format($downpayment, 2); ?></span></div>
      <p class="nonrefundable">Downpayment is non-refundable.</p>
      <div class="downpayment-summary">
        <div class="vs-section">
          <div class="vs-section-title">Reservation Details</div>
          <div class="vs-row"><span class="vs-lbl">Amenity</span><span class="vs-val"><?php echo htmlspecialchars($amenity ?: 'N/A'); ?></span></div>
        <?php
          $hours = 1;
          if (isset($pending['hours'])) {
            $hours = max(1, intval($pending['hours']));
          } else {
            $sd = $pending['start_date'] ?? null; $ed = $pending['end_date'] ?? null; $st = $pending['start_time'] ?? null; $et = $pending['end_time'] ?? null;
            if ($sd && $ed && $sd === $ed && $st && $et) {
              $sh = intval(substr($st,0,2)); $eh = intval(substr($et,0,2));
              $sm = intval(substr($st,3,2)); $em = intval(substr($et,3,2));
              $hours = max(1, ($eh*60+$em-($sh*60+$sm))/60);
            }
          }
          $persons = isset($pending['persons']) ? intval($pending['persons']) : 1;
          $usePoints = !empty($pending['use_points']);
          $pointsUsed = intval($pending['points_used'] ?? 0);
          $hourlyRate = 0;
          if ($amenity === 'Basketball Court' || $amenity === 'Tennis Court') {
            $hourlyRate = 100;
          } elseif ($amenity === 'Clubhouse') {
            $hourlyRate = 300;
          } elseif ($amenity === 'Multi-Purpose Building') {
            $hourlyRate = 200;
          }
          $paidHours = $usePoints ? max(0, $hours - 1) : $hours;
          $originalAmount = $usePoints ? ($price + $hourlyRate) : $price;
          $rewardPoints = $pointsUsed > 0 ? $pointsUsed : (($amenity === 'Basketball Court' || $amenity === 'Tennis Court') ? 300 : (($amenity === 'Clubhouse') ? 600 : (($amenity === 'Multi-Purpose Building') ? 750 : 0)));
          $startDateDisplay = '--';
          $endDateDisplay = '--';
          if (!empty($pending['start_date'])) {
            $startDateTs = strtotime((string)$pending['start_date']);
            if ($startDateTs !== false) { $startDateDisplay = date('m/d/Y', $startDateTs); }
          }
          if (!empty($pending['end_date'])) {
            $endDateTs = strtotime((string)$pending['end_date']);
            if ($endDateTs !== false) { $endDateDisplay = date('m/d/Y', $endDateTs); }
          }
        ?>
        <?php if ($usePoints): ?><div class="vs-banner"><i class="fa-solid fa-circle-check"></i> VHEcoPoint Redemption: <?php echo $hours > 1 ? 'Discounted Redemption' : 'Fully Redeemed'; ?></div><?php endif; ?>
          <div class="vs-row"><span class="vs-lbl">Start Date</span><span class="vs-val"><?php echo htmlspecialchars($startDateDisplay); ?></span></div>
          <div class="vs-row"><span class="vs-lbl">End Date</span><span class="vs-val"><?php echo htmlspecialchars($endDateDisplay); ?></span></div>
          <div class="vs-row"><span class="vs-lbl">Time</span><span class="vs-val">
          <?php
            $st = $pending['start_time'] ?? '';
            $et = $pending['end_time'] ?? '';
            echo ($st && $et) ? (format_time_ap($st) . ' – ' . format_time_ap($et)) : '--';
          ?>
          </span></div>
          <div class="vs-row"><span class="vs-lbl">Persons</span><span class="vs-val"><?php echo intval($persons); ?></span></div>
        </div>
        <?php if ($usePoints): ?>
          <div class="vs-section vs-reward">
            <div class="vs-section-title">VHEcoPoint Redemption: <?php echo $hours > 1 ? 'Discounted Redemption' : 'Fully Redeemed'; ?></div>
            <div class="vs-row"><span class="vs-lbl">Original Duration</span><span class="vs-val"><?php echo $hours; ?> hour<?php echo $hours !== 1 ? 's' : ''; ?></span></div>
            <div class="vs-row vs-good"><span class="vs-lbl">Reward</span><span class="vs-val">-1 Free Hour (<?php echo number_format($rewardPoints); ?> pts)</span></div>
            <div class="vs-row"><span class="vs-lbl">Paid Duration</span><span class="vs-val"><?php echo $paidHours; ?> hour<?php echo $paidHours !== 1 ? 's' : ''; ?></span></div>
          </div>
          <div class="vs-section vs-payment">
            <div class="vs-section-title">Payment Summary</div>
            <div class="vs-row"><span class="vs-lbl">Original Amount</span><span class="vs-val">₱<?php echo number_format($originalAmount, 2); ?></span></div>
            <div class="vs-row vs-good"><span class="vs-lbl">VHEcoPoint Discount</span><span class="vs-val">-₱<?php echo number_format($hourlyRate, 2); ?> (<?php echo number_format($rewardPoints); ?> pts)</span></div>
            <div class="vs-row vs-final-row"><span class="vs-lbl">Final Amount</span><span class="vs-val">₱<?php echo number_format($price, 2); ?></span></div>
            <div class="vs-row vs-dp-row"><span class="vs-lbl">Downpayment</span><span class="vs-val">₱<?php echo number_format($downpayment, 2); ?></span></div>
          </div>
        <?php else: ?>
          <div class="vs-section vs-payment">
            <div class="vs-section-title">Payment Summary</div>
            <div class="vs-row"><span class="vs-lbl">Total Price</span><span class="vs-val">₱<?php echo number_format($price, 2); ?></span></div>
            <div class="vs-row vs-dp-row"><span class="vs-lbl">Downpayment</span><span class="vs-val">₱<?php echo number_format($downpayment, 2); ?></span></div>
          </div>
        <?php endif; ?>
        <div class="vs-section">
          <div class="vs-section-title">Payment Collection</div>
          <div class="vs-row"><span class="vs-lbl">Online Payment (Partial)</span><span class="vs-val">₱<?php echo number_format($downpayment, 2); ?></span></div>
          <div class="vs-row"><span class="vs-lbl">Onsite Payment (Remaining)</span><span class="vs-val">₱<?php echo number_format($remaining, 2); ?></span></div>
          <div class="vs-row"><span class="vs-lbl">QR Reference Code</span><span class="vs-val"><?php echo htmlspecialchars($ref_code ?: 'N/A'); ?></span></div>
        </div>
      </div>
      <form method="POST" enctype="multipart/form-data" novalidate style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <input type="hidden" name="continue" value="<?php echo htmlspecialchars($continue); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo intval($entry_pass_id); ?>">
        <div class="upload-area">
          <label for="receiptInput" class="label">Upload GCash Receipt (JPG, PNG, PDF • Max 5MB)</label>
          <input type="file" name="receipt" id="receiptInput" accept="image/jpeg,image/png,.pdf" required>
          <div class="upload-preview" id="uploadPreview" style="display:none"></div>
          <button type="button" class="btn btn-outline" id="removeFileBtn" disabled>Remove Selected File</button>
          <div class="field-warning" id="receiptWarning" role="alert"><span class="warn-icon">!</span><span class="msg"></span></div>
        </div>
        <label for="gcashReferenceNumber" class="field-label">GCash Reference Number (from receipt)<span class="ref-note">A GCash transaction reference number has 13 digits.</span></label>
        <input type="text" name="gcashreferencenumber" id="gcashReferenceNumber" class="field-input" placeholder="Enter the GCash reference number from your receipt" inputmode="numeric" maxlength="13" required>
        <div class="field-warning" id="refWarning" role="alert"><span class="warn-icon">!</span><span class="msg"></span></div>
        <button type="submit" class="btn" id="confirmBtn">Confirm Payment</button>
      </form>
    </div>
  </div>
  <div class="login-modal" id="warningModal">
    <div class="modal-content">
      <button type="button" class="modal-close" id="warningCloseBtn" aria-label="Close">&times;</button>
      <div class="modal-title" id="warningTitle">Please complete the required fields.</div>
      <div class="modal-message" id="warningMsg"></div>
      <button type="button" class="modal-btn" id="warningOkBtn">OK</button>
    </div>
  </div>
  <div class="proceed-modal" id="proceedModal">
    <div class="proceed-content">
      <button type="button" class="proceed-close" id="proceedCloseBtn" aria-label="Close">&times;</button>
      <h3>Do you want to proceed?</h3>
      <div class="proceed-actions">
        <button type="button" class="btn btn-outline" id="proceedNo">Cancel</button>
        <button type="button" class="btn" id="proceedYes">Proceed</button>
      </div>
    </div>
  </div>
  <div class="proceed-modal" id="backModal">
    <div class="proceed-content">
      <button type="button" class="proceed-close" id="backCloseBtn" aria-label="Close">&times;</button>
      <h3>Going back will reset your reservation.</h3>
      <p style="margin:8px 0 0;color:#4b5563;">You will need to enter your details again.</p>
      <div class="proceed-actions">
        <button type="button" class="btn btn-outline" id="backCancel">Stay</button>
        <button type="button" class="btn" id="backConfirm">Go Back</button>
      </div>
    </div>
  </div>
  <div class="modal" id="imgModal">
    <div class="modal-content">
      <button type="button" class="modal-close" id="imgModalClose" aria-label="Close">&times;</button>
      <img id="imgModalSrc" src="" alt="Proof of Payment">
    </div>
  </div>
  <script>
    (function(){
      const input=document.getElementById('receiptInput');
      const refInput=document.getElementById('gcashReferenceNumber');
      const receiptWarning=document.getElementById('receiptWarning');
      const refWarning=document.getElementById('refWarning');
      const btn=document.getElementById('confirmBtn');
      const preview=document.getElementById('uploadPreview');
      const removeBtn=document.getElementById('removeFileBtn');
      const form=btn ? btn.closest('form') : null;
      const proceedModal=document.getElementById('proceedModal');
      const proceedYes=document.getElementById('proceedYes');
      const proceedNo=document.getElementById('proceedNo');
      const backModal=document.getElementById('backModal');
      const backConfirm=document.getElementById('backConfirm');
      const backCancel=document.getElementById('backCancel');
      const backCloseBtn=document.getElementById('backCloseBtn');
      const proceedCloseBtn=document.getElementById('proceedCloseBtn');
      const warningModal=document.getElementById('warningModal');
      const warningTitle=document.getElementById('warningTitle');
      const warningMsg=document.getElementById('warningMsg');
      const warningCloseBtn=document.getElementById('warningCloseBtn');
      const warningOkBtn=document.getElementById('warningOkBtn');
      const serverMessage=<?php echo json_encode($msg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
      let pendingSubmit=false;
      function renderPreview(file){
        if(!file){ preview.style.display='none'; preview.innerHTML=''; return; }
        const name=document.createElement('div');
        name.className='file-name';
        name.textContent=file.name;
        preview.innerHTML='';
        preview.appendChild(name);
        const type=(file.type||'').toLowerCase();
        if(type.startsWith('image/')){
          const img=document.createElement('img');
          const reader=new FileReader();
          reader.onload=function(e){ img.src=e.target.result; img.setAttribute('data-full', e.target.result); };
          reader.readAsDataURL(file);
          img.style.cursor='pointer';
          img.addEventListener('click', function(){ openImgModal(img.getAttribute('data-full') || img.src); });
          preview.appendChild(img);
        } else {
          const note=document.createElement('div');
          note.style.color='#cfe9d3';
          note.textContent='Selected file ready to upload.';
          preview.appendChild(note);
        }
        preview.style.display='flex';
      }
      let lastFileSig='';
      function update(){
        const hasFile=!!(input && input.files && input.files.length>0);
        removeBtn.disabled=!hasFile;
        const f=hasFile?input.files[0]:null;
        const sig=f?(f.name+'\u0000'+f.size+'\u0000'+(f.lastModified||0)):'';
        if(sig===lastFileSig) return;
        lastFileSig=sig;
        renderPreview(f);
      }
      function openWarning(title, msg){
        if(!warningModal) return;
        if(warningTitle) warningTitle.textContent=title;
        if(warningMsg) warningMsg.textContent=msg;
        warningModal.classList.add('is-visible');
        document.body.classList.add('modal-open');
      }
      function closeWarning(){
        if(!warningModal) return;
        warningModal.classList.remove('is-visible');
        document.body.classList.remove('modal-open');
      }
      function setInlineWarning(element, message){
        if(!element) return;
        const msg=element.querySelector('.msg');
        if(msg) msg.textContent=message || '';
        element.classList.toggle('is-visible', !!message);
      }
      function validateSubmission(){
        const hasFile=!!(input && input.files && input.files.length>0);
        const refVal=(refInput && (refInput.value||'').trim())||'';
        const validRef=/^\d{13}$/.test(refVal) && !/^(\d)\1{12}$/.test(refVal);
        setInlineWarning(receiptWarning, '');
        setInlineWarning(refWarning, '');
        if(!hasFile && !validRef){
          setInlineWarning(receiptWarning, 'Please upload your Proof of Payment.');
          setInlineWarning(refWarning, 'Please enter your GCash Reference Number.');
          return false;
        }
        if(!hasFile){
          setInlineWarning(receiptWarning, 'Please upload your Proof of Payment.');
          return false;
        }
        if(!validRef){
          setInlineWarning(refWarning, 'Please enter a valid 13-digit GCash Reference Number.');
          return false;
        }
        return true;
      }
      function openProceed(){
        if(!proceedModal) return;
        proceedModal.style.display='flex';
        document.body.classList.add('modal-open');
      }
      function closeProceed(){
        if(!proceedModal) return;
        proceedModal.style.display='none';
        document.body.classList.remove('modal-open');
      }
      function openBack(){
        if(!backModal) return;
        backModal.style.display='flex';
        document.body.classList.add('modal-open');
      }
      function closeBack(){
        if(!backModal) return;
        backModal.style.display='none';
        document.body.classList.remove('modal-open');
      }
      const backBtn=document.getElementById('backBtn');
      if(backBtn){
        backBtn.addEventListener('click', function(e){
          e.preventDefault();
          openBack();
        });
      }
      if(input){ input.addEventListener('change', function(){ update(); setInlineWarning(receiptWarning, ''); }); }
      if(refInput){
        refInput.addEventListener('input', function(){
          const raw = (refInput.value || '').replace(/\D+/g, '');
          const cleaned = raw.slice(0, 13);
          if (refInput.value !== cleaned) { refInput.value = cleaned; }
          setInlineWarning(refWarning, '');
        });
      }
      if(removeBtn){ removeBtn.addEventListener('click', function(){ input.value=''; update(); }); }
      if(form){
        form.addEventListener('submit', function(e){
          if(pendingSubmit) return;
          e.preventDefault();
          if(!validateSubmission()) return;
          openProceed();
        });
      }
      if(proceedYes && form){
        proceedYes.addEventListener('click', function(){
          if(pendingSubmit) return;
          pendingSubmit=true;
          closeProceed();
          form.submit();
        });
      }
      if(proceedNo){
        proceedNo.addEventListener('click', function(){
          closeProceed();
        });
      }
      if(proceedCloseBtn){
        proceedCloseBtn.addEventListener('click', function(){
          closeProceed();
        });
      }
      if(proceedModal){
        proceedModal.addEventListener('click', function(e){
          if(e.target === proceedModal){ closeProceed(); }
        });
      }
      if(warningCloseBtn){ warningCloseBtn.addEventListener('click', closeWarning); }
      if(warningOkBtn){ warningOkBtn.addEventListener('click', closeWarning); }
      if(warningModal){
        warningModal.addEventListener('click', function(e){
          if(e.target === warningModal){ closeWarning(); }
        });
      }
      if(backConfirm && backBtn){
        backConfirm.addEventListener('click', function(){
          try{ sessionStorage.removeItem('reserve_form'); }catch(_){}
          closeBack();
          window.location.href = backBtn.getAttribute('href') || 'reserve.php';
        });
      }
      if(backCancel){
        backCancel.addEventListener('click', function(){
          closeBack();
        });
      }
      if(backCloseBtn){
        backCloseBtn.addEventListener('click', function(){
          closeBack();
        });
      }
      if(backModal){
        backModal.addEventListener('click', function(e){
          if(e.target === backModal){ closeBack(); }
        });
      }
      const imgModal=document.getElementById('imgModal');
      const imgModalSrc=document.getElementById('imgModalSrc');
      const imgModalClose=document.getElementById('imgModalClose');
      function openImgModal(src){
        if(!imgModal||!src)return;
        imgModalSrc.src=src;
        if(imgModal.classList.contains('closing')){ imgModal.classList.remove('closing'); }
        imgModal.classList.add('open');
        document.body.classList.add('modal-open');
      }
      function closeImgModal(){
        if(!imgModal)return;
        if(imgModal.classList.contains('closing'))return;
        if(imgModal.classList.contains('open')||getComputedStyle(imgModal).display!=='none'){
          imgModal.classList.remove('open');
          imgModal.classList.add('closing');
          setTimeout(function(){
            imgModal.classList.remove('closing');
            imgModal.style.display='none';
            document.body.classList.remove('modal-open');
            imgModalSrc.src='';
          },260);
        } else {
          document.body.classList.remove('modal-open');
          imgModalSrc.src='';
        }
      }
      if(imgModalClose){imgModalClose.addEventListener('click',closeImgModal);}
      if(imgModal){imgModal.addEventListener('click',function(e){if(e.target===imgModal)closeImgModal();});}
      document.addEventListener('keydown',function(e){if(e.key==='Escape'&&imgModal&&imgModal.classList.contains('open'))closeImgModal();});
      update();
      if(serverMessage){
        if(/receipt|proof of payment/i.test(serverMessage)){ setInlineWarning(receiptWarning, serverMessage); }
        if(/reference/i.test(serverMessage)){ setInlineWarning(refWarning, serverMessage); }
      }

      // Mobile keyboard stabilization
      (function(){
        var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
        if (!isMobile || !window.visualViewport) return;
        var lastScrolledRef = 0;
        var lastScrolledInput = 0;

        function recenter(el){
          if (!el) return;
          var r = el.getBoundingClientRect();
          var vh = window.visualViewport.height || window.innerHeight || 0;
          if (r.top < 0 || r.bottom > vh) {
            el.scrollIntoView({ behavior:'instant', block:'nearest' });
          }
        }

        if (refInput) {
          refInput.addEventListener('focus', function(){
            recenter(refInput);
          });
        }

        if (input) {
          input.addEventListener('focus', function(){
            recenter(input);
          });
        }

        window.visualViewport.addEventListener('resize', function(){
          var active = document.activeElement;
          var now = Date.now();
          if (active === refInput) {
            if (now - lastScrolledRef < 200) return;
            lastScrolledRef = now;
            recenter(refInput);
          } else if (active === input) {
            if (now - lastScrolledInput < 200) return;
            lastScrolledInput = now;
            recenter(input);
          }
        });
      })();
    })();
  </script>
</body>
</html>