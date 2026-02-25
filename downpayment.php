<?php
session_start();
include 'connect.php';

// FETCH USER EMAIL FROM ENTRYPASS
$entry_pass_id = intval($_GET['entry_pass_id'] ?? 0);
$user_email_prefill = '';
if($entry_pass_id > 0){
    $stmt = $con->prepare("SELECT email FROM entry_passes WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $entry_pass_id);
    $stmt->execute();
    $stmt->bind_result($user_email);
    if($stmt->fetch()){
        $user_email_prefill = $user_email;
    }
    $stmt->close();
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
$hasPoolBookingType = false;
$poolCheck = $con->query("SHOW COLUMNS FROM reservations LIKE 'pool_booking_type'");
if ($poolCheck && $poolCheck->num_rows > 0) { $hasPoolBookingType = true; }
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
    $poolCol = $hasPoolBookingType ? "pool_booking_type" : "NULL AS pool_booking_type";
    $stmtC = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, entry_pass_id, booking_for, $poolCol FROM reservations WHERE ref_code = ? LIMIT 1");
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
            'pool_booking_type' => $rwC['pool_booking_type'] ?? ''
        ];
        $_SESSION['pending_reservation'] = $pending;
        if ($entry_pass_id <= 0 && !empty($pending['entry_pass_id'])) { $entry_pass_id = intval($pending['entry_pass_id']); }
    }
    $stmtC->close();
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

