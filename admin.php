<?php
$staffInactivityLimit = 2700;
ini_set('session.gc_maxlifetime', (string)$staffInactivityLimit);
session_start();
include 'connect.php';

$now = time();
$last = intval($_SESSION['staff_last_activity'] ?? 0);
$timeout = intval($_SESSION['staff_session_timeout'] ?? $staffInactivityLimit);
if ($last > 0 && $timeout > 0 && ($now - $last) > $timeout) {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header("Location: login.php");
    exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    $_SESSION['staff_last_activity'] = $now;
    if (!isset($_SESSION['staff_session_timeout'])) {
        $_SESSION['staff_session_timeout'] = $staffInactivityLimit;
    }
}

function admin_status_link($code){ $scheme=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on')?'https':'http'; $host=$_SERVER['HTTP_HOST']??'localhost'; $basePath=rtrim(dirname($_SERVER['SCRIPT_NAME']??'/VictorianPass'),'/'); return $scheme.'://'.$host.$basePath.'/qr_view.php?code='.urlencode($code); }
function admin_send_email($to,$subject,$body){
  if(!$to) return false;
  $fromName = getenv('MAIL_FROM_NAME') ?: 'VictorianPass';
  $fromEmail = getenv('MAIL_FROM') ?: 'noreply@victorianpass.local';
  $vendor = __DIR__ . '/vendor/autoload.php';
  $hasPHPMailer = file_exists($vendor);
  if($hasPHPMailer){
    require_once $vendor;
    if(class_exists('PHPMailer\\PHPMailer\\PHPMailer')){
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      try{
        $host = getenv('SMTP_HOST');
        if($host){
          $mail->isSMTP();
          $mail->Host = $host;
          $mail->SMTPAuth = true;
          $mail->Username = getenv('SMTP_USER') ?: '';
          $mail->Password = getenv('SMTP_PASS') ?: '';
          $secure = getenv('SMTP_SECURE') ?: 'tls';
          $mail->SMTPSecure = $secure;
          $mail->Port = intval(getenv('SMTP_PORT') ?: ($secure==='ssl'?465:587));
        }
        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $body;
        return $mail->send();
      } catch (Throwable $e) {
        return false;
      }
    }
  }
  $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ".$fromName." <".$fromEmail.">\r\n";
  return @mail($to,$subject,$body,$headers);
}
function ensureDownpaymentColumn($con){
     if(!($con instanceof mysqli)) return;
     $c = $con->query("SHOW COLUMNS FROM reservations LIKE 'downpayment'");
     if(!$c || $c->num_rows === 0){
         @$con->query("ALTER TABLE reservations ADD COLUMN downpayment DECIMAL(10,2) NULL");
     }
     $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'receipt_uploaded_at'");
     if(!$c2 || $c2->num_rows === 0){
         @$con->query("ALTER TABLE reservations ADD COLUMN receipt_uploaded_at DATETIME NULL");
     }
 }
function ensureHouseRange($con){
  if(!($con instanceof mysqli)) return;
  @$con->begin_transaction();
  @$con->query("DELETE FROM houses WHERE house_number NOT REGEXP '^VH-[0-9]{4}$' OR CAST(SUBSTRING(house_number,4) AS UNSIGNED) < 1 OR CAST(SUBSTRING(house_number,4) AS UNSIGNED) > 2220");
  $stmt = $con->prepare("INSERT IGNORE INTO houses (house_number, address) VALUES (?, ?)");
  if ($stmt) {
    $addr = 'Victorian Heights Subdivision';
    for ($i=1; $i<=2220; $i++){
      $hn = 'VH-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
      $stmt->bind_param('ss', $hn, $addr);
      $stmt->execute();
    }
    $stmt->close();
  }
  @$con->commit();
}
function ensureEmailStatusColumns($con){ if(!($con instanceof mysqli)) return; $tables=['reservations','guest_forms']; foreach($tables as $t){ $c1=$con->query("SHOW COLUMNS FROM $t LIKE 'email_sent'"); if(!$c1||$c1->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_sent TINYINT(1) NOT NULL DEFAULT 0"); } $c2=$con->query("SHOW COLUMNS FROM $t LIKE 'email_sent_at'"); if(!$c2||$c2->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_sent_at DATETIME NULL"); } $c3=$con->query("SHOW COLUMNS FROM $t LIKE 'email_error'"); if(!$c3||$c3->num_rows===0){ @$con->query("ALTER TABLE $t ADD COLUMN email_error TEXT NULL"); } }
}
function send_status_email_template($to,$code){
  if(!$to||!filter_var($to,FILTER_VALIDATE_EMAIL)) return ['ok'=>false,'err'=>'invalid_email'];
  $subject='Your VictorianPass QR Reference Code & QR Approval';
  $link=admin_status_link($code);
  $body='<div style="font-family:Poppins,Arial,sans-serif;color:#222;background:#f7f7f7;padding:20px">'
       .'<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,0.08);overflow:hidden">'
       .'<div style="background:#23412e;color:#fff;padding:16px 20px;font-weight:700">VictorianPass</div>'
       .'<div style="padding:20px">'
       .'<p style="margin:0 0 10px">Hello,</p>'
       .'<p style="margin:0 0 14px;line-height:1.6">Your payment has been confirmed, and your EntryPass QR code has been approved.</p>'
       .'<p style="margin:0 0 8px">Your QR Reference Code (VP-XXXXXX):</p>'
       .'<div style="display:inline-block;background:#f3f3f3;border:1px solid #e0e0e0;padding:12px 16px;border-radius:10px;font-weight:700">'.htmlspecialchars($code).'</div>'
       .'<p style="margin:16px 0 12px;line-height:1.6">Use this code on the Check Status page to view your reservation details and access your EntryPass QR code.</p>'
       .'<p style="margin:0 0 16px"><a href="'.htmlspecialchars($link).'" style="background:#23412e;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;display:inline-block">Open Status Page</a></p>'
       .'<p style="margin:18px 0 0;color:#555">Thank you for using VictorianPass.</p>'
       .'</div>'
       .'</div>'
       .'</div>';
  $ok=admin_send_email($to,$subject,$body);
  return ['ok'=>$ok,'err'=>$ok?null:'send_failed'];
}