// HANDLE FORM SUBMISSION
if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    $ref_code = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
    $gcashReferenceNumber = isset($_POST['gcashreferencenumber']) ? trim($_POST['gcashreferencenumber']) : '';
    if ($gcashReferenceNumber === '' || !preg_match('/^\d{13}$/', $gcashReferenceNumber) || preg_match('/^(\d)\1{12}$/', $gcashReferenceNumber)) {
      $msg = 'Invalid GCash reference number.';
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
      $pool_booking_type = isset($pending['pool_booking_type']) ? trim($pending['pool_booking_type']) : '';
      if ($booking_for === '') { $booking_for = null; }
      if ($amenity === 'Pool') { $pool_booking_type = ($pool_booking_type === 'whole_pool') ? 'whole_pool' : 'per_person'; } else { $pool_booking_type = null; }
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
      if(empty($msg)){
        $acct = ($continue_post === 'reserve_resident') ? 'resident' : 'visitor';
        $hadLegacy = false;
        if($con instanceof mysqli){ $chk=$con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1"); $chk->bind_param('s',$ref_code); $chk->execute(); $cr=$chk->get_result(); $hadLegacy = ($cr && $cr->num_rows>0); $chk->close(); }
        $stmt = $con->prepare("UPDATE reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), start_time = COALESCE(?, start_time), end_time = COALESCE(?, end_time), persons = COALESCE(?, persons), price = COALESCE(?, price), downpayment = COALESCE(?, downpayment), pool_booking_type = COALESCE(?, pool_booking_type), receipt_path = COALESCE(?, receipt_path), gcash_reference_number = COALESCE(?, gcash_reference_number), user_id = COALESCE(?, user_id), entry_pass_id = COALESCE(?, entry_pass_id), booking_for = COALESCE(?, booking_for), booked_by_role = COALESCE(?, booked_by_role), booked_by_name = COALESCE(?, booked_by_name), account_type = COALESCE(account_type, ?), payment_status='submitted', approval_status='pending', receipt_uploaded_at = COALESCE(receipt_uploaded_at, NOW()) WHERE ref_code = ?");
        $stmt->bind_param('sssssiddsssiisssss', $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $pool_booking_type, $receiptPath, $gcashReferenceNumber, $uid, $entry_pass_id_post, $booking_for, $booked_by_role, $booked_by_name, $acct, $ref_code);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if ($affected === 0) {
          $ins = $con->prepare("INSERT INTO reservations (ref_code, amenity, start_date, end_date, start_time, end_time, persons, price, downpayment, pool_booking_type, receipt_path, gcash_reference_number, user_id, entry_pass_id, booking_for, booked_by_role, booked_by_name, account_type, payment_status, approval_status, receipt_uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', 'pending', NOW())");
          $ins->bind_param('ssssssiddsssiissss', $ref_code, $amenity, $start, $end, $startTime, $endTime, $persons, $price, $downpayment, $pool_booking_type, $receiptPath, $gcashReferenceNumber, $uid, $entry_pass_id_post, $booking_for, $booked_by_role, $booked_by_name, $acct);
          $ins->execute();
          $ins->close();
        }
        if ($acct === 'resident') {
          try {
            $chkRR = $con->prepare("SELECT id FROM resident_reservations WHERE ref_code = ? LIMIT 1");
            $chkRR->bind_param('s', $ref_code);
            $chkRR->execute(); $resRR = $chkRR->get_result(); $existsRR = ($resRR && $resRR->num_rows>0); $chkRR->close();
            if ($existsRR) {
              $uRR = $con->prepare("UPDATE resident_reservations SET amenity = COALESCE(?, amenity), start_date = COALESCE(?, start_date), end_date = COALESCE(?, end_date), approval_status = 'pending', updated_at = NOW(), user_id = COALESCE(?, user_id) WHERE ref_code = ?");
              $uRR->bind_param('sssis', $amenity, $start, $end, $uid, $ref_code);
              $uRR->execute(); $uRR->close();
            } else {
              $iRR = $con->prepare("INSERT INTO resident_reservations (user_id, amenity, start_date, end_date, approval_status, ref_code, created_at, updated_at) VALUES (?, ?, ?, ?, 'pending', ?, NOW(), NOW())");
              $iRR->bind_param('issss', $uid, $amenity, $start, $end, $ref_code);
              $iRR->execute(); $iRR->close();
            }
          } catch (Throwable $_) { }
        }
      }
      $_SESSION['pending_reservation'] = null;
      if(empty($msg)){
        $msg = 'Receipt uploaded. Payment submitted for review.';
        $_SESSION['flash_notice'] = 'Request submitted, waiting for approval';
        unset($_SESSION['flash_ref_code']);
      }
      // Resolve recipient name/email
      $full_name = '';
      $email = '';
      if ($entry_pass_id_post) {
        $stmtInfo = $con->prepare("SELECT full_name, middle_name, last_name, email FROM entry_passes WHERE id = ? LIMIT 1");
        $stmtInfo->bind_param('i', $entry_pass_id_post);
        $stmtInfo->execute();
        $stmtInfo->bind_result($fn, $mn, $ln, $em);
        if ($stmtInfo->fetch()) {
          $full_name = trim(($fn ?: '') . ' ' . ($mn ?: '') . ' ' . ($ln ?: ''));
          $email = $em ?: '';
        }
        $stmtInfo->close();
      }
      if ($email === '' && $uid) {
        $stmtU = $con->prepare("SELECT first_name, middle_name, last_name, email FROM users WHERE id = ? LIMIT 1");
        $stmtU->bind_param('i', $uid);
        $stmtU->execute();
        $stmtU->bind_result($uf, $um, $ul, $ue);
        if ($stmtU->fetch()) {
          $full_name = trim(($uf ?: '') . ' ' . ($um ?: '') . ' ' . ($ul ?: ''));
          $email = $ue ?: '';
        }
        $stmtU->close();
      }
      if ($email === '' && $user_email_prefill !== '') { $email = $user_email_prefill; }
      if ($full_name === '') { $full_name = 'Guest'; }

    }
    if (($continue_post ?? $continue) === 'reserve_resident') {
      header('Location: profileresident.php');
    } else {
      header('Location: dashboardvisitor.php');
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Downpayment - GCash</title>
    <link rel="icon" type="image/png" href="images/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    *{font-family:'Poppins',sans-serif}
    body{margin:0;background:#fafbfc;color:#111827;padding-top:76px}
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
    .toast{position:fixed;top:14px;left:50%;transform:translateX(-50%);background:#23412e;color:#fff;padding:10px 14px;border-radius:10px;box-shadow:0 8px 18px rgba(0,0,0,.12);font-size:.9rem;z-index:1000}
    .upload-area{border:1.5px dashed #d1d5db;background:#f9fafb;padding:18px;border-radius:12px;margin-top:16px;display:flex;flex-direction:column;gap:10px}
    .upload-area .label{color:#111827;font-weight:600;font-size:.95rem}
    #receiptInput{padding:10px 12px;border:1.5px solid #d1d5db;border-radius:8px;background:#fff;color:#111827;font-size:.95rem;font-family:'Poppins',sans-serif}
    #receiptInput:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.1);outline:none}
    .field-label{color:#111827;font-weight:600;font-size:.95rem;display:block;margin-top:6px}
    .field-input{width:100%;padding:.75rem;border:1px solid #ccc;border-radius:8px;font-size:.95rem;background:#fff;color:#111827;font-family:'Poppins',sans-serif;box-sizing:border-box;margin-top:6px}
    .field-input:focus{border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.1);outline:none}
    #confirmBtn{padding:12px 20px;font-size:1rem;margin-top:8px;align-self:flex-end}
    .upload-preview{display:flex;flex-direction:column;gap:8px;align-items:flex-start;justify-content:flex-start;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:10px}
    .upload-preview img{max-width:100%;height:auto;border-radius:8px}
    .upload-preview .file-name{color:#111827;font-weight:600;font-size:.9rem}
    .nonrefundable{background:#fee2e2;color:#b30000;border:1px solid #fecaca;border-radius:8px;padding:10px 12px;font-weight:700;margin-top:10px;display:block;font-size:.9rem;border-left:4px solid #dc2626}
    body.modal-open{overflow:hidden}
    .proceed-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,0.6);align-items:center;justify-content:center;z-index:2000}
    .proceed-content{background:#fff;border-radius:14px;padding:22px 24px;width:92%;max-width:360px;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,0.25);position:relative}
    .proceed-content h3{margin:0;color:#111827;font-size:1.1rem}
    .proceed-actions{display:flex;gap:10px;justify-content:center;margin-top:18px}
    .proceed-actions .btn{background:#23412e;color:#fff;border:none;border-radius:10px;padding:10px 18px;font-weight:700;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
    .proceed-actions .btn:hover{transform:translateY(-2px);box-shadow:0 8px 16px rgba(15,23,42,.12)}
    .proceed-actions .btn.btn-outline{background:#e5e7eb;color:#111}
    .proceed-close{position:absolute;top:10px;right:12px;width:28px;height:28px;border-radius:50%;background:#f3f4f6;color:#111827;border:none;display:inline-flex;align-items:center;justify-content:center;font-size:16px;cursor:pointer}
    .navbar{display:flex;justify-content:space-between;align-items:center;padding:14px 6%;background:rgba(43,38,35,0.95);backdrop-filter:blur(10px);position:fixed;top:0;left:0;right:0;z-index:1000;border-bottom:1px solid rgba(255,255,255,0.1);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
    .logo{display:flex;align-items:center;gap:12px}
    .back-row{max-width:720px;margin:14px auto 0;padding:0 16px}
    .back-btn{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;background:#d4a017;color:#fff;border:none;border-radius:999px;font-weight:700;text-decoration:none;font-size:1.1rem;box-shadow:0 6px 14px rgba(212, 160, 23, 0.35);transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease}
    .back-btn i{color:#ffffff;}
    .back-btn:hover{opacity:.95;transform:translateY(-1px);box-shadow:0 8px 16px rgba(212, 160, 23, 0.4);background:#b68912}
    .logo img{width:42px;height:42px}
    .brand-text h1{margin:0;font-size:1.3rem;font-weight:700;color:#f4f4f4}
    .brand-text p{margin:0;font-size:.85rem;color:#aaa}
    @media (max-width:640px){
      .wrap{margin:40px auto}
      .card{padding:18px}
      .pay-callout{flex-direction:column;align-items:flex-start}
      .pay-callout .num{margin-left:0;margin-top:4px}
    }
  </style>
  </head>
<body>
  <?php
    // compute pricing breakdown
    $amenity = isset($pending['amenity']) ? $pending['amenity'] : '';
    $price   = isset($pending['price']) ? floatval($pending['price']) : 0.0;
    $downpayment = isset($pending['downpayment']) ? floatval($pending['downpayment']) : null;
    $isHourBased = in_array($amenity, ['Basketball Court','Tennis Court','Clubhouse'], true);
    $isPersonBased = in_array($amenity, ['Pool'], true);
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
        if ($amenity === 'Pool') {
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
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
  </header>
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
      <div class="break">
        <div class="row"><span class="label">Amenity</span><span class="amount"><?php echo htmlspecialchars($amenity ?: 'N/A'); ?></span></div>
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
        ?>
        <div class="row"><span class="label">Time</span><span class="amount">
          <?php
            $st = $pending['start_time'] ?? '';
            $et = $pending['end_time'] ?? '';
            echo ($st && $et) ? (format_time_ap($st) . ' – ' . format_time_ap($et)) : '--';
          ?>
        </span></div>
        <div class="row"><span class="label">Duration</span><span class="amount"><?php echo htmlspecialchars($durationText); ?></span></div>
        <div class="row"><span class="label">Persons</span><span class="amount"><?php echo intval($persons); ?></span></div>
        <div class="row"><span class="label">Total Price</span><span class="amount">₱<?php echo number_format($price, 2); ?></span></div>
        <div class="row"><span class="label">Online Payment (Partial)</span><span class="amount">₱<?php echo number_format($downpayment, 2); ?></span></div>
        <div class="row"><span class="label">Onsite Payment (Remaining)</span><span class="amount">₱<?php echo number_format($remaining, 2); ?></span></div>
        <div class="row"><span class="label">QR Reference Code</span><span class="amount"><?php echo htmlspecialchars($ref_code ?: 'N/A'); ?></span></div>
      </div>
      <form method="POST" enctype="multipart/form-data" style="margin-top:12px; display:flex; flex-direction:column; gap:10px;">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($ref_code); ?>">
        <input type="hidden" name="continue" value="<?php echo htmlspecialchars($continue); ?>">
        <input type="hidden" name="entry_pass_id" value="<?php echo intval($entry_pass_id); ?>">
        <div class="upload-area">
          <label for="receiptInput" class="label">Upload GCash Receipt (JPG, PNG, PDF • Max 5MB)</label>
          <input type="file" name="receipt" id="receiptInput" accept="image/jpeg,image/png,.pdf" required>
          <div class="upload-preview" id="uploadPreview" style="display:none"></div>
          <button type="button" class="btn btn-outline" id="removeFileBtn" disabled>Remove Selected File</button>
        </div>
        <label for="gcashReferenceNumber" class="field-label">GCash Reference Number (from receipt)</label>
        <input type="text" name="gcashreferencenumber" id="gcashReferenceNumber" class="field-input" placeholder="Enter the GCash reference number from your receipt" required inputmode="numeric" pattern="\d{13}" minlength="13" maxlength="13">
        <button type="submit" class="btn" id="confirmBtn" disabled>Confirm Payment</button>
      </form>
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
      <p style="margin:10px 0 0;color:#4b5563;font-size:.95rem;">You will need to enter your details again.</p>
      <div class="proceed-actions">
        <button type="button" class="btn btn-outline" id="backCancel">Stay</button>
        <button type="button" class="btn" id="backConfirm">Go Back</button>
      </div>
    </div>
  </div>
  <script>
    (function(){
      const input=document.getElementById('receiptInput');
      const refInput=document.getElementById('gcashReferenceNumber');
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
          reader.onload=function(e){ img.src=e.target.result; };
          reader.readAsDataURL(file);
          preview.appendChild(img);
        } else {
          const note=document.createElement('div');
          note.style.color='#cfe9d3';
          note.textContent='Selected file ready to upload.';
          preview.appendChild(note);
        }
        preview.style.display='flex';
      }
      function update(){
        const hasFile=!!(input && input.files && input.files.length>0);
        const refVal=(refInput && (refInput.value||'').trim())||'';
        const validRef=/^\d{13}$/.test(refVal) && !/^(\d)\1{12}$/.test(refVal);
        btn.disabled=!(hasFile && validRef);
        removeBtn.disabled=!hasFile;
        renderPreview(hasFile?input.files[0]:null);
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
      if(input){ input.addEventListener('change', update); }
      if(refInput){
        refInput.addEventListener('input', function(){
          const cleaned = (refInput.value || '').replace(/\D+/g, '').slice(0, 13);
          if (refInput.value !== cleaned) { refInput.value = cleaned; }
          update();
        });
      }
      if(removeBtn){ removeBtn.addEventListener('click', function(){ input.value=''; update(); }); }
      if(form){
        form.addEventListener('submit', function(e){
          if(btn.disabled || pendingSubmit) return;
          e.preventDefault();
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
      update();
    })();
  </script>
</body>
</html>