function should_send_status_email($con,$refCode){
  if(!$refCode || !($con instanceof mysqli)) return false;
  $stmt=$con->prepare("SELECT approval_status, COALESCE(email_sent,0) AS email_sent FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmt->bind_param('s',$refCode);
  $stmt->execute();
  $res=$stmt->get_result();
  $row=$res?$res->fetch_assoc():null;
  $stmt->close();
  $appr=strtolower($row['approval_status']??'');
  $sent=intval($row['email_sent']??0);
  return ($appr==='approved' && $sent===0);
}

function isAmenityPaymentVerified($con, $refCode){
  if(!$refCode) return false;
  $stmt = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
  $stmt->bind_param('s', $refCode);
  $stmt->execute();
  $res = $stmt->get_result();
  $row = $res ? $res->fetch_assoc() : null;
  $stmt->close();
  $ps = strtolower($row['payment_status'] ?? '');
  return $ps === 'verified';
}

// Ensure new guest_forms table exists for admin operations
ensureGuestFormsTable($con);
ensureGuestFormsWantsAmenityColumn($con);
ensureGuestFormsAmenityColumns($con);
ensureDenialReasonColumns($con);
ensureEmailStatusColumns($con);
ensureDownpaymentColumn($con);
ensureHouseRange($con);
ensureReceiptAttemptsColumn($con);

// Handle AJAX request for user details (admin resident profile)
if (isset($_GET['action']) && $_GET['action'] == 'get_user_details' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, sex, birthdate, house_number, address, valid_id_path, created_at, user_type, IFNULL(status,'active') as status FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for visitor details (guest_forms first, legacy fallback)
if (isset($_GET['action']) && $_GET['action'] == 'get_visitor_details' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $source = isset($_GET['source']) ? $_GET['source'] : '';
    $hasPoolBookingType = false;
    $poolCheck = $con->query("SHOW COLUMNS FROM reservations LIKE 'pool_booking_type'");
    if ($poolCheck && $poolCheck->num_rows > 0) { $hasPoolBookingType = true; }
    $poolCol = $hasPoolBookingType ? "r.pool_booking_type AS pool_booking_type" : "'' AS pool_booking_type";

    // Try new guest_forms source unless explicitly a reservation
    if ($source !== 'reservation') {
    $stmtGF = $con->prepare("SELECT gf.*, 
                                    u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name,
                                    u.email AS res_email, u.phone AS res_phone, u.house_number AS res_house_number,
                                    r.payment_status AS r_payment_status, r.price AS r_price, r.downpayment AS r_downpayment,
                                    r.amenity AS r_amenity, $poolCol, r.start_date AS r_start_date, r.end_date AS r_end_date,
                                    r.start_time AS r_start_time, r.end_time AS r_end_time,
                                    r.receipt_path AS r_receipt_path, r.receipt_attempts AS receipt_attempts,
                                    r.persons AS r_persons, r.ref_code AS r_ref_code
                             FROM guest_forms gf
                             LEFT JOIN users u ON gf.resident_user_id = u.id
                             LEFT JOIN reservations r ON r.ref_code = gf.ref_code
                             WHERE gf.id = ?");
    $stmtGF->bind_param('i', $id);
    $stmtGF->execute();
        $resGF = $stmtGF->get_result();
    if ($resGF && $row = $resGF->fetch_assoc()) {
        $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
        $ps = null; $refCodeChk = $row['ref_code'] ?? null;
        if ($refCodeChk) {
          $stmtPayChk = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
          $stmtPayChk->bind_param('s', $refCodeChk);
          $stmtPayChk->execute(); $rpC = $stmtPayChk->get_result();
          if($rpC && ($prC=$rpC->fetch_assoc())){ $ps = strtolower($prC['payment_status'] ?? ''); }
          $stmtPayChk->close();
        }
        
        $details = [
            'id' => intval($row['id']),
            'user_id' => isset($row['resident_user_id']) ? intval($row['resident_user_id']) : null,
            'full_name' => $row['visitor_first_name'],
            'middle_name' => $row['visitor_middle_name'],
            'last_name' => $row['visitor_last_name'],
            'sex' => $row['visitor_sex'],
            'birthdate' => $row['visitor_birthdate'],
            'contact' => $row['visitor_contact'],
            'email' => $row['visitor_email'],
            'address' => $row['resident_house'],
            'valid_id_path' => $row['valid_id_path'],
            'entry_created' => $row['created_at'],
            'amenity' => $isAmenity ? ($row['r_amenity'] ?: ($row['amenity'] ?: 'Amenity Reservation')) : 'Guest Entry',
            'start_date' => $isAmenity ? ($row['r_start_date'] ?: ($row['start_date'] ?: $row['visit_date'])) : $row['visit_date'],
            'end_date' => $isAmenity ? ($row['r_end_date'] ?: ($row['end_date'] ?: $row['visit_date'])) : $row['visit_date'],
            'start_time' => ($row['r_start_time'] ?: ($row['start_time'] ?? null)),
            'end_time' => ($row['r_end_time'] ?: ($row['end_time'] ?? null)),
            'persons' => isset($row['r_persons']) && $row['r_persons']!==null ? intval($row['r_persons']) : (!empty($row['persons']) ? intval($row['persons']) : null),
            'purpose' => $row['purpose'],
            'price' => $isAmenity ? (isset($row['price']) ? floatval($row['price']) : (isset($row['r_price']) ? floatval($row['r_price']) : null)) : null,
            'downpayment' => $isAmenity ? (isset($row['r_downpayment']) ? floatval($row['r_downpayment']) : null) : null,
            'payment_status' => isset($row['r_payment_status']) ? strtolower($row['r_payment_status']) : null,
            'pool_booking_type' => $row['pool_booking_type'] ?? '',
            'ref_code' => ($row['r_ref_code'] ?: $row['ref_code']),
            'approval_status' => $row['approval_status'],
            'approved_by' => $row['approved_by'],
            'approval_date' => $row['approval_date'],
            'res_first_name' => $row['res_first_name'],
            'res_middle_name' => $row['res_middle_name'],
            'res_last_name' => $row['res_last_name'],
            'res_house_number' => $row['res_house_number'],
            'res_phone' => $row['res_phone'],
            'res_email' => $row['res_email']
        ];
        echo json_encode(['success' => true, 'details' => $details]);
        exit;
    }
    }

    // Legacy visitor flow: reservations + entry_passes
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.id = ? AND r.entry_pass_id IS NOT NULL";
    if ($source !== 'guest_form') {
        $stmt = $con->prepare($query);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            $isAmenity = !empty($row['amenity']); $ps = strtolower($row['payment_status'] ?? '');
            
            echo json_encode(['success' => true, 'details' => $row]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Visitor details not found']);
    exit;
}

// Handle AJAX request for resident reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_resident_reservation_details' && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT r.id, r.user_id, r.ref_code, r.amenity, r.start_date, r.end_date, r.start_time, r.end_time, r.persons, r.purpose,
                                    r.created_at, r.approval_status, r.approved_by, r.approval_date,
                                    r.price, r.downpayment, r.payment_status, r.receipt_path, r.receipt_attempts, r.denial_reason, r.booking_for, r.booked_by_role, r.booked_by_name,
                                    u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.user_type,
                                    gf.id AS gf_id, gf.visitor_first_name AS guest_first_name, gf.visitor_middle_name AS guest_middle_name,
                                    gf.visitor_last_name AS guest_last_name, gf.visitor_email AS guest_email, gf.visitor_contact AS guest_contact
                             FROM reservations r
                             LEFT JOIN users u ON r.user_id = u.id
                             LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code
                             WHERE r.id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for standard amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details' && isset($_GET['id'])) {
    $reservation_id = intval($_GET['id']);
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.user_type,
                     gf.id AS gf_id, gf.visitor_first_name AS guest_first_name, gf.visitor_middle_name AS guest_middle_name,
                     gf.visitor_last_name AS guest_last_name, gf.visitor_email AS guest_email, gf.visitor_contact AS guest_contact
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code
              WHERE r.id = ? AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation details not found']);
    }
    exit;
}

// Handle AJAX request to fetch reservation by ref_code
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details_by_ref' && isset($_GET['ref'])) {
    header('Content-Type: application/json');
    $ref = trim($_GET['ref']);
    $stmt = $con->prepare("SELECT r.*, u.user_type, gf.id AS gf_id FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code WHERE r.ref_code = ? ORDER BY r.id DESC LIMIT 1");
    $stmt->bind_param('s', $ref);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        echo json_encode(['success' => true, 'id' => $row['id'], 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for resident amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_resident_reservation_details' && isset($_GET['id'])) {
    $rr_id = intval($_GET['id']);
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number, u.user_type,
                     gf.id AS gf_id, gf.visitor_first_name AS guest_first_name, gf.visitor_middle_name AS guest_middle_name,
                     gf.visitor_last_name AS guest_last_name, gf.visitor_email AS guest_email, gf.visitor_contact AS guest_contact
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code
              WHERE r.id = ? AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $rr_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resident reservation not found']);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'get_notifications') {
    $payments = getPendingPaymentCount($con);
    $awaiting = getAmenityAwaitingPaymentCount($con);
    $ready = getAmenityReadyForApprovalCount($con);
    $incidents = getOpenIncidentCount($con);
    $newreqs = getNewRequestsCount($con);
    $system = getUnreadSystemNotificationsCount($con);
    $requests = [];
    $receipts = [];
    $res = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM reservations WHERE receipt_path IS NOT NULL AND (payment_status IS NULL OR payment_status IN ('pending','pending_update')) AND (status IS NULL OR status NOT IN ('cancelled', 'deleted', 'moved_to_history')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled', 'deleted', 'moved_to_history')) ORDER BY created_at DESC LIMIT 8");
    if($res){ while($row=$res->fetch_assoc()){ $receipts[] = ['type'=>'payment','label'=>'Payment','source'=>'verify','title'=>'Receipt awaiting verification','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
    $res2 = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at, verification_date, payment_status FROM reservations WHERE receipt_path IS NOT NULL AND payment_status = 'submitted' AND (status IS NULL OR status NOT IN ('cancelled', 'deleted', 'moved_to_history')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled', 'deleted', 'moved_to_history')) ORDER BY created_at DESC LIMIT 8");
    if($res2){ while($row=$res2->fetch_assoc()){ $title = (!empty($row['verification_date'])) ? 'Receipt re-submitted' : 'Payment receipt submitted'; $receipts[] = ['type'=>'payment','label'=>'Payment','source'=>'verify','title'=>$title,'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
    $gf = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM guest_forms WHERE approval_status='pending' ORDER BY created_at DESC LIMIT 8");
    if($gf){ while($row=$gf->fetch_assoc()){ $requests[] = ['type'=>'resident_guest','label'=>"Resident’s Guest",'source'=>'guest_form','title'=>"Resident’s Guest",'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
    $rr = $con->query("SELECT r.id, r.ref_code, r.amenity, UNIX_TIMESTAMP(r.created_at) AS epoch, r.created_at, u.user_type FROM reservations r LEFT JOIN users u ON r.user_id = u.id WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL AND r.approval_status='pending' ORDER BY r.created_at DESC LIMIT 8");
    if($rr){ while($row=$rr->fetch_assoc()){ 
        $uType = ($row['user_type'] === 'visitor') ? 'visitor' : 'resident';
        $title = ($uType === 'visitor') ? 'New visitor amenity request' : 'New resident amenity request';
        $src = ($uType === 'visitor') ? 'visitor_amenity' : 'resident';
        $label = ($uType === 'visitor') ? 'Visitor' : 'Resident';
        $requests[] = ['type'=>'request','label'=>$label,'source'=>$src,'title'=>$title,'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; 
    } }
    $legacy = $con->query("SELECT r.id, r.ref_code, r.amenity, UNIX_TIMESTAMP(r.created_at) AS epoch, r.created_at FROM reservations r WHERE r.entry_pass_id IS NOT NULL AND (r.approval_status='pending' OR (r.status IS NOT NULL AND r.status='pending')) ORDER BY r.created_at DESC LIMIT 8");
    if($legacy){ while($row=$legacy->fetch_assoc()){ $requests[] = ['type'=>'request','label'=>'Visitor','source'=>'visitor','title'=>'New visitor request','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
    // Include escalated incident reports for admin notifications
    $ir = $con->query("SELECT id, status, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM incident_reports WHERE escalated_to_admin = 1 ORDER BY created_at DESC LIMIT 8");
    if($ir){ while($row=$ir->fetch_assoc()){ $requests[] = ['type'=>'incident','label'=>'Incident','source'=>'report','title'=>'Incident escalated','ref'=>null,'amenity'=>null,'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
    
    // Fetch system notifications (cancellations, etc.)
    $notifs = $con->query("SELECT id, title, message, created_at, UNIX_TIMESTAMP(created_at) AS epoch, type FROM notifications WHERE user_id IS NULL AND is_read = 0 ORDER BY created_at DESC LIMIT 8");
    if($notifs){ while($row=$notifs->fetch_assoc()){
        $msg = (string)($row['message'] ?? '');
        $ref = null;
        if (preg_match('/(?:Reservation|Amenity request|Guest request)\\s+([A-Za-z0-9\\-]+)/i', $msg, $m)) {
            $ref = $m[1];
        }
        // Rewrite "by user" to actual user type for reservation cancellations
        if ($ref && stripos($msg, 'reservation') !== false && stripos($msg, 'cancelled') !== false) {
            $who = 'resident';
            $stmtW = $con->prepare("SELECT entry_pass_id FROM reservations WHERE ref_code = ? LIMIT 1");
            if ($stmtW) {
                $stmtW->bind_param('s', $ref);
                $stmtW->execute();
                $resW = $stmtW->get_result();
                if ($resW && ($rw = $resW->fetch_assoc())) {
                    $eid = intval($rw['entry_pass_id'] ?? 0);
                    if ($eid > 0) $who = 'visitor';
                }
                $stmtW->close();
            }
            $msg = "Reservation $ref cancelled by $who.";
        }
        $requests[] = [
            'id'=>$row['id'],
            'type'=>'notification',
            'label'=>'System',
            'source'=>'system',
            'title'=>$msg,
            'ref'=>$ref,
            'amenity'=>null,
            'time'=>$row['created_at'],
            'epoch'=>intval($row['epoch'])
        ];
    } }

    $items = array_merge($receipts, $requests);
    usort($items, function($a, $b){
        $ea = isset($a['epoch']) ? intval($a['epoch']) : 0;
        $eb = isset($b['epoch']) ? intval($b['epoch']) : 0;
        if ($eb === $ea) return 0;
        return ($eb > $ea) ? 1 : -1;
    });
    header('Content-Type: application/json');
    echo json_encode([
        'payments' => $payments,
        'awaiting' => $awaiting,
        'ready' => $ready,
        'incidents' => $incidents,
        'new_requests' => $newreqs,
        'system' => $system,
        'total' => ($payments + $awaiting + $ready + $incidents + $newreqs + $system),
        'requests' => $requests,
        'receipts' => $receipts,
        'items' => array_slice($items,0,12)
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'dismiss_notification' && isset($_GET['id'])) {
    $nid = intval($_GET['id']);
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    $stmt->bind_param('i', $nid);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => true]);
    exit;
}

// Handle incident report status updates
if (isset($_POST['incident_action']) && isset($_POST['report_id'])) {
    $rid = intval($_POST['report_id']);
    $action = $_POST['incident_action'];
    $newStatus = null;
    if ($action === 'resolve') $newStatus = 'resolved';
    elseif ($action === 'reject') $newStatus = 'rejected';
    elseif ($action === 'cancel') $newStatus = 'cancelled';
    if ($newStatus) {
        $stmt = $con->prepare("UPDATE incident_reports SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $rid);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin.php?page=report");
    exit;
}

// Handle incident report deletion by admin
if (isset($_POST['incident_delete']) && isset($_POST['report_id'])) {
    $rid = intval($_POST['report_id']);
    // Delete files from disk
    $stmtF = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ?");
    $stmtF->bind_param('i', $rid);
    $stmtF->execute();
    $resF = $stmtF->get_result();
    if ($resF) {
        while ($rowF = $resF->fetch_assoc()) {
            $fp = $rowF['file_path'];
            if ($fp && file_exists($fp)) { @unlink($fp); }
        }
    }
    $stmtF->close();
    // Delete proofs and report
    $stmtD = $con->prepare("DELETE FROM incident_proofs WHERE report_id = ?");
    $stmtD->bind_param('i', $rid);
    $stmtD->execute();
    $stmtD->close();
    $stmtR = $con->prepare("DELETE FROM incident_reports WHERE id = ?");
    $stmtR->bind_param('i', $rid);
    $stmtR->execute();
    $stmtR->close();
    header("Location: admin.php?page=report");
    exit;
}

if (isset($_POST['user_action']) && isset($_POST['user_id'])) {
    $uid = intval($_POST['user_id']);
    $action = $_POST['user_action'];
    $redirectPage = $_POST['redirect_page'] ?? 'residents';

    ensureUsersStatusColumn($con);

    if ($action === 'suspend_user' || $action === 'deactivate_user') {
        $reason = trim($_POST['suspension_reason'] ?? '');
        if ($reason !== '') {
            $reason = substr($reason, 0, 255);
        } else {
            $reason = null;
        }
        $stmt = $con->prepare("UPDATE users SET status='disabled', suspension_reason = ? WHERE id = ?");
        $stmt->bind_param('si', $reason, $uid);
        $stmt->execute();
        $stmt->close();
        $msg = 'Your account has been suspended by the admin.';
        if ($reason) {
            $msg .= ' Reason: ' . $reason;
        }
        notifyUser($con, $uid, 'Account Suspended', $msg, 'warning');
        header("Location: admin.php?page=" . $redirectPage);
        exit;
    }
    if ($action === 'activate_user') {
        $stmt = $con->prepare("UPDATE users SET status='active', suspension_reason = NULL WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->close();
        $msg = 'Your account has been activated. You can now access your account.';
        notifyUser($con, $uid, 'Account Activated', $msg, 'success');
        header("Location: admin.php?page=" . $redirectPage);
        exit;
    }
    if ($action === 'delete_user') {
        $con->begin_transaction();
        try {
            $stmt1 = $con->prepare("UPDATE reservations SET user_id = NULL WHERE user_id = ?");
            $stmt1->bind_param('i', $uid);
            $stmt1->execute();
            $stmt1->close();
            
            // Clear related references to avoid FK or logical constraints
            $stmtGF = $con->prepare("UPDATE guest_forms SET resident_user_id = NULL WHERE resident_user_id = ?");
            if ($stmtGF) { $stmtGF->bind_param('i', $uid); $stmtGF->execute(); $stmtGF->close(); }
            $stmtN = $con->prepare("UPDATE notifications SET user_id = NULL WHERE user_id = ?");
            if ($stmtN) { $stmtN->bind_param('i', $uid); $stmtN->execute(); $stmtN->close(); }
            $stmtIR = $con->prepare("UPDATE incident_reports SET user_id = NULL WHERE user_id = ?");
            if ($stmtIR) { $stmtIR->bind_param('i', $uid); $stmtIR->execute(); $stmtIR->close(); }
            $stmtRR = $con->prepare("DELETE FROM resident_reservations WHERE user_id = ?");
            if ($stmtRR) { $stmtRR->bind_param('i', $uid); $stmtRR->execute(); $stmtRR->close(); }
            
            $stmt2 = $con->prepare("DELETE FROM users WHERE id = ?");
            $stmt2->bind_param('i', $uid);
            $stmt2->execute();
            $stmt2->close();
            
            $con->commit();
        } catch (Exception $e) {
            $con->rollback();
        }
        header("Location: admin.php?page=" . $redirectPage);
        exit;
    }
}

// Ensure admin session based on existing login.php (role-based)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_email = $_SESSION['email'] ?? '';
$admin_role = $_SESSION['role'] ?? '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Functions to get dashboard statistics
function getResidentCount($con) {
    $query = "SELECT COUNT(*) as count FROM users WHERE user_type = 'resident'";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getActivePassesCount($con) {
    // Assuming you have a passes table or similar
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations WHERE end_date >= CURDATE()";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPendingRequestsCount($con) {
    // Pending requests across all sources
    $total = 0;
    $q1 = "SELECT COUNT(*) AS c FROM reservations WHERE approval_status = 'pending'";
    if ($r1 = $con->query($q1)) { if ($row = $r1->fetch_assoc()) { $total += intval($row['c']); } }

    $q2 = "SELECT COUNT(*) AS c FROM resident_reservations WHERE approval_status = 'pending'";
    if ($r2 = $con->query($q2)) { if ($row = $r2->fetch_assoc()) { $total += intval($row['c']); } }

    $q3 = "SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status = 'pending'";
    if ($r3 = $con->query($q3)) { if ($row = $r3->fetch_assoc()) { $total += intval($row['c']); } }

    return $total;
}

function getPendingResidentRequestsCountNew($con) {
    $q = "
      SELECT COUNT(DISTINCT COALESCE(NULLIF(r.ref_code,''), CONCAT('res-', r.id))) AS c
      FROM reservations r
      LEFT JOIN users u ON r.user_id = u.id
      WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)
        AND r.amenity IS NOT NULL
        AND u.user_type = 'resident'
        AND (r.booking_for IS NULL OR r.booking_for = 'resident')
        AND (r.approval_status IS NULL OR TRIM(LOWER(r.approval_status)) IN ('', 'pending'))
        AND (r.status IS NULL OR TRIM(LOWER(r.status)) IN ('', 'pending'))
    ";
    if ($r = $con->query($q)) { if ($row = $r->fetch_assoc()) { return intval($row['c']); } }
    return 0;
}

function getPendingVisitorRequestsCountNew($con) {
    $q = "
      SELECT COUNT(DISTINCT COALESCE(NULLIF(r.ref_code,''), CONCAT('res-', r.id))) AS c
      FROM reservations r
      JOIN users u ON r.user_id = u.id
      WHERE (r.approval_status IS NULL OR TRIM(LOWER(r.approval_status)) IN ('', 'pending'))
        AND (r.status IS NULL OR TRIM(LOWER(r.status)) IN ('', 'pending'))
        AND u.user_type = 'visitor'
    ";
    if ($r = $con->query($q)) { if ($row = $r->fetch_assoc()) { return intval($row['c']); } }
    return 0;
}

function getVisitorAccountsCount($con) {
    $q = "SELECT COUNT(*) AS c FROM users WHERE user_type = 'visitor'";
    if ($r = $con->query($q)) {
        if ($row = $r->fetch_assoc()) {
            return intval($row['c']);
        }
    }
    return 0;
}

function getPendingResidentAccountsCount($con) {
    $q = "SELECT COUNT(*) AS c FROM users WHERE user_type = 'resident' AND status = 'pending'";
    if ($r = $con->query($q)) {
        if ($row = $r->fetch_assoc()) {
            return intval($row['c']);
        }
    }
    return 0;
}

function getPaymentReceiptsCount($con) {
    // Count verified payments
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations WHERE payment_status = 'verified'";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPendingPaymentCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE receipt_path IS NOT NULL AND (payment_status IS NULL OR payment_status IN ('pending','pending_update')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q);
  if($r){ $row = $r->fetch_assoc(); if($row){ return intval($row['c']); } }
  return 0;
}
function getAmenityAwaitingPaymentCount($con){
  $q = "SELECT COUNT(*) AS c
        FROM guest_forms gf
        LEFT JOIN reservations r ON r.ref_code = gf.ref_code
        WHERE gf.amenity IS NOT NULL AND gf.approval_status = 'pending'
          AND (r.payment_status IS NULL OR r.payment_status <> 'verified')
          AND (gf.approval_status IS NULL OR gf.approval_status NOT IN ('cancelled','moved_to_history','permission_granted'))
          AND (r.status IS NULL OR r.status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getAmenityReadyForApprovalCount($con){
  $q = "SELECT COUNT(*) AS c
        FROM guest_forms gf
        LEFT JOIN reservations r ON r.ref_code = gf.ref_code
        WHERE gf.amenity IS NOT NULL AND gf.approval_status = 'pending'
          AND r.payment_status = 'verified'
          AND (gf.approval_status IS NULL OR gf.approval_status NOT IN ('cancelled','moved_to_history','permission_granted'))
          AND (r.status IS NULL OR r.status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getOpenIncidentCount($con){
  $q = "SELECT COUNT(*) AS c FROM incident_reports WHERE escalated_to_admin = 1 AND status IN ('new','in_progress')";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingResidentAmenityCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND approval_status='pending' AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingGuestFormCount($con){
  $q = "SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status='pending' AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingVisitorLegacyCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL AND (approval_status='pending' OR (status IS NOT NULL AND status='pending')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history','permission_granted'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getNewRequestsCount($con){
  return getPendingResidentAmenityCount($con) + getPendingGuestFormCount($con) + getPendingVisitorLegacyCount($con);
}
function getUnreadSystemNotificationsCount($con){
  $q = "SELECT COUNT(*) AS c FROM notifications WHERE user_id IS NULL AND is_read = 0";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getRecentNotifications($con){
  $items = [];
  $res = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM reservations WHERE receipt_path IS NOT NULL AND (payment_status IS NULL OR payment_status IN ('pending','pending_update')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history','permission_granted')) ORDER BY created_at DESC LIMIT 5");
  if($res){ while($row=$res->fetch_assoc()){ $items[] = ['type'=>'payment','source'=>'verify','title'=>'Receipt awaiting verification','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $gf = $con->query("SELECT id, ref_code, amenity, UNIX_TIMESTAMP(created_at) AS epoch, created_at FROM guest_forms WHERE amenity IS NOT NULL AND approval_status='pending' AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) ORDER BY created_at DESC LIMIT 5");
  if($gf){ while($row=$gf->fetch_assoc()){ $items[] = ['type'=>'resident_guest','label'=>"Resident’s Guest",'source'=>'guest_form','title'=>"Resident’s Guest",'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $gf2 = $con->query("SELECT gf.id, gf.ref_code, gf.amenity, UNIX_TIMESTAMP(gf.created_at) AS epoch, gf.created_at FROM guest_forms gf LEFT JOIN reservations r ON r.ref_code = gf.ref_code WHERE gf.amenity IS NOT NULL AND gf.approval_status='pending' AND r.payment_status='verified' AND (gf.approval_status IS NULL OR gf.approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','moved_to_history','permission_granted')) ORDER BY gf.created_at DESC LIMIT 5");
  if($gf2){ while($row=$gf2->fetch_assoc()){ $items[] = ['type'=>'resident_guest','label'=>"Resident’s Guest",'source'=>'guest_form','title'=>"Resident’s Guest",'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $rr = $con->query("SELECT r.id, r.ref_code, r.amenity, UNIX_TIMESTAMP(r.created_at) AS epoch, r.created_at, u.user_type FROM reservations r LEFT JOIN users u ON r.user_id = u.id WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL AND r.approval_status='pending' AND (r.approval_status IS NULL OR r.approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','moved_to_history','permission_granted')) ORDER BY r.created_at DESC LIMIT 5");
  if($rr){ while($row=$rr->fetch_assoc()){ 
      $uType = ($row['user_type'] === 'visitor') ? 'visitor' : 'resident';
      $title = ($uType === 'visitor') ? 'New visitor amenity request' : 'New resident amenity request';
      $src = ($uType === 'visitor') ? 'visitor_amenity' : 'resident';
      $items[] = ['type'=>'request','source'=>$src,'title'=>$title,'ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; 
  } }
  $legacy = $con->query("SELECT r.id, r.ref_code, r.amenity, UNIX_TIMESTAMP(r.created_at) AS epoch, r.created_at FROM reservations r WHERE r.entry_pass_id IS NOT NULL AND (r.approval_status='pending' OR (r.status IS NOT NULL AND r.status='pending')) AND (r.approval_status IS NULL OR r.approval_status NOT IN ('cancelled','moved_to_history','permission_granted')) AND (r.status IS NULL OR r.status NOT IN ('cancelled','moved_to_history','permission_granted')) ORDER BY r.created_at DESC LIMIT 5");
  if($legacy){ while($row=$legacy->fetch_assoc()){ $items[] = ['type'=>'request','source'=>'visitor','title'=>'New visitor request','ref'=>$row['ref_code'],'amenity'=>$row['amenity'],'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; } }
  $ir = $con->query("SELECT id, complainant, created_at, status FROM incident_reports WHERE escalated_to_admin = 1 ORDER BY created_at DESC LIMIT 5");
  if($ir){ while($row=$ir->fetch_assoc()){ $items[] = ['type'=>'incident','source'=>'report','title'=>'Incident escalated','ref'=>null,'amenity'=>null,'time'=>$row['created_at'],'epoch'=>intval(strtotime($row['created_at']))]; } }
  $notifs = $con->query("SELECT id, title, message, created_at, UNIX_TIMESTAMP(created_at) AS epoch, type FROM notifications WHERE user_id IS NULL AND is_read = 0 ORDER BY created_at DESC LIMIT 5");
  if($notifs){ while($row=$notifs->fetch_assoc()){
      $msg = (string)($row['message'] ?? '');
      $ref = null;
      if (preg_match('/(?:Reservation|Amenity request|Guest request)\\s+([A-Za-z0-9\\-]+)/i', $msg, $m)) {
          $ref = $m[1];
      }
      if ($ref && stripos($msg, 'reservation') !== false && stripos($msg, 'cancelled') !== false) {
          $who = 'resident';
          $stmtW = $con->prepare("SELECT entry_pass_id FROM reservations WHERE ref_code = ? LIMIT 1");
          if ($stmtW) {
              $stmtW->bind_param('s', $ref);
              $stmtW->execute();
              $resW = $stmtW->get_result();
              if ($resW && ($rw = $resW->fetch_assoc())) {
                  $eid = intval($rw['entry_pass_id'] ?? 0);
                  if ($eid > 0) $who = 'visitor';
              }
              $stmtW->close();
          }
          $msg = "Reservation $ref cancelled by $who.";
      }
      $items[] = ['id'=>$row['id'], 'type'=>'notification','source'=>'system','title'=>$msg,'ref'=>$ref,'amenity'=>null,'time'=>$row['created_at'],'epoch'=>intval($row['epoch'])]; 
  } }
  usort($items, function($a, $b){
    $ea = isset($a['epoch']) ? intval($a['epoch']) : 0;
    $eb = isset($b['epoch']) ? intval($b['epoch']) : 0;
    if ($eb === $ea) return 0;
    return ($eb > $ea) ? 1 : -1;
  });
  return array_slice($items,0,8);
}

function getEntryPassesCount($con){
  $q = "SELECT COUNT(*) AS c FROM entry_passes";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getReservationsTotalCount($con){
  $q = "SELECT COUNT(*) AS c FROM reservations";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getResidentAmenityReservationsTotal($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getVisitorLegacyRequestsTotal($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getGuestFormsTotal($con){
  $q = "SELECT COUNT(*) AS c FROM guest_forms";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getIncidentReportsTotal($con){
  $q = "SELECT COUNT(*) AS c FROM incident_reports";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getPendingApprovalsSummary($con){
  return getPendingResidentAmenityCount($con) + getPendingVisitorLegacyCount($con) + getPendingGuestFormCount($con) + getOpenIncidentCount($con);
}
function getMostRequestedAmenities($con, $limit = 5){
  $lim = intval($limit);
  if ($lim <= 0) { $lim = 5; }
  $q = "SELECT amenity, SUM(cnt) AS total FROM (
          SELECT amenity, COUNT(*) AS cnt FROM reservations WHERE amenity IS NOT NULL AND amenity <> '' GROUP BY amenity
          UNION ALL
          SELECT amenity, COUNT(*) AS cnt FROM resident_reservations WHERE amenity IS NOT NULL AND amenity <> '' GROUP BY amenity
          UNION ALL
          SELECT amenity, COUNT(*) AS cnt FROM guest_forms WHERE amenity IS NOT NULL AND amenity <> '' GROUP BY amenity
        ) x
        GROUP BY amenity
        ORDER BY total DESC, amenity ASC
        LIMIT ".$lim;
  $rows = [];
  if ($r = $con->query($q)) {
    while ($row = $r->fetch_assoc()) { $rows[] = $row; }
  }
  return $rows;
}
function getResidentAmenityRequestsPendingApproved($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND amenity <> '' AND approval_status IN ('pending','approved') AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getVisitorAmenityRequestsPendingApproved($con){
  $q = "SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL AND (approval_status IN ('pending','approved') OR status IN ('pending','approved')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history'))";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getGuestFormRequestsPendingApproved($con){
  $q = "SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status IN ('pending','approved')";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['c']); return 0;
}
function getTotalRequestsThisMonth($con){
  $ym = date('Y-m');
  $q = "SELECT COALESCE(SUM(c),0) AS total FROM (
          SELECT COUNT(*) AS c FROM reservations WHERE DATE_FORMAT(created_at,'%Y-%m') = '$ym'
          UNION ALL
          SELECT COUNT(*) AS c FROM resident_reservations WHERE DATE_FORMAT(created_at,'%Y-%m') = '$ym'
          UNION ALL
          SELECT COUNT(*) AS c FROM guest_forms WHERE DATE_FORMAT(created_at,'%Y-%m') = '$ym'
          UNION ALL
          SELECT COUNT(*) AS c FROM incident_reports WHERE DATE_FORMAT(created_at,'%Y-%m') = '$ym'
        ) t";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['total']); return 0;
}
function getCancelledRequestsTotal($con){
  $ym = date('Y-m');
  $q = "SELECT COALESCE(SUM(c),0) AS total FROM (
          SELECT COUNT(*) AS c FROM reservations WHERE (approval_status = 'cancelled' OR status = 'cancelled') AND DATE_FORMAT(created_at,'%Y-%m') = '$ym'
          UNION ALL
          SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status = 'cancelled' AND DATE_FORMAT(created_at,'%Y-%m') = '$ym'
          UNION ALL
          SELECT COUNT(*) AS c FROM incident_reports WHERE status = 'cancelled' AND DATE_FORMAT(created_at,'%Y-%m') = '$ym'
        ) t";
  $r = $con->query($q); if($r && ($row=$r->fetch_assoc())) return intval($row['total']); return 0;
}
function getReservationsApprovalBreakdown($con){
  $map = [];
  $q = "SELECT COALESCE(approval_status,'pending') AS s, COUNT(*) AS c FROM reservations GROUP BY s";
  if($r=$con->query($q)){ while($row=$r->fetch_assoc()){ $map[strtolower($row['s'])] = intval($row['c']); } }
  return $map;
}
function getGuestFormsApprovalBreakdown($con){
  $map = [];
  $q = "SELECT COALESCE(approval_status,'pending') AS s, COUNT(*) AS c FROM guest_forms GROUP BY s";
  if($r=$con->query($q)){ while($row=$r->fetch_assoc()){ $map[strtolower($row['s'])] = intval($row['c']); } }
  return $map;
}
function getIncidentStatusBreakdown($con){
  $map = [];
  $q = "SELECT COALESCE(status,'new') AS s, COUNT(*) AS c FROM incident_reports GROUP BY s";
  if($r=$con->query($q)){ while($row=$r->fetch_assoc()){ $map[strtolower($row['s'])] = intval($row['c']); } }
  return $map;
}
function getPaymentStatusBreakdown($con){
  $map = [];
  $q = "SELECT COALESCE(payment_status,'pending') AS s, COUNT(*) AS c FROM reservations GROUP BY s";
  if($r=$con->query($q)){ while($row=$r->fetch_assoc()){ $map[strtolower($row['s'])] = intval($row['c']); } }
  return $map;
}

function formatGuardNameFromEmail($email){
  $local = explode('@', $email)[0] ?? '';
  $s = $local;
  if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
  if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
  $s = preg_replace('/[^a-zA-Z]/', '', $s);
  $surname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
  return $surname;
}
function getGuestFormsActivity($con){
  $q = "SELECT gf.id, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name, gf.created_at, gf.approval_status,
               u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name
        FROM guest_forms gf
        LEFT JOIN users u ON gf.resident_user_id = u.id
        ORDER BY gf.created_at DESC
        LIMIT 50";
  $r = $con->query($q);
  return $r ?: false;
}
function getReservationsActivity($con){
  $q = "SELECT r.id, r.ref_code, r.amenity, r.approval_status, r.approval_date, r.booked_by_name, r.booked_by_role, r.booking_for, r.created_at,
               u.first_name, u.middle_name, u.last_name,
               ep.full_name, ep.middle_name AS ep_middle, ep.last_name AS ep_last
        FROM reservations r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN entry_passes ep ON r.entry_pass_id = ep.id
        WHERE r.approval_status IN ('approved','denied')
        ORDER BY COALESCE(r.approval_date, r.created_at) DESC
        LIMIT 50";
  $r = $con->query($q);
  return $r ?: false;
}
function getIncidentReportsActivity($con){
  $q = "SELECT ir.id, ir.complainant, ir.nature, ir.other_concern, ir.created_at,
               u.first_name, u.middle_name, u.last_name,
               s.email AS guard_email
        FROM incident_reports ir
        LEFT JOIN users u ON ir.user_id = u.id
        LEFT JOIN staff s ON s.id = ir.escalated_by_guard_id
        ORDER BY ir.created_at DESC
        LIMIT 50";
  $r = $con->query($q);
  return $r ?: false;
}

function getPaymentActivity($con){
  $q = "SELECT r.ref_code, r.gcash_reference_number, r.account_type, r.entry_pass_id, r.user_id,
               r.receipt_uploaded_at, r.created_at, r.payment_status,
               u.user_type
        FROM reservations r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.receipt_path IS NOT NULL
           OR r.payment_status IN ('submitted','verified','rejected','pending_update')
        ORDER BY COALESCE(r.receipt_uploaded_at, r.created_at) DESC
        LIMIT 50";
  $r = $con->query($q);
  return $r ?: false;
}

function normalizeMonthValue($m){
  $m = preg_replace('/[^0-9\-]/', '', (string)$m);
  if (!preg_match('/^\d{4}\-\d{2}$/', $m)) { $m = date('Y-m'); }
  return $m;
}

function getMonthRange($month){
  $m = normalizeMonthValue($month);
  $start = $m . '-01 00:00:00';
  $end = date('Y-m-t 23:59:59', strtotime($start));
  $label = date('F Y', strtotime($start));
  return ['month' => $m, 'start' => $start, 'end' => $end, 'label' => $label];
}

function getMonthlyResidentAmenityCounts($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $sql = "SELECT amenity, SUM(cnt) AS total FROM (
            SELECT r.amenity, COUNT(*) AS cnt
            FROM reservations r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.amenity IS NOT NULL AND r.amenity <> ''
              AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)
              AND (u.user_type = 'resident' OR u.user_type IS NULL)
              AND LOWER(TRIM(COALESCE(r.approval_status, r.status))) = 'approved'
              AND (r.status IS NULL OR LOWER(TRIM(r.status)) NOT IN ('cancelled','deleted','moved_to_history'))
              AND (r.approval_status IS NULL OR LOWER(TRIM(r.approval_status)) NOT IN ('cancelled','denied','deleted','moved_to_history'))
              AND COALESCE(r.approval_date, r.created_at) BETWEEN ? AND ?
            GROUP BY r.amenity
            UNION ALL
            SELECT rr.amenity, COUNT(*) AS cnt
            FROM resident_reservations rr
            WHERE rr.amenity IS NOT NULL AND rr.amenity <> ''
              AND LOWER(TRIM(rr.approval_status)) = 'approved'
              AND COALESCE(rr.approval_date, rr.created_at) BETWEEN ? AND ?
            GROUP BY rr.amenity
          ) x
          GROUP BY amenity
          ORDER BY total DESC, amenity ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ssss', $start, $end, $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyVisitorAmenityCounts($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $sql = "SELECT r.amenity, COUNT(*) AS total
          FROM reservations r
          LEFT JOIN users u ON r.user_id = u.id
          WHERE r.amenity IS NOT NULL AND r.amenity <> ''
            AND ((r.entry_pass_id IS NOT NULL AND r.entry_pass_id <> 0) OR u.user_type = 'visitor' OR r.account_type = 'visitor')
            AND LOWER(TRIM(COALESCE(r.approval_status, r.status))) = 'approved'
            AND (r.status IS NULL OR LOWER(TRIM(r.status)) NOT IN ('cancelled','deleted','moved_to_history'))
            AND (r.approval_status IS NULL OR LOWER(TRIM(r.approval_status)) NOT IN ('cancelled','denied','deleted','moved_to_history'))
            AND COALESCE(r.approval_date, r.created_at) BETWEEN ? AND ?
          GROUP BY r.amenity
          ORDER BY total DESC, r.amenity ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyMostRequestedAmenities($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $sql = "SELECT amenity, SUM(cnt) AS total FROM (
            SELECT r.amenity, COUNT(*) AS cnt
            FROM reservations r
            WHERE r.amenity IS NOT NULL AND r.amenity <> ''
              AND LOWER(TRIM(COALESCE(r.approval_status, r.status))) = 'approved'
              AND (r.status IS NULL OR LOWER(TRIM(r.status)) NOT IN ('cancelled','deleted','moved_to_history'))
              AND (r.approval_status IS NULL OR LOWER(TRIM(r.approval_status)) NOT IN ('cancelled','denied','deleted','moved_to_history'))
              AND COALESCE(r.approval_date, r.created_at) BETWEEN ? AND ?
            GROUP BY r.amenity
            UNION ALL
            SELECT rr.amenity, COUNT(*) AS cnt
            FROM resident_reservations rr
            WHERE rr.amenity IS NOT NULL AND rr.amenity <> ''
              AND LOWER(TRIM(rr.approval_status)) = 'approved'
              AND COALESCE(rr.approval_date, rr.created_at) BETWEEN ? AND ?
            GROUP BY rr.amenity
            UNION ALL
            SELECT gf.amenity, COUNT(*) AS cnt
            FROM guest_forms gf
            WHERE gf.amenity IS NOT NULL AND gf.amenity <> ''
              AND LOWER(TRIM(gf.approval_status)) = 'approved'
              AND COALESCE(gf.approval_date, gf.created_at) BETWEEN ? AND ?
            GROUP BY gf.amenity
          ) x
          GROUP BY amenity
          ORDER BY total DESC, amenity ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ssssss', $start, $end, $start, $end, $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyApprovedGuestRequests($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $sql = "SELECT gf.ref_code, gf.amenity, gf.visit_date, gf.start_date, gf.end_date, gf.approval_date, gf.created_at,
                 gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name,
                 u.first_name, u.middle_name, u.last_name
          FROM guest_forms gf
          LEFT JOIN users u ON gf.resident_user_id = u.id
          WHERE gf.resident_user_id IS NOT NULL
            AND LOWER(TRIM(gf.approval_status)) = 'approved'
            AND COALESCE(gf.approval_date, gf.created_at) BETWEEN ? AND ?
          ORDER BY COALESCE(gf.approval_date, gf.created_at) ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyIncidentReports($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $sql = "SELECT ir.id, ir.complainant, ir.nature, ir.other_concern, ir.status, ir.created_at,
                 u.first_name, u.middle_name, u.last_name
          FROM incident_reports ir
          LEFT JOIN users u ON ir.user_id = u.id
          WHERE ir.created_at BETWEEN ? AND ?
            AND (ir.status IS NULL OR LOWER(TRIM(ir.status)) NOT IN ('cancelled','rejected'))
          ORDER BY ir.created_at ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyPaymentTransactions($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $hasPoolType = false;
  $chk = $con->query("SHOW COLUMNS FROM reservations LIKE 'pool_booking_type'");
  if ($chk && $chk->num_rows > 0) { $hasPoolType = true; }
  $poolCol = $hasPoolType ? "r.pool_booking_type" : "'' AS pool_booking_type";
  $sql = "SELECT r.ref_code, r.gcash_reference_number, r.payment_status, r.receipt_uploaded_at, r.created_at,
                 r.account_type, r.entry_pass_id, u.user_type, r.amenity, r.persons, r.price, ".$poolCol.",
                 u.first_name, u.middle_name, u.last_name,
                 ep.full_name AS ep_full_name, ep.middle_name AS ep_middle_name, ep.last_name AS ep_last_name,
                 gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name, gf.resident_user_id
          FROM reservations r
          LEFT JOIN users u ON r.user_id = u.id
          LEFT JOIN entry_passes ep ON r.entry_pass_id = ep.id
          LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code
          WHERE (r.receipt_path IS NOT NULL OR r.payment_status IN ('submitted','verified','rejected','pending_update','pending'))
            AND (r.status IS NULL OR LOWER(TRIM(r.status)) NOT IN ('cancelled','deleted','moved_to_history'))
            AND (r.approval_status IS NULL OR LOWER(TRIM(r.approval_status)) NOT IN ('cancelled','denied','deleted','moved_to_history'))
            AND COALESCE(r.receipt_uploaded_at, r.created_at) BETWEEN ? AND ?
          ORDER BY COALESCE(r.receipt_uploaded_at, r.created_at) ASC";
  $stmt = $con->prepare($sql);
  if ($stmt) {
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = $row; }
    $stmt->close();
  }
  return $rows;
}

function getMonthlyScheduledArrivals($con, $start, $end){
  $rows = [];
  if (!($con instanceof mysqli)) return $rows;
  $startDate = date('Y-m-d', strtotime($start));
  $endDate = date('Y-m-d', strtotime($end));
  $normalize = function($d){
    if (!$d) return null;
    $t = strtotime($d);
    if ($t === false) return null;
    return date('Y-m-d', $t);
  };
  $resGF = $con->query("SELECT ref_code, visitor_first_name, visitor_middle_name, visitor_last_name, visit_date, start_date, end_date, TRIM(approval_status) AS approval_status, approval_date, amenity, approved_by FROM guest_forms WHERE LOWER(TRIM(approval_status))='approved' AND approved_by IS NOT NULL");
  if ($resGF) {
    while ($r = $resGF->fetch_assoc()) {
      $nm = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $sd = $normalize($r['start_date'] ?? '') ?: $normalize($r['visit_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if (!$sd) continue;
      if ($ed < $startDate || $sd > $endDate) continue;
      $hasAmen = trim((string)($r['amenity'] ?? '')) !== '';
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => ($nm !== '' ? $nm : '-'),
        'type' => $hasAmen ? 'Resident Guest Amenity' : 'Resident Guest Entry',
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['approval_status'],
        'amenity' => $r['amenity'] ?? ''
      ];
    }
  }
  $resR = $con->query("SELECT r.ref_code, r.start_date, r.end_date, r.approval_date, TRIM(COALESCE(r.approval_status, r.status)) AS status, r.entry_pass_id, r.account_type, r.amenity, r.approved_by, e.full_name AS ep_full_name, u.first_name, u.middle_name, u.last_name, u.user_type, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE LOWER(TRIM(COALESCE(r.approval_status, r.status)))='approved' AND r.approved_by IS NOT NULL");
  if ($resR) {
    while ($r = $resR->fetch_assoc()) {
      $isVisitor = (!empty($r['entry_pass_id']) || strtolower($r['account_type'] ?? '') === 'visitor' || strtolower($r['user_type'] ?? '') === 'visitor');
      $nm = '';
      if ($isVisitor) {
        $nm = trim(($r['ep_full_name'] ?? ''));
        if ($nm === '') {
          $nm = trim(($r['visitor_first_name'] ?? '') . ' ' . ($r['visitor_middle_name'] ?? '') . ' ' . ($r['visitor_last_name'] ?? ''));
        }
      } else {
        $nm = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      }
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if (!$sd) continue;
      if ($ed < $startDate || $sd > $endDate) continue;
      $amenity = $r['amenity'] ?? '';
      $label = $isVisitor ? 'Visitor' : 'Resident';
      $type = trim((string)$amenity) !== '' ? ($label . ' Amenity') : ($label . ' Request');
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => ($nm !== '' ? $nm : '-'),
        'type' => $type,
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['status'],
        'amenity' => $amenity
      ];
    }
  }
  $resRR = $con->query("SELECT rr.ref_code, rr.start_date, rr.end_date, rr.approval_date, rr.approval_status, rr.amenity, rr.approved_by, u.first_name, u.middle_name, u.last_name, r2.account_type, r2.entry_pass_id, r2.user_id, gf2.visitor_first_name, gf2.visitor_middle_name, gf2.visitor_last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id LEFT JOIN reservations r2 ON rr.ref_code = r2.ref_code LEFT JOIN guest_forms gf2 ON rr.ref_code = gf2.ref_code WHERE LOWER(rr.approval_status)='approved' AND rr.approved_by IS NOT NULL");
  if ($resRR) {
    while ($r = $resRR->fetch_assoc()) {
      $nm = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      if ($nm === '') {
        $nm = trim(($r['visitor_first_name'] ?? '') . ' ' . ($r['visitor_middle_name'] ?? '') . ' ' . ($r['visitor_last_name'] ?? ''));
      }
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if (!$sd) continue;
      if ($ed < $startDate || $sd > $endDate) continue;
      $amenity = $r['amenity'] ?? '';
      $type = trim((string)$amenity) !== '' ? 'Resident Amenity' : 'Resident Request';
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => ($nm !== '' ? $nm : '-'),
        'type' => $type,
        'start_date' => $sd,
        'end_date' => $ed,
        'status' => $r['approval_status'],
        'amenity' => $amenity
      ];
    }
  }
  usort($rows, function($a, $b){
    $da = $a['start_date'] ?? '';
    $db = $b['start_date'] ?? '';
    if ($da === $db) return 0;
    return ($da < $db) ? -1 : 1;
  });
  return $rows;
}

function getMonthlySummaryData($con, $month){
  $range = getMonthRange($month);
  $start = $range['start'];
  $end = $range['end'];
  return [
    'month' => $range['month'],
    'label' => $range['label'],
    'start' => $start,
    'end' => $end,
    'cards' => getMonthlySummaryCards($con, $start, $end),
    'resident_amenities' => getMonthlyResidentAmenityCounts($con, $start, $end),
    'visitor_amenities' => getMonthlyVisitorAmenityCounts($con, $start, $end),
    'most_requested' => getMonthlyMostRequestedAmenities($con, $start, $end),
    'approved_guest_requests' => getMonthlyApprovedGuestRequests($con, $start, $end),
    'incident_reports' => getMonthlyIncidentReports($con, $start, $end),
    'payment_transactions' => getMonthlyPaymentTransactions($con, $start, $end),
    'scheduled_arrivals' => getMonthlyScheduledArrivals($con, $start, $end)
  ];
}

function getMonthlySummaryCards($con, $start, $end){
  $cards = [
    'resident_amenity_total' => 0,
    'visitor_amenity_total' => 0,
    'resident_activities_total' => 0,
    'most_requested_total' => 0,
    'payment_transactions_total' => 0,
    'scheduled_arrivals_total' => 0
  ];
  $residentAmenityTotal = 0;
  $visitorAmenityTotal = 0;
  $approvedGuestTotal = 0;
  $incidentTotal = 0;
  $mostRequestedTotal = 0;
  $paymentTotal = 0;
  $scheduledTotal = 0;
  if ($con instanceof mysqli) {
    $residentAmenityCounts = getMonthlyResidentAmenityCounts($con, $start, $end);
    if (!empty($residentAmenityCounts)) {
      foreach ($residentAmenityCounts as $row) { $residentAmenityTotal += intval($row['total'] ?? 0); }
    }
    $visitorAmenityCounts = getMonthlyVisitorAmenityCounts($con, $start, $end);
    if (!empty($visitorAmenityCounts)) {
      foreach ($visitorAmenityCounts as $row) { $visitorAmenityTotal += intval($row['total'] ?? 0); }
    }
    $approvedGuestRows = getMonthlyApprovedGuestRequests($con, $start, $end);
    $approvedGuestTotal = is_array($approvedGuestRows) ? count($approvedGuestRows) : 0;
    $incidentRows = getMonthlyIncidentReports($con, $start, $end);
    $incidentTotal = is_array($incidentRows) ? count($incidentRows) : 0;
    $mostRequestedRows = getMonthlyMostRequestedAmenities($con, $start, $end);
    if (!empty($mostRequestedRows)) {
      foreach ($mostRequestedRows as $row) { $mostRequestedTotal += intval($row['total'] ?? 0); }
    }
    $paymentRows = getMonthlyPaymentTransactions($con, $start, $end);
    $paymentTotal = is_array($paymentRows) ? count($paymentRows) : 0;
    $scheduledRows = getMonthlyScheduledArrivals($con, $start, $end);
    $scheduledTotal = is_array($scheduledRows) ? count($scheduledRows) : 0;
  }
  $cards['resident_amenity_total'] = $residentAmenityTotal;
  $cards['visitor_amenity_total'] = $visitorAmenityTotal;
  $cards['resident_activities_total'] = $approvedGuestTotal + $incidentTotal;
  $cards['most_requested_total'] = $mostRequestedTotal;
  $cards['payment_transactions_total'] = $paymentTotal;
  $cards['scheduled_arrivals_total'] = $scheduledTotal;
  return $cards;
}

function renderVerifyReceiptsCard($con){
?>
    <div class="card-box" style="margin-top: 20px;">
      <h3>Verify Payment Receipts</h3>
      <div class="notice">Use View All Details to jump to the matching request. Verify or reject the receipt below.</div>
      <table class="table table-verify">
        <thead>
          <tr>
            <th>User Type</th>
            <th>Name</th>
            <th>Receipt</th>
            <th>Proof of Payment Upload Date</th>
            <th>Price Details</th>
            <th>Payment Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $resList = $con->query("SELECT r.id, r.ref_code, r.amenity, r.start_date, r.end_date, r.payment_status, r.receipt_path, r.entry_pass_id, r.receipt_attempts, r.denial_reason,
                                           r.price, r.downpayment, r.created_at, r.receipt_uploaded_at,
                                           ep.full_name, ep.middle_name, ep.last_name,
                                           u.first_name AS res_first_name, u.last_name AS res_last_name, u.user_type,
                                           gf.id AS gf_id
                                      FROM reservations r
                                      LEFT JOIN entry_passes ep ON r.entry_pass_id = ep.id
                                      LEFT JOIN users u ON r.user_id = u.id
                                      LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code AND gf.resident_user_id IS NOT NULL
                                      WHERE r.receipt_path IS NOT NULL
                                      ORDER BY COALESCE(r.receipt_uploaded_at, r.created_at) DESC");
            if ($resList && $resList->num_rows > 0) {
              while ($row = $resList->fetch_assoc()) {
                echo '<tr data-ref="' . htmlspecialchars($row['ref_code'] ?? '') . '">';
                $userType = 'Resident';
                if (!empty($row['user_type'])) {
                    $userType = ucfirst($row['user_type']);
                } elseif (!empty($row['entry_pass_id'])) {
                    $userType = 'Visitor';
                }
                if (!empty($row['gf_id'])) {
                    $userType = "Resident’s Guest";
                }
                echo '<td>' . $userType . '</td>';
                $fullName = !empty($row['entry_pass_id'])
                  ? trim(($row['full_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))
                  : trim(($row['res_first_name'] ?? '') . ' ' . ($row['res_last_name'] ?? ''));
                if ($fullName === '') { $fullName = $userType; }
                echo '<td>' . htmlspecialchars($fullName) . '</td>';
                
            $ps = strtolower($row['payment_status'] ?? 'pending');
            $canVerify = $ps !== 'verified';
            if (!empty($row['receipt_path'])) {
                  $rp = $row['receipt_path'];
                  $isPdf = (bool)preg_match('/\.pdf$/i', (string)$rp);
                  if ($isPdf) {
                    echo '<td><a class="receipt-link" href="#" onclick="openReceiptModal(\'' . htmlspecialchars($rp) . '\', ' . ($canVerify ? intval($row['id']) : 0) . ', \'requests\'); return false;">Open Receipt (PDF)</a></td>';
                  } else {
                echo '<td><a class="receipt-link" href="#" onclick="openReceiptModal(\'' . htmlspecialchars($rp) . '\', ' . ($canVerify ? intval($row['id']) : 0) . ', \'requests\'); return false;"><img class="receipt-thumbnail" src="' . htmlspecialchars($rp) . '" alt="Receipt"></a></td>';
                  }
                } else {
                  echo '<td><span class="muted">No receipt</span></td>';
                }
                $uploadedAt = !empty($row['receipt_uploaded_at']) ? $row['receipt_uploaded_at'] : ($row['created_at'] ?? null);
                $uploadedStr = $uploadedAt ? date('Y-m-d H:i', strtotime($uploadedAt)) : '-';
                echo '<td>' . htmlspecialchars($uploadedStr) . '</td>';
                $tp = isset($row['price']) ? floatval($row['price']) : 0.0;
                $dpRaw = (isset($row['downpayment']) && $row['downpayment'] !== null) ? floatval($row['downpayment']) : null;
                echo '<td>';
                if ($tp > 0) {
                  $tpStr = number_format($tp, 2, '.', '');
                  $dpStr = $dpRaw !== null ? number_format($dpRaw, 2, '.', '') : '';
                  echo '<button type="button" class="btn btn-view" onclick="openPriceDetails(\''.$tpStr.'\', \''.$dpStr.'\')"><i class="fa-solid fa-eye"></i> View Price Details</button>';
                } else {
                  echo '<span class="muted">-</span>';
                }
                echo '</td>';
                $psClass = $ps==='verified' ? 'badge-approved' : ($ps==='rejected' ? 'badge-rejected' : 'badge-pending');
                $psLabel = ucwords(str_replace('_',' ', $ps));
                echo '<td><span class="badge ' . $psClass . '">' . $psLabel . '</span></td>';
                echo '<td class="actions">';
                $ref = urlencode($row['ref_code']);
                if (!empty($row['gf_id'])) {
                  $targetPage = 'resident_guest_forms';
                } else if (!empty($row['entry_pass_id']) || strtolower($row['user_type'] ?? '') === 'visitor') {
                  $targetPage = 'visitor_requests';
                } else {
                  $targetPage = 'requests';
                }
                echo "<a class='btn btn-view btn-view-details' href='admin.php?page=".$targetPage."&ref=".$ref."'><i class='fa-solid fa-eye'></i> View All Details</a>";
                if($ps!=='verified' && $ps!=='rejected'){
                  $attempts = intval($row['receipt_attempts'] ?? 0);
                  if ($attempts >= 3) {
                    echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                    echo "<input type='hidden' name='reservation_id' value='" . intval($row['id']) . "'>";
                    echo "<input type='hidden' name='action' value='deny_request'>";
                    $existingReason = trim((string)($row['denial_reason'] ?? ''));
                    $readonlyAttr = ($ps === 'pending_update') ? " readonly" : "";
                    $valueAttr = ($ps === 'pending_update' ? " value='" . htmlspecialchars($existingReason, ENT_QUOTES) . "'" : "");
                    echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                    echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                    echo "</form>";
                  } else {
                    echo '<form method="post" onsubmit="return openDenyModal(this)">';
                    echo '<input type="hidden" name="reservation_id" value="' . intval($row['id']) . '">';
                    echo '<input type="hidden" name="action" value="reject_receipt">';
                    $existingReason = trim((string)($row['denial_reason'] ?? ''));
                    $readonlyAttr = ($ps === 'pending_update') ? " readonly" : "";
                    $valueAttr = ($ps === 'pending_update' ? " value=\'' . htmlspecialchars($existingReason, ENT_QUOTES) . '\'" : "");
                    echo '<input type="hidden" name="denial_reason" class="denial-reason"' . $valueAttr . '>';
                    echo '<button type="submit" class="btn btn-reject" onclick="return openDenyModal(this.closest(\'form\'))"><i class="fa-solid fa-xmark"></i> Reject</button>';
                    echo '</form>';
                  }
                }
                echo '</td>';
                echo '</tr>';
              }
            } else {
              echo '<tr><td colspan="7" style="text-align:center;">No receipts to verify</td></tr>';
            }
          ?>
        </tbody>
      </table>
    </div>
<?php
}

// Functions to get data for different sections
function getPendingResidents($con) {
    $query = "SELECT * FROM users WHERE user_type = 'resident' AND status = 'pending' ORDER BY created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getPendingVisitors($con) {
    $query = "SELECT * FROM users WHERE user_type = 'visitor' AND status = 'pending' ORDER BY created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getVisitors($con) {
    $query = "SELECT * FROM users WHERE user_type = 'visitor' ORDER BY created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getResidents($con) {
    $query = "SELECT * FROM users WHERE user_type = 'resident' ORDER BY created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Resident amenity reservations (resident_reservations table)
function getResidentReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.house_number, u.email, u.phone, u.user_type,
                     gf.id AS gf_id
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code AND gf.resident_user_id IS NOT NULL
              WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL
              AND (r.approval_status IS NULL OR (r.approval_status != 'cancelled' AND r.approval_status != 'completed' AND r.approval_status != 'expired' AND r.approval_status != 'denied')) 
              AND (r.status IS NULL OR (r.status != 'cancelled' AND r.status != 'completed' AND r.status != 'expired' AND r.status != 'denied'))
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getResidentOnlyReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.house_number, u.email, u.phone, u.user_type,
                     gf.id AS gf_id
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code AND gf.resident_user_id IS NOT NULL
              WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL AND u.user_type = 'resident'
              AND (r.booking_for IS NULL OR r.booking_for = 'resident')
              AND (r.approval_status IS NULL OR (r.approval_status != 'cancelled' AND r.approval_status != 'completed' AND r.approval_status != 'expired' AND r.approval_status != 'permission_granted' AND r.approval_status != 'moved_to_history' AND r.approval_status != 'denied')) 
              AND (r.status IS NULL OR (r.status != 'cancelled' AND r.status != 'completed' AND r.status != 'expired' AND r.status != 'permission_granted' AND r.status != 'moved_to_history' AND r.status != 'denied'))
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getVisitorAccountReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.house_number, u.email, u.phone, u.user_type
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              WHERE (r.entry_pass_id IS NULL OR r.entry_pass_id = 0) AND r.amenity IS NOT NULL AND u.user_type = 'visitor'
              AND (r.approval_status IS NULL OR (r.approval_status != 'cancelled' AND r.approval_status != 'completed' AND r.approval_status != 'expired' AND r.approval_status != 'permission_granted' AND r.approval_status != 'moved_to_history' AND r.approval_status != 'denied')) 
              AND (r.status IS NULL OR (r.status != 'cancelled' AND r.status != 'completed' AND r.status != 'expired' AND r.status != 'permission_granted' AND r.status != 'moved_to_history' AND r.status != 'denied'))
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

// Guest amenity reservations (reservations with entry_pass_id and amenity)
function getGuestAmenityReservations($con) {
    $query = "SELECT gf.*, gf.id AS gf_id,
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     u.house_number AS res_house_number
              FROM guest_forms gf
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.amenity IS NOT NULL
              AND (gf.approval_status IS NULL OR (gf.approval_status != 'cancelled' AND gf.approval_status != 'completed' AND gf.approval_status != 'permission_granted' AND gf.approval_status != 'moved_to_history' AND gf.approval_status != 'expired'))
              ORDER BY gf.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getSecurityGuards($con) {
    $query = "SELECT * FROM staff WHERE role = 'guard'";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getIncidentReports($con) {
    $query = "SELECT ir.*, u.first_name, u.middle_name, u.last_name, s.email AS escalated_by_email
              FROM incident_reports ir
              LEFT JOIN users u ON ir.user_id = u.id
              LEFT JOIN staff s ON s.id = ir.escalated_by_guard_id
              WHERE ir.escalated_to_admin = 1
              ORDER BY ir.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getIncidentProofs($con, $reportId) {
    $stmt = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $res = $stmt->get_result();
    $files = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) { $files[] = $row['file_path']; }
    }
    $stmt->close();
    return $files;
}

// Function to get visitor requests with personal details
function getVisitorRequests($con) {
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.entry_pass_id IS NOT NULL 
              AND (r.approval_status IS NULL OR (r.approval_status != 'cancelled' AND r.approval_status != 'completed' AND r.approval_status != 'expired'))
              AND (r.status IS NULL OR (r.status != 'cancelled' AND r.status != 'completed' AND r.status != 'expired'))
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Split visitor-related requests by source
function getResidentVisitorRequests($con) {
    // Link guest forms to reservations via ref_code; amenity only when a reservation exists
    $query = "SELECT gf.*, 
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     r.amenity AS amenity, COALESCE(r.persons, gf.persons) AS persons, 
                     u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name
              FROM guest_forms gf
              LEFT JOIN reservations r ON r.ref_code = gf.ref_code
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.resident_user_id IS NOT NULL
              AND (gf.approval_status IS NULL OR (gf.approval_status NOT IN ('cancelled','completed','deleted','moved_to_history','permission_granted')))
              ORDER BY gf.created_at DESC";
    $res = $con->query($query);
    return ($res && $res->num_rows > 0) ? $res : false;
}

function getResidentGuestAmenityReservations($con) {
    $query = "SELECT r.*, 
                     u.first_name AS res_first_name, u.last_name AS res_last_name, u.house_number AS res_house_number,
                     gf.visitor_first_name AS gf_first_name, gf.visitor_middle_name AS gf_middle_name, gf.visitor_last_name AS gf_last_name
              FROM reservations r
              JOIN guest_forms gf ON gf.ref_code = r.ref_code AND gf.resident_user_id IS NOT NULL
              JOIN users u ON gf.resident_user_id = u.id
              WHERE (r.booked_by_role IN ('guest', 'co_owner') OR r.booking_for IN ('guest', 'co_owner'))
              AND r.amenity IS NOT NULL
              AND (r.approval_status IS NULL OR (r.approval_status != 'cancelled' AND r.approval_status != 'completed' AND r.approval_status != 'expired'))
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getVisitorOnlyRequests($con) {
    $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate,
                                  ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
                           FROM reservations r
                           JOIN entry_passes ep ON r.entry_pass_id = ep.id
                           WHERE r.entry_pass_id IS NOT NULL AND (r.user_id IS NULL OR r.user_id = 0)
                           AND (r.approval_status IS NULL OR r.approval_status != 'cancelled')
                           AND (r.status IS NULL OR r.status != 'cancelled')
                           ORDER BY r.created_at DESC");
    return $legacy ?: false;
}

// Add: ensure reservations has a status column and auto-expire old reservations
function ensureReservationStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        // Create a status column with sensible defaults
        $con->query("ALTER TABLE reservations ADD COLUMN status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'");
    }
}

// Ensure column to track the date/time when a receipt was uploaded
function ensureReceiptUploadedAtColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'receipt_uploaded_at'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN receipt_uploaded_at DATETIME NULL AFTER receipt_path");
    }
}
function ensureReservationGcashReferenceColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'gcash_reference_number'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN gcash_reference_number VARCHAR(30) NULL AFTER receipt_path");
    }
}
function ensureReservationBookerColumns($con){
    if(!($con instanceof mysqli)) return;
    $c0 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booking_for'");
    if(!$c0 || $c0->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booking_for VARCHAR(50) NULL AFTER user_id");
    }
    $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_role'");
    if(!$c1 || $c1->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_role ENUM('resident','guest','co_owner') NULL AFTER booking_for");
    }
    $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'booked_by_name'");
    if(!$c2 || $c2->num_rows===0){
        @$con->query("ALTER TABLE reservations ADD COLUMN booked_by_name VARCHAR(255) NULL AFTER booked_by_role");
    }
}
function autoExpireReservations($con) {
    // Mark reservations expired when past end_date, but do not touch cancelled ones
    $con->query("UPDATE reservations SET status='expired' WHERE end_date < CURDATE() AND status NOT IN ('expired', 'cancelled')");
}

// Ensure incident-related tables exist to prevent runtime errors
function ensureIncidentTables($con) {
    $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      complainant VARCHAR(150) NOT NULL,
      address VARCHAR(255) NOT NULL,
      nature VARCHAR(255) NULL,
      other_concern VARCHAR(255) NULL,
      user_id INT NULL,
      status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_status (status),
      INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB");

    $con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_report_id (report_id)
    ) ENGINE=InnoDB");

    $chkStatus = $con->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incident_reports' AND COLUMN_NAME = 'status' LIMIT 1");
    if ($chkStatus) {
        $row = $chkStatus->fetch_assoc();
        $colType = $row['COLUMN_TYPE'] ?? '';
        if (strpos($colType, "'cancelled'") === false) {
            $con->query("ALTER TABLE incident_reports MODIFY COLUMN status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new'");
        }
        $chkStatus->free();
    }

    // Add escalation tracking columns if missing
    $c1 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_to_admin'");
    if ($c1 && $c1->num_rows === 0) {
        $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    $c2 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_by_guard_id'");
    if ($c2 && $c2->num_rows === 0) {
        $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_by_guard_id INT NULL AFTER escalated_to_admin");
    }
    $c3 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_at'");
    if ($c3 && $c3->num_rows === 0) {
        $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_at DATETIME NULL AFTER escalated_by_guard_id");
    }
}

ensureReservationStatusColumn($con);
autoExpireReservations($con);
ensureIncidentTables($con);
ensureReceiptUploadedAtColumn($con);
ensureReservationGcashReferenceColumn($con);
ensureReservationBookerColumns($con);
// Ensure resident reservations have necessary columns
function ensureResidentApprovalColumns($con) {
    $check1 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approved_by'");
    if ($check1 && $check1->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approved_by INT NULL AFTER approval_status");
    }
    $check2 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approval_date'");
    if ($check2 && $check2->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approval_date DATETIME NULL AFTER approved_by");
    }
}
ensureResidentApprovalColumns($con);
ensureResidentReservationQrColumn($con);

// Ensure users table has a status column to support deactivation and pending approval
function ensureUsersStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'pending'");
    } else {
        // Check if enum has pending
        $row = $check->fetch_assoc();
        if (stripos($row['Type'], 'pending') === false) {
             $con->query("ALTER TABLE users MODIFY COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'pending'");
        }
    }
}
ensureUsersStatusColumn($con);
function ensureUsersSuspensionReasonColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'suspension_reason'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN suspension_reason VARCHAR(255) NULL AFTER status");
    }
}
ensureUsersSuspensionReasonColumn($con);

// Ensure notifications table exists
function ensureNotificationsTable($con) {
    $con->query("CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL COMMENT 'For residents',
        entry_pass_id INT NULL COMMENT 'For visitors',
        title VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
        INDEX idx_user_id (user_id),
        INDEX idx_is_read (is_read)
    ) ENGINE=InnoDB");
}
ensureNotificationsTable($con);

function notifyUser($con, $userId, $title, $message, $type = 'info') {
    if (!$userId) { return; }
    try {
        $stmt = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->bind_param('isss', $userId, $title, $message, $type);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {}
}

// Ensure new guest_forms table and its QR column exist
function ensureGuestFormsTable($con) {
    $con->query("CREATE TABLE IF NOT EXISTS guest_forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      resident_user_id INT NULL,
      resident_house VARCHAR(100) NULL,
      resident_email VARCHAR(150) NULL,
      visitor_first_name VARCHAR(100) NOT NULL,
      visitor_middle_name VARCHAR(100) NULL,
      visitor_last_name VARCHAR(100) NOT NULL,
      visitor_sex VARCHAR(20) NULL,
      visitor_birthdate DATE NULL,
      visitor_contact VARCHAR(50) NULL,
      visitor_email VARCHAR(150) NULL,
      valid_id_path VARCHAR(255) NULL,
      visit_date DATE NULL,
      visit_time VARCHAR(20) NULL,
      purpose VARCHAR(255) NULL,
      wants_amenity TINYINT(1) NOT NULL DEFAULT 0,
      persons INT NULL,
      ref_code VARCHAR(50) NOT NULL UNIQUE,
      approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
      approved_by INT NULL,
      approval_date DATETIME NULL,
      denial_reason TEXT NULL,
      qr_path VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_resident_user_id (resident_user_id),
      INDEX idx_ref_code (ref_code)
    ) ENGINE=InnoDB");
}

// Ensure amenity preference column exists even if table was created earlier
function ensureGuestFormsWantsAmenityColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'wants_amenity'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE guest_forms ADD COLUMN wants_amenity TINYINT(1) NOT NULL DEFAULT 0 AFTER purpose");
    }
}

function ensureGuestFormsAmenityColumns($con) {
    $cols = ['amenity','start_date','end_date','price'];
    foreach ($cols as $c) {
        $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE '".$con->real_escape_string($c)."'");
        if ($check && $check->num_rows === 0) {
            if ($c === 'amenity') $con->query("ALTER TABLE guest_forms ADD COLUMN amenity VARCHAR(100) NULL AFTER wants_amenity");
            if ($c === 'start_date') $con->query("ALTER TABLE guest_forms ADD COLUMN start_date DATE NULL AFTER amenity");
            if ($c === 'end_date') $con->query("ALTER TABLE guest_forms ADD COLUMN end_date DATE NULL AFTER start_date");
            if ($c === 'price') $con->query("ALTER TABLE guest_forms ADD COLUMN price DECIMAL(10,2) NULL AFTER persons");
        }
    }
}
function ensureDenialReasonColumns($con) {
    if (!($con instanceof mysqli)) { return; }
    $tables = ['guest_forms','reservations','resident_reservations'];
    foreach ($tables as $t) {
        $check = @$con->query("SHOW COLUMNS FROM $t LIKE 'denial_reason'");
        if ($check && $check->num_rows === 0) {
            @$con->query("ALTER TABLE $t ADD COLUMN denial_reason TEXT NULL");
        }
    }
}

function ensureReceiptAttemptsColumn($con) {
    $t = "reservations";
    $check = @$con->query("SHOW COLUMNS FROM $t LIKE 'receipt_attempts'");
    if (!$check || $check->num_rows === 0) {
        @$con->query("ALTER TABLE $t ADD COLUMN receipt_attempts INT NULL DEFAULT 0");
    }
}

function generateQrForGuestForm($con, $gfId) {
    $gfId = intval($gfId);
    if ($gfId <= 0) return;

    // Fetch guest form
    $stmt = $con->prepare("SELECT ref_code FROM guest_forms WHERE id = ?");
    $stmt->bind_param('i', $gfId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('GF-' . $gfId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/qr_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_guest_' . $gfId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE guest_forms SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $gfId);
    $stmt2->execute();
    $stmt2->close();
}

// Ensure reservations has a QR path column
function ensureReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER receipt_path");
    }
}

// Ensure resident_reservations has a QR path column
function ensureResidentReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER updated_at");
    }
}

// Generate and store QR code for a reservation
function generateQrForReservation($con, $reservationId) {
    $reservationId = intval($reservationId);
    if ($reservationId <= 0) return;

    ensureReservationQrColumn($con);
    // Fetch reservation details
    $stmt = $con->prepare("SELECT ref_code, start_date, end_date FROM reservations WHERE id = ?");
    $stmt->bind_param('i', $reservationId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RES-' . $reservationId);
    $start = isset($row['start_date']) ? $row['start_date'] : '';
    $end   = isset($row['end_date']) ? $row['end_date'] : '';

    // Build a direct status URL so scanners open the details page
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/qr_view.php?code=' . urlencode($ref);

    // Generate QR for the status link
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return; // fail silently

    $relPath = 'uploads/qr_reservation_' . $reservationId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    // Update reservation with QR path
    $stmt2 = $con->prepare("UPDATE reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $reservationId);
    $stmt2->execute();
    $stmt2->close();
}

// Generate and store QR code for a resident reservation
function generateQrForResidentReservation($con, $rrId) {
    $rrId = intval($rrId);
    if ($rrId <= 0) return;

    ensureResidentReservationQrColumn($con);

    $stmt = $con->prepare("SELECT ref_code FROM resident_reservations WHERE id = ?");
    $stmt->bind_param('i', $rrId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RR-' . $rrId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/qr_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_resident_' . $rrId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE resident_reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $rrId);
    $stmt2->execute();
    $stmt2->close();
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $denialReason = trim($_POST['denial_reason'] ?? '');
        if ($denialReason !== '') {
            $denialReason = substr($denialReason, 0, 1000);
        } else {
            $denialReason = null;
        }
        
        // Handle visitor request approval/denial (guest_forms first)
        if ($action == 'approve_request' || $action == 'deny_request') {
            $reservation_id = intval($_POST['reservation_id']);
            $approval_status = ($action == 'approve_request') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;
            $reasonToSave = ($approval_status === 'denied') ? $denialReason : null;
            $conflict = false; $amenity = ''; $start = ''; $end = ''; $st = ''; $et = '';

            // Try updating guest_forms
            $stmtGFCheck = $con->prepare("SELECT id FROM guest_forms WHERE id = ?");
            $stmtGFCheck->bind_param('i', $reservation_id);
            $stmtGFCheck->execute();
            $resGFCheck = $stmtGFCheck->get_result();
            if ($resGFCheck && $resGFCheck->num_rows > 0) {
                // Load details for conflict check (handle DBs without time columns)
                $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
                $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
                $selectFields = "amenity, start_date, end_date" . (($hasGt && $hasGt->num_rows>0)?", start_time":"") . (($hasGe && $hasGe->num_rows>0)?", end_time":"");
                $stmtInfo = $con->prepare("SELECT $selectFields FROM guest_forms WHERE id = ?");
                $stmtInfo->bind_param('i', $reservation_id);
                $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
                if($resInfo && ($row=$resInfo->fetch_assoc())){ $amenity=$row['amenity']??''; $start=$row['start_date']??''; $end=$row['end_date']??''; $st=$row['start_time']??''; $et=$row['end_time']??''; }
                $stmtInfo->close();
                if ($approval_status === 'approved' && $amenity && $start && $end) {
                    $singleDay = ($start === $end && $st && $et);
                    $cnt = 0;
                    if ($singleDay) {
                        $stmt1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                        $stmt1->bind_param('ssss', $amenity, $start, $st, $et); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
                        $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
                        if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt2->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            // No time columns; skip time-based conflict for single-day
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE 0=1");
                        }
                        $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
                        $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
                        if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt3->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE 0=1");
                        }
                        $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    } else {
                        $stmt1=$con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND start_date <= ? AND end_date >= ?");
                        $stmt1->bind_param('sss',$amenity,$end,$start); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND start_date <= ? AND end_date >= ?");
                        $stmt2->bind_param('sss',$amenity,$end,$start); $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
                        $stmt3->bind_param('sss',$amenity,$end,$start); $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    }
                    $conflict = ($cnt > 0);
                    // Do NOT override approval_status. Just warn admin via redirect if conflict.
                }
                $stmtUp = $con->prepare("UPDATE guest_forms SET approval_status = ?, approved_by = ?, approval_date = NOW(), denial_reason = ? WHERE id = ?");
                $stmtUp->bind_param('sisi', $approval_status, $staff_id, $reasonToSave, $reservation_id);
                $stmtUp->execute();
                $stmtUp->close();
                if ($approval_status === 'approved') {
                    generateQrForGuestForm($con, $reservation_id);
                }
                $notifUserId = null;
                $notifRef = null;
                $notifAmenity = null;
                $stmtNotif = $con->prepare("SELECT resident_user_id, ref_code, amenity FROM guest_forms WHERE id = ? LIMIT 1");
                $stmtNotif->bind_param('i', $reservation_id);
                $stmtNotif->execute();
                $resNotif = $stmtNotif->get_result();
                if ($resNotif && ($rowN = $resNotif->fetch_assoc())) {
                    $notifUserId = intval($rowN['resident_user_id'] ?? 0);
                    $notifRef = $rowN['ref_code'] ?? null;
                    $notifAmenity = $rowN['amenity'] ?? null;
                }
                $stmtNotif->close();
                if ($notifUserId) {
                    $title = ($approval_status === 'approved') ? 'Request Approved' : 'Request Denied';
                    $msg = ($approval_status === 'approved') ? 'Your request has been approved.' : 'Your request has been denied.';
                    if ($approval_status !== 'approved' && $denialReason) { $msg .= ' Reason: ' . $denialReason; }
                    if (!empty($notifRef)) { $msg .= ' Code: ' . $notifRef . '.'; }
                    if (!empty($notifAmenity)) { $msg .= ' Amenity: ' . $notifAmenity . '.'; }
                    notifyUser($con, $notifUserId, $title, $msg, ($approval_status === 'approved' ? 'success' : 'error'));
                }
            } else {
                // Legacy: Update reservation approval status
                // Load details for conflict check
                $stmtInfo = $con->prepare("SELECT amenity, start_date, end_date, start_time, end_time FROM reservations WHERE id = ?");
                $stmtInfo->bind_param('i', $reservation_id);
                $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
                if($resInfo && ($row=$resInfo->fetch_assoc())){ $amenity=$row['amenity']??''; $start=$row['start_date']??''; $end=$row['end_date']??''; $st=$row['start_time']??''; $et=$row['end_time']??''; }
                $stmtInfo->close();
                if ($approval_status === 'approved' && $amenity && $start && $end) {
                    $singleDay = ($start === $end && $st && $et);
                    $cnt = 0;
                    if ($singleDay) {
                        $stmt1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                        $stmt1->bind_param('ssss', $amenity, $start, $st, $et); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $hasRt = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
                        $hasRe = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
                        if ($hasRt && $hasRt->num_rows>0 && $hasRe && $hasRe->num_rows>0) {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND ? BETWEEN start_date AND end_date AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt2->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE 0=1");
                        }
                        $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $hasGt = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
                        $hasGe = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
                        if ($hasGt && $hasGt->num_rows>0 && $hasGe && $hasGe->num_rows>0) {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND ? BETWEEN start_date AND end_date AND (approval_status IN ('pending','approved')) AND (TIME(?) < end_time AND TIME(?) > start_time)");
                            $stmt3->bind_param('ssss',$amenity,$start,$st,$et);
                        } else {
                            $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE 0=1");
                        }
                        $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    } else {
                        $stmt1=$con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE amenity = ? AND (approval_status IS NULL OR approval_status IN ('pending','approved')) AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history')) AND start_date <= ? AND end_date >= ?");
                        $stmt1->bind_param('sss',$amenity,$end,$start); $stmt1->execute(); $r1=$stmt1->get_result(); $cnt+=($r1 && ($rw=$r1->fetch_assoc()))?intval($rw['c']):0; $stmt1->close();
                        $stmt2=$con->prepare("SELECT COUNT(*) AS c FROM resident_reservations WHERE amenity = ? AND approval_status IN ('pending','approved') AND start_date <= ? AND end_date >= ?");
                        $stmt2->bind_param('sss',$amenity,$end,$start); $stmt2->execute(); $r2=$stmt2->get_result(); $cnt+=($r2 && ($rw=$r2->fetch_assoc()))?intval($rw['c']):0; $stmt2->close();
                        $stmt3=$con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE amenity = ? AND start_date <= ? AND end_date >= ? AND (approval_status IN ('pending','approved'))");
                        $stmt3->bind_param('sss',$amenity,$end,$start); $stmt3->execute(); $r3=$stmt3->get_result(); $cnt+=($r3 && ($rw=$r3->fetch_assoc()))?intval($rw['c']):0; $stmt3->close();
                    }
                    $conflict = ($cnt > 0);
                    // Do NOT override approval_status. Just warn admin via redirect if conflict.
                }
                // Enforce payment verification before acting on amenity requests
                $stmtCheck = $con->prepare("SELECT amenity, payment_status, ref_code FROM reservations WHERE id = ? LIMIT 1");
                $stmtCheck->bind_param('i', $reservation_id); $stmtCheck->execute(); $resChk = $stmtCheck->get_result();
                $refCodeRes = null; $psRes = null; $amenRes = null;
                if($resChk && ($rwC=$resChk->fetch_assoc())){ $amenRes = $rwC['amenity'] ?? ''; $psRes = strtolower($rwC['payment_status'] ?? ''); $refCodeRes = $rwC['ref_code'] ?? null; }
                $stmtCheck->close();
                if (!empty($amenRes) && $psRes !== 'verified') {
                  header("Location: admin.php?page=visitor_requests&msg=payment_required");
                  exit;
                }
                $query = "UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW(), denial_reason = ? WHERE id = ?";
                $stmt = $con->prepare($query);
                $stmt->bind_param("sisi", $approval_status, $staff_id, $reasonToSave, $reservation_id);
                $stmt->execute();
                $stmt->close();
                if ($approval_status === 'approved') {
                    generateQrForReservation($con, $reservation_id);
                }
                $notifUserId = null;
                $notifRef = null;
                $notifAmenity = null;
                $stmtNotif = $con->prepare("SELECT user_id, ref_code, amenity FROM reservations WHERE id = ? LIMIT 1");
                $stmtNotif->bind_param('i', $reservation_id);
                $stmtNotif->execute();
                $resNotif = $stmtNotif->get_result();
                if ($resNotif && ($rowN = $resNotif->fetch_assoc())) {
                    $notifUserId = intval($rowN['user_id'] ?? 0);
                    $notifRef = $rowN['ref_code'] ?? null;
                    $notifAmenity = $rowN['amenity'] ?? null;
                }
                $stmtNotif->close();
                if ($notifUserId) {
                    $title = ($approval_status === 'approved') ? 'Reservation Approved' : 'Reservation Denied';
                    $msg = ($approval_status === 'approved') ? 'Your reservation has been approved.' : 'Your reservation has been denied.';
                    if ($approval_status !== 'approved' && $denialReason) { $msg .= ' Reason: ' . $denialReason; }
                    if (!empty($notifRef)) { $msg .= ' Code: ' . $notifRef . '.'; }
                    if (!empty($notifAmenity)) { $msg .= ' Amenity: ' . $notifAmenity . '.'; }
                    notifyUser($con, $notifUserId, $title, $msg, ($approval_status === 'approved' ? 'success' : 'error'));
                }
            }

            $redir = isset($_POST['redirect_page']) ? preg_replace('/[^a-z_]/', '', $_POST['redirect_page']) : 'visitor_requests';
            header("Location: admin.php?page=" . $redir . ($conflict ? "&msg=time_conflict" : ""));
            exit;
        }
        
        // Handle reservation approval/rejection
        if ($action == 'approve_reservation' || $action == 'reject_reservation') {
            $reservation_id = $_POST['reservation_id'];
            $status = ($action == 'approve_reservation') ? 'approved' : 'rejected';
            $reasonToSave = ($status === 'rejected') ? $denialReason : null;
            
            // Update reservation status (column ensured above)
            $query = "UPDATE reservations SET status = ?, denial_reason = ? WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("ssi", $status, $reasonToSave, $reservation_id);
            $stmt->execute();

            // Generate QR code upon approval
            if ($status === 'approved') {
                generateQrForReservation($con, intval($reservation_id));
            }
            $notifUserId = null;
            $notifRef = null;
            $notifAmenity = null;
            $stmtNotif = $con->prepare("SELECT user_id, ref_code, amenity FROM reservations WHERE id = ? LIMIT 1");
            $stmtNotif->bind_param('i', $reservation_id);
            $stmtNotif->execute();
            $resNotif = $stmtNotif->get_result();
            if ($resNotif && ($rowN = $resNotif->fetch_assoc())) {
                $notifUserId = intval($rowN['user_id'] ?? 0);
                $notifRef = $rowN['ref_code'] ?? null;
                $notifAmenity = $rowN['amenity'] ?? null;
            }
            $stmtNotif->close();
            if ($notifUserId) {
                $title = ($status === 'approved') ? 'Reservation Approved' : 'Reservation Rejected';
                $msg = ($status === 'approved') ? 'Your reservation has been approved.' : 'Your reservation has been rejected.';
                if ($status !== 'approved' && $denialReason) { $msg .= ' Reason: ' . $denialReason; }
                if (!empty($notifRef)) { $msg .= ' Code: ' . $notifRef . '.'; }
                if (!empty($notifAmenity)) { $msg .= ' Amenity: ' . $notifAmenity . '.'; }
                notifyUser($con, $notifUserId, $title, $msg, ($status === 'approved' ? 'success' : 'error'));
            }
            
            // Redirect to prevent form resubmission
            $redirect_page = $_POST['redirect_page'] ?? 'requests';
            header("Location: admin.php?page=" . $redirect_page);
            exit;
        }

        // Handle deletion of denied/rejected reservations or guest_forms
        if ($action == 'delete_reservation') {
            $reservation_id = intval($_POST['reservation_id'] ?? 0);
            if ($reservation_id > 0) {
                // Prefer guest_forms
                $stmtGF = $con->prepare("SELECT approval_status, ref_code FROM guest_forms WHERE id = ?");
                $stmtGF->bind_param('i', $reservation_id);
                $stmtGF->execute();
                $resGF = $stmtGF->get_result();
                $stmtGF->close();
                if ($resGF && $rowGF = $resGF->fetch_assoc()) {
                    $status = strtolower($rowGF['approval_status'] ?? '');
                    if ($status === 'denied' || $status === 'cancelled') {
                        $refCode = $rowGF['ref_code'] ?? '';
                        $stmtDelGF = $con->prepare("DELETE FROM guest_forms WHERE id = ?");
                        $stmtDelGF->bind_param('i', $reservation_id);
                        $stmtDelGF->execute();
                        $stmtDelGF->close();
                        
                        // Also delete from reservations
                        if ($refCode) {
                             $stmtDelR = $con->prepare("DELETE FROM reservations WHERE ref_code = ?");
                             $stmtDelR->bind_param('s', $refCode);
                             $stmtDelR->execute();
                             $stmtDelR->close();
                        }
                    }
                } else {
                    // Legacy reservation path
                    $stmt = $con->prepare("SELECT id, entry_pass_id, approval_status, status, ref_code FROM reservations WHERE id = ?");
                    $stmt->bind_param('i', $reservation_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $appStatus = strtolower($row['approval_status'] ?? '');
                        $stStatus = strtolower($row['status'] ?? '');
                        $isDenied = ($appStatus === 'denied' || $appStatus === 'cancelled' || $stStatus === 'rejected' || $stStatus === 'cancelled');
                        if ($isDenied) {
                            $entryId = intval($row['entry_pass_id'] ?? 0);
                            $refCode = $row['ref_code'] ?? '';
                            if ($entryId > 0) {
                                $stmtDelEP = $con->prepare("DELETE FROM entry_passes WHERE id = ?");
                                $stmtDelEP->bind_param('i', $entryId);
                                $stmtDelEP->execute();
                                $stmtDelEP->close();
                            }
                            $stmtDelR = $con->prepare("DELETE FROM reservations WHERE id = ?");
                            $stmtDelR->bind_param('i', $reservation_id);
                            $stmtDelR->execute();
                            $stmtDelR->close();
                            
                            // Also cleanup resident_reservations if exists
                            if ($refCode) {
                                $stmtDelRR = $con->prepare("DELETE FROM resident_reservations WHERE ref_code = ?");
                                $stmtDelRR->bind_param('s', $refCode);
                                $stmtDelRR->execute();
                                $stmtDelRR->close();
                            }
                        }
                    }
                    $stmt->close();
                }
            }
            $redirect_page = $_POST['redirect_page'] ?? 'requests';
            header("Location: admin.php?page=" . $redirect_page);
            exit;
        }

        // Handle user account approval/denial
        if ($action == 'approve_user' || $action == 'deny_user') {
            $user_id = intval($_POST['user_id'] ?? 0);
            $new_status = ($action == 'approve_user') ? 'active' : 'denied';
            $reasonToSave = ($action == 'deny_user') ? $denialReason : null;
            
            if ($user_id > 0) {
                $stmt = $con->prepare("UPDATE users SET status = ?, suspension_reason = ? WHERE id = ?");
                $stmt->bind_param('ssi', $new_status, $reasonToSave, $user_id);
                $stmt->execute();
                $stmt->close();
                if ($action == 'deny_user') {
                    $msg = 'Your account has been denied and suspended. Please log out.';
                    if ($denialReason) { $msg .= ' Reason: ' . $denialReason; }
                    notifyUser($con, $user_id, 'Account Denied', $msg, 'error');
                } else {
                    $msg = 'Your account has been approved. You can now log in.';
                    notifyUser($con, $user_id, 'Account Approved', $msg, 'success');
                }
            }
            // Redirect back to the same page
            $redirect_page = $_POST['redirect_page'] ?? 'dashboard';
            header("Location: admin.php?page=" . $redirect_page);
            exit;
        }

        // Handle resident reservation approval/denial (unified reservations)
        if ($action == 'approve_resident_reservation' || $action == 'deny_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            $approval_status = ($action == 'approve_resident_reservation') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;
            $reasonToSave = ($approval_status === 'denied') ? $denialReason : null;

            if ($rr_id > 0) {
                // Ensure reservations has approval metadata
                $c1 = $con->query("SHOW COLUMNS FROM reservations LIKE 'approved_by'");
                if($c1 && $c1->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN approved_by INT NULL"); }
                $c2 = $con->query("SHOW COLUMNS FROM reservations LIKE 'approval_date'");
                if($c2 && $c2->num_rows===0){ @$con->query("ALTER TABLE reservations ADD COLUMN approval_date DATETIME NULL"); }

                $stmt = $con->prepare("UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW(), denial_reason = ? WHERE id = ? AND (entry_pass_id IS NULL OR entry_pass_id = 0)");
                $stmt->bind_param('sisi', $approval_status, $staff_id, $reasonToSave, $rr_id);
                $stmt->execute();
                $stmt->close();

                if ($approval_status === 'approved') {
                    generateQrForReservation($con, $rr_id);
                }
                $notifUserId = null;
                $notifRef = null;
                $notifAmenity = null;
                $stmtNotif = $con->prepare("SELECT user_id, ref_code, amenity FROM reservations WHERE id = ? LIMIT 1");
                $stmtNotif->bind_param('i', $rr_id);
                $stmtNotif->execute();
                $resNotif = $stmtNotif->get_result();
                if ($resNotif && ($rowN = $resNotif->fetch_assoc())) {
                    $notifUserId = intval($rowN['user_id'] ?? 0);
                    $notifRef = $rowN['ref_code'] ?? null;
                    $notifAmenity = $rowN['amenity'] ?? null;
                }
                $stmtNotif->close();
                if ($notifUserId) {
                    $title = ($approval_status === 'approved') ? 'Reservation Approved' : 'Reservation Denied';
                    $msg = ($approval_status === 'approved') ? 'Your reservation has been approved.' : 'Your reservation has been denied.';
                    if ($approval_status !== 'approved' && $denialReason) { $msg .= ' Reason: ' . $denialReason; }
                    if (!empty($notifRef)) { $msg .= ' Code: ' . $notifRef . '.'; }
                    if (!empty($notifAmenity)) { $msg .= ' Amenity: ' . $notifAmenity . '.'; }
                    notifyUser($con, $notifUserId, $title, $msg, ($approval_status === 'approved' ? 'success' : 'error'));
                }
            }
            $redirect_page = $_POST['redirect_page'] ?? 'requests';
            header("Location: admin.php?page=" . $redirect_page);
            exit;
        }

        

        // Handle deletion of denied resident reservations (unified reservations)
        if ($action == 'delete_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            if ($rr_id > 0) {
                // Only allow deletion when denied or cancelled
                $stmt = $con->prepare("SELECT approval_status, ref_code FROM reservations WHERE id = ? AND (entry_pass_id IS NULL OR entry_pass_id = 0) ");
                $stmt->bind_param('i', $rr_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    $status = strtolower($row['approval_status'] ?? '');
                    if ($status === 'denied' || $status === 'cancelled') {
                        $refCode = $row['ref_code'] ?? '';
                        $stmtDel = $con->prepare("DELETE FROM reservations WHERE id = ?");
                        $stmtDel->bind_param('i', $rr_id);
                        $stmtDel->execute();
                        $stmtDel->close();
                        
                        // Also cleanup resident_reservations
                        if ($refCode) {
                            $stmtDelRR = $con->prepare("DELETE FROM resident_reservations WHERE ref_code = ?");
                            $stmtDelRR->bind_param('s', $refCode);
                            $stmtDelRR->execute();
                            $stmtDelRR->close();
                        }
                    }
                }
                $stmt->close();
            }
            $redirect_page = $_POST['redirect_page'] ?? 'requests';
            header("Location: admin.php?page=" . $redirect_page);
            exit;
        }

        // Handle receipt verification
        if ($action == 'verify_receipt' || $action == 'reject_receipt') {
            $reservation_id = $_POST['reservation_id'];
            $payment_status = ($action == 'verify_receipt') ? 'verified' : 'rejected';
            $staff_id = $_SESSION['staff_id'] ?? null;
            $reasonToSave = ($payment_status === 'rejected') ? $denialReason : null;
            
            // Update payment status
            $query = "UPDATE reservations SET payment_status = ?, verified_by = ?, verification_date = NOW(), denial_reason = ? WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("sisi", $payment_status, $staff_id, $reasonToSave, $reservation_id);
            $stmt->execute();
            if ($payment_status === 'rejected') {
              $stmtInc = $con->prepare("UPDATE reservations SET receipt_attempts = COALESCE(receipt_attempts,0) + 1 WHERE id = ?");
              $stmtInc->bind_param('i', $reservation_id);
              $stmtInc->execute();
              $stmtInc->close();
            }
            
            $refCode = null; $entryId = null; $amenityName = null; $notifUserId = null; $userType = null; $startDate = null; $startTime = null; $endTime = null; $attempts = 0;
            $stmtInfo = $con->prepare("SELECT r.ref_code, r.entry_pass_id, r.amenity, r.approval_status, r.user_id, r.start_date, r.start_time, r.end_time, r.receipt_attempts, u.user_type FROM reservations r LEFT JOIN users u ON r.user_id = u.id WHERE r.id = ? LIMIT 1");
            $stmtInfo->bind_param('i', $reservation_id);
            $stmtInfo->execute(); $resInfo = $stmtInfo->get_result();
            $approvedNow=false; $approvalStatusRes=null;
            if($resInfo && ($rw=$resInfo->fetch_assoc())){ $refCode = $rw['ref_code'] ?? null; $entryId = $rw['entry_pass_id'] ?? null; $amenityName = $rw['amenity'] ?? null; $approvalStatusRes = $rw['approval_status'] ?? null; $notifUserId = intval($rw['user_id'] ?? 0); $userType = strtolower($rw['user_type'] ?? ''); $startDate = $rw['start_date'] ?? null; $startTime = $rw['start_time'] ?? null; $endTime = $rw['end_time'] ?? null; $attempts = intval($rw['receipt_attempts'] ?? 0); }
            $stmtInfo->close();
            if ($payment_status === 'verified' && $refCode) {
            }
            if ($attempts >= 3 && $payment_status === 'rejected') {
                $stmtDeny = $con->prepare("UPDATE reservations SET approval_status = 'denied' WHERE id = ?");
                $stmtDeny->bind_param('i', $reservation_id);
                $stmtDeny->execute();
                $stmtDeny->close();
            }
            if ($notifUserId) {
                $title = ($payment_status === 'verified') ? 'Payment Verified' : 'Payment Rejected';
                if ($payment_status === 'verified') {
                  $msg = 'Your payment has been verified.';
                } else {
                  $amenityLabel = trim((string)$amenityName);
                  if ($amenityLabel !== '') { $amenityLabel = strtoupper($amenityLabel); }
                  $dateLabel = '';
                  if (!empty($startDate)) {
                    $dateLabel = date('m.d.y', strtotime($startDate));
                  }
                  $timeLabel = '';
                  if (!empty($startTime)) {
                    $timeLabel = date('h:i A', strtotime($startTime));
                    if (!empty($endTime)) {
                      $timeLabel .= ' - ' . date('h:i A', strtotime($endTime));
                    }
                  }
                  $scheduleLabel = trim($dateLabel . ($timeLabel !== '' ? ' ' . $timeLabel : ''));
                  $msg = 'Your reservation payment';
                  if ($amenityLabel !== '') { $msg .= ' for ' . $amenityLabel; }
                  if ($scheduleLabel !== '') { $msg .= ' on ' . $scheduleLabel; }
                  if ($attempts <= 1) {
                    $msg .= ' was rejected. Please upload a clear and legible payment receipt to avoid denial. You have 3 attempts. Attempt ' . max($attempts, 1) . ' of 3.';
                  } else {
                    $msg .= ' was rejected. Please update your proof of payment. Attempt ' . max($attempts, 1) . ' of 3.';
                  }
                }
                if ($payment_status !== 'verified' && $denialReason) { $msg .= ' Reason: ' . $denialReason; }
                notifyUser($con, $notifUserId, $title, $msg, ($payment_status === 'verified' ? 'success' : 'error'));
            }
            $redirectPage = isset($_POST['redirect_page']) ? preg_replace('/[^a-z_]/', '', $_POST['redirect_page']) : '';
            if (!empty($redirectPage)) {
              $redirect = 'admin.php?page=' . $redirectPage;
              if (!empty($refCode)) { $redirect .= '&ref=' . urlencode($refCode); }
            } else {
              $redirect = 'admin.php?page=requests';
              if ($payment_status === 'verified' && !empty($refCode)) {
                $stmtGF = $con->prepare("SELECT id FROM guest_forms WHERE ref_code = ? LIMIT 1");
                $stmtGF->bind_param('s', $refCode);
                $stmtGF->execute();
                $resGF = $stmtGF->get_result();
                $stmtGF->close();
                if ($resGF && $resGF->num_rows > 0) {
                  $redirect = 'admin.php?page=resident_guest_forms&ref=' . urlencode($refCode);
                } else {
                  $isVisitor = (!empty($entryId) || $userType === 'visitor');
                  $redirectPage = $isVisitor ? 'visitor_requests' : 'requests';
                  $redirect = 'admin.php?page=' . $redirectPage . '&ref=' . urlencode($refCode);
                }
              }
            }
            header("Location: $redirect");
            exit;
        }
        
        // Handle updating rejection message when payment proof is resubmitted
        if ($action === 'update_denial_reason') {
            $refCode = isset($_POST['ref_code']) ? trim($_POST['ref_code']) : '';
            $redirectUrl = isset($_POST['redirect']) ? trim($_POST['redirect']) : '';
            if ($refCode === '') {
                header("Location: admin.php?page=requests");
                exit;
            }
            $canUpdate = false;
            $stmtChk = $con->prepare("SELECT payment_status FROM reservations WHERE ref_code = ? LIMIT 1");
            $stmtChk->bind_param('s', $refCode);
            $stmtChk->execute();
            $resChk = $stmtChk->get_result();
            if ($resChk && ($rw = $resChk->fetch_assoc())) {
                $ps = strtolower(trim($rw['payment_status'] ?? ''));
                if ($ps === 'pending_update') { $canUpdate = true; }
            }
            $stmtChk->close();
            if ($canUpdate) {
                $stmtUp = $con->prepare("UPDATE reservations SET denial_reason = ? WHERE ref_code = ?");
                $stmtUp->bind_param('ss', $denialReason, $refCode);
                $stmtUp->execute();
                $stmtUp->close();
            }
            if ($redirectUrl !== '') {
                header("Location: " . $redirectUrl);
            } else {
                header("Location: admin.php?page=requests&ref=" . urlencode($refCode));
            }
            exit;
        }
    }
}

if (isset($_GET['action']) && $_GET['action'] === 'export_monthly_report' && isset($_GET['month'])) {
  $m = normalizeMonthValue($_GET['month']);
  $report = getMonthlySummaryData($con, $m);
  $monthLabel = $report['label'];
  $colName = function($i){ $s=''; $i=intval($i); while($i>=0){ $s=chr(($i%26)+65).$s; $i=intval($i/26)-1; } return $s; };
  $amenityOrder = ['Pool','Clubhouse','Basketball Court','Tennis Court'];
  $mapCounts = function($rows) use ($amenityOrder){
    $map = [];
    foreach ($amenityOrder as $a) { $map[$a] = 0; }
    if (!empty($rows)) {
      foreach ($rows as $r) {
        $a = $r['amenity'] ?? '';
        if ($a === '') continue;
        if (!isset($map[$a])) { $map[$a] = 0; }
        $map[$a] += intval($r['total'] ?? 0);
      }
    }
    $list = [];
    foreach ($map as $a => $t) { $list[] = ['amenity' => $a, 'total' => $t]; }
    return $list;
  };
  $residentList = $mapCounts($report['resident_amenities'] ?? []);
  $visitorList = $mapCounts($report['visitor_amenities'] ?? []);
  $cards = $report['cards'] ?? [];
  $residentTotal = intval($cards['resident_amenity_total'] ?? 0);
  $visitorTotal = intval($cards['visitor_amenity_total'] ?? 0);
  $totalAmenity = $residentTotal + $visitorTotal;
  $paymentTotal = intval($cards['payment_transactions_total'] ?? 0);
  $totalRevenue = 0.0;
  if (!empty($report['payment_transactions'])) {
    foreach ($report['payment_transactions'] as $r) {
      $ps = strtolower($r['payment_status'] ?? '');
      if ($ps === 'verified') { $totalRevenue += floatval($r['price'] ?? 0); }
    }
  }
  $grid = [];
  $colOffset = 2;
  $mergeCells = [];
  $setCell = function($r, $c, $v, $s = 0) use (&$grid, $colOffset){
    if (!isset($grid[$r])) { $grid[$r] = []; }
    $grid[$r][$c + $colOffset] = ['v' => $v, 's' => $s];
  };
  $merge = function($r1, $c1, $r2, $c2) use (&$mergeCells, $colName, $colOffset){
    $mergeCells[] = $colName($c1 + $colOffset - 1).$r1.':'.$colName($c2 + $colOffset - 1).$r2;
  };
  $row = 1;
  $setCell($row, 4, 'VICTORIAN HEIGHTS SUBDIVISION', 1);
  $row++;
  $setCell($row, 4, 'Victorianpass: Monthly Summary Report', 2);
  $row++;
  $setCell($row, 4, 'For the month of '.$monthLabel, 2);
  $row++;
  $setCell($row, 8, 'Date', 8);
  $setCell($row, 9, date('m/d/Y'), 9);
  $row += 2;
  $setCell($row, 1, 'Victorianpass Amenity Reservation Data', 3); $merge($row, 1, $row, 9);
  $row++;
  $setCell($row, 1, 'Resident', 3); $merge($row, 1, $row, 4);
  $setCell($row, 6, 'Visitor', 3); $merge($row, 6, $row, 9);
  $row++;
  $setCell($row, 1, 'Amenity', 4);
  $setCell($row, 2, 'Total', 4);
  $setCell($row, 6, 'Amenity', 4);
  $setCell($row, 7, 'Total', 4);
  $row++;
  $maxAmenityRows = max(count($residentList), count($visitorList));
  for ($i = 0; $i < $maxAmenityRows; $i++) {
    $rRow = $row + $i;
    $rAmen = $residentList[$i]['amenity'] ?? '';
    $rTot = $residentList[$i]['total'] ?? 0;
    $vAmen = $visitorList[$i]['amenity'] ?? '';
    $vTot = $visitorList[$i]['total'] ?? 0;
    $setCell($rRow, 1, $rAmen, 5);
    $setCell($rRow, 2, $rTot, 7);
    $setCell($rRow, 6, $vAmen, 5);
    $setCell($rRow, 7, $vTot, 7);
  }
  $row = $row + $maxAmenityRows + 1;
  $setCell($row, 1, 'Overall Summary', 3); $merge($row, 1, $row, 9);
  $row++;
  $setCell($row, 1, 'Metric', 4);
  $setCell($row, 2, 'Total', 4);
  $metrics = [
    ['Resident Amenity Reservations', $residentTotal],
    ['Visitor Amenity Reservations', $visitorTotal],
    ['Total Amenity Reservations', $totalAmenity],
    ['Payment Transactions', $paymentTotal],
    ['Total Revenue (Verified)', number_format($totalRevenue, 2, '.', '')]
  ];
  foreach ($metrics as $mRow) {
    $row++;
    $setCell($row, 1, $mRow[0], 5);
    $setCell($row, 2, $mRow[1], 7);
  }
  $row += 2;
  $setCell($row, 1, 'Most Requested Amenities', 3); $merge($row, 1, $row, 9);
  $row++;
  $setCell($row, 1, 'Amenity', 4);
  $setCell($row, 2, 'Total', 4);
  $mostRows = $report['most_requested'] ?? [];
  if (!empty($mostRows)) {
    foreach ($mostRows as $r) {
      $row++;
      $setCell($row, 1, $r['amenity'] ?? '', 5);
      $setCell($row, 2, $r['total'] ?? 0, 7);
    }
  } else {
    $row++;
    $setCell($row, 1, 'No amenity reservations', 5);
    $setCell($row, 2, '0', 7);
  }
  $row += 2;
  $setCell($row, 1, 'Transaction Data', 3); $merge($row, 1, $row, 9);
  $row++;
  $headers = ['Ref No.','Name','User Type','Amenity','Package','Pax','GCash Ref No.','Price','Date'];
  foreach ($headers as $i => $h) { $setCell($row, $i + 1, $h, 4); }
  $totalSales = 0.0;
  if (!empty($report['payment_transactions'])) {
    foreach ($report['payment_transactions'] as $r) {
      $row++;
      $userType = '';
      $name = '';
      if (!empty($r['resident_user_id'])) {
        $userType = 'Resident Guest';
        $name = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      } else {
        $isVisitor = (!empty($r['entry_pass_id']) || strtolower($r['account_type'] ?? '') === 'visitor' || strtolower($r['user_type'] ?? '') === 'visitor');
        if ($isVisitor) {
          $userType = 'Visitor';
          $name = trim(($r['ep_full_name'] ?? '').' '.($r['ep_middle_name'] ?? '').' '.($r['ep_last_name'] ?? ''));
          if ($name === '') {
            $name = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
          }
        } else {
          $userType = 'Resident';
          $name = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
        }
      }
      if ($name === '') { $name = '-'; }
      if ($userType === '') { $userType = 'Unknown'; }
      $amenity = $r['amenity'] ?? '';
      $poolType = strtolower($r['pool_booking_type'] ?? '');
      $package = '-';
      if ($amenity === 'Pool') { $package = ($poolType === 'whole_pool') ? 'Whole' : 'Shared'; }
      $pax = $r['persons'] ?? '';
      $price = isset($r['price']) ? number_format(floatval($r['price']), 2, '.', '') : '';
      $totalSales += floatval($r['price'] ?? 0);
      $dateRaw = $r['receipt_uploaded_at'] ?? $r['created_at'] ?? '';
      $date = $dateRaw ? date('m/d/Y', strtotime($dateRaw)) : '';
      $setCell($row, 1, $r['ref_code'] ?? '', 5);
      $setCell($row, 2, $name, 5);
      $setCell($row, 3, $userType, 5);
      $setCell($row, 4, $amenity, 5);
      $setCell($row, 5, $package, 5);
      $setCell($row, 6, $pax, 7);
      $setCell($row, 7, $r['gcash_reference_number'] ?? '', 5);
      $setCell($row, 8, $price, 7);
      $setCell($row, 9, $date, 5);
    }
    $row++;
    $setCell($row, 7, 'Total Sales', 6);
    $setCell($row, 8, number_format($totalSales, 2, '.', ''), 7);
  } else {
    $row++;
    $setCell($row, 1, 'No payment transactions', 5);
  }
  $maxRow = 0;
  foreach ($grid as $r => $cols) { if ($r > $maxRow) { $maxRow = $r; } }
  if (!class_exists('ZipArchive')) {
    $styleId = function($s){
      $s = intval($s);
      if ($s === 1) return 'sTitle';
      if ($s === 2) return 'sCenterBold';
      if ($s === 3) return 'sSection';
      if ($s === 4) return 'sHeader';
      if ($s === 5) return 'sCell';
      if ($s === 6) return 'sCellBold';
      if ($s === 7) return 'sCellCenter';
      if ($s === 8) return 'sRightBold';
      if ($s === 9) return 'sRight';
      return '';
    };
    $mkRow = function($rowIndex, $maxCol) use (&$grid, $styleId) {
      $out = '<Row>';
      for ($c = 1; $c <= $maxCol; $c++) {
        $val = isset($grid[$rowIndex][$c]) ? $grid[$rowIndex][$c]['v'] : '';
        $sid = isset($grid[$rowIndex][$c]) ? $styleId($grid[$rowIndex][$c]['s'] ?? 0) : '';
        $safe = htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $styleAttr = $sid !== '' ? ' ss:StyleID="'.$sid.'"' : '';
        $out .= '<Cell'.$styleAttr.'><Data ss:Type="String">'.$safe.'</Data></Cell>';
      }
      $out .= '</Row>';
      return $out;
    };
    $styles = '<Styles>'
      .'<Style ss:ID="sTitle"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1" ss:Size="16"/><Interior ss:Color="#DAF2D0" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sCenterBold"><Alignment ss:Horizontal="Center"/><Font ss:Bold="1"/></Style>'
      .'<Style ss:ID="sSection"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/><Interior ss:Color="#DAF2D0" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sHeader"><Alignment ss:Horizontal="Center" ss:Vertical="Center"/><Font ss:Bold="1"/><Interior ss:Color="#DAF2D0" ss:Pattern="Solid"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sCell"><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sCellBold"><Font ss:Bold="1"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sCellCenter"><Alignment ss:Horizontal="Center"/><Borders><Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1"/><Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1"/></Borders></Style>'
      .'<Style ss:ID="sRightBold"><Alignment ss:Horizontal="Right"/><Font ss:Bold="1"/></Style>'
      .'<Style ss:ID="sRight"><Alignment ss:Horizontal="Right"/></Style>'
      .'</Styles>';
    $xml = '<?xml version="1.0"?>' .
           '<?mso-application progid="Excel.Sheet"?>' .
           '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ' .
           'xmlns:o="urn:schemas-microsoft-com:office:office" ' .
           'xmlns:x="urn:schemas-microsoft-com:office:excel" ' .
           'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
           $styles .
           '<Worksheet ss:Name="Summary"><Table>';
    for ($i = 0; $i < 11; $i++) {
      $w = [20,20,140,100,120,120,90,70,140,90,90][$i];
      $xml .= '<Column ss:Width="'.$w.'"/>';
    }
    for ($r = 1; $r <= $maxRow; $r++) { $xml .= $mkRow($r, 11); }
    $xml .= '</Table></Worksheet></Workbook>';
    $fname = 'Monthly_Summary_'.$m.'.xls';
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    echo $xml;
    exit;
  }
  $xmlRows = [];
  for ($r = 1; $r <= $maxRow; $r++) {
    if (!isset($grid[$r])) continue;
    ksort($grid[$r]);
    $xml = '<row r="'.$r.'">';
    foreach ($grid[$r] as $c => $cell) {
      $ref = $colName($c - 1) . $r;
      $safe = htmlspecialchars((string)$cell['v'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      $sAttr = isset($cell['s']) && intval($cell['s']) > 0 ? ' s="'.intval($cell['s']).'"' : '';
      $xml .= '<c r="'.$ref.'" t="inlineStr"'.$sAttr.'><is><t>'.$safe.'</t></is></c>';
    }
    $xml .= '</row>';
    $xmlRows[] = $xml;
  }
  $colsXml = '<cols>';
  $widths = [5,5,20,14,16,16,12,10,20,12,12];
  for ($i = 1; $i <= 11; $i++) {
    $w = $widths[$i - 1] ?? 12;
    $colsXml .= '<col min="'.$i.'" max="'.$i.'" width="'.$w.'" customWidth="1"/>';
  }
  $colsXml .= '</cols>';
  $mergeXml = '';
  if (!empty($mergeCells)) {
    $mergeXml = '<mergeCells count="'.count($mergeCells).'">';
    foreach ($mergeCells as $mRef) { $mergeXml .= '<mergeCell ref="'.$mRef.'"/>'; }
    $mergeXml .= '</mergeCells>';
  }
  $logoPath = __DIR__ . '/images/logo.svg';
  $hasLogo = false;
  $logoData = '';
  if (is_file($logoPath)) {
    $logoData = file_get_contents($logoPath);
    if ($logoData !== false && $logoData !== '') { $hasLogo = true; }
  }
  $drawingTag = '';
  if ($hasLogo) { $drawingTag = '<drawing r:id="rId1"/>'; }
  $sheetNs = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
  if ($hasLogo) { $sheetNs .= ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"'; }
  $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet '.$sheetNs.'>'.$colsXml.'<sheetData>'.implode('', $xmlRows).'</sheetData>'.$mergeXml.$drawingTag.'</worksheet>';
  $drawingXml = '';
  $drawingRels = '';
  $sheetRels = '';
  if ($hasLogo) {
    $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
      .'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
      .'<xdr:oneCellAnchor>'
      .'<xdr:from><xdr:col>6</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>0</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>'
      .'<xdr:ext cx="2286000" cy="914400"/>'
      .'<xdr:pic>'
      .'<xdr:nvPicPr><xdr:cNvPr id="1" name="Logo"/><xdr:cNvPicPr/></xdr:nvPicPr>'
      .'<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
      .'<xdr:spPr><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
      .'</xdr:pic>'
      .'<xdr:clientData/>'
      .'</xdr:oneCellAnchor>'
      .'</xdr:wsDr>';
    $drawingRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.svg"/></Relationships>';
    $sheetRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>';
  }
  $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    .'<fonts count="3">'
    .'<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
    .'<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
    .'<font><b/><sz val="16"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
    .'</fonts>'
    .'<fills count="3">'
    .'<fill><patternFill patternType="none"/></fill>'
    .'<fill><patternFill patternType="gray125"/></fill>'
    .'<fill><patternFill patternType="solid"><fgColor rgb="FFDAF2D0"/><bgColor indexed="64"/></patternFill></fill>'
    .'</fills>'
    .'<borders count="2">'
    .'<border><left/><right/><top/><bottom/><diagonal/></border>'
    .'<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
    .'</borders>'
    .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    .'<cellXfs count="10">'
    .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    .'<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
    .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
    .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1"/>'
    .'<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1"/>'
    .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
    .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment horizontal="right"/></xf>'
    .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment horizontal="right"/></xf>'
    .'</cellXfs>'
    .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    .'</styleSheet>';
  $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Summary" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>';
  $relsRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
  $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
  $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
  if ($hasLogo) {
    $contentTypes .= '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>';
    $contentTypes .= '<Default Extension="svg" ContentType="image/svg+xml"/>';
  }
  $contentTypes .= '</Types>';
  $zip = new ZipArchive();
  $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
  $zip->open($tmp, ZipArchive::OVERWRITE);
  $zip->addFromString('[Content_Types].xml', $contentTypes);
  $zip->addFromString('_rels/.rels', $relsRels);
  $zip->addFromString('xl/workbook.xml', $workbookXml);
  $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
  $zip->addFromString('xl/styles.xml', $stylesXml);
  $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
  if ($hasLogo) {
    $zip->addFromString('xl/drawings/drawing1.xml', $drawingXml);
    $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRels);
    $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRels);
    $zip->addFromString('xl/media/image1.svg', $logoData);
  }
  $zip->close();
  $fname = 'Monthly_Summary_'.$m.'.xlsx';
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp);
  @unlink($tmp);
  exit;
}
// Get current page from URL parameter or default to dashboard
if (false && isset($_GET['action']) && $_GET['action'] === 'export_monthly_report' && isset($_GET['month']) && isset($_GET['format'])) {
  $m = preg_replace('/[^0-9\-]/', '', $_GET['month']);
  if (!preg_match('/^\d{4}\-\d{2}$/', $m)) { $m = date('Y-m'); }
  $start = $m . '-01 00:00:00';
  $end = date('Y-m-t 23:59:59', strtotime($start));
  $rows = [];
  $incidentRows = [];
  $approved = 0; $denied = 0; $pending = 0; $verifiedPay = 0; $pendingPay = 0; $total = 0;
  $amenityCounts = [];
  if ($con instanceof mysqli) {
    $stmt = $con->prepare("SELECT r.ref_code, r.amenity, r.start_date, r.end_date, r.created_at, COALESCE(r.approval_status,'pending') AS approval_status, COALESCE(r.payment_status,'pending') AS payment_status, COALESCE(u.user_type,'resident') AS user_type, COALESCE(r.booked_by_role,'') AS booked_by_role, COALESCE(r.booked_by_name,'') AS booked_by_name FROM reservations r LEFT JOIN users u ON r.user_id = u.id WHERE r.created_at BETWEEN ? AND ? ORDER BY r.created_at ASC");
    $stmt->bind_param('ss', $start, $end);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) { $rows[] = ['source' => 'reservation'] + $row; }
    $stmt->close();
    $stmt2 = $con->prepare("SELECT gf.ref_code, gf.amenity, gf.start_date, gf.end_date, gf.created_at, COALESCE(gf.approval_status,'pending') AS approval_status FROM guest_forms gf WHERE gf.created_at BETWEEN ? AND ? ORDER BY gf.created_at ASC");
    $stmt2->bind_param('ss', $start, $end);
    $stmt2->execute();
    $res2 = $stmt2->get_result();
    while ($res2 && ($row2 = $res2->fetch_assoc())) { $rows[] = ['source' => 'guest_form', 'payment_status' => '', 'user_type' => 'visitor', 'booked_by_role' => '', 'booked_by_name' => ''] + $row2; }
    $stmt2->close();
    $stmt3 = $con->prepare("SELECT rr.ref_code, rr.amenity, rr.start_date, rr.end_date, rr.created_at, COALESCE(rr.approval_status,'pending') AS approval_status, COALESCE(u.user_type,'resident') AS user_type, COALESCE(u.first_name,'') AS first_name, COALESCE(u.middle_name,'') AS middle_name, COALESCE(u.last_name,'') AS last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.created_at BETWEEN ? AND ? ORDER BY rr.created_at ASC");
    $stmt3->bind_param('ss', $start, $end);
    $stmt3->execute();
    $res3 = $stmt3->get_result();
    while ($res3 && ($row3 = $res3->fetch_assoc())) {
      $full = trim(($row3['first_name'] ?? '') . ' ' . ($row3['middle_name'] ?? '') . ' ' . ($row3['last_name'] ?? ''));
      $rows[] = ['source' => 'resident_reservation', 'payment_status' => '', 'user_type' => $row3['user_type'] ?? 'resident', 'booked_by_role' => 'resident', 'booked_by_name' => $full] + $row3;
    }
    $stmt3->close();
    $stmt4 = $con->prepare("SELECT ir.id, ir.complainant, ir.subject, ir.nature, ir.status, ir.created_at, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id WHERE ir.created_at BETWEEN ? AND ? ORDER BY ir.created_at ASC");
    $stmt4->bind_param('ss', $start, $end);
    $stmt4->execute();
    $res4 = $stmt4->get_result();
    while ($res4 && ($row4 = $res4->fetch_assoc())) { $incidentRows[] = $row4; }
    $stmt4->close();
  }
  foreach ($rows as $r) {
    $total++;
    $st = strtolower($r['approval_status'] ?? 'pending');
    if ($st === 'approved') $approved++; elseif ($st === 'denied' || $st === 'cancelled') $denied++; else $pending++;
    $ps = strtolower($r['payment_status'] ?? '');
    if ($ps === 'verified') $verifiedPay++; elseif ($ps === 'pending' || $ps === 'submitted' || $ps === 'pending_update') $pendingPay++;
    $amen = trim((string)($r['amenity'] ?? ''));
    if ($amen !== '') { $amenityCounts[$amen] = ($amenityCounts[$amen] ?? 0) + 1; }
  }
  $topAmenities = [];
  if (!empty($amenityCounts)) {
    $topAmenities = $amenityCounts;
    arsort($topAmenities);
    $topAmenities = array_slice($topAmenities, 0, 5, true);
  }
  $residentAmenityPA = 0;
  $visitorAmenityPA = 0;
  $guestFormPA = 0;
  $incidentCount = 0;
  $pendingApprovals = 0;
  $totalRequestsMonth = 0;
  $cancelledMonth = 0;
  if ($con instanceof mysqli) {
    $q1 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND amenity <> '' AND approval_status IN ('pending','approved') AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history')) AND created_at BETWEEN ? AND ?");
    $q1->bind_param('ss', $start, $end);
    $q1->execute(); $r1 = $q1->get_result(); if ($r1 && ($rw=$r1->fetch_assoc())) { $residentAmenityPA = intval($rw['c']); } $q1->close();
    $q2 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL AND (approval_status IN ('pending','approved') OR status IN ('pending','approved')) AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history')) AND created_at BETWEEN ? AND ?");
    $q2->bind_param('ss', $start, $end);
    $q2->execute(); $r2 = $q2->get_result(); if ($r2 && ($rw=$r2->fetch_assoc())) { $visitorAmenityPA = intval($rw['c']); } $q2->close();
    $q3 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status IN ('pending','approved') AND created_at BETWEEN ? AND ?");
    $q3->bind_param('ss', $start, $end);
    $q3->execute(); $r3 = $q3->get_result(); if ($r3 && ($rw=$r3->fetch_assoc())) { $guestFormPA = intval($rw['c']); } $q3->close();
    $q4 = $con->prepare("SELECT COUNT(*) AS c FROM incident_reports WHERE created_at BETWEEN ? AND ?");
    $q4->bind_param('ss', $start, $end);
    $q4->execute(); $r4 = $q4->get_result(); if ($r4 && ($rw=$r4->fetch_assoc())) { $incidentCount = intval($rw['c']); } $q4->close();
    $q5 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE (entry_pass_id IS NULL OR entry_pass_id = 0) AND amenity IS NOT NULL AND amenity <> '' AND approval_status = 'pending' AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history')) AND created_at BETWEEN ? AND ?");
    $q5->bind_param('ss', $start, $end);
    $q5->execute(); $r5 = $q5->get_result(); $pendingResident = ($r5 && ($rw=$r5->fetch_assoc())) ? intval($rw['c']) : 0; $q5->close();
    $q6 = $con->prepare("SELECT COUNT(*) AS c FROM reservations WHERE entry_pass_id IS NOT NULL AND (approval_status = 'pending' OR status = 'pending') AND (approval_status IS NULL OR approval_status NOT IN ('cancelled','moved_to_history')) AND (status IS NULL OR status NOT IN ('cancelled','moved_to_history')) AND created_at BETWEEN ? AND ?");
    $q6->bind_param('ss', $start, $end);
    $q6->execute(); $r6 = $q6->get_result(); $pendingVisitor = ($r6 && ($rw=$r6->fetch_assoc())) ? intval($rw['c']) : 0; $q6->close();
    $q7 = $con->prepare("SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status = 'pending' AND created_at BETWEEN ? AND ?");
    $q7->bind_param('ss', $start, $end);
    $q7->execute(); $r7 = $q7->get_result(); $pendingGuest = ($r7 && ($rw=$r7->fetch_assoc())) ? intval($rw['c']) : 0; $q7->close();
    $q8 = $con->prepare("SELECT COUNT(*) AS c FROM incident_reports WHERE escalated_to_admin = 1 AND status IN ('new','in_progress') AND created_at BETWEEN ? AND ?");
    $q8->bind_param('ss', $start, $end);
    $q8->execute(); $r8 = $q8->get_result(); $pendingInc = ($r8 && ($rw=$r8->fetch_assoc())) ? intval($rw['c']) : 0; $q8->close();
    $pendingApprovals = $pendingResident + $pendingVisitor + $pendingGuest + $pendingInc;
    $totalRequestsMonth = $total + $incidentCount;
    $q9 = $con->prepare("SELECT COALESCE(SUM(c),0) AS total FROM (
            SELECT COUNT(*) AS c FROM reservations WHERE (approval_status = 'cancelled' OR status = 'cancelled') AND created_at BETWEEN ? AND ?
            UNION ALL
            SELECT COUNT(*) AS c FROM guest_forms WHERE approval_status = 'cancelled' AND created_at BETWEEN ? AND ?
            UNION ALL
            SELECT COUNT(*) AS c FROM incident_reports WHERE status = 'cancelled' AND created_at BETWEEN ? AND ?
          ) t");
    $q9->bind_param('ssssss', $start, $end, $start, $end, $start, $end);
    $q9->execute(); $r9 = $q9->get_result(); if ($r9 && ($rw=$r9->fetch_assoc())) { $cancelledMonth = intval($rw['total']); } $q9->close();
  }
  $monthLabel = date('F Y', strtotime($start));
  $fmt = strtolower($_GET['format']);
  if ($fmt === 'xlsx') {
    if (!class_exists('ZipArchive')) {
      $mkRow = function($cells) {
        $out = '<Row>';
        foreach ($cells as $c) {
          $safe = htmlspecialchars((string)$c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
          $out .= '<Cell><Data ss:Type="String">'.$safe.'</Data></Cell>';
        }
        $out .= '</Row>';
        return $out;
      };
      $xml = '<?xml version="1.0"?>' .
             '<?mso-application progid="Excel.Sheet"?>' .
             '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ' .
             'xmlns:o="urn:schemas-microsoft-com:office:office" ' .
             'xmlns:x="urn:schemas-microsoft-com:office:excel" ' .
             'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' .
             '<Worksheet ss:Name="Summary"><Table>';
      $xml .= $mkRow(["Monthly Summary Report - $monthLabel"]);
      $xml .= $mkRow([""]);
      $xml .= $mkRow(["Totals"]);
      $xml .= $mkRow(["Approved", $approved]);
      $xml .= $mkRow(["Denied/Cancelled", $denied]);
      $xml .= $mkRow(["Pending", $pending]);
      $xml .= $mkRow(["Verified Payments", $verifiedPay]);
      $xml .= $mkRow(["Pending Payments", $pendingPay]);
      $xml .= $mkRow(["Resident Amenity Requests", $residentAmenityPA]);
      $xml .= $mkRow(["Visitor Amenity Requests", $visitorAmenityPA]);
      $xml .= $mkRow(["Guest Form Requests", $guestFormPA]);
      $xml .= $mkRow(["Incident Reports", $incidentCount]);
      $xml .= $mkRow(["Pending Approvals", $pendingApprovals]);
      $xml .= $mkRow(["Total Requests", $totalRequestsMonth]);
      $xml .= $mkRow(["Cancelled Requests", $cancelledMonth]);
      if (!empty($topAmenities)) {
        $xml .= $mkRow(["Most Requested Amenities"]);
        foreach ($topAmenities as $k=>$v) { $xml .= $mkRow(["Amenity: $k", $v]); }
      }
      $xml .= $mkRow([""]);
      $xml .= $mkRow(["Ref Code","Source","Amenity","Booked By","Role","User Type","Approval Status","Payment Status","Start Date","End Date","Created At"]);
      foreach ($rows as $r) {
        $xml .= $mkRow([
          $r['ref_code'] ?? '',
          $r['source'] ?? '',
          $r['amenity'] ?? '',
          $r['booked_by_name'] ?? '',
          $r['booked_by_role'] ?? '',
          $r['user_type'] ?? '',
          $r['approval_status'] ?? '',
          $r['payment_status'] ?? '',
          $r['start_date'] ?? '',
          $r['end_date'] ?? '',
          $r['created_at'] ?? ''
        ]);
      }
      if (!empty($incidentRows)) {
        $xml .= $mkRow([""]);
        $xml .= $mkRow(["Incident Reports"]);
        $xml .= $mkRow(["Report ID","Resident","Status","Subject","Created At"]);
        foreach ($incidentRows as $ir) {
          $full = trim(($ir['first_name'] ?? '') . ' ' . ($ir['middle_name'] ?? '') . ' ' . ($ir['last_name'] ?? ''));
          $name = $full !== '' ? $full : ($ir['complainant'] ?? '');
          $subj = $ir['subject'] ?? '';
          if ($subj === '') { $subj = $ir['nature'] ?? ''; }
          $xml .= $mkRow(["IR-".intval($ir['id']), $name, $ir['status'] ?? '', $subj, $ir['created_at'] ?? '']);
        }
      }
      $xml .= '</Table></Worksheet></Workbook>';
      $fname = 'Monthly_Summary_'.$m.'.xls';
      header('Content-Type: application/vnd.ms-excel');
      header('Content-Disposition: attachment; filename="'.$fname.'"');
      echo $xml;
      exit;
    }
    $colName = function($i){ $s=''; $i=intval($i); while($i>=0){ $s=chr(($i%26)+65).$s; $i=intval($i/26)-1; } return $s; };
    $xmlRows = [];
    $makeRow = function($cells, $rowIndex) use ($colName){
      $i = 0; $xml = '<row r="'.$rowIndex.'">';
      foreach ($cells as $c) {
        $ref = $colName($i) . $rowIndex;
        $safe = htmlspecialchars((string)$c, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$safe.'</t></is></c>';
        $i++;
      }
      $xml .= '</row>';
      return $xml;
    };
    $idx = 1;
    $xmlRows[] = $makeRow(["Monthly Summary Report - $monthLabel"], $idx++); 
    $xmlRows[] = $makeRow([""], $idx++);
    $xmlRows[] = $makeRow(["Totals"], $idx++);
    $xmlRows[] = $makeRow(["Approved", $approved], $idx++);
    $xmlRows[] = $makeRow(["Denied/Cancelled", $denied], $idx++);
    $xmlRows[] = $makeRow(["Pending", $pending], $idx++);
    $xmlRows[] = $makeRow(["Verified Payments", $verifiedPay], $idx++);
    $xmlRows[] = $makeRow(["Pending Payments", $pendingPay], $idx++);
    $xmlRows[] = $makeRow(["Resident Amenity Requests", $residentAmenityPA], $idx++);
    $xmlRows[] = $makeRow(["Visitor Amenity Requests", $visitorAmenityPA], $idx++);
    $xmlRows[] = $makeRow(["Guest Form Requests", $guestFormPA], $idx++);
    $xmlRows[] = $makeRow(["Incident Reports", $incidentCount], $idx++);
    $xmlRows[] = $makeRow(["Pending Approvals", $pendingApprovals], $idx++);
    $xmlRows[] = $makeRow(["Total Requests", $totalRequestsMonth], $idx++);
    $xmlRows[] = $makeRow(["Cancelled Requests", $cancelledMonth], $idx++);
    if (!empty($topAmenities)) {
      $xmlRows[] = $makeRow(["Most Requested Amenities"], $idx++);
      foreach ($topAmenities as $k=>$v) { $xmlRows[] = $makeRow(["Amenity: $k", $v], $idx++); }
    }
    $xmlRows[] = $makeRow([""], $idx++);
    $xmlRows[] = $makeRow(["Ref Code","Source","Amenity","Booked By","Role","User Type","Approval Status","Payment Status","Start Date","End Date","Created At"], $idx++);
    foreach ($rows as $r) {
      $xmlRows[] = $makeRow([
        $r['ref_code'] ?? '',
        $r['source'] ?? '',
        $r['amenity'] ?? '',
        $r['booked_by_name'] ?? '',
        $r['booked_by_role'] ?? '',
        $r['user_type'] ?? '',
        $r['approval_status'] ?? '',
        $r['payment_status'] ?? '',
        $r['start_date'] ?? '',
        $r['end_date'] ?? '',
        $r['created_at'] ?? ''
      ], $idx++);
    }
    if (!empty($incidentRows)) {
      $xmlRows[] = $makeRow([""], $idx++);
      $xmlRows[] = $makeRow(["Incident Reports"], $idx++);
      $xmlRows[] = $makeRow(["Report ID","Resident","Status","Subject","Created At"], $idx++);
      foreach ($incidentRows as $ir) {
        $full = trim(($ir['first_name'] ?? '') . ' ' . ($ir['middle_name'] ?? '') . ' ' . ($ir['last_name'] ?? ''));
        $name = $full !== '' ? $full : ($ir['complainant'] ?? '');
        $subj = $ir['subject'] ?? '';
        if ($subj === '') { $subj = $ir['nature'] ?? ''; }
        $xmlRows[] = $makeRow(["IR-".intval($ir['id']), $name, $ir['status'] ?? '', $subj, $ir['created_at'] ?? ''], $idx++);
      }
    }
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.implode('', $xmlRows).'</sheetData></worksheet>';
    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets><sheet name="Summary" sheetId="1" r:id="rId1" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"/></sheets></workbook>';
    $relsRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>';
    $zip = new ZipArchive();
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $relsRels);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    $zip->close();
    $fname = 'Monthly_Summary_'.$m.'.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
  } else {
    $pdfEscape = function($t){
      $t = (string)$t;
      $t = str_replace(["\\","(",")","\r"], ["\\\\","\\(","\\)",""], $t);
      return $t;
    };
    $line = function($text, $size, $x, $y) use ($pdfEscape){
      return "BT /F1 ".$size." Tf ".$x." ".$y." Td (".$pdfEscape($text).") Tj ET\n";
    };
    $content = '';
    $y = 800;
    $content .= $line("Monthly Summary Report - ".$monthLabel, 13, 50, $y); $y -= 22;
    $content .= $line("Summary", 12, 50, $y); $y -= 16;
    $summaryPairs = [
      ["Approved", $approved],
      ["Denied/Cancelled", $denied],
      ["Pending", $pending],
      ["Verified Payments", $verifiedPay],
      ["Pending Payments", $pendingPay],
      ["Resident Amenity Requests", $residentAmenityPA],
      ["Visitor Amenity Requests", $visitorAmenityPA],
      ["Guest Form Requests", $guestFormPA],
      ["Incident Reports", $incidentCount],
      ["Pending Approvals", $pendingApprovals],
      ["Total Requests", $totalRequestsMonth],
      ["Cancelled Requests", $cancelledMonth]
    ];
    foreach ($summaryPairs as $pair) {
      $content .= $line($pair[0], 10, 60, $y);
      $content .= $line($pair[1], 10, 300, $y);
      $y -= 14;
    }
    if (!empty($topAmenities)) {
      $y -= 6;
      $content .= $line("Most Requested Amenities", 12, 50, $y); $y -= 16;
      $content .= $line("Amenity", 10, 60, $y);
      $content .= $line("Count", 10, 300, $y);
      $y -= 14;
      foreach ($topAmenities as $k=>$v) {
        $content .= $line($k, 10, 60, $y);
        $content .= $line($v, 10, 300, $y);
        $y -= 14;
      }
    }
    $y -= 6;
    $content .= $line("Requests", 12, 50, $y); $y -= 16;
    $cols = [
      ["Ref Code", 40, 10],
      ["Source", 100, 10],
      ["Amenity", 150, 16],
      ["Booked By", 230, 16],
      ["Role", 310, 8],
      ["Type", 350, 8],
      ["Approval", 390, 10],
      ["Payment", 445, 10],
      ["Start", 500, 10]
    ];
    foreach ($cols as $c) { $content .= $line($c[0], 9, $c[1], $y); }
    $y -= 12;
    foreach ($rows as $r) {
      if ($y < 70) { $content .= $line("More rows omitted. Download Excel for full list.", 9, 50, $y); $y -= 12; break; }
      $vals = [
        $r['ref_code'] ?? '',
        $r['source'] ?? '',
        $r['amenity'] ?? '',
        $r['booked_by_name'] ?? '',
        $r['booked_by_role'] ?? '',
        $r['user_type'] ?? '',
        $r['approval_status'] ?? '',
        $r['payment_status'] ?? '',
        $r['start_date'] ?? ''
      ];
      foreach ($cols as $i=>$c) {
        $max = intval($c[2]);
        $text = isset($vals[$i]) ? (string)$vals[$i] : '';
        if ($max > 0 && mb_strlen($text) > $max) { $text = mb_substr($text, 0, $max - 1) . '…'; }
        $content .= $line($text, 9, $c[1], $y);
      }
      $y -= 12;
    }
    if (!empty($incidentRows) && $y > 90) {
      $y -= 6;
      $content .= $line("Incident Reports", 12, 50, $y); $y -= 16;
      $content .= $line("Report ID", 9, 50, $y);
      $content .= $line("Resident", 9, 120, $y);
      $content .= $line("Status", 9, 250, $y);
      $content .= $line("Subject", 9, 310, $y);
      $content .= $line("Created At", 9, 470, $y);
      $y -= 12;
      foreach ($incidentRows as $ir) {
        if ($y < 70) { $content .= $line("More incident rows omitted. Download Excel for full list.", 9, 50, $y); $y -= 12; break; }
        $full = trim(($ir['first_name'] ?? '') . ' ' . ($ir['middle_name'] ?? '') . ' ' . ($ir['last_name'] ?? ''));
        $name = $full !== '' ? $full : ($ir['complainant'] ?? '');
        $subj = $ir['subject'] ?? '';
        if ($subj === '') { $subj = $ir['nature'] ?? ''; }
        $rid = "IR-".intval($ir['id']);
        $content .= $line($rid, 9, 50, $y);
        $content .= $line(mb_strlen($name) > 16 ? mb_substr($name, 0, 15) . '…' : $name, 9, 120, $y);
        $content .= $line($ir['status'] ?? '', 9, 250, $y);
        $content .= $line(mb_strlen($subj) > 24 ? mb_substr($subj, 0, 23) . '…' : $subj, 9, 310, $y);
        $content .= $line($ir['created_at'] ?? '', 9, 470, $y);
        $y -= 12;
      }
    }
    $objects = [];
    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objects[3] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>";
    $objects[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[5] = "<< /Length ".strlen($content)." >>\nstream\n".$content."\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    for ($i = 1; $i <= 5; $i++) {
      $offsets[$i] = strlen($pdf);
      $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    for ($i = 1; $i <= 5; $i++) { $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]); }
    $pdf .= "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefPos."\n%%EOF";
    $fname = 'Monthly_Summary_'.$m.'.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$fname.'"');
    echo $pdf;
    exit;
  }
}
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
if ($currentPage === 'verify') {
  $currentPage = 'requests';
}
$verifyContext = isset($_GET['verify_context']) ? $_GET['verify_context'] : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>VictorianPass | Admin</title>
<link rel="icon" type="image/png" href="images/logo.svg">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
/* Modern Admin Dashboard CSS */
:root {
    /* Color Palette */
    --primary: #23412e;
    --primary-dark: #1a3022;
    --primary-light: #e8f5e9;
    --accent: #d4af37;
    
    --bg-body: #f4f6f8;
    --bg-surface: #ffffff;
    --bg-sidebar: #2b2623;
    
    --text-main: #2c3e50;
    --text-secondary: #5a6b7c;
    --text-muted: #95a5a6;
    
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    
    /* Status Colors */
    --success: #27ae60;
    --success-bg: #e8f8f5;
    --warning: #f39c12;
    --warning-bg: #fef9e7;
    --danger: #c0392b;
    --danger-bg: #fdedec;
    --info: #2980b9;
    --info-bg: #ebf5fb;
    
    /* Shadows & Transitions */
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s ease-in-out;
    
    --radius: 8px;
    --sidebar-width: 280px;
    --header-height: 68px;
}

/* Reset & Base */
* { box-sizing: border-box; }
body, button, input, select, textarea { font-family: 'Poppins', sans-serif; }
*:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

body {
    margin: 0;
    padding: 0;
    font-family: 'Poppins', sans-serif;
    background-color: var(--bg-body);
    color: var(--text-main);
    overflow-x: hidden;
    line-height: 1.5;
}

a { text-decoration: none; color: inherit; transition: var(--transition); }
ul { list-style: none; padding: 0; margin: 0; }
h1, h2, h3, h4, h5, h6 { margin: 0; font-weight: 600; color: var(--text-main); }

/* Layout Structure */
.app {
    display: flex;
    min-height: 100vh;
}

/* Sidebar */
.sidebar {
    width: var(--sidebar-width);
    background: radial-gradient(circle at top left, #3a332f 0%, #2b2623 55%, #211b18 100%);
    color: #f4efe6;
    display: flex;
    flex-direction: column;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
    z-index: 100;
    flex-shrink: 0;
    transition: width 0.25s ease;
}

.brand {
    padding: 22px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    justify-content: center;
}

.brand img { width: 36px; height: 36px; }
.brand .title { display: flex; flex-direction: column; }
.brand h1 { font-size: 1rem; color: var(--primary); line-height: 1.2; }
.brand p { font-size: 0.75rem; color: var(--text-secondary); margin: 0; }

.nav-list {
    padding: 18px 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-item {
    padding: 12px 18px;
    border-radius: 12px;
    color: rgba(255,255,255,0.78);
    font-weight: 500;
    font-size: 0.95rem;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: var(--transition);
}

.nav-item:hover, .nav-item.active {
    background: rgba(255,255,255,0.1);
    color: #fff;
    font-weight: 600;
}

.nav-item.active {
    box-shadow: inset 3px 0 0 var(--accent);
}

.nav-item img {
    width: 20px;
    height: 20px;
    object-fit: contain;
    filter: brightness(0) invert(1) opacity(0.7);
    transition: var(--transition);
}

.nav-item:hover img, .nav-item.active img {
    opacity: 1;
}
.nav-item i {
    width: 20px;
    text-align: center;
    font-size: 1rem;
    color: inherit;
    transition: var(--transition);
}

.sidebar-footer {
    margin-top: auto;
    padding: 18px 20px 22px;
    border-top: 1px solid rgba(255,255,255,0.08);
}

.sidebar-footer .text-muted-link {
    color: #fff;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.9rem;
    background: #c0392b;
    padding: 10px 12px;
    border-radius: 10px;
    text-decoration: none;
}
.sidebar-footer .text-muted-link:hover { background: #a93226; color: #fff; }
.sidebar-footer .text-muted-link svg { width: 18px; height: 18px; flex-shrink: 0; }

/* Main Content Area */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--bg-body);
}

/* Top Header */
.top-header {
    height: var(--header-height);
    padding: 0 28px;
    background: radial-gradient(circle at top left, #3a332f 0%, #2b2623 55%, #211b18 100%);
    border-bottom: 1px solid #1a1512;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    position: sticky;
    top: 0;
    z-index: 90;
    color: #fff;
}

.header-brand, .header-actions {
    display: flex;
    align-items: center;
    gap: 15px;
}
.header-brand {
    gap: 12px;
    min-width: 0;
}
.header-brand .sidebar-toggle {
    margin-right: 2px;
}
.header-brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
}
.header-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.4px;
}
.header-subtitle {
    font-size: 0.85rem;
    color: rgba(255,255,255,0.75);
    font-weight: 600;
    letter-spacing: 0.2px;
}

.sidebar-toggle {
    border: 1px solid rgba(255,255,255,0.25);
    background: rgba(255,255,255,0.12);
    color: #fff;
    width: 40px;
    height: 40px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}
.sidebar-toggle:hover { background: rgba(255,255,255,0.2); }

body.sidebar-collapsed .sidebar { width: 72px; }
body.sidebar-collapsed .brand { justify-content: center; padding: 16px 12px; }
body.sidebar-collapsed .brand .title { display: none; }
body.sidebar-collapsed .nav-list { padding: 16px 8px; }
body.sidebar-collapsed .nav-item { justify-content: center; padding: 10px; gap: 0; }
body.sidebar-collapsed .nav-item span { display: none; }
body.sidebar-collapsed .sidebar-footer { padding: 16px 10px; }
body.sidebar-collapsed .sidebar-footer .text-muted-link span { display: none; }
body.sidebar-collapsed .sidebar-footer .text-muted-link { padding: 10px; width: 100%; }

.avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    object-fit: cover;
    border: 2px solid rgba(255,255,255,0.2);
    cursor: pointer;
    transition: var(--transition);
}
.avatar:hover { border-color: var(--accent); }

/* Page Header */
.page-header {
    padding: 18px 30px 6px;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 10px;
}

.page-header h2 { font-size: 1.5rem; color: var(--text-main); }

.header-search {
    flex: 1;
    display: flex;
    justify-content: center;
    min-width: 0;
}
.search {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 999px;
    padding: 8px 16px;
    width: min(520px, 100%);
    display: flex;
    align-items: center;
    transition: var(--transition);
}
.search:focus-within { border-color: rgba(255,255,255,0.45); box-shadow: 0 0 0 3px rgba(255,255,255,0.12); }
.search input { border: none; width: 100%; font-size: 0.9rem; background: transparent; outline: none; color: #fff; }
.search input::placeholder { color: rgba(255,255,255,0.6); }

/* Dashboard Widgets */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    padding: 0 30px;
    margin-bottom: 30px;
}

.dashboard-widget {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    justify-content: center;
    transition: var(--transition);
    position: relative;
    overflow: hidden;
}

.dashboard-widget::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: var(--primary);
    opacity: 0.6;
    transition: var(--transition);
}

.dashboard-widget:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-4px);
}
.dashboard-widget:hover::before { opacity: 1; }

.dashboard-widget-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 8px 0;
}

.dashboard-widget-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    font-weight: 500;
}

/* Panels & Cards */
.panel, .card-box {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    margin: 0 30px 30px 30px;
    overflow-x: auto;
}

.panel h3, .card-box h3 {
    margin: 0 0 20px 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 15px;
}

/* Fix for nested legacy containers */
.panel .card-box {
    box-shadow: none;
    border: none;
    padding: 0;
    margin: 0;
    background: transparent;
}
.panel .content-row { margin: 0; }

/* Utilities */
.mb-20 { margin-bottom: 20px; }
.muted { color: var(--text-muted); font-style: italic; }
.notice {
    background: var(--info-bg);
    color: var(--info);
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 0.9rem;
    margin-bottom: 20px;
    border-left: 4px solid var(--info);
    display: flex;
    align-items: center;
}

.row-highlight {
    animation: highlightRow 2s ease-out;
    background-color: var(--primary-light) !important;
}

@keyframes highlightRow {
    0% { background-color: var(--warning-bg); }
    100% { background-color: var(--primary-light); }
}

.receipt-link {
    color: var(--info);
    text-decoration: underline;
    font-size: 0.85rem;
    font-weight: 500;
}
.receipt-link:hover { color: var(--primary); }

/* Tables */
table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; table-layout: auto; }
table.table-requests,
table.table-reservations,
table.table-resident-guest { min-width: 1100px; }
table.table-requests th,
table.table-reservations th,
table.table-resident-guest th { white-space: nowrap; }
table.table-requests td,
table.table-reservations td,
table.table-resident-guest td { vertical-align: top; }
th, td { padding: 16px 18px; text-align: left; border-bottom: 1px solid var(--border-light); font-size: 0.9rem; vertical-align: middle; line-height: 1.45; white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
th {
    font-weight: 600;
    color: var(--text-secondary);
    background: #f8fafc;
    text-transform: uppercase;
    font-size: 0.75rem;
    letter-spacing: 0.6px;
    position: sticky;
    top: 0;
    z-index: 10;
}
tr:last-child td { border-bottom: none; }
tr:hover { background-color: #f8fafc; }

/* Table Actions */
.actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
table td.actions { flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 10px; min-width: 220px; white-space: normal; }
table td.actions > * { width: 100%; }
table td.actions form { width: 100%; margin: 0; display: block; }
table td.actions .btn,
table td.actions a.btn {
    width: 100%;
    justify-content: center;
    min-height: 34px;
    white-space: nowrap;
}
table td.actions .receipt-link { display: block; margin: 6px 0; }
table td.actions .muted { display: block; margin: 6px 0; }
table td.actions .badge { justify-content: center; }
table td .receipt-link,
table td .muted {
    overflow: visible;
    text-overflow: clip;
    white-space: normal;
}
.actions .suspend-reason {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    background: #fff;
    height: 32px;
    line-height: 1.2;
}
.actions .denial-reason {
    width: 100%;
    padding: 7px 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    background: #fff;
    height: 32px;
    line-height: 1.2;
}
.actions .delete-form { display: none; }
.actions .delete-form.show { display: inline-flex; }
table td.actions .delete-form.show { width: 100%; }
.notif-panel .actions { flex-direction: row; align-items: center; flex-wrap: nowrap; }
.notif-panel .actions .btn { width: auto; min-height: 32px; }
.actions .suspend-reason:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(35,65,46,0.12);
}
.actions .denial-reason:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 2px rgba(35,65,46,0.12);
}
.btn {
    padding: 8px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    font-weight: 500;
    transition: var(--transition);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    line-height: 1;
}
.btn:hover { filter: brightness(92%); transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
.btn:active { transform: translateY(0); box-shadow: none; }

.btn-view { background: #2563eb; color: #fff; box-shadow: 0 6px 14px rgba(37,99,235,0.35); }
.btn-view:hover { background: #1d4ed8; box-shadow: 0 8px 18px rgba(29,78,216,0.4); }
.btn-receipt { background: #7c3aed; color: #fff; }
.btn-approve { background: var(--success); color: #fff; }
.btn-reject { background: var(--danger); color: #fff; }
.btn-receipt:hover { background: #6d28d9; }
.btn-edit { background: var(--warning); color: #fff; }
.btn-remove { background: var(--danger); color: #fff; }
.btn-disabled { background: var(--border); color: var(--text-muted); cursor: not-allowed; opacity: 0.7; }
.btn-disabled:hover { transform: none; box-shadow: none; filter: none; }
/* Gold QR button */
.btn-qr { background: var(--accent); color: #fff; box-shadow: 0 6px 14px rgba(212,175,55,0.35); }
.btn-qr:hover { background: #b08d2f; box-shadow: 0 8px 18px rgba(212,175,55,0.45); }

/* Status Badges */
.status, .badge, .status-badge {
    padding: 6px 12px;
    border-radius: 50px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.5px;
    white-space: nowrap;
}

.status.active, .badge-active, .status-ongoing, .status-completed, .badge-approved { background: var(--success-bg); color: var(--success); }
.status.pending, .badge-pending, .status-pending { background: var(--warning-bg); color: var(--warning); }
.status.rejected, .badge-rejected, .badge-denied, .status-denied { background: var(--danger-bg); color: var(--danger); }
.status-cancelled { background: var(--border-light); color: var(--text-muted); }

/* Notifications */
.notif-btn {
    background: rgba(255,255,255,0.1);
    border: none;
    cursor: pointer;
    position: relative;
    color: rgba(255,255,255,0.9);
    transition: var(--transition);
    padding: 6px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
}
.notif-btn:hover { background: rgba(255,255,255,0.2); color: #fff; }
.notif-btn svg { width: 20px; height: 20px; fill: currentColor; }

.notif-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    background: var(--danger);
    color: #fff;
    border-radius: 50%;
    min-width: 19px;
    height: 19px;
    font-size: 0.66rem;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #2b2623;
    font-weight: 700;
}
.notif-badge.pulse { animation: pulse 1s; }

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.5); }
    100% { transform: scale(1); }
}

.notif-panel {
    position: absolute;
    top: 100%;
    right: 0;
    margin-top: 15px;
    width: 340px;
    max-height: 450px;
    background: var(--bg-surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    overflow-y: auto;
    z-index: 200;
    border: 1px solid var(--border);
    display: none; /* Toggled by JS */
}

.notif-item {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    gap: 12px;
    transition: var(--transition);
    cursor: pointer;
    position: relative;
    align-items: flex-start;
}
.notif-item:hover { background: var(--bg-body); }
.notif-item:last-child { border-bottom: none; }

.notif-item-link {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    text-decoration: none;
    color: inherit;
    width: 100%;
}

.notif-type {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: var(--primary-light);
    color: var(--primary);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.68rem;
    font-weight: 700;
    flex-shrink: 0;
}

.notif-meta { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.notif-meta strong { font-weight: 600; font-size: 0.88rem; color: var(--text-main); }
.notif-meta div { font-size: 0.82rem; color: var(--text-secondary); line-height: 1.35; word-wrap: break-word; overflow-wrap: anywhere; white-space: normal; hyphens: auto; }
.notif-item-time { font-size: 0.74rem; color: var(--text-muted); margin-top: 4px; }

.notif-dismiss {
    position: absolute;
    top: 8px;
    right: 8px;
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 0.95rem;
    cursor: pointer;
    opacity: 0;
    transition: var(--transition);
}
.notif-item:hover .notif-dismiss { opacity: 1; }
.notif-dismiss:hover { color: var(--danger); }

/* Modals Styles consolidated below */

.close {
    position: absolute;
    top: 12px;
    right: 12px;
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: #eef2f0;
    color: #23412e;
    border: none;
    font-size: 16px;
    cursor: pointer;
    line-height: 1;
    z-index: 100;
    box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    transition: transform 0.2s ease, color 0.2s ease, filter 0.2s ease;
}
.close:hover { filter: brightness(0.95); transform: scale(1.05); }

/* Animations */
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
.modal.closing { animation: fadeIn 0.2s ease-out reverse; }
.modal.closing .modal-content { animation: slideIn 0.2s ease-out reverse; }
body.modal-open { overflow: hidden; }

/* Receipt Thumbnail */
.receipt-thumbnail {
    width: 48px;
    height: 48px;
    border-radius: 6px;
    object-fit: cover;
    border: 1px solid var(--border);
    transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    cursor: zoom-in;
    background: #fff;
}
.receipt-thumbnail:hover {
    transform: scale(3);
    z-index: 100;
    box-shadow: var(--shadow-lg);
    border-color: #fff;
}

/* Toast */
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--bg-surface);
    border-left: 5px solid var(--primary);
    box-shadow: var(--shadow-lg);
    border-radius: 8px;
    padding: 16px;
    width: min(96vw, 380px);
    z-index: 2000;
    animation: slideInLeft 0.3s;
    display: flex;
    flex-direction: column;
    gap: 8px;
    max-height: 40vh;
    overflow-y: auto;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    white-space: normal;
    hyphens: auto;
}
@keyframes slideInLeft { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
.toast h4 { color: var(--primary); margin-bottom: 5px; font-size: 0.95rem; }
.toast p { font-size: 0.85rem; color: var(--text-secondary); margin: 0; }

.toast-container{
    position: fixed;
    top: 20px;
    right: 20px;
    width: min(96vw, 380px);
    display: flex;
    flex-direction: column;
    gap: 10px;
    z-index: 2000;
    pointer-events: none;
}
.toast-container .toast{ pointer-events: auto; }

/* Notifications */
.notif-btn {
    background: transparent;
    border: none;
    cursor: pointer;
    position: relative;
    padding: 6px;
    border-radius: 50%;
    transition: var(--transition);
}
.notif-btn:hover { background: rgba(255,255,255,0.1); }
.notif-btn img { width: 22px; height: 22px; display: block; }

.notif-badge {
    position: absolute;
    top: -3px;
    right: -3px;
    background: var(--danger);
    color: #fff;
    font-size: 0.66rem;
    font-weight: 700;
    padding: 2px 6px;
    border-radius: 999px;
    border: 2px solid #211b18;
}

/* Modals - Square & Centered */
.modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    align-items: center;
    justify-content: center;
}
.modal.modal-top { z-index: 3000; }

.modal-content {
    background-color: var(--bg-surface);
    margin: 0;
    padding: 20px;
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: var(--shadow-lg);
    position: relative;
    display: flex;
    flex-direction: column;
    gap: 12px;
    animation: slideIn 0.3s ease-out;
    
    /* Square Shape & Sizing */
    width: min(90vw, 550px);
    aspect-ratio: 1/1;
    max-height: 90vh;
    overflow: hidden;
}

.modal-content h3 {
    padding: 8px 8px 12px 4px;
    border-bottom: 1px solid var(--border-light);
    margin: 0;
    font-size: 1.15rem;
    background: var(--bg-surface);
    position: sticky;
    top: 0;
    z-index: 10;
}

/* Scrollable Content */
.modal-content > div, 
.tab-body,
#visitorDetailsContent, 
#reservationDetailsContent, 
#residentReservationDetailsContent, 
#userDetailsContent,
#priceDetailsContent {
    overflow-y: auto;
    flex: 1;
    padding-right: 4px;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    white-space: normal;
    hyphens: auto;
}

.modal-content p{ margin: 6px 0; line-height: 1.5; }
.modal-content img{ max-width: 100%; height: auto; display: block; }
.modal-content table{ width: 100%; border-collapse: collapse; }
.modal-content td{ padding: 6px 0; }

.modal .notif-item {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    align-items: flex-start;
    gap: 14px;
    position: relative;
    transition: var(--transition);
}
.modal .notif-item:hover { background-color: var(--bg-body); }
.modal .notif-item:last-child { border-bottom: none; }

.modal .notif-item-link {
    flex: 1;
    display: flex;
    gap: 14px;
    text-decoration: none;
    color: inherit;
    align-items: flex-start;
    min-width: 0;
}

.modal .notif-type {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: none;
    background: var(--primary-light);
    color: var(--primary);
    padding: 6px 8px;
    border-radius: 8px;
    height: auto;
    white-space: normal;
    width: 120px;
    min-height: 36px;
    text-align: center;
    line-height: 1.2;
    word-break: break-word;
    margin-top: 1px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal .notif-meta {
    flex: 1;
    font-size: 0.9rem;
    line-height: 1.45;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.modal .notif-meta strong { color: var(--text-main); display: block; margin-bottom: 0; font-size: 0.92rem; }
.modal .notif-meta div { color: var(--text-secondary); font-size: 0.84rem; word-break: break-word; }

.modal .notif-dismiss {
    background: transparent;
    border: none;
    color: var(--text-muted);
    font-size: 0.78rem;
    cursor: pointer;
    padding: 6px 10px;
    opacity: 0;
    transition: var(--transition);
    align-self: flex-start;
    position: static;
}
.modal .notif-item:hover .notif-dismiss { opacity: 1; }
.modal .notif-dismiss:hover { color: var(--danger); text-decoration: underline; }

/* Action Buttons */
.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 8px 16px;
    border-radius: 6px;
    font-weight: 500;
    font-size: 0.9rem;
    border: none;
    cursor: pointer;
    transition: var(--transition);
    text-decoration: none;
    gap: 8px;
}
.btn-approve, .btn-success { background: var(--success); color: #fff; }
.btn-approve:hover { background: #059669; }

.btn-reject, .btn-danger { background: var(--danger); color: #fff; }
.btn-reject:hover { background: #dc2626; }

.btn-delete { background: var(--bg-body); color: var(--danger); border: 1px solid var(--border); }
.btn-delete:hover { background: #fee2e2; border-color: var(--danger); }

#visitorModal .modal-content,
#residentReservationModal .modal-content,
#reservationModal .modal-content,
#priceDetailsModal .modal-content,
#incidentDetailsModal .modal-content {
    width: min(92vw, 640px);
    aspect-ratio: auto;
    padding: 0;
    border-radius: 14px;
    box-shadow: 0 8px 18px rgba(0,0,0,0.12);
}
#denyReasonModal .modal-content {
    width: min(92vw, 520px);
    aspect-ratio: auto;
    padding: 16px;
    border-radius: 12px;
    gap: 8px;
}
#denyReasonTitle { margin: 0; padding: 8px 0 10px; }
#denyReasonMessage { margin: 6px 0 8px; }
#denyReasonLabel { margin-top: 6px; }
#denyReasonInput { min-height: 90px; }
#denyReasonSubmit { background: var(--danger); color: #fff; }
#visitorModal .modal-content h3,
#residentReservationModal .modal-content h3,
#reservationModal .modal-content h3,
#priceDetailsModal .modal-content h3,
#incidentDetailsModal .modal-content h3 {
    margin: 0;
    padding: 12px 16px;
    background: #fff;
    border-bottom: 1px solid #e6ebe6;
    color: #23412e;
    font-size: 1.05rem;
    font-weight: 700;
}
#visitorDetailsContent,
#residentReservationDetailsContent,
#reservationDetailsContent,
#priceDetailsContent {
    padding: 18px 20px 22px;
}
#visitorDetailsContent .request-details,
#residentReservationDetailsContent .request-details,
#reservationDetailsContent .request-details,
#priceDetailsContent .request-details {
    display: flex;
    flex-direction: column;
    gap: 14px;
    font-family: 'Poppins', sans-serif;
    color: #333;
}
#visitorDetailsContent .request-status,
#residentReservationDetailsContent .request-status,
#reservationDetailsContent .request-status,
#priceDetailsContent .request-status {
    text-align: center;
}
#visitorDetailsContent .section-title,
#residentReservationDetailsContent .section-title,
#reservationDetailsContent .section-title,
#priceDetailsContent .section-title {
    font-weight: 600;
    font-size: 0.95rem;
    color: #555;
    margin: 10px 0 8px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
#visitorDetailsContent .info-grid,
#residentReservationDetailsContent .info-grid,
#reservationDetailsContent .info-grid,
#priceDetailsContent .info-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
    background: #f9f9f9;
    padding: 15px;
    border-radius: 12px;
    border: 1px solid #eee;
}
#visitorDetailsContent .info-row,
#residentReservationDetailsContent .info-row,
#reservationDetailsContent .info-row,
#priceDetailsContent .info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 0.95rem;
    gap: 12px;
}
#visitorDetailsContent .info-label,
#residentReservationDetailsContent .info-label,
#reservationDetailsContent .info-label,
#priceDetailsContent .info-label {
    color: #666;
    font-weight: 500;
}
#visitorDetailsContent .info-value,
#residentReservationDetailsContent .info-value,
#reservationDetailsContent .info-value,
#priceDetailsContent .info-value {
    color: #111;
    font-weight: 600;
    text-align: right;
}
#visitorDetailsContent .status-badge-lg,
#residentReservationDetailsContent .status-badge-lg,
#reservationDetailsContent .status-badge-lg,
#priceDetailsContent .status-badge-lg {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.85rem;
    text-transform: uppercase;
}
#visitorDetailsContent .st-approved, #residentReservationDetailsContent .st-approved, #reservationDetailsContent .st-approved, #priceDetailsContent .st-approved { background: #dcfce7; color: #166534; }
#visitorDetailsContent .st-pending, #residentReservationDetailsContent .st-pending, #reservationDetailsContent .st-pending, #priceDetailsContent .st-pending { background: #ffedd5; color: #c2410c; }
#visitorDetailsContent .st-denied, #residentReservationDetailsContent .st-denied, #reservationDetailsContent .st-denied, #priceDetailsContent .st-denied { background: #fee2e2; color: #991b1b; }
#visitorDetailsContent .st-expired, #residentReservationDetailsContent .st-expired, #reservationDetailsContent .st-expired, #priceDetailsContent .st-expired { background: #f3f4f6; color: #4b5563; }
#visitorDetailsContent .price-section,
#residentReservationDetailsContent .price-section,
#reservationDetailsContent .price-section,
#priceDetailsContent .price-section {
    margin-top: 8px;
    padding-top: 12px;
    border-top: 1px solid #ddd;
    display: flex;
    flex-direction: column;
    gap: 6px;
}
#visitorDetailsContent .total-price,
#residentReservationDetailsContent .total-price,
#reservationDetailsContent .total-price,
#priceDetailsContent .total-price {
    font-size: 1.05rem;
    font-weight: 700;
    color: #23412e;
}
#visitorDetailsContent .price-down,
#residentReservationDetailsContent .price-down,
#reservationDetailsContent .price-down,
#priceDetailsContent .price-down {
    font-size: 0.9rem;
    color: #666;
    font-weight: 500;
}
#visitorDetailsContent .price-balance,
#residentReservationDetailsContent .price-balance,
#reservationDetailsContent .price-balance,
#priceDetailsContent .price-balance {
    font-size: 0.95rem;
    font-weight: 600;
    color: #c2410c;
}

.btn-view { background: var(--info); color: #fff; }
.btn-view:hover { background: #2563eb; }

.btn-disabled {
    background: var(--border);
    color: var(--text-muted);
    cursor: not-allowed;
    opacity: 0.7;
}

/* Modal Images */
#incidentProofImg {
    max-width: 100%;
    max-height: 80vh;
    object-fit: contain;
    display: block;
    margin: 0 auto;
}

/* Utilities Extra */
.text-center { text-align: center; }
.d-inline-block { display: inline-block; }
.ml-6 { margin-left: 6px; }

/* Responsive Design */
@media (max-width: 768px) {
    .app { flex-direction: column; }
    .sidebar { width: 100%; height: auto; position: sticky; top: 0; border-right: none; border-bottom: 1px solid rgba(255,255,255,0.08); }
    
    .nav-list { 
        flex-direction: row; 
        overflow-x: auto; 
        padding: 10px 15px; 
        gap: 12px; 
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .nav-list::-webkit-scrollbar { display: none; }
    
    .nav-item { 
        white-space: nowrap; 
        padding: 8px 16px; 
        border-radius: 999px; 
        border: 1px solid rgba(255,255,255,0.12); 
        background: rgba(255,255,255,0.08);
    }
    .nav-item.active { 
        border-left: 1px solid rgba(255,255,255,0.3); 
        background: rgba(255,255,255,0.2); 
        color: #fff;
    }
    .nav-item.active img { filter: brightness(0) invert(1); }
    
    .top-header { padding: 12px 18px; height: auto; flex-wrap: wrap; gap: 12px; }
    .header-brand { order: 1; }
    .header-actions { order: 2; }
    .header-search { order: 3; width: 100%; }
    .search { width: 100%; }
    .page-header { padding: 12px 16px 6px; }
    .main { margin-left: 0; width: 100%; }
    
    .dashboard-grid, .panel { padding: 0 16px; margin: 0 0 16px 0; }
    .panel { margin: 0 16px 16px 16px; padding: 16px; }
    
    table { min-width: 520px; }
    th, td { padding: 10px 12px; font-size: 0.8rem; }
    .content-row { overflow-x: auto; -webkit-overflow-scrolling: touch; }
}
.notif-badge { font-family: 'Poppins', sans-serif; }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="brand">
      <img src="images/logo.svg" alt="VictorianPass logo">
    </div>

    <nav class="nav-list">
       <a href="?page=dashboard" class="nav-item <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>" data-page="dashboard"><i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
       <a href="?page=residents" class="nav-item <?php echo $currentPage == 'residents' ? 'active' : ''; ?>" data-page="residents"><i class="fa-solid fa-house-user"></i><span>Residents</span></a>
       <a href="?page=visitors" class="nav-item <?php echo $currentPage == 'visitors' ? 'active' : ''; ?>" data-page="visitors"><i class="fa-solid fa-user"></i><span>Visitors</span></a>
       <a href="?page=requests" class="nav-item <?php echo $currentPage == 'requests' ? 'active' : ''; ?>" data-page="requests"><i class="fa-solid fa-clipboard-list"></i><span>Resident Requests</span></a>
       <a href="?page=resident_guest_forms" class="nav-item <?php echo $currentPage == 'resident_guest_forms' ? 'active' : ''; ?>" data-page="resident_guest_forms"><i class="fa-solid fa-user-plus"></i><span>Resident's Guest Request</span></a>
       <a href="?page=visitor_requests" class="nav-item <?php echo $currentPage == 'visitor_requests' ? 'active' : ''; ?>" data-page="visitor_requests"><i class="fa-solid fa-clipboard-list"></i><span>Visitor Requests</span></a>
       <a href="?page=report" class="nav-item <?php echo $currentPage == 'report' ? 'active' : ''; ?>" data-page="report"><i class="fa-solid fa-triangle-exclamation"></i><span>View Reported Incidents</span></a>
    <a href="?page=security" class="nav-item <?php echo $currentPage == 'security' ? 'active' : ''; ?>" data-page="security"><i class="fa-solid fa-shield-halved"></i><span>Security Guards</span></a>
    <a href="?page=history" class="nav-item <?php echo $currentPage == 'history' ? 'active' : ''; ?>" data-page="history"><i class="fa-solid fa-box-archive"></i><span>Archived Requests</span></a>
    <a href="?page=summary" class="nav-item <?php echo $currentPage == 'summary' ? 'active' : ''; ?>" data-page="summary"><i class="fa-solid fa-chart-column"></i><span>Summary Report</span></a>
     </nav>
    <div class="sidebar-footer">
      <a href="?logout=1" class="text-muted-link">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 17v-2H7v-6h9V7l5 5-5 5zm-11 3h8v2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8v2H5v16z"/></svg>
        <span>Log Out</span>
      </a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main">
    <?php $pageTitles = [
      'requests' => 'Resident Requests',
      'resident_guest_forms' => "Resident's Guest Request",
      'visitor_requests' => 'Visitor Requests',
      'reservations' => 'Reservations',
      'report' => 'View Reported Incidents',
      'security' => 'Security Guards',
      'residents' => 'Residents',
      'cancelled' => 'Cancelled Requests',
      'summary' => 'Summary Report',
      'dashboard' => 'Dashboard'
    ];
    $pageTitle = $pageTitles[$currentPage] ?? ucfirst($currentPage); ?>
    <header class="top-header">
      <div class="header-brand">
        <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle sidebar" title="Toggle sidebar">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>
        </button>
        <div class="header-brand-text">
          <div class="header-title">Admin Dashboard</div>
          <div class="header-subtitle">Victorian Heights</div>
        </div>
      </div>
      <div class="header-search">
        <div class="search"><input id="search-input" placeholder="Search <?php echo htmlspecialchars($pageTitle); ?>..."></div>
      </div>
      <?php 
        $notifPayments = getPendingPaymentCount($con); 
        $notifAwaiting = getAmenityAwaitingPaymentCount($con); 
        $notifReady = getAmenityReadyForApprovalCount($con);
        $notifIncidents = getOpenIncidentCount($con);
        $notifNewReqs = getNewRequestsCount($con);
        $notifSystem = getUnreadSystemNotificationsCount($con);
        $notifTotal = $notifPayments + $notifAwaiting + $notifReady + $notifIncidents + $notifNewReqs + $notifSystem;
        $recent = getRecentNotifications($con);
      ?>
      <div class="header-actions">
        <div class="notifications">
          <button id="notifToggle" class="notif-btn" aria-label="Notifications" title="Notifications">
            <img alt="Notifications" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4a1.5 1.5 0 10-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z' fill='%23fff'/></svg>" />
            <?php if($notifTotal>0){ $badgeText = ($notifTotal>99) ? '99+' : (string)intval($notifTotal); echo "<span class='notif-badge'>".$badgeText."</span>"; } ?>
          </button>
          <div id="notifPanel" class="notif-panel" style="display:none"></div>
          <div id="notifModal" class="modal">
            <div class="modal-content">
              <button type="button" class="close" id="notifModalClose" aria-label="Close">✕</button>
              <h3>Notifications</h3>
             <!--<div class="tabs">
                <button class="tab-btn active" data-tab="req">Requests</button>
                <button class="tab-btn" data-tab="rec">Payment Receipts</button>
              </div> -->
              <div id="tabReq" class="tab-body">
                <div id="notifRequestsList"></div>
              </div>
              <div id="tabRec" class="tab-body" style="display:none">
                <div id="notifReceiptsList"></div>
              </div>
            </div>
          </div>
        </div>
        <img class="avatar" src="images/mainpage/profile'.jpg" alt="admin">
      </div>
    </header>

    <div class="page-header">
      <h2 id="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
      <script>
        (function(){
          const input=document.getElementById('search-input');
          function filter(){
            const q=(input.value||'').toLowerCase().trim();
            const main=document.querySelector('.main');
            const tables=main.querySelectorAll('table');
            tables.forEach(function(tbl){
              const rows=tbl.querySelectorAll('tbody tr');
              let any=false;
              rows.forEach(function(r){
                const t=(r.textContent||'').toLowerCase();
                const show=!q||t.indexOf(q)>=0;
                r.style.display=show?'':'none';
                any=any||show;
              });
              const thead=tbl.querySelector('thead');
              const cols=(thead?thead.querySelectorAll('th').length:tbl.rows[0]?tbl.rows[0].cells.length:1)||1;
              let emptyRow=tbl.querySelector('tr.search-empty');
              if(!any&&q){
                if(!emptyRow){
                  emptyRow=document.createElement('tr');
                  emptyRow.className='search-empty';
                  const td=document.createElement('td');
                  td.colSpan=cols; td.style.textAlign='center'; td.style.color='#6b6b6b'; td.textContent='No results';
                  emptyRow.appendChild(td);
                  const tb=tbl.querySelector('tbody')||tbl; tb.appendChild(emptyRow);
                }
              } else { if(emptyRow) emptyRow.remove(); }
            });
          }
          if(input){ input.addEventListener('input',filter); }
          const t=document.getElementById('notifToggle');
          const p=document.getElementById('notifPanel');
          const m=document.getElementById('notifModal');
          const mc=document.getElementById('notifModalClose');
          if(t&&m){ t.addEventListener('click',function(){ m.style.display = (m.style.display==='flex') ? 'none' : 'flex'; }); }
          if(mc&&m){ mc.addEventListener('click',function(){ m.style.display='none'; }); }
          document.addEventListener('click',function(e){ if(m && e.target===m){ m.style.display='none'; } });
          var lastTotal = null;
          var dismissed = new Set();
          function formatNotifDateTime(value){
            if(!value) return '';
            var d=new Date(value);
            if(isNaN(d.getTime())) return String(value);
            var mm=String(d.getMonth()+1).padStart(2,'0');
            var dd=String(d.getDate()).padStart(2,'0');
            var yy=String(d.getFullYear()).slice(-2);
            var h=d.getHours();
            var mi=String(d.getMinutes()).padStart(2,'0');
            var ampm=h>=12?'PM':'AM';
            h=h%12; if(h===0) h=12;
            return mm+'.'+dd+'.'+yy+' '+h+':'+mi+' '+ampm;
          }
          function keyFor(it){ return [String(it.type||''), String(it.ref||''), String(it.time||'')].join('|'); }
          function renderNotif(data){
            if(!data) return;
            var badge = t && t.querySelector('.notif-badge');
            var itemsRaw = Array.isArray(data.items)?data.items:[];
            var items = [];
            for(var i=0;i<itemsRaw.length;i++){ var k=keyFor(itemsRaw[i]); if(!dismissed.has(k)) items.push(itemsRaw[i]); }
            var total = parseInt(data.total||0,10);
            if(!total && items.length){ total = items.length; }
            if(t){
              if(total>0){ if(!badge){ badge=document.createElement('span'); badge.className='notif-badge'; t.appendChild(badge);} badge.textContent = (total>99 ? '99+' : String(total)); if(lastTotal!==null && total>lastTotal){ badge.classList.add('pulse'); setTimeout(function(){ badge.classList.remove('pulse'); }, 1200); } }
              else { if(badge){ badge.remove(); } }
            }
            var reqList = document.getElementById('notifRequestsList');
            var recList = document.getElementById('notifReceiptsList');
            var requests = Array.isArray(data.requests)?data.requests:[];
            var receipts = Array.isArray(data.receipts)?data.receipts:[];
            var build = function(arr){
              var list = (arr||[]).filter(function(it){ return !dismissed.has(keyFor(it)); });
              list.sort(function(a,b){ var ea=parseInt(a.epoch||0,10)||0; var eb=parseInt(b.epoch||0,10)||0; return eb - ea; });
              var html='';
              if(list.length===0){ html+="<div class='notif-item'><div class='notif-meta'>No items</div></div>"; }
              for(var i=0;i<list.length;i++){
                var it=list[i]||{}; var typeUpper=String(it.type||'').toUpperCase(); var badge=(it.label?String(it.label):typeUpper); var typeLower=String(it.type||'').toLowerCase(); var title=String(it.title||''); var ref=it.ref?String(it.ref):''; var amen=it.amenity?String(it.amenity):''; var rawTime=String(it.time||''); var time=formatNotifDateTime(rawTime); var href = linkFor(it); var nid=it.id||'';
                html += "<div class='notif-item' data-id='"+nid+"' data-type='"+typeLower+"' data-ref='"+ref.replace(/[<>]/g,'')+"' data-time='"+rawTime+"'>"
                  + "<a class='notif-item-link' href='"+href+"'>"
                  + "<div class='notif-type'>"+badge+"</div>"
                  + "<div class='notif-meta'><div><strong>"+title.replace(/[<>]/g,'')+"</strong>"+(amen?" — "+amen.replace(/[<>]/g,''):'')+"</div>"+(ref?"<div>Status Code: "+ref.replace(/[<>]/g,'')+"</div>":"")+"<div style='color:#888'>"+time+"</div></div>"
                  + "</a>"
                  + "<button type='button' class='notif-dismiss'>Dismiss</button>"
                  + "</div>";
              }
              return html;
            };
            if(reqList){ reqList.innerHTML = build(requests); }
            if(recList){ recList.innerHTML = build(receipts); }
            lastTotal = total;
          }
          function pollNotifications(){ fetch('admin.php?action=get_notifications').then(function(r){ return r.json(); }).then(function(data){ renderNotif(data); }).catch(function(){}); }
          var lastSeenEpoch = 0;
          function linkFor(it){
            var type=(it.type||'').toLowerCase(), src=(it.source||''), base='?page=dashboard';
            if(type==='payment') base='?page=requests';
            else if(type==='resident_guest') base='?page=resident_guest_forms';
            else if(type==='amenity'||type==='approval') base=(src==='guest_form' ? '?page=resident_guest_forms' : '?page=requests');
            else if(type==='request') base=(src==='resident'? '?page=requests' : '?page=visitor_requests');
            else if(type==='incident') base='?page=report';
            else if(type==='notification'){
              var msg=String(it.title||'').toLowerCase();
              base = (msg.indexOf('cancel')!==-1 ? '?page=history' : '?page=dashboard');
            }
            var ref = it.ref ? String(it.ref) : '';
            if(!ref && type==='notification'){
              var m = String(it.title||'').match(/(?:Reservation|Amenity request|Guest request)\s+([A-Za-z0-9\-]+)/i);
              if(m){ ref = m[1]; }
            }
            if(ref){
              base += (base.indexOf('?')>=0 ? '&' : '?') + 'ref=' + encodeURIComponent(ref);
            }
            return base;
          }
          (function(){ var tabs = document.querySelectorAll('.tab-btn'); var tabReq = document.getElementById('tabReq'); var tabRec = document.getElementById('tabRec'); tabs.forEach(function(btn){ btn.addEventListener('click', function(){ tabs.forEach(function(b){ b.classList.remove('active'); }); btn.classList.add('active'); var t = btn.getAttribute('data-tab'); if(t==='req'){ if(tabReq) tabReq.style.display='block'; if(tabRec) tabRec.style.display='none'; } else { if(tabReq) tabReq.style.display='none'; if(tabRec) tabRec.style.display='block'; } }); }); })();
          function showToast(it){ var c=document.getElementById('toastContainer'); if(!c||!it) return; var el=document.createElement('div'); el.className='toast'; var safeTitle=String(it.title||'').replace(/[<>]/g,''); var safeAmen=it.amenity?String(it.amenity).replace(/[<>]/g,''):''; var safeRef=it.ref?String(it.ref).replace(/[<>]/g,''):''; var href=linkFor(it);
            el.innerHTML = "<div><h4>New "+(String(it.type||'').toUpperCase())+"</h4><p>"+safeTitle+(safeAmen?" — "+safeAmen:'')+(safeRef?" (Status Code: "+safeRef+")":"")+"</p><div class='actions'><a href='"+href+"' class='btn btn-view'><i class='fa-solid fa-eye'></i> Open</a><button class='btn btn-remove'><i class='fa-solid fa-xmark'></i> Dismiss</button></div></div>";
            var dismissBtn = el.querySelector('.btn-remove'); if(dismissBtn){ dismissBtn.addEventListener('click', function(){ var k = keyFor(it); dismissed.add(k); el.remove(); }); }
            c.appendChild(el); setTimeout(function(){ if(el&&el.parentNode){ el.remove(); } }, 8000);
          }
          var initialized = false;
          function handleData(data){
            try{
              renderNotif(data);
              var items = Array.isArray(data.items)?data.items:[];
              if(items.length>0){
                var newest = items[0];
                var t = parseInt(newest.epoch||0,10);
                if(!initialized){ lastSeenEpoch = t||0; initialized = true; }
                else if(!isNaN(t) && t>lastSeenEpoch){ showToast(newest); lastSeenEpoch = t; }
              }
            } catch(e){}
          }
          function poll(){ fetch('admin.php?action=get_notifications').then(function(r){ return r.json(); }).then(handleData).catch(function(){}); }
          poll();
          var pollMs = 2000; var timer = setInterval(poll, pollMs);
          document.addEventListener('visibilitychange', function(){ if(document.hidden){ clearInterval(timer); timer = setInterval(poll, 5000); } else { clearInterval(timer); timer = setInterval(poll, pollMs); poll(); } });
          function dismissItem(e){ var btn=e.target.closest('.notif-dismiss'); if(!btn) return; var item=btn.closest('.notif-item'); if(!item) return; var k=[item.getAttribute('data-type')||'', item.getAttribute('data-ref')||'', item.getAttribute('data-time')||''].join('|'); var nid=item.getAttribute('data-id'); if(nid){ fetch('admin.php?action=dismiss_notification&id='+nid).catch(function(){}); } dismissed.add(k); item.remove(); }
          if(p){ p.addEventListener('click', dismissItem); }
          if(m){ m.addEventListener('click', dismissItem); }
        })();
      </script>
      <script>
        (function(){
          try{
            var params = new URLSearchParams(window.location.search);
            var ref = params.get('ref');
            if(ref){
              var row = document.querySelector('tr[data-ref="'+ref+'"]');
              if(row){
                row.classList.add('row-highlight');
                try{ row.scrollIntoView({behavior:'smooth', block:'center'}); }catch(e){}
                var id = row.getAttribute('data-id');
                var src = row.getAttribute('data-source');
                if(id && src){
                  var n = parseInt(id,10);
                  if(src==='resident'){ if(typeof showResidentReservationDetails==='function'){ showResidentReservationDetails(n); } }
                  else if(src==='visitor'){ if(typeof showReservationDetails==='function'){ showReservationDetails(n,'visitor'); } }
                  else if(src==='guest_form'){ if(typeof showVisitorDetails==='function'){ showVisitorDetails(n,'guest_form'); } }
                  else if(src==='reservation'){ if(typeof showVisitorDetails==='function'){ showVisitorDetails(n,'reservation'); } }
                }
              }
            }
          }catch(e){}
        })();
      </script>
      <script>
        (function(){
          var body = document.body;
          var toggle = document.getElementById('sidebarToggle');
          var key = 'adminSidebarCollapsed';
          var stored = localStorage.getItem(key);
          if(stored === '1'){ body.classList.add('sidebar-collapsed'); }
          if(toggle){
            toggle.addEventListener('click', function(){
              var collapsed = body.classList.toggle('sidebar-collapsed');
              localStorage.setItem(key, collapsed ? '1' : '0');
            });
          }
          var items = document.querySelectorAll('.nav-item');
          items.forEach(function(item){
            var span = item.querySelector('span');
            if(span){ item.setAttribute('title', span.textContent.trim()); }
          });
        })();
      </script>
    </div>

<!-- DASHBOARD -->
<?php if ($currentPage == 'dashboard'): ?>
<section class="panel" id="dashboard-panel">
  <h3>Community Overview</h3>
  <div class="dashboard-grid">
    <a class="dashboard-widget" href="?page=residents" aria-label="View Resident Accounts">
      <div class="dashboard-widget-value"><?php echo getPendingResidentAccountsCount($con); ?></div>
      <div class="dashboard-widget-label">Resident Accounts</div>
    </a>
    <a class="dashboard-widget" href="?page=requests" aria-label="View Pending Resident Requests">
      <div class="dashboard-widget-value"><?php echo getPendingResidentRequestsCountNew($con); ?></div>
      <div class="dashboard-widget-label">Pending Residents Request</div>
    </a>
    <a class="dashboard-widget" href="?page=visitors" aria-label="View Visitor Accounts">
      <div class="dashboard-widget-value"><?php echo getVisitorAccountsCount($con); ?></div>
      <div class="dashboard-widget-label">Visitors Accounts</div>
    </a>
    <a class="dashboard-widget" href="?page=visitor_requests" aria-label="View Pending Visitor Requests">
      <div class="dashboard-widget-value"><?php echo getPendingVisitorRequestsCountNew($con); ?></div>
      <div class="dashboard-widget-label">Pending Visitor Requests</div>
    </a>
  </div>
</section>
<?php endif; ?>

<?php if ($currentPage == 'summary'): ?>
<section class="panel" id="summary-panel">
  <h3>Summary Report</h3>
  <?php $report = getMonthlySummaryData($con, $_GET['month'] ?? date('Y-m')); $selMonth = $report['month']; $monthLabel = $report['label']; $cards = $report['cards'] ?? []; ?>
  <div style="display:flex; gap:12px; align-items:center; margin:10px 0 18px; flex-wrap:wrap;">
    <form method="GET" action="admin.php" style="display:flex; gap:10px; align-items:center;">
      <input type="hidden" name="page" value="summary">
      <label for="monthSel">Month</label>
      <input id="monthSel" type="month" name="month" value="<?php echo htmlspecialchars($selMonth); ?>" required style="padding:8px 10px; border:1px solid #e0e0e0; border-radius:8px;" onchange="this.form.submit()">
    </form>
    <form method="GET" action="admin.php" target="_blank" style="display:flex; gap:10px; align-items:center;">
      <input type="hidden" name="action" value="export_monthly_report">
      <input type="hidden" name="format" value="xlsx">
      <input type="hidden" name="month" value="<?php echo htmlspecialchars($selMonth); ?>">
      <button type="submit" class="btn btn-view"><i class="fa-solid fa-download"></i> Export Excel</button>
    </form>
  </div>
  <div class="dashboard-grid" style="padding:0; margin:0 0 20px;">
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['resident_amenity_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Total Resident Amenity Reservations</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['visitor_amenity_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Total Visitor Amenity Reservations</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['resident_activities_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Resident Activities (Approved Guests + Incidents)</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['most_requested_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Most Requested Amenities (Total Reservations)</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['payment_transactions_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Payment Transactions Activity (Residents + Visitors)</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-value"><?php echo intval($cards['scheduled_arrivals_total'] ?? 0); ?></div>
      <div class="dashboard-widget-label">Guard Scheduled Arrivals (Admin Approved)</div>
    </div>
  </div>
</section>
<?php endif; ?>



<!-- RESIDENT GUEST FORMS -->
<?php if ($currentPage == 'resident_guest_forms'): ?>
  <section class="panel" id="resident-guest-forms-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Resident’s Guest Requests</h3>
      <div class="notice">Requests from residents to add guests</div>
      <table class="table table-resident-guest">
        <thead>
          <tr>
            <th>Resident</th>
            <th>Guest Name</th>
            <th>Request Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $residentRequests = getResidentVisitorRequests($con);
          $hasResidentRequests = false;
          if ($residentRequests && $residentRequests->num_rows > 0) {
              while ($req = $residentRequests->fetch_assoc()) {
                  $hasResidentRequests = true;
                  $isLegacy = array_key_exists('entry_pass_id', $req);
                  $srcAttr = $isLegacy ? 'reservation' : 'guest_form';
                  echo "<tr data-ref='" . htmlspecialchars($req['ref_code'] ?? '') . "' data-id='" . intval($req['id']) . "' data-source='" . $srcAttr . "'>";
                  
                  // Resident Info
                  $resName = trim(($req['res_first_name'] ?? '') . ' ' . ($req['res_last_name'] ?? ''));
                  $resHouse = !empty($req['res_house_number']) ? htmlspecialchars($req['res_house_number']) : 'N/A';
                  echo "<td>";
                  echo "<div style='font-weight:600; color:#333;'>" . htmlspecialchars($resName) . "</div>";
                  echo "<div style='font-size:0.85rem; color:#666;'>" . $resHouse . "</div>";
                  echo "</td>";

                  $fullName = trim(($req['full_name'] ?? '') . ' ' . ($req['middle_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  $reqDate = !empty($req['created_at']) ? date('M d, Y', strtotime($req['created_at'])) : '-';
                  echo "<td>" . $reqDate . "</td>";
                  
                  echo "<td class='actions'>";
                  $__ps2 = strtolower($rr['payment_status'] ?? '');
                  if ($__ps2 !== 'rejected') {
                  $__ps = strtolower($gar['payment_status'] ?? '');
                  if ($__ps !== 'rejected') {
                  $approval_status = $req['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : (($approval_status === 'denied' || $approval_status === 'cancelled') ? 'badge-rejected' : 'badge-pending');
                  echo "<div style='margin-bottom: 8px;'><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></div>";

                  $payStatus = null; $resIdMatch = null; $receiptPath = null; $isAmenity = !empty($req['amenity']);
                  if (!empty($req['ref_code'])) {
                    $stmtPay2 = $con->prepare("SELECT id, payment_status, receipt_path, receipt_attempts, denial_reason FROM reservations WHERE ref_code = ? LIMIT 1");
                    $stmtPay2->bind_param('s', $req['ref_code']);
                    $stmtPay2->execute(); $rp2 = $stmtPay2->get_result();
                    if($rp2 && ($pr2=$rp2->fetch_assoc())){ $payStatus = $pr2['payment_status'] ?? null; $resIdMatch = intval($pr2['id'] ?? 0); $receiptPath = $pr2['receipt_path'] ?? null; $receiptAttempts = intval($pr2['receipt_attempts'] ?? 0); $denialReasonVal = trim((string)($pr2['denial_reason'] ?? '')); }
                    $stmtPay2->close();
                  }
                  echo "<button type='button' class='btn btn-view' onclick=\"showVisitorDetails(" . intval($req['id']) . ", '" . htmlspecialchars($srcAttr, ENT_QUOTES) . "')\"><i class='fa-solid fa-eye'></i> View More Details</button>";
                  if ($isAmenity) {
                    $payStatusLower = strtolower($payStatus ?? '');
                    if ($payStatusLower !== 'verified') {
                      if (!empty($receiptPath)) {
                        $isPdf = (bool)preg_match('/\.pdf$/i', (string)$receiptPath);
                        if ($isPdf) {
                          echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($resIdMatch) . ", 'resident_guest_forms')\" style='margin:6px 0;'><i class='fa-solid fa-file'></i> Open Receipt (PDF)</button>";
                        } else {
                          echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($resIdMatch) . ", 'resident_guest_forms')\" style='margin:6px 0;'><i class='fa-solid fa-file'></i> Verify Payment Receipt</button>";
                        }
                      } else {
                        echo "<div class='muted' style='margin:6px 0;'>No receipt</div>";
                      }
                    }
                    if ($resIdMatch && !empty($receiptPath) && $payStatusLower !== 'verified') {
                      if (($receiptAttempts ?? 0) >= 3) {
                        echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                        echo "<input type='hidden' name='reservation_id' value='" . intval($resIdMatch) . "'>";
                        echo "<input type='hidden' name='action' value='deny_request'>";
                        echo "<input type='hidden' name='redirect_page' value='resident_guest_forms'>";
                        $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($denialReasonVal ?? '')), ENT_QUOTES) . "'" : "");
                        echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                        echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                        echo "</form>";
                      } else {
                        echo "<form method='post' style='display:inline;' onsubmit='return openDenyModal(this)'>";
                        echo "<input type='hidden' name='reservation_id' value='" . intval($resIdMatch) . "'>";
                        echo "<input type='hidden' name='action' value='reject_receipt'>";
                        echo "<input type='hidden' name='redirect_page' value='resident_guest_forms'>";
                        $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($denialReasonVal ?? '')), ENT_QUOTES) . "'" : "");
                        echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                        echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Reject</button>";
                        echo "</form>";
                      }
                    }
                  }
                  if ($approval_status == 'pending') {
                      $disabled = ($isAmenity && $payStatus !== 'verified');
                  echo "<form method='post' class='action-form action-approve'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                  echo "<input type='hidden' name='action' value='approve_request'>";
                  echo "<input type='hidden' name='redirect_page' value='resident_guest_forms'>";
                  echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . "><i class='fa-solid fa-check'></i> Approve</button>";
                  echo "</form>";
                  echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                  echo "<input type='hidden' name='action' value='deny_request'>";
                  echo "<input type='hidden' name='redirect_page' value='resident_guest_forms'>";
                  echo "<input type='hidden' name='denial_reason' class='denial-reason'>";
                  echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-reject") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . " onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                  echo "</form>";
                  } elseif ($approval_status == 'denied' || $approval_status == 'cancelled') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this " . $approval_status . " request? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                      echo "<input type='hidden' name='action' value='delete_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete</button>";
                      echo "</form>";
                  } else {
                      if ($approval_status === 'deleted') {
                        echo "<span class='muted'>Deleted by Resident</span>";
                      } else {
                        $approvedBy = !empty($req['approved_by']) ? "by Admin" : "";
                        if ($approval_status === 'approved' && !empty($req['ref_code'])) {
                          echo "<a class='btn btn-qr' href='qr_view.php?code=" . urlencode($req['ref_code']) . "' target='_blank' style='margin-right:6px;'><i class='fa-solid fa-qrcode'></i> View QR</a>";
                        }
                        echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy</span>";
                      }
                  }
                  }
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasResidentRequests) {
              echo "<tr><td colspan='4' style='text-align:center;'>No resident guest requests found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>

  </div>
</section>
<?php endif; ?>

<!-- RESERVATIONS -->
<?php if ($currentPage == 'reservations'): ?>
<section class="panel" id="reservations-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Reservations</h3>
      <table class="table table-reservations">
        <thead>
          <tr>
            <th>Name</th>
            <th>Reference Code</th>
            <th>Type</th>
            <th>House #</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Request Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $residentRes = getResidentReservations($con);
          $hasRR = false;
          if ($residentRes && $residentRes->num_rows > 0) {
              while ($rr = $residentRes->fetch_assoc()) {
                  $hasRR = true;
                  echo "<tr data-ref='" . htmlspecialchars($rr['ref_code'] ?? '') . "' data-id='" . intval($rr['id']) . "' data-source='resident'>";
                  $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($rr['ref_code'] ?? '-') . "</td>";
                  
                  $isResidentGuest = !empty($rr['gf_id']);
                  $uType = $isResidentGuest ? "Resident’s Guest" : ucfirst($rr['user_type'] ?? 'Resident');
                  $uTypeClass = ($rr['user_type'] === 'visitor') ? 'badge-pending' : 'badge-approved';
                  echo "<td><span class='badge $uTypeClass' style='font-size:0.8rem;'>$uType</span></td>";

                  echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                  echo "<td>" . htmlspecialchars($rr['amenity'] ?? '-') . "</td>";
                  $dateRange = (!empty($rr['start_date']) && !empty($rr['end_date'])) ? (date('M d', strtotime($rr['start_date'])) . ' - ' . date('M d, Y', strtotime($rr['end_date']))) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $dateRange . "</td>";
                  $approval_status = $rr['approval_status'] ?? 'pending';
                  $payStatusLower = strtolower($rr['payment_status'] ?? '');
                  $attemptsRr = intval($rr['receipt_attempts'] ?? 0);
                  $statusClass = 'badge-pending';
                  $statusLabel = ucfirst($approval_status);
                  if ($approval_status === 'approved') { $statusClass = 'badge-approved'; }
                  else if ($approval_status === 'denied' || $approval_status === 'cancelled') { $statusClass = 'badge-rejected'; $statusLabel = ucfirst($approval_status); }
                  else if ($payStatusLower === 'pending_update') { $statusClass = 'badge-pending'; $statusLabel = 'Pending (Resubmitted)'; }
                  else if ($payStatusLower === 'rejected') { $statusClass = 'badge-rejected'; $statusLabel = 'Rejected (Attempt ' . max($attemptsRr,1) . ' of 3)'; }
                  echo "<td><span class='badge $statusClass'>" . $statusLabel . "</span></td>";
                  echo "<td class='actions'>";
                  echo "<button type='button' class='btn btn-view' onclick='showReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'><i class='fa-solid fa-eye'></i> View Details</button>";
                  $psTmp = strtolower($rr['payment_status'] ?? '');
                  if ($psTmp === 'rejected') { echo "<div class='muted' style='margin-top:6px;'>Wait for the updated proof.</div>"; echo "</td>"; echo "</tr>"; continue; }
                  if ($approval_status == 'pending') {
                      $disabled = !isAmenityPaymentVerified($con, $rr['ref_code'] ?? '');
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                      echo "<input type='hidden' name='redirect_page' value='reservations'>";
                      echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . "><i class='fa-solid fa-check'></i> Approve</button>";
                      echo "</form>";

                } elseif ($approval_status == 'denied' || $approval_status == 'cancelled') {
                    echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this " . $approval_status . " reservation? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                    echo "<input type='hidden' name='redirect_page' value='reservations'>";
                    echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete</button>";
                    echo "</form>";
                  } else {
                      $approvedBy = !empty($rr['approved_by']) ? "by Admin" : "";
                      if ($approval_status === 'approved' && !empty($rr['ref_code'])) {
                        echo "<a class='btn btn-qr' href='qr_view.php?code=" . urlencode($rr['ref_code']) . "' target='_blank' style='margin-right:6px;'><i class='fa-solid fa-qrcode'></i> View QR</a>";
                      }
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasRR) {
              echo "<tr><td colspan='8' style='text-align:center;'>No reservations found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>

    

  </div>
</section>
<?php endif; ?>

<!-- RESIDENTS -->
<?php if ($currentPage == 'residents'): ?>
<section class="panel" id="residents-panel">
  <h3>Registered Residents</h3>
  <table class="table table-residents">
    <thead>
      <tr>
        <th>Name</th>
        <th>House Number</th>
        <th>Registered On</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $residents = getResidents($con);
      if ($residents && $residents->num_rows > 0) {
          while ($resident = $residents->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $resident['first_name'] . " " . $resident['last_name'] . "</td>";
              echo "<td>" . $resident['house_number'] . "</td>";
              echo "<td>" . date('M d, Y', strtotime($resident['created_at'])) . "</td>";
              echo "<td class='actions'>";
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($resident['id']) . ",\"resident\")'><i class='fa-solid fa-eye'></i> View Details</button>";
              $status = strtolower($resident['status'] ?? 'active');
              if ($status !== 'disabled') {
                echo "<form method='post' style='display:inline;' onsubmit='return openAdminConfirm(this, \"Deactivate this account?\")'>";
                echo "<input type='hidden' name='user_id' value='" . intval($resident['id']) . "'>";
                echo "<input type='hidden' name='user_action' value='deactivate_user'>";
                echo "<input type='hidden' name='redirect_page' value='residents'>";
                echo "<input type='text' name='suspension_reason' class='suspend-reason' placeholder='Reason' required maxlength='255'>";
                echo "<button type='submit' class='btn btn-reject'><i class='fa-solid fa-ban'></i> Deactivate</button>";
                echo "</form>";
              } else {
                echo "<form method='post' class='delete-form show' onsubmit='return openAdminConfirm(this, \"Delete this account permanently?\")' style='display:inline;'>";
                echo "<input type='hidden' name='user_id' value='" . intval($resident['id']) . "'>";
                echo "<input type='hidden' name='user_action' value='delete_user'>";
                echo "<input type='hidden' name='redirect_page' value='residents'>";
                echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete Account</button>";
                echo "</form>";
              }
              echo "</td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='4' style='text-align:center;'>No residents found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<?php if ($currentPage == 'visitors'): ?>
<section class="panel" id="visitors-panel">
  <h3>Registered Visitors</h3>
  <table class="table table-residents">
    <thead>
      <tr>
        <th>Name</th>
        <th>Status</th>
        <th>Registered On</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $visitors = getVisitors($con);
      if ($visitors && $visitors->num_rows > 0) {
          while ($visitor = $visitors->fetch_assoc()) {
              echo "<tr>";
              $fullName = trim(($visitor['first_name'] ?? '') . ' ' . ($visitor['last_name'] ?? ''));
              echo "<td>" . ($fullName !== '' ? $fullName : 'Visitor') . "</td>";
              $status = strtolower($visitor['status'] ?? 'active');
              $statusLabel = ucfirst($status);
              $statusClass = ($status === 'active') ? 'badge-approved' : (($status === 'pending') ? 'badge-pending' : 'badge-rejected');
              echo "<td><span class='badge $statusClass'>" . $statusLabel . "</span></td>";
              echo "<td>" . (!empty($visitor['created_at']) ? date('M d, Y', strtotime($visitor['created_at'])) : '-') . "</td>";
              echo "<td class='actions'>";
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($visitor['id']) . ",\"visitor\")'><i class='fa-solid fa-eye'></i> View Details</button>";
              if ($status !== 'disabled') {
                echo "<form method='post' style='display:inline;' onsubmit='return openAdminConfirm(this, \"Deactivate this account?\")'>";
                echo "<input type='hidden' name='user_id' value='" . intval($visitor['id']) . "'>";
                echo "<input type='hidden' name='user_action' value='deactivate_user'>";
                echo "<input type='hidden' name='redirect_page' value='visitors'>";
                echo "<input type='text' name='suspension_reason' class='suspend-reason' placeholder='Reason' required maxlength='255'>";
                echo "<button type='submit' class='btn btn-reject'><i class='fa-solid fa-ban'></i> Deactivate</button>";
                echo "</form>";
              } else {
                echo "<form method='post' class='delete-form show' onsubmit='return openAdminConfirm(this, \"Delete this account permanently?\")' style='display:inline;'>";
                echo "<input type='hidden' name='user_id' value='" . intval($visitor['id']) . "'>";
                echo "<input type='hidden' name='user_action' value='delete_user'>";
                echo "<input type='hidden' name='redirect_page' value='visitors'>";
                echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete Account</button>";
                echo "</form>";
              }
              echo "</td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='4' style='text-align:center;'>No visitors found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- Price Details Modal -->
<div id="priceDetailsModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closePriceDetailsModal()" aria-label="Close">✕</button>
    <h3>Price Details</h3>
    <div id="priceDetailsContent"></div>
  </div>
  </div>

<script>
function openPriceDetails(totalStr, downStr){
  var t = parseFloat(totalStr||'0');
  var d = (downStr && downStr !== '') ? parseFloat(downStr) : (t>0 ? Math.max(0, t*0.5) : 0);
  var r = Math.max(0, t - d);
  var el = document.getElementById('priceDetailsContent');
  if(el){
    var fmt = function(n){ return Number(n).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); };
    el.innerHTML = '<div class="request-details">'
      + '<div class="section-title">Price Breakdown</div>'
      + '<div class="info-grid">'
      + '<div class="info-row total-price"><span class="info-label">Total Price</span><span class="info-value">₱' + fmt(t) + '</span></div>'
      + '<div class="info-row price-down"><span class="info-label">Online Payment (Partial)</span><span class="info-value">₱' + fmt(d) + '</span></div>'
      + '<div class="info-row price-balance"><span class="info-label">Onsite Payment (Remaining)</span><span class="info-value">₱' + fmt(r) + '</span></div>'
      + '</div>'
      + '</div>';
  }
  var m = document.getElementById('priceDetailsModal'); if(m){ m.style.display = 'flex'; }
}
function closePriceDetailsModal(){ var m=document.getElementById('priceDetailsModal'); if(m){ m.style.display='none'; } }
window.addEventListener('click', function(e){ var m=document.getElementById('priceDetailsModal'); if(e.target===m){ m.style.display='none'; } });
</script>
<style>
.reason-input-wrap{position:relative;display:block;vertical-align:middle;margin:6px 0}
.reason-input-wrap .denial-reason{display:block;width:100%;box-sizing:border-box;padding:12px 44px 12px 14px;border:1px solid #e2e8f0;border-radius:10px;font-size:0.95rem}
.reason-input-wrap .edit-reason-btn{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:none;color:#23412e;cursor:pointer;padding:4px;width:32px;height:32px;border-radius:6px}
.reason-input-wrap .edit-reason-btn:hover{background:#f0f3f1}
</style>

<!-- Receipt Image Modal -->
<div id="receiptModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeReceiptModal()" aria-label="Close">✕</button>
    <div style="display:flex;flex-direction:column;gap:12px;max-height:85vh;">
      <div style="overflow-y: auto; flex: 1; display: flex; align-items: center; justify-content: center;">
        <img id="receiptModalImg" alt="Receipt" style="width:100%;height:auto;border-radius:8px"/>
        <a id="receiptModalPdfLink" href="#" target="_blank" style="display:none;padding:10px 14px;border:1px solid #ddd;border-radius:8px;color:#23412e;text-decoration:none;font-weight:600;">Open Receipt (PDF)</a>
      </div>
      <form id="receiptVerifyForm" method="post" style="display:none;justify-content:center;">
        <input type="hidden" name="reservation_id" id="receiptVerifyId">
        <input type="hidden" name="action" value="verify_receipt">
        <input type="hidden" name="redirect_page" id="receiptVerifyRedirect" value="requests">
        <button type="submit" class="btn btn-approve"><i class="fa-solid fa-receipt"></i> <i class="fa-solid fa-check"></i> Verify</button>
      </form>
      <div id="receiptVerifiedNote" class="muted" style="display:none;text-align:center;">Payment verified</div>
    </div>
  </div>
</div>
<script>
function openReceiptModal(src, reservationId, redirectPage){ var m=document.getElementById('receiptModal'); var img=document.getElementById('receiptModalImg'); var link=document.getElementById('receiptModalPdfLink'); var form=document.getElementById('receiptVerifyForm'); var note=document.getElementById('receiptVerifiedNote'); var idInput=document.getElementById('receiptVerifyId'); var redirectInput=document.getElementById('receiptVerifyRedirect'); var isPdf = typeof src === 'string' && src.toLowerCase().indexOf('.pdf') !== -1; if(img){ if(isPdf){ img.style.display='none'; } else { img.style.display='block'; img.src = src; } } if(link){ if(isPdf){ link.href = src; link.style.display='inline-flex'; } else { link.style.display='none'; link.href = '#'; } } if(form && idInput){ var rid = parseInt(reservationId || '0', 10); if(rid > 0){ idInput.value = String(rid); if(redirectInput){ redirectInput.value = redirectPage || 'requests'; } form.style.display = 'flex'; if(note){ note.style.display = 'none'; } } else { idInput.value = ''; form.style.display = 'none'; if(redirectInput){ redirectInput.value = redirectPage || 'requests'; } if(note){ note.style.display = 'block'; } } } if(m){ m.style.display='flex'; } }
function closeReceiptModal(){ var m=document.getElementById('receiptModal'); if(m){ m.style.display='none'; } }
window.addEventListener('click', function(e){ var m=document.getElementById('receiptModal'); if(e.target===m){ m.style.display='none'; } });
</script>

<div id="denyReasonModal" class="modal modal-top">
  <div class="modal-content" style="max-width:520px;padding:16px;gap:8px;">
    <button type="button" class="close" id="denyReasonClose" aria-label="Close">✕</button>
    <h3 id="denyReasonTitle">Confirm Rejection</h3>
    <div id="denyReasonMessage" style="margin:6px 0 8px;color:#5a6b7c;font-size:0.9rem;">Are you sure you want to reject this item?</div>
    <div id="denyReasonLabel" style="font-weight:600;margin-top:6px;">Reason</div>
    <textarea id="denyReasonInput" rows="3" style="width:100%;border:1px solid #e2e8f0;border-radius:10px;padding:10px;font-family:Poppins,Arial,sans-serif;min-height:90px;"></textarea>
    <div id="denyReasonError" style="display:none;color:#b91c1c;font-size:0.85rem;margin-top:6px;">Please enter a reason to continue.</div>
    <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:10px;">
      <button type="button" class="btn btn-view" id="denyReasonCancel"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-reject" id="denyReasonSubmit"><i class="fa-solid fa-check"></i> Confirm</button>
    </div>
  </div>
</div>
<script>
(function(){
  var modal = document.getElementById('denyReasonModal');
  var input = document.getElementById('denyReasonInput');
  var btnCancel = document.getElementById('denyReasonCancel');
  var btnClose = document.getElementById('denyReasonClose');
  var btnSubmit = document.getElementById('denyReasonSubmit');
  var titleEl = document.getElementById('denyReasonTitle');
  var msgEl = document.getElementById('denyReasonMessage');
  var labelEl = document.getElementById('denyReasonLabel');
  var errorEl = document.getElementById('denyReasonError');
  var pendingForm = null;
  var requireReason = false;

  function resolveRejectMessage(form){
    var actionInput = form.querySelector('input[name="action"]');
    var incidentInput = form.querySelector('input[name="incident_action"]');
    var redirectInput = form.querySelector('input[name="redirect_page"]');
    var actionVal = actionInput ? String(actionInput.value || '') : '';
    var incidentVal = incidentInput ? String(incidentInput.value || '') : '';
    var redirectVal = redirectInput ? String(redirectInput.value || '') : '';
    var av = actionVal.trim().toLowerCase();
    var iv = incidentVal.trim().toLowerCase();
    var rv = redirectVal.trim().toLowerCase();
    if (iv === 'reject') return 'Are you sure you want to reject this incident report?';
    if (av === 'reject_receipt') return 'Are you sure you want to proceed with rejecting this payment receipt?';
    if (av === 'deny_request' && rv === 'resident_guest_forms') return 'Are you sure to deny this guest request?';
    if (av === 'deny_request') return 'Are you sure you want to proceed with rejecting this amenity request?';
    if (av === 'deny_resident_reservation') return 'Are you sure you want to proceed with rejecting this reservation?';
    if (av === 'reject_reservation') return 'Are you sure you want to proceed with rejecting this reservation?';
    if (av === 'deny_user') return 'Are you sure you want to deny this account?';
    return 'Are you sure you want to reject this item?';
  }

  function openModal(form, mustHaveReason){
    pendingForm = form;
    requireReason = !!mustHaveReason;
    if (titleEl) {
      var a = form.querySelector('input[name="action"]');
      var av = a ? String(a.value || '').trim().toLowerCase() : '';
      var proceedTitles = ['reject_receipt','reject_reservation','deny_request','deny_resident_reservation'];
      titleEl.textContent = (proceedTitles.indexOf(av) !== -1) ? 'Proceed With Rejection' : 'Confirm Rejection';
    }
    if (msgEl) msgEl.textContent = resolveRejectMessage(form);
    if (labelEl) labelEl.textContent = requireReason ? 'Reason' : 'Reason (optional)';
    if (input) {
      var existing = form.querySelector('input[name="denial_reason"]');
      input.value = existing ? String(existing.value || '') : '';
      input.placeholder = requireReason ? 'Enter reason' : 'Optional reason';
      input.style.borderColor = '#e2e8f0';
    }
    if (errorEl) errorEl.style.display = 'none';
    if (modal) {
      modal.style.display = 'flex';
      document.body.classList.add('modal-open');
    }
  }

  function closeModal(){
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('modal-open');
    pendingForm = null;
    requireReason = false;
  }

  function submitModal(){
    if (!pendingForm) { closeModal(); return; }
    var reasonVal = input ? String(input.value || '').trim() : '';
    if (requireReason && reasonVal === '') {
      if (input) input.style.borderColor = '#b91c1c';
      if (errorEl) errorEl.style.display = 'block';
      if (input) input.focus();
      return;
    }
    var reasonInput = pendingForm.querySelector('input[name="denial_reason"]');
    if (reasonInput) reasonInput.value = reasonVal;
    pendingForm.dataset.rejectConfirmed = '1';
    pendingForm.submit();
    closeModal();
  }

  function bindRejectForm(form){
    if (!form || form.dataset.rejectBound === '1') return;
    var actionInput = form.querySelector('input[name="action"]');
    var incidentInput = form.querySelector('input[name="incident_action"]');
    var actionVal = actionInput ? String(actionInput.value || '') : '';
    var incidentVal = incidentInput ? String(incidentInput.value || '') : '';
    var isRejectAction = (actionVal && /reject|deny/i.test(actionVal)) || (incidentVal && /reject/i.test(incidentVal));
    if (!isRejectAction) return;
    var reasonInput = form.querySelector('input[name="denial_reason"]');
    if (reasonInput) {
      reasonInput.required = false;
      reasonInput.type = 'hidden';
    }
    form.dataset.rejectBound = '1';
    form.addEventListener('submit', function(e){
      if (form.dataset.rejectConfirmed === '1') {
        form.dataset.rejectConfirmed = '0';
        return;
      }
      e.preventDefault();
      openModal(form, !!reasonInput);
    });
  }

  if (btnCancel) btnCancel.addEventListener('click', closeModal);
  if (btnClose) btnClose.addEventListener('click', closeModal);
  if (btnSubmit) btnSubmit.addEventListener('click', submitModal);
  window.addEventListener('click', function(e){
    if (e.target === modal) closeModal();
  });

  document.querySelectorAll('form').forEach(bindRejectForm);

  window.openDenyModal = function(form){
    var reasonInput = form.querySelector('input[name="denial_reason"]');
    openModal(form, !!reasonInput);
    return false;
  };
  window.toggleReasonEdit = function(btn){
    var input = btn && btn.previousElementSibling;
    if(!input) return;
    var isReadonly = input.hasAttribute('readonly');
    if(isReadonly){
      input.removeAttribute('readonly');
      btn.innerHTML = '<i class="fa-solid fa-check"></i>';
      input.focus();
      var finalize = function(){
        input.setAttribute('readonly','readonly');
        btn.innerHTML = '<i class="fa-solid fa-pencil"></i>';
      };
      input.addEventListener('keydown', function(e){
        if(e.key === 'Enter'){
          e.preventDefault();
          finalize();
        }
      }, { once: true });
      input.addEventListener('blur', function(){
        finalize();
      }, { once: true });
    }else{
      input.setAttribute('readonly','readonly');
      btn.innerHTML = '<i class="fa-solid fa-pencil"></i>';
    }
  };
})();
</script>

<!-- SECURITY GUARDS -->
<?php if ($currentPage == 'security'): ?>
<section class="panel" id="security-panel">
  <h3>Security Guards on Duty</h3>
  <table class="table table-security">
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $guards = getSecurityGuards($con);
      if ($guards && $guards->num_rows > 0) {
          while ($guard = $guards->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $guard['id'] . "</td>";
              echo "<td>" . $guard['email'] . "</td>";
              echo "<td>" . $guard['role'] . "</td>";
              echo "<td><span class='badge badge-active'>On Duty</span></td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='4' style='text-align:center;'>No security guards found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- REQUESTS -->
<?php if ($currentPage == 'requests'): ?>
<section class="panel" id="requests-panel">
  <div class="content-row">
  
  <!-- Resident Amenity Requests (from resident_reservations) -->
  <div class="card-box">
    <h3>Resident Amenity Requests</h3>
    <table class="table table-requests">
      <thead>
        <tr>
          <th>Name</th>
          <th>Reference Code</th>
          <th>Type</th>
          <th>House #</th>
          <th>Request Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $residentRes = getResidentOnlyReservations($con);
        $hasRR = false;
        if ($residentRes && $residentRes->num_rows > 0) {
            while ($rr = $residentRes->fetch_assoc()) {
                $approval_status = $rr['approval_status'] ?? 'pending';
                $statusValue = $rr['status'] ?? '';
                $statusLower = strtolower((string)$statusValue);
                $scannedAt = $rr['scanned_at'] ?? null;
                $approvalLower = strtolower((string)$approval_status);
                if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
                  continue;
                }
                if (!empty($scannedAt) && !in_array($approvalLower, ['denied','cancelled','expired','moved_to_history'], true)) {
                  $stmtGrant = $con->prepare("UPDATE reservations SET approval_status='permission_granted', status='permission_granted', updated_at = NOW() WHERE id = ?");
                  if ($stmtGrant) { $stmtGrant->bind_param('i', $rr['id']); $stmtGrant->execute(); $stmtGrant->close(); }
                  continue;
                }
                $hasRR = true;
                echo "<tr data-ref='" . htmlspecialchars($rr['ref_code'] ?? '') . "' data-id='" . intval($rr['id']) . "' data-source='resident'>";
                $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                echo "<td>" . htmlspecialchars($rr['ref_code'] ?? '-') . "</td>";
                
                $isResidentGuest = !empty($rr['gf_id']);
                $uType = $isResidentGuest ? "Resident’s Guest" : ucfirst($rr['user_type'] ?? 'Resident');
                $uTypeClass = 'badge-approved';
                echo "<td><span class='badge $uTypeClass' style='font-size:0.8rem;'>$uType</span></td>";

                echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                $payStatusLower = strtolower($rr['payment_status'] ?? '');
                $attempts = intval($rr['receipt_attempts'] ?? 0);
                if ($payStatusLower === 'rejected' && $attempts >= 3 && strtolower($approval_status) !== 'denied') {
                  $stmtAuto = $con->prepare("UPDATE reservations SET approval_status='denied' WHERE id=?");
                  if ($stmtAuto) { $stmtAuto->bind_param('i', $rr['id']); $stmtAuto->execute(); $stmtAuto->close(); }
                  continue;
                }
                $statusClass = $approval_status === 'approved' ? 'badge-approved' : (($approval_status === 'denied' || $approval_status === 'cancelled') ? 'badge-rejected' : 'badge-pending');
                $statusLabel = ($payStatusLower === 'pending_update') ? 'Pending (Resubmitted)' : ucfirst($approval_status);
                if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
                  $statusClass = 'badge-approved';
                  $statusLabel = 'Access Granted';
                }
                if ($payStatusLower === 'rejected') { 
                  $statusClass = 'badge-rejected'; 
                  $statusLabel = ($attempts >= 3) ? 'Denied – Max Attempts Reached' : ('Rejected (Attempt ' . max($attempts,1) . ' of 3)');
                }
                echo "<td><span class='badge $statusClass'>" . $statusLabel . "</span></td>";
                echo "<td class='actions'>";
                echo "<button type='button' class='btn btn-view' onclick='showReservationDetails(" . intval($rr['id']) . ",\"visitor\")'><i class='fa-solid fa-eye'></i> View Details</button>";
                $payStatusLower = strtolower($rr['payment_status'] ?? '');
                if ($payStatusLower === 'rejected') { 
                  $attempts = intval($rr['receipt_attempts'] ?? 0);
                  $note = ($attempts >= 3) ? 'Denied — Max Attempts Reached. Payment rejected 3 times. No further uploads allowed.' : 'Wait for the updated proof.';
                  echo "<div class='muted' style='margin-top:6px;'>$note</div>"; 
                  $denReason = trim((string)($rr['denial_reason'] ?? ''));
                  if ($denReason !== '') {
                    echo "<div style='margin-top:6px;padding:8px 12px;border-radius:10px;background:#fee2e2;color:#991b1b;font-weight:600;width:100%;box-sizing:border-box;'>Reason: " . htmlspecialchars($denReason) . "</div>";
                  }
                }
                $receiptPath = $rr['receipt_path'] ?? null;
                $attempts = intval($rr['receipt_attempts'] ?? 0);
                if ($attempts >= 3) {
                  echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                  echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                  echo "<input type='hidden' name='action' value='deny_request'>";
                  echo "<input type='hidden' name='redirect_page' value='requests'>";
                  $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                  echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                  echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                  echo "</form>";
                } else if ($payStatusLower !== 'rejected') {
                  if ($payStatusLower !== 'verified') {
                    if (!empty($receiptPath)) {
                      $isPdf = (bool)preg_match('/\.pdf$/i', (string)$receiptPath);
                      if ($isPdf) {
                        echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($rr['id']) . ", 'requests')\"><i class='fa-solid fa-file'></i> Open Receipt (PDF)</button>";
                      } else {
                        echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($rr['id']) . ", 'requests')\"><i class='fa-solid fa-file'></i> Verify Payment Receipt</button>";
                      }
                    } else {
                      echo "<div class='muted'>No receipt</div>";
                    }
                  }
                  if (!empty($rr['id']) && !empty($receiptPath) && $payStatusLower !== 'verified') {
                    if ($attempts >= 3) {
                      echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_request'>";
                      echo "<input type='hidden' name='redirect_page' value='requests'>";
                      $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                      echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                      echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                      echo "</form>";
                    } else {
                      echo "<form method='post' onsubmit='return openDenyModal(this)'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='reject_receipt'>";
                      echo "<input type='hidden' name='redirect_page' value='requests'>";
                      $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                      echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                      echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Reject</button>";
                      echo "</form>";
                    }
                  }
                }
                if ($approval_status == 'pending') {
                    $disabled = !isAmenityPaymentVerified($con, $rr['ref_code'] ?? '');
                    echo "<form method='post'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                    echo "<input type='hidden' name='redirect_page' value='requests'>";
                    echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . "><i class='fa-solid fa-check'></i> Approve</button>";
                    echo "</form>";

                } elseif ($approval_status == 'denied' || $approval_status == 'cancelled') {
                    echo "<form method='post' onsubmit='return confirm(\"Delete this " . $approval_status . " reservation? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                    echo "<input type='hidden' name='redirect_page' value='requests'>";
                    echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete</button>";
                    echo "</form>";
                } else {
                    $approvedBy = !empty($rr['approved_by']) ? "by Admin" : "";
                    if ($approval_status === 'approved' && !empty($rr['ref_code'])) {
                      echo "<a class='btn btn-qr' href='qr_view.php?code=" . urlencode($rr['ref_code']) . "' target='_blank'><i class='fa-solid fa-qrcode'></i> View QR</a>";
                    }
                    echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
        }
        if (!$hasRR) {
            echo "<tr><td colspan='6' style='text-align:center;'>No amenity requests found</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- (removed duplicate verify section to avoid confusion) -->

<!-- REPORTS -->
<?php if ($currentPage == 'report'): ?>
<section class="panel" id="report-panel">
  <h3>Reported Incidents</h3>
  <table class="table table-report">
    <thead>
      <tr>
        <th>Report ID</th>
        <th>Reported By</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $reports = getIncidentReports($con);
      if ($reports && $reports->num_rows > 0) {
          while ($r = $reports->fetch_assoc()) {
              $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
              $displayName = $fullName !== '' ? $fullName : $r['complainant'];
              echo '<tr>';
              echo '<td>' . intval($r['id']) . '</td>';
              echo '<td>' . htmlspecialchars($displayName) . '</td>';
              $status = $r['status'];
              $badgeClass = $status === 'resolved' ? 'badge badge-approved' : ($status === 'rejected' ? 'badge badge-rejected' : ($status === 'cancelled' ? 'badge badge-expired' : 'badge badge-warning'));
              echo '<td><span class="' . $badgeClass . '">' . ucfirst($status) . '</span></td>';
              // Actions
              echo '<td>';
              echo '<button type="button" class="btn btn-view" onclick="showIncidentDetails(' . intval($r['id']) . ')" style="margin-right:6px;"><i class="fa-solid fa-eye"></i> View Details</button>';
              echo '<form method="POST" style="display:inline-block;margin-right:6px;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              if ($status === 'new' || $status === 'in_progress') {
                  echo '<input type="hidden" name="incident_action" value="resolve">';
                  echo '<button type="submit" class="btn btn-approve"><i class="fa-solid fa-check"></i> Resolve</button>';
              }
              echo '</form>';
              echo '<form method="POST" style="display:inline-block;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              echo '<input type="hidden" name="incident_action" value="reject">';
              echo '<button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark"></i> Reject</button>';
              echo '</form>';
              echo '<form method="POST" style="display:inline-block;margin-left:6px;" onsubmit="return confirm(\'Delete this incident report? This cannot be undone.\')">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              echo '<input type="hidden" name="incident_delete" value="1">';
              echo '<button type="submit" class="btn btn-delete"><i class="fa-solid fa-trash"></i> Delete</button>';
              echo '</form>';
              echo '</td>';
              echo '</tr>';
          }
      } else {
          echo '<tr><td colspan="4" style="text-align:center;">No incidents reported yet</td></tr>';
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- VISITOR REQUESTS -->
<?php if ($currentPage == 'visitor_requests'): ?>
<section class="panel" id="visitor-requests-panel">
  <div class="content-row">
  


    <!-- Visitor Account Amenity Requests -->
    <div class="card-box" style="margin-bottom: 20px;">
      <h3>Visitor Amenity Request</h3>
      <table class="table table-requests">
        <thead>
          <tr>
            <th>Name</th>
            <th>Reference Code</th>
            <th>Type</th>
            <th>Request Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $visitorRes = getVisitorAccountReservations($con);
          $hasVR = false;
          if ($visitorRes && $visitorRes->num_rows > 0) {
              while ($rr = $visitorRes->fetch_assoc()) {
                  $approval_status = $rr['approval_status'] ?? 'pending';
                  $statusValue = $rr['status'] ?? '';
                  $statusLower = strtolower((string)$statusValue);
                  $scannedAt = $rr['scanned_at'] ?? null;
                  $approvalLower = strtolower((string)$approval_status);
                  if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
                    continue;
                  }
                  if (!empty($scannedAt) && !in_array($approvalLower, ['denied','cancelled','expired','moved_to_history'], true)) {
                    $stmtGrant = $con->prepare("UPDATE reservations SET approval_status='permission_granted', status='permission_granted', updated_at = NOW() WHERE id = ?");
                    if ($stmtGrant) { $stmtGrant->bind_param('i', $rr['id']); $stmtGrant->execute(); $stmtGrant->close(); }
                    continue;
                  }
                  $hasVR = true;
                  echo "<tr data-ref='" . htmlspecialchars($rr['ref_code'] ?? '') . "' data-id='" . intval($rr['id']) . "' data-source='visitor'>";
                  $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($rr['ref_code'] ?? '-') . "</td>";
                  
                  $uType = ucfirst($rr['user_type'] ?? 'Visitor');
                  $uTypeClass = 'badge-approved'; 
                  echo "<td><span class='badge $uTypeClass' style='font-size:0.8rem;'>$uType</span></td>";
                  $payStatusLower = strtolower($rr['payment_status'] ?? '');
                  $attempts = intval($rr['receipt_attempts'] ?? 0);
                  if ($payStatusLower === 'rejected' && $attempts >= 3 && strtolower($approval_status) !== 'denied') {
                    $stmtAuto = $con->prepare("UPDATE reservations SET approval_status='denied' WHERE id=?");
                    if ($stmtAuto) { $stmtAuto->bind_param('i', $rr['id']); $stmtAuto->execute(); $stmtAuto->close(); }
                    continue;
                  }
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : (($approval_status === 'denied' || $approval_status === 'cancelled') ? 'badge-rejected' : 'badge-pending');
                  $statusLabel = ($payStatusLower === 'pending_update') ? 'Pending (Resubmitted)' : ucfirst($approval_status);
                  if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
                    $statusClass = 'badge-approved';
                    $statusLabel = 'Access Granted';
                  }
                  if ($payStatusLower === 'rejected') { $statusClass = 'badge-rejected'; $statusLabel = 'Rejected (Attempt ' . max($attempts,1) . ' of 3)'; }
                  echo "<td><span class='badge $statusClass'>" . $statusLabel . "</span></td>";
                  echo "<td class='actions'>";
                  echo "<button type='button' class='btn btn-view' onclick='showReservationDetails(" . intval($rr['id']) . ",\"visitor\")'><i class='fa-solid fa-eye'></i> View Details</button>";
                  $payStatusLower = strtolower($rr['payment_status'] ?? '');
                  if ($payStatusLower === 'rejected') { 
                    $attempts = intval($rr['receipt_attempts'] ?? 0);
                    $denialReasonVal = trim((string)($rr['denial_reason'] ?? ''));
                    $note = ($attempts >= 3) ? 'Denied — Max Attempts Reached. Payment rejected 3 times. No further uploads allowed.' : 'Wait for the uploaded proof.';
                    echo "<div class='muted' style='margin-top:6px;'>$note</div>"; 
                    if (!empty($denialReasonVal)) {
                      echo "<div style='margin-top:6px;padding:8px 12px;border-radius:10px;background:#fee2e2;color:#991b1b;font-weight:600;width:100%;box-sizing:border-box;'>Reason: " . htmlspecialchars($denialReasonVal) . "</div>";
                    }
                    echo "</td>"; echo "</tr>"; continue; 
                  }
                  $attempts = intval($rr['receipt_attempts'] ?? 0);
                  if ($attempts >= 3) {
                    echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                    echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='deny_request'>";
                    echo "<input type='hidden' name='redirect_page' value='visitor_requests'>";
                    $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                    echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                    echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                    echo "</form>";
                  }
                  if ($payStatusLower !== 'rejected') {
                  $receiptPath = $rr['receipt_path'] ?? null;
                  if ($payStatusLower !== 'verified') {
                  if (!empty($receiptPath)) {
                    $isPdf = (bool)preg_match('/\.pdf$/i', (string)$receiptPath);
                    if ($isPdf) {
                      echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($rr['id']) . ", 'visitor_requests')\"><i class='fa-solid fa-file'></i> Open Receipt (PDF)</button>";
                    } else {
                      echo "<button type='button' class='btn btn-receipt' onclick=\"openReceiptModal('" . htmlspecialchars($receiptPath) . "', " . intval($rr['id']) . ", 'visitor_requests')\"><i class='fa-solid fa-file'></i> Verify Payment Receipt</button>";
                    }
                  } else {
                    echo "<div class='muted'>No receipt</div>";
                  }
                  }
                  if (!empty($rr['id']) && !empty($receiptPath) && $payStatusLower !== 'verified') {
                    $attempts = intval($rr['receipt_attempts'] ?? 0);
                    if ($attempts >= 3) {
                      echo "<form method='post' class='action-form action-deny' onsubmit='return openDenyModal(this)'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_request'>";
                      echo "<input type='hidden' name='redirect_page' value='visitor_requests'>";
                      $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                      echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                      echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Deny</button>";
                      echo "</form>";
                    } else {
                      echo "<form method='post' onsubmit='return openDenyModal(this)'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='reject_receipt'>";
                      echo "<input type='hidden' name='redirect_page' value='visitor_requests'>";
                      $valueAttr = ($payStatusLower === 'pending_update' ? " value='" . htmlspecialchars(trim((string)($rr['denial_reason'] ?? '')), ENT_QUOTES) . "'" : "");
                      echo "<input type='hidden' name='denial_reason' class='denial-reason'".$valueAttr.">";
                      echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'><i class='fa-solid fa-xmark'></i> Reject</button>";
                      echo "</form>";
                    }
                  }
                  if ($approval_status == 'pending') {
                      $disabled = !isAmenityPaymentVerified($con, $rr['ref_code'] ?? '');
                      echo "<form method='post'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                      echo "<input type='hidden' name='redirect_page' value='visitor_requests'>";
                      echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . "><i class='fa-solid fa-check'></i> Approve</button>";
                      echo "</form>";

                  } elseif ($approval_status == 'denied' || $approval_status == 'cancelled') {
                      echo "<form method='post' onsubmit='return confirm(\"Delete this " . $approval_status . " reservation? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                      echo "<input type='hidden' name='redirect_page' value='visitor_requests'>";
                      echo "<button type='submit' class='btn btn-remove'><i class='fa-solid fa-trash'></i> Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($rr['approved_by']) ? "by Admin" : "";
                      if ($approval_status === 'approved' && !empty($rr['ref_code'])) {
                        echo "<a class='btn btn-qr' href='qr_view.php?code=" . urlencode($rr['ref_code']) . "' target='_blank'><i class='fa-solid fa-qrcode'></i> View QR</a>";
                      }
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy</span>";
                  }
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasVR) {
              echo "<tr><td colspan='5' style='text-align:center;'>No visitor account amenity requests found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>


</section>
<?php endif; ?>

<!-- ARCHIVED REQUESTS -->
<?php if ($currentPage == 'history'): ?>
<section class="panel" id="history-panel">
  <div class="content-row">
    <div class="card-box">
      <h3>Archived Requests (Cancelled, Completed, Access Granted)</h3>
      <div class="notice">List of all cancelled and completed requests. You can permanently delete them here.</div>
      <table class="table table-history">
        <thead>
          <tr>
            <th>Type & Status</th>
            <th>Name</th>
            <th>Reference Code</th>
            <th>Details</th>
            <th>Dates</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $hasArchived = false;
          
          // 1. Archived Guest Forms
          $gf = $con->query("SELECT gf.*, gf.visitor_first_name, gf.visitor_last_name, gf.updated_at FROM guest_forms gf WHERE gf.approval_status IN ('cancelled', 'completed', 'moved_to_history', 'permission_granted','deleted') ORDER BY gf.updated_at DESC, gf.created_at DESC");
          if ($gf) {
            while ($row = $gf->fetch_assoc()) {
               $hasArchived = true;
               $rawStatus = strtolower($row['approval_status'] ?? '');
               if ($rawStatus === 'permission_granted') {
                 $statusLabel = 'Access Granted';
                 $badgeClass = 'badge-approved';
               } elseif ($rawStatus === 'deleted') {
                 $statusLabel = 'Deleted by Resident';
                 $badgeClass = 'badge-rejected';
               } else {
                 $status = $rawStatus;
                 $badgeClass = ($status === 'completed' || $status === 'approved') ? 'badge-approved' : 'badge-rejected';
                 $statusLabel = ucfirst($status);
               }
               
               $name = htmlspecialchars(($row['visitor_first_name']??'') . ' ' . ($row['visitor_last_name']??''));
              $details = "Role: " . htmlspecialchars($row['purpose']??'Co-owner');
               if (!empty($row['amenity'])) $details .= "<br>Amenity: " . htmlspecialchars($row['amenity']);
               $date = (!empty($row['start_date']) ? date('M d', strtotime($row['start_date'])) : '') . 
                       (!empty($row['end_date']) ? ' - ' . date('M d', strtotime($row['end_date'])) : '');
              $refCode = htmlspecialchars($row['ref_code'] ?? '-');
               $updatedAt = !empty($row['updated_at']) ? date('M d, Y H:i', strtotime($row['updated_at'])) : '-';
               
               echo "<tr>";
               echo "<td><div style='display:flex;flex-direction:column;gap:4px;'><span class='badge' style='background:#ccc;color:#333'>Guest Form</span><span class='badge $badgeClass'>$statusLabel</span></div></td>";
               echo "<td><strong>$name</strong></td>";
              echo "<td>$refCode</td>";
               echo "<td>$details</td>";
               echo "<td>$date</td>";
               
               echo "<td>";
               echo "<form method='post' onsubmit='return confirm(\"Permanently delete this archived request?\");'>";
               echo "<input type='hidden' name='action' value='delete_reservation'>";
               echo "<input type='hidden' name='reservation_id' value='" . intval($row['id']) . "'>";
               echo "<input type='hidden' name='redirect_page' value='history'>";
               echo "<button type='submit' class='btn btn-remove' style='display:flex;align-items:center;gap:5px;'><i class='fa-solid fa-trash'></i> Delete</button>";
               echo "</form>";
               echo "</td>";
               echo "</tr>";
            }
          }
          
          // 2. Archived Reservations
          $hasReservationUpdatedAt = false;
          if ($con instanceof mysqli) {
            $chkUpdated = $con->query("SHOW COLUMNS FROM reservations LIKE 'updated_at'");
            $hasReservationUpdatedAt = $chkUpdated && $chkUpdated->num_rows > 0;
          }
          $orderClause = $hasReservationUpdatedAt ? "r.updated_at DESC, r.created_at DESC" : "r.created_at DESC";
          $res = $con->query("SELECT r.*, u.first_name, u.last_name, u.user_type, u.house_number FROM reservations r LEFT JOIN users u ON r.user_id = u.id WHERE (r.status IN ('cancelled', 'completed', 'expired', 'moved_to_history', 'permission_granted', 'denied') OR r.approval_status IN ('cancelled', 'completed', 'expired', 'moved_to_history', 'permission_granted', 'denied')) ORDER BY $orderClause");
          if ($res) {
            while ($row = $res->fetch_assoc()) {
               $hasArchived = true;
               $status = 'cancelled';
               $s = strtolower($row['status']??'');
               $as = strtolower($row['approval_status']??'');
               if ($s === 'permission_granted' || $as === 'permission_granted') { 
                 $status = 'access granted';
               }
               elseif ($s === 'completed' || $as === 'completed') { $status = 'completed'; }
               elseif ($s === 'expired' || $as === 'expired') { $status = 'expired'; }
               elseif ($s === 'moved_to_history' || $as === 'moved_to_history') { $status = 'cancelled'; }
               elseif ($s === 'approved' || $as === 'approved') { $status = 'approved'; }
               elseif ($s === 'denied' || $as === 'denied') { $status = 'denied'; }
               
               if ($status === 'access granted') {
                 $badgeClass = 'badge-approved';
                 $statusLabel = 'Access Granted';
               } else {
                 $badgeClass = ($status === 'completed' || $status === 'approved') ? 'badge-approved' : (($status === 'expired') ? 'badge-rejected' : 'badge-rejected');
                 $statusLabel = ucfirst($status);
               }

               $uType = ucfirst($row['user_type'] ?? 'Visitor');
               $name = htmlspecialchars(($row['first_name']??'') . ' ' . ($row['last_name']??''));
               if (empty(trim($name)) && !empty($row['entry_pass_id'])) {
                   $name = "Visitor (Entry Pass)";
               }
              $details = "Amenity: " . htmlspecialchars($row['amenity']??'-');
               $date = (!empty($row['start_date']) ? date('M d', strtotime($row['start_date'])) : '') . 
                       (!empty($row['end_date']) ? ' - ' . date('M d', strtotime($row['end_date'])) : '');
              $refCode = htmlspecialchars($row['ref_code'] ?? '-');
               $updatedAt = !empty($row['updated_at']) ? date('M d, Y H:i', strtotime($row['updated_at'])) : '-';
               
               echo "<tr>";
               echo "<td><div style='display:flex;flex-direction:column;gap:4px;'><span class='badge' style='background:#ccc;color:#333'>Reservation ($uType)</span><span class='badge $badgeClass'>$statusLabel</span></div></td>";
               echo "<td><strong>$name</strong></td>";
              echo "<td>$refCode</td>";
               echo "<td>$details</td>";
               echo "<td>$date</td>";
               
               echo "<td>";
               echo "<form method='post' onsubmit='return confirm(\"Permanently delete this archived request?\");'>";
               echo "<input type='hidden' name='action' value='delete_reservation'>";
               echo "<input type='hidden' name='reservation_id' value='" . intval($row['id']) . "'>";
               echo "<input type='hidden' name='redirect_page' value='history'>";
               echo "<button type='submit' class='btn btn-remove' style='display:flex;align-items:center;gap:5px;'><i class='fa-solid fa-trash'></i> Delete</button>";
               echo "</form>";
               echo "</td>";
               echo "</tr>";
            }
          }
          
          if (!$hasArchived) {
            echo "<tr><td colspan='6' style='text-align:center;'>No archived requests found.</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- Visitor Details Modal -->
<div id="visitorModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeVisitorModal()" aria-label="Close">✕</button>
    <h3>Visitor Details</h3>
    <div id="visitorDetailsContent">
      <!-- Content will be loaded here -->
    </div>
  </div>
</div>

<!-- Incident Proof Modal -->
<div id="incidentProofModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeIncidentProofModal()" aria-label="Close">✕</button>
    <div style="overflow-y: auto; flex: 1; display: flex; align-items: center; justify-content: center;">
      <img id="incidentProofImg" src="" alt="Proof" />
    </div>
  </div>
</div>

<div id="incidentDetailsModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeIncidentDetailsModal()" aria-label="Close">✕</button>
    <h3>Incident Details</h3>
    <div style="max-height:80vh; overflow:auto;">
      <iframe id="incidentDetailsFrame" src="" style="width:100%; height:70vh; border:0;"></iframe>
    </div>
  </div>
</div>

<script>
// JavaScript to handle navigation
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    // Update active class
    document.querySelectorAll('.nav-item').forEach(navItem => {
      navItem.classList.remove('active');
    });
    this.classList.add('active');
    
    // Update page title
    const pageTitle = this.querySelector('span').textContent;
    document.getElementById('page-title').textContent = pageTitle;
    
    // Update search placeholder
    document.getElementById('search-input').placeholder = `Search ${pageTitle}...`;
  });
});

// Incident proof modal
function showIncidentProofModal(src){
  var m=document.getElementById('incidentProofModal');
  var img=document.getElementById('incidentProofImg');
  if(m&&img){ img.src=src; m.classList.add('modal-top'); m.style.display='flex'; }
}
function closeIncidentProofModal(){ var m=document.getElementById('incidentProofModal'); if(m){ m.style.display='none'; m.classList.remove('modal-top'); } }

function showIncidentDetails(id){
  var m=document.getElementById('incidentDetailsModal');
  var f=document.getElementById('incidentDetailsFrame');
  if(f){ f.src='get_report_details.php?id=' + encodeURIComponent(id); }
  if(m){ m.style.display='flex'; }
}
function closeIncidentDetailsModal(){
  var m=document.getElementById('incidentDetailsModal');
  var f=document.getElementById('incidentDetailsFrame');
  if(m){ m.style.display='none'; }
  if(f){ f.src=''; }
}

function calcAgeFromBirthdate(birthdateStr){
  if(!birthdateStr) return '';
  const d = new Date(birthdateStr);
  if(isNaN(d)) return '';
  const today = new Date();
  let age = today.getFullYear() - d.getFullYear();
  const m = today.getMonth() - d.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < d.getDate())) age--;
  if (age < 0) age = 0;
  return age;
}
function formatBirthdateWithAge(birthdateStr){
  if(!birthdateStr) return '';
  const dateLabel = new Date(birthdateStr).toLocaleDateString();
  const age = calcAgeFromBirthdate(birthdateStr);
  return (age !== '' && age !== null && age !== undefined) ? `${dateLabel} (Age ${age})` : dateLabel;
}

function showVisitorDetails(id, source) {
  // Reset modal
  const contentEl = document.getElementById('visitorDetailsContent');
  if(contentEl) contentEl.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
  const modal = document.getElementById('visitorModal');
  const modalTitleEl = document.querySelector('#visitorModal h3');
  if(modalTitleEl) modalTitleEl.textContent = 'Request Details';
  if(modal) modal.style.display = 'flex';

  // Make AJAX request to get visitor details
  const url = 'admin.php?action=get_visitor_details&id=' + encodeURIComponent(id) + (source? ('&source=' + encodeURIComponent(source)) : '');
  fetch(url)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const details = data.details;
        const isResident = details.user_id && String(details.user_id) !== '0';
        // Update modal title depending on source
        const modalTitleEl = document.querySelector('#visitorModal h3');
        if (modalTitleEl) modalTitleEl.textContent = isResident ? 'Resident Request Details' : 'Visitor Request Details';

        const residentName = [details.res_first_name || '', details.res_middle_name || '', details.res_last_name || ''].join(' ').replace(/\s+/g, ' ').trim();
        function fmtTime(t){ if(!t) return ''; const p=String(t).split(':'), hh=parseInt(p[0]||'0',10), m=(p[1]||'00'); const ap=hh>=12?'PM':'AM'; let h=hh%12; if(h===0) h=12; return `${h}:${String(m).padStart(2,'0')} ${ap}`; }
        function fmtDateTime(dt){ try{ const d=new Date(dt); const mm=String(d.getMonth()+1).padStart(2,'0'); const dd=String(d.getDate()).padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); let h=d.getHours(); const m=String(d.getMinutes()).padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}.${dd}.${yy} ${h}:${m} ${ap}`; }catch(e){ return String(dt); } }
        function fmtDateTimeSec(dt){ try{ const d=new Date(dt); const mm=String(d.getMonth()+1).padStart(2,'0'); const dd=String(d.getDate()).padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); let h=d.getHours(); const m=String(d.getMinutes()).padStart(2,'0'); const s=String(d.getSeconds()).padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}.${dd}.${yy} ${h}:${m}:${s} ${ap}`; }catch(e){ return String(dt); } }
        const ps = ((details.payment_status || 'pending') + '').toLowerCase();
        const att = parseInt(details.receipt_attempts||0, 10);
        const psClass = ps==='verified'?'badge-approved':(ps==='rejected'?'badge-rejected':'badge-pending');
        const isGuestEntry = !details.amenity || String(details.amenity).trim() === 'Guest Entry';
        const visitDateVal = (isGuestEntry ? details.visit_date : details.start_date);
        const visitEndDateVal = (isGuestEntry ? null : details.end_date);
        const visitStartTimeVal = (isGuestEntry ? details.visit_time : details.start_time);
        const visitEndTimeVal = (isGuestEntry ? null : details.end_time);
      const poolTypeRaw = (details.pool_booking_type || '').toString().toLowerCase();
      const poolTypeLabel = (String(details.amenity||'').toLowerCase()==='pool' && poolTypeRaw) ? (poolTypeRaw==='whole_pool' ? 'Whole Pool' : 'Shared Pool') : '';
        const sectionTitle = isGuestEntry ? 'Visit Details' : 'Reservation Details';
        const approvalStatus = (details.approval_status || 'pending').toLowerCase();
        let stClass = 'st-pending';
        let stLabel = 'Pending Review';
        if (approvalStatus.includes('approv')) { stClass = 'st-approved'; stLabel = 'Approved'; }
        else if ((approvalStatus.includes('denied') || approvalStatus.includes('reject')) || (ps==='rejected' && att>=3)) { stClass = 'st-denied'; stLabel = (ps==='rejected' && att>=3) ? 'Denied – Max Attempts Reached' : 'Denied'; }
        else if (approvalStatus.includes('cancel')) { stClass = 'st-denied'; stLabel = 'Cancelled'; }
        else if (approvalStatus.includes('expire')) { stClass = 'st-expired'; stLabel = 'Expired'; }

        const fullName = [details.full_name || '', details.middle_name || '', details.last_name || ''].join(' ').replace(/\s+/g,' ').trim();
        const validIdValue = details.valid_id_path ? `<button type="button" class="btn btn-view" onclick="showIncidentProofModal('${String(details.valid_id_path).replace(/'/g, "\\'")}')"><i class="fa-solid fa-id-card"></i> View ID</button>` : 'Not uploaded';
        const statusBadge = `<div class="request-status"><span class="status-badge-lg ${stClass}">${stLabel}</span></div>`;

        const priceBlock = details.price ? (()=>{ 
          const total=parseFloat(details.price)||0; 
          const dp=(details.downpayment!=null?parseFloat(details.downpayment):Math.max(0, total*0.5)); 
          const rem=Math.max(0, total-dp); 
          return `<div class="price-section">
            <div class="info-row total-price">
              <span>Total Price</span>
              <span>₱${total.toLocaleString()}</span>
            </div>
            <div class="info-row price-down">
              <span>Downpayment Paid</span>
              <span>- ₱${dp.toLocaleString()}</span>
            </div>
            <div class="info-row price-balance">
              <span>Balance Due</span>
              <span>₱${rem.toLocaleString()}</span>
            </div>
          </div>`; 
        })() : '';

        const content = isResident
          ? `
          <div class="request-details">
            ${statusBadge}
            <div>
              <div class="section-title">Resident Information</div>
              <div class="info-grid">
                ${residentName ? `<div class="info-row"><span class="info-label">Name</span><span class="info-value">${residentName}</span></div>` : ''}
                ${details.res_house_number ? `<div class="info-row"><span class="info-label">House No.</span><span class="info-value">${details.res_house_number}</span></div>` : ''}
                ${details.res_email ? `<div class="info-row"><span class="info-label">Email</span><span class="info-value">${details.res_email}</span></div>` : ''}
                ${details.res_phone ? `<div class="info-row"><span class="info-label">Contact</span><span class="info-value">${details.res_phone}</span></div>` : ''}
              </div>
            </div>
            <div>
              <div class="section-title">Visitor Information</div>
              <div class="info-grid">
                ${fullName ? `<div class="info-row"><span class="info-label">Full Name</span><span class="info-value">${fullName}</span></div>` : ''}
                <div class="info-row"><span class="info-label">Sex</span><span class="info-value">${details.sex || '-'}</span></div>
                ${details.birthdate ? `<div class="info-row"><span class="info-label">Birthdate</span><span class="info-value">${formatBirthdateWithAge(details.birthdate)}</span></div>` : ''}
                <div class="info-row"><span class="info-label">Contact</span><span class="info-value">${details.contact || '-'}</span></div>
                ${details.email ? `<div class="info-row"><span class="info-label">Email</span><span class="info-value">${details.email}</span></div>` : ''}
                <div class="info-row"><span class="info-label">Valid ID</span><span class="info-value">${validIdValue}</span></div>
              </div>
            </div>
            ${!isGuestEntry ? `
            <div>
              <div class="section-title">${sectionTitle}</div>
              <div class="info-grid">
                ${visitDateVal ? `<div class="info-row"><span class="info-label">Date</span><span class="info-value">${new Date(visitDateVal).toLocaleDateString()}${visitEndDateVal ? ' - ' + new Date(visitEndDateVal).toLocaleDateString() : ''}</span></div>` : ''}
                ${(visitStartTimeVal || visitEndTimeVal) ? `<div class="info-row"><span class="info-label">Time</span><span class="info-value">${fmtTime(visitStartTimeVal)}${visitEndTimeVal ? ' - ' + fmtTime(visitEndTimeVal) : ''}</span></div>` : ''}
                ${details.amenity && details.amenity !== 'Guest Entry' ? `<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${details.amenity}</span></div>` : ''}
                ${poolTypeLabel ? `<div class="info-row"><span class="info-label">Pool Type</span><span class="info-value">${poolTypeLabel}</span></div>` : ''}
                ${priceBlock}
              </div>
            </div>
            ` : ''}
            <div>
              <div class="section-title">Request Status</div>
              <div class="info-grid">
                <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
                ${details.entry_created ? `<div class="info-row"><span class="info-label">Request Date</span><span class="info-value">${fmtDateTime(details.entry_created)}</span></div>` : ''}
                ${details.approved_by ? `<div class="info-row"><span class="info-label">Approved By</span><span class="info-value">Admin</span></div>` : ''}
                ${details.approval_date ? `<div class="info-row"><span class="info-label">Approval Date</span><span class="info-value">${fmtDateTimeSec(details.approval_date)}</span></div>` : ''}
              </div>
            </div>
          </div>
          `
          : `
          <div class="request-details">
            ${statusBadge}
            <div>
              <div class="section-title">Personal Information</div>
              <div class="info-grid">
                ${fullName ? `<div class="info-row"><span class="info-label">Full Name</span><span class="info-value">${fullName}</span></div>` : ''}
                ${details.sex ? `<div class="info-row"><span class="info-label">Sex</span><span class="info-value">${details.sex}</span></div>` : ''}
                ${details.birthdate ? `<div class="info-row"><span class="info-label">Birthdate</span><span class="info-value">${formatBirthdateWithAge(details.birthdate)}</span></div>` : ''}
                ${details.contact ? `<div class="info-row"><span class="info-label">Contact</span><span class="info-value">${details.contact}</span></div>` : ''}
                ${details.email ? `<div class="info-row"><span class="info-label">Email</span><span class="info-value">${details.email}</span></div>` : ''}
                ${details.address ? `<div class="info-row"><span class="info-label">Address</span><span class="info-value">${details.address}</span></div>` : ''}
                <div class="info-row"><span class="info-label">Valid ID</span><span class="info-value">${validIdValue}</span></div>
              </div>
            </div>
            <div>
              <div class="section-title">${sectionTitle}</div>
              <div class="info-grid">
                ${details.ref_code ? `<div class="info-row"><span class="info-label">Reference Code</span><span class="info-value">${details.ref_code}</span></div>` : ''}
                ${details.amenity && details.amenity !== 'Guest Entry' ? `<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${details.amenity}</span></div>` : ''}
                ${poolTypeLabel ? `<div class="info-row"><span class="info-label">Pool Type</span><span class="info-value">${poolTypeLabel}</span></div>` : ''}
                ${visitDateVal ? `<div class="info-row"><span class="info-label">Date</span><span class="info-value">${new Date(visitDateVal).toLocaleDateString()}${visitEndDateVal ? ' - ' + new Date(visitEndDateVal).toLocaleDateString() : ''}</span></div>` : ''}
                ${(visitStartTimeVal || visitEndTimeVal) ? `<div class="info-row"><span class="info-label">Time</span><span class="info-value">${fmtTime(visitStartTimeVal)}${visitEndTimeVal ? ' - ' + fmtTime(visitEndTimeVal) : ''}</span></div>` : ''}
                ${details.persons ? `<div class="info-row"><span class="info-label">No. of Persons</span><span class="info-value">${details.persons}</span></div>` : ''}
                ${details.purpose ? `<div class="info-row"><span class="info-label">Purpose of Visit</span><span class="info-value">${details.purpose}</span></div>` : ''}
                ${priceBlock}
              </div>
            </div>
            <div>
              <div class="section-title">Request Status</div>
              <div class="info-grid">
                <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
                ${details.entry_created ? `<div class="info-row"><span class="info-label">Request Date</span><span class="info-value">${fmtDateTime(details.entry_created)}</span></div>` : ''}
                ${details.approved_by ? `<div class="info-row"><span class="info-label">Approved By</span><span class="info-value">Admin</span></div>` : ''}
                ${details.approval_date ? `<div class="info-row"><span class="info-label">Approval Date</span><span class="info-value">${fmtDateTimeSec(details.approval_date)}</span></div>` : ''}
              </div>
            </div>
          </div>
          `;
        document.getElementById('visitorDetailsContent').innerHTML = content;
      } else {
        document.getElementById('visitorDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error: ' + (data.message||'Unknown error') + '</div>';
      }
    })
    .catch(error => {
      console.error('Error:', error);
      document.getElementById('visitorDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading visitor details.</div>';
    });
}

// Function to close visitor details modal
function closeVisitorModal() {
  var m = document.getElementById('visitorModal');
  if(m){ m.style.display = 'none'; }
  var c = document.getElementById('visitorDetailsContent');
  if(c){ c.innerHTML = ''; }
}

// Close modal when clicking outside of it
window.onclick = function(event) {
  const modal = document.getElementById('visitorModal');
  if (event.target == modal) {
    modal.style.display = 'none';
  }
}
</script>

<!-- Reservation Details Modal -->
<div id="reservationModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeReservationModal()" aria-label="Close">✕</button>
    <h3>Reservation Details</h3>
    <div id="reservationDetailsContent"></div>
  </div>
</div>

<script>
function showReservationDetails(reservationId, expectedType){
  var c = document.getElementById('reservationDetailsContent');
  if(c){ c.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>'; }
  var m = document.getElementById('reservationModal');
  if(m){ m.style.display = 'flex'; }
  fetch('admin.php?action=get_reservation_details&id=' + reservationId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading reservation details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      var userType = (d.user_type || '').toString().toLowerCase();
      if (expectedType && userType !== expectedType) { /* allow viewing details regardless of type */ }
      const residentName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const guestName = [d.guest_first_name||'', d.guest_middle_name||'', d.guest_last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const isResidentGuest = !!d.gf_id;
      const whoLabel = isResidentGuest ? "Resident’s Guest" : ((String(d.user_type||'resident').toLowerCase() === 'visitor') ? 'Visitor' : 'Resident');
      var modalTitle = document.querySelector('#reservationModal h3');
      if (modalTitle) {
        var titleBase = isResidentGuest ? "Resident’s Guest" : (userType === 'visitor' ? 'Visitor Reservation' : 'Resident Reservation');
        modalTitle.textContent = titleBase + ' Details';
      }
      const reservedBy = isResidentGuest ? (guestName || "Resident’s Guest") : whoLabel;
      const displayName = isResidentGuest ? (guestName || 'Guest') : residentName;
      const displayEmail = isResidentGuest ? (d.guest_email||'') : (d.email||'');
      const displayPhone = isResidentGuest ? (d.guest_contact||'') : (d.phone||'');
      const ps = ((d.payment_status||'pending')+'').toLowerCase();
      const att = parseInt(d.receipt_attempts||0, 10);
      const approvalStatus = (d.approval_status || 'pending').toLowerCase();
      const poolTypeRaw = (d.pool_booking_type || '').toString().toLowerCase();
      let poolTypeLabel = '';
      if (String(d.amenity||'').toLowerCase()==='pool') {
        if (poolTypeRaw === 'whole_pool') { poolTypeLabel = 'Whole Pool'; }
        else if (poolTypeRaw === 'per_person') { poolTypeLabel = 'Shared Pool'; }
        else {
          const personsVal = parseInt(d.persons||0, 10);
          if (personsVal >= 20) { poolTypeLabel = 'Whole Pool'; }
          else if (personsVal > 0) { poolTypeLabel = 'Shared Pool'; }
        }
      }
      let stClass = 'st-pending';
      let stLabel = 'Pending Review';
      if (approvalStatus.includes('approv')) { stClass = 'st-approved'; stLabel = 'Approved'; }
      else if ((approvalStatus.includes('denied') || approvalStatus.includes('reject')) || (ps==='rejected' && att>=3)) { stClass = 'st-denied'; stLabel = (ps==='rejected' && att>=3) ? 'Denied – Max Attempts Reached' : 'Denied'; }
      else if (approvalStatus.includes('cancel')) { stClass = 'st-denied'; stLabel = 'Cancelled'; }
      else if (approvalStatus.includes('expire')) { stClass = 'st-expired'; stLabel = 'Expired'; }
      const priceBlock = d.price ? (()=>{ 
        const total=parseFloat(d.price)||0; 
        const dp=(d.downpayment!=null?parseFloat(d.downpayment):Math.max(0,total*0.5)); 
        const rem=Math.max(0,total-dp); 
        return `<div class="price-section">
          <div class="info-row total-price"><span class="info-label">Total Price</span><span class="info-value">₱${total.toLocaleString()}</span></div>
          <div class="info-row price-down"><span class="info-label">Online Payment (Partial)</span><span class="info-value">₱${dp.toLocaleString()}</span></div>
          <div class="info-row price-balance"><span class="info-label">Onsite Payment (Remaining)</span><span class="info-value">₱${rem.toLocaleString()}</span></div>
        </div>`; 
      })() : '';
      const receiptPath = (d.receipt_path||'').toString().trim();
      const payStatus = ps;
      const isPdf = /\.pdf$/i.test(receiptPath);
      const redirectPage = isResidentGuest ? 'resident_guest_forms' : (userType === 'visitor' ? 'visitor_requests' : 'requests');
      const denialReason = (d.denial_reason||'').toString().trim();
      const showDenial = denialReason && (payStatus === 'rejected' || payStatus === 'pending_update' || approvalStatus.includes('denied') || approvalStatus.includes('reject'));
      const waitNote = payStatus === 'rejected' ? ((att>=3) ? 'Denied — Max Attempts Reached. Payment rejected 3 times. No further uploads allowed.' : 'Wait for the updated proof.') : '';
      const receiptHtml = (receiptPath && payStatus==='verified') ? (
        `<div class="details-section" style="animation: fadeIn 0.5s ease;">
          <h4>Proof of Payment</h4>
          ${isPdf ? `<a href="${receiptPath}" target="_blank" style="color:#23412e;font-weight:600;">Open uploaded proof (PDF)</a>` : `<a href="${receiptPath}" target="_blank"><img src="${receiptPath}" alt="Uploaded proof of payment" style="max-width:100%; height:auto; border-radius:8px; cursor:pointer;"></a>`}
        </div>`
      ) : '';
      const denialHtml = '';
      const content = `
        <div class="request-details">
          <div class="request-status"><span class="status-badge-lg ${stClass}">${stLabel}</span></div>
          <div class="section-title">${whoLabel} Information</div>
          <div class="info-grid">
            ${displayName?`<div class="info-row"><span class="info-label">Name</span><span class="info-value">${displayName}</span></div>`:''}
            ${(!isResidentGuest && d.house_number)?`<div class="info-row"><span class="info-label">House No.</span><span class="info-value">${d.house_number}</span></div>`:''}
            ${displayEmail?`<div class="info-row"><span class="info-label">Email</span><span class="info-value">${displayEmail}</span></div>`:''}
            ${displayPhone?`<div class="info-row"><span class="info-label">Phone</span><span class="info-value">${displayPhone}</span></div>`:''}
          </div>
          ${isResidentGuest ? `
          <div class="section-title">Resident Information</div>
          <div class="info-grid">
            ${residentName?`<div class="info-row"><span class="info-label">Name</span><span class="info-value">${residentName}</span></div>`:''}
            ${d.house_number?`<div class="info-row"><span class="info-label">House No.</span><span class="info-value">${d.house_number}</span></div>`:''}
            ${d.email?`<div class="info-row"><span class="info-label">Email</span><span class="info-value">${d.email}</span></div>`:''}
            ${d.phone?`<div class="info-row"><span class="info-label">Phone</span><span class="info-value">${d.phone}</span></div>`:''}
          </div>` : ''}
          <div class="section-title">Reservation Details</div>
          <div class="info-grid">
            ${d.ref_code?`<div class="info-row"><span class="info-label">Reference Code</span><span class="info-value">${d.ref_code}</span></div>`:''}
            ${d.amenity?`<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${d.amenity}</span></div>`:''}
            ${poolTypeLabel?`<div class="info-row"><span class="info-label">Pool Type</span><span class="info-value">${poolTypeLabel}</span></div>`:''}
            ${reservedBy?`<div class="info-row"><span class="info-label">Reserved By</span><span class="info-value">${reservedBy}</span></div>`:''}
            ${d.start_date?`<div class="info-row"><span class="info-label">Start Date</span><span class="info-value">${new Date(d.start_date).toLocaleDateString()}</span></div>`:''}
            ${d.end_date?`<div class="info-row"><span class="info-label">End Date</span><span class="info-value">${new Date(d.end_date).toLocaleDateString()}</span></div>`:''}
            ${(d.start_time||d.end_time)?`<div class="info-row"><span class="info-label">Time</span><span class="info-value">${fmtTime(d.start_time)}${d.end_time?' - '+fmtTime(d.end_time):''}</span></div>`:''}
            ${d.persons?`<div class="info-row"><span class="info-label">Persons</span><span class="info-value">${d.persons}</span></div>`:''}
            ${priceBlock}
          </div>
          ${receiptHtml}
          ${denialHtml}
          <div class="section-title">Request Status</div>
          <div class="info-grid">
            <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
            ${d.created_at?`<div class="info-row"><span class="info-label">Requested</span><span class="info-value">${fmtDateTime(d.created_at)}</span></div>`:''}
            ${d.approved_by?`<div class="info-row"><span class="info-label">Approved By</span><span class="info-value">Admin</span></div>`:''}
              ${d.approval_date?`<div class="info-row"><span class="info-label">Approval Date</span><span class="info-value">${fmtDateTimeSec(d.approval_date)}</span></div>`:''}
          </div>
        </div>`;
      document.getElementById('reservationDetailsContent').innerHTML = content;
      document.getElementById('reservationModal').style.display = 'flex';
    })
    .catch(err => { console.error(err); alert('Error loading reservation details'); });
}

function closeReservationModal(){
  var m = document.getElementById('reservationModal');
  if(m){ m.style.display = 'none'; }
  var c = document.getElementById('reservationDetailsContent');
  if(c){ c.innerHTML = ''; }
}

window.addEventListener('click', function(event){
  const rmodal = document.getElementById('reservationModal');
  if(event.target === rmodal){ rmodal.style.display = 'none'; }
});
</script>

<!-- Resident Reservation Details Modal -->
<div id="residentReservationModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeResidentReservationModal()" aria-label="Close">✕</button>
    <h3>Resident Reservation</h3>
    <div id="residentReservationDetailsContent"></div>
  </div>
</div>

<script>
function fmtTime(t){ if(!t) return ''; var p=String(t).split(':'), hh=parseInt(p[0]||'0',10), m=(p[1]||'00'); var ap=hh>=12?'PM':'AM'; var h=hh%12; if(h===0) h=12; return (String(h)+":"+String(m).padStart(2,'0')+" "+ap); }
function fmtDateTime(dt){ try{ var d=new Date(dt); var mm=String(d.getMonth()+1).padStart(2,'0'); var dd=String(d.getDate()).padStart(2,'0'); var yy=String(d.getFullYear()).slice(-2); var hh=d.getHours(); var m=String(d.getMinutes()).padStart(2,'0'); var ap=hh>=12?'PM':'AM'; var h=hh%12; if(h===0) h=12; return (mm+"."+dd+"."+yy+" "+h+":"+m+" "+ap); }catch(e){ return String(dt); } }
function fmtDateTimeSec(dt){ try{ var d=new Date(dt); var mm=String(d.getMonth()+1).padStart(2,'0'); var dd=String(d.getDate()).padStart(2,'0'); var yy=String(d.getFullYear()).slice(-2); var hh=d.getHours(); var m=String(d.getMinutes()).padStart(2,'0'); var s=String(d.getSeconds()).padStart(2,'0'); var ap=hh>=12?'PM':'AM'; var h=hh%12; if(h===0) h=12; return (mm+"."+dd+"."+yy+" "+h+":"+m+":"+s+" "+ap); }catch(e){ return String(dt); } }
function showResidentReservationDetails(rrId){
  document.getElementById('residentReservationDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
  document.getElementById('residentReservationModal').style.display = 'flex';
  
  fetch('admin.php?action=get_resident_reservation_details&id=' + rrId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ 
        document.getElementById('residentReservationDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error: ' + (data.message||'Unknown error') + '</div>';
        return; 
      }
      const d = data.details || {};
      const ps = ((d.payment_status||'pending')+'').toLowerCase();
      const psClass = ps==='verified'?'badge-approved':(ps==='rejected'?'badge-rejected':'badge-pending');
      const residentName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const guestName = [d.guest_first_name||'', d.guest_middle_name||'', d.guest_last_name||''].join(' ').replace(/\s+/g,' ').trim();
      
      const bookedByRole = (d.booked_by_role || '').toLowerCase();
      const bookedByName = d.booked_by_name || '';
      const isBookedByGuest = (bookedByRole === 'guest' || bookedByRole === 'co_owner');
      const isResidentGuest = !!d.gf_id || isBookedByGuest;
      const poolTypeRaw = (d.pool_booking_type || '').toString().toLowerCase();
      let poolTypeLabel = '';
      if (String(d.amenity||'').toLowerCase()==='pool') {
        if (poolTypeRaw === 'whole_pool') { poolTypeLabel = 'Whole Pool'; }
        else if (poolTypeRaw === 'per_person') { poolTypeLabel = 'Shared Pool'; }
        else {
          const personsVal = parseInt(d.persons||0, 10);
          if (personsVal >= 20) { poolTypeLabel = 'Whole Pool'; }
          else if (personsVal > 0) { poolTypeLabel = 'Shared Pool'; }
        }
      }
      
      let userType = ((d.user_type || 'Resident').charAt(0).toUpperCase() + (d.user_type || 'Resident').slice(1));
      if (isResidentGuest) {
          userType = "Resident’s Guest";
          if (bookedByRole === 'co_owner') userType = "Co-owner";
      }

      const reservedBy = isBookedByGuest ? (bookedByName || userType) : (isResidentGuest ? (guestName || "Resident’s Guest") : userType);
      const displayName = isBookedByGuest ? (bookedByName || 'Guest') : (isResidentGuest ? (guestName || 'Guest') : residentName);
      
      const displayEmail = isResidentGuest ? (d.guest_email||'') : (d.email||'');
      const displayPhone = isResidentGuest ? (d.guest_contact||'') : (d.phone||'');
      
      const reservationLabel = isResidentGuest ? "Resident’s Guest" : "Resident Reservation";
      const primarySectionTitle = isResidentGuest ? "Resident’s Guest" : "Resident";
      
      const modalTitle = document.querySelector('#residentReservationModal h3');
      if(modalTitle) modalTitle.textContent = reservationLabel + ' Details';

      const approvalStatus = (d.approval_status || 'pending').toLowerCase();
      let stClass = 'st-pending';
      let stLabel = 'Pending Review';
      if (approvalStatus.includes('approv')) { stClass = 'st-approved'; stLabel = 'Approved'; }
      else if (approvalStatus.includes('denied') || approvalStatus.includes('reject')) { stClass = 'st-denied'; stLabel = 'Denied'; }
      else if (approvalStatus.includes('cancel')) { stClass = 'st-denied'; stLabel = 'Cancelled'; }
      else if (approvalStatus.includes('expire')) { stClass = 'st-expired'; stLabel = 'Expired'; }
      const priceBlock = d.price ? (()=>{ 
        const total=parseFloat(d.price)||0; 
        const dp=(d.downpayment!=null?parseFloat(d.downpayment):Math.max(0,total*0.5)); 
        const rem=Math.max(0,total-dp); 
        return `<div class="price-section">
          <div class="info-row total-price"><span class="info-label">Total Price</span><span class="info-value">₱${total.toLocaleString()}</span></div>
          <div class="info-row price-down"><span class="info-label">Online Payment (Partial)</span><span class="info-value">₱${dp.toLocaleString()}</span></div>
          <div class="info-row price-balance"><span class="info-label">Onsite Payment (Remaining)</span><span class="info-value">₱${rem.toLocaleString()}</span></div>
        </div>`; 
      })() : '';
      const receiptPath = (d.receipt_path||'').toString().trim();
      const isPdf = /\.pdf$/i.test(receiptPath);
      const denialReason = (d.denial_reason||'').toString().trim();
      const att = parseInt(d.receipt_attempts||0, 10);
      const showDenial = denialReason && (ps === 'rejected' || ps === 'pending_update' || approvalStatus.includes('denied') || approvalStatus.includes('reject'));
      const waitNote = ps === 'rejected' ? ((att>=3) ? 'Denied — Max Attempts Reached. Payment rejected 3 times. No further uploads allowed.' : 'Wait for the updated proof.') : '';
      const receiptHtml = (receiptPath && ps==='verified') ? (
        `<div class="details-section" style="animation: fadeIn 0.5s ease;">
          <h4>Proof of Payment</h4>
          ${isPdf ? `<a href="${receiptPath}" target="_blank" style="color:#23412e;font-weight:600;">Open uploaded proof (PDF)</a>` : `<a href="${receiptPath}" target="_blank"><img src="${receiptPath}" alt="Uploaded proof of payment" style="max-width:100%; height:auto; border-radius:8px; cursor:pointer;"></a>`}
        </div>`
      ) : '';
      const denialHtml = showDenial ? (
        `<div style="margin-top:12px;padding:12px;border-radius:10px;background:#fee2e2;color:#991b1b;font-weight:600;">
          <div>Reason: ${denialReason}</div>
          ${waitNote ? `<div style="margin-top:6px;color:#7f1d1d;font-weight:500;">${waitNote}</div>` : ''}
        </div>`
      ) : '';
      const content = `
          <div class="request-details">
            <div class="request-status"><span class="status-badge-lg ${stClass}">${stLabel}</span></div>
            <div class="section-title">${primarySectionTitle} Information</div>
            <div class="info-grid">
              ${displayName?`<div class="info-row"><span class="info-label">Name</span><span class="info-value">${displayName}</span></div>`:''}
              ${(!isResidentGuest && d.house_number)?`<div class="info-row"><span class="info-label">House No.</span><span class="info-value">${d.house_number}</span></div>`:''}
              ${displayEmail?`<div class="info-row"><span class="info-label">Email</span><span class="info-value">${displayEmail}</span></div>`:''}
              ${displayPhone?`<div class="info-row"><span class="info-label">Phone</span><span class="info-value">${displayPhone}</span></div>`:''}
            </div>
            ${isResidentGuest ? `
            <div class="section-title">Resident Owner Information</div>
            <div class="info-grid">
              ${residentName?`<div class="info-row"><span class="info-label">Name</span><span class="info-value">${residentName}</span></div>`:''}
              ${d.house_number?`<div class="info-row"><span class="info-label">House No.</span><span class="info-value">${d.house_number}</span></div>`:''}
              ${d.email?`<div class="info-row"><span class="info-label">Email</span><span class="info-value">${d.email}</span></div>`:''}
              ${d.phone?`<div class="info-row"><span class="info-label">Phone</span><span class="info-value">${d.phone}</span></div>`:''}
            </div>` : ''}
            <div class="section-title">Reservation Details</div>
            <div class="info-grid">
              ${d.ref_code?`<div class="info-row"><span class="info-label">Reference Code</span><span class="info-value">${d.ref_code}</span></div>`:''}
              ${d.amenity?`<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${d.amenity}</span></div>`:''}
              ${poolTypeLabel?`<div class="info-row"><span class="info-label">Pool Type</span><span class="info-value">${poolTypeLabel}</span></div>`:''}
              ${reservedBy?`<div class="info-row"><span class="info-label">Reserved By</span><span class="info-value">${reservedBy}</span></div>`:''}
              ${d.start_date?`<div class="info-row"><span class="info-label">Start Date</span><span class="info-value">${new Date(d.start_date).toLocaleDateString()}</span></div>`:''}
              ${d.end_date?`<div class="info-row"><span class="info-label">End Date</span><span class="info-value">${new Date(d.end_date).toLocaleDateString()}</span></div>`:''}
              ${(d.start_time||d.end_time)?`<div class="info-row"><span class="info-label">Time</span><span class="info-value">${fmtTime(d.start_time)}${d.end_time?' - '+fmtTime(d.end_time):''}</span></div>`:''}
              ${d.persons?`<div class="info-row"><span class="info-label">Persons</span><span class="info-value">${d.persons}</span></div>`:''}
              ${priceBlock}
              <div class="info-row"><span class="info-label">Downpayment</span><span class="info-value"><span class="badge ${psClass}">${ps.charAt(0).toUpperCase()+ps.slice(1)}</span></span></div>
            </div>
            ${receiptHtml}
            ${denialHtml}
            <div class="section-title">Request Status</div>
            <div class="info-grid">
              <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
              ${d.created_at?`<div class="info-row"><span class="info-label">Requested</span><span class="info-value">${fmtDateTime(d.created_at)}</span></div>`:''}
              ${d.approved_by?`<div class="info-row"><span class="info-label">Approved By</span><span class="info-value">Admin</span></div>`:''}
              ${d.approval_date?`<div class="info-row"><span class="info-label">Approval Date</span><span class="info-value">${fmtDateTimeSec(d.approval_date)}</span></div>`:''}
            </div>
          </div>`;
      document.getElementById('residentReservationDetailsContent').innerHTML = content;
    })
    .catch(err => { 
      console.error(err); 
      document.getElementById('residentReservationDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>';
    });
}

function showReservationDetailsByRef(ref){
  fetch('admin.php?action=get_reservation_details_by_ref&ref=' + encodeURIComponent(ref))
    .then(r => r.json())
    .then(data => {
      if(!data.success) return;
      var id = parseInt(data.id, 10);
      var d = data.details || {};
      var isResidentGuest = !!d.gf_id;
      var utype = (d.user_type||'').toString().toLowerCase();
      if(isResidentGuest){ showResidentReservationDetails(id); }
      else { showReservationDetails(id, utype==='visitor'?'visitor':'resident'); }
    })
    .catch(function(){});
}

function closeResidentReservationModal(){
  var m = document.getElementById('residentReservationModal');
  if(m){ m.style.display = 'none'; }
  var c = document.getElementById('residentReservationDetailsContent');
  if(c){ c.innerHTML = ''; }
}

window.addEventListener('click', function(event){
  const rmodal2 = document.getElementById('residentReservationModal');
  if(event.target === rmodal2){ rmodal2.style.display = 'none'; }
});
</script>

<!-- User Details Modal -->
<div id="userModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeUserModal()" aria-label="Close">✕</button>
    <h3>User Profile</h3>
    <div id="userDetailsContent"></div>
  </div>
  </div>

<script>
function showUserDetails(userId, expectedType){
  document.getElementById('userDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
  closeVisitorModal();
  closeReservationModal();
  closeResidentReservationModal();
  closePriceDetailsModal();
  closeIncidentProofModal();
  document.getElementById('userModal').style.display = 'flex';
  document.body.classList.add('modal-open');
  
  fetch('admin.php?action=get_user_details&id=' + userId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ 
        document.getElementById('userDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error: ' + (data.message||'Unknown error') + '</div>';
        return; 
      }
      const d = data.details || {};
      var userType = (d.user_type || '').toString().toLowerCase();
      if (expectedType && userType && userType !== expectedType) {
        document.getElementById('userDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">This user is not a ' + expectedType + ' account.</div>';
        return;
      }
      var modalTitle = document.querySelector('#userModal h3');
      if (modalTitle) {
        var titleText = 'User Profile';
        if (userType === 'resident') titleText = 'Resident Profile';
        if (userType === 'visitor') titleText = 'Visitor Profile';
        modalTitle.textContent = titleText;
      }
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const residenceBlock = userType === 'resident' ? `
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Residence</h4>
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.address?`<p><strong>Address:</strong> ${d.address}</p>`:''}
            ${d.created_at?`<p><strong>Registered:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
          </div>` : '';
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Personal</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.sex?`<p><strong>Sex:</strong> ${d.sex}</p>`:''}
            ${d.birthdate?`<p><strong>Birthdate:</strong> ${formatBirthdateWithAge(d.birthdate)}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
            ${d.valid_id_path?`<p><strong>Valid ID:</strong> <button type="button" class="btn btn-view" onclick="showIncidentProofModal('${String(d.valid_id_path).replace(/'/g, "\\'")}')"><i class="fa-solid fa-id-card"></i> View ID</button></p>`:''}
          </div>
          ${residenceBlock}
        </div>`;
      document.getElementById('userDetailsContent').innerHTML = content;
    })
    .catch(err => { 
      console.error(err); 
      document.getElementById('userDetailsContent').innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>';
    });
}

function closeUserModal(){
  var m = document.getElementById('userModal');
  if (!m) return;
  m.classList.add('closing');
  setTimeout(function(){
    m.style.display = 'none';
    m.classList.remove('closing');
    document.body.classList.remove('modal-open');
  }, 200);
}

window.addEventListener('click', function(event){
  const umodal = document.getElementById('userModal');
  if(event.target === umodal){ closeUserModal(); }
});
</script>

</main>
</div>
<div id="toastContainer" class="toast-container" aria-live="polite"></div>
<div id="adminConfirmModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; z-index:3500;">
  <div style="background:#fff; border-radius:12px; padding:22px 20px; width:380px; max-width:92vw; box-shadow:0 12px 30px rgba(0,0,0,0.25); text-align:center; position:relative;">
    <button type="button" class="close" id="adminConfirmClose" aria-label="Close">✕</button>
    <div style="font-weight:700; color:#1f2937; font-size:1.05rem; margin-bottom:8px;">Confirm Action</div>
    <div id="adminConfirmMessage" style="font-size:0.95rem; color:#374151; line-height:1.5; margin-bottom:16px;"></div>
    <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
      <button type="button" class="btn btn-cancel" id="adminConfirmCancelBtn" style="min-width:130px;"><i class="fa-solid fa-xmark"></i> Cancel</button>
      <button type="button" class="btn btn-reject" id="adminConfirmOkBtn" style="min-width:130px;"><i class="fa-solid fa-check"></i> Confirm</button>
    </div>
  </div>
</div>
<script>
  (function(){
    var modal = document.getElementById('adminConfirmModal');
    var msgEl = document.getElementById('adminConfirmMessage');
    var closeBtn = document.getElementById('adminConfirmClose');
    var cancelBtn = document.getElementById('adminConfirmCancelBtn');
    var okBtn = document.getElementById('adminConfirmOkBtn');
    var currentForm = null;
    function close(){ if(modal) modal.style.display='none'; currentForm = null; }
    window.openAdminConfirm = function(form, message){
      if (form && String(form.dataset.confirmed || '') === '1') {
        form.dataset.confirmed = '';
        return true;
      }
      currentForm = form || null;
      if(msgEl) msgEl.textContent = message || 'Are you sure?';
      if(modal) modal.style.display = 'flex';
      return false;
    };
    if(closeBtn) closeBtn.onclick = function(){ close(); };
    if(cancelBtn) cancelBtn.onclick = function(){ close(); };
    if(okBtn) okBtn.onclick = function(){
      var form = currentForm;
      close();
      if(!form) return;
      var reason = form.querySelector('input[name=\"suspension_reason\"]');
      if(reason){
        var val = (reason.value || '').trim();
        if(!val){ reason.focus(); return; }
      }
      form.dataset.confirmed = '1';
      if (typeof form.requestSubmit === 'function') {
        form.requestSubmit();
      } else {
        form.submit();
      }
    };
    if(modal) modal.addEventListener('click', function(e){ if(e.target === modal) close(); });
  })();
</script>
<script>
  function toggleDeleteForReason(input) {
    var wrap = input.closest('.actions');
    if (!wrap) return;
    var del = wrap.querySelector('.delete-form');
    if (!del) return;
    var hasText = (input.value || '').trim().length > 0;
    if (hasText) {
      del.classList.add('show');
    } else {
      del.classList.remove('show');
    }
  }
  document.querySelectorAll('.suspend-reason').forEach(function(input){
    toggleDeleteForReason(input);
    input.addEventListener('input', function(){ toggleDeleteForReason(input); });
    input.addEventListener('change', function(){ toggleDeleteForReason(input); });
  });
</script>
<script src="js/logout-modal.js"></script>
<script>
  (function(){
    var refreshMs = 15000;
    function hasVisibleModal(){
      var modals = document.querySelectorAll('.modal,#adminConfirmModal,#denyReasonModal');
      for(var i=0;i<modals.length;i++){
        var m = modals[i];
        if(!m) continue;
        var ds = m.style && m.style.display ? m.style.display : '';
        if(ds && ds !== 'none') return true;
      }
      return false;
    }
    function hasActiveInput(){
      var el = document.activeElement;
      if(!el) return false;
      if(el.isContentEditable) return true;
      var tag = (el.tagName||'').toLowerCase();
      return tag === 'input' || tag === 'textarea' || tag === 'select';
    }
    setInterval(function(){
      if (hasVisibleModal()) return;
      if (hasActiveInput()) return;
      location.reload();
    }, refreshMs);
  })();
</script>
</body>
 </html>
