<?php
$staffInactivityLimit = 2700;
ini_set('session.gc_maxlifetime', (string)$staffInactivityLimit);
require_once __DIR__ . '/session_bootstrap.php';
include 'connect.php';
require_once __DIR__ . '/qr_url_helpers.php';

$isReservationDetailsAjax = isset($_GET['action']) && in_array($_GET['action'], [
  'get_reservation_details',
  'get_resident_reservation_details',
  'get_visitor_details',
  'get_user_details',
  'get_reservation_details_by_ref',
  'get_notifications',
  'dismiss_notification'
], true);

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

if ($isReservationDetailsAjax) { session_write_close(); }

function admin_status_link($code){ return vp_qr_link('qr_view.php', ['code' => $code]); }
function admin_receipt_url($storedPath){
  $path = trim((string)$storedPath);
  if ($path === '' || preg_match('#^(?:https?:)?//#i', $path) || stripos($path, 'data:') === 0) return $path;
  $path = str_replace('\\', '/', $path);
  $path = ltrim($path, '/');
  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin.php'), '/\\');
  $segments = array_values(array_filter(explode('/', $path), static function($segment){ return $segment !== ''; }));
  $encodedPath = implode('/', array_map('rawurlencode', $segments));
  return ($basePath === '' || $basePath === '.') ? '/' . $encodedPath : $basePath . '/' . $encodedPath;
}
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
function ensureUsersPointsColumn($con){
  if(!($con instanceof mysqli)) return;
  $c = $con->query("SHOW COLUMNS FROM users LIKE 'points'");
  if(!$c || $c->num_rows === 0){
    @$con->query("ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0");
  }
}
function ensurePointTransactionsTable($con){
  if(!($con instanceof mysqli)) return;
  @$con->query("CREATE TABLE IF NOT EXISTS point_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_type ENUM('earn','redeem','adjustment') NOT NULL DEFAULT 'earn',
    amount INT NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    reservation_ref_code VARCHAR(20) NULL,
    material_type VARCHAR(50) NULL,
    weight_kg DECIMAL(10,2) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_point_transactions_user_id (user_id),
    INDEX idx_point_transactions_type (transaction_type),
    INDEX idx_point_transactions_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $checks = [
    "SHOW COLUMNS FROM point_transactions LIKE 'reservation_ref_code'" => "ALTER TABLE point_transactions ADD COLUMN reservation_ref_code VARCHAR(20) NULL AFTER description",
    "SHOW COLUMNS FROM point_transactions LIKE 'material_type'" => "ALTER TABLE point_transactions ADD COLUMN material_type VARCHAR(50) NULL AFTER reservation_ref_code",
    "SHOW COLUMNS FROM point_transactions LIKE 'weight_kg'" => "ALTER TABLE point_transactions ADD COLUMN weight_kg DECIMAL(10,2) NULL AFTER material_type"
  ];
  foreach ($checks as $checkQuery => $alterQuery) {
    $exists = $con->query($checkQuery);
    if (!$exists || $exists->num_rows === 0) {
      @$con->query($alterQuery);
    }
  }
}
function smartWasteMaterialLabel($materialType = '', $description = ''){
  $value = strtolower(trim((string)$materialType));
  $desc = strtolower((string)$description);
  $haystack = trim($value . ' ' . $desc);
  if (strpos($haystack, 'plastic') !== false || strpos($haystack, 'pet') !== false) return 'Plastic (PET)';
  if (strpos($haystack, 'aluminum') !== false || strpos($haystack, 'aluminium') !== false || strpos($haystack, 'can') !== false) return 'Aluminum Cans';
  if (strpos($haystack, 'cardboard') !== false || strpos($haystack, 'paper') !== false) return 'Paper & Cardboard';
  return 'Other';
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

// One-time schema migration guard (file-based flag — no DB privileges needed).
if (!function_exists('vpSchemaDone')) {
  function vpSchemaDone($con, $key) {
    static $cache = [];
    if (isset($cache[$key])) return $cache[$key];
    $flag = __DIR__ . '/schema_flags/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.done';
    $cache[$key] = @file_exists($flag);
    return $cache[$key];
  }
}
if (!function_exists('vpMarkSchemaDone')) {
  function vpMarkSchemaDone($con, $key) {
    $dir = __DIR__ . '/schema_flags';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $flag = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.done';
    @file_put_contents($flag, '1');
  }
}

// Ensure new guest_forms table exists for admin operations
if (!$isReservationDetailsAjax && !vpSchemaDone($con, 'admin_v1')) {
  ensureGuestFormsTable($con);
  ensureGuestFormsWantsAmenityColumn($con);
  ensureGuestFormsAmenityColumns($con);
  ensureDenialReasonColumns($con);
  ensureEmailStatusColumns($con);
  ensureDownpaymentColumn($con);
  ensureUsersPointsColumn($con);
  ensurePointTransactionsTable($con);
  ensureHouseRange($con);
  ensureReceiptAttemptsColumn($con);
  vpMarkSchemaDone($con, 'admin_v1');
}

// Ensure guest_forms has check-in columns (scheduled guest visits)
if (!$isReservationDetailsAjax && ($con instanceof mysqli)) {
  $gfCols = ['entered_at' => 'DATETIME NULL', 'entered_by' => 'INT NULL', 'scanned_at' => 'DATETIME NULL'];
  foreach ($gfCols as $gfCol => $gfDef) {
    $gfChk = @$con->query("SHOW COLUMNS FROM guest_forms LIKE '" . $con->real_escape_string($gfCol) . "'");
    if ($gfChk && $gfChk->num_rows === 0) {
      @$con->query("ALTER TABLE guest_forms ADD COLUMN $gfCol $gfDef");
    }
    if ($gfChk) { $gfChk->close(); }
  }
}

// Handle AJAX request for user details (admin resident profile)
if (isset($_GET['action']) && $_GET['action'] == 'get_user_details' && isset($_GET['id'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
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
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $id = intval($_GET['id']);
    $source = isset($_GET['source']) ? $_GET['source'] : '';

    // Try new guest_forms source unless explicitly a reservation
    if ($source !== 'reservation') {
    $stmtGF = $con->prepare("SELECT gf.*, 
                                    u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name,
                                    u.email AS res_email, u.phone AS res_phone, u.house_number AS res_house_number,
                                    r.payment_status AS r_payment_status, r.price AS r_price, r.downpayment AS r_downpayment,
                                    r.amenity AS r_amenity, r.start_date AS r_start_date, r.end_date AS r_end_date,
                                    r.start_time AS r_start_time, r.end_time AS r_end_time,
                                    r.receipt_path AS r_receipt_path, r.receipt_uploaded_at AS r_receipt_uploaded_at,
                                    r.receipt_attempts AS receipt_attempts,
                                    r.persons AS r_persons, r.ref_code AS r_ref_code
                             FROM guest_forms gf
                             LEFT JOIN users u ON u.id = (
                                 SELECT id FROM users
                                 WHERE (gf.resident_user_id IS NOT NULL AND users.id = gf.resident_user_id)
                                    OR (gf.resident_user_id IS NULL AND gf.resident_email <> '' AND users.email = gf.resident_email)
                                    OR (gf.resident_user_id IS NULL AND (gf.resident_email IS NULL OR gf.resident_email = '') AND gf.resident_house <> '' AND users.house_number = gf.resident_house)
                                 ORDER BY (gf.resident_user_id IS NOT NULL) DESC,
                                          (gf.resident_email <> '' AND users.email = gf.resident_email) DESC,
                                          users.id
                                 LIMIT 1
                             )
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
            'visit_date' => $row['visit_date'] ?? null,
            'visit_time' => $row['visit_time'] ?? null,
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
            'receipt_url' => !empty($row['r_receipt_path']) ? admin_receipt_url($row['r_receipt_path']) : '',
            'receipt_uploaded_at' => $row['r_receipt_uploaded_at'] ?? null,
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
          $row['receipt_url'] = !empty($row['receipt_path']) ? admin_receipt_url($row['receipt_path']) : '';
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
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    $id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT r.id, r.user_id, r.ref_code, r.amenity, r.start_date, r.end_date, r.start_time, r.end_time, r.persons, r.purpose,
                                    r.created_at, r.approval_status, r.approved_by, r.approval_date,
                                    r.price, r.downpayment, r.payment_status, r.receipt_path, r.receipt_uploaded_at, r.receipt_attempts, r.denial_reason, r.booking_for, r.account_type, r.booked_by_role, r.booked_by_name,
                                    r.use_points,
                                    COALESCE((SELECT SUM(pt.amount) FROM point_transactions pt WHERE pt.user_id = r.user_id AND pt.reservation_ref_code = r.ref_code AND pt.transaction_type = 'redeem'), 0) AS points_used,
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
      $row['receipt_url'] = !empty($row['receipt_path']) ? admin_receipt_url($row['receipt_path']) : '';
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for standard amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details' && isset($_GET['id'])) {
  header('Content-Type: application/json; charset=utf-8');
  if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
  }
  $reservationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
  if (!$reservationId || !($con instanceof mysqli)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit;
  }
  $query = "SELECT r.id, r.user_id, r.ref_code, r.amenity, r.start_date, r.end_date,
           r.start_time, r.end_time, r.persons, r.purpose, r.created_at,
           r.approval_status, r.approved_by, r.approval_date, r.price,
           r.downpayment, r.payment_status, r.receipt_path, r.receipt_uploaded_at, r.receipt_attempts,
           r.denial_reason, r.booking_for, r.account_type, r.booked_by_role, r.booked_by_name,
           r.entry_pass_id, r.use_points,
           COALESCE((SELECT SUM(pt.amount) FROM point_transactions pt WHERE pt.user_id = r.user_id AND pt.reservation_ref_code = r.ref_code AND pt.transaction_type = 'redeem'), 0) AS points_used,
           u.first_name, u.middle_name, u.last_name,
           u.email, u.phone, u.house_number, u.user_type,
           gf.id AS gf_id, gf.visitor_first_name AS guest_first_name,
           gf.visitor_middle_name AS guest_middle_name, gf.visitor_last_name AS guest_last_name,
           gf.visitor_email AS guest_email, gf.visitor_contact AS guest_contact
        FROM reservations r
        LEFT JOIN users u ON u.id = r.user_id
        LEFT JOIN guest_forms gf ON gf.ref_code = r.ref_code
        WHERE r.id = ?
        LIMIT 1";
  $stmt = $con->prepare($query);
  if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to prepare reservation lookup']);
    exit;
  }
  $stmt->bind_param('i', $reservationId);
  if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load reservation details']);
    exit;
  }
  $result = $stmt->get_result();
  $row = $result ? $result->fetch_assoc() : null;
  $stmt->close();
  if ($row) {
    $row['receipt_url'] = !empty($row['receipt_path']) ? admin_receipt_url($row['receipt_path']) : '';
    echo json_encode(['success' => true, 'details' => $row]);
  } else {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Reservation details not found']);
  }
  exit;
}

// Handle AJAX request to fetch reservation by ref_code
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details_by_ref' && isset($_GET['ref'])) {
    header('Content-Type: application/json');
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
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
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
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

if (isset($_GET['action']) && $_GET['action'] === 'sidebar_counts') {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    /* One round trip per poll feeds both the four sidebar badges and the visitor
       page's own filter box counts. */
    $counts = getVisitorRequestsCounts($con);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'badges'  => getSidebarActionCounts($con),
        'counts'  => $counts,
        'action'  => ($counts['to_verify'] + $counts['ready']),
    ]);
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'dismiss_notification' && isset($_GET['id'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
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
  $sql = "SELECT r.ref_code, r.gcash_reference_number, r.payment_status, r.receipt_uploaded_at, r.created_at,
                 r.account_type, r.entry_pass_id, u.user_type, r.amenity, r.persons, r.price,
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
                  $receiptUrl = admin_receipt_url($rp);
                  $isPdf = (bool)preg_match('/\.pdf$/i', (string)$rp);
                  if ($isPdf) {
                    echo '<td><a class="receipt-link" href="#" onclick="openReceiptModal(' . htmlspecialchars(json_encode($receiptUrl), ENT_QUOTES, 'UTF-8') . ', ' . ($canVerify ? intval($row['id']) : 0) . ', \'requests\'); return false;">Open Receipt (PDF)</a></td>';
                  } else {
                echo '<td><a class="receipt-link" href="#" onclick="openReceiptModal(' . htmlspecialchars(json_encode($receiptUrl), ENT_QUOTES, 'UTF-8') . ', ' . ($canVerify ? intval($row['id']) : 0) . ', \'requests\'); return false;"><img class="receipt-thumbnail" src="' . htmlspecialchars($receiptUrl, ENT_QUOTES, 'UTF-8') . '" alt="Receipt"></a></td>';
                  }
                } else {
                  echo '<td><span class="muted">No receipt uploaded.</span></td>';
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
                    echo "<button type='submit' class='btn btn-reject' onclick='return openDenyModal(this.closest(\"form\"))'>Deny</button>";
                    echo "</form>";
                  } else {
                    echo '<form method="post" onsubmit="return openDenyModal(this)">';
                    echo '<input type="hidden" name="reservation_id" value="' . intval($row['id']) . '">';
                    echo '<input type="hidden" name="action" value="reject_receipt">';
                    $existingReason = trim((string)($row['denial_reason'] ?? ''));
                    $readonlyAttr = ($ps === 'pending_update') ? " readonly" : "";
                    $valueAttr = ($ps === 'pending_update' ? " value=\'' . htmlspecialchars($existingReason, ENT_QUOTES) . '\'" : "");
                    echo '<input type="hidden" name="denial_reason" class="denial-reason"' . $valueAttr . '>';
                    echo '<button type="submit" class="btn btn-reject" onclick="return openDenyModal(this.closest(\'form\'))">Reject</button>';
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
    // Alphabetical by name. The "Name" column renders "first_name last_name",
    // so sort on that same order to keep the list matching what is displayed.
    // COALESCE keeps rows with a blank name from jumping to the top.
    $query = "SELECT * FROM users
              WHERE user_type = 'resident'
              ORDER BY COALESCE(first_name, '') ASC,
                       COALESCE(last_name, '') ASC,
                       id ASC";
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
                     gf.id AS gf_id,
                     COALESCE((SELECT SUM(pt.amount) FROM point_transactions pt WHERE pt.user_id = r.user_id AND pt.reservation_ref_code = r.ref_code AND pt.transaction_type = 'redeem'), 0) AS points_used
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

/* Visitor amenity requests, normalised once and shared by every consumer (the
   sidebar badge, the filter boxes and the table) so the three can never disagree.
   The result is memoised because the sidebar renders before the page body and
   both want the same read. */
function collectVisitorRequestRows($con) {
    static $rows = null;
    if ($rows !== null) { return $rows; }
    $rows = array();

    $res = getVisitorAccountReservations($con);
    if (!$res || $res->num_rows === 0) { return $rows; }

    while ($vr = $res->fetch_assoc()) {
        $approval_status = $vr['approval_status'] ?? 'pending';
        $approvalLower = strtolower((string)$approval_status);
        $statusLower = strtolower((string)($vr['status'] ?? ''));
        $payStatusLower = strtolower((string)($vr['payment_status'] ?? ''));
        $attempts = intval($vr['receipt_attempts'] ?? 0);

        if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
            continue;
        }
        /* Scanned at the gate: the visit already happened, so it leaves this list. */
        if (!empty($vr['scanned_at']) && !in_array($approvalLower, array('denied','cancelled','expired','moved_to_history'), true)) {
            $stmtGrant = $con->prepare("UPDATE reservations SET approval_status='permission_granted', status='permission_granted', updated_at = NOW() WHERE id = ?");
            if ($stmtGrant) { $stmtGrant->bind_param('i', $vr['id']); $stmtGrant->execute(); $stmtGrant->close(); }
            continue;
        }
        /* Third rejected receipt: auto-denied, so it leaves this list too. */
        if ($payStatusLower === 'rejected' && $attempts >= 3 && $approvalLower !== 'denied') {
            $stmtAuto = $con->prepare("UPDATE reservations SET approval_status='denied' WHERE id=?");
            if ($stmtAuto) { $stmtAuto->bind_param('i', $vr['id']); $stmtAuto->execute(); $stmtAuto->close(); }
            continue;
        }

        /* A visitor cannot submit without paying the downpayment, so every row
           here already has a receipt. The only step before approval is the
           admin's own receipt check, hence exactly four states. */
        if ($payStatusLower === 'rejected' || $approvalLower === 'denied' || $approvalLower === 'cancelled') {
            $key = 'rejected';
        } elseif ($approvalLower === 'approved' || $statusLower === 'approved') {
            $key = 'approved';
        } elseif ($payStatusLower === 'verified') {
            $key = 'ready';
        } else {
            $key = 'to_verify';
        }

        $vr['vr_key']    = $key;
        $vr['vr_label']  = visitorRequestStatusLabel($key);
        $vr['vr_created'] = strtotime((string)($vr['created_at'] ?? '')) ?: 0;
        $vr['vr_visit']   = strtotime((string)($vr['start_date'] ?? '')) ?: 0;
        $vr['vr_pay']     = ($vr['downpayment'] === null || $vr['downpayment'] === '') ? -1 : (float)$vr['downpayment'];
        $vr['vr_approvable'] = ($key === 'ready') ? isAmenityPaymentVerified($con, $vr['ref_code'] ?? '') : false;
        $rows[] = $vr;
    }
    return $rows;
}

function visitorRequestStatusLabel($key) {
    $labels = array(
        'to_verify' => 'To verify',
        'ready'     => 'Ready to approve',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    );
    return $labels[$key] ?? $key;
}

/* Same idea as the visitor collector, for the resident list: the sidebar badge,
   the filter boxes and the table all read this one memoised array, so the badge
   can never disagree with the counts on screen. Memoised because the sidebar is
   rendered before the page body and both want the same read. */
function collectResidentRequestRows($con) {
    static $rows = null;
    if ($rows !== null) { return $rows; }
    $rows = array();

    $res = getResidentOnlyReservations($con);
    if (!$res || $res->num_rows === 0) { return $rows; }

    while ($rr = $res->fetch_assoc()) {
        $approval_status = $rr['approval_status'] ?? 'pending';
        $approvalLower = strtolower((string)$approval_status);
        $statusLower = strtolower((string)($rr['status'] ?? ''));
        $payStatusLower = strtolower((string)($rr['payment_status'] ?? ''));
        $attempts = intval($rr['receipt_attempts'] ?? 0);

        if ($approvalLower === 'permission_granted' || $statusLower === 'permission_granted') {
            continue;
        }
        /* Scanned at the gate: the visit already happened, so it leaves this list. */
        if (!empty($rr['scanned_at']) && !in_array($approvalLower, array('denied','cancelled','expired','moved_to_history'), true)) {
            $stmtGrant = $con->prepare("UPDATE reservations SET approval_status='permission_granted', status='permission_granted', updated_at = NOW() WHERE id = ?");
            if ($stmtGrant) { $stmtGrant->bind_param('i', $rr['id']); $stmtGrant->execute(); $stmtGrant->close(); }
            continue;
        }
        /* Third rejected receipt: auto-denied, so it leaves this list too. */
        if ($payStatusLower === 'rejected' && $attempts >= 3 && $approvalLower !== 'denied') {
            $stmtAuto = $con->prepare("UPDATE reservations SET approval_status='denied' WHERE id=?");
            if ($stmtAuto) { $stmtAuto->bind_param('i', $rr['id']); $stmtAuto->execute(); $stmtAuto->close(); }
            continue;
        }

        /* The downpayment receipt is the only thing verified at this step, so a
           request is either waiting on that check or already past it. */
        if ($payStatusLower === 'rejected' || $approvalLower === 'denied' || $approvalLower === 'cancelled') {
            $key = 'rejected';
        } elseif ($approvalLower === 'approved' || $statusLower === 'approved') {
            $key = 'approved';
        } elseif ($payStatusLower === 'verified') {
            $key = 'ready';
        } else {
            $key = 'to_verify';
        }

        $rr['rr_key'] = $key;
        $rr['rr_label'] = residentRequestStatusLabel($key);
        $rr['rr_created'] = strtotime((string)($rr['created_at'] ?? '')) ?: 0;
        $rows[] = $rr;
    }

    /* Action first: To verify, then Ready to approve, then the rest.
       Newest first inside each group. */
    $order = array('to_verify' => 0, 'ready' => 1, 'approved' => 2, 'rejected' => 3);
    usort($rows, function ($a, $b) use ($order) {
        $sa = $order[$a['rr_key']];
        $sb = $order[$b['rr_key']];
        if ($sa !== $sb) { return $sa - $sb; }
        return $b['rr_created'] - $a['rr_created'];
    });
    return $rows;
}

function residentRequestStatusLabel($key) {
    $labels = array(
        'to_verify' => 'To verify',
        'ready'     => 'Ready to approve',
        'approved'  => 'Approved',
        'rejected'  => 'Rejected',
    );
    return $labels[$key] ?? $key;
}

/* "To verify" + "Ready to approve", the two states the admin can still act on. */
function getResidentRequestsActionCount($con) {
    $n = 0;
    foreach (collectResidentRequestRows($con) as $rr) {
        if ($rr['rr_key'] === 'to_verify' || $rr['rr_key'] === 'ready') { $n++; }
    }
    return $n;
}

/* Guest requests still waiting on the admin. Mirrors the row set of
   getResidentVisitorRequests() (the Guest Request table) and then keeps only the
   ones nobody has decided yet, so the badge and the table agree. A NULL
   approval_status counts as pending, exactly as the page treats it. */
function getGuestRequestsActionCount($con) {
    $query = "SELECT gf.approval_status
              FROM guest_forms gf
              LEFT JOIN users u ON u.id = (
                  SELECT id FROM users
                  WHERE (gf.resident_user_id IS NOT NULL AND users.id = gf.resident_user_id)
                     OR (gf.resident_user_id IS NULL AND gf.resident_email <> '' AND users.email = gf.resident_email)
                     OR (gf.resident_user_id IS NULL AND (gf.resident_email IS NULL OR gf.resident_email = '') AND gf.resident_house <> '' AND users.house_number = gf.resident_house)
                  ORDER BY (gf.resident_user_id IS NOT NULL) DESC,
                           (gf.resident_email <> '' AND users.email = gf.resident_email) DESC,
                           users.id
                  LIMIT 1
              )
              WHERE u.id IS NOT NULL
                AND (gf.approval_status IS NULL OR (gf.approval_status NOT IN ('cancelled','completed','deleted','moved_to_history','permission_granted')))
                AND (gf.approval_status IS NULL OR gf.approval_status = 'pending')";
    try {
        $res = $con->query($query);
    } catch (Throwable $e) {
        return 0;
    }
    if (!$res) { return 0; }
    $n = 0;
    while ($row = $res->fetch_assoc()) {
        $status = strtolower(trim((string)($row['approval_status'] ?? '')));
        if ($status === '' || $status === 'pending') { $n++; }
    }
    return $n;
}

/* Escalated incidents the admin has not resolved, rejected or cancelled. That is
   exactly the set the bell already counts, so the two never disagree. */
function getReportedIncidentsActionCount($con) {
    return intval(getOpenIncidentCount($con));
}

/* The four sidebar action badges. Each number is counted on its own terms so one
   page's backlog never leaks into another's badge. */
function getSidebarActionCounts($con) {
    return array(
        'requests'            => getResidentRequestsActionCount($con),
        'visitor_requests'    => getVisitorRequestsActionCount($con),
        'resident_guest_forms'=> getGuestRequestsActionCount($con),
        'report'              => getReportedIncidentsActionCount($con),
    );
}

/* One sidebar action badge. Hidden at zero, capped at 99+. The page and the
   wording ride along on the element so the poll can repaint any of the four
   without a branch per badge. */
function sidebar_action_badge($page, $label, $n, $unit = 'need action') {
    $n = intval($n);
    return '<b class="nav-badge"'
         . ' data-page="' . htmlspecialchars($page, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-label="' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-unit="' . htmlspecialchars($unit, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-count="' . $n . '"'
         . ($n > 0 ? '' : ' hidden') . '>'
         . ($n > 99 ? '99+' : $n)
         . '</b>';
}

/* The badge is decorative, so the count is announced through the link's name.
   $unit lets one page read as "waiting" without touching the other three. */
function sidebar_action_aria($label, $n, $unit = 'need action') {
    $n = intval($n);
    return htmlspecialchars(
        $n > 0 ? ($label . ', ' . $n . ' ' . $unit) : $label,
        ENT_QUOTES,
        'UTF-8'
    );
}

/* The two states the admin can still act on. This is the sidebar badge number
   and, by definition, "To verify" + "Ready to approve". */
function getVisitorRequestsActionCount($con) {
    $n = 0;
    foreach (collectVisitorRequestRows($con) as $vr) {
        if ($vr['vr_key'] === 'to_verify' || $vr['vr_key'] === 'ready') { $n++; }
    }
    return $n;
}

function getVisitorRequestsCounts($con) {
    $counts = array('all' => 0, 'to_verify' => 0, 'ready' => 0, 'approved' => 0, 'rejected' => 0);
    foreach (collectVisitorRequestRows($con) as $vr) {
        $counts['all']++;
        if (array_key_exists($vr['vr_key'], $counts)) { $counts[$vr['vr_key']]++; }
    }
    return $counts;
}

function vpRelativeTime($ts) {
    $ts = intval($ts);
    if ($ts <= 0) { return ''; }
    $diff = time() - $ts;
    if ($diff < 60) { return 'just now'; }
    if ($diff < 3600) {
        $m = intdiv($diff, 60);
        return $m . ' minute' . ($m === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $h = intdiv($diff, 3600);
        return $h . ' hour' . ($h === 1 ? '' : 's') . ' ago';
    }
    $d = intdiv($diff, 86400);
    if ($d < 30) { return $d . ' day' . ($d === 1 ? '' : 's') . ' ago'; }
    if ($d < 365) {
        $mo = intdiv($d, 30);
        return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
    }
    $y = intdiv($d, 365);
    return $y . ' year' . ($y === 1 ? '' : 's') . ' ago';
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
    // Link guest forms to reservations via ref_code; amenity only when a reservation exists.
    // Match the resident by user id, or by the stored email/house number (covers older rows that
    // were saved without a resident_user_id when the session link could not be resolved).
    $query = "SELECT gf.*, 
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     r.amenity AS amenity, COALESCE(r.persons, gf.persons) AS persons, 
                     u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name
              FROM guest_forms gf
              LEFT JOIN users u ON u.id = (
                  SELECT id FROM users
                  WHERE (gf.resident_user_id IS NOT NULL AND users.id = gf.resident_user_id)
                     OR (gf.resident_user_id IS NULL AND gf.resident_email <> '' AND users.email = gf.resident_email)
                     OR (gf.resident_user_id IS NULL AND (gf.resident_email IS NULL OR gf.resident_email = '') AND gf.resident_house <> '' AND users.house_number = gf.resident_house)
                  ORDER BY (gf.resident_user_id IS NOT NULL) DESC,
                           (gf.resident_email <> '' AND users.email = gf.resident_email) DESC,
                           users.id
                  LIMIT 1
              )
              LEFT JOIN reservations r ON r.ref_code = gf.ref_code
              WHERE u.id IS NOT NULL
              AND (gf.approval_status IS NULL OR (gf.approval_status NOT IN ('cancelled','completed','deleted','moved_to_history','permission_granted')))
              ORDER BY gf.created_at DESC";
    try {
        $res = $con->query($query);
    } catch (Throwable $e) {
        $res = false;
    }
    if ($res) {
        return ($res->num_rows > 0) ? $res : false;
    }
    // Fallback for older guest_forms schemas: match by user id only
    $q2 = "SELECT gf.id, gf.resident_user_id, gf.resident_house, gf.resident_email,
                  gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name,
                  gf.ref_code, gf.approval_status, gf.approved_by, gf.created_at,
                  gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                  u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name
           FROM guest_forms gf
           LEFT JOIN users u ON gf.resident_user_id = u.id
           WHERE gf.resident_user_id IS NOT NULL
             AND (gf.approval_status IS NULL OR (gf.approval_status NOT IN ('cancelled','completed','deleted','moved_to_history','permission_granted')))
           ORDER BY gf.created_at DESC";
    try {
        $res2 = $con->query($q2);
    } catch (Throwable $e) {
        $res2 = false;
    }
    return ($res2 && $res2->num_rows > 0) ? $res2 : false;
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
    // Reservations stay visible for 7 days past their end date, then move to archive.
    // Set both status and approval_status so they are excluded from ALL active queries.
    $con->query("UPDATE reservations SET status='expired', approval_status='expired' WHERE end_date + INTERVAL 7 DAY < CURDATE() AND status NOT IN ('expired', 'cancelled') AND (approval_status IS NULL OR approval_status NOT IN ('expired', 'cancelled'))");
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

function ensureUsersSuspensionReasonColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'suspension_reason'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN suspension_reason VARCHAR(255) NULL AFTER status");
    }
}

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

autoExpireReservations($con);

// admin_v2 one-time schema migration guard
if (!vpSchemaDone($con, 'admin_v2')) {
  ensureReservationStatusColumn($con);
  ensureIncidentTables($con);
  ensureReceiptUploadedAtColumn($con);
  ensureReservationGcashReferenceColumn($con);
  ensureReservationBookerColumns($con);
  ensureResidentApprovalColumns($con);
  ensureResidentReservationQrColumn($con);
  ensureUsersStatusColumn($con);
  ensureUsersSuspensionReasonColumn($con);
  ensureNotificationsTable($con);
  vpMarkSchemaDone($con, 'admin_v2');
}

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
      entered_at DATETIME NULL,
      entered_by INT NULL,
      scanned_at DATETIME NULL,
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

    $statusLink = vp_qr_link('qr_view.php', ['code' => $ref]);

    $relPath = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);

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
    $statusLink = vp_qr_link('qr_view.php', ['code' => $ref]);

    // Generate QR for the status link
    $relPath = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);

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

    $statusLink = vp_qr_link('qr_view.php', ['code' => $ref]);

    $relPath = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);

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
                $confirmDate = trim($_POST['visit_date'] ?? '');
                $confirmTime = trim($_POST['visit_time'] ?? '');
                if ($approval_status === 'approved') {
                  $confirmDateObj = DateTime::createFromFormat('!Y-m-d', $confirmDate);
                  $confirmDateValid = $confirmDateObj && $confirmDateObj->format('Y-m-d') === $confirmDate && $confirmDate >= date('Y-m-d');
                  $confirmTimeValid = (bool)preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $confirmTime);
                  if (!$confirmDateValid || !$confirmTimeValid) {
                    $_SESSION['flash_notice'] = 'Guest Entry Date and Guest Entry Time are required before approval.';
                    $redirectPage = preg_replace('/[^a-z_]/', '', $_POST['redirect_page'] ?? 'resident_guest_forms');
                    header('Location: admin.php?page=' . ($redirectPage ?: 'resident_guest_forms'));
                    exit;
                  }
                  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $confirmTime)) { $confirmTime .= ':00'; }
                    /* Both writes are guarded on "still pending", so a stale tab or a
                       double click cannot overturn a decision that has already
                       been made. Nothing else about the flow changes. */
                    $stmtUp = $con->prepare("UPDATE guest_forms SET approval_status = ?, approved_by = ?, approval_date = NOW(), denial_reason = ?, visit_date = ?, visit_time = ? WHERE id = ? AND (approval_status IS NULL OR approval_status = 'pending')");
                    $stmtUp->bind_param('sisssi', $approval_status, $staff_id, $reasonToSave, $confirmDate, $confirmTime, $reservation_id);
                } else {
                    $stmtUp = $con->prepare("UPDATE guest_forms SET approval_status = ?, approved_by = ?, approval_date = NOW(), denial_reason = ? WHERE id = ? AND (approval_status IS NULL OR approval_status = 'pending')");
                    $stmtUp->bind_param('sisi', $approval_status, $staff_id, $reasonToSave, $reservation_id);
                }
                $stmtUp->execute();
                $gqChanged = $stmtUp->affected_rows;
                $stmtUp->close();
                /* The pending guard can refuse the write. Nothing downstream may
                   run in that case, or the resident would be told about a
                   decision that was never saved. */
                if (!$gqChanged) {
                    $_SESSION['flash_notice'] = 'This guest request is no longer pending. Nothing was changed.';
                    $redirectPage = preg_replace('/[^a-z_]/', '', $_POST['redirect_page'] ?? 'resident_guest_forms');
                    header('Location: admin.php?page=' . ($redirectPage ?: 'resident_guest_forms'));
                    exit;
                }
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
                    $title = ($approval_status === 'approved') ? 'Guest Entry Pass Approved' : 'Guest Request Denied';
                    if ($approval_status === 'approved') {
                        $msg = 'Your guest request has been approved.';
                        if ($confirmDate !== '') { $msg .= ' Confirmed arrival: ' . $confirmDate . ($confirmTime !== '' ? ' ' . $confirmTime : '') . '.'; }
                        $msg .= ' The entry pass with a unique QR code is now available in your My Requests list and is valid only on the approved date and time.';
                    } else {
                        $msg = 'Your guest request has been denied.';
                        if ($denialReason) { $msg .= ' Reason: ' . $denialReason; }
                    }
                    if (!empty($notifRef)) { $msg .= ' Code: ' . $notifRef . '.'; }
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
                if ($approval_status === 'approved' && !empty($amenRes) && $psRes !== 'verified') {
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
  $amenityOrder = ['Clubhouse','Multi-Purpose Building','Basketball Court','Tennis Court'];
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
      $package = '-';
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

// Determine active system: VictorianPass or VHEcoPoint
$victorianPassPages = ['dashboard', 'residents', 'visitors', 'requests', 'resident_guest_forms', 'visitor_requests', 'report', 'security', 'history', 'summary'];
$vhEcoPointPages = ['smart_waste', 'smart_waste_sessions', 'smart_waste_logs'];
$currentSystem = in_array($currentPage, $vhEcoPointPages) ? 'ecopoint' : 'victorianpass';
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
<link rel="stylesheet" href="css/ecopoint-brand.css?v=<?php echo substr(@md5_file(__DIR__ . '/css/ecopoint-brand.css') ?: '', 0, 12); ?>">

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
    --sidebar-width: 240px;
    --header-height: 60px;
}

/* Reset & Base */
* { box-sizing: border-box; }
body, button, input, select, textarea { font-family: 'Poppins', sans-serif; }
*:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* Global Form Fields */
input[type="text"], input[type="email"], input[type="password"], input[type="number"],
input[type="date"], input[type="month"], input[type="search"], input[type="tel"],
input[type="url"], select, textarea {
    font-family: 'Poppins', sans-serif;
    font-size: 0.85rem;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #fff;
    color: var(--text-main);
    transition: border-color 0.2s, box-shadow 0.2s;
    line-height: 1.4;
}
input[type="text"]:focus, input[type="email"]:focus, input[type="password"]:focus,
input[type="number"]:focus, input[type="date"]:focus, input[type="month"]:focus,
input[type="search"]:focus, input[type="tel"]:focus, input[type="url"]:focus,
select:focus, textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(35,65,46,0.1);
}
select {
    appearance: none;
    -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%236b7280' d='M2 4l4 4 4-4'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    padding-right: 30px;
    cursor: pointer;
}
label {
    font-family: 'Poppins', sans-serif;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 5px;
    display: block;
}

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
    padding-top: 10px;
}

.sidebar-topbar {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 12px 14px 10px;
    border-bottom: 1px solid rgba(255,255,255,0.06);
}

.sidebar-header-row {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
}

.sidebar-title-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}

.sidebar-title {
    font-size: 1.05rem;
    font-weight: 800;
    line-height: 1.1;
    color: #fff;
    white-space: normal;
}

.sidebar-subtitle {
    font-size: 0.8rem;
    color: rgba(255,255,255,0.76);
    font-weight: 600;
    white-space: nowrap;
}

.nav-list {
    padding: 14px 10px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.nav-item {
    padding: 10px 14px;
    border-radius: 10px;
    color: rgba(255,255,255,0.78);
    font-weight: 500;
    font-size: 0.9rem;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 10px;
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

.nav-item.smart-waste-link {
    background: rgba(34, 197, 94, 0.12);
    color: #c7f9cc;
    border: 1px solid rgba(34, 197, 94, 0.28);
}

.nav-item.smart-waste-link i {
    color: #7ee787;
}

.nav-item.smart-waste-link:hover,
.nav-item.smart-waste-link.active {
    background: linear-gradient(135deg, rgba(34, 197, 94, 0.26), rgba(22, 163, 74, 0.18));
    color: #ffffff;
    border-color: rgba(134, 239, 172, 0.45);
    box-shadow: inset 3px 0 0 #7ee787, 0 8px 18px rgba(34, 197, 94, 0.18);
}

.nav-item.smart-waste-link:hover i,
.nav-item.smart-waste-link.active i {
    color: #dcfce7;
}

.nav-item .nav-copy {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.nav-item .nav-copy small {
    font-size: 0.68rem;
    font-weight: 600;
    line-height: 1.2;
    opacity: 0.78;
}

.nav-item.smart-waste-link .nav-copy strong {
    font-size: 0.95rem;
}

.nav-item.smart-waste-link .nav-copy small {
    color: #dcfce7;
    opacity: 0.92;
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

/* ---- Sidebar action badges (Resident Requests, Visitor Requests,
   Guest Request, Reported Incidents) ----
   Same red as the top-bar bell count (.notif-badge): var(--danger) on white
   bold text. The sidebar is always dark (--bg-sidebar), so the white number
   keeps its contrast on both the normal and the active item, and in light and
   dark mode alike. Only these four items are positioned, so nothing else in
   the sidebar can be nudged by the badge. */
.nav-item[data-page="requests"],
.nav-item[data-page="visitor_requests"],
.nav-item[data-page="resident_guest_forms"],
.nav-item[data-page="report"] { position: relative; }
.nav-badge {
    margin-left: auto;
    flex-shrink: 0;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--danger);
    color: #fff;
    font-size: 0.68rem;
    font-weight: 700;
    font-style: normal;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-variant-numeric: tabular-nums;
    box-shadow: 0 0 0 2px var(--bg-sidebar);
}
.nav-badge[hidden] { display: none; }

/* Collapsed to icons: the number cannot fit, so it becomes a red dot on the
   icon. <b> rather than <span> because the collapsed rule hides nav-item spans. */
body.sidebar-collapsed .nav-badge {
    position: absolute;
    top: 3px;
    left: 30px;
    margin-left: 0;
    width: 10px;
    min-width: 10px;
    height: 10px;
    padding: 0;
    font-size: 0;
    border-radius: 50%;
    box-shadow: 0 0 0 2px var(--bg-sidebar);
}

/* Mobile: the nav collapses into a horizontal strip of pills, so again a dot. */
@media (max-width: 768px) {
    .nav-badge {
        position: absolute;
        top: 2px;
        left: 10px;
        margin-left: 0;
        width: 9px;
        min-width: 9px;
        height: 9px;
        padding: 0;
        font-size: 0;
        border-radius: 50%;
        box-shadow: 0 0 0 2px #2b2623;
    }
}

/* Pulse once when a new request needs action, then stay static. */
.nav-badge.pulse { animation: vrBadgePulse 1s ease-out; }
@keyframes vrBadgePulse {
    0%, 100% { transform: scale(1); }
    45%      { transform: scale(1.35); }
}
@media (prefers-reduced-motion: reduce) {
    .nav-badge.pulse { animation: none; }
}

/* System Switcher */
.system-switcher-header {
  --vh-eco-logo-size: 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    align-items: stretch;
    margin-left: 0;
}

.system-switch-header-btn {
    display: grid;
    grid-template-columns: var(--vh-eco-logo-size) minmax(0, 1fr) var(--vh-eco-logo-size);
    align-items: center;
    gap: 0;
    height: 34px;
    padding: 0 10px;
    border: 1px solid rgba(255,255,255,0.18);
    border-radius: 8px;
    background: rgba(255,255,255,0.06);
    color: rgba(255,255,255,0.8);
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    cursor: pointer;
    transition: all 0.2s ease;
    text-decoration: none;
    white-space: nowrap;
}
  .system-switch-header-btn > span {
    text-align: center;
  }

.system-switch-header-btn:hover {
    background: rgba(255,255,255,0.12);
    color: #fff;
    border-color: rgba(255,255,255,0.28);
}

.system-switch-header-btn.active {
    background: linear-gradient(135deg, rgba(212, 175, 55, 0.18), rgba(255,255,255,0.08));
    border-color: rgba(212, 175, 55, 0.75);
    color: #fff;
    box-shadow: 0 0 0 1px rgba(212, 175, 55, 0.2), 0 6px 16px rgba(0,0,0,0.12);
}

.system-switch-header-btn.ecopoint-switch {
    background: rgba(34, 197, 94, 0.1) !important;
    border-color: rgba(134, 239, 172, 0.3) !important;
}

.system-switch-header-btn.ecopoint-switch.active {
    background: linear-gradient(135deg, rgba(21, 128, 61, 0.45), rgba(22, 163, 74, 0.22)) !important;
    border-color: rgba(134, 239, 172, 0.8) !important;
    color: #fff !important;
}

.system-switch-header-btn i {
    font-size: 0.95rem;
}
.system-switch-header-btn .system-switch-victorian-logo {
  display: block;
  width: var(--vh-eco-logo-size);
  height: var(--vh-eco-logo-size);
  flex: 0 0 var(--vh-eco-logo-size);
  object-fit: contain;
}

/* Old sidebar switcher - hide it */
.system-switcher {
    display: none;
}

/* Navigation grouping */
.nav-section {
    margin-bottom: 8px;
}

.nav-section-title {
    font-size: 0.7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: rgba(255,255,255,0.4);
    padding: 12px 20px 6px;
    margin: 8px 0 0 0;
}

.nav-section:first-child .nav-section-title {
    margin-top: 0;
}

.sidebar-footer {
    margin-top: auto;
    padding: 14px 16px 18px;
    border-top: 1px solid rgba(255,255,255,0.06);
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
    gap: 14px;
}
.header-brand {
    gap: 12px;
    min-width: 0;
    flex: 1;
    justify-content: flex-start;
    padding-left: 0;
}
.header-actions {
    margin-left: auto;
    justify-content: flex-end;
}
.notifications {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}
.header-title-group {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
    flex-wrap: nowrap;
}
.header-brand .sidebar-toggle {
    margin-right: 0;
}
.header-brand-text {
    display: flex;
    flex-direction: column;
    line-height: 1.1;
    justify-content: center;
}
.header-title {
    font-size: 1.1rem;
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

body.sidebar-collapsed .sidebar {
    width: 80px;
    overflow-x: hidden;
    overflow-y: auto;
}
body.sidebar-collapsed .sidebar-topbar {
    padding: 12px 10px 8px;
    overflow: hidden;
}
body.sidebar-collapsed .sidebar-header-row {
    justify-content: center;
    margin-bottom: 8px;
}
body.sidebar-collapsed .sidebar-title-group,
body.sidebar-collapsed .sidebar-subtitle,
body.sidebar-collapsed .nav-section-title,
body.sidebar-collapsed .nav-item span,
body.sidebar-collapsed .sidebar-footer .text-muted-link span,
body.sidebar-collapsed .system-switch-header-btn span {
    display: none !important;
}
body.sidebar-collapsed .system-switcher-header {
    align-items: center;
    gap: 8px;
    padding: 0 2px;
}
body.sidebar-collapsed .system-switch-header-btn {
  display: inline-flex;
    width: 42px;
    min-width: 42px;
    padding: 0;
    justify-content: center;
}
body.sidebar-collapsed .nav-list {
    padding: 16px 10px;
}
body.sidebar-collapsed .nav-item {
    justify-content: center;
    padding: 10px 8px;
    gap: 0;
    width: 100%;
}
body.sidebar-collapsed .nav-item i {
    width: auto;
    font-size: 1.1rem;
}
body.sidebar-collapsed .sidebar-footer {
    padding: 16px 10px;
}
body.sidebar-collapsed .sidebar-footer .text-muted-link {
    padding: 10px;
    width: 100%;
    justify-content: center;
}
body.sidebar-collapsed .sidebar-toggle {
    margin: 0 auto;
}

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
    padding: 14px 30px 6px;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 8px;
}

.page-header h2 { font-size: 1.5rem; color: var(--text-main); }

.header-search {
    flex: 1;
    display: flex;
    justify-content: center;
    min-width: 0;
    padding: 0 8px;
}
.search {
    background: rgba(255,255,255,0.12);
    border: 1px solid rgba(255,255,255,0.2);
    border-radius: 999px;
    padding: 8px 16px;
    width: min(760px, 100%);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: var(--transition);
    font-family: 'Poppins', sans-serif;
}
.search:focus-within { border-color: rgba(255,255,255,0.45); box-shadow: 0 0 0 3px rgba(255,255,255,0.12); }
.search-icon {
    color: rgba(255,255,255,0.72);
    font-size: 0.9rem;
    flex-shrink: 0;
}
.search input { border: none; width: 100%; font-size: 0.88rem; font-family: 'Poppins', sans-serif; background: transparent; outline: none; color: #fff; }
.search input::placeholder { color: rgba(255,255,255,0.6); font-family: 'Poppins', sans-serif; }

/* Dashboard Widgets */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    padding: 0 30px;
    margin-bottom: 24px;
}

.dashboard-widget {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 18px 20px;
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
    height: 3px;
    background: var(--primary);
    opacity: 0.5;
    transition: var(--transition);
}

.dashboard-widget:hover {
    box-shadow: var(--shadow-md);
    transform: translateY(-2px);
}
.dashboard-widget:hover::before { opacity: 1; }

.dashboard-widget-value {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--text-main);
    margin: 6px 0;
}

.dashboard-widget-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    font-weight: 500;
}

.dashboard-widget-subtext {
    font-size: 0.78rem;
    color: var(--text-muted);
    margin-top: 2px;
}

/* Panels & Cards */
.panel, .card-box {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 20px 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    margin: 0 30px 24px 30px;
    overflow-x: auto;
}

.panel h3, .card-box h3 {
    margin: 0 0 16px 0;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 12px;
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

/* Smart Waste Station */
.smart-waste-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.75fr) minmax(300px, 1fr);
    gap: 24px;
    align-items: start;
}
.smart-waste-main,
.smart-waste-side {
    display: flex;
    flex-direction: column;
    gap: 24px;
    min-width: 0;
}
.smart-waste-card {
    background: linear-gradient(180deg, #ffffff 0%, #fbfcfd 100%);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 20px;
    box-shadow: var(--shadow-sm);
    min-width: 0;
    overflow: hidden;
}
.smart-waste-card h4 {
    font-size: 1.05rem;
    margin-bottom: 6px;
}
.smart-waste-note {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 16px;
}
.smart-waste-chart {
    display: grid;
    grid-template-columns: repeat(7, minmax(0, 1fr));
    gap: 12px;
    align-items: end;
    min-height: 180px;
}
.smart-waste-bar-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
}
.smart-waste-bar {
    width: 100%;
    min-height: 18px;
    border-radius: 12px 12px 6px 6px;
    background: linear-gradient(180deg, #7ed957 0%, #3a7d1f 100%);
    box-shadow: inset 0 -1px 0 rgba(255,255,255,0.15);
}
.smart-waste-bar-value {
    font-size: 0.78rem;
    color: var(--text-secondary);
    font-weight: 600;
}
.smart-waste-bar-label {
    font-size: 0.78rem;
    color: var(--text-muted);
    font-weight: 600;
}
.smart-waste-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.smart-waste-list-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border-light);
}
.smart-waste-list-item:last-child {
    border-bottom: none;
    padding-bottom: 0;
}
.smart-waste-material-icon,
.smart-waste-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    flex-shrink: 0;
}
.smart-waste-avatar {
    background: linear-gradient(135deg, #23412e 0%, #2f7d32 100%);
    color: #fff;
    font-size: 0.82rem;
}
.smart-waste-material-icon {
    background: #eef6ef;
    color: #2f7d32;
}
.smart-waste-list-main {
    flex: 1;
    min-width: 0;
}
.smart-waste-list-title {
    font-weight: 700;
    color: var(--text-main);
    font-size: 0.92rem;
}
.smart-waste-list-subtitle {
    color: var(--text-secondary);
    font-size: 0.82rem;
}
.smart-waste-list-value {
    font-weight: 800;
    color: var(--success);
    font-size: 0.9rem;
    white-space: nowrap;
}
.smart-waste-progress {
    margin-top: 8px;
    width: 100%;
    height: 8px;
    background: #edf2f7;
    border-radius: 999px;
    overflow: hidden;
}
.smart-waste-progress-bar {
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #7ed957 0%, #3cb371 100%);
}
.smart-waste-status-pill,
.smart-waste-tier-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 0.7rem;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    line-height: 1.3;
}
.smart-waste-status-pill {
    background: #ecfdf3;
    color: #15803d;
}
.smart-waste-tier-pill {
    background: #f3f4f6;
    color: #374151;
}
.smart-waste-tier-pill.is-unlocked {
    background: #ecfdf3;
    color: #166534;
}
.smart-waste-status-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.smart-waste-status-box {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px;
    background: #fff;
}
.smart-waste-status-label {
    color: var(--text-secondary);
    font-size: 0.82rem;
    margin-bottom: 4px;
}
.smart-waste-status-value {
    font-weight: 800;
    color: var(--text-main);
}
.smart-waste-bin-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px;
}
.smart-waste-bin-card {
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 14px;
    background: #fff;
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
}
.smart-waste-bin-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
    margin-bottom: 10px;
}
.smart-waste-bin-label {
    font-weight: 800;
    color: var(--text-main);
    font-size: 0.92rem;
}
.smart-waste-bin-subtitle {
    color: var(--text-secondary);
    font-size: 0.78rem;
    margin-top: 2px;
}
.smart-waste-bin-percent {
    font-weight: 800;
    font-size: 1.15rem;
    color: var(--text-main);
    white-space: nowrap;
}
.smart-waste-meter {
    width: 100%;
    height: 12px;
    border-radius: 999px;
    background: #edf2f7;
    overflow: hidden;
    margin-bottom: 8px;
}
.smart-waste-meter-bar {
    height: 100%;
    border-radius: inherit;
}
.smart-waste-bin-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    color: var(--text-secondary);
    font-size: 0.78rem;
}
.smart-waste-bin-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 4px 10px;
    font-size: 0.7rem;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    line-height: 1.3;
}
.smart-waste-bin-pill.is-empty {
    background: #f3f4f6;
    color: #4b5563;
}
.smart-waste-bin-pill.is-low {
    background: #ecfdf3;
    color: #15803d;
}
.smart-waste-bin-pill.is-medium {
    background: #eff6ff;
    color: #1d4ed8;
}
.smart-waste-bin-pill.is-high {
    background: #fff7ed;
    color: #c2410c;
}
.smart-waste-bin-pill.is-full {
    background: #fef2f2;
    color: #b91c1c;
}
.smart-waste-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}
.smart-waste-kpi {
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 14px;
    background: #fff;
}
.smart-waste-kpi-label {
    color: var(--text-secondary);
    font-size: 0.8rem;
    margin-bottom: 4px;
}
.smart-waste-kpi-value {
    color: var(--text-main);
    font-size: 1.2rem;
    font-weight: 800;
}
.smart-waste-kpi-subtext {
    color: var(--text-muted);
    font-size: 0.78rem;
    margin-top: 4px;
}
.smart-waste-table-compact table {
    min-width: 100%;
}
.smart-waste-table-compact th,
.smart-waste-table-compact td {
    white-space: nowrap;
}
.smart-waste-table-compact td.wrap {
    white-space: normal;
}
.smart-waste-empty {
    padding: 18px;
    border: 1px dashed var(--border);
    border-radius: 12px;
    color: var(--text-secondary);
    background: #fafafa;
    text-align: center;
}
.smart-waste-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
    align-items: end;
}
.smart-waste-form-grid label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 5px;
}
.smart-waste-upload-form label {
    display: block;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-secondary);
    margin-bottom: 6px;
}
.smart-waste-upload-form input,
.smart-waste-upload-form select {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: 8px;
    font-size: 0.85rem;
    font-family: 'Poppins', sans-serif;
    background: #fff;
    color: var(--text-main);
}
.smart-waste-upload-form input:focus,
.smart-waste-upload-form select:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(35,65,46,0.1);
}
.smart-waste-preview {
    margin-top: 14px;
    border: 1px dashed var(--border);
    border-radius: 14px;
    background: #f8fafc;
    padding: 12px;
    display: none;
    justify-content: center;
}
.smart-waste-preview img {
    width: 100%;
    max-height: 260px;
    object-fit: contain;
    border-radius: 10px;
}
.smart-waste-upload-actions {
    margin-top: 14px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
}
.smart-waste-upload-status {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 12px;
    font-size: 0.88rem;
    display: none;
}
.smart-waste-upload-status.is-info {
    display: block;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.smart-waste-upload-status.is-success {
    display: block;
    background: #ecfdf3;
    color: #15803d;
    border: 1px solid #86efac;
}
.smart-waste-upload-status.is-error {
    display: block;
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid #fecaca;
}
.smart-waste-config-note {
    margin-top: 14px;
    padding: 12px 14px;
    border-radius: 12px;
    background: #fffbeb;
    color: #92400e;
    border: 1px solid #fcd34d;
    font-size: 0.85rem;
}
.table-responsive-wrapper {
    width: 100%;
    max-width: 100%;
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
}
.smart-waste-card .table-responsive-wrapper table {
    min-width: 760px;
}

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
table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 760px; table-layout: auto; }
table.table-reservations,
table.table-resident-guest { min-width: 960px; }
table.table-reservations th,
table.table-resident-guest th { white-space: nowrap; }
table.table-reservations td,
table.table-resident-guest td { vertical-align: top; }
th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid var(--border-light); font-size: 0.85rem; vertical-align: middle; line-height: 1.45; white-space: normal; overflow-wrap: anywhere; word-break: break-word; }
th {
    font-weight: 600;
    color: var(--text-secondary);
    background: #f9fafb;
    text-transform: uppercase;
    font-size: 0.72rem;
    letter-spacing: 0.5px;
    position: sticky;
    top: 0;
    z-index: 10;
}
tr:last-child td { border-bottom: none; }
tr:hover { background-color: #f9fafb; }

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
    padding: 7px 14px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.82rem;
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
    padding: 4px 10px;
    border-radius: 50px;
    font-size: 0.7rem;
    font-weight: 600;
    font-family: 'Poppins', sans-serif;
    text-transform: uppercase;
    display: inline-flex;
    align-items: center;
    letter-spacing: 0.4px;
    white-space: nowrap;
    line-height: 1.3;
}

.status.active, .badge-active, .status-ongoing, .status-completed, .badge-approved { background: #dcfce7; color: #166534; }
.status.pending, .badge-pending, .status-pending { background: #fef3c7; color: #92400e; }
.status.rejected, .badge-rejected, .badge-denied, .status-denied { background: #fee2e2; color: #991b1b; }
.status-cancelled { background: #f3f4f6; color: #6b7280; }

/* =========================================================
   Resident Amenity Requests  (admin.php?page=requests)
   ========================================================= */

/* Page header + subtitle */
.page-header-stack { display: block; }
.page-subtitle {
    margin: 4px 0 0;
    font-size: 0.85rem;
    font-weight: 400;
    line-height: 1.4;
    color: var(--text-secondary);
}

/* Filter boxes */
.rr-filters {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    margin: 0 0 10px;
}
.rr-filter {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    min-width: 0;
    padding: 12px 16px;
    min-height: 68px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    text-align: left;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}
.rr-filter:hover { border-color: var(--accent); background: var(--primary-light); }
.rr-filter:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rr-filter[aria-pressed="true"] {
    border: 2px solid var(--accent);
    padding: 11px 15px;
    background: rgba(212, 175, 55, 0.12);
}
.rr-filter-count {
    font-size: 1.55rem;
    font-weight: 600;
    line-height: 1.15;
    color: var(--text-main);
}
.rr-filter[aria-pressed="true"] .rr-filter-count { color: var(--primary-dark); }
.rr-filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    font-weight: 500;
    line-height: 1.3;
    color: var(--text-secondary);
}
.rr-filter[aria-pressed="true"] .rr-filter-label { color: var(--text-main); }
.rr-filter-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    background: var(--warning);
}
.rr-filter-dot.is-ready { background: var(--primary); }

/* Table */
.rr-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
table.table-rr { width: 100%; min-width: 1340px; table-layout: fixed; }
table.table-rr th {
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: var(--border-light);
    padding: 10px 14px;
}
table.table-rr td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-light);
}
table.table-rr tbody tr:last-child td { border-bottom: 1px solid var(--border-light); }
table.table-rr tbody tr:hover { background: var(--primary-light); }
table.table-rr tbody tr.rr-empty:hover { background: transparent; }

.rr-resident-name {
    display: block;
    font-weight: 600;
    color: var(--text-main);
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    overflow-wrap: break-word;
}
.rr-resident-meta {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.rr-amount {
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
.rr-payment-eco {
    display: inline-block;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
}
.rr-payment-eco:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
.rr-payment-points {
    display: block;
    margin-top: 2px;
    color: var(--text-muted);
    font-size: 0.72rem;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}
/* Status pills */
.rr-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.3;
    letter-spacing: 0;
    text-transform: none;
    white-space: nowrap;
}
.rr-pill-to_verify { background: #fef3c7; color: #92400e; }
.rr-pill-ready { background: var(--primary); color: #fff; }
.rr-pill-approved { background: #dcfce7; color: #166534; }
.rr-pill-rejected { background: #fee2e2; color: #991b1b; }

/* Actions: one horizontal row, equal buttons, no shadows */
table.table-rr td.actions {
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: nowrap;
    gap: 8px;
    min-width: 280px;
}
table.table-rr td.actions form {
    width: auto;
    margin: 0;
    display: inline-flex;
    flex-shrink: 0;
}
table.table-rr td.actions .btn,
table.table-rr td.actions a.btn {
    width: 118px;
    min-width: 118px;
    flex-shrink: 0;
    min-height: 32px;
    padding: 0 10px;
    border-radius: var(--radius);
    font-size: 0.78rem;
    box-shadow: none;
    white-space: nowrap;
    justify-content: center;
}
table.table-rr td.actions .btn:hover,
table.table-rr td.actions a.btn:hover {
    box-shadow: none;
    transform: none;
}

table.table-rr tbody tr.rr-empty td {
    text-align: center;
    padding: 28px 14px;
    border-bottom: none;
    color: var(--text-secondary);
    font-size: 0.85rem;
}

@media (max-width: 1100px) {
    .rr-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 700px) {
    .rr-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 440px) {
    .rr-filters { grid-template-columns: 1fr; }
}

/* A one-time server message, e.g. "this request is no longer pending". */
.rr-flash {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0 0 16px;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-left: 3px solid var(--warning);
    border-radius: var(--radius);
    background: var(--warning-bg);
    color: var(--text-main);
    font-size: 0.82rem;
}
.rr-flash button {
    margin-left: auto;
    background: none;
    border: 0;
    padding: 0 2px;
    color: var(--text-secondary);
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius);
}
.rr-flash button:hover { color: var(--text-main); }
.rr-flash button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* ---- Sort and filter bar. Search lives in the page header. ---- */
.rr-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 0 0 8px;
}
.rr-field { display: flex; flex-direction: column; gap: 4px; }
.rr-field > label {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--text-muted);
}
.rr-select {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
    min-width: 150px;
}
.rr-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.rr-select:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rr-date-range { display: flex; align-items: center; gap: 6px; }
.rr-date-control { display: flex; align-items: flex-end; gap: 8px; }
.rr-date-range[hidden] { display: none; }
.rr-date-range input[type="date"] {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
}
.rr-date-range input[type="date"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.rr-date-range input[type="date"]:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rr-clear {
    margin-left: auto;
    background: none;
    border: none;
    padding: 6px 2px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
    border-radius: var(--radius);
    transition: var(--transition);
}
.rr-clear:hover { color: var(--danger); }
.rr-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rr-clear[hidden] { display: none; }
/* One control block wraps only when the available width requires it. */
.rr-controls {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1 1 100%;
    min-width: 0;
}

/* ---- Result line ---- */
.rr-result-line {
    margin: 0 0 12px;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* ---- Submitted column ---- */
.rr-submitted {
    display: block;
    color: var(--text-main);
    white-space: nowrap;
}
.rr-ago,
.rr-when {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    white-space: nowrap;
}
.rr-res-date {
    display: block;
    color: var(--text-main);
    white-space: nowrap;
}
.rr-submitted-date,
.rr-submitted-time,
.rr-when { display: block; white-space: nowrap; }
.rr-resident-name:focus-visible,
.rr-resident-meta:focus-visible,
.vr-visitor-name:focus-visible,
.vr-visitor-meta:focus-visible,
.vr-amenity:focus-visible,
.gq-name:focus-visible,
.gq-meta:focus-visible,
.gq-request-contact:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}

/* ---- Sortable column headers ---- */
table.table-rr th.rr-sortable { cursor: pointer; user-select: none; }
table.table-rr th.rr-sortable:hover { color: var(--text-main); }
table.table-rr th.rr-sortable:focus-visible { outline: 2px solid var(--primary); outline-offset: -2px; }
.rr-sort-arrow {
    display: inline-block;
    margin-left: 5px;
    font-size: 0.62rem;
    color: var(--text-muted);
}
table.table-rr th.rr-sort-active { color: var(--text-main); }
table.table-rr th.rr-sort-active .rr-sort-arrow { color: var(--primary); }

/* ---- Empty state ---- */
table.table-rr tbody tr.rr-empty .rr-empty-clear {
    display: inline-flex;
    margin: 12px 0 0 12px;
    padding: 7px 14px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.78rem;
    font-weight: 500;
    cursor: pointer;
}
table.table-rr tbody tr.rr-empty .rr-empty-clear:hover { border-color: var(--accent); background: var(--primary-light); }
table.table-rr tbody tr.rr-empty .rr-empty-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
table.table-rr tbody tr.rr-empty .rr-empty-clear[hidden] { display: none; }

/* ---- Pagination ---- */
.rr-pager {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin: 16px 0 0;
}
.rr-pager-info {
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-right: auto;
}
.rr-page-btn {
    min-width: 34px;
    min-height: 34px;
    padding: 0 10px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.8rem;
    cursor: pointer;
    box-shadow: none;
    transition: var(--transition);
}
.rr-page-btn:hover:not(:disabled) { border-color: var(--accent); background: var(--primary-light); }
.rr-page-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.rr-page-btn[aria-current="true"] {
    border: 2px solid var(--accent);
    background: rgba(212, 175, 55, 0.12);
    color: var(--text-main);
    font-weight: 600;
}
.rr-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }

@media (max-width: 760px) {
    .rr-controls { gap: 8px; }
    .rr-controls > .rr-field { flex: 1 1 155px; }
    .rr-controls > .rr-date-field { flex: 1 1 310px; }
    .rr-controls .rr-select { width: 100%; min-width: 0; }
    .rr-date-control { flex-wrap: wrap; }
}

/* =========================================================
   Visitor Requests  (admin.php?page=visitor_requests)
   Mirrors the Resident Requests token values on purpose, so the two
   admin request lists read as one system.
   ========================================================= */

/* Filter boxes */
.vr-filters {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 16px;
    margin: 0 0 16px;
}
.vr-filter {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    min-width: 0;
    padding: 12px 16px;
    min-height: 68px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    text-align: left;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}
.vr-filter:hover { border-color: var(--accent); background: var(--primary-light); }
.vr-filter:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.vr-filter[aria-pressed="true"] {
    border: 2px solid var(--accent);
    padding: 11px 15px;
    background: rgba(212, 175, 55, 0.12);
}
.vr-filter-count {
    font-size: 1.55rem;
    font-weight: 600;
    line-height: 1.15;
    color: var(--text-main);
}
.vr-filter[aria-pressed="true"] .vr-filter-count { color: var(--primary-dark); }
.vr-filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    font-weight: 500;
    line-height: 1.3;
    color: var(--text-secondary);
}
.vr-filter[aria-pressed="true"] .vr-filter-label { color: var(--text-main); }
/* Only the two actionable boxes carry a dot, and only while they have work. */
.vr-filter-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    background: var(--warning);
}
.vr-filter-dot.is-ready { background: var(--primary); }

/* Sort / filter bar */
.vr-toolbar {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin: 0 0 16px;
}
.vr-field { display: flex; flex-direction: column; gap: 4px; }
.vr-field > label {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--text-muted);
}
.vr-select {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
    min-width: 148px;
}
.vr-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.vr-select:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

.vr-date-range { display: flex; align-items: center; gap: 6px; }
.vr-date-range input[type="date"] {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
}
.vr-date-range input[type="date"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.vr-date-range input[type="date"]:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

.vr-clear {
    margin-left: auto;
    background: none;
    border: none;
    padding: 6px 2px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
    border-radius: var(--radius);
    transition: var(--transition);
}
.vr-clear:hover { color: var(--danger); }
.vr-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.vr-clear[hidden] { display: none; }

.vr-mobile-filters { display: none; }

/* One container: an inline row on desktop, a stacked panel on mobile. Keeps the
   controls in the DOM once, so there is nothing to move on resize. */
.vr-controls {
    display: flex;
    align-items: flex-end;
    gap: 16px;
    flex-wrap: wrap;
    flex: 1 1 auto;
    min-width: 0;
}

/* Result line */
.vr-result-line {
    margin: 0 0 12px;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Table */
.vr-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
table.table-vr { width: 100%; min-width: 1270px; table-layout: fixed; }
table.table-vr th {
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: var(--border-light);
    padding: 10px 14px;
    white-space: nowrap;
}
table.table-vr th.vr-sortable {
    cursor: pointer;
    user-select: none;
}
table.table-vr th.vr-sortable:hover { color: var(--text-main); }
table.table-vr th.vr-sortable:focus-visible { outline: 2px solid var(--primary); outline-offset: -2px; }
.vr-sort-arrow {
    display: inline-block;
    margin-left: 5px;
    font-size: 0.62rem;
    color: var(--text-muted);
}
th.vr-sort-active .vr-sort-arrow { color: var(--primary); }
th.vr-sort-active { color: var(--text-main); }

table.table-vr td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-light);
}
table.table-vr tbody tr:last-child td { border-bottom: 1px solid var(--border-light); }
table.table-vr tbody tr:hover { background: var(--primary-light); }
table.table-vr tbody tr.vr-empty:hover { background: transparent; }

.vr-visitor-name {
    display: block;
    font-weight: 600;
    color: var(--text-main);
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    overflow-wrap: break-word;
}
.vr-visitor-meta {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.vr-ref {
    font-weight: 500;
    color: var(--text-secondary);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
    word-break: keep-all;
}
.vr-amenity {
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    overflow-wrap: break-word;
}
.vr-submitted {
    display: block;
    color: var(--text-main);
    white-space: nowrap;
}
.vr-submitted-date,
.vr-submitted-time { display: block; white-space: nowrap; }
.vr-ago {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    white-space: nowrap;
}
.vr-amount {
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
}

/* Status pills */
.vr-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.3;
    letter-spacing: 0;
    text-transform: none;
    white-space: nowrap;
}
.vr-pill-to_verify { background: #fef3c7; color: #92400e; }
.vr-pill-ready     { background: var(--primary); color: #fff; }
.vr-pill-approved  { background: #dcfce7; color: #166534; }
.vr-pill-rejected  { background: #fee2e2; color: #991b1b; }

/* Actions: one horizontal row, equal buttons, no shadows */
table.table-vr td.actions {
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: nowrap;
    gap: 8px;
    min-width: 280px;
}
table.table-vr td.actions form {
    width: auto;
    margin: 0;
    display: inline-flex;
    flex-shrink: 0;
}
table.table-vr td.actions .btn,
table.table-vr td.actions a.btn {
    width: 118px;
    min-width: 118px;
    flex-shrink: 0;
    min-height: 32px;
    padding: 0 10px;
    border-radius: var(--radius);
    font-size: 0.78rem;
    box-shadow: none;
    white-space: nowrap;
    justify-content: center;
}
table.table-vr td.actions .btn:hover,
table.table-vr td.actions a.btn:hover {
    box-shadow: none;
    transform: none;
}
/* The Approve button fades in when "Approve receipt" unlocks the row. */
@keyframes vrFadeIn {
    from { opacity: 0; transform: translateY(3px); }
    to   { opacity: 1; transform: none; }
}
.vr-btn-fade { animation: vrFadeIn 0.28s ease-out; }
@media (prefers-reduced-motion: reduce) {
    .vr-btn-fade { animation: none; }
}

table.table-vr tbody tr.vr-empty td {
    text-align: center;
    padding: 32px 14px;
    border-bottom: none;
    color: var(--text-secondary);
    font-size: 0.85rem;
}
table.table-vr tbody tr.vr-empty .vr-empty-clear {
    display: inline-flex;
    margin-top: 12px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 8px 16px;
    min-height: 34px;
    cursor: pointer;
    box-shadow: none;
}
table.table-vr tbody tr.vr-empty .vr-empty-clear:hover { border-color: var(--accent); background: var(--primary-light); }
table.table-vr tbody tr.vr-empty .vr-empty-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* Pagination */
.vr-pager {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin: 16px 0 0;
}
.vr-pager-info {
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-right: auto;
}
.vr-page-btn {
    min-width: 34px;
    min-height: 34px;
    padding: 0 10px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.8rem;
    cursor: pointer;
    box-shadow: none;
    transition: var(--transition);
}
.vr-page-btn:hover:not(:disabled) { border-color: var(--accent); background: var(--primary-light); }
.vr-page-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.vr-page-btn[aria-current="true"] {
    border: 2px solid var(--accent);
    background: rgba(212, 175, 55, 0.12);
    color: var(--text-main);
    font-weight: 600;
}
.vr-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }

@media (max-width: 1100px) {
    .vr-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 760px) {
    .vr-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 440px) {
    .vr-filters { grid-template-columns: 1fr; }
}
/* Tablet: the five boxes wrap onto two lines. */
@media (min-width: 761px) and (max-width: 1100px) {
    .vr-filters { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
/* Mobile: dropdowns collapse behind one button, search stays visible. */
@media (max-width: 760px) {
    .vr-toolbar { flex-wrap: nowrap; align-items: center; }
    .vr-mobile-filters {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
        min-height: 38px;
        padding: 0 14px;
        background: var(--bg-surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        color: var(--text-main);
        font-family: 'Poppins', sans-serif;
        font-size: 0.82rem;
        font-weight: 500;
        cursor: pointer;
    }
    .vr-mobile-filters:hover { border-color: var(--accent); background: var(--primary-light); }
    .vr-mobile-filters:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .vr-mobile-filters[aria-expanded="true"] { border-color: var(--accent); background: var(--primary-light); }

    /* The controls become a panel that only shows when the button is pressed. */
    .vr-controls {
        display: none;
        position: absolute;
        left: 14px;
        right: 14px;
        z-index: 20;
        flex-direction: column;
        align-items: stretch;
        gap: 16px;
        padding: 16px;
        background: var(--bg-surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        box-shadow: var(--shadow-md);
    }
    .vr-controls.is-open { display: flex; }
    .vr-controls .vr-select,
    .vr-controls .vr-date-range input[type="date"] { width: 100%; min-width: 0; }
    .vr-controls .vr-clear { margin-left: 0; align-self: flex-start; }
}
.vr-toolbar { position: relative; }
.vr-sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* =========================================================
   Resident's Guest Requests (admin.php?page=resident_guest_forms)
   Same tokens as the Visitor Requests block above on purpose: the two admin
   request lists should read as one system. Prefixed gq- so nothing here can
   reach the other request pages.
   ========================================================= */

.gq-flash {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 0 0 16px;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-left: 3px solid var(--warning);
    border-radius: var(--radius);
    background: var(--warning-bg);
    color: var(--text-main);
    font-size: 0.82rem;
}
.gq-flash button {
    margin-left: auto;
    background: none;
    border: 0;
    padding: 0 2px;
    color: var(--text-secondary);
    font-size: 1rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius);
}
.gq-flash button:hover { color: var(--text-main); }
.gq-flash button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* Filter boxes */
.gq-filters {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 16px;
    margin: 0 0 10px;
}
.gq-filter {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    min-width: 0;
    padding: 12px 16px;
    min-height: 68px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    cursor: pointer;
    text-align: left;
    font-family: 'Poppins', sans-serif;
    transition: var(--transition);
}
.gq-filter:hover { border-color: var(--accent); background: var(--primary-light); }
.gq-filter:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-filter[aria-pressed="true"] {
    border: 2px solid var(--accent);
    padding: 11px 15px;
    background: rgba(212, 175, 55, 0.12);
}
.gq-filter-count {
    font-size: 1.55rem;
    font-weight: 600;
    line-height: 1.15;
    color: var(--text-main);
}
.gq-filter[aria-pressed="true"] .gq-filter-count { color: var(--primary-dark); }
.gq-filter-label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.78rem;
    font-weight: 500;
    line-height: 1.3;
    color: var(--text-secondary);
}
.gq-filter[aria-pressed="true"] .gq-filter-label { color: var(--text-main); }
/* Only the Pending box carries a dot, and only while it has work. */
.gq-filter-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex-shrink: 0;
    background: var(--warning);
}

/* Sort and date controls; search lives in the page header. */
.gq-toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin: 0 0 8px;
}
.gq-field { display: flex; flex-direction: column; gap: 4px; }
.gq-field > label {
    font-size: 0.68rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--text-muted);
}
.gq-select {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
    min-width: 150px;
}
.gq-select:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.gq-select:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-date-range { display: flex; align-items: center; gap: 6px; }
.gq-date-control { display: flex; align-items: flex-end; gap: 8px; }
.gq-date-range[hidden] { display: none; }
.gq-date-range input[type="date"] {
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 9px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    line-height: 1.4;
}
.gq-date-range input[type="date"]:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px rgba(212, 175, 55, 0.18); }
.gq-date-range input[type="date"]:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-clear {
    margin-left: auto;
    background: none;
    border: none;
    padding: 6px 2px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    color: var(--text-secondary);
    text-decoration: underline;
    text-underline-offset: 3px;
    cursor: pointer;
    border-radius: var(--radius);
    transition: var(--transition);
}
.gq-clear:hover { color: var(--danger); }
.gq-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-clear[hidden] { display: none; }
/* One control block wraps only when the available width requires it. */
.gq-controls {
    display: flex;
    align-items: flex-end;
    gap: 10px;
    flex-wrap: wrap;
    flex: 1 1 100%;
    min-width: 0;
}

/* Result line */
.gq-result-line {
    margin: 0 0 12px;
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Table */
.gq-table-wrap {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
table.table-gq { width: 100%; min-width: 1380px; table-layout: fixed; }
table.table-gq th {
    text-transform: none;
    letter-spacing: 0;
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: var(--border-light);
    padding: 10px 14px;
    white-space: nowrap;
}
table.table-gq th.gq-sortable { cursor: pointer; user-select: none; }
table.table-gq th.gq-sortable:hover { color: var(--text-main); }
table.table-gq th.gq-sortable:focus-visible { outline: 2px solid var(--primary); outline-offset: -2px; }
.gq-sort-arrow {
    display: inline-block;
    margin-left: 5px;
    font-size: 0.62rem;
    color: var(--text-muted);
}
th.gq-sort-active .gq-sort-arrow { color: var(--primary); }
th.gq-sort-active { color: var(--text-main); }

table.table-gq td {
    padding: 10px 14px;
    vertical-align: middle;
    border-bottom: 1px solid var(--border-light);
}
table.table-gq tbody tr:last-child td { border-bottom: 1px solid var(--border-light); }
table.table-gq tbody tr:hover { background: var(--primary-light); }
table.table-gq tbody tr.gq-empty:hover { background: transparent; }

.gq-name {
    display: block;
    font-weight: 600;
    color: var(--text-main);
    display: -webkit-box;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
    overflow: hidden;
    overflow-wrap: anywhere;
}
.gq-meta {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.gq-request-contact {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.gq-visit {
    display: block;
    font-weight: 600;
    color: var(--text-main);
    white-space: nowrap;
}
.gq-requested {
    display: block;
    color: var(--text-main);
    white-space: nowrap;
}
.gq-ago {
    display: block;
    margin-top: 2px;
    font-size: 0.72rem;
    color: var(--text-muted);
    white-space: nowrap;
}

/* Status pills */
.gq-pill {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.72rem;
    font-weight: 600;
    line-height: 1.3;
    letter-spacing: 0;
    text-transform: none;
    white-space: nowrap;
}
.gq-pill-pending  { background: var(--warning-bg); color: #92400e; }
.gq-pill-approved { background: var(--success-bg); color: #166534; }
.gq-pill-denied   { background: var(--danger-bg);  color: #991b1b; }

/* Actions: one horizontal row, equal buttons, no shadows */
table.table-gq td.actions {
    flex-direction: row;
    align-items: center;
    justify-content: flex-start;
    flex-wrap: nowrap;
    gap: 8px;
    min-width: 264px;
}
table.table-gq td.actions .btn,
table.table-gq td.actions a.btn {
    width: 112px;
    min-width: 112px;
    flex-shrink: 0;
    min-height: 32px;
    padding: 0 10px;
    border-radius: var(--radius);
    font-size: 0.78rem;
    box-shadow: none;
    white-space: nowrap;
    justify-content: center;
}
table.table-gq td.actions .btn:hover,
table.table-gq td.actions a.btn:hover {
    box-shadow: none;
    transform: none;
}
table.table-gq td.actions .gq-busy { opacity: 0.55; pointer-events: none; }

table.table-gq tbody tr.gq-empty td {
    text-align: center;
    padding: 32px 14px;
    border-bottom: none;
    color: var(--text-secondary);
    font-size: 0.85rem;
}
table.table-gq tbody tr.gq-empty .gq-empty-clear {
    display: inline-flex;
    margin-top: 12px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    font-weight: 500;
    padding: 8px 16px;
    min-height: 34px;
    cursor: pointer;
    box-shadow: none;
}
table.table-gq tbody tr.gq-empty .gq-empty-clear:hover { border-color: var(--accent); background: var(--primary-light); }
table.table-gq tbody tr.gq-empty .gq-empty-clear:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* Keep the three request tables readable at fixed widths, scrolling inside
   their existing wrappers instead of compressing columns. */
table.table-rr th:nth-child(1), table.table-rr td:nth-child(1) { width: 220px; }
table.table-rr th:nth-child(2), table.table-rr td:nth-child(2) { width: 175px; }
table.table-rr th:nth-child(3), table.table-rr td:nth-child(3) { width: 165px; }
table.table-rr th:nth-child(4), table.table-rr td:nth-child(4) { width: 170px; }
table.table-rr th:nth-child(5), table.table-rr td:nth-child(5) { width: 175px; }
table.table-rr th:nth-child(6), table.table-rr td:nth-child(6) { width: 155px; }
table.table-rr th:nth-child(7), table.table-rr td:nth-child(7) { width: 280px; }
table.table-vr th:nth-child(1), table.table-vr td:nth-child(1) { width: 220px; }
table.table-vr th:nth-child(2), table.table-vr td:nth-child(2) { width: 125px; }
table.table-vr th:nth-child(3), table.table-vr td:nth-child(3) { width: 180px; }
table.table-vr th:nth-child(4), table.table-vr td:nth-child(4) { width: 165px; }
table.table-vr th:nth-child(5), table.table-vr td:nth-child(5) { width: 145px; }
table.table-vr th:nth-child(6), table.table-vr td:nth-child(6) { width: 155px; }
table.table-vr th:nth-child(7), table.table-vr td:nth-child(7) { width: 280px; }
table.table-gq th:nth-child(1), table.table-gq td:nth-child(1) { width: 220px; }
table.table-gq th:nth-child(2), table.table-gq td:nth-child(2) { width: 230px; }
table.table-gq th:nth-child(3), table.table-gq td:nth-child(3) { width: 100px; }
table.table-gq th:nth-child(4), table.table-gq td:nth-child(4) { width: 160px; }
table.table-gq th:nth-child(5), table.table-gq td:nth-child(5) { width: 160px; }
table.table-gq th:nth-child(6), table.table-gq td:nth-child(6) { width: 130px; }
table.table-gq th:nth-child(7), table.table-gq td:nth-child(7) { width: 380px; }

table.table-rr tbody tr:not(.rr-empty),
table.table-vr tbody tr:not(.vr-empty),
table.table-gq tbody tr:not(.gq-empty) { height: 84px; }

table.table-rr th, table.table-rr td,
table.table-vr th, table.table-vr td,
table.table-gq th, table.table-gq td {
    vertical-align: middle;
    overflow-wrap: normal;
    word-break: normal;
}
table.table-rr .rr-pill,
table.table-vr .vr-pill,
table.table-gq .gq-pill {
    flex-shrink: 0;
    white-space: nowrap;
    line-height: 1.3;
}
table.table-rr td.actions,
table.table-vr td.actions,
table.table-gq td.actions {
    flex-wrap: nowrap;
    white-space: nowrap;
}
table.table-rr td.actions form,
table.table-vr td.actions form,
table.table-gq td.actions form {
    flex: 0 0 auto;
}
table.table-rr td.actions .btn,
table.table-rr td.actions a.btn,
table.table-vr td.actions .btn,
table.table-vr td.actions a.btn,
table.table-gq td.actions .btn,
table.table-gq td.actions a.btn {
    flex-shrink: 0;
    height: 34px;
    min-height: 34px;
    white-space: nowrap;
}
table.table-gq td.actions { min-width: 368px; }
table.table-gq td.actions .btn,
table.table-gq td.actions a.btn { height: 34px; min-height: 34px; }

@media (max-width: 760px) {
    table.table-rr tbody tr:not(.rr-empty) td:first-child,
    table.table-vr tbody tr:not(.vr-empty) td:first-child,
    table.table-gq tbody tr:not(.gq-empty) td:first-child {
        position: sticky;
        left: 0;
        z-index: 1;
        background: var(--bg-surface);
    }
    table.table-rr tbody tr:not(.rr-empty):hover td:first-child,
    table.table-vr tbody tr:not(.vr-empty):hover td:first-child,
    table.table-gq tbody tr:not(.gq-empty):hover td:first-child {
        background: var(--primary-light);
    }
}

/* Pagination */
.gq-pager {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin: 16px 0 0;
}
.gq-pager-info {
    font-size: 0.78rem;
    color: var(--text-secondary);
    margin-right: auto;
}
.gq-page-btn {
    min-width: 34px;
    min-height: 34px;
    padding: 0 10px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.8rem;
    cursor: pointer;
    box-shadow: none;
    transition: var(--transition);
}
.gq-page-btn:hover:not(:disabled) { border-color: var(--accent); background: var(--primary-light); }
.gq-page-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-page-btn[aria-current="true"] {
    border: 2px solid var(--accent);
    background: rgba(212, 175, 55, 0.12);
    color: var(--text-main);
    font-weight: 600;
}
.gq-page-btn:disabled { opacity: 0.45; cursor: not-allowed; }

/* ---- View Details dialog ---- */
#gqModal .modal-content {
    box-sizing: border-box;
    width: min(97vw, 760px);
    max-width: 760px;
    height: min(90vh, 820px);
    max-height: 90vh;
    min-height: 0;
    padding: 0;
    gap: 0;
    border-radius: var(--radius);
    overflow: hidden;
}
.gq-dlg-head {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 0 0 auto;
    min-width: 0;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-light);
}
.gq-dlg-head > div:first-child { min-width: 0; }
.gq-dlg-head h3 {
    margin: 0;
    padding: 0;
    border: 0;
    background: none;
    position: static;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    overflow-wrap: anywhere;
}
.gq-dlg-sub {
    display: block;
    margin-top: 2px;
    font-size: 0.8rem;
    font-weight: 400;
    color: var(--text-secondary);
}
.gq-dlg-head .gq-dlg-pill { margin-left: auto; align-self: center; }
/* The shared .close is absolutely placed for the older dialogs; this header is a
   flex row, so the button joins the flow instead. */
.gq-dlg-head .close { position: static; margin: 0; flex-shrink: 0; align-self: center; }
.gq-dlg-body {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    align-items: start;
    gap: 20px;
    padding: 18px 20px;
    overflow-y: auto;
    overflow-x: hidden;
    flex: 1 1 auto;
    min-height: 0;
    overscroll-behavior: contain;
}
.gq-dlg-body > div { min-width: 0; }
.gq-dlg-section + .gq-dlg-section { margin-top: 18px; }
#gqModal .modal-content > .gq-dlg-head,
#gqModal .modal-content > .gq-dlg-foot {
    flex: 0 0 auto;
    overflow: visible;
    padding-right: 20px;
}
#gqModal .modal-content > .gq-dlg-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    padding-right: 20px;
}
#gqModal .modal-content > .gq-dlg-body > div {
    flex: 0 0 auto;
    min-height: 0;
    overflow: visible;
    padding-right: 0;
}
.gq-dlg-title {
    font-size: 0.72rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    color: var(--text-muted);
    margin: 0 0 8px;
}
.gq-info { display: flex; flex-direction: column; gap: 8px; }
.gq-info-row {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    font-size: 0.82rem;
}
.gq-info-row > span:first-child { color: var(--text-secondary); flex-shrink: 0; }
.gq-info-row > span:last-child {
    color: var(--text-main);
    font-weight: 500;
    text-align: right;
    overflow-wrap: anywhere;
    word-break: normal;
    hyphens: none;
}
.gq-id-box {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    background: var(--bg-body);
}
.gq-id-frame {
    position: relative;
    overflow: hidden;
    border-radius: var(--radius);
    min-height: min(180px, 25vh);
    max-height: 45vh;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: zoom-in;
}
.gq-id-frame.is-zoomed { cursor: zoom-out; }
.gq-id-frame img {
    display: block;
    width: auto;
    max-width: 100%;
    max-height: 45vh;
    height: auto;
    object-fit: contain;
    transition: transform 0.2s ease-in-out;
    transform-origin: center center;
}
.gq-id-actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 8px;
    font-size: 0.75rem;
    color: var(--text-muted);
}
.gq-id-actions[hidden] { display: none; }
.gq-id-actions .btn { box-shadow: none; flex: 0 0 auto; }
.gq-id-none {
    padding: 26px 10px;
    font-size: 0.82rem;
    color: var(--text-muted);
    text-align: center;
}
.gq-id-error {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 20px 12px;
    color: var(--text-muted);
    font-size: 0.82rem;
    text-align: center;
}
.gq-dlg-foot {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 0 0 auto;
    padding: 12px 20px;
    border-top: 1px solid var(--border-light);
    background: var(--bg-surface);
    flex-wrap: wrap;
}
.gq-dlg-foot .gq-foot-hint {
    margin-right: auto;
    font-size: 0.78rem;
    color: var(--text-muted);
    max-width: 60ch;
}
.gq-dlg-foot .gq-foot-reason {
    margin-right: auto;
    font-size: 0.82rem;
    color: var(--text-main);
    max-width: 60ch;
    overflow-wrap: anywhere;
}
.gq-dlg-foot .btn {
    box-shadow: none;
    height: 38px;
    min-height: 38px;
    padding: 0 14px;
    border-radius: var(--radius);
    font-size: 0.82rem;
}
.gq-dlg-foot .btn:hover { transform: none; }
.gq-dlg-foot .btn[disabled] { opacity: 0.55; cursor: not-allowed; }
.gq-id-lightbox[hidden] { display: none; }
.gq-id-lightbox {
    position: fixed;
    inset: 0;
    z-index: 5;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: var(--bg-body);
}
.gq-id-lightbox img {
    display: block;
    max-width: 100%;
    max-height: 100%;
    width: auto;
    height: auto;
    object-fit: contain;
}
.gq-id-lightbox-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    font-size: 1.4rem;
    cursor: pointer;
}
.gq-id-lightbox-close:focus-visible,
.gq-id-error .btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* ---- Deny dialog ---- */
#gqDenyModal .modal-content {
    width: min(94vw, 480px);
    max-width: 480px;
    padding: 16px;
    gap: 10px;
    border-radius: var(--radius);
}
#gqDenyModal h3 { padding: 0 36px 8px 0; font-size: 1.05rem; }
.gq-deny-msg { font-size: 0.85rem; color: var(--text-secondary); margin: 0; }
.gq-deny-field { display: flex; flex-direction: column; gap: 4px; }
.gq-deny-field > label { font-size: 0.68rem; font-weight: 600; color: var(--text-muted); }
.gq-deny-field select {
    font-family: 'Poppins', sans-serif;
    font-size: 0.85rem;
    padding: 9px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
}
.gq-deny-field select:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-deny-note {
    width: 100%;
    min-height: 62px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-main);
    resize: vertical;
}
.gq-deny-note:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.gq-deny-error { color: var(--danger); font-size: 0.8rem; }
.gq-deny-error[hidden] { display: none; }
.gq-deny-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }
.gq-deny-actions .btn {
    box-shadow: none;
    min-height: 34px;
    padding: 0 16px;
    border-radius: var(--radius);
    font-size: 0.82rem;
}
.gq-deny-actions .btn:hover { transform: none; }

/* ---- Toast ---- */
.gq-toasts {
    position: fixed;
    right: 16px;
    bottom: 16px;
    z-index: 4000;
    display: flex;
    flex-direction: column;
    gap: 8px;
    pointer-events: none;
}
.gq-toasts[hidden] { display: none; }
.gq-toast {
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: 340px;
    padding: 10px 14px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-left: 3px solid var(--success);
    border-radius: var(--radius);
    box-shadow: var(--shadow-md);
    color: var(--text-main);
    font-family: 'Poppins', sans-serif;
    font-size: 0.82rem;
    animation: gqToastIn 0.22s ease-out;
}
.gq-toast.is-error { border-left-color: var(--danger); }
.gq-toast button {
    margin-left: auto;
    background: none;
    border: 0;
    padding: 0 2px;
    color: var(--text-muted);
    font-size: 0.95rem;
    line-height: 1;
    cursor: pointer;
    border-radius: var(--radius);
}
.gq-toast button:hover { color: var(--text-main); }
.gq-toast button:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
@keyframes gqToastIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: none; }
}
@media (prefers-reduced-motion: reduce) {
    .gq-toast { animation: none; }
    .gq-id-frame img { transition: none; }
}

/* Two columns once the boxes would get too narrow to read. */
@media (max-width: 900px) {
    .gq-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .gq-dlg-head > div:first-child { flex: 1 1 0; }
    .gq-dlg-head .gq-dlg-pill { flex-shrink: 0; }
    .gq-dlg-head .gq-dlg-pill { margin-left: 0; }
}
@media (max-width: 760px) {
    .gq-controls { gap: 8px; }
    .gq-controls > .gq-field { flex: 1 1 155px; }
    .gq-controls > .gq-date-field { flex: 1 1 310px; }
    .gq-controls .gq-select { width: 100%; min-width: 0; }
    .gq-date-control { flex-wrap: wrap; }

    #gqModal .modal-content {
        box-sizing: border-box;
        width: 100vw;
        max-width: 100vw;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
    }
    #gqModal .modal-content > .gq-dlg-head { padding: 14px 16px; }
    #gqModal .modal-content > .gq-dlg-body {
        grid-template-columns: minmax(0, 1fr);
        gap: 16px;
        padding: 16px;
    }
    #gqModal .modal-content > .gq-dlg-foot { padding: 12px 16px; }
    .gq-dlg-foot .gq-foot-hint,
    .gq-dlg-foot .gq-foot-reason { max-width: none; flex: 1 1 100%; }
    .gq-dlg-foot .btn { flex: 1 1 0; }
    .gq-id-frame,
    .gq-id-frame img { max-height: 40vh; }
}

/* ========================= RESERVATION DETAILS DIALOG ========================= */
#reservationModal .modal-content.rrd-dialog {
    width: min(97vw, 1180px);
    max-width: 1180px;
    height: min(93vh, 860px);
    max-height: min(93vh, 860px);
    padding: 0;
    gap: 0;
    border-radius: var(--radius);
    overflow: hidden;
}

/* --- Fixed header --- */
/* The page's own `.modal-content > div { flex: 1 }` would otherwise give the
   header, steps and footer a share of the height each, squeezing the scroll
   area down to a quarter of the dialog. Pin them to their content instead. */
#reservationModal .rrd-head {
    flex: 0 0 auto;
    overflow: visible;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    background: var(--bg-surface);
}
#reservationModal .rrd-head-main { flex: 1; min-width: 0; }
#reservationModal .rrd-name {
    margin: 0;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--text-main);
    line-height: 1.35;
}
#reservationModal .rrd-sub {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 8px 0 0;
    font-size: 0.86rem;
    color: var(--text-secondary);
}
#reservationModal .rrd-sub b { font-weight: 600; color: var(--text-main); }
#reservationModal .rrd-sub span + span::before { content: '\00b7'; margin-right: 8px; color: var(--text-muted); }
#reservationModal .rrd-head .rrd-pill { flex-shrink: 0; margin-top: 4px; }
/* Same 32px grey circle the main and profile pages use on their modals. Kept in
   the header's flex flow so it reserves its own space and never collides with
   the status pill; the global .close is position:absolute, which would need
   padding tweaks in every breakpoint here. */
#reservationModal .rrd-head .close {
    flex-shrink: 0;
    position: static;
    margin: 0;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 0;
    background: #e5e7eb;
    color: #111827;
    font-size: 18px;
    font-weight: 700;
    line-height: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
}
#reservationModal .rrd-head .close:hover { filter: brightness(0.92); }

/* --- Status pill --- */
#reservationModal .rrd-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 0.8rem;
    font-weight: 600;
    white-space: nowrap;
}
#reservationModal .rrd-pill::before {
    content: '';
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: currentColor;
    flex-shrink: 0;
}
#reservationModal .rrd-pill.k-to_verify { background: #fef3c7; color: #92400e; }
#reservationModal .rrd-pill.k-ready     { background: var(--primary); color: #fff; }
#reservationModal .rrd-pill.k-approved  { background: #dcfce7; color: #166534; }
#reservationModal .rrd-pill.k-rejected  { background: #fee2e2; color: #991b1b; }

/* --- Two-step progress row --- */
#reservationModal .rrd-steps {
    flex: 0 0 auto;
    overflow: visible;
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 14px 24px;
    border-bottom: 1px solid var(--border-light);
    background: var(--bg-body);
}
#reservationModal .rrd-step {
    display: flex;
    align-items: center;
    gap: 9px;
    font-size: 0.86rem;
    color: var(--text-muted);
    white-space: nowrap;
}
#reservationModal .rrd-step-dot {
    width: 24px;
    height: 24px;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    border: 1px solid var(--border);
    background: var(--bg-surface);
    color: var(--text-muted);
    font-size: 0.7rem;
    font-weight: 700;
}
#reservationModal .rrd-step-line { flex: 1; height: 1px; min-width: 20px; background: var(--border); }
#reservationModal .rrd-step.is-done { color: var(--text-main); font-weight: 600; }
#reservationModal .rrd-step.is-done .rrd-step-dot { background: var(--success); border-color: var(--success); color: #fff; }
#reservationModal .rrd-step.is-current { color: var(--text-main); font-weight: 600; }
#reservationModal .rrd-step.is-current .rrd-step-dot { background: var(--primary); border-color: var(--primary); color: #fff; }
#reservationModal .rrd-step.is-done + .rrd-step-line { background: var(--success); }

/* --- Two-column body --- */
/* The receipt is a portrait image and does not need half the dialog, while the
   right column carries a four-column comparison table. Split them unevenly. */
#reservationModal #reservationDetailsContent.rrd-scroll {
    flex: 1 1 auto;
    min-height: 0;
    display: grid;
    grid-template-columns: minmax(0, 420px) minmax(0, 1fr);
    gap: 20px;
    padding: 20px 24px;
    overflow: hidden;
    overflow-y: hidden;
    padding-right: 24px;
}
#reservationModal .rrd-left { min-width: 0; min-height: 0; display: flex; flex-direction: column; gap: 10px; }
#reservationModal .rrd-right {
    min-width: 0;
    min-height: 0;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 20px;
    padding-right: 6px;
}

/* --- Receipt column --- */
#reservationModal .rrd-receipt {
    position: relative;
    flex: 1;
    min-height: 240px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-body);
    overflow: hidden;
}
#reservationModal .rrd-receipt img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; cursor: zoom-in; }
#reservationModal .rrd-receipt-state {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 24px;
    text-align: center;
    font-size: 0.88rem;
    color: var(--text-secondary);
    background: var(--bg-body);
}
#reservationModal .rrd-receipt-state i { font-size: 1.7rem; color: var(--text-muted); }
#reservationModal .rrd-receipt-state.is-error { color: var(--danger); }
#reservationModal .rrd-receipt-state.is-error i { color: var(--danger); }
#reservationModal .rrd-receipt-tools { flex-shrink: 0; display: flex; gap: 10px; }
#reservationModal .rrd-receipt-tools .btn { border-radius: var(--radius); min-height: 40px; padding: 0 18px; }
#reservationModal .rrd-receipt-caption { flex-shrink: 0; margin: 0; font-size: 0.8rem; color: var(--text-muted); }

/* --- Cards --- */
#reservationModal .rrd-card { border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg-surface); }
#reservationModal .rrd-card-title {
    margin: 0;
    padding: 12px 16px;
    border-bottom: 1px solid var(--border-light);
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--text-secondary);
    text-transform: none;
    letter-spacing: 0;
}
#reservationModal .rrd-card-body { padding: 14px 16px; }

/* --- Key/value rows --- */
#reservationModal .rrd-kv { display: flex; align-items: baseline; justify-content: space-between; gap: 16px; padding: 8px 0; }
#reservationModal .rrd-kv + .rrd-kv { border-top: 1px solid var(--border-light); }
#reservationModal .rrd-k { color: var(--text-secondary); font-size: 0.85rem; font-weight: 500; }
#reservationModal .rrd-v { color: var(--text-main); font-size: 0.92rem; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
#reservationModal .rrd-kv.is-key { margin: 6px -16px; padding: 11px 16px; background: var(--primary-light); }
#reservationModal .rrd-kv.is-key .rrd-k { color: var(--text-main); font-weight: 600; }
#reservationModal .rrd-kv.is-key .rrd-v { color: var(--primary); font-size: 1.02rem; font-weight: 700; }
#reservationModal .rrd-kv.is-balance .rrd-k,
#reservationModal .rrd-kv.is-balance .rrd-v { color: var(--text-muted); font-weight: 500; }
#reservationModal .rrd-balance-note {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 0 16px;
    color: var(--text-muted);
    font-size: 0.78rem;
    line-height: 1.3;
    white-space: nowrap;
}
#reservationModal .rrd-balance-info {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--text-muted);
    cursor: help;
}
#reservationModal .rrd-balance-info:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
}
#reservationModal .rrd-balance-tooltip {
    position: absolute;
    right: 0;
    bottom: calc(100% + 6px);
    z-index: 2;
    width: max-content;
    max-width: min(260px, calc(100vw - 40px));
    padding: 8px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-surface);
    color: var(--text-secondary);
    font-size: 0.76rem;
    font-weight: 400;
    line-height: 1.4;
    text-align: left;
    white-space: normal;
    visibility: hidden;
    opacity: 0;
}
#reservationModal .rrd-balance-info:hover .rrd-balance-tooltip,
#reservationModal .rrd-balance-info:focus .rrd-balance-tooltip {
    visibility: visible;
    opacity: 1;
}

/* --- Receipt check table --- */
/* The page's global `table { min-width: 760px }` (and 480px on small screens)
   forces admin data tables to scroll sideways. This one has to fit its card, so
   min-width is cleared and the columns are fixed. `max-width` is ignored on
   table boxes, which is why the minimum is what actually pins the width. */
#reservationModal .rrd-check { width: 100%; max-width: 100%; min-width: 0; border-collapse: collapse; table-layout: fixed; }
#reservationModal .rrd-check th:nth-child(1), #reservationModal .rrd-check td:nth-child(1) { width: 21%; }
#reservationModal .rrd-check th:nth-child(2), #reservationModal .rrd-check td:nth-child(2) { width: 25%; }
#reservationModal .rrd-check th:nth-child(3), #reservationModal .rrd-check td:nth-child(3) { width: 34%; }
#reservationModal .rrd-check th:nth-child(4), #reservationModal .rrd-check td:nth-child(4) { width: 20%; }
#reservationModal .rrd-check th {
    position: static;
    padding: 8px 10px;
    text-align: left;
    font-size: 0.76rem;
    font-weight: 600;
    color: var(--text-secondary);
    background: var(--border-light);
    border-bottom: 1px solid var(--border);
}
#reservationModal .rrd-check td { padding: 10px; border-bottom: 1px solid var(--border-light); vertical-align: middle; }
#reservationModal .rrd-check tr:last-child td { border-bottom: none; }
#reservationModal .rrd-check .rrd-in {
    width: 100%;
    min-width: 0;
    min-height: 38px;
    padding: 7px 10px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.86rem;
    color: var(--text-main);
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 6px;
}
#reservationModal .rrd-in:focus { outline: 2px solid var(--primary); outline-offset: 0; border-color: var(--primary); }
#reservationModal .rrd-check .rrd-flag { font-size: 0.82rem; font-weight: 600; white-space: nowrap; }
#reservationModal .rrd-flag.ok { color: var(--success); }
#reservationModal .rrd-flag.bad { color: var(--danger); }
#reservationModal .rrd-flag.wait { color: var(--text-muted); font-weight: 500; }

/* --- Advisory warnings --- */
#reservationModal .rrd-warn {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 14px;
    border-left: 3px solid var(--warning);
    border-radius: 6px;
    background: var(--warning-bg);
    color: var(--text-main);
    font-size: 0.84rem;
    font-weight: 600;
}
#reservationModal .rrd-warn[hidden] { display: none; }
#reservationModal .rrd-note {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: 11px 14px;
    border-left: 3px solid var(--info);
    border-radius: 6px;
    background: var(--info-bg);
    color: var(--text-main);
    font-size: 0.84rem;
}

/* --- Sticky footer --- */
#reservationModal .rrd-foot {
    flex: 0 0 auto;
    overflow: visible;
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    background: var(--bg-surface);
}
#reservationModal .rrd-foot-row { display: flex; align-items: center; gap: 16px; }
#reservationModal .rrd-foot-hint { flex: 1; min-width: 0; font-size: 0.86rem; color: var(--text-secondary); }
#reservationModal .rrd-foot-hint.is-ok { color: var(--success); font-weight: 600; }
#reservationModal .rrd-foot-hint.is-bad { color: var(--danger); font-weight: 600; }
#reservationModal .rrd-foot-btns { display: flex; gap: 10px; flex-shrink: 0; }
#reservationModal .rrd-foot-btns .btn { min-height: 42px; padding: 0 22px; border-radius: var(--radius); white-space: nowrap; }
#reservationModal .rrd-btn-danger-ghost { background: var(--bg-surface); color: var(--danger); border: 1px solid var(--danger); }
#reservationModal .rrd-btn-danger-ghost:hover:not(:disabled) { background: var(--danger-bg); }

/* --- Inline rejection confirmation --- */
#reservationModal .rrd-reject {
    display: flex;
    flex-direction: column;
    gap: 10px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg-body);
}
#reservationModal .rrd-reject[hidden] { display: none; }
#reservationModal .rrd-reject-label { font-size: 0.86rem; font-weight: 600; color: var(--text-main); }
#reservationModal .rrd-reject select {
    width: 100%;
    min-height: 42px;
    padding: 9px 12px;
    font-family: 'Poppins', sans-serif;
    font-size: 0.88rem;
    color: var(--text-main);
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 6px;
}
#reservationModal .rrd-reject select:focus { outline: 2px solid var(--primary); outline-offset: 0; border-color: var(--primary); }
#reservationModal .rrd-reject-btns { display: flex; gap: 10px; }
#reservationModal .rrd-reject-btns .btn { min-height: 40px; padding: 0 18px; border-radius: var(--radius); }

/* --- Receipt zoom overlay --- */
#reservationModal .rrd-zoom {
    position: fixed;
    inset: 0;
    z-index: 2500;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 28px;
    background: rgba(0,0,0,0.9);
}
#reservationModal .rrd-zoom[hidden] { display: none; }
#reservationModal .rrd-zoom img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; border-radius: var(--radius); }
#reservationModal .rrd-zoom-close {
    position: absolute;
    top: 16px;
    right: 16px;
    width: 36px;
    height: 36px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    line-height: 1;
    color: #fff;
    background: rgba(255,255,255,0.14);
    border: 1px solid rgba(255,255,255,0.3);
    border-radius: 8px;
    cursor: pointer;
}

/* --- Busy / disabled states --- */
#reservationModal.rrd-busy .rrd-foot-btns .btn,
#reservationModal.rrd-busy .rrd-reject-btns .btn,
#reservationModal.rrd-busy .rrd-receipt-tools .btn { opacity: 0.6; pointer-events: none; }

/* Many of the parts below set display, which would defeat the [hidden] attribute. */
#reservationModal [hidden] { display: none !important; }

/* Toast tones reuse the existing .toast shell, only the edge colour changes. */
.toast.rrd-toast-ok { border-left-color: var(--success); }
.toast.rrd-toast-error { border-left-color: var(--danger); }

/* --- Mobile: full screen --- */
@media (max-width: 980px) {
    /* Stretch instead of centre so the dialog is exactly the viewport, with no
       rounding gap along any edge. */
    #reservationModal { align-items: stretch; justify-content: stretch; }
    #reservationModal .modal-content.rrd-dialog {
        width: 100vw;
        max-width: 100vw;
        height: 100vh;
        max-height: 100vh;
        border-radius: 0;
        border: none;
        /* A full-bleed dialog has nothing to slide in from, and the offset would
           briefly expose the backdrop along the edges. */
        animation: none;
    }
    #reservationModal .rrd-head { padding: 18px 20px; }
    #reservationModal .rrd-steps { padding: 12px 20px; }
    #reservationModal #reservationDetailsContent.rrd-scroll {
        grid-template-columns: minmax(0, 1fr);
        /* Rows follow their content and the whole pane scrolls. Stretched rows
           starved the details down to a sliver on phones. */
        grid-template-rows: max-content max-content;
        align-content: start;
        gap: 18px;
        padding: 18px 20px;
        overflow-y: auto;
        overflow-x: hidden;
    }
    #reservationModal .rrd-left { min-height: 240px; max-height: 42vh; }
    #reservationModal .rrd-right { overflow: visible; padding-right: 0; }
    #reservationModal .rrd-check th:nth-child(1), #reservationModal .rrd-check td:nth-child(1) { width: 18%; }
    #reservationModal .rrd-check th:nth-child(2), #reservationModal .rrd-check td:nth-child(2) { width: 24%; }
    #reservationModal .rrd-check th:nth-child(3), #reservationModal .rrd-check td:nth-child(3) { width: 38%; }
    #reservationModal .rrd-foot { padding: 14px 20px; }
}
@media (max-width: 560px) {
    #reservationModal .rrd-head { padding: 16px; gap: 12px; }
    #reservationModal .rrd-name { font-size: 1.08rem; }
    #reservationModal .rrd-sub { font-size: 0.8rem; gap: 6px; }
    #reservationModal .rrd-steps { padding: 12px 16px; gap: 8px; }
    #reservationModal .rrd-step { font-size: 0.8rem; gap: 7px; }
    #reservationModal #reservationDetailsContent.rrd-scroll { padding: 16px; gap: 16px; }
    #reservationModal .rrd-foot { padding: 14px 16px; }
    #reservationModal .rrd-foot-row { flex-wrap: wrap; }
    #reservationModal .rrd-foot-hint { flex: 1 0 100%; }
    #reservationModal .rrd-foot-btns { width: 100%; }
    #reservationModal .rrd-foot-btns .btn { flex: 1; min-height: 44px; }
    #reservationModal .rrd-zoom { padding: 12px; }
}

/* Notifications */
.notif-btn {
    background: rgba(255,255,255,0.1);
    border: none;
    cursor: pointer;
    position: relative;
    color: rgba(255,255,255,0.9);
    transition: var(--transition);
    padding: 8px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.08);
    flex-shrink: 0;
}
.notif-btn:hover { background: rgba(255,255,255,0.2); color: #fff; }
.notif-btn img {
    width: 20px;
    height: 20px;
    display: block;
}
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
    margin-top: 12px;
    width: 320px;
    max-height: 420px;
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
    top: 10px;
    right: 12px;
    z-index: 100;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 0;
    background: #e5e7eb;
    color: #111827;
    font-size: 18px;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    line-height: 1;
}
.close:hover { filter: brightness(0.92); }

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
    
    width: min(90vw, 550px);
    max-height: 90vh;
    overflow: hidden;
}

.modal-content h3 {
    padding: 8px 44px 12px 4px;
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
.btn-approve, .btn-success { background: var(--success); color: #fff; }
.btn-approve:hover { background: #059669; }

.btn-reject, .btn-danger { background: var(--danger); color: #fff; }
.btn-reject:hover { background: #dc2626; }
.receipt-action-row {
  display: flex;
  justify-content: center;
  align-items: stretch;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}
.receipt-action-row form { flex: 1 1 190px; max-width: 250px; margin: 0; }
.receipt-action-row .btn { width: 100%; min-height: 40px; gap: 8px; font-weight: 600; line-height: 1.2; }
.receipt-action-row .btn i { flex: 0 0 auto; }
@media (max-width: 480px) {
  .receipt-action-row form { flex-basis: 100%; max-width: none; }
}

.btn-delete { background: var(--bg-body); color: var(--danger); border: 1px solid var(--border); }
.btn-delete:hover { background: #fee2e2; border-color: var(--danger); }

#visitorModal .modal-content,
#residentReservationModal .modal-content,
#reservationModal .modal-content,
#priceDetailsModal .modal-content,
#incidentDetailsModal .modal-content,
#userModal .modal-content {
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
#denyReasonTitle { margin: 0; padding: 8px 44px 10px 0; }
#denyReasonMessage { margin: 6px 0 8px; }
#denyReasonLabel { margin-top: 6px; }
#denyReasonInput { min-height: 90px; }
#denyReasonSubmit { background: var(--danger); color: #fff; }
#visitorModal .modal-content h3,
#residentReservationModal .modal-content h3,
#reservationModal .modal-content h3,
#priceDetailsModal .modal-content h3,
#incidentDetailsModal .modal-content h3,
#userModal .modal-content h3 {
    margin: 0;
    padding: 12px 44px 12px 16px;
    background: #fff;
    border-bottom: 1px solid #e6ebe6;
    color: #23412e;
    font-size: 1.05rem;
    font-weight: 700;
}
#visitorDetailsContent,
#residentReservationDetailsContent,
#reservationDetailsContent,
#priceDetailsContent,
#userDetailsContent {
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
    justify-content: center;
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
#reservationDetailsContent .eco-badge, #residentReservationDetailsContent .eco-badge {
  display: flex;
  flex: 0 0 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  max-width: 100%;
  padding: 0;
  background: transparent;
  border: 0;
  color: transparent;
  font-size: 0;
  white-space: normal;
}
#reservationDetailsContent .eco-badge::before, #residentReservationDetailsContent .eco-badge::before {
  content: "♻ EcoPoints Used";
  display: inline-flex;
  align-items: center;
  min-height: 30px;
  padding: 6px 12px;
  border-radius: 20px;
  background: #ccfbf1;
  color: #0f766e;
  border: 1px solid #5eead4;
  font-size: 0.85rem;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
}
#reservationDetailsContent .eco-confirm-btn, #residentReservationDetailsContent .eco-confirm-btn {
  --vh-eco-logo-size: 18px;
  --vh-eco-logo-radius: 3px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 30px;
  gap: 6px;
  margin: 0;
  padding: 7px 12px;
  border: 1px solid #166534;
  border-radius: 20px;
  background: #166534;
  color: #fff;
  font-size: 0.75rem;
  font-weight: 700;
  line-height: 1.2;
  text-decoration: none;
  white-space: nowrap;
}
#reservationDetailsContent .eco-confirm-btn:hover, #residentReservationDetailsContent .eco-confirm-btn:hover {
  background: #14532d;
  border-color: #14532d;
}
@media (max-width: 600px) {
  #reservationDetailsContent .eco-badge, #residentReservationDetailsContent .eco-badge {
    flex-basis: 100%;
    width: 100%;
  }
  #reservationDetailsContent .eco-confirm-btn, #residentReservationDetailsContent .eco-confirm-btn {
    white-space: normal;
    text-align: center;
  }
}
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

/* Resident / Visitor Profile modal — align with resident reservation details styling */
#userDetailsContent {
    font-family: 'Poppins', sans-serif;
    color: #333;
}
#userDetailsContent > div {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)) !important;
    gap: 16px !important;
}
#userDetailsContent > div > div {
    background: #f9f9f9;
    border: 1px solid #eee;
    border-radius: 12px;
    padding: 15px;
}
#userDetailsContent h4 {
    color: #555 !important;
    font-weight: 600 !important;
    font-size: 0.95rem !important;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0 0 8px !important;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
}
#userDetailsContent p {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 0.95rem;
    margin: 6px 0;
    line-height: 1.5;
}
#userDetailsContent p strong {
    color: #666;
    font-weight: 500;
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
@media (max-width: 1200px) {
    .smart-waste-layout {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 1024px) {
    .dashboard-grid {
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 12px;
        padding: 0 20px;
    }
    .panel, .card-box { margin: 0 20px 20px 20px; padding: 18px 20px; }
    .page-header { padding: 12px 20px 6px; }
}

@media (max-width: 900px) {
    .smart-waste-chart {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {
    .app { flex-direction: column; }
    .sidebar {
        width: 100%;
        height: auto;
        position: sticky;
        top: 0;
        border-right: none;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        max-height: 56px;
        overflow: hidden;
    }
    .sidebar.sidebar-open { max-height: none; }
    
    .sidebar-topbar { padding: 10px 14px 8px; }
    .sidebar-title { font-size: 0.95rem; }
    .sidebar-subtitle { font-size: 0.75rem; }
    
    .nav-list { 
        flex-direction: row; 
        overflow-x: auto; 
        padding: 8px 12px; 
        gap: 8px; 
        -webkit-overflow-scrolling: touch;
        scrollbar-width: none;
    }
    .nav-list::-webkit-scrollbar { display: none; }
    
    .nav-item { 
        white-space: nowrap; 
        padding: 7px 14px; 
        border-radius: 999px; 
        border: 1px solid rgba(255,255,255,0.12); 
        background: rgba(255,255,255,0.08);
        font-size: 0.82rem;
        gap: 6px;
    }
    .nav-item.active { 
        border-left: 1px solid rgba(255,255,255,0.3); 
        background: rgba(255,255,255,0.2); 
        color: #fff;
    }
    .nav-item.active img { filter: brightness(0) invert(1); }
    
    .top-header { padding: 10px 14px; height: auto; flex-wrap: wrap; gap: 10px; }
    .header-brand { order: 1; }
    .header-actions { order: 2; }
    .header-search { order: 3; width: 100%; }
    .search { width: 100%; padding: 8px 14px; }
    .page-header { padding: 10px 14px 6px; }
    .main { margin-left: 0; width: 100%; }
    
    .dashboard-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; padding: 0 14px; margin-bottom: 16px; }
    .dashboard-widget { padding: 14px; }
    .dashboard-widget-value { font-size: 1.5rem; }
    .panel, .card-box { margin: 0 14px 14px 14px; padding: 14px; }
    
    table { min-width: 480px; }
    th, td { padding: 8px 10px; font-size: 0.78rem; }
    .content-row { overflow-x: auto; -webkit-overflow-scrolling: touch; }
    .smart-waste-layout {
        grid-template-columns: 1fr;
    }
    .smart-waste-chart {
        gap: 6px;
        min-height: 120px;
    }
    .smart-waste-card {
        padding: 14px;
    }
    .smart-waste-status-grid {
        grid-template-columns: 1fr 1fr;
    }
    .smart-waste-card .table-responsive-wrapper table {
        min-width: 580px;
    }
    .system-switcher-header { gap: 6px; }
    .system-switch-header-btn { height: 34px; padding: 0 10px; font-size: 0.75rem; }
}

@media (max-width: 480px) {
    .dashboard-grid { grid-template-columns: 1fr; }
    .smart-waste-status-grid { grid-template-columns: 1fr; }
    .smart-waste-kpi-grid { grid-template-columns: 1fr; }
    .smart-waste-bin-grid { grid-template-columns: 1fr; }
    #userDetailsContent > div { grid-template-columns: 1fr !important; }
}
.smart-waste-brand-banner {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 14px 16px;
    border-radius: 12px;
    background: linear-gradient(135deg, #ecfdf5, #d1fae5);
    border: 1px solid #86efac;
    color: #14532d;
    margin-bottom: 14px;
    box-shadow: 0 4px 12px rgba(34, 197, 94, 0.08);
}

.smart-waste-brand-banner .kicker {
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.smart-waste-brand-banner .station-name {
    font-size: 1.3rem;
    font-weight: 800;
    line-height: 1.1;
}

.smart-waste-brand-banner .station-desc {
    font-size: 0.92rem;
    line-height: 1.35;
}

/* Shared search control for the three admin request lists. */
.request-search-header { gap: 16px; }
.request-search-header .header-brand {
    flex: 0 0 0;
    width: 0;
    padding: 0;
    overflow: hidden;
}
.request-search-container {
    flex: 1 1 600px;
    justify-content: flex-start;
    min-width: 280px;
    max-width: 680px;
    padding: 0;
    margin-right: auto;
}
.request-search-field {
    box-sizing: border-box;
    width: 100%;
    height: 40px;
    min-width: 0;
    padding: 0 10px 0 14px;
    gap: 10px;
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 999px;
}
.request-search-field:focus-within {
    border-color: var(--primary);
    box-shadow: 0 0 0 2px var(--primary-light);
}
.request-search-field .search-icon { color: var(--text-muted); }
.request-search-field input {
    min-width: 0;
    color: var(--text-main);
    font-size: 0.84rem;
}
.request-search-field input::placeholder { color: var(--text-muted); }
.request-search-field input::-webkit-search-cancel-button { display: none; }
.request-search-clear,
.request-search-toggle {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 28px;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: 50%;
    background: transparent;
    color: var(--text-secondary);
    cursor: pointer;
}
.request-search-clear { visibility: hidden; pointer-events: none; }
.request-search-field input:not(:placeholder-shown) ~ .request-search-clear {
    visibility: visible;
    pointer-events: auto;
}
.request-search-clear:hover,
.request-search-toggle:hover { background: var(--primary-light); color: var(--text-main); }
.request-search-clear:focus-visible,
.request-search-toggle:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.request-search-toggle { display: none; }

@media (max-width: 900px) {
    .request-search-field input { font-size: 0.8rem; }
}
@media (max-width: 768px) {
    .top-header.request-search-header {
        box-sizing: border-box;
        height: var(--header-height);
        min-height: var(--header-height);
        padding: 0 14px;
        flex-wrap: nowrap;
        gap: 8px;
    }
    .request-search-header .header-brand { display: none; }
    .request-search-header .header-actions { order: 2; margin-left: 0; flex: 0 0 auto; }
    .request-search-container {
        order: 1;
        flex: 1 1 auto;
        width: auto;
        min-width: 0;
        max-width: none;
        margin: 0;
    }
    .request-search-toggle { display: inline-flex; }
    .request-search-field { display: none; }
    .request-search-container.is-expanded .request-search-toggle { display: none; }
    .request-search-container.is-expanded .request-search-field {
        display: flex;
        position: absolute;
        left: 14px;
        right: 14px;
        top: 50%;
        z-index: 2;
        width: auto;
        max-width: none;
        transform: translateY(-50%);
    }
}
.notif-badge { font-family: 'Poppins', sans-serif; }
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-topbar">
      <div class="sidebar-header-row">
        <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle sidebar" title="Toggle sidebar">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>
        </button>

        <div class="sidebar-title-group">
          <div class="sidebar-title">Admin Dashboard</div>
          <div class="sidebar-subtitle">Victorian Heights</div>
        </div>
      </div>

      <div class="system-switcher-header">
        <a href="?page=dashboard" class="system-switch-header-btn <?php echo $currentSystem == 'victorianpass' ? 'active' : ''; ?>" title="VictorianPass Admin">
          <img src="images/logo.svg" alt="" aria-hidden="true" class="system-switch-victorian-logo">
          <span>Victorian Pass</span>
        </a>
        <a href="?page=smart_waste" class="system-switch-header-btn ecopoint-switch <?php echo $currentSystem == 'ecopoint' ? 'active' : ''; ?>" title="VHEcoPoint Admin">
          <?php echo vh_eco_logo(''); ?>
          <span>VHEcoPoint</span>
        </a>
      </div>
    </div>

    <?php /* Counted once, before the nav, so every badge and the page body behind
             it read the same snapshot. */
    $sidebarCounts = getSidebarActionCounts($con); ?>
    <nav class="nav-list">
       <!-- VictorianPass Navigation -->
       <?php if ($currentSystem == 'victorianpass'): ?>
       <div class="nav-section">
         <div class="nav-section-title">Overview</div>
         <a href="?page=dashboard" class="nav-item <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>" data-page="dashboard"><i class="fa-solid fa-gauge"></i><span>Dashboard</span></a>
         <a href="?page=summary" class="nav-item <?php echo $currentPage == 'summary' ? 'active' : ''; ?>" data-page="summary"><i class="fa-solid fa-chart-column"></i><span>Summary Report</span></a>
       </div>
       <div class="nav-section">
         <div class="nav-section-title">People</div>
         <a href="?page=residents" class="nav-item <?php echo $currentPage == 'residents' ? 'active' : ''; ?>" data-page="residents"><i class="fa-solid fa-house-user"></i><span>Residents</span></a>
         <a href="?page=visitors" class="nav-item <?php echo $currentPage == 'visitors' ? 'active' : ''; ?>" data-page="visitors"><i class="fa-solid fa-user"></i><span>Visitors</span></a>
         <a href="?page=security" class="nav-item <?php echo $currentPage == 'security' ? 'active' : ''; ?>" data-page="security"><i class="fa-solid fa-shield-halved"></i><span>Security Guards</span></a>
       </div>
<div class="nav-section">
          <div class="nav-section-title">Requests</div>
          <?php
          $sbResident = 'Resident requests';
          $sbGuest    = 'Guest requests';
          $sbVisitor  = 'Visitor requests';
          ?>
          <a href="?page=requests" class="nav-item <?php echo $currentPage == 'requests' ? 'active' : ''; ?>" data-page="requests"
             aria-label="<?php echo sidebar_action_aria($sbResident, $sidebarCounts['requests']); ?>">
            <i class="fa-solid fa-clipboard-list"></i><span>Resident Requests</span>
            <?php echo sidebar_action_badge('requests', $sbResident, $sidebarCounts['requests']); ?>
          </a>
          <a href="?page=resident_guest_forms" class="nav-item <?php echo $currentPage == 'resident_guest_forms' ? 'active' : ''; ?>" data-page="resident_guest_forms"
             aria-label="<?php echo sidebar_action_aria($sbGuest, $sidebarCounts['resident_guest_forms'], 'waiting'); ?>">
            <i class="fa-solid fa-user-plus"></i><span>Guest Request</span>
            <?php echo sidebar_action_badge('resident_guest_forms', $sbGuest, $sidebarCounts['resident_guest_forms'], 'waiting'); ?>
          </a>
          <a href="?page=visitor_requests" class="nav-item <?php echo $currentPage == 'visitor_requests' ? 'active' : ''; ?>" data-page="visitor_requests"
             aria-label="<?php echo sidebar_action_aria($sbVisitor, $sidebarCounts['visitor_requests']); ?>">
            <i class="fa-solid fa-clipboard-list" aria-hidden="true"></i><span>Visitor Requests</span>
            <?php echo sidebar_action_badge('visitor_requests', $sbVisitor, $sidebarCounts['visitor_requests']); ?>
          </a>
        </div>
       <div class="nav-section">
         <div class="nav-section-title">Management</div>
         <?php $sbIncident = 'Reported incidents'; ?>
         <a href="?page=report" class="nav-item <?php echo $currentPage == 'report' ? 'active' : ''; ?>" data-page="report"
            aria-label="<?php echo sidebar_action_aria($sbIncident, $sidebarCounts['report']); ?>">
           <i class="fa-solid fa-triangle-exclamation"></i><span>Reported Incidents</span>
           <?php echo sidebar_action_badge('report', $sbIncident, $sidebarCounts['report']); ?>
         </a>
         <a href="?page=history" class="nav-item <?php echo $currentPage == 'history' ? 'active' : ''; ?>" data-page="history"><i class="fa-solid fa-box-archive"></i><span>Archived Requests</span></a>
       </div>
       <?php endif; ?>

       <!-- VHEcoPoint Navigation -->
       <?php if ($currentSystem == 'ecopoint'): ?>
       <div class="nav-section">
         <div class="nav-section-title">VHEcoPoint</div>
         <a href="?page=smart_waste" class="nav-item <?php echo $currentPage == 'smart_waste' ? 'active' : ''; ?>" data-page="smart_waste"><i class="fa-solid fa-gauge"></i><span>Overview</span></a>
         <a href="?page=smart_waste_sessions" class="nav-item <?php echo $currentPage == 'smart_waste_sessions' ? 'active' : ''; ?>" data-page="smart_waste_sessions"><i class="fa-solid fa-recycle"></i><span>Sessions</span></a>
         <a href="?page=smart_waste_logs" class="nav-item <?php echo $currentPage == 'smart_waste_logs' ? 'active' : ''; ?>" data-page="smart_waste_logs"><i class="fa-solid fa-clock-rotate-left"></i><span>Amenity Logs</span></a>
       </div>
       <?php endif; ?>
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
      'requests' => 'Resident amenity requests',
      'resident_guest_forms' => "Resident's Guest Request",
      'visitor_requests' => 'Visitor Requests',
      'reservations' => 'Reservations',
      'report' => 'View Reported Incidents',
      'smart_waste' => 'VHEcoPoint Dashboard',
      'smart_waste_sessions' => 'VHEcoPoint Sessions',
      'smart_waste_logs' => 'VHEcoPoint Amenity Logs',
      'security' => 'Security Guards',
      'residents' => 'Residents',
      'cancelled' => 'Cancelled Requests',
      'summary' => 'Summary Report',
      'dashboard' => 'Dashboard'
    ];
    $pageTitle = $pageTitles[$currentPage] ?? ucfirst($currentPage); ?>
    <?php
      $isRequestSearchPage = in_array($currentPage, array('requests', 'resident_guest_forms', 'visitor_requests'), true);
      $requestSearchPlaceholder = '';
      if ($currentPage === 'requests') {
        $requestSearchPlaceholder = 'Search by name, reference code or house number';
      } elseif ($currentPage === 'resident_guest_forms') {
        $requestSearchPlaceholder = 'Search by resident, guest name or contact';
      } elseif ($currentPage === 'visitor_requests') {
        $requestSearchPlaceholder = 'Search by visitor name or reference code';
      }
    ?>
    <header class="top-header<?php echo $isRequestSearchPage ? ' request-search-header' : ''; ?>">
      <div class="header-brand" aria-hidden="true"></div>
      <div class="header-search<?php echo $isRequestSearchPage ? ' request-search-container' : ''; ?>"<?php echo $isRequestSearchPage ? ' id="request-search-container"' : ''; ?>>
        <?php if ($isRequestSearchPage): ?>
        <button type="button" class="request-search-toggle" id="request-search-toggle"
                aria-label="Open search" aria-expanded="false" aria-controls="search-input">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        </button>
        <div class="search request-search-field">
          <i class="fa-solid fa-magnifying-glass search-icon" aria-hidden="true"></i>
          <input id="search-input" type="search" aria-label="Search requests"
                 placeholder="<?php echo htmlspecialchars($requestSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                 data-desktop-placeholder="<?php echo htmlspecialchars($requestSearchPlaceholder, ENT_QUOTES, 'UTF-8'); ?>"
                 data-list-search="1" autocomplete="off">
          <button type="button" class="request-search-clear" id="request-search-clear" aria-label="Clear search">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </button>
        </div>
        <?php else: ?>
        <div class="search">
          <i class="fa-solid fa-magnifying-glass search-icon"></i>
          <input id="search-input" placeholder="Search <?php echo htmlspecialchars($pageTitle); ?>...">
        </div>
        <?php endif; ?>
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
              <button type="button" class="close" id="notifModalClose" aria-label="Close">×</button>
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

    <div class="page-header<?php echo in_array($currentPage, array('requests', 'visitor_requests', 'resident_guest_forms'), true) ? ' page-header-stack' : ''; ?>">
      <div>
        <h2 id="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
        <?php if ($currentPage === 'requests'): ?>
        <p class="page-subtitle">Verify each downpayment receipt, then approve the reservation.</p>
        <?php elseif ($currentPage === 'visitor_requests'): ?>
        <p class="page-subtitle">Approve each downpayment receipt, then approve the visit.</p>
        <?php elseif ($currentPage === 'resident_guest_forms'): ?>
        <p class="page-subtitle">Review each guest's ID and visit schedule, then approve or deny.</p>
        <?php endif; ?>
      </div>
      <script>
        (function(){
          const input=document.getElementById('search-input');
          const requestSearchContainer=document.getElementById('request-search-container');
          const requestSearchToggle=document.getElementById('request-search-toggle');
          const requestSearchClear=document.getElementById('request-search-clear');
          const requestSearchPage=input && input.getAttribute('data-list-search') === '1';
          function requestSearchIsNarrow(){ return window.matchMedia('(max-width: 768px)').matches; }
          function setRequestSearchExpanded(expanded){
            if(!requestSearchContainer || !requestSearchToggle) return;
            requestSearchContainer.classList.toggle('is-expanded', expanded);
            requestSearchToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            requestSearchToggle.setAttribute('aria-label', expanded ? 'Search requests' : 'Open search');
          }
          function syncRequestSearchLayout(){
            if(!requestSearchPage) return;
            input.placeholder = window.matchMedia('(max-width: 900px)').matches
              ? 'Search requests'
              : input.getAttribute('data-desktop-placeholder');
            if(!requestSearchIsNarrow()) setRequestSearchExpanded(false);
          }
          if(requestSearchPage){
            syncRequestSearchLayout();
            window.addEventListener('resize', syncRequestSearchLayout);
            if(requestSearchToggle){
              requestSearchToggle.addEventListener('click', function(){
                setRequestSearchExpanded(true);
                input.focus();
              });
            }
            if(requestSearchClear){
              requestSearchClear.addEventListener('click', function(){
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.focus();
              });
            }
            input.addEventListener('keydown', function(e){
              if(e.key === 'Escape'){
                e.preventDefault();
                e.stopPropagation();
                input.value = '';
                input.dispatchEvent(new Event('input', { bubbles: true }));
              }
            });
            if(requestSearchContainer){
              requestSearchContainer.addEventListener('focusout', function(){
                window.setTimeout(function(){
                  if(requestSearchIsNarrow() && !input.value && !requestSearchContainer.contains(document.activeElement)){
                    setRequestSearchExpanded(false);
                  }
                }, 0);
              });
            }
            document.addEventListener('DOMContentLoaded', function(){
              if(requestSearchIsNarrow() && input.value) setRequestSearchExpanded(true);
              syncRequestSearchLayout();
            });
          }
          function filter(){
            const q=(input.value||'').toLowerCase().trim();
            const main=document.querySelector('.main');
            const tables=main.querySelectorAll('table');
            tables.forEach(function(tbl){
              /* Tables that run their own search bar opt out of this one. */
              if(tbl.getAttribute('data-vr-own-search')) return;
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
          if(input && input.getAttribute('data-list-search') !== '1'){ input.addEventListener('input',filter); }
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
          function poll(){ fetch('admin.php?action=get_notifications').then(function(r){ return r.json(); }).then(handleData).catch(function(){}); pollVrCounts(); }

          /* ---- Sidebar action badges + Visitor Requests filter box counts ----
             Rides the existing notification poll, so no second timer. The four
             badges are cheap and update on every admin page; the heavier tbody
             swap only happens on the visitor page, and only when the server's
             breakdown has actually drifted from what is on screen. */
          var vrLastSig = null;
          var gqLastSig = null;
          var rrLastSig = null;
          function hasOpenModal(){
            var ms = document.querySelectorAll('.modal,#adminConfirmModal,#denyReasonModal');
            for (var i = 0; i < ms.length; i++) {
              if (!ms[i]) { continue; }
              var d = (ms[i].style && ms[i].style.display) ? ms[i].style.display : '';
              if (d && d !== 'none') { return true; }
            }
            return false;
          }
          /* Every status is part of the signature, not just the total. A request
             moving from To verify to Ready keeps the total the same but still has
             to redraw the row and both box counts. */
          function vrSignature(d){
            var c = (d && d.counts) ? d.counts : {};
            var keys = ['all', 'to_verify', 'ready', 'approved', 'rejected'];
            var out = [];
            for (var i = 0; i < keys.length; i++) { out.push(parseInt(c[keys[i]], 10) || 0); }
            return out.join(':');
          }
          function pollVrCounts(){
            var badges = document.querySelectorAll('.nav-badge');
            var onVrPage = !!(window.VR_LIST && document.getElementById('vr-tbody'));
            /* The guest page runs its own boxes, so it needs the same
               swap-the-tbody treatment the visitor list already has. */
            var onGqPage = !!(window.GQ_LIST && document.getElementById('gq-tbody'));
            var onRrPage = !!(window.RR_LIST && document.getElementById('rr-tbody'));
            if (!badges.length && !onVrPage && !onGqPage && !onRrPage) { return; }
            fetch('admin.php?action=sidebar_counts')
              .then(function(r){ return r.json(); })
              .then(function(d){
                if (!d || !d.success) { return; }
                if (typeof rrdApplySidebarCounts === 'function') {
                  rrdApplySidebarCounts(d.badges, true);
                }
                if (onRrPage && window.RR_LIST) {
                    /* Same arrangement as the guest page: a decision taken in
                       View Details moves the count now, so the signature is
                       dropped and re-seeded from the rows on screen. */
                    window.RR_LIST.onRowsChanged = function(){ rrLastSig = null; };
                }
                if (onRrPage) {
                    /* To verify plus Ready to approve is what the badge counts and
                       what the two dotted boxes hold, so one number is enough to
                       notice that this page has fallen out of step. */
                    var rrAction = parseInt(d.badges && d.badges.requests, 10);
                    if (isNaN(rrAction)) { rrAction = 0; }
                    if (rrLastSig === null) {
                        var rrSeen = window.RR_LIST.statusCounts
                          ? window.RR_LIST.statusCounts()
                          : { to_verify: 0, ready: 0 };
                        rrLastSig = (rrSeen.to_verify + rrSeen.ready);
                    }
                    if (rrAction !== rrLastSig && !hasOpenModal()) {
                        fetch('admin.php?page=requests')
                          .then(function(r){ return r.text(); })
                          .then(function(html){
                            if (window.RR_LIST && window.RR_LIST.refreshFrom(html)) {
                              rrLastSig = rrAction;
                            }
                          })
                          .catch(function(){});
                    }
                }
                if (onGqPage && window.GQ_LIST) {
                    /* A decision made on this page moves the count now, not at
                       the next tick: drop the signature so it re-seeds from
                       what is really on screen. */
                    window.GQ_LIST.onRowsChanged = function(){ gqLastSig = null; };
                }
                if (onGqPage) {
                    /* Pending is the only status that can still move on this page:
                       a request either arrives or leaves the pending pile once it
                       is approved or denied. Its size is therefore a full
                       signature, and it is the same number the badge shows. */
                    var gqPending = parseInt(d.badges && d.badges.resident_guest_forms, 10);
                    if (isNaN(gqPending)) { gqPending = 0; }
                    if (gqLastSig === null) {
                        /* Seed from what is really on screen, so a request that
                           landed between render and first poll is still picked up. */
                        gqLastSig = window.GQ_LIST.statusCounts
                          ? window.GQ_LIST.statusCounts().pending
                          : gqPending;
                    }
                    if (gqPending !== gqLastSig && !hasOpenModal()) {
                        fetch('admin.php?page=resident_guest_forms')
                          .then(function(r){ return r.text(); })
                          .then(function(html){
                            if (window.GQ_LIST && window.GQ_LIST.refreshFrom(html)) {
                              gqLastSig = gqPending;
                            }
                          })
                          .catch(function(){});
                    }
                }

                if (!onVrPage) { return; }

                var sig = vrSignature(d);
                if (vrLastSig === null) {
                  /* Seed from what is really on screen, so a request that landed
                     between render and first poll is still picked up. */
                  vrLastSig = window.VR_LIST.statusCounts
                    ? vrSignature({ counts: window.VR_LIST.statusCounts() })
                    : sig;
                }
                if (sig === vrLastSig) { return; }
                /* Never swap rows out from under the open dialog. The signature is
                   left alone on purpose, so the very next tick retries. */
                if (hasOpenModal()) { return; }
                fetch('admin.php?page=visitor_requests')
                  .then(function(r){ return r.text(); })
                  .then(function(html){
                    if (window.VR_LIST && window.VR_LIST.refreshFrom(html)) {
                      vrLastSig = sig;
                    }
                  })
                  .catch(function(){});
              })
              .catch(function(){});
          }
          poll();
          var pollMs = 10000; var timer = setInterval(poll, pollMs);
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
      <input id="monthSel" type="month" name="month" value="<?php echo htmlspecialchars($selMonth); ?>" required onchange="this.form.submit()">
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
      <div class="dashboard-widget-label">Resident Activities (Guest Requests + Incidents)</div>
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

<?php if (in_array($currentPage, ['smart_waste', 'smart_waste_sessions', 'smart_waste_logs'])): ?>
<?php
  // ---------------------------------------------------------------------
  // VHEcoPoint panel - REAL data from ecopoint_waste_sessions / stations
  // (defensive: never fatals if ecopoint tables or point_transactions are
  //  missing - the page still renders with empty states).
  // ---------------------------------------------------------------------
  if (!defined('ECO_SESSION_STATUSES_OPEN')) { define('ECO_SESSION_STATUSES_OPEN', ['WAITING', 'ACTIVE', 'PROCESSING']); }
  function swDuration($created, $end = null) {
    $c = is_string($created) && $created !== '' ? strtotime($created) : 0;
    if (!$c) return '-';
    $s = ($end && $end !== '' && $end !== '-') ? strtotime($end) : time();
    $d = max(0, $s - $c);
    $h = floor($d / 3600); $m = floor(($d % 3600) / 60);
    if ($h > 0) return $h . 'h ' . $m . 'm';
    if ($m > 0) return $m . 'm';
    return '< 1m';
  }

  $swStats = [
    'resident_count'  => 0,
    'active_now'      => 0,
    'completed_today' => 0,
    'total_sessions'  => 0,
    'total_kg'        => 0.0,
    'total_pts'       => 0,
    'total_kg_today'  => 0.0,
    'total_pts_today' => 0,
    'earned_points'   => 0,
    'redeemed_points' => 0,
    'redemption_count'=> 0,
  ];
  $materialConfigs = [
    'Plastic (PET)'       => ['icon' => 'fa-bottle-water',        'color' => '#22c55e'],
    'Paper & Cardboard'   => ['icon' => 'fa-box-open',            'color' => '#60a5fa'],
    'Aluminum Cans'       => ['icon' => 'fa-prescription-bottle', 'color' => '#f59e0b'],
  ];
  $materialStats = [];
  foreach ($materialConfigs as $label => $config) {
    $materialStats[$label] = ['kg_total' => 0.0, 'kg_today' => 0.0, 'txn_count' => 0];
  }
  $weeklyActivity = [];
  $dateCursor = new DateTimeImmutable('today -6 days');
  for ($i = 0; $i < 7; $i++) {
    $k = $dateCursor->format('Y-m-d');
    $weeklyActivity[$k] = ['label' => $dateCursor->format('D'), 'count' => 0];
    $dateCursor = $dateCursor->modify('+1 day');
  }
  $activeSessions = [];
  $historySessions = [];
  $amenityRedemptionLogs = [];
  $participationStats = [
    'all_time_count' => 0, 'last30_count' => 0, 'last7_count' => 0,
    'all_time_rate' => 0,  'last30_rate' => 0,  'last7_rate' => 0,
  ];
  $swStatusStyle = [
    'WAITING' => ['#b45309', '#fffbeb'], 'ACTIVE' => ['#1d4ed8', '#eff6ff'],
    'PROCESSING' => ['#0e7490', '#ecfeff'], 'COMPLETED' => ['#15803d', '#ecfdf3'],
    'CANCELLED' => ['#991b1b', '#fef2f2'], 'ERROR' => ['#7f1d1d', '#fee2e2'],
  ];
  $todayKey = date('Y-m-d');
  $swSearch = trim((string)($_GET['q'] ?? ''));
  $swStatus = trim((string)($_GET['status'] ?? ''));
  $swValidStatuses = ['WAITING', 'ACTIVE', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'ERROR'];

  $swHas = ['sessions' => false, 'stations' => false, 'pts' => false, 'users' => false];
  if ($con instanceof mysqli) {
    try {
      $res = $con->query('SHOW TABLES');
      if ($res) { while ($row = $res->fetch_row()) { $t = strtolower((string)$row[0]); if ($t === 'ecopoint_waste_sessions') { $swHas['sessions'] = true; } elseif ($t === 'ecopoint_stations') { $swHas['stations'] = true; } elseif ($t === 'point_transactions') { $swHas['pts'] = true; } elseif ($t === 'users') { $swHas['users'] = true; } } }
    } catch (Throwable $e) {}

    try {
      $r = $con->query("SELECT COUNT(*) AS c FROM users WHERE user_type='resident'");
      if ($r && $row = $r->fetch_assoc()) $swStats['resident_count'] = intval($row['c'] ?? 0);
    } catch (Throwable $e) {}

    $accountLogActive = 0;
    $accountLogMonth = 0;
    $accountLogSessions = 0;
    try {
      if ($swHas['sessions']) {
        $r = $con->query("SELECT COUNT(DISTINCT user_id) AS c FROM ecopoint_waste_sessions");
        if ($r && $row = $r->fetch_assoc()) $accountLogActive = intval($row['c'] ?? 0);
        $r = $con->query("SELECT COUNT(DISTINCT user_id) AS c FROM ecopoint_waste_sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($r && $row = $r->fetch_assoc()) $accountLogMonth = intval($row['c'] ?? 0);
        $r = $con->query("SELECT COUNT(*) AS c FROM ecopoint_waste_sessions");
        if ($r && $row = $r->fetch_assoc()) $accountLogSessions = intval($row['c'] ?? 0);
      }
    } catch (Throwable $e) {}

    if ($swHas['pts']) {
      try {
        $r = $con->query("SELECT COUNT(*) AS c,
                 SUM(CASE WHEN transaction_type='earn'   THEN 1 ELSE 0 END) AS e,
                 SUM(CASE WHEN transaction_type='redeem' THEN 1 ELSE 0 END) AS d,
                 COALESCE(SUM(CASE WHEN transaction_type='earn'   THEN amount ELSE 0 END),0) AS ep,
                 COALESCE(SUM(CASE WHEN transaction_type='redeem' THEN amount ELSE 0 END),0) AS rp
               FROM point_transactions");
        if ($r && $row = $r->fetch_assoc()) {
          $swStats['redemption_count'] = intval($row['d'] ?? 0);
          $swStats['earned_points']    = intval($row['ep'] ?? 0);
          $swStats['redeemed_points']  = intval($row['rp'] ?? 0);
        }
        $r = $con->query("SELECT pt.amount, pt.description, pt.reservation_ref_code, pt.created_at,
                 u.first_name, u.last_name, u.house_number, u.email
               FROM point_transactions pt
               LEFT JOIN users u ON u.id = pt.user_id
               WHERE pt.transaction_type='redeem'
               ORDER BY pt.created_at DESC LIMIT 10");
        if ($r) { while ($row = $r->fetch_assoc()) $amenityRedemptionLogs[] = $row; }
      } catch (Throwable $e) {}
    }

    if ($swHas['sessions']) {
      $inListOpen = implode("','", ECO_SESSION_STATUSES_OPEN);
      try {
        // KPIs from real sessions
        $r = $con->query("SELECT COUNT(*) AS c FROM ecopoint_waste_sessions WHERE status IN ('$inListOpen')");
        if ($r && $row = $r->fetch_assoc()) $swStats['active_now'] = intval($row['c'] ?? 0);

        $r = $con->query("SELECT COUNT(*) AS c, COALESCE(SUM(weight_kg),0) AS kg, COALESCE(SUM(points_awarded),0) AS pts
               FROM ecopoint_waste_sessions WHERE status='COMPLETED' AND DATE(completed_at) = CURDATE()");
        if ($r && $row = $r->fetch_assoc()) {
          $swStats['completed_today'] = intval($row['c'] ?? 0);
          $swStats['total_kg_today']  = floatval($row['kg'] ?? 0);
          $swStats['total_pts_today'] = intval($row['pts'] ?? 0);
        }

        $r = $con->query("SELECT COUNT(*) AS c, COALESCE(SUM(weight_kg),0) AS kg, COALESCE(SUM(points_awarded),0) AS pts
               FROM ecopoint_waste_sessions WHERE status='COMPLETED'");
        if ($r && $row = $r->fetch_assoc()) {
          $swStats['total_sessions'] = intval($row['c'] ?? 0);
          $swStats['total_kg']       = floatval($row['kg'] ?? 0);
          $swStats['total_pts']      = intval($row['pts'] ?? 0);
        }

        // Weekly activity - real sessions created per day (last 7 days)
        $r = $con->query("SELECT DATE(created_at) AS d, COUNT(*) AS c FROM ecopoint_waste_sessions
               WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at)");
        if ($r) { while ($row = $r->fetch_assoc()) { $k = (string)($row['d'] ?? ''); if ($k !== '' && isset($weeklyActivity[$k])) $weeklyActivity[$k]['count'] = intval($row['c'] ?? 0); } }

        // Material stats + participation (distinct residents) from COMPLETED sessions
        $r = $con->query("SELECT ws.material_type, ws.weight_kg, ws.created_at, ws.user_id FROM ecopoint_waste_sessions ws WHERE ws.status='COMPLETED'");
        if ($r) {
          while ($row = $r->fetch_assoc()) {
            $label = smartWasteMaterialLabel((string)($row['material_type'] ?? ''), '');
            if (!isset($materialConfigs[$label])) continue; // only the 3 allowed recyclables
            $w = floatval($row['weight_kg'] ?? 0);
            $materialStats[$label]['kg_total'] += $w;
            $materialStats[$label]['txn_count']++;
            if (date('Y-m-d', strtotime((string)$row['created_at'])) === $todayKey) $materialStats[$label]['kg_today'] += $w;
          }
        }

        $successfulScanStatuses = "'WAITING','ACTIVE','PROCESSING','COMPLETED'";

        // A successful QR scan creates a session; open and completed sessions count as participation.
        $r = $con->query("SELECT COUNT(DISTINCT ws.user_id) AS c FROM ecopoint_waste_sessions ws INNER JOIN users u ON u.id = ws.user_id WHERE ws.status IN ($successfulScanStatuses) AND u.user_type='resident'");
        if ($r && $row = $r->fetch_assoc()) $participationStats['all_time_count'] = intval($row['c'] ?? 0);

        // Participation windows use QR scan/session creation time, including open sessions.
        $r = $con->query("SELECT COUNT(DISTINCT ws.user_id) AS c FROM ecopoint_waste_sessions ws INNER JOIN users u ON u.id = ws.user_id WHERE ws.status IN ($successfulScanStatuses) AND u.user_type='resident' AND ws.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        if ($r && $row = $r->fetch_assoc()) $participationStats['last30_count'] = intval($row['c'] ?? 0);
        $r = $con->query("SELECT COUNT(DISTINCT ws.user_id) AS c FROM ecopoint_waste_sessions ws INNER JOIN users u ON u.id = ws.user_id WHERE ws.status IN ($successfulScanStatuses) AND u.user_type='resident' AND ws.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        if ($r && $row = $r->fetch_assoc()) $participationStats['last7_count'] = intval($row['c'] ?? 0);

        // Participants list for sidebar
        $participants = [];
        $r = $con->query("SELECT u.id, u.first_name, u.last_name, u.house_number, u.email,
                 COUNT(ws.id) AS session_count, SUM(ws.weight_kg) AS total_kg, SUM(ws.points_awarded) AS total_pts,
                 MAX(ws.created_at) AS last_session
               FROM ecopoint_waste_sessions ws
               LEFT JOIN users u ON u.id = ws.user_id
               WHERE ws.status IN ($successfulScanStatuses) AND u.user_type='resident'
               GROUP BY u.id, u.first_name, u.last_name, u.house_number, u.email
               ORDER BY last_session DESC");
        if ($r) { while ($row = $r->fetch_assoc()) $participants[] = $row; }

        // Live active sessions
        $r = $con->query("SELECT ws.*, st.station_code,
                 u.first_name, u.last_name, u.house_number, u.email
               FROM ecopoint_waste_sessions ws
               LEFT JOIN ecopoint_stations st ON st.id = ws.station_id
               LEFT JOIN users u              ON u.id  = ws.user_id
               WHERE ws.status IN ('$inListOpen')
               ORDER BY ws.id DESC LIMIT 25");
        if ($r) { while ($row = $r->fetch_assoc()) $activeSessions[] = $row; }

        // Session history - search + status filter
        $where = ''; $params = []; $types = '';
        if ($swSearch !== '') {
          $where .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.house_number LIKE ? OR st.station_code LIKE ? OR ws.qr_ref_code LIKE ? OR ws.material_type LIKE ? OR CAST(ws.id AS CHAR) LIKE ?) ";
          $like = '%' . $swSearch . '%';
          $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
          $types .= 'sssssss';
        }
        if ($swStatus !== '' && in_array($swStatus, $swValidStatuses, true)) {
          $where .= " AND ws.status = ? ";
          $params[] = $swStatus; $types .= 's';
        }
        $stmt = $con->prepare("
          SELECT ws.*, st.station_code,
                 u.first_name, u.last_name, u.house_number, u.email
          FROM   ecopoint_waste_sessions ws
          LEFT JOIN ecopoint_stations st ON st.id = ws.station_id
          LEFT JOIN users u              ON u.id  = ws.user_id
          WHERE  1=1 $where
          ORDER BY ws.id DESC
          LIMIT  100
        ");
        if ($stmt) {
          if ($types !== '') $stmt->bind_param($types, ...$params);
          $stmt->execute();
          $res = $stmt->get_result();
          while ($row = $res->fetch_assoc()) $historySessions[] = $row;
          $stmt->close();
        }
      } catch (Throwable $e) {}
    }
  }

  $residentBase = max(1, intval($swStats['resident_count']));
  $participationStats['all_time_rate'] = round(($participationStats['all_time_count'] / $residentBase) * 100, 1);
  $participationStats['last30_rate']   = round(($participationStats['last30_count']   / $residentBase) * 100, 1);
  $participationStats['last7_rate']    = round(($participationStats['last7_count']    / $residentBase) * 100, 1);

  $maxWeeklyCount = 1;
  foreach ($weeklyActivity as $day) { if (($day['count'] ?? 0) > $maxWeeklyCount) $maxWeeklyCount = intval($day['count']); }
  $maxMaterialKg = 1; $maxKgToday = 1;
  foreach ($materialStats as $mr) {
    if (($mr['kg_total'] ?? 0) > $maxMaterialKg) $maxMaterialKg = floatval($mr['kg_total']);
    if (($mr['kg_today'] ?? 0) > $maxKgToday)    $maxKgToday    = floatval($mr['kg_today']);
  }
?>
<section class="panel" id="smart-waste-panel">
  <div class="smart-waste-brand-banner">
    <span class="station-name">VHEcoPoint</span>
    <span class="station-desc">Smart Waste Segregation Station</span>
  </div>
  <h3>VHEcoPoint Admin Panel</h3>

  <?php if ($currentPage == 'smart_waste'): ?>
  <div class="dashboard-grid" style="padding:0; margin:0 0 20px; grid-template-columns:repeat(3, 1fr);">
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">Active Sessions Now</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['active_now']); ?></div>
      <div class="dashboard-widget-subtext">WAITING / ACTIVE / PROCESSING sessions</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">Completed Today</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['completed_today']); ?></div>
      <div class="dashboard-widget-subtext"><?php echo number_format($swStats['total_kg_today'] * 1000, 0); ?> g - <?php echo number_format($swStats['total_pts_today']); ?> pts today</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">Total Sessions</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['total_sessions']); ?></div>
      <div class="dashboard-widget-subtext"><?php echo number_format($swStats['total_kg'] * 1000, 0); ?> g collected all-time</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">VHEcoPoints Awarded</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['total_pts']); ?></div>
      <div class="dashboard-widget-subtext"><?php echo number_format($swStats['total_pts_today']); ?> earned today - <?php echo number_format($swStats['redeemed_points']); ?> redeemed</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">Total Grams Collected</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['total_kg'] * 1000, 0); ?></div>
      <div class="dashboard-widget-subtext"><?php echo number_format($swStats['total_sessions']); ?> completed sessions all-time</div>
    </div>
    <div class="dashboard-widget">
      <div class="dashboard-widget-label">Registered Residents</div>
      <div class="dashboard-widget-value"><?php echo number_format($swStats['resident_count']); ?></div>
      <div class="dashboard-widget-subtext"><?php echo number_format($accountLogActive); ?> have used the station - <?php echo number_format($accountLogMonth); ?> active this month</div>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($currentPage == 'smart_waste_sessions'): ?>
  <div class="smart-waste-card">
        <h4>Real-Time Active Sessions</h4>
        <div class="smart-waste-note">Sessions currently in progress at VHEcoPoint stations. Refreshes automatically.</div>
        <div class="table-responsive-wrapper smart-waste-table-compact">
          <table>
            <thead><tr>
              <th>ID</th><th>Station</th><th>Resident</th><th>House</th><th>Status</th><th>Material</th><th>Weight (g)</th><th>Points (calc / award)</th><th>Started</th><th>Elapsed</th>
            </tr></thead>
            <tbody>
              <?php if (empty($activeSessions)): ?>
                <tr><td colspan="10" style="text-align:center;">No active sessions right now.</td></tr>
              <?php else: foreach ($activeSessions as $s): ?>
                <?php
                  $stStyle = $swStatusStyle[$s['status']] ?? ['#374151', '#f3f4f6'];
                  $resident = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                  if ($resident === '') $resident = 'Unknown Resident';
                ?>
                <tr>
                  <td><b>#<?php echo (int)$s['id']; ?></b></td>
                  <td><?php echo htmlspecialchars($s['station_code'] ?? '-'); ?></td>
                  <td><strong><?php echo htmlspecialchars($resident); ?></strong><br><small style="color:#6b7280;"><?php echo htmlspecialchars($s['email'] ?? ''); ?></small></td>
                  <td><?php echo htmlspecialchars($s['house_number'] ?? '-'); ?></td>
                  <td><span class="smart-waste-status-pill" style="background:<?php echo $stStyle[1]; ?>;color:<?php echo $stStyle[0]; ?>;"><?php echo htmlspecialchars($s['status']); ?></span></td>
                  <td><?php echo htmlspecialchars(smartWasteMaterialLabel($s['material_type'] ?? '', '')); ?></td>
                  <td><?php echo number_format((float)($s['weight_kg'] ?? 0) * 1000, 0); ?> g</td>
                  <td><?php echo (int)($s['points_calculated'] ?? 0); ?> / <?php echo (int)($s['points_awarded'] ?? 0); ?></td>
                  <td><?php echo $s['created_at'] ? date('M j, g:i A', strtotime($s['created_at'])) : '-'; ?></td>
                  <td><?php echo swDuration($s['created_at'] ?? '', $s['completed_at'] ?? ''); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="smart-waste-card">
        <h4>Recycling Session History</h4>
        <div class="smart-waste-note">All VHEcoPoint recycling sessions with resident details, timing, materials, weight, and points awarded.</div>
        <form class="smart-waste-form-grid" method="GET" action="">
          <input type="hidden" name="page" value="smart_waste_sessions">
          <div>
            <label>Search</label>
            <input type="text" name="q" placeholder="Name, house #, station, QR ref, or session ID..." value="<?php echo htmlspecialchars($swSearch); ?>">
          </div>
          <div>
            <label>Status</label>
            <select name="status">
              <option value="">All statuses</option>
              <?php foreach ($swValidStatuses as $st): ?>
                <option value="<?php echo $st; ?>" <?php echo $swStatus === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-approve" style="padding:9px 16px;font-family:'Poppins',sans-serif;"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
            <?php if ($swSearch !== '' || $swStatus !== ''): ?>
              <a href="admin.php?page=smart_waste_sessions" class="btn btn-delete" style="padding:9px 14px;font-family:'Poppins',sans-serif;">Clear</a>
            <?php endif; ?>
          </div>
        </form>
        <div style="height:10px;"></div>
        <div class="table-responsive-wrapper smart-waste-table-compact">
          <table>
            <thead><tr>
              <th>ID</th><th>Started</th><th>Ended</th><th>Duration</th><th>Resident</th><th>House</th><th>Status</th><th>Material</th><th>Weight (g)</th><th>Pts Awarded</th>
            </tr></thead>
            <tbody>
              <?php if (empty($historySessions)): ?>
                <tr><td colspan="10" style="text-align:center;">No sessions found<?php echo ($swSearch !== '' || $swStatus !== '') ? ' for the current search/filter' : ''; ?>.</td></tr>
              <?php else: foreach ($historySessions as $s): ?>
                <?php
                  $stStyle = $swStatusStyle[$s['status']] ?? ['#374151', '#f3f4f6'];
                  $resident = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                  if ($resident === '') $resident = 'Unknown Resident';
                  $ended = $s['completed_at'] ? date('M j, g:i A', strtotime($s['completed_at'])) : '-';
                  $isFinal = in_array($s['status'], ['COMPLETED', 'CANCELLED', 'ERROR'], true);
                ?>
                <tr>
                  <td><b>#<?php echo (int)$s['id']; ?></b></td>
                  <td><?php echo $s['created_at'] ? date('M j, g:i A', strtotime($s['created_at'])) : '-'; ?></td>
                  <td><?php echo htmlspecialchars($ended); ?></td>
                  <td><?php echo swDuration($s['created_at'] ?? '', $isFinal ? ($s['completed_at'] ?? '') : ''); ?></td>
                  <td><strong><?php echo htmlspecialchars($resident); ?></strong><br><small style="color:#6b7280;"><?php echo htmlspecialchars($s['email'] ?? ''); ?></small></td>
                  <td><?php echo htmlspecialchars($s['house_number'] ?? '-'); ?></td>
                  <td><span class="smart-waste-status-pill" style="background:<?php echo $stStyle[1]; ?>;color:<?php echo $stStyle[0]; ?>;"><?php echo htmlspecialchars($s['status']); ?></span></td>
                  <td><?php echo htmlspecialchars(smartWasteMaterialLabel($s['material_type'] ?? '', '')); ?></td>
                  <td><?php echo number_format((float)($s['weight_kg'] ?? 0) * 1000, 0); ?> g</td>
                  <td style="font-weight:800;color:#166534;"><?php echo (int)($s['points_awarded'] ?? 0); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
  <?php endif; ?>

  <?php if ($currentPage == 'smart_waste_logs'): ?>
      <div class="smart-waste-card">
        <h4>Amenity Redemption / Usage Logs</h4>
        <div class="smart-waste-note">Separate log showing which resident redeemed points for which amenity or facility booking.</div>
        <div class="table-responsive-wrapper smart-waste-table-compact">
          <table>
            <thead><tr>
              <th>Time</th><th>Resident Account</th><th>House</th><th>Amenity Redeemed</th><th>Points Spent</th><th>Booking Reference</th>
            </tr></thead>
            <tbody>
              <?php if (empty($amenityRedemptionLogs)): ?>
                <tr><td colspan="6" style="text-align:center;">No amenity redemption records found.</td></tr>
              <?php else: foreach ($amenityRedemptionLogs as $log): ?>
                <?php
                  $residentName = trim(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? ''));
                  if ($residentName === '') { $residentName = 'Unknown Resident'; }
                  $amenityDesc = trim((string)($log['description'] ?? ''));
                  if ($amenityDesc === '') { $amenityDesc = 'Amenity Booking'; }
                  $eventTime = !empty($log['created_at']) ? date('M d, Y g:i A', strtotime($log['created_at'])) : 'N/A';
                  $referenceCode = trim((string)($log['reservation_ref_code'] ?? ''));
                ?>
                <tr>
                  <td><?php echo htmlspecialchars($eventTime); ?></td>
                  <td><strong><?php echo htmlspecialchars($residentName); ?></strong><br><small style="color:#6b7280;"><?php echo htmlspecialchars($log['email'] ?? ''); ?></small></td>
                  <td><?php echo htmlspecialchars(trim((string)($log['house_number'] ?? 'N/A'))); ?></td>
                  <td><?php echo htmlspecialchars($amenityDesc); ?></td>
                  <td style="font-weight:800;color:#b91c1c;">-<?php echo number_format(intval($log['amount'] ?? 0)); ?> pts</td>
                  <td class="wrap"><?php echo htmlspecialchars($referenceCode !== '' ? $referenceCode : 'N/A'); ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
  <?php endif; ?>

  <?php if ($currentPage == 'smart_waste'): ?>
  <div style="display:flex; flex-direction:column; gap:16px;">
    <div class="smart-waste-card">
      <h4>Weekly Station Activity</h4>
      <div class="smart-waste-note">VHEcoPoint sessions logged per day over the last 7 days.</div>
      <div class="smart-waste-chart">
        <?php foreach ($weeklyActivity as $day): ?>
          <?php
            $barHeight = max(18, intval((($day['count'] ?? 0) / $maxWeeklyCount) * 120));
            if (($day['count'] ?? 0) === 0) { $barHeight = 18; }
          ?>
          <div class="smart-waste-bar-wrap">
            <div class="smart-waste-bar-value"><?php echo intval($day['count'] ?? 0); ?></div>
            <div class="smart-waste-bar" style="height:<?php echo $barHeight; ?>px;"></div>
            <div class="smart-waste-bar-label"><?php echo htmlspecialchars($day['label'] ?? ''); ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="smart-waste-card">
      <h4>Resident Participation Rates</h4>
      <div class="smart-waste-note">Participation is based on registered VictorianPass residents who have successfully scanned their QR code at a VHEcoPoint station.</div>
      <div class="smart-waste-kpi-grid">
        <div class="smart-waste-kpi">
          <div class="smart-waste-kpi-label">VHEcoPoint Participants</div>
          <div class="smart-waste-kpi-value"><?php echo number_format($participationStats['all_time_count']); ?></div>
          <div class="smart-waste-kpi-subtext">Unique residents with at least 1 successful VHEcoPoint QR scan</div>
        </div>
        <div class="smart-waste-kpi">
          <div class="smart-waste-kpi-label">Registered Residents</div>
          <div class="smart-waste-kpi-value"><?php echo number_format($swStats['resident_count']); ?></div>
          <div class="smart-waste-kpi-subtext">Total active VictorianPass resident accounts</div>
        </div>
        <div class="smart-waste-kpi">
          <div class="smart-waste-kpi-label">All-Time Participation Rate</div>
          <div class="smart-waste-kpi-value"><?php echo number_format($participationStats['all_time_rate'], 1); ?>%</div>
          <div class="smart-waste-kpi-subtext"><?php echo number_format($participationStats['all_time_count']); ?> of <?php echo number_format($swStats['resident_count']); ?> registered residents</div>
        </div>
      </div>
      <div class="smart-waste-kpi-grid" style="margin-top:12px;">
        <div class="smart-waste-kpi">
          <div class="smart-waste-kpi-label">Active in Last 30 Days</div>
          <div class="smart-waste-kpi-value"><?php echo number_format($participationStats['last30_rate'], 1); ?>%</div>
          <div class="smart-waste-kpi-subtext"><?php echo number_format($participationStats['last30_count']); ?> of <?php echo number_format($swStats['resident_count']); ?> registered residents</div>
        </div>
        <div class="smart-waste-kpi">
          <div class="smart-waste-kpi-label">Active in Last 7 Days</div>
          <div class="smart-waste-kpi-value"><?php echo number_format($participationStats['last7_rate'], 1); ?>%</div>
          <div class="smart-waste-kpi-subtext"><?php echo number_format($participationStats['last7_count']); ?> of <?php echo number_format($swStats['resident_count']); ?> registered residents</div>
        </div>
      </div>
    </div>

    <div class="smart-waste-card">
      <h4><i class="fa-solid fa-users" style="margin-right:6px;"></i>Station Participants</h4>
      <div class="smart-waste-note"><?php echo number_format($participationStats['all_time_count']); ?> of <?php echo number_format($swStats['resident_count']); ?> registered residents have used the VHEcoPoint station.</div>
      <?php if (empty($participants)): ?>
        <div class="smart-waste-empty">No participants yet.</div>
      <?php else: ?>
        <div class="smart-waste-list">
          <?php foreach ($participants as $p): ?>
            <?php
              $pName = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
              if ($pName === '') $pName = 'Resident #' . intval($p['id']);
              $pHouse = trim((string)($p['house_number'] ?? ''));
              $pLastSession = !empty($p['last_session']) ? date('M j, Y', strtotime($p['last_session'])) : '-';
            ?>
            <div class="smart-waste-list-item">
              <span class="smart-waste-material-icon" style="background:#ecfdf3; color:#15803d;">
                <i class="fa-solid fa-user"></i>
              </span>
              <div class="smart-waste-list-main">
                <div class="smart-waste-list-title"><?php echo htmlspecialchars($pName); ?> <?php if ($pHouse !== ''): ?><small style="color:#6b7280;font-weight:400;">(<?php echo htmlspecialchars($pHouse); ?>)</small><?php endif; ?></div>
                <div class="smart-waste-list-subtitle"><?php echo intval($p['session_count']); ?> session<?php echo $p['session_count'] == 1 ? '' : 's'; ?> - <?php echo number_format(floatval($p['total_kg'] ?? 0) * 1000, 0); ?> g - <?php echo number_format(intval($p['total_pts'] ?? 0)); ?> pts</div>
                <div class="smart-waste-list-subtitle"><small style="color:#9ca3af;">Last: <?php echo $pLastSession; ?></small></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>




<!-- RESIDENT GUEST FORMS -->
<?php if ($currentPage == 'resident_guest_forms'):
  /* Everything the list and the dialog need is prepared once per row, then
     handed to the browser as JSON on the row itself. No extra endpoint, and
     nothing for the other request pages to share. */
  /* guest_forms, via the same accessor this page always used. It returns a
     result object or false, so it is drained into a plain array here. */
  $gqResult = getResidentVisitorRequests($con);
  $gqRows   = ($gqResult && $gqResult->num_rows > 0)
      ? $gqResult->fetch_all(MYSQLI_ASSOC)
      : array();
  $gqCounts = array('all' => 0, 'pending' => 0, 'approved' => 0, 'denied' => 0);
  $gqPayload = array();
  foreach ($gqRows as $gqRow) {
      $gqStatus = strtolower(trim((string)($gqRow['approval_status'] ?? '')));
      if ($gqStatus === '') { $gqStatus = 'pending'; }
      if (!isset($gqCounts[$gqStatus])) { $gqStatus = 'pending'; }
      $gqCounts[$gqStatus]++;
      $gqCounts['all']++;
      $gqCreated = !empty($gqRow['created_at']) ? strtotime($gqRow['created_at']) : 0;
      $gqVisitTs = !empty($gqRow['visit_date']) ? strtotime($gqRow['visit_date'] . ' ' . (!empty($gqRow['visit_time']) ? $gqRow['visit_time'] : '00:00:00')) : 0;
      $gqPayload[] = array(
          'id'         => intval($gqRow['id']),
          'status'     => $gqStatus,
          'ref'        => (string)($gqRow['ref_code'] ?? ''),
          'guest'      => trim((string)($gqRow['visitor_first_name'] ?? '') . ' ' . (string)($gqRow['visitor_middle_name'] ?? '') . ' ' . (string)($gqRow['visitor_last_name'] ?? '')),
          'contact'    => trim((string)($gqRow['visitor_contact'] ?? '')),
          'resident'   => trim((string)($gqRow['res_first_name'] ?? '') . ' ' . (string)($gqRow['res_last_name'] ?? '')),
          'house'      => (string)($gqRow['res_house_number'] ?? ''),
          'visit_date' => (string)($gqRow['visit_date'] ?? ''),
          'visit_time' => (string)($gqRow['visit_time'] ?? ''),
          'requested'  => !empty($gqRow['created_at']) ? date('M j, Y', $gqCreated) : '',
          'requested_iso' => !empty($gqRow['created_at']) ? date('M j, Y g:i A', $gqCreated) : '',
          'ago'        => vpRelativeTime($gqCreated),
          'valid_id'   => (string)($gqRow['valid_id_path'] ?? ''),
          'reason'     => trim((string)($gqRow['denial_reason'] ?? '')),
          'created'    => $gqCreated,
          'visit_ts'   => $gqVisitTs,
      );
  }
  /* A one-time server message, e.g. "this request was no longer pending". */
  $gqFlash = isset($_SESSION['flash_notice']) ? trim((string)$_SESSION['flash_notice']) : '';
  unset($_SESSION['flash_notice']);
?>
<section class="panel" id="gq-panel">

  <?php if ($gqFlash !== ''): ?>
  <div class="gq-flash" role="status">
    <span><?php echo htmlspecialchars($gqFlash); ?></span>
    <button type="button" id="gq-flash-close" aria-label="Dismiss message">&times;</button>
  </div>
  <?php endif; ?>

  <!-- The four boxes are the status filter. Default is All requests. -->
  <div class="gq-filters" role="group" aria-label="Filter guest requests by status">
    <button type="button" class="gq-filter" data-gq-filter="all" aria-pressed="true">
      <span class="gq-filter-count" data-gq-count="all"><?php echo intval($gqCounts['all']); ?></span>
      <span class="gq-filter-label">All requests</span>
    </button>
    <button type="button" class="gq-filter" data-gq-filter="pending" aria-pressed="false">
      <span class="gq-filter-count" data-gq-count="pending"><?php echo intval($gqCounts['pending']); ?></span>
      <span class="gq-filter-label"><?php echo intval($gqCounts['pending']) > 0 ? '<span class="gq-filter-dot" aria-hidden="true"></span>' : ''; ?>Pending</span>
    </button>
    <button type="button" class="gq-filter" data-gq-filter="approved" aria-pressed="false">
      <span class="gq-filter-count" data-gq-count="approved"><?php echo intval($gqCounts['approved']); ?></span>
      <span class="gq-filter-label">Approved</span>
    </button>
    <button type="button" class="gq-filter" data-gq-filter="denied" aria-pressed="false">
      <span class="gq-filter-count" data-gq-count="denied"><?php echo intval($gqCounts['denied']); ?></span>
      <span class="gq-filter-label">Denied</span>
    </button>
  </div>

  <!-- Sort and date controls; search is in the top bar. -->
  <div class="gq-toolbar">
    <div class="gq-controls" id="gq-controls">
      <div class="gq-field">
        <label for="gq-sort">Sort by</label>
        <select id="gq-sort" class="gq-select">
          <option value="needs_action">Needs action</option>
          <option value="newest">Newest requested</option>
          <option value="oldest">Oldest requested</option>
          <option value="visit_soon">Visit date: soonest first</option>
          <option value="guest_name">Guest name (A to Z)</option>
        </select>
      </div>
      <div class="gq-field gq-date-field">
        <label for="gq-date">Date requested</label>
        <div class="gq-date-control">
          <select id="gq-date" class="gq-select">
            <option value="">Any time</option>
            <option value="today">Today</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="custom">Custom range</option>
          </select>
          <span class="gq-date-range" id="gq-date-range" hidden>
            <input type="date" id="gq-date-from" aria-label="Requested from">
            <span class="muted">to</span>
            <input type="date" id="gq-date-to" aria-label="Requested to">
          </span>
        </div>
      </div>
      <button type="button" class="gq-clear" id="gq-clear" hidden>Clear filters</button>
    </div>
  </div>

  <p class="gq-result-line" id="gq-result-line" aria-live="polite"></p>

  <div class="gq-table-wrap">
    <table class="table table-gq" id="gq-table" data-vr-own-search="1">
      <thead>
        <tr>
          <th scope="col">Resident</th>
          <th scope="col">Guest</th>
          <th scope="col">Valid ID</th>
          <th scope="col">Visit schedule</th>
          <th scope="col" class="gq-sortable" data-gq-sortcol="created"
              tabindex="0" aria-sort="none">Requested<span class="gq-sort-arrow" aria-hidden="true">&#8597;</span></th>
          <th scope="col">Status</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody id="gq-tbody">
        <?php if (count($gqPayload) > 0): ?>
          <?php foreach ($gqPayload as $gqItem):
            $gqHaystack = strtolower(implode(' ', array($gqItem['guest'], $gqItem['resident'], $gqItem['house'], $gqItem['contact'], $gqItem['ref'])));
            $gqPill = $gqItem['status'] === 'approved' ? 'approved' : ($gqItem['status'] === 'denied' ? 'denied' : 'pending');
          ?>
          <tr data-status="<?php echo htmlspecialchars($gqItem['status'], ENT_QUOTES); ?>"
              data-id="<?php echo intval($gqItem['id']); ?>"
              data-ref="<?php echo htmlspecialchars($gqItem['ref'], ENT_QUOTES); ?>"
              data-source="guest_form"
              data-name="<?php echo htmlspecialchars($gqItem['guest'], ENT_QUOTES); ?>"
              data-created="<?php echo intval($gqItem['created']); ?>"
              data-visit="<?php echo intval($gqItem['visit_ts']); ?>"
              data-search="<?php echo htmlspecialchars($gqHaystack, ENT_QUOTES); ?>"
              data-row="<?php echo htmlspecialchars(json_encode($gqItem, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES); ?>">

            <td>
              <?php $gqResidentName = $gqItem['resident'] !== '' ? $gqItem['resident'] : '—'; ?>
              <span class="gq-name" tabindex="0" title="<?php echo htmlspecialchars($gqResidentName, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gqResidentName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($gqResidentName); ?></span>
              <span class="gq-meta" tabindex="0" title="<?php echo htmlspecialchars($gqItem['house'] !== '' ? $gqItem['house'] : '—', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gqItem['house'] !== '' ? $gqItem['house'] : '—', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($gqItem['house'] !== '' ? $gqItem['house'] : '—'); ?></span>
            </td>

            <td>
              <?php $gqGuestName = $gqItem['guest'] !== '' ? $gqItem['guest'] : '—'; ?>
              <span class="gq-name" tabindex="0" title="<?php echo htmlspecialchars($gqGuestName, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gqGuestName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($gqGuestName); ?></span>
              <span class="gq-request-contact" tabindex="0" title="<?php echo htmlspecialchars($gqItem['contact'] !== '' ? $gqItem['contact'] : '—', ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($gqItem['contact'] !== '' ? $gqItem['contact'] : '—', ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($gqItem['contact'] !== '' ? $gqItem['contact'] : '—'); ?></span>
            </td>

            <td>
              <?php if ($gqItem['valid_id'] !== ''): ?>
                <button type="button" class="btn btn-view gq-view-id"
                        data-gq-id="<?php echo intval($gqItem['id']); ?>">View ID</button>
              <?php else: ?>
                <span class="muted">&mdash;</span>
              <?php endif; ?>
            </td>

            <td>
              <?php
                $gqVisitLabel = $gqItem['visit_date'] !== '' ? date('M j, Y', strtotime($gqItem['visit_date'])) : '';
                $gqTimeLabel  = '';
                if ($gqItem['visit_time'] !== '') {
                    $gqTimeTs = strtotime($gqItem['visit_time']);
                    if ($gqTimeTs) { $gqTimeLabel = date('g:i A', $gqTimeTs); }
                }
              ?>
              <span class="gq-visit"><?php echo htmlspecialchars($gqVisitLabel !== '' ? $gqVisitLabel : '—'); ?></span>
              <?php if ($gqTimeLabel !== ''): ?>
                <span class="gq-meta"><?php echo htmlspecialchars($gqTimeLabel); ?></span>
              <?php endif; ?>
            </td>

            <td>
              <span class="gq-requested"><?php echo htmlspecialchars($gqItem['requested'] !== '' ? $gqItem['requested'] : '—'); ?></span>
              <?php if (!empty($gqItem['created'])): ?>
                <span class="gq-meta"><?php echo htmlspecialchars(date('g:i A', intval($gqItem['created']))); ?></span>
              <?php endif; ?>
              <?php if ($gqItem['ago'] !== ''): ?>
                <span class="gq-ago"><?php echo htmlspecialchars($gqItem['ago']); ?></span>
              <?php endif; ?>
            </td>

            <td><span class="gq-pill gq-pill-<?php echo $gqPill; ?>"><?php echo ucfirst($gqItem['status']); ?></span></td>

            <td class="actions">
              <button type="button" class="btn btn-view gq-open"
                      data-gq-id="<?php echo intval($gqItem['id']); ?>">View Details</button>
              <?php if ($gqItem['status'] === 'pending'): ?>
                <button type="button" class="btn btn-reject gq-deny"
                        data-gq-id="<?php echo intval($gqItem['id']); ?>">Deny</button>
                <button type="button" class="btn btn-approve gq-approve"
                        data-gq-id="<?php echo intval($gqItem['id']); ?>">Approve</button>
              <?php elseif ($gqItem['status'] === 'approved' && $gqItem['ref'] !== ''): ?>
                <a class="btn btn-qr" href="qr_view.php?code=<?php echo urlencode($gqItem['ref']); ?>"
                   target="_blank" rel="noopener"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> View QR</a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        <tr class="gq-empty" id="gq-empty-row" style="display:none">
          <td colspan="7">
            <span id="gq-empty-text">No requests match your filters.</span>
            <button type="button" class="gq-empty-clear" id="gq-empty-clear" hidden>Clear filters</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="gq-pager" id="gq-pager" hidden>
    <span class="gq-pager-info" id="gq-pager-info"></span>
    <button type="button" class="gq-page-btn" id="gq-page-prev" aria-label="Previous page">&larr;</button>
    <span id="gq-page-numbers"></span>
    <button type="button" class="gq-page-btn" id="gq-page-next" aria-label="Next page">&rarr;</button>
  </div>

</section>

<!-- View Details: guest and visit details on the left, the valid ID on the right. -->
<div id="gqModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="gq-dlg-title">
  <div class="modal-content">
    <div class="gq-dlg-head">
      <div>
        <h3 id="gq-dlg-title">Guest request</h3>
        <span class="gq-dlg-sub" id="gq-dlg-sub"></span>
      </div>
      <span class="gq-dlg-pill gq-pill" id="gq-dlg-pill"></span>
      <button type="button" class="close" id="gq-dlg-close" aria-label="Close">&times;</button>
    </div>
    <div class="gq-dlg-body">
      <div>
        <section class="gq-dlg-section">
          <p class="gq-dlg-title">Guest</p>
          <div class="gq-info" id="gq-dlg-info"></div>
        </section>
        <section class="gq-dlg-section">
          <p class="gq-dlg-title">Visit</p>
          <div class="gq-info" id="gq-dlg-visit"></div>
        </section>
        <section class="gq-dlg-section">
          <p class="gq-dlg-title">Requested by</p>
          <div class="gq-info" id="gq-dlg-requester"></div>
        </section>
        <section class="gq-dlg-section" id="gq-dlg-denial-section" hidden>
          <p class="gq-dlg-title">Denial reason</p>
          <div class="gq-info" id="gq-dlg-denial"></div>
        </section>
      </div>
      <div>
        <p class="gq-dlg-title">Valid ID</p>
        <div class="gq-id-box">
          <div class="gq-id-frame" id="gq-id-frame"></div>
          <div class="gq-id-actions" id="gq-id-actions" hidden>
            <span id="gq-id-hint">Click the image to zoom</span>
            <button type="button" class="btn btn-view" id="gq-id-zoom">Zoom</button>
          </div>
        </div>
      </div>
    </div>
    <div class="gq-dlg-foot" id="gq-dlg-foot"></div>
  </div>
  <div class="gq-id-lightbox" id="gq-id-lightbox" role="dialog" aria-modal="true"
       aria-label="Enlarged valid ID image" hidden>
    <button type="button" class="gq-id-lightbox-close" id="gq-id-lightbox-close" aria-label="Close enlarged image">&times;</button>
    <img id="gq-id-lightbox-image" alt="">
  </div>
</div>

<!-- Deny: the reason is required before anything is saved. -->
<div id="gqDenyModal" class="modal modal-top" role="dialog" aria-modal="true" aria-labelledby="gq-deny-title">
  <div class="modal-content">
    <h3 id="gq-deny-title">Deny this guest request</h3>
    <p class="gq-deny-msg" id="gq-deny-msg">Choose a reason. The resident will see it.</p>
    <div class="gq-deny-field">
      <label for="gq-deny-reason">Reason</label>
      <select id="gq-deny-reason">
        <option value="">Select a reason</option>
        <option value="ID is unclear">ID is unclear</option>
        <option value="ID does not match the guest name">ID does not match the guest name</option>
        <option value="Visit schedule not allowed">Visit schedule not allowed</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="gq-deny-field" id="gq-deny-note-wrap" hidden>
      <label for="gq-deny-note">Details</label>
      <textarea class="gq-deny-note" id="gq-deny-note" placeholder="Add a short note (optional)"></textarea>
    </div>
    <div class="gq-deny-error" id="gq-deny-error" hidden>Please choose a reason to continue.</div>
    <div class="gq-deny-actions">
      <button type="button" class="btn btn-view" id="gq-deny-cancel">Cancel</button>
      <button type="button" class="btn btn-reject" id="gq-deny-submit">Deny request</button>
    </div>
  </div>
</div>

<div class="gq-toasts" id="gq-toasts" aria-live="polite"></div>
<script>
window.GQ_LIST = (function(){
  var table = document.getElementById('gq-table');
  if (!table) { return null; }

  var tbody    = document.getElementById('gq-tbody');
  var emptyRow = document.getElementById('gq-empty-row');
  var emptyTxt = document.getElementById('gq-empty-text');
  var emptyBtn = document.getElementById('gq-empty-clear');
  var resultEl = document.getElementById('gq-result-line');
  var pager    = document.getElementById('gq-pager');
  var pagerInf = document.getElementById('gq-pager-info');
  var pagePrev = document.getElementById('gq-page-prev');
  var pageNext = document.getElementById('gq-page-next');
  var pageNums = document.getElementById('gq-page-numbers');

  var searchIn  = document.getElementById('search-input');
  var sortSel   = document.getElementById('gq-sort');
  var dateSel   = document.getElementById('gq-date');
  var rangeBox  = document.getElementById('gq-date-range');
  var dateFrom  = document.getElementById('gq-date-from');
  var dateTo    = document.getElementById('gq-date-to');
  var clearBtn  = document.getElementById('gq-clear');
  var flashX    = document.getElementById('gq-flash-close');

  var boxes     = Array.prototype.slice.call(document.querySelectorAll('[data-gq-filter]'));
  var countEls  = Array.prototype.slice.call(document.querySelectorAll('[data-gq-count]'));

  var modal    = document.getElementById('gqModal');
  var dlgTitle = document.getElementById('gq-dlg-title');
  var dlgSub   = document.getElementById('gq-dlg-sub');
  var dlgPill  = document.getElementById('gq-dlg-pill');
  var dlgInfo  = document.getElementById('gq-dlg-info');
  var dlgVisit = document.getElementById('gq-dlg-visit');
  var dlgRequester = document.getElementById('gq-dlg-requester');
  var dlgDenialSection = document.getElementById('gq-dlg-denial-section');
  var dlgDenial = document.getElementById('gq-dlg-denial');
  var dlgFoot  = document.getElementById('gq-dlg-foot');
  var dlgClose = document.getElementById('gq-dlg-close');
  var idFrame  = document.getElementById('gq-id-frame');
  var idActs   = document.getElementById('gq-id-actions');
  var idZoom   = document.getElementById('gq-id-zoom');
  var idLightbox = document.getElementById('gq-id-lightbox');
  var idLightboxImage = document.getElementById('gq-id-lightbox-image');
  var idLightboxClose = document.getElementById('gq-id-lightbox-close');

  var denyModal = document.getElementById('gqDenyModal');
  var denyMsg   = document.getElementById('gq-deny-msg');
  var denySel   = document.getElementById('gq-deny-reason');
  var denyNoteW = document.getElementById('gq-deny-note-wrap');
  var denyNote  = document.getElementById('gq-deny-note');
  var denyErr   = document.getElementById('gq-deny-error');
  var denyGo    = document.getElementById('gq-deny-submit');
  var denyNo    = document.getElementById('gq-deny-cancel');

  var KEYS   = ['all', 'pending', 'approved', 'denied'];
  var RANK   = { pending: 0, approved: 1, denied: 2 };
  var PER_PAGE = 10;
  var STORE  = 'vp_admin_gq_state';

  var state = { status: 'all', q: '', sort: 'needs_action', date: '', from: '', to: '', page: 1 };
  var searchTimer = null;

  /* Which row the dialog is showing, and the row the deny dialog is about. */
  var openId = null, denyId = null, lastFocus = null, saving = false;

  function rows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-status]')); }
  function attr(r, n){ return r.getAttribute(n) || ''; }
  function num(r, n){ var v = parseFloat(attr(r, n)); return isFinite(v) ? v : 0; }
  function rowById(id){ var found = null; rows().forEach(function(r){ if (parseInt(attr(r,'data-id'),10) === id) { found = r; } }); return found; }
  function dataOf(r){ try { return JSON.parse(attr(r, 'data-row')); } catch (e) { return null; } }

  function save(){
    try { window.sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {}
  }
  function load(){
    try {
      var raw = window.sessionStorage.getItem(STORE);
      if (!raw) { return; }
      var saved = JSON.parse(raw);
      if (!saved || typeof saved !== 'object') { return; }
      if (KEYS.indexOf(saved.status) !== -1) { state.status = saved.status; }
      if (saved.sort) { state.sort = saved.sort; }
      if (typeof saved.q === 'string') { state.q = saved.q; }
      if (typeof saved.date === 'string') { state.date = saved.date; }
      if (typeof saved.from === 'string') { state.from = saved.from; }
      if (typeof saved.to === 'string') { state.to = saved.to; }
    } catch (e) {}
  }

  /* ---- filters ---- */
  function dayStart(){ var d = new Date(); d.setHours(0,0,0,0); return d.getTime() / 1000; }
  function passesDate(row){
    var mode = state.date;
    if (!mode) { return true; }
    var ts = num(row, 'data-created');
    if (!ts) { return false; }
    if (mode === 'today') { return ts >= dayStart(); }
    if (mode === '7' || mode === '30') {
      var days = (mode === '7') ? 7 : 30;
      return ts >= (dayStart() - ((days - 1) * 86400));
    }
    if (mode === 'custom') {
      var d = new Date(ts * 1000);
      var iso = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      if (state.from && iso < state.from) { return false; }
      if (state.to && iso > state.to) { return false; }
      return true;
    }
    return true;
  }

  function visible(){
    var q = state.q.toLowerCase().trim();
    return rows().filter(function(r){
      if (state.status !== 'all' && attr(r, 'data-status') !== state.status) { return false; }
      if (q && attr(r, 'data-search').indexOf(q) === -1) { return false; }
      return passesDate(r);
    });
  }

  /* ---- sort (applied to the filtered set) ---- */
  var COMPARATORS = {
    needs_action: function(a,b){
      var d = (RANK[attr(a,'data-status')] !== undefined ? RANK[attr(a,'data-status')] : 9)
            - (RANK[attr(b,'data-status')] !== undefined ? RANK[attr(b,'data-status')] : 9);
      return d !== 0 ? d : (num(b,'data-created') - num(a,'data-created'));
    },
    newest:      function(a,b){ return num(b,'data-created') - num(a,'data-created'); },
    oldest:      function(a,b){ return num(a,'data-created') - num(b,'data-created'); },
    visit_soon:  function(a,b){ return (num(a,'data-visit') || 8.64e15) - (num(b,'data-visit') || 8.64e15); },
    guest_name:  function(a,b){ return attr(a,'data-name').localeCompare(attr(b,'data-name')); }
  };

  /* ---- one pass: sort, show/hide, paginate, count ---- */
  function apply(){
    var list = visible();
    var cmp  = COMPARATORS[state.sort] || COMPARATORS.needs_action;
    list.sort(cmp);

    var total = rows().length;
    var pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    if (state.page > pages) { state.page = pages; }
    if (state.page < 1) { state.page = 1; }

    var from = (state.page - 1) * PER_PAGE;
    var to   = Math.min(from + PER_PAGE, list.length);

    /* Sorting an array does nothing you can see: rows paint in document order,
       so the sorted ones are moved physically. Filtered-out rows are parked
       behind them and the empty-state row stays last. The pool is taken first,
       because moving rows out of the tbody would leave nothing to query. */
    var pool = rows();
    var frag = document.createDocumentFragment();
    for (var i = 0; i < list.length; i++) { frag.appendChild(list[i]); }
    for (var j = 0; j < pool.length; j++) {
      if (list.indexOf(pool[j]) === -1) { frag.appendChild(pool[j]); }
    }
    if (emptyRow) { tbody.insertBefore(frag, emptyRow); } else { tbody.appendChild(frag); }

    pool.forEach(function(r){ r.style.display = 'none'; });
    for (var k = from; k < to; k++) { list[k].style.display = ''; }

    if (resultEl) {
      resultEl.textContent = 'Showing ' + list.length + ' of ' + total + ' request' + (total === 1 ? '' : 's');
    }

    var anyFilter = !!(state.q.trim() || state.date || state.from || state.to) || state.sort !== 'needs_action';
    if (emptyRow) { emptyRow.style.display = list.length === 0 ? '' : 'none'; }
    if (emptyTxt) {
      emptyTxt.textContent = (total === 0) ? 'No guest requests yet.' : 'No requests match your filters.';
    }
    if (emptyBtn) { emptyBtn.hidden = !anyFilter; }
    if (clearBtn) { clearBtn.hidden = !anyFilter; }

    renderPager(pages, from, to, list.length);
    paintHeaders();
    save();
  }

  function renderPager(pages, from, to, shown){
    if (pager) { pager.hidden = pages <= 1; }
    if (pagerInf) {
      pagerInf.textContent = shown > 0 ? ('Page ' + state.page + ' of ' + pages + ' · ' + (from + 1) + '–' + to) : '';
    }
    if (pagePrev) { pagePrev.disabled = state.page <= 1; }
    if (pageNext) { pageNext.disabled = state.page >= pages; }
    if (!pageNums) { return; }
    pageNums.innerHTML = '';
    for (var p = 1; p <= pages; p++) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'gq-page-btn';
      b.textContent = String(p);
      b.setAttribute('aria-label', 'Page ' + p);
      if (p === state.page) { b.setAttribute('aria-current', 'true'); }
      b.addEventListener('click', (function(page){
        return function(){ state.page = page; apply(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
      })(p));
      pageNums.appendChild(b);
    }
  }

  /* ---- status boxes ---- */
  function setStatus(key, persist){
    if (KEYS.indexOf(key) === -1) { key = 'all'; }
    state.status = key;
    boxes.forEach(function(b){
      b.setAttribute('aria-pressed', b.getAttribute('data-gq-filter') === key ? 'true' : 'false');
    });
    if (persist !== false) { state.page = 1; save(); apply(); }
  }
  boxes.forEach(function(b){
    b.addEventListener('click', function(){ setStatus(b.getAttribute('data-gq-filter')); });
  });

  /* ---- toolbar wiring ---- */
  function syncControls(){
    if (searchIn && searchIn.value !== state.q) { searchIn.value = state.q; }
    if (sortSel && sortSel.value !== state.sort) { sortSel.value = state.sort; }
    if (dateSel && dateSel.value !== state.date) { dateSel.value = state.date; }
    if (rangeBox) { rangeBox.hidden = (state.date !== 'custom'); }
    if (dateFrom && dateFrom.value !== state.from) { dateFrom.value = state.from; }
    if (dateTo && dateTo.value !== state.to) { dateTo.value = state.to; }
  }

  /* Search waits for a pause in typing rather than firing per keystroke. */
  if (searchIn) {
    searchIn.addEventListener('input', function(){
      var v = searchIn.value;
      if (searchTimer) { window.clearTimeout(searchTimer); }
      searchTimer = window.setTimeout(function(){
        state.q = v;
        state.page = 1;
        apply();
      }, 250);
    });
  }
  if (sortSel) { sortSel.addEventListener('change', function(){ state.sort = sortSel.value; state.page = 1; apply(); }); }
  if (dateSel) { dateSel.addEventListener('change', function(){ state.date = dateSel.value; state.page = 1; syncControls(); apply(); }); }
  if (dateFrom) { dateFrom.addEventListener('change', function(){ state.from = dateFrom.value; state.page = 1; apply(); }); }
  if (dateTo)   { dateTo.addEventListener('change', function(){ state.to = dateTo.value; state.page = 1; apply(); }); }

  /* Clears search, date and sort back to defaults. The status box is left
     alone on purpose, same as the Visitor Requests list. */
  function clearAll(){
    state.q = '';
    state.sort = 'needs_action';
    state.date = '';
    state.from = '';
    state.to = '';
    state.page = 1;
    syncControls();
    apply();
  }
  if (clearBtn) { clearBtn.addEventListener('click', clearAll); }
  /* Delegated, because a live refresh replaces the tbody's children. */
  if (tbody) {
    tbody.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('.gq-empty-clear')) { clearAll(); }
    });
  }
  if (flashX && flashX.closest) {
    flashX.addEventListener('click', function(){
      var box = flashX.closest('.gq-flash');
      if (box && box.parentNode) { box.parentNode.removeChild(box); }
    });
  }

  if (pagePrev) { pagePrev.addEventListener('click', function(){ if (state.page > 1) { state.page--; apply(); } }); }
  if (pageNext) { pageNext.addEventListener('click', function(){ state.page++; apply(); }); }

  /* ---- clickable Requested header ---- */
  function paintHeaders(){
    Array.prototype.slice.call(table.querySelectorAll('.gq-sortable')).forEach(function(th){
      var on = (state.sort === 'newest' || state.sort === 'oldest');
      var arrow = th.querySelector('.gq-sort-arrow');
      th.classList.toggle('gq-sort-active', on);
      th.setAttribute('aria-sort', on ? ((state.sort === 'oldest') ? 'ascending' : 'descending') : 'none');
      if (arrow) { arrow.textContent = on ? ((state.sort === 'oldest') ? '▲' : '▼') : '↕'; }
    });
  }
  Array.prototype.slice.call(table.querySelectorAll('.gq-sortable')).forEach(function(th){
    var run = function(){
      state.sort = (state.sort === 'newest') ? 'oldest' : 'newest';
      state.page = 1;
      syncControls();
      apply();
    };
    th.addEventListener('click', run);
    th.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); run(); }
    });
  });

  /* ---- toast ---- */
  function toast(msg, isError){
    var box = document.getElementById('gq-toasts');
    if (!box) { return; }
    var el = document.createElement('div');
    el.className = 'gq-toast' + (isError ? ' is-error' : '');
    var span = document.createElement('span');
    span.textContent = msg;
    var x = document.createElement('button');
    x.type = 'button';
    x.setAttribute('aria-label', 'Dismiss');
    x.innerHTML = '&times;';
    x.addEventListener('click', function(){ el.remove(); });
    el.appendChild(span);
    el.appendChild(x);
    box.appendChild(el);
    window.setTimeout(function(){ if (el && el.parentNode) { el.remove(); } }, 5000);
  }

  /* ---- the dialog ---- */
  function infoRow(label, value){
    var row = document.createElement('div');
    row.className = 'gq-info-row';
    var a = document.createElement('span'); a.textContent = label;
    var b = document.createElement('span'); b.textContent = (value === '' ? '—' : value);
    row.appendChild(a); row.appendChild(b);
    return row;
  }

  function formatVisitDate(value){
    var match = String(value || '').match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (!match) { return value || ''; }
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    var month = parseInt(match[2], 10);
    if (month < 1 || month > 12) { return value; }
    return months[month - 1] + ' ' + parseInt(match[3], 10) + ', ' + match[1];
  }

  function formatVisitTime(value){
    var match = String(value || '').match(/(?:^|T|\s)(\d{1,2}):(\d{2})(?::\d{2})?\s*(AM|PM)?$/i);
    if (!match) { return value || ''; }
    var hour = parseInt(match[1], 10);
    var suffix = match[3] ? match[3].toUpperCase() : (hour >= 12 ? 'PM' : 'AM');
    hour = hour % 12 || 12;
    return hour + ':' + match[2] + ' ' + suffix;
  }

  function formatRequestedOn(value){
    var text = String(value || '');
    var isoMatch = text.match(/^(\d{4}-\d{1,2}-\d{1,2})[ T](\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?)$/i);
    if (isoMatch) {
      return formatVisitDate(isoMatch[1]) + ' at ' + formatVisitTime(isoMatch[2]);
    }
    var match = text.match(/^(.*?)\s+(\d{1,2}:\d{2}(?::\d{2})?\s*[AP]M)$/i);
    return match ? match[1] + ' at ' + formatVisitTime(match[2]) : text;
  }

  function paintDialog(d){
    if (dlgTitle) { dlgTitle.textContent = d.guest || 'Guest request'; }
    if (dlgSub) {
      dlgSub.textContent = 'Requested by ' + ((d.resident || 'a resident')) + (d.house ? (' · House ' + d.house) : '');
    }
    if (dlgPill) {
      dlgPill.className = 'gq-pill gq-dlg-pill gq-pill-' + d.status;
      dlgPill.textContent = d.status.charAt(0).toUpperCase() + d.status.slice(1);
    }

    if (dlgInfo) {
      dlgInfo.innerHTML = '';
      dlgInfo.appendChild(infoRow('Name', d.guest || ''));
      dlgInfo.appendChild(infoRow('Contact', d.contact || ''));
    }
    if (dlgVisit) {
      dlgVisit.innerHTML = '';
      dlgVisit.appendChild(infoRow('Entry date', formatVisitDate(d.visit_date)));
      dlgVisit.appendChild(infoRow('Entry time', formatVisitTime(d.visit_time)));
      dlgVisit.appendChild(infoRow('Requested on', formatRequestedOn(d.requested_iso)));
    }
    if (dlgRequester) {
      dlgRequester.innerHTML = '';
      dlgRequester.appendChild(infoRow('Resident', d.resident || ''));
      dlgRequester.appendChild(infoRow('House number', d.house ? ('House ' + d.house) : ''));
    }
    if (dlgDenialSection && dlgDenial) {
      dlgDenialSection.hidden = d.status !== 'denied';
      dlgDenial.innerHTML = '';
      if (d.status === 'denied') { dlgDenial.appendChild(infoRow('Reason', d.reason || 'No reason recorded')); }
    }

    if (idFrame) {
      idFrame.innerHTML = '';
      idFrame.classList.remove('is-zoomed');
      if (d.valid_id) {
        var img = document.createElement('img');
        img.src = d.valid_id;
        img.alt = 'Uploaded valid ID for ' + (d.guest || 'the guest');
        img.addEventListener('error', function(){
          var failedSrc = img.src;
          var errorBox = document.createElement('div');
          errorBox.className = 'gq-id-error';
          var message = document.createElement('span');
          message.textContent = 'Could not load the ID image';
          var retry = document.createElement('button');
          retry.type = 'button';
          retry.className = 'btn btn-view';
          retry.textContent = 'Try again';
          retry.addEventListener('click', function(){
            errorBox.remove();
            idFrame.appendChild(img);
            img.src = failedSrc.split('#')[0] + '#gq-retry-' + Date.now();
          });
          errorBox.appendChild(message);
          errorBox.appendChild(retry);
          if (img.parentNode === idFrame) { idFrame.replaceChild(errorBox, img); }
        });
        idFrame.appendChild(img);
        if (idActs) { idActs.hidden = false; }
      } else {
        var none = document.createElement('div');
        none.className = 'gq-id-none';
        none.textContent = 'No valid ID uploaded.';
        idFrame.appendChild(none);
        if (idActs) { idActs.hidden = true; }
      }
    }

    if (dlgFoot) {
      dlgFoot.innerHTML = '';
      if (d.status === 'pending') {
        var hint = document.createElement('span');
        hint.className = 'gq-foot-hint';
        hint.textContent = 'Check the valid ID and the visit schedule before deciding.';
        var deny = document.createElement('button');
        deny.type = 'button'; deny.className = 'btn btn-reject'; deny.textContent = 'Deny';
        deny.addEventListener('click', function(){ openDeny(d.id); });
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'btn btn-approve'; ok.textContent = 'Approve';
        ok.addEventListener('click', function(){ decide(d.id, 'approve_request', ''); });
        dlgFoot.appendChild(hint); dlgFoot.appendChild(deny); dlgFoot.appendChild(ok);
      } else if (d.status === 'approved') {
        var who = document.createElement('span');
        who.className = 'gq-foot-hint';
        who.textContent = 'Approved by Admin';
        dlgFoot.appendChild(who);
        if (d.ref) {
          var qr = document.createElement('a');
          qr.className = 'btn btn-qr';
          qr.href = 'qr_view.php?code=' + encodeURIComponent(d.ref);
          qr.target = '_blank'; qr.rel = 'noopener';
          qr.innerHTML = '<i class="fa-solid fa-qrcode" aria-hidden="true"></i> View QR';
          dlgFoot.appendChild(qr);
        }
      } else {
        var why = document.createElement('span');
        why.className = 'gq-foot-reason';
        why.textContent = 'Denied: ' + (d.reason || 'no reason recorded');
        dlgFoot.appendChild(why);
      }
    }
  }

  function open(id, opener){
    var row = rowById(id);
    if (!row) { return; }
    var d = dataOf(row);
    if (!d) { return; }
    openId = id;
    /* Taken before the move, so close() can hand it back. */
    lastFocus = opener || document.activeElement;
    paintDialog(d);
    if (modal) { modal.style.display = 'flex'; }
    /* Focus goes in with the dialog, not left behind on the row. */
    var first = modal && modal.querySelector('#gq-dlg-close');
    if (first && first.focus) { first.focus(); }
  }

  function close(){
    if (modal) { modal.style.display = 'none'; }
    openId = null;
    if (idFrame) { idFrame.classList.remove('is-zoomed'); }
    closeLightbox(false);
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); lastFocus = null; }
  }

  if (dlgClose) { dlgClose.addEventListener('click', close); }
  if (modal) {
    modal.addEventListener('click', function(e){ if (e.target === modal) { close(); } });
  }
  document.addEventListener('keydown', function(e){
    if (e.key !== 'Escape') { return; }
    if (idLightbox && !idLightbox.hidden) { closeLightbox(true); return; }
    if (denyModal && denyModal.style.display === 'flex') { closeDeny(); return; }
    if (modal && modal.style.display === 'flex') { close(); }
  });

  function openLightbox(){
    var img = idFrame && idFrame.querySelector('img');
    if (!img || !idLightbox || !idLightboxImage) { return; }
    idLightboxImage.src = img.src;
    idLightboxImage.alt = img.alt;
    idLightbox.hidden = false;
    if (idLightboxClose) { idLightboxClose.focus(); }
  }
  function closeLightbox(restoreFocus){
    if (!idLightbox || idLightbox.hidden) { return; }
    idLightbox.hidden = true;
    if (idLightboxImage) { idLightboxImage.removeAttribute('src'); }
    if (restoreFocus && idZoom) { idZoom.focus(); }
  }
  if (idFrame) {
    idFrame.addEventListener('click', function(e){
      if (e.target && e.target.tagName === 'IMG') { openLightbox(); }
    });
  }
  if (idZoom) { idZoom.addEventListener('click', openLightbox); }
  if (idLightboxClose) { idLightboxClose.addEventListener('click', function(){ closeLightbox(true); }); }
  if (idLightbox) {
    idLightbox.addEventListener('click', function(e){ if (e.target === idLightbox) { closeLightbox(true); } });
  }

  /* ---- deny dialog: the reason is required ---- */
  function openDeny(id){
    var row = rowById(id);
    if (!row) { return; }
    var d = dataOf(row);
    if (!d) { return; }
    denyId = id;
    if (denyMsg) { denyMsg.textContent = 'Deny the request for ' + (d.guest || 'this guest') + '. Choose a reason.'; }
    if (denySel) { denySel.value = ''; }
    if (denyNote) { denyNote.value = ''; }
    if (denyNoteW) { denyNoteW.hidden = true; }
    if (denyErr)  { denyErr.hidden = true; }
    if (denyModal) { denyModal.style.display = 'flex'; }
    if (denySel) { denySel.focus(); }
  }
  function closeDeny(){
    if (denyModal) { denyModal.style.display = 'none'; }
    denyId = null;
  }
  function denyReason(){
    var reason = (denySel && denySel.value) ? denySel.value : '';
    if (reason !== 'Other') { return reason; }
    var extra = (denyNote && denyNote.value) ? denyNote.value.trim() : '';
    return extra ? ('Other: ' + extra) : reason;
  }
  if (denySel) {
    denySel.addEventListener('change', function(){
      if (denyNoteW) { denyNoteW.hidden = (denySel.value !== 'Other'); }
      if (denyErr) { denyErr.hidden = true; }
    });
  }
  if (denyNo) { denyNo.addEventListener('click', closeDeny); }
  if (denyModal) {
    denyModal.addEventListener('click', function(e){ if (e.target === denyModal) { closeDeny(); } });
  }
  if (denyGo) {
    denyGo.addEventListener('click', function(){
      if (!denySel || !denySel.value) {
        if (denyErr) { denyErr.hidden = false; }
        if (denySel) { denySel.focus(); }
        return;
      }
      var id = denyId;
      closeDeny();
      if (id !== null) { decide(id, 'deny_request', denyReason()); }
    });
  }

  /* ---- approve / deny without leaving the page ---- */
  function decide(id, action, reason){
    if (saving) { return; }
    var row = rowById(id);
    if (!row) { return; }
    var d = dataOf(row);
    if (!d) { return; }
    if (d.status !== 'pending') {
      toast('This request is no longer pending.', true);
      return;
    }

    var buttons = Array.prototype.slice.call(row.querySelectorAll('.gq-approve, .gq-deny'));
    if (modal) {
      Array.prototype.slice.call(dlgFoot.querySelectorAll('button, a')).forEach(function(b){
        if (!b.classList.contains('btn-qr')) { buttons.push(b); b.setAttribute('disabled', 'disabled'); }
      });
    }
    buttons.forEach(function(b){ b.classList.add('gq-busy'); b.setAttribute('disabled', 'disabled'); });
    saving = true;

    /* The existing server action is the one that decides; this page only feeds
       it the row's own visit schedule, which it requires before approving. */
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('reservation_id', String(d.id));
    body.set('redirect_page', 'resident_guest_forms');
    if (action === 'approve_request') {
      body.set('visit_date', d.visit_date || '');
      body.set('visit_time', d.visit_time || '');
    } else {
      body.set('denial_reason', reason || '');
    }

    /* The endpoint answers with a redirect back to this page, so the response
       body is the freshly rendered list. That doubles as the confirmation:
       if the row did not flip, the server refused the write. */
    fetch('admin.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function(r){ return r.text(); })
      .then(function(html){
        saving = false;
        var swapped = api.refreshFrom(html);
        var now = (rowById(id) && dataOf(rowById(id))) || null;
        var landed = now ? now.status : null;
        if (action === 'approve_request') {
          if (landed === 'approved') { toast('Guest request approved'); }
          else { toast('Could not approve this request', true); }
        } else {
          if (landed === 'denied') { toast('Guest request denied'); }
          else { toast('Could not deny this request', true); }
        }
        if (!swapped) { syncControls(); apply(); }
      })
      .catch(function(){
        saving = false;
        buttons.forEach(function(b){ b.classList.remove('gq-busy'); b.removeAttribute('disabled'); });
        toast('Could not reach the server', true);
      });
  }

  /* ---- row buttons (delegated: a refresh replaces the children) ---- */
  if (tbody) {
    tbody.addEventListener('click', function(e){
      var t = e.target;
      if (!t || !t.closest) { return; }
      var openBtn = t.closest('.gq-open, .gq-view-id');
      if (openBtn) { open(parseInt(openBtn.getAttribute('data-gq-id'), 10), openBtn); return; }
      var okBtn = t.closest('.gq-approve');
      if (okBtn) { decide(parseInt(okBtn.getAttribute('data-gq-id'), 10), 'approve_request', ''); return; }
      var noBtn = t.closest('.gq-deny');
      if (noBtn) { openDeny(parseInt(noBtn.getAttribute('data-gq-id'), 10)); return; }
    });
  }

  /* The response to an action, and to a poll-driven refetch, is the whole page,
     so its badges are already correct. They are handed to the same painter the
     shared poll uses rather than repainted here. */
  function syncBadgesFrom(doc){
    if (typeof rrdApplySidebarCounts !== 'function') { return; }
    var counts = {};
    Array.prototype.forEach.call(doc.querySelectorAll('.nav-badge[data-count]'), function(b){
      var page = b.getAttribute('data-page');
      if (page) { counts[page] = parseInt(b.getAttribute('data-count'), 10) || 0; }
    });
    if (Object.keys(counts).length) { rrdApplySidebarCounts(counts, false); }
  }
  /* ---- how many rows sit in each status right now ---- */
  function statusCounts(){
    var c = { all: 0, pending: 0, approved: 0, denied: 0 };
    rows().forEach(function(r){
      var s = attr(r, 'data-status');
      c.all++;
      if (Object.prototype.hasOwnProperty.call(c, s) && s !== 'all') { c[s]++; }
    });
    return c;
  }

  function recount(){
    var c = statusCounts();
    countEls.forEach(function(el){
      var k = el.getAttribute('data-gq-count');
      if (Object.prototype.hasOwnProperty.call(c, k)) { el.textContent = c[k]; }
    });
    boxes.forEach(function(b){
      var k = b.getAttribute('data-gq-filter');
      var lbl = b.querySelector('.gq-filter-label');
      if (!lbl) { return; }
      var wants = (k === 'pending') && c[k] > 0;
      var dot = lbl.querySelector('.gq-filter-dot');
      if (wants && !dot) {
        var s = document.createElement('span');
        s.className = 'gq-filter-dot';
        s.setAttribute('aria-hidden', 'true');
        lbl.insertBefore(s, lbl.firstChild);
      } else if (!wants && dot) {
        dot.parentNode.removeChild(dot);
      }
    });
    return c;
  }

  var api = {
    state: state,
    apply: apply,
    syncControls: syncControls,
    setStatus: setStatus,
    rows: rows,
    recount: recount,
    statusCounts: statusCounts,
    open: open,
    close: close,
    /* Swaps in a freshly rendered tbody. Only the table changes: the filters,
       sort, page and search live in this closure and are re-applied, so the
       admin never loses where they were. */
    refreshFrom: function(html){
      try {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var freshBody = doc.getElementById('gq-tbody');
        if (!freshBody) { return false; }
        tbody.innerHTML = freshBody.innerHTML;
        emptyRow = document.getElementById('gq-empty-row');
        emptyTxt  = document.getElementById('gq-empty-text');
        emptyBtn  = document.getElementById('gq-empty-clear');

        var freshCounts = doc.querySelectorAll('[data-gq-count]');
        Array.prototype.forEach.call(countEls, function(el, i){
          if (freshCounts[i]) { el.textContent = freshCounts[i].textContent; }
        });
        syncBadgesFrom(doc);
        syncControls();
        api.recount();
        apply();
        /* Let the poll forget its old signature: the next tick re-seeds it
           from the rows on screen, so no needless refetch follows. */
        if (typeof api.onRowsChanged === 'function') { api.onRowsChanged(); }
        return true;
      } catch (e) { return false; }
    }
  };

  load();
  syncControls();
  setStatus(state.status, false);
  api.recount();
  apply();

  return api;
})();
</script>

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
                  echo "<button type='button' class='btn btn-view' onclick='showReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'>View Details</button>";
                  $psTmp = strtolower($rr['payment_status'] ?? '');
                  if ($psTmp === 'rejected') { echo "<div class='muted' style='margin-top:6px;'>Wait for the updated proof.</div>"; echo "</td>"; echo "</tr>"; continue; }
                  if ($approval_status == 'pending') {
                      $disabled = !isAmenityPaymentVerified($con, $rr['ref_code'] ?? '');
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                      echo "<input type='hidden' name='redirect_page' value='reservations'>";
                      echo "<button type='submit' class='btn " . ($disabled ? "btn-disabled" : "btn-approve") . "' " . ($disabled ? "disabled title='Verify payment receipt first'" : "") . ">Approve</button>";
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
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($resident['id']) . ",\"resident\")'>View Details</button>";
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
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($visitor['id']) . ",\"visitor\")'>View Details</button>";
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
    <button type="button" class="close" onclick="closePriceDetailsModal()" aria-label="Close">×</button>
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
    <button type="button" class="close" onclick="closeReceiptModal()" aria-label="Close">×</button>
    <div style="display:flex;flex-direction:column;gap:12px;max-height:85vh;">
      <div style="overflow-y: auto; flex: 1; display: flex; align-items: center; justify-content: center;">
        <img id="receiptModalImg" alt="Receipt" style="width:100%;height:auto;border-radius:8px"/>
        <a id="receiptModalPdfLink" href="#" target="_blank" style="display:none;padding:10px 14px;border:1px solid #ddd;border-radius:8px;color:#23412e;text-decoration:none;font-weight:600;">Open Receipt (PDF)</a>
      </div>
      <form id="receiptVerifyForm" method="post" style="display:none;justify-content:center;">
        <input type="hidden" name="reservation_id" id="receiptVerifyId">
        <input type="hidden" name="action" value="verify_receipt">
        <input type="hidden" name="redirect_page" id="receiptVerifyRedirect" value="requests">
        <button type="submit" class="btn btn-approve">Verify</button>
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
    <button type="button" class="close" id="denyReasonClose" aria-label="Close">×</button>
    <h3 id="denyReasonTitle">Confirm Rejection</h3>
    <div id="denyReasonMessage" style="margin:6px 0 8px;color:#5a6b7c;font-size:0.9rem;">Are you sure you want to reject this item?</div>
    <div id="denyReasonLabel" style="font-weight:600;margin-top:6px;">Reason</div>
    <textarea id="denyReasonInput" rows="3" style="width:100%;"></textarea>
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
<?php
/* Load the visible request list once so the filter counts and the table rows
   are always computed from the same set. This is the same memoised collector
   the sidebar badge reads, so the badge cannot drift from the boxes below. */
$rrRows = collectResidentRequestRows($con);
$rrCounts = array('all' => count($rrRows), 'to_verify' => 0, 'ready' => 0, 'approved' => 0, 'rejected' => 0);
foreach ($rrRows as $rrCounted) { $rrCounts[$rrCounted['rr_key']]++; }

/* The amenity dropdown lists only what is actually in the data, in the order
   the amenities are named on the reserve page. */
$rrAmenities = array();
foreach ($rrRows as $rrSeen) {
    $rrAmenity = trim((string)($rrSeen['amenity'] ?? ''));
    if ($rrAmenity !== '' && !in_array($rrAmenity, $rrAmenities, true)) { $rrAmenities[] = $rrAmenity; }
}
natcasesort($rrAmenities);
$rrAmenities = array_values($rrAmenities);

/* "2 hours ago" beside the real timestamp. */
$rrAgo = function ($ts) {
    if (!$ts) { return ''; }
    $diff = time() - $ts;
    if ($diff < 60)    { return 'just now'; }
    if ($diff < 3600)  { $n = (int)floor($diff / 60);    return $n . ' minute' . ($n === 1 ? '' : 's') . ' ago'; }
    if ($diff < 86400) { $n = (int)floor($diff / 3600);  return $n . ' hour' . ($n === 1 ? '' : 's') . ' ago'; }
    $days = (int)floor($diff / 86400);
    if ($days < 7)     { return $days . ' day' . ($days === 1 ? '' : 's') . ' ago'; }
    if ($days < 31)    { $w = (int)floor($days / 7);    return $w . ' week' . ($w === 1 ? '' : 's') . ' ago'; }
    $mo = (int)floor($days / 30);
    return $mo . ' month' . ($mo === 1 ? '' : 's') . ' ago';
};

/* A one-time server message, e.g. "this request is no longer pending". */
$rrFlash = isset($_SESSION['flash_notice']) ? trim((string)$_SESSION['flash_notice']) : '';
unset($_SESSION['flash_notice']);
?>
<section class="panel" id="requests-panel">

  <?php if ($rrFlash !== ''): ?>
  <div class="rr-flash" role="status">
    <span><?php echo htmlspecialchars($rrFlash); ?></span>
    <button type="button" id="rr-flash-close" aria-label="Dismiss message">&times;</button>
  </div>
  <?php endif; ?>

  <!-- The five boxes are the status filter. Default is All requests. -->
  <div class="rr-filters" role="group" aria-label="Filter requests by status">
    <?php
    $rrFilterDefs = array(
        'all'       => array('All requests',    ''),
        'to_verify' => array('To verify',        'rr-filter-dot'),
        'ready'     => array('Ready to approve', 'rr-filter-dot is-ready'),
        'approved'  => array('Approved',         ''),
        'rejected'  => array('Rejected',         ''),
    );
    foreach ($rrFilterDefs as $rrFilterKey => $rrFilterDef) :
        $rrShowDot = ($rrFilterDef[1] !== '' && $rrCounts[$rrFilterKey] > 0);
    ?>
      <button type="button" class="rr-filter" data-rr-filter="<?php echo $rrFilterKey; ?>"
              aria-pressed="<?php echo ($rrFilterKey === 'all') ? 'true' : 'false'; ?>">
        <span class="rr-filter-count" data-rr-count="<?php echo $rrFilterKey; ?>"><?php echo intval($rrCounts[$rrFilterKey]); ?></span>
        <span class="rr-filter-label">
          <?php if ($rrShowDot): ?><span class="<?php echo $rrFilterDef[1]; ?>" aria-hidden="true"></span><?php endif; ?>
          <?php echo htmlspecialchars($rrFilterDef[0]); ?>
        </span>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- Sort and filter controls; search is in the top bar. -->
  <div class="rr-toolbar">
    <div class="rr-controls" id="rr-controls">
      <div class="rr-field">
        <label for="rr-sort">Sort by</label>
        <select id="rr-sort" class="rr-select">
          <option value="needs_action">Needs action first</option>
          <option value="newest">Newest submitted</option>
          <option value="oldest">Oldest submitted</option>
          <option value="visit_soon">Reservation date: soonest first</option>
          <option value="visit_late">Reservation date: latest first</option>
          <option value="resident_name">Resident name (A to Z)</option>
          <option value="downpayment_high">Payment: highest first</option>
        </select>
      </div>
      <div class="rr-field">
        <label for="rr-amenity">Amenity</label>
        <select id="rr-amenity" class="rr-select">
          <option value="">All amenities</option>
          <?php foreach ($rrAmenities as $rrAmenityOption): ?>
            <option value="<?php echo htmlspecialchars($rrAmenityOption, ENT_QUOTES); ?>"><?php echo htmlspecialchars($rrAmenityOption); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rr-field">
        <label for="rr-payment">Payment</label>
        <select id="rr-payment" class="rr-select">
          <option value="">All payments</option>
          <option value="cash">Cash downpayment</option>
          <option value="ecopoints">Paid with EcoPoints</option>
        </select>
      </div>
      <div class="rr-field rr-date-field">
        <label for="rr-date">Date</label>
        <div class="rr-date-control">
          <select id="rr-date" class="rr-select">
            <option value="">Any time</option>
            <option value="today">Today</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="custom">Custom range</option>
          </select>
          <span class="rr-date-range" id="rr-date-range" hidden>
            <input type="date" id="rr-date-from" aria-label="Submitted from">
            <span class="muted">to</span>
            <input type="date" id="rr-date-to" aria-label="Submitted to">
          </span>
        </div>
      </div>
      <button type="button" class="rr-clear" id="rr-clear" hidden>Clear filters</button>
    </div>
  </div>

  <p class="rr-result-line" id="rr-result-line" aria-live="polite"></p>

  <div class="rr-table-wrap">
    <table class="table table-rr" id="rr-table" data-vr-own-search="1">
      <thead>
        <tr>
          <th scope="col">Resident</th>
          <th scope="col">Amenity</th>
          <th scope="col">Reservation date</th>
          <th scope="col" class="rr-sortable" data-rr-sortcol="created"
              tabindex="0" aria-sort="none">Submitted<span class="rr-sort-arrow" aria-hidden="true">&#8597;</span></th>
          <th scope="col" class="rr-sortable" data-rr-sortcol="amount"
              tabindex="0" aria-sort="none">Payment<span class="rr-sort-arrow" aria-hidden="true">&#8597;</span></th>
          <th scope="col">Status</th>
          <th scope="col">Actions</th>
        </tr>
      </thead>
      <tbody id="rr-tbody">
      <?php
      foreach ($rrRows as $rr) :
          $rrId = intval($rr['id']);
          $rrName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
          if ($rrName === '') { $rrName = '—'; }
          $rrHouse = trim((string)($rr['house_number'] ?? ''));
          $rrMeta = ($rrHouse !== '') ? ('House ' . $rrHouse . ' · Resident') : 'Resident';

          $rrDownpaymentRaw = $rr['downpayment'] ?? null;
          $rrHasDownpayment = $rrDownpaymentRaw !== null && $rrDownpaymentRaw !== '' && is_numeric($rrDownpaymentRaw);
          $rrRawDownpayment = $rrHasDownpayment ? (float)$rrDownpaymentRaw : 0.0;
          $rrPointsUsed = max(0, intval($rr['points_used'] ?? 0));
          $rrHours = 0.0;
          if (!empty($rr['start_time']) && !empty($rr['end_time'])) {
              $rrHours = max(0, (strtotime((string)$rr['end_time']) - strtotime((string)$rr['start_time'])) / 3600);
          }
          $rrFinalAmount = isset($rr['price']) && $rr['price'] !== '' && is_numeric($rr['price'])
              ? max(0, (float)$rr['price'])
              : null;
          $rrFullyRedeemed = !empty($rr['use_points']) && $rrPointsUsed > 0
              && (($rrFinalAmount !== null && $rrFinalAmount <= 0) || abs($rrHours - 1) < 0.001);
          $rrPaymentType = $rrFullyRedeemed ? 'ecopoints' : ($rrHasDownpayment ? 'cash' : 'unavailable');
          $rrPaymentAmount = $rrFullyRedeemed ? 0 : $rrRawDownpayment;
          $rrDownpayment = $rrPaymentType === 'cash'
              ? '₱' . number_format($rrRawDownpayment, 2)
              : 'Payment info unavailable';
          $rrCreated = intval($rr['rr_created'] ?? 0);
          $rrSubmittedDate = $rrCreated ? date('M j, Y', $rrCreated) : '—';
          $rrSubmittedTime = $rrCreated ? date('g:i A', $rrCreated) : '';
          $rrSubmittedAgo = $rrAgo($rrCreated);
          $rrStart = trim((string)($rr['start_date'] ?? ''));
          $rrStartTs = $rrStart !== '' ? (strtotime($rrStart) ?: 0) : 0;
          $rrReservation = $rrStartTs ? date('M j, Y', $rrStartTs) : '—';
          /* The booked slot, if the reservation carries one. */
          $rrClock = function ($raw) {
              $raw = trim((string)$raw);
              if ($raw === '') { return ''; }
              $ts = strtotime($raw);
              return $ts ? date('g:i A', $ts) : '';
          };
          $rrFrom = $rrClock($rr['start_time'] ?? '');
          $rrTo = $rrClock($rr['end_time'] ?? '');
          if ($rrFrom !== '' && $rrTo !== '')      { $rrSlot = $rrFrom . ' - ' . $rrTo; }
          elseif ($rrFrom !== '' || $rrTo !== '') { $rrSlot = $rrFrom !== '' ? $rrFrom : $rrTo; }
          else                                     { $rrSlot = ''; }
          $rrRef = trim((string)($rr['ref_code'] ?? ''));
          $rrAmenityName = trim((string)($rr['amenity'] ?? ''));
          if ($rrAmenityName === '') { $rrAmenityName = '—'; }

          /* Search covers the three things the admin is given to look up. */
          $rrHaystack = strtolower(trim($rrName . ' ' . $rrRef . ' ' . $rrHouse));

          /* Tooltips carry the details that used to sit under the buttons. */
          $rrPillTitle = '';
          if ($rr['rr_key'] === 'approved' && !empty($rr['approved_by'])) {
              $rrPillTitle = 'Approved by Admin';
          } else if ($rr['rr_key'] === 'rejected') {
              $rrReason = trim((string)($rr['denial_reason'] ?? ''));
              if ($rrReason !== '') { $rrPillTitle = 'Reason: ' . $rrReason; }
          }
      ?>
        <tr data-status="<?php echo htmlspecialchars($rr['rr_key'], ENT_QUOTES); ?>"
            data-id="<?php echo $rrId; ?>"
            data-ref="<?php echo htmlspecialchars($rrRef, ENT_QUOTES); ?>"
            data-name="<?php echo htmlspecialchars(strtolower($rrName), ENT_QUOTES); ?>"
            data-amenity="<?php echo htmlspecialchars($rrAmenityName, ENT_QUOTES); ?>"
            data-created="<?php echo $rrCreated; ?>"
            data-start="<?php echo $rrStartTs; ?>"
            data-amount="<?php echo htmlspecialchars((string)$rrPaymentAmount, ENT_QUOTES); ?>"
            data-payment="<?php echo htmlspecialchars($rrPaymentType, ENT_QUOTES); ?>"
            data-search="<?php echo htmlspecialchars($rrHaystack, ENT_QUOTES); ?>">
          <td>
            <span class="rr-resident-name" tabindex="0" title="<?php echo htmlspecialchars($rrName, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($rrName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rrName); ?></span>
            <span class="rr-resident-meta" tabindex="0" title="<?php echo htmlspecialchars($rrMeta, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($rrMeta, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rrMeta); ?></span>
          </td>
          <td><span class="vr-amenity" tabindex="0" title="<?php echo htmlspecialchars($rrAmenityName, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($rrAmenityName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rrAmenityName); ?></span></td>
          <td>
            <span class="rr-res-date" title="<?php echo htmlspecialchars(trim($rrReservation . ($rrSlot !== '' ? ' ' . $rrSlot : '')), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($rrReservation); ?></span>
            <?php if ($rrSlot !== ''): ?>
              <span class="rr-when"><?php echo htmlspecialchars($rrSlot); ?></span>
            <?php endif; ?>
          </td>
          <td>
            <span class="rr-submitted-date"><?php echo htmlspecialchars($rrSubmittedDate); ?></span>
            <?php if ($rrSubmittedTime !== ''): ?><span class="rr-submitted-time"><?php echo htmlspecialchars($rrSubmittedTime); ?></span><?php endif; ?>
            <?php if ($rrSubmittedAgo !== ''): ?>
              <span class="rr-ago"><?php echo htmlspecialchars($rrSubmittedAgo); ?></span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($rrPaymentType === 'ecopoints'): ?>
              <span class="rr-payment-eco" tabindex="0"
                    title="Fully paid with VHEcoPoints. No cash downpayment or receipt needed."
                    aria-label="Paid with EcoPoints, <?php echo $rrPointsUsed; ?> points">
                <i class="fa-solid fa-leaf" aria-hidden="true"></i> Paid with EcoPoints
              </span>
              <span class="rr-payment-points"><?php echo number_format($rrPointsUsed); ?> pts</span>
            <?php elseif ($rrPaymentType === 'cash'): ?>
              <span class="rr-amount"><?php echo htmlspecialchars($rrDownpayment); ?></span>
            <?php else: ?>
              <span class="rr-resident-meta">Payment info unavailable</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="rr-pill rr-pill-<?php echo $rr['rr_key']; ?>"<?php echo ($rrPillTitle !== '' ? ' title="' . htmlspecialchars($rrPillTitle, ENT_QUOTES, 'UTF-8') . '"' : ''); ?>><?php echo htmlspecialchars($rr['rr_label']); ?></span>
          </td>
          <td class="actions">
            <button type="button" class="btn btn-view" onclick='showReservationDetails(<?php echo $rrId; ?>,"visitor")'>View Details</button>
            <?php if ($rr['rr_key'] === 'ready' && isAmenityPaymentVerified($con, $rrRef)): ?>
            <form method="post">
              <input type="hidden" name="rr_id" value="<?php echo $rrId; ?>">
              <input type="hidden" name="action" value="approve_resident_reservation">
              <input type="hidden" name="redirect_page" value="requests">
              <button type="submit" class="btn btn-approve">Approve</button>
            </form>
            <?php elseif ($rr['rr_key'] === 'approved' && $rrRef !== ''): ?>
            <a class="btn btn-qr" href="qr_view.php?code=<?php echo urlencode($rrRef); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode" aria-hidden="true"></i> View QR</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
        <tr class="rr-empty" id="rr-empty-row" style="display:none">
          <td colspan="7">
            <span id="rr-empty-text">No requests match your filters.</span>
            <button type="button" class="rr-empty-clear" id="rr-empty-clear" hidden>Clear filters</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="rr-pager" id="rr-pager" hidden>
    <span class="rr-pager-info" id="rr-pager-info"></span>
    <button type="button" class="rr-page-btn" id="rr-page-prev" aria-label="Previous page">&larr;</button>
    <span id="rr-page-numbers"></span>
    <button type="button" class="rr-page-btn" id="rr-page-next" aria-label="Next page">&rarr;</button>
  </div>

</section>
<script>
window.RR_LIST = (function(){
  var table = document.getElementById('rr-table');
  if (!table) { return null; }

  var tbody    = document.getElementById('rr-tbody');
  var emptyRow = document.getElementById('rr-empty-row');
  var emptyBtn = document.getElementById('rr-empty-clear');
  var resultEl = document.getElementById('rr-result-line');
  var pager    = document.getElementById('rr-pager');
  var pagerInf = document.getElementById('rr-pager-info');
  var pagePrev = document.getElementById('rr-page-prev');
  var pageNext = document.getElementById('rr-page-next');
  var pageNums = document.getElementById('rr-page-numbers');

  var searchIn  = document.getElementById('search-input');
  var sortSel   = document.getElementById('rr-sort');
  var amenitySel = document.getElementById('rr-amenity');
  var paymentSel = document.getElementById('rr-payment');
  var dateSel   = document.getElementById('rr-date');
  var rangeBox  = document.getElementById('rr-date-range');
  var dateFrom  = document.getElementById('rr-date-from');
  var dateTo    = document.getElementById('rr-date-to');
  var clearBtn  = document.getElementById('rr-clear');
  var flashX    = document.getElementById('rr-flash-close');

  var boxes     = Array.prototype.slice.call(document.querySelectorAll('[data-rr-filter]'));
  var countEls  = Array.prototype.slice.call(document.querySelectorAll('[data-rr-count]'));

  /* To verify, then Ready to approve, then Approved, then Rejected. That is the
     order the boxes sit in and the order "needs action first" sorts by. */
  var KEYS   = ['all', 'to_verify', 'ready', 'approved', 'rejected'];
  var RANK   = { to_verify: 0, ready: 1, approved: 2, rejected: 3 };
  var PER_PAGE = 10;
  var STORE  = 'vp_admin_rr_state';

  var state = { status: 'all', q: '', sort: 'needs_action', amenity: '', payment: '', date: '', from: '', to: '', page: 1 };
  var searchTimer = null;

  function rows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-status]')); }
  function attr(r, n){ return r.getAttribute(n) || ''; }
  function num(r, n){ var v = parseFloat(attr(r, n)); return isFinite(v) ? v : 0; }

  function save(){
    try { window.sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (err) {}
  }

  function load(){
    try {
      var raw = window.sessionStorage.getItem(STORE);
      if (!raw) { return; }
      var saved = JSON.parse(raw);
      if (!saved || typeof saved !== 'object') { return; }
      if (KEYS.indexOf(saved.status) !== -1) { state.status = saved.status; }
      if (typeof saved.q === 'string')      { state.q = saved.q; }
      if (typeof saved.sort === 'string')   { state.sort = saved.sort; }
      if (typeof saved.amenity === 'string'){ state.amenity = saved.amenity; }
      if (['', 'cash', 'ecopoints'].indexOf(saved.payment) !== -1) { state.payment = saved.payment; }
      if (typeof saved.date === 'string')   { state.date = saved.date; }
      if (typeof saved.from === 'string')   { state.from = saved.from; }
      if (typeof saved.to === 'string')     { state.to = saved.to; }
      var p = parseInt(saved.page, 10);
      if (p > 0) { state.page = p; }
    } catch (err) {}
  }

  function dayStart(){ var d = new Date(); d.setHours(0,0,0,0); return d.getTime() / 1000; }

  function passesDate(row){
    var mode = state.date;
    if (!mode) { return true; }
    var ts = num(row, 'data-created');
    if (!ts) { return false; }
    if (mode === 'today') { return ts >= dayStart(); }
    if (mode === '7' || mode === '30') {
      var days = (mode === '7') ? 7 : 30;
      return ts >= (dayStart() - ((days - 1) * 86400));
    }
    if (mode === 'custom') {
      var d = new Date(ts * 1000);
      var iso = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      if (state.from && iso < state.from) { return false; }
      if (state.to && iso > state.to) { return false; }
    }
    return true;
  }

  function visible(){
    var q = state.q.toLowerCase().trim();
    var out = [];
    rows().forEach(function(r){
      var s = attr(r, 'data-status');
      if (state.status !== 'all' && s !== state.status) { return; }
      if (state.amenity && attr(r, 'data-amenity') !== state.amenity) { return; }
      if (state.payment && attr(r, 'data-payment') !== state.payment) { return; }
      if (!passesDate(r)) { return; }
      if (q && attr(r, 'data-search').indexOf(q) === -1) { return; }
      out.push(r);
    });
    return out;
  }

  function byText(a, b){
    var x = attr(a, 'data-name'), y = attr(b, 'data-name');
    if (x === y) { return num(b, 'data-created') - num(a, 'data-created'); }
    return x < y ? -1 : 1;
  }
  function byStart(a, b){ return num(a, 'data-start') - num(b, 'data-start'); }

  var COMPARATORS = {
    needs_action: function(a, b){
      var d = (RANK[attr(a,'data-status')] !== undefined ? RANK[attr(a,'data-status')] : 9)
            - (RANK[attr(b,'data-status')] !== undefined ? RANK[attr(b,'data-status')] : 9);
      if (d !== 0) { return d; }
      return num(b, 'data-created') - num(a, 'data-created');
    },
    newest:       function(a, b){ return num(b, 'data-created') - num(a, 'data-created'); },
    oldest:       function(a, b){ return num(a, 'data-created') - num(b, 'data-created'); },
    visit_soon:   function(a, b){ return byStart(a, b); },
    visit_late:   function(a, b){ return byStart(b, a); },
    resident_name: byText,
    downpayment_high: function(a, b){ return num(b, 'data-amount') - num(a, 'data-amount'); }
  };

  function apply(){
    var list = visible();
    var cmp  = COMPARATORS[state.sort] || COMPARATORS.needs_action;
    list.sort(cmp);

    var total = rows().length;
    var pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    if (state.page > pages) { state.page = pages; }
    if (state.page < 1) { state.page = 1; }

    var from = (state.page - 1) * PER_PAGE;
    var to   = Math.min(from + PER_PAGE, list.length);

    /* Hide everything first, then show the page. The rows are reordered in the
       DOM as well, so paging and sorting read the same to a screen reader. */
    rows().forEach(function(r){ r.style.display = 'none'; });
    var pool = rows();
    var frag = document.createDocumentFragment();
    list.forEach(function(r){ frag.appendChild(r); });
    tbody.insertBefore(frag, emptyRow);
    for (var i = from; i < to; i++) { list[i].style.display = ''; }

    /* The shared header search injects its own "No results" row; drop it so this
       table always shows a single empty state. */
    Array.prototype.forEach.call(tbody.querySelectorAll('tr.search-empty'), function(r){ r.remove(); });

    var shown = Math.max(0, to - from);
    if (resultEl) {
      resultEl.textContent = 'Showing ' + list.length + ' of ' + total
        + ' request' + (total === 1 ? '' : 's');
    }
    if (emptyRow) { emptyRow.style.display = (list.length === 0) ? '' : 'none'; }
    if (emptyBtn) { emptyBtn.hidden = !(state.q.trim() || state.amenity || state.payment || state.date || state.from || state.to); }

    var anyFilter = !!(state.q.trim() || state.amenity || state.payment || state.date || state.from || state.to)
                 || state.sort !== 'needs_action';
    if (clearBtn) { clearBtn.hidden = !anyFilter; }

    paintHeaders();
    renderPager(pages, from, to, shown);
    save();
  }

  function renderPager(pages, from, to, shown){
    if (!pager) { return; }
    if (pages < 2) { pager.hidden = true; return; }
    pager.hidden = false;
    if (pagerInf) { pagerInf.textContent = 'Page ' + state.page + ' of ' + pages + ' · showing ' + shown; }
    if (pagePrev) { pagePrev.disabled = (state.page <= 1); }
    if (pageNext) { pageNext.disabled = (state.page >= pages); }
    if (!pageNums) { return; }
    pageNums.textContent = '';
    for (var p = 1; p <= pages; p++) {
      (function(page){
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'rr-page-btn';
        b.textContent = String(page);
        b.setAttribute('aria-label', 'Page ' + page);
        if (page === state.page) { b.setAttribute('aria-current', 'true'); }
        b.addEventListener('click', function(){ state.page = page; apply(); });
        pageNums.appendChild(b);
      })(p);
    }
  }

  function setStatus(key, persist){
    if (KEYS.indexOf(key) === -1) { key = 'all'; }
    state.status = key;
    state.page = 1;
    boxes.forEach(function(btn){
      btn.setAttribute('aria-pressed', btn.getAttribute('data-rr-filter') === key ? 'true' : 'false');
    });
    if (persist) { save(); }
    apply();
  }

  /* Pushes the state back into the controls after a change, a clear or a
     restore, so what is on screen is always the truth. */
  function syncControls(){
    if (searchIn) { searchIn.value = state.q; }
    if (sortSel && sortSel.value !== state.sort) { sortSel.value = state.sort; }
    if (amenitySel && amenitySel.value !== state.amenity) { amenitySel.value = state.amenity; }
    if (paymentSel && paymentSel.value !== state.payment) { paymentSel.value = state.payment; }
    if (dateSel && dateSel.value !== state.date) { dateSel.value = state.date; }
    if (rangeBox) { rangeBox.hidden = (state.date !== 'custom'); }
    if (dateFrom) { dateFrom.value = state.from; }
    if (dateTo) { dateTo.value = state.to; }
  }

  /* Clear keeps the status box, because that is the admin's place in the list,
     and puts everything else back to the default. */
  function clearAll(){
    state.q = '';
    state.sort = 'needs_action';
    state.amenity = '';
    state.payment = '';
    state.date = '';
    state.from = '';
    state.to = '';
    state.page = 1;
    syncControls();
    apply();
  }

  function paintHeaders(){
    Array.prototype.forEach.call(table.querySelectorAll('th.rr-sortable'), function(th){
      var col = th.getAttribute('data-rr-sortcol');
      var on = (col === 'created' && (state.sort === 'newest' || state.sort === 'oldest'))
            || (col === 'amount' && state.sort === 'downpayment_high');
      th.classList.toggle('rr-sort-active', on);
      var arrow = th.querySelector('.rr-sort-arrow');
      if (!arrow) { return; }
      if (!on) { th.setAttribute('aria-sort', 'none'); arrow.textContent = '↕'; return; }
      var asc = (state.sort === 'oldest') || (state.sort === 'visit_soon') || (state.sort === 'resident_name');
      th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');
      arrow.textContent = asc ? '↑' : '↓';
    });
  }

  function sortBy(col){
    if (col === 'created') {
      state.sort = (state.sort === 'newest') ? 'oldest' : 'newest';
    } else if (col === 'amount') {
      state.sort = 'downpayment_high';
    }
    state.page = 1;
    syncControls();
    apply();
  }

  var run = function(){
    var t = function(){
      state.page = 1;
      apply();
    };
    /* Typing waits a beat so a long name is not filtered letter by letter. */
    if (searchIn) {
      searchIn.addEventListener('input', function(){
        state.q = searchIn.value;
        if (searchTimer) { window.clearTimeout(searchTimer); }
        searchTimer = window.setTimeout(function(){ searchTimer = null; t(); }, 250);
      });
      searchIn.addEventListener('keydown', function(e){
        if (e.key === 'Enter') { if (searchTimer) { window.clearTimeout(searchTimer); searchTimer = null; } t(); }
      });
    }
    if (sortSel) { sortSel.addEventListener('change', function(){ state.sort = sortSel.value; t(); }); }
    if (amenitySel) { amenitySel.addEventListener('change', function(){ state.amenity = amenitySel.value; t(); }); }
    if (paymentSel) { paymentSel.addEventListener('change', function(){ state.payment = paymentSel.value; t(); }); }
    if (dateSel) {
      dateSel.addEventListener('change', function(){
        state.date = dateSel.value;
        if (state.date !== 'custom') { state.from = ''; state.to = ''; }
        syncControls();
        t();
      });
    }
    if (dateFrom) { dateFrom.addEventListener('change', function(){ state.from = dateFrom.value; t(); }); }
    if (dateTo) { dateTo.addEventListener('change', function(){ state.to = dateTo.value; t(); }); }
    if (clearBtn) { clearBtn.addEventListener('click', clearAll); }
    if (emptyBtn) { emptyBtn.addEventListener('click', clearAll); }
    if (pagePrev) { pagePrev.addEventListener('click', function(){ if (state.page > 1) { state.page--; apply(); } }); }
    if (pageNext) { pageNext.addEventListener('click', function(){ state.page++; apply(); }); }
    boxes.forEach(function(btn){
      btn.addEventListener('click', function(){ setStatus(btn.getAttribute('data-rr-filter'), true); });
    });
    Array.prototype.forEach.call(table.querySelectorAll('th.rr-sortable'), function(th){
      var go = function(){ sortBy(th.getAttribute('data-rr-sortcol')); };
      th.addEventListener('click', go);
      th.addEventListener('keydown', function(e){
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); }
      });
    });
    if (flashX) {
      flashX.addEventListener('click', function(){
        var box = flashX.closest('.rr-flash');
        if (box) { box.remove(); }
      });
    }
  };

  /* How many rows sit in each status right now. The two action boxes drive the
     sidebar badge, so this has to agree with it exactly. */
  function statusCounts(){
    var c = { all: 0, to_verify: 0, ready: 0, approved: 0, rejected: 0 };
    rows().forEach(function(r){
      var s = attr(r, 'data-status');
      c.all++;
      if (Object.prototype.hasOwnProperty.call(c, s) && s !== 'all') { c[s]++; }
    });
    return c;
  }

  function recount(){
    var c = statusCounts();
    countEls.forEach(function(el){
      var k = el.getAttribute('data-rr-count');
      if (Object.prototype.hasOwnProperty.call(c, k)) { el.textContent = c[k]; }
    });
    /* The two boxes the admin acts on carry the dot only while they have work. */
    boxes.forEach(function(b){
      var k = b.getAttribute('data-rr-filter');
      var lbl = b.querySelector('.rr-filter-label');
      if (!lbl) { return; }
      var wants = (k === 'to_verify' || k === 'ready') && c[k] > 0;
      var dot = lbl.querySelector('.rr-filter-dot');
      if (wants && !dot) {
        var s = document.createElement('span');
        s.className = (k === 'ready') ? 'rr-filter-dot is-ready' : 'rr-filter-dot';
        s.setAttribute('aria-hidden', 'true');
        lbl.insertBefore(s, lbl.firstChild);
      } else if (!wants && dot) {
        dot.parentNode.removeChild(dot);
      }
    });
    return c;
  }

  var api = {
    state: state,
    apply: apply,
    syncControls: syncControls,
    setStatus: setStatus,
    clearAll: clearAll,
    rows: rows,
    recount: recount,
    statusCounts: statusCounts,
    /* Swaps in a freshly rendered tbody. Only the table changes: the filters,
       sort, page and search live in this closure and are re-applied, so the
       admin never loses where they were. */
    refreshFrom: function(html){
      try {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var freshBody = doc.getElementById('rr-tbody');
        if (!freshBody) { return false; }
        tbody.innerHTML = freshBody.innerHTML;
        emptyRow = document.getElementById('rr-empty-row');
        emptyBtn = document.getElementById('rr-empty-clear');

        var freshCounts = doc.querySelectorAll('[data-rr-count]');
        Array.prototype.forEach.call(countEls, function(el, i){
          if (freshCounts[i]) { el.textContent = freshCounts[i].textContent; }
        });
        syncControls();
        api.recount();
        apply();
        if (typeof api.onRowsChanged === 'function') { api.onRowsChanged(); }
        return true;
      } catch (e) { return false; }
    }
  };

  load();
  run();
  syncControls();
  setStatus(state.status, false);
  api.recount();
  apply();

  return api;
})();
</script>
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
              echo '<button type="button" class="btn btn-view" onclick="showIncidentDetails(' . intval($r['id']) . ')" style="margin-right:6px;">View Details</button>';
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
              echo '<button type="submit" class="btn btn-reject">Reject</button>';
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
<?php
/* One shared read feeds the filter boxes, the table and the sidebar badge, so
   the three numbers can never drift apart. */
$vrRows = collectVisitorRequestRows($con);
$vrCounts = getVisitorRequestsCounts($con);
$vrAmenities = array();
foreach ($vrRows as $vrScan) {
    $vrAmenityLabel = trim((string)($vrScan['amenity'] ?? ''));
    if ($vrAmenityLabel !== '') { $vrAmenities[$vrAmenityLabel] = $vrAmenityLabel; }
}
ksort($vrAmenities);
?>
<section class="panel" id="visitor-requests-panel">
  <div class="content-row">
  <div class="card-box">

    <!-- The boxes ARE the status filter -->
    <div class="vr-filters" role="group" aria-label="Filter requests by status">
      <?php
      $vrFilterDefs = array(
          'all'       => array('All requests',    ''),
          'to_verify' => array('To verify',        'vr-filter-dot'),
          'ready'     => array('Ready to approve', 'vr-filter-dot is-ready'),
          'approved'  => array('Approved',         ''),
          'rejected'  => array('Rejected',         ''),
      );
      foreach ($vrFilterDefs as $vrFilterKey => $vrFilterDef) :
          $vrShowDot = ($vrFilterDef[1] !== '' && $vrCounts[$vrFilterKey] > 0);
      ?>
      <button type="button" class="vr-filter" data-vr-filter="<?php echo $vrFilterKey; ?>" aria-pressed="<?php echo ($vrFilterKey === 'all') ? 'true' : 'false'; ?>">
        <span class="vr-filter-count" data-vr-count="<?php echo $vrFilterKey; ?>"><?php echo intval($vrCounts[$vrFilterKey]); ?></span>
        <span class="vr-filter-label">
          <?php if ($vrShowDot): ?><span class="<?php echo $vrFilterDef[1]; ?>" aria-hidden="true"></span><?php endif; ?>
          <?php echo htmlspecialchars($vrFilterDef[0]); ?>
        </span>
      </button>
      <?php endforeach; ?>
    </div>

    <!-- Sort and filter bar -->
    <div class="vr-toolbar">
      <button type="button" class="vr-mobile-filters" id="vr-mobile-filters" aria-expanded="false" aria-controls="vr-controls">
        <i class="fa-solid fa-sliders" aria-hidden="true"></i> Filters
      </button>

      <div class="vr-controls" id="vr-controls">
        <div class="vr-field">
          <label for="vr-sort">Sort by</label>
          <select class="vr-select" id="vr-sort">
            <option value="needs_action">Needs action first</option>
            <option value="newest">Newest submitted</option>
            <option value="oldest">Oldest submitted</option>
            <option value="visit_soon">Visit date: soonest first</option>
            <option value="visit_late">Visit date: latest first</option>
            <option value="name">Visitor name (A to Z)</option>
            <option value="down_high">Downpayment: highest first</option>
          </select>
        </div>

        <div class="vr-field">
          <label for="vr-amenity">Amenity</label>
          <select class="vr-select" id="vr-amenity">
            <option value="">All amenities</option>
            <?php foreach ($vrAmenities as $vrAmenityOption) : ?>
            <option value="<?php echo htmlspecialchars($vrAmenityOption, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($vrAmenityOption); ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="vr-field">
          <label for="vr-date">Date</label>
          <select class="vr-select" id="vr-date">
            <option value="">Any time</option>
            <option value="today">Today</option>
            <option value="7">Last 7 days</option>
            <option value="30">Last 30 days</option>
            <option value="custom">Custom range</option>
          </select>
        </div>

        <div class="vr-date-range" id="vr-date-range" hidden>
          <label class="vr-sr-only" for="vr-date-from">Submitted from</label>
          <input type="date" id="vr-date-from">
          <span aria-hidden="true">&ndash;</span>
          <label class="vr-sr-only" for="vr-date-to">Submitted to</label>
          <input type="date" id="vr-date-to">
        </div>

        <button type="button" class="vr-clear" id="vr-clear" hidden>Clear filters</button>
      </div>
    </div>

    <p class="vr-result-line" id="vr-result-line" role="status"></p>

    <div class="vr-table-wrap">
      <table class="table table-vr" id="vr-table" data-vr-own-search="1">
      <thead>
        <tr>
          <th>Visitor</th>
          <th>Reference</th>
          <th>Amenity</th>
          <th class="vr-sortable" data-vr-sortcol="created" tabindex="0" role="button" aria-label="Sort by submitted date">Submitted<span class="vr-sort-arrow" aria-hidden="true"></span></th>
          <th class="vr-sortable" data-vr-sortcol="down" tabindex="0" role="button" aria-label="Sort by downpayment">Downpayment<span class="vr-sort-arrow" aria-hidden="true"></span></th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody id="vr-tbody">

          <?php
          foreach ($vrRows as $vr) :
              $vrId   = intval($vr['id']);
              $vrRef  = (string)($vr['ref_code'] ?? '');
              $vrName = trim(($vr['first_name'] ?? '') . ' ' . ($vr['middle_name'] ?? '') . ' ' . ($vr['last_name'] ?? ''));
              if ($vrName === '') { $vrName = '—'; }

              /* The resident being visited, when the booking recorded one. */
              $vrHost = trim((string)($vr['booked_by_name'] ?? ''));
              $vrMeta = ($vrHost !== '') ? ('Visitor · for ' . $vrHost) : 'Visitor';

              /* Search covers name, reference and the resident being visited. */
              $vrHaystack = strtolower($vrName . ' ' . $vrRef . ' ' . $vrHost);

              $vrDownpayment = ($vr['downpayment'] === null || $vr['downpayment'] === '')
                  ? '—'
                  : '₱' . number_format((float)$vr['downpayment'], 2);

              if ($vr['vr_created'] > 0) {
                  $vrSubmittedDate = date('M j, Y', $vr['vr_created']);
                  $vrSubmittedTime = date('g:i A', $vr['vr_created']);
          } else {
                  $vrSubmittedDate = '—';
                  $vrSubmittedTime = '';
              }
              $vrAgo = vpRelativeTime($vr['vr_created']);

              /* Tooltips carry the detail that used to sit under the buttons. */
              $vrPillTitle = '';
              if ($vr['vr_key'] === 'approved' && !empty($vr['approved_by'])) {
                  $vrPillTitle = 'Approved by Admin';
              } else if ($vr['vr_key'] === 'rejected') {
                  $vrReason = trim((string)($vr['denial_reason'] ?? ''));
                  if ($vrReason !== '') { $vrPillTitle = 'Reason: ' . $vrReason; }
              }
          ?>
        <tr data-status="<?php echo $vr['vr_key']; ?>"
            data-id="<?php echo $vrId; ?>"
            data-ref="<?php echo htmlspecialchars($vrRef, ENT_QUOTES, 'UTF-8'); ?>"
            data-search="<?php echo htmlspecialchars($vrHaystack, ENT_QUOTES, 'UTF-8'); ?>"
            data-created="<?php echo intval($vr['vr_created']); ?>"
            data-visit="<?php echo intval($vr['vr_visit']); ?>"
            data-down="<?php echo htmlspecialchars((string)$vr['vr_pay'], ENT_QUOTES, 'UTF-8'); ?>"
            data-name="<?php echo htmlspecialchars(strtolower($vrName), ENT_QUOTES, 'UTF-8'); ?>">
          <td>
            <span class="vr-visitor-name" tabindex="0" title="<?php echo htmlspecialchars($vrName, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($vrName, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($vrName); ?></span>
            <span class="vr-visitor-meta" tabindex="0" title="<?php echo htmlspecialchars($vrMeta, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($vrMeta, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($vrMeta); ?></span>
          </td>
          <td><span class="vr-ref"><?php echo htmlspecialchars($vrRef !== '' ? $vrRef : '—'); ?></span></td>
          <?php $vrAmenity = (string)($vr['amenity'] ?? '—'); ?>
          <td><span class="vr-amenity" tabindex="0" title="<?php echo htmlspecialchars($vrAmenity, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($vrAmenity, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($vrAmenity); ?></span></td>
          <td>
            <span class="vr-submitted-date"><?php echo htmlspecialchars($vrSubmittedDate); ?></span>
            <?php if ($vrSubmittedTime !== ''): ?><span class="vr-submitted-time"><?php echo htmlspecialchars($vrSubmittedTime); ?></span><?php endif; ?>
            <span class="vr-ago"><?php echo htmlspecialchars($vrAgo); ?></span>
          </td>
          <td><span class="vr-amount"><?php echo htmlspecialchars($vrDownpayment); ?></span></td>
          <td>
            <span class="vr-pill vr-pill-<?php echo $vr['vr_key']; ?>"<?php echo ($vrPillTitle !== '' ? ' title="' . htmlspecialchars($vrPillTitle, ENT_QUOTES, 'UTF-8') . '"' : ''); ?>><?php echo htmlspecialchars($vr['vr_label']); ?></span>
          </td>
          <td class="actions">
            <button type="button" class="btn btn-view" onclick='showReservationDetails(<?php echo $vrId; ?>,"visitor")'>View Details</button>
            <?php /* Approve only exists once the receipt has been approved. */ if ($vr['vr_key'] === 'ready' && $vr['vr_approvable']): ?>
            <form method="post">
              <input type="hidden" name="rr_id" value="<?php echo $vrId; ?>">
              <input type="hidden" name="action" value="approve_resident_reservation">
              <input type="hidden" name="redirect_page" value="visitor_requests">
              <button type="submit" class="btn btn-approve">Approve</button>
            </form>
            <?php elseif ($vr['vr_key'] === 'approved' && $vrRef !== ''): ?>
            <a class="btn btn-qr" href="qr_view.php?code=<?php echo urlencode($vrRef); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode"></i> View QR</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
        <tr class="vr-empty" id="vr-empty-row"<?php echo count($vrRows) > 0 ? ' style="display:none;"' : ''; ?>>
          <td colspan="7">
            <span id="vr-empty-text">No requests match your filters.</span>
            <br>
            <button type="button" class="vr-empty-clear" id="vr-empty-clear" hidden>Clear filters</button>
          </td>
        </tr>
        </tbody>
      </table>
    </div>

    <div class="vr-pager" id="vr-pager" hidden>
      <span class="vr-pager-info" id="vr-pager-info"></span>
      <button type="button" class="vr-page-btn" id="vr-page-prev" aria-label="Previous page">&larr;</button>
      <span id="vr-page-numbers" style="display:flex;gap:6px;flex-wrap:wrap;"></span>
      <button type="button" class="vr-page-btn" id="vr-page-next" aria-label="Next page">&rarr;</button>
    </div>

  </div>
  </div>
</section>
<script>
/* =============================================================
   Visitor Requests list: status boxes + search + amenity + date,
   then the sort, then pagination. Everything is instant and in
   place: no request ever reloads this page.
   ============================================================= */
window.VR_LIST = (function(){
  var table = document.getElementById('vr-table');
  if (!table) { return null; }

  var tbody    = document.getElementById('vr-tbody');
  var emptyRow = document.getElementById('vr-empty-row');
  var emptyTxt = document.getElementById('vr-empty-text');
  var emptyBtn = document.getElementById('vr-empty-clear');
  var resultEl = document.getElementById('vr-result-line');
  var pager    = document.getElementById('vr-pager');
  var pagerInf = document.getElementById('vr-pager-info');
  var pagePrev = document.getElementById('vr-page-prev');
  var pageNext = document.getElementById('vr-page-next');
  var pageNums = document.getElementById('vr-page-numbers');

  var sortSel  = document.getElementById('vr-sort');
  var amenSel  = document.getElementById('vr-amenity');
  var dateSel  = document.getElementById('vr-date');
  var rangeBox = document.getElementById('vr-date-range');
  var dateFrom = document.getElementById('vr-date-from');
  var dateTo   = document.getElementById('vr-date-to');
  var clearBtn = document.getElementById('vr-clear');
  var mBtn     = document.getElementById('vr-mobile-filters');
  var mPanel   = document.getElementById('vr-controls');

  var boxes = Array.prototype.slice.call(document.querySelectorAll('[data-vr-filter]'));
  var countEls = Array.prototype.slice.call(document.querySelectorAll('[data-vr-count]'));

  var KEYS   = ['all', 'to_verify', 'ready', 'approved', 'rejected'];
  var RANK   = { to_verify: 0, ready: 1, approved: 2, rejected: 3 };
  var PER_PAGE = 10;
  var STORE  = 'vp_admin_vr_state';

  var state = { status: 'all', q: '', sort: 'needs_action', amenity: '', date: '', from: '', to: '', page: 1 };
  /* The search box lives in the page header, not in the table, so it is wired up
     here instead of from markup next to the filters. */
  var searchInput = document.getElementById('search-input');
  var searchTimer = null;

  function rows(){ return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-status]')); }
  function attr(r, n){ return r.getAttribute(n) || ''; }
  function num(r, n){ var v = parseFloat(attr(r, n)); return isFinite(v) ? v : 0; }

  function save(){
    try { window.sessionStorage.setItem(STORE, JSON.stringify(state)); } catch (e) {}
  }
  function load(){
    try {
      var raw = window.sessionStorage.getItem(STORE);
      if (!raw) { return; }
      var saved = JSON.parse(raw);
      if (!saved || typeof saved !== 'object') { return; }
      if (KEYS.indexOf(saved.status) !== -1) { state.status = saved.status; }
      if (saved.sort) { state.sort = saved.sort; }
      if (typeof saved.q === 'string') { state.q = saved.q; }
      if (typeof saved.amenity === 'string') { state.amenity = saved.amenity; }
      if (typeof saved.date === 'string') { state.date = saved.date; }
      if (typeof saved.from === 'string') { state.from = saved.from; }
      if (typeof saved.to === 'string') { state.to = saved.to; }
    } catch (e) {}
  }

  /* ---- filters ---- */
  function dayStart(){ var d = new Date(); d.setHours(0,0,0,0); return d.getTime() / 1000; }
  function passesDate(row){
    var mode = state.date;
    if (!mode) { return true; }
    var ts = num(row, 'data-created');
    if (!ts) { return false; }
    if (mode === 'today') { return ts >= dayStart(); }
    if (mode === '7' || mode === '30') {
      var days = (mode === '7') ? 7 : 30;
      return ts >= (dayStart() - ((days - 1) * 86400));
    }
    if (mode === 'custom') {
      var d = new Date(ts * 1000);
      var iso = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
      if (state.from && iso < state.from) { return false; }
      if (state.to && iso > state.to) { return false; }
      return true;
    }
    return true;
  }

  function visible(){
    var q = state.q.toLowerCase().trim();
    return rows().filter(function(r){
      if (state.status !== 'all' && attr(r, 'data-status') !== state.status) { return false; }
      if (q && attr(r, 'data-search').indexOf(q) === -1) { return false; }
      if (state.amenity) {
        var cells = r.querySelectorAll('td');
        if (!cells[2] || (cells[2].textContent || '').trim() !== state.amenity) { return false; }
      }
      return passesDate(r);
    });
  }

  /* ---- sort (applied to the filtered set) ---- */
  var COMPARATORS = {
    needs_action: function(a,b){
      var d = RANK[attr(a,'data-status')] - RANK[attr(b,'data-status')];
      return d !== 0 ? d : (num(b,'data-created') - num(a,'data-created'));
    },
    newest:      function(a,b){ return num(b,'data-created') - num(a,'data-created'); },
    oldest:      function(a,b){ return num(a,'data-created') - num(b,'data-created'); },
    visit_soon:  function(a,b){ return (num(a,'data-visit')||8.64e15) - (num(b,'data-visit')||8.64e15); },
    visit_late:  function(a,b){ return (num(b,'data-visit')||8.64e15) - (num(a,'data-visit')||8.64e15); },
    name:        function(a,b){ return attr(a,'data-name').localeCompare(attr(b,'data-name')); },
    down_high:   function(a,b){ return num(b,'data-down') - num(a,'data-down'); }
  };

  /* ---- one pass: sort, show/hide, paginate, count ---- */
  function apply(){
    var list = visible();
    var cmp  = COMPARATORS[state.sort] || COMPARATORS.needs_action;
    list.sort(cmp);

    var total = rows().length;
    var pages = Math.max(1, Math.ceil(list.length / PER_PAGE));
    if (state.page > pages) { state.page = pages; }
    if (state.page < 1) { state.page = 1; }

    var from = (state.page - 1) * PER_PAGE;
    var to   = Math.min(from + PER_PAGE, list.length);

    /* Sorting an array of rows does nothing you can see: table rows are painted
       in document order, so the sorted ones have to be physically moved. Rows
       that were filtered out are parked behind them, and the empty-state row
       stays last. The pool is grabbed first, because moving the rows out of the
       tbody would leave nothing for a later query to find. */
    var pool = rows();
    var frag = document.createDocumentFragment();
    for (var i = 0; i < list.length; i++) { frag.appendChild(list[i]); }
    for (var j = 0; j < pool.length; j++) {
      if (list.indexOf(pool[j]) === -1) { frag.appendChild(pool[j]); }
    }
    if (emptyRow) { tbody.insertBefore(frag, emptyRow); } else { tbody.appendChild(frag); }

    pool.forEach(function(r){ r.style.display = 'none'; });
    for (var k = from; k < to; k++) { list[k].style.display = ''; }

    if (resultEl) {
      resultEl.textContent = 'Showing ' + list.length + ' of ' + total + ' request' + (total === 1 ? '' : 's');
    }

    var anyFilter = !!(state.q.trim() || state.amenity || state.date || state.from || state.to) || state.sort !== 'needs_action';
    if (emptyRow) { emptyRow.style.display = list.length === 0 ? '' : 'none'; }
    if (emptyTxt) {
      emptyTxt.textContent = (total === 0)
        ? 'No visitor requests yet.'
        : 'No requests match your filters.';
    }
    if (emptyBtn) { emptyBtn.hidden = !anyFilter; }
    if (clearBtn) { clearBtn.hidden = !anyFilter; }

    renderPager(pages, from, to, list.length);
    paintHeaders();
    save();
  }

  function renderPager(pages, from, to, shown){
    if (pager) { pager.hidden = pages <= 1; }
    if (pagerInf) {
      pagerInf.textContent = shown > 0 ? ('Page ' + state.page + ' of ' + pages + ' · ' + (from + 1) + '–' + to) : '';
    }
    if (pagePrev) { pagePrev.disabled = state.page <= 1; }
    if (pageNext) { pageNext.disabled = state.page >= pages; }
    if (!pageNums) { return; }
    pageNums.innerHTML = '';
    for (var p = 1; p <= pages; p++) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'vr-page-btn';
      b.textContent = String(p);
      b.setAttribute('aria-label', 'Page ' + p);
      if (p === state.page) { b.setAttribute('aria-current', 'true'); }
      b.addEventListener('click', (function(page){
        return function(){ state.page = page; apply(); window.scrollTo({ top: 0, behavior: 'smooth' }); };
      })(p));
      pageNums.appendChild(b);
    }
  }

  /* ---- status boxes ---- */
  function setStatus(key, persist){
    if (KEYS.indexOf(key) === -1) { key = 'all'; }
    state.status = key;
    boxes.forEach(function(b){
      b.setAttribute('aria-pressed', b.getAttribute('data-vr-filter') === key ? 'true' : 'false');
    });
    if (persist !== false) { state.page = 1; save(); apply(); }
  }

  boxes.forEach(function(b){
    b.addEventListener('click', function(){ setStatus(b.getAttribute('data-vr-filter')); });
  });

  /* ---- toolbar wiring ---- */
  function syncControls(){
    if (searchInput && searchInput.value !== state.q) { searchInput.value = state.q; }
    if (sortSel && sortSel.value !== state.sort) { sortSel.value = state.sort; }
    if (amenSel) {
      amenSel.value = state.amenity;
      /* A remembered amenity that no longer exists would otherwise filter the
         whole list away with no way to see why. */
      if (state.amenity && !amenSel.value) { state.amenity = ''; }
    }
    if (dateSel && dateSel.value !== state.date) { dateSel.value = state.date; }
    if (rangeBox) { rangeBox.hidden = (state.date !== 'custom'); }
    if (dateFrom && dateFrom.value !== state.from) { dateFrom.value = state.from; }
    if (dateTo && dateTo.value !== state.to) { dateTo.value = state.to; }
  }

  /* Search waits for a pause in typing rather than firing per keystroke. */
  if (searchInput) {
    searchInput.addEventListener('input', function(){
      var v = searchInput.value;
      if (searchTimer) { window.clearTimeout(searchTimer); }
      searchTimer = window.setTimeout(function(){
        state.q = v;
        state.page = 1;
        apply();
      }, 250);
    });
  }
  if (sortSel) { sortSel.addEventListener('change', function(){ state.sort = sortSel.value; state.page = 1; apply(); }); }
  if (amenSel) { amenSel.addEventListener('change', function(){ state.amenity = amenSel.value; state.page = 1; apply(); }); }
  if (dateSel) { dateSel.addEventListener('change', function(){ state.date = dateSel.value; state.page = 1; syncControls(); apply(); }); }
  if (dateFrom) { dateFrom.addEventListener('change', function(){ state.from = dateFrom.value; state.page = 1; apply(); }); }
  if (dateTo)   { dateTo.addEventListener('change', function(){ state.to = dateTo.value; state.page = 1; apply(); }); }

  /* Clears search, amenity, date and sort back to defaults. The status box is
     deliberately left alone. */
  function clearAll(){
    state.q = '';
    state.sort = 'needs_action';
    state.amenity = '';
    state.date = '';
    state.from = '';
    state.to = '';
    state.page = 1;
    syncControls();
    apply();
  }
  if (clearBtn) { clearBtn.addEventListener('click', clearAll); }
  /* Delegated, because the tbody's children get replaced by a live refresh. */
  if (tbody) {
    tbody.addEventListener('click', function(e){
      if (e.target && e.target.closest && e.target.closest('.vr-empty-clear')) { clearAll(); }
    });
  }

  if (pagePrev) { pagePrev.addEventListener('click', function(){ if (state.page > 1) { state.page--; apply(); } }); }
  if (pageNext) { pageNext.addEventListener('click', function(){ state.page++; apply(); }); }

  /* ---- clickable column headers ---- */
  function headerSortTo(col){
    if (col === 'created') { return (state.sort === 'newest') ? 'oldest' : 'newest'; }
    return 'down_high';
  }
  function paintHeaders(){
    Array.prototype.slice.call(table.querySelectorAll('.vr-sortable')).forEach(function(th){
      var col = th.getAttribute('data-vr-sortcol');
      var arrow = th.querySelector('.vr-sort-arrow');
      var on = (state.sort === 'newest' || state.sort === 'oldest') ? (col === 'created')
             : (state.sort === 'down_high') ? (col === 'down') : false;
      th.classList.toggle('vr-sort-active', !!on);
      th.setAttribute('aria-sort', on ? ((state.sort === 'oldest') ? 'ascending' : 'descending') : 'none');
      if (arrow) { arrow.textContent = on ? ((state.sort === 'oldest') ? '▲' : '▼') : '↕'; }
    });
  }
  Array.prototype.slice.call(table.querySelectorAll('.vr-sortable')).forEach(function(th){
    var run = function(){ state.sort = headerSortTo(th.getAttribute('data-vr-sortcol')); state.page = 1; syncControls(); apply(); };
    th.addEventListener('click', run);
    th.addEventListener('keydown', function(e){
      if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') { e.preventDefault(); run(); }
    });
  });

  /* ---- mobile filter panel ---- */
  if (mBtn && mPanel) {
    mBtn.addEventListener('click', function(){
      var open = mPanel.classList.toggle('is-open');
      mBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape' && mPanel.classList.contains('is-open')) {
        mPanel.classList.remove('is-open');
        mBtn.setAttribute('aria-expanded', 'false');
        mBtn.focus();
      }
    });
  }

  /* ---- public surface: used by the dialog sync and the live poll ---- */
  var api = {
    state: state,
    apply: apply,
    syncControls: syncControls,
    paintHeaders: paintHeaders,
    setStatus: setStatus,
    rows: rows,
    count: function(){ return rows().length; },
    /* Swaps in a freshly rendered tbody when the poll sees the server's total
       has moved on. Only the table changes: filters, sort, page and search all
       live in this closure and are re-applied, so nothing is lost. */
    refreshFrom: function(html){
      try {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var freshBody = doc.getElementById('vr-tbody');
        if (!freshBody) { return false; }
        tbody.innerHTML = freshBody.innerHTML;
        /* Re-point at the new empty-state nodes. */
        emptyRow = document.getElementById('vr-empty-row');
        emptyTxt  = document.getElementById('vr-empty-text');
        emptyBtn  = document.getElementById('vr-empty-clear');

        var freshCounts = doc.querySelectorAll('[data-vr-count]');
        Array.prototype.forEach.call(countEls, function(el, i){
          if (freshCounts[i]) { el.textContent = freshCounts[i].textContent; }
        });
        var freshAmenity = doc.getElementById('vr-amenity');
        if (freshAmenity && amenSel) {
          amenSel.innerHTML = freshAmenity.innerHTML;
          amenSel.value = state.amenity;
        }
        syncControls();
        api.recount();
        apply();
        return true;
      } catch (e) { return false; }
    },
    /* Recount the boxes and the result line from the rows on screen. */
    recount: function(){
      var c = statusCounts();
      countEls.forEach(function(el){
        var k = el.getAttribute('data-vr-count');
        if (Object.prototype.hasOwnProperty.call(c, k)) { el.textContent = c[k]; }
      });
      boxes.forEach(function(b){
        var k = b.getAttribute('data-vr-filter');
        var lbl = b.querySelector('.vr-filter-label');
        if (!lbl) { return; }
        var wants = (k === 'to_verify' || k === 'ready') && c[k] > 0;
        var dot = lbl.querySelector('.vr-filter-dot');
        if (wants && !dot) {
          var s = document.createElement('span');
          s.className = (k === 'ready') ? 'vr-filter-dot is-ready' : 'vr-filter-dot';
          s.setAttribute('aria-hidden', 'true');
          lbl.insertBefore(s, lbl.firstChild);
        } else if (!wants && dot) {
          dot.parentNode.removeChild(dot);
        }
      });
      return c;
    },
    /* The same tally, without touching the DOM. The poll uses it to work out
       whether the server has drifted from what is on screen. */
    statusCounts: statusCounts
  };

  /* ---- how many rows sit in each status right now ---- */
  function statusCounts(){
    var c = { all: 0, to_verify: 0, ready: 0, approved: 0, rejected: 0 };
    rows().forEach(function(r){
      var s = attr(r, 'data-status');
      c.all++;
      if (Object.prototype.hasOwnProperty.call(c, s) && s !== 'all') { c[s]++; }
    });
    return c;
  }

  load();
  syncControls();
  setStatus(state.status, false);
  api.recount();
  apply();

  return api;
})();
</script>
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
    <button type="button" class="close" onclick="closeVisitorModal()" aria-label="Close">×</button>
    <h3>Visitor Details</h3>
    <div id="visitorDetailsContent">
      <!-- Content will be loaded here -->
    </div>
  </div>
</div>

<!-- Incident Proof Modal -->
<div id="incidentProofModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeIncidentProofModal()" aria-label="Close">×</button>
    <div style="overflow-y: auto; flex: 1; display: flex; align-items: center; justify-content: center;">
      <img id="incidentProofImg" src="" alt="Proof" />
    </div>
  </div>
</div>

<div id="incidentDetailsModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeIncidentDetailsModal()" aria-label="Close">×</button>
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
    
    // Request lists keep their page-specific shared search placeholder.
    const searchInput = document.getElementById('search-input');
    if(searchInput && searchInput.getAttribute('data-list-search') !== '1'){
      searchInput.placeholder = `Search ${pageTitle}...`;
    }
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
  const pr = String(birthdateStr).split('-');
  if(pr.length !== 3) return '';
  const y = parseInt(pr[0],10), mo = parseInt(pr[1],10), da = parseInt(pr[2],10);
  if(isNaN(y)||isNaN(mo)||isNaN(da)) return '';
  const today = new Date();
  let age = today.getFullYear() - y;
  const m = (today.getMonth()+1) - mo;
  if (m < 0 || (m === 0 && today.getDate() < da)) age--;
  if (age < 0) age = 0;
  return age;
}
function formatBirthdateWithAge(birthdateStr){
  if(!birthdateStr) return '';
  const dateLabel = fmtDate(birthdateStr);
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
              <div class="section-title">Request Status</div>
              <div class="info-grid">
                <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
                ${details.entry_created ? `<div class="info-row"><span class="info-label">Request Submitted</span><span class="info-value">${fmtRequestSubmitted(details.entry_created)}</span></div>` : ''}
              </div>
            </div>
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
                ${visitDateVal ? `<div class="info-row"><span class="info-label">Date</span><span class="info-value">${fmtDate(visitDateVal)}${visitEndDateVal ? ' - ' + fmtDate(visitEndDateVal) : ''}</span></div>` : ''}
                ${(visitStartTimeVal || visitEndTimeVal) ? `<div class="info-row"><span class="info-label">Time</span><span class="info-value">${fmtTime(visitStartTimeVal)}${visitEndTimeVal ? ' - ' + fmtTime(visitEndTimeVal) : ''}</span></div>` : ''}
                ${details.amenity && details.amenity !== 'Guest Entry' ? `<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${details.amenity}</span></div>` : ''}
                ${priceBlock}
              </div>
            </div>
            ` : ''}
            ${isGuestEntry ? `
            <div>
              <div class="section-title">Guest Entry Schedule</div>
              <div class="info-grid">
                ${visitDateVal ? `<div class="info-row"><span class="info-label">Guest Entry Date</span><span class="info-value">${fmtDate(visitDateVal)}</span></div>` : ''}
                ${visitStartTimeVal ? `<div class="info-row"><span class="info-label">Guest Entry Time</span><span class="info-value">${fmtTime(visitStartTimeVal)}</span></div>` : ''}
              </div>
            </div>
            ` : ''}
          </div>
          `
          : `
          <div class="request-details">
            ${statusBadge}
            <div>
              <div class="section-title">Request Status</div>
              <div class="info-grid">
                <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
                ${details.entry_created ? `<div class="info-row"><span class="info-label">Request Submitted</span><span class="info-value">${fmtRequestSubmitted(details.entry_created)}</span></div>` : ''}
              </div>
            </div>
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
                ${visitDateVal ? `<div class="info-row"><span class="info-label">${isGuestEntry ? 'Guest Entry Date' : 'Date'}</span><span class="info-value">${fmtDate(visitDateVal)}${visitEndDateVal ? ' - ' + fmtDate(visitEndDateVal) : ''}</span></div>` : ''}
                ${(visitStartTimeVal || visitEndTimeVal) ? `<div class="info-row"><span class="info-label">${isGuestEntry ? 'Guest Entry Time' : 'Time'}</span><span class="info-value">${fmtTime(visitStartTimeVal)}${visitEndTimeVal ? ' - ' + fmtTime(visitEndTimeVal) : ''}</span></div>` : ''}
                ${details.persons ? `<div class="info-row"><span class="info-label">No. of Persons</span><span class="info-value">${details.persons}</span></div>` : ''}
                ${details.purpose ? `<div class="info-row"><span class="info-label">Purpose of Visit</span><span class="info-value">${details.purpose}</span></div>` : ''}
                ${priceBlock}
              </div>
            </div>
          </div>
          `;
        contentEl.innerHTML = content;
        const receiptUrl = String(details.receipt_url || '').trim();
        if (!isGuestEntry && receiptUrl) {
          const proofSection = document.createElement('div');
          proofSection.className = 'details-section';
          const proofHeading = document.createElement('h4');
          proofHeading.textContent = 'Proof of Payment';
          proofSection.appendChild(proofHeading);

          const proofLink = document.createElement('a');
          proofLink.href = receiptUrl;
          proofLink.target = '_blank';
          proofLink.rel = 'noopener';
          if (/\.pdf$/i.test(receiptUrl)) {
            proofLink.textContent = 'Open uploaded proof (PDF)';
          } else {
            const proofImage = document.createElement('img');
            proofImage.src = receiptUrl;
            proofImage.alt = 'Uploaded proof of payment';
            proofImage.style.maxWidth = '100%';
            proofImage.style.height = 'auto';
            proofLink.appendChild(proofImage);
          }
          proofSection.appendChild(proofLink);
          contentEl.appendChild(proofSection);
        }
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
<div id="reservationModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="rrdName">
  <div class="modal-content rrd-dialog">
    <div class="rrd-head">
      <div class="rrd-head-main">
        <h3 class="rrd-name" id="rrdName">Reservation Details</h3>
        <div class="rrd-sub" id="rrdSub"></div>
      </div>
      <span class="rrd-pill" id="rrdPill" hidden></span>
      <button type="button" class="close" onclick="closeReservationModal()" aria-label="Close reservation details">&times;</button>
    </div>

    <div class="rrd-steps" id="rrdSteps" aria-hidden="true"></div>

    <div id="reservationDetailsContent" class="rrd-scroll">
      <div class="rrd-left">
        <div class="rrd-receipt" id="rrdReceipt">
          <div class="rrd-receipt-state" id="rrdReceiptState">
            <i class="fa-solid fa-spinner fa-spin"></i>
            <span>Loading receipt&hellip;</span>
          </div>
        </div>
        <div class="rrd-receipt-caption" id="rrdReceiptCaption"></div>
        <div class="rrd-receipt-tools">
          <button type="button" class="btn btn-light" id="rrdZoomBtn" onclick="rrdOpenZoom()" hidden>
            <i class="fa-solid fa-magnifying-glass-plus"></i> Zoom
          </button>
          <a class="btn btn-light" id="rrdDownloadBtn" download hidden>
            <i class="fa-solid fa-download"></i> Download
          </a>
        </div>
      </div>
      <div class="rrd-right" id="rrdRight"></div>
    </div>

    <div class="rrd-foot">
      <div class="rrd-warn" id="rrdFootWarn" hidden></div>

      <div class="rrd-reject" id="rrdReject" hidden>
        <label class="rrd-reject-label" for="rrdRejectSelect">Reason for rejection</label>
        <select id="rrdRejectSelect">
          <option value="">Select a reason&hellip;</option>
          <option value="Amount does not match">Amount does not match</option>
          <option value="Receipt is unclear">Receipt is unclear</option>
          <option value="Reference number already used">Reference number already used</option>
          <option value="Other">Other</option>
        </select>
        <div class="rrd-reject-btns">
          <button type="button" class="btn btn-danger" id="rrdRejectConfirm" disabled>
            <i class="fa-solid fa-xmark"></i> Confirm rejection
          </button>
          <button type="button" class="btn btn-light" onclick="rrdToggleReject(false)">Cancel</button>
        </div>
      </div>

      <div class="rrd-foot-row">
        <div class="rrd-foot-hint" id="rrdHint"></div>
        <div class="rrd-foot-btns" id="rrdActions"></div>
      </div>
    </div>

    <div class="rrd-zoom" id="rrdZoom" hidden onclick="if(event.target===this)rrdOpenZoom(false)">
      <button type="button" class="rrd-zoom-close" onclick="rrdOpenZoom(false)" aria-label="Close zoomed receipt">&times;</button>
      <img id="rrdZoomImg" alt="Zoomed proof of payment">
    </div>
  </div>
</div>

<script>
function amenityHourlyRate(amenityName, accountType, userType){
  const a = String(amenityName||'').toLowerCase();
  const rateType = String(accountType||userType||'').toLowerCase();
  const isResidentBooking = rateType === 'resident';
  if(a.indexOf('basketball') !== -1) return isResidentBooking ? 100 : 150;
  if(a.indexOf('clubhouse') !== -1) return isResidentBooking ? 300 : 450;
  if(a.indexOf('multi') !== -1 || a.indexOf('purpose') !== -1) return isResidentBooking ? 200 : 300;
  if(a.indexOf('tennis') !== -1) return isResidentBooking ? 100 : 150;
  return 0;
}
function durationHours(startTimeRaw, endTimeRaw){
  const sc = String(startTimeRaw||'').split(':');
  const ec = String(endTimeRaw||'').split(':');
  if(sc.length < 2 || ec.length < 2) return 0;
  const sh = parseInt(sc[0],10) || 0, sm = parseInt(sc[1],10) || 0;
  const eh = parseInt(ec[0],10) || 0, em = parseInt(ec[1],10) || 0;
  return Math.max(0, ((eh*60+em) - (sh*60+sm)) / 60);
}
function fmtMoney(n){ return '₱' + (Number(n)||0).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2}); }
function fmtNum(n){ return (Number(n)||0).toLocaleString(); }
function buildReservationPriceBlock(details){
  const total = parseFloat(details.price);
  if (!Number.isFinite(total)) return '';
  const requiredDownpayment = Math.max(0, total * 0.5);
  const hasDownpayment = details.downpayment !== null && details.downpayment !== undefined && details.downpayment !== '';
  const paidDownpayment = hasDownpayment ? (parseFloat(details.downpayment) || 0) : requiredDownpayment;
  const remainingBalance = Math.max(0, total - paidDownpayment);
  const paymentStatus = String(details.payment_status || 'pending').toLowerCase();
  const paymentLabel = paymentStatus === 'verified' ? 'Verified' : (paymentStatus === 'rejected' ? 'Rejected' : 'Submitted - Awaiting Verification');
  return `<div class="price-section">
    <div class="info-row total-price"><span class="info-label">Total Price</span><span class="info-value">${fmtMoney(total)}</span></div>
    <div class="info-row price-down"><span class="info-label">Required Downpayment</span><span class="info-value">${fmtMoney(requiredDownpayment)}</span></div>
    <div class="info-row price-down"><span class="info-label">Downpayment Paid</span><span class="info-value">${fmtMoney(paidDownpayment)}</span></div>
    <div class="info-row price-balance"><span class="info-label">Remaining Balance</span><span class="info-value">${fmtMoney(remainingBalance)}</span></div>
    <div class="info-row"><span class="info-label">Payment Status</span><span class="info-value">${paymentLabel}</span></div>
  </div>`;
}
/* =============================================================
   RESERVATION DETAILS DIALOG
   To verify -> Ready to approve -> Approved, plus read-only Rejected.
   Actions POST to the existing handlers and re-read the record, so the
   resident list behind the dialog is patched in place (filters, search and
   scroll position all survive) and the dialog never reloads the page.
   ============================================================= */
var RRD = { id: null, d: null, key: '', amt: null, expDate: '', busy: false, lastFocus: null, lastRowId: null, redirectPage: 'requests', zoomOpen: false };
var RRD_LABELS = { to_verify: 'To verify', ready: 'Ready to approve', approved: 'Approved', rejected: 'Rejected' };

function rrdEsc(v){
  return String(v == null ? '' : v).replace(/[&<>"']/g, function(c){
    return ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' })[c];
  });
}

function rrdStatusOf(d){
  var ps = String(d.payment_status || 'pending').toLowerCase();
  var ap = String(d.approval_status || 'pending').toLowerCase();
  var st = String(d.status || '').toLowerCase();
  if (ps === 'rejected' || ap.indexOf('denied') > -1 || ap.indexOf('reject') > -1 || ap.indexOf('cancel') > -1) { return 'rejected'; }
  if (ap.indexOf('approv') > -1 || st === 'approved') { return 'approved'; }
  if (ps === 'verified') { return 'ready'; }
  return 'to_verify';
}

function rrdAmounts(d){
  var hours = durationHours(d.start_time, d.end_time);
  var rate  = amenityHourlyRate(d.amenity, d.account_type, d.user_type) || 0;
  var pts   = (parseInt(d.use_points, 10) === 1) ? (parseInt(d.points_used, 10) || 0) : 0;
  var price = parseFloat(d.price);
  var havePrice = isFinite(price) && price > 0;
  /* d.price is what the resident was actually quoted, so it is the total and
     wins whenever it is present. The rate table is only used to split out the
     one-free-hour VHEcoPoint benefit, and only when the modelled result agrees
     with the stored price. */
  var fullyRedeemed = pts > 0 && Math.abs(hours - 1) < 0.001;
  var amountKnown = havePrice || fullyRedeemed || (rate > 0 && hours > 0);
  var finalAmt = havePrice ? price : (Math.max(0, hours - (pts > 0 ? 1 : 0)) * rate);
  if (fullyRedeemed) { finalAmt = 0; }
  var original = finalAmt, discount = 0, split = false;
  if (pts > 0 && rate > 0) {
    var modelled = Math.max(0, hours - 1) * rate;
    if (Math.abs(modelled - finalAmt) < 0.005) { original = finalAmt + rate; discount = rate; split = true; }
  }
  var stored = parseFloat(d.downpayment);
  var required = (isFinite(stored) && stored > 0) ? stored : finalAmt * 0.5;
  var remaining = Math.round(Math.max(0, finalAmt - required) * 100) / 100;
  return { hours: hours, rate: rate, points: pts, original: original, discount: discount,
           final: finalAmt, required: required, remaining: remaining, known: amountKnown,
           split: split, redeemed: fullyRedeemed };
}

/* The request is the only dated record the system holds, so it is the honest
   "expected" payment date. The resident's own date is read off the receipt. */
function rrdExpectedDate(d){
  var raw = String(d.created_at || '').trim();
  if (!raw) { return ''; }
  var dt = new Date(raw.replace(' ', 'T'));
  if (isNaN(dt.getTime())) { return ''; }
  return dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0') + '-' + String(dt.getDate()).padStart(2, '0');
}
function rrdSlashDate(iso){
  if (!iso) { return ''; }
  var p = String(iso).split('-');
  return p.length === 3 ? p[1] + '/' + p[2] + '/' + p[0] : iso;
}

function rrdCard(title, body){
  return '<div class="rrd-card"><h4 class="rrd-card-title">' + rrdEsc(title) + '</h4><div class="rrd-card-body">' + body + '</div></div>';
}
function rrdRow(label, value, isKey, extraClass){
  if (value === '' || value === null || value === undefined) { return ''; }
  return '<div class="rrd-kv' + (isKey ? ' is-key' : '') + (extraClass ? ' ' + extraClass : '') + '"><span class="rrd-k">' + rrdEsc(label) + '</span><span class="rrd-v">' + rrdEsc(value) + '</span></div>';
}

/* ---------- receipt column ---------- */
function rrdRenderReceipt(d){
  var box = document.getElementById('rrdReceipt');
  var state = document.getElementById('rrdReceiptState');
  var cap = document.getElementById('rrdReceiptCaption');
  var zbtn = document.getElementById('rrdZoomBtn');
  var dbtn = document.getElementById('rrdDownloadBtn');
  var url = String(d.receipt_url || d.receipt_path || '').trim();
  var old = box.querySelector('img');
  if (old) { old.parentNode.removeChild(old); }
  zbtn.hidden = true;
  dbtn.hidden = true;
  dbtn.removeAttribute('href');
  state.hidden = false;
  state.className = 'rrd-receipt-state';
  cap.textContent = '';

  if (!url) {
    var fully = (RRD.amt && RRD.amt.points > 0 && RRD.amt.required <= 0);
    state.innerHTML = '<i class="fa-solid fa-file-circle-xmark"></i><span>' +
      (fully ? 'No receipt required. This reservation was fully covered by the VHEcoPoint redemption.'
             : (d.receipt_uploaded_at ? 'Payment submitted, but the receipt file is unavailable.'
                                      : 'No receipt was uploaded for this request.')) + '</span>';
    return;
  }
  if (/\.pdf$/i.test(url)) {
    state.innerHTML = '<i class="fa-solid fa-file-pdf"></i><span>This proof of payment is a PDF.<br>Download it to review, then compare it with the receipt check.</span>';
    dbtn.setAttribute('href', url);
    dbtn.setAttribute('download', '');
    dbtn.hidden = false;
    return;
  }

  state.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Loading receipt&hellip;</span>';
  var img = document.createElement('img');
  img.alt = 'Proof of payment';
  img.onload = function(){
    state.hidden = true;
    zbtn.hidden = false;
    dbtn.setAttribute('href', url);
    dbtn.setAttribute('download', '');
    dbtn.hidden = false;
  };
  img.onerror = function(){
    state.className = 'rrd-receipt-state is-error';
    state.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>The receipt image could not be loaded.</span>';
    cap.textContent = 'Download the file to check whether it still exists on the server.';
    dbtn.setAttribute('href', url);
    dbtn.setAttribute('download', '');
    dbtn.hidden = false;
  };
  img.onclick = function(){ rrdOpenZoom(true); };
  img.src = url;
  box.appendChild(img);
}

/* ---------- detail column ---------- */
function rrdRenderRight(d, meta){
  var a = RRD.amt;
  var out = '';

  var payment = '';
  if (a.split) {
    payment += rrdRow('Original amount', fmtMoney(a.original));
    payment += rrdRow('VHEcoPoint discount', '-' + fmtMoney(a.discount) + ' (' + fmtNum(a.points) + ' pts)');
    payment += rrdRow('Final amount', fmtMoney(a.final));
  } else {
    payment += rrdRow('Total price', fmtMoney(a.final));
  }
  payment += rrdRow('Required downpayment', fmtMoney(a.required), true);
  var balanceValue = !a.known ? 'Balance information unavailable' : (a.remaining > 0 ? fmtMoney(a.remaining) : '₱0.00');
  payment += rrdRow('Remaining balance', balanceValue, false, 'is-balance');
  if (a.known && a.remaining <= 0) {
    payment += '<p class="rrd-balance-note">No balance due</p>';
  } else if (a.known && RRD.key !== 'rejected') {
    payment += '<p class="rrd-balance-note"><span>To be paid on site</span><button type="button" class="rrd-balance-info" aria-label="How the remaining balance is paid" aria-describedby="rrdBalanceTooltip"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span class="rrd-balance-tooltip" role="tooltip" id="rrdBalanceTooltip">The resident pays this amount at the venue on the day of the reservation. It is not part of the receipt check.</span></button></p>';
  }
  if (a.redeemed) {
    payment += '<div class="rrd-note" style="margin-top:8px;"><i class="fa-solid fa-circle-info"></i><span>Fully redeemed with VHEcoPoints, so there is no cash downpayment to compare against.</span></div>';
  } else if (a.points > 0 && !a.split) {
    payment += '<div class="rrd-note" style="margin-top:8px;"><i class="fa-solid fa-circle-info"></i><span>The VHEcoPoint rate for this amenity does not match the recorded price, so the total is taken from the recorded price. Check it before verifying.</span></div>';
  }
  out += rrdCard('Payment breakdown', payment);

  var res = '';
  res += rrdRow('Reference', d.ref_code);
  res += rrdRow('Reserved by', meta.reservedBy);
  res += rrdRow('Starts', [fmtDate(d.start_date), fmtTime(d.start_time)].filter(Boolean).join(' at '));
  res += rrdRow('Ends', [fmtDate(d.end_date), fmtTime(d.end_time)].filter(Boolean).join(' at '));
  res += rrdRow('Duration', fmtDuration(d.start_time, d.end_time));
  res += rrdRow('Persons', d.persons);
  out += rrdCard('Reservation details', res);

  var who = '';
  who += rrdRow('Name', meta.displayName);
  if (!meta.isResidentGuest) { who += rrdRow('House no.', d.house_number); }
  who += rrdRow('Email', meta.displayEmail);
  who += rrdRow('Phone', meta.displayPhone);
  out += rrdCard(meta.isResidentGuest ? 'Guest information' : 'Resident information', who);
  if (meta.isResidentGuest) {
    var res2 = rrdRow('Resident', meta.residentName) + rrdRow('House no.', d.house_number) +
               rrdRow('Email', d.email) + rrdRow('Phone', d.phone);
    if (res2) { out += rrdCard('Resident information', res2); }
  }

  if (a.points > 0) {
    var eco = rrdRow('EcoPoints used', fmtNum(a.points) + ' pts');
    if (a.split) { eco += rrdRow('Benefit', '1 free hour') + rrdRow('Discount', fmtMoney(a.discount)); }
    eco += rrdRow('Redemption status', a.redeemed ? 'Fully redeemed' : 'Partially redeemed') +
           '<div class="rrd-note" style="margin-top:8px;"><i class="fa-solid fa-lock"></i><span>Read-only. Confirm the points here, then record the redemption in VHEcoPoint.</span></div>';
    out += rrdCard('VHEcoPoint redemption', eco);
  }

  if (RRD.key === 'to_verify') {
    out += rrdCard('Receipt check',
      '<table class="rrd-check"><thead><tr><th>Item</th><th>Expected</th><th>On receipt</th><th>Match</th></tr></thead><tbody>' +
      '<tr><td class="rrd-k">Downpayment</td><td class="rrd-v">' + rrdEsc(fmtMoney(a.required)) + '</td>' +
        '<td><input class="rrd-in" type="number" step="0.01" min="0" id="rrdInAmount" placeholder="0.00" aria-label="Downpayment amount on receipt"></td>' +
        '<td><span class="rrd-flag wait" id="rrdFlagAmount">Enter</span></td></tr>' +
      '<tr><td class="rrd-k">Payment date</td><td class="rrd-v">' + rrdEsc(rrdSlashDate(RRD.expDate) || 'Unknown') + '</td>' +
        '<td><input class="rrd-in" type="date" id="rrdInDate" aria-label="Payment date on receipt"></td>' +
        '<td><span class="rrd-flag wait" id="rrdFlagDate">Enter</span></td></tr>' +
      '</tbody></table>' +
      '<div class="rrd-note" style="margin-top:10px;"><i class="fa-solid fa-circle-info"></i><span>Read the amount and date off the receipt, then enter them here. A mismatch is a warning to check, not a block on verifying.</span></div>');
  }

  if (RRD.key === 'rejected') {
    var reason = String(d.denial_reason || '').trim();
    var att = parseInt(d.receipt_attempts || 0, 10);
    out += rrdCard('Rejection',
      rrdRow('Reason', reason || 'Not recorded') +
      rrdRow('Attempts used', att + ' of 3') +
      '<div class="rrd-note" style="margin-top:8px;"><i class="fa-solid fa-rotate-left"></i><span>Waiting for the resident to upload a new receipt. The status returns to To verify on their next submission.</span></div>');
  }

  document.getElementById('rrdRight').innerHTML = out;
}

/* ---------- footer ---------- */
function rrdRenderFoot(d, att){
  var btns = document.getElementById('rrdActions');
  var hint = document.getElementById('rrdHint');
  var warn = document.getElementById('rrdFootWarn');
  var sel = document.getElementById('rrdRejectSelect');
  var conf = document.getElementById('rrdRejectConfirm');
  rrdToggleReject(false);
  sel.value = '';
  conf.disabled = true;
  warn.hidden = true;
  warn.innerHTML = '';
  var html = '';

  /* The visitor flow talks about receipts and visits. The resident and
     resident-guest flows keep their original payment/request wording. */
  var isVisitor = (RRD.redirectPage === 'visitor_requests');

  if (RRD.key === 'to_verify') {
    hint.className = 'rrd-foot-hint';
    hint.textContent = isVisitor
      ? 'Compare the receipt with the breakdown, then approve or reject the receipt.'
      : 'Compare the receipt with the breakdown, then verify or reject.';
    var rejectLbl = isVisitor ? 'Reject receipt' : 'Reject payment';
    var verifyLbl = isVisitor ? 'Approve receipt' : 'Verify payment';
    if (att < 3) {
      html += '<button type="button" class="btn rrd-btn-danger-ghost" onclick="rrdToggleReject()"><i class="fa-solid fa-xmark"></i> ' + rejectLbl + '</button>';
    }
    html += '<button type="button" class="btn btn-approve" onclick="rrdDoVerify()"><i class="fa-solid fa-check"></i> ' + verifyLbl + '</button>';
  } else if (RRD.key === 'ready') {
    hint.className = 'rrd-foot-hint is-ok';
    hint.innerHTML = isVisitor
      ? '<i class="fa-solid fa-circle-check"></i> Receipt approved. You can now approve this visit.'
      : '<i class="fa-solid fa-circle-check"></i> Payment verified. You can now approve this request.';
    html += '<button type="button" class="btn btn-approve" onclick="rrdDoApprove()"><i class="fa-solid fa-check"></i> ' + (isVisitor ? 'Approve' : 'Approve request') + '</button>';
  } else if (RRD.key === 'approved') {
    hint.className = 'rrd-foot-hint is-ok';
    hint.innerHTML = '<i class="fa-solid fa-circle-check"></i> Approved by Admin. The QR pass is ready.';
    if (d.ref_code) {
      html += '<a class="btn btn-qr" href="qr_view.php?code=' + encodeURIComponent(d.ref_code) + '" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode"></i> View QR</a>';
    }
  } else {
    var reason = String(d.denial_reason || '').trim();
    hint.className = 'rrd-foot-hint is-bad';
    hint.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Rejected' + (reason ? ' — ' + rrdEsc(reason) : '') +
      (att >= 3 ? ' (3 attempts reached)' : '') + '. Nothing to do until a new receipt is uploaded.';
  }
  btns.innerHTML = html;
}

/* ---------- master render ---------- */
function rrdRender(d){
  RRD.d = d;
  RRD.id = parseInt(d.id, 10) || null;
  RRD.key = rrdStatusOf(d);
  RRD.amt = rrdAmounts(d);
  RRD.expDate = rrdExpectedDate(d);

  var isResidentGuest = !!d.gf_id;
  var userType = String(d.user_type || 'resident').toLowerCase();
  RRD.redirectPage = isResidentGuest ? 'resident_guest_forms' : (userType === 'visitor' ? 'visitor_requests' : 'requests');

  var residentName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
  var guestName = [d.guest_first_name||'', d.guest_middle_name||'', d.guest_last_name||''].join(' ').replace(/\s+/g,' ').trim();
  var displayName = isResidentGuest ? (guestName || "Resident's Guest") : (residentName || 'Resident');
  var role = isResidentGuest ? "Resident's Guest" : (userType === 'visitor' ? 'Visitor' : 'Resident');
  var att = parseInt(d.receipt_attempts || 0, 10);

  document.getElementById('rrdName').textContent = displayName;
  var sub = [];
  if (d.ref_code) { sub.push('<span>Ref <b>' + rrdEsc(d.ref_code) + '</b></span>'); }
  sub.push('<span>' + rrdEsc(d.amenity || 'Amenity') + '</span>');
  sub.push('<span>' + rrdEsc(role) + '</span>');
  if (d.created_at) { sub.push('<span>Submitted ' + rrdEsc(fmtSubmittedOn(d.created_at)) + '</span>'); }
  if (att > 0 && RRD.key === 'to_verify') { sub.push('<span>Receipt attempt ' + (att + 1) + ' of 3</span>'); }
  document.getElementById('rrdSub').innerHTML = sub.join('');

  var pill = document.getElementById('rrdPill');
  pill.className = 'rrd-pill k-' + RRD.key;
  pill.textContent = RRD_LABELS[RRD.key];
  pill.hidden = false;

  var stepState = (RRD.key === 'approved') ? ['is-done','is-done']
                : (RRD.key === 'ready') ? ['is-done','is-current'] : ['is-current','is-muted'];
  function step(cls, n, label){
    return '<div class="rrd-step ' + cls + '"><span class="rrd-step-dot">' +
      (cls === 'is-done' ? '<i class="fa-solid fa-check"></i>' : n) + '</span>' + label + '</div>';
  }
  document.getElementById('rrdSteps').innerHTML =
    step(stepState[0], '1', 'Verify downpayment') +
    '<div class="rrd-step-line"></div>' +
    step(stepState[1], '2', 'Approve request');

  rrdRenderReceipt(d);
  rrdRenderRight(d, {
    isResidentGuest: isResidentGuest,
    reservedBy: isResidentGuest ? (guestName || "Resident's Guest") : role,
    displayName: displayName,
    displayEmail: isResidentGuest ? (d.guest_email || '') : (d.email || ''),
    displayPhone: isResidentGuest ? (d.guest_contact || '') : (d.phone || ''),
    residentName: residentName
  });
  rrdRenderFoot(d, att);
  rrdEval();
  /* Re-rendering replaces whatever button had focus. Never leave it stranded on
     <body>, and never park it on an irreversible action. */
  var m = document.getElementById('reservationModal');
  if (m && m.style.display === 'flex' && !m.contains(document.activeElement)) {
    var safe = m.querySelector('.rrd-head .close');
    if (safe) { safe.focus(); }
  }
}

/* ---------- receipt check evaluation ---------- */
function rrdEval(){
  var amtIn = document.getElementById('rrdInAmount');
  var dateIn = document.getElementById('rrdInDate');
  var warn = document.getElementById('rrdFootWarn');
  if (!amtIn || !dateIn) { return; }
  var fA = document.getElementById('rrdFlagAmount');
  var fD = document.getElementById('rrdFlagDate');
  var ok = 0, bad = 0;

  var typed = String(amtIn.value).trim();
  var entered = parseFloat(typed);
  if (typed === '' || !isFinite(entered)) {
    fA.className = 'rrd-flag wait'; fA.textContent = 'Enter';
  } else if (Math.abs(entered - RRD.amt.required) < 0.005) {
    fA.className = 'rrd-flag ok'; fA.innerHTML = '<i class="fa-solid fa-circle-check"></i> Match'; ok++;
  } else {
    fA.className = 'rrd-flag bad'; fA.textContent = 'Mismatch'; bad++;
  }

  if (!dateIn.value) {
    fD.className = 'rrd-flag wait'; fD.textContent = 'Enter';
  } else if (dateIn.value === RRD.expDate) {
    fD.className = 'rrd-flag ok'; fD.innerHTML = '<i class="fa-solid fa-circle-check"></i> Match'; ok++;
  } else {
    fD.className = 'rrd-flag bad'; fD.textContent = 'Mismatch'; bad++;
  }

  if (bad > 0) {
    warn.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>' +
      (bad === 1 ? 'One receipt value does not match' : 'Both receipt values do not match') +
      ' the expected amount or date. Check the receipt again before verifying.</span>';
    warn.hidden = false;
  } else {
    warn.hidden = true;
  }
}

/* ---------- inline rejection ---------- */
function rrdToggleReject(show){
  var box = document.getElementById('rrdReject');
  if (show === undefined) { show = box.hidden; }
  box.hidden = !show;
  var sel = document.getElementById('rrdRejectSelect');
  var conf = document.getElementById('rrdRejectConfirm');
  sel.value = '';
  conf.disabled = true;
  if (show) { sel.focus(); }
}

/* ---------- zoom ---------- */
function rrdOpenZoom(show){
  var z = document.getElementById('rrdZoom');
  var zi = document.getElementById('rrdZoomImg');
  if (show === undefined) { show = z.hidden; }
  if (show) {
    var src = document.querySelector('#rrdReceipt img');
    if (!src) { return; }
    zi.src = src.src;
    z.hidden = false;
    RRD.zoomOpen = true;
    z.querySelector('.rrd-zoom-close').focus();
  } else {
    z.hidden = true;
    zi.removeAttribute('src');
    RRD.zoomOpen = false;
    var zbtn = document.getElementById('rrdZoomBtn');
    if (zbtn && !zbtn.hidden) { zbtn.focus(); }
  }
}

/* ---------- actions ---------- */
function rrdSetBusy(on){
  RRD.busy = !!on;
  var m = document.getElementById('reservationModal');
  if (m) { m.classList.toggle('rrd-busy', RRD.busy); }
  /* Buttons are disabled as well as unclickable, so the state is announced too. */
  var scope = m ? m.querySelectorAll('.rrd-foot-btns .btn, .rrd-reject-btns .btn, .rrd-receipt-tools .btn, #rrdRejectConfirm') : [];
  Array.prototype.forEach.call(scope, function(b){
    if (b.tagName === 'A') { return; }
    if (b.id === 'rrdRejectConfirm') { b.disabled = RRD.busy || !document.getElementById('rrdRejectSelect').value; return; }
    b.disabled = RRD.busy;
  });
}
function rrdToast(title, msg, tone){
  var c = document.getElementById('toastContainer');
  if (!c) { return; }
  var t = document.createElement('div');
  t.className = 'toast' + (tone === 'ok' ? ' rrd-toast-ok' : (tone === 'error' ? ' rrd-toast-error' : ''));
  t.setAttribute('role', 'status');
  var h = document.createElement('h4'); h.textContent = title;
  var p = document.createElement('p'); p.textContent = msg;
  t.appendChild(h); t.appendChild(p);
  c.appendChild(t);
  setTimeout(function(){ if (t.parentNode) { t.remove(); } }, 6000);
}
function rrdAct(fields, onDone){
  if (RRD.busy || !RRD.id) { return; }
  rrdSetBusy(true);
  fetch('admin.php', {
    method: 'POST',
    credentials: 'same-origin',
    redirect: 'manual',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
    body: new URLSearchParams(fields).toString()
  }).then(function(){
    return rrdReload(onDone);
  }).catch(function(err){
    console.error(err);
    rrdSetBusy(false);
    rrdToast('Action failed', 'Could not reach the server. Please try again.', 'error');
  });
}
function rrdReload(onDone){
  return fetch('admin.php?action=get_reservation_details&id=' + encodeURIComponent(RRD.id), { credentials: 'same-origin' })
    .then(function(r){ return r.json(); })
    .then(function(data){
      rrdSetBusy(false);
      if (!data || !data.success) {
        rrdToast('Could not refresh', (data && data.message) || 'Unexpected server response.', 'error');
        return;
      }
      rrdRender(data.details || {});
      rrdSyncList();
      if (typeof onDone === 'function') { onDone(data.details || {}); }
    })
    .catch(function(err){
      console.error(err);
      rrdSetBusy(false);
      rrdToast('Action failed', 'Could not refresh the reservation. Please reopen it.', 'error');
    });
}
function rrdDoVerify(){
  rrdAct({ reservation_id: RRD.id, action: 'verify_receipt', redirect_page: RRD.redirectPage }, function(){
    if (RRD.redirectPage === 'visitor_requests') {
      rrdToast('Receipt approved', 'You can now approve this visit.', 'ok');
    } else {
      rrdToast('Payment verified', 'You can now approve this request.', 'ok');
    }
  });
}
function rrdDoApprove(){
  rrdAct({ rr_id: RRD.id, action: 'approve_resident_reservation', redirect_page: RRD.redirectPage }, function(){
    if (RRD.redirectPage === 'visitor_requests') {
      rrdToast('Visit approved', 'The QR pass has been generated and the visitor was notified.', 'ok');
    } else {
      rrdToast('Request approved', 'The QR pass has been generated and the resident was notified.', 'ok');
    }
  });
}
function rrdDoReject(){
  var sel = document.getElementById('rrdRejectSelect');
  var reason = sel.value;
  /* The reason is required, never optional. */
  if (!reason) { return; }
  rrdToggleReject(false);
  rrdAct({ reservation_id: RRD.id, action: 'reject_receipt', denial_reason: reason, redirect_page: RRD.redirectPage }, function(){
    var who = (RRD.redirectPage === 'visitor_requests') ? 'The visitor was notified.' : 'The resident was notified.';
    rrdToast('Receipt rejected', who + ' Reason: ' + reason, 'error');
  });
}

/* ---------- keep the list behind the dialog in step ---------- */
/* The dialog is shared, so the list behind it is whichever one is on screen. */
function rrdListCtx(){
  var vp = document.getElementById('visitor-requests-panel');
  if (vp) {
    return { panel: vp, tbody: document.getElementById('vr-tbody'), pill: 'vr-pill', redirect: 'visitor_requests', visitor: true };
  }
  var rp = document.getElementById('requests-panel');
  if (!rp) { return null; }
  return { panel: rp, tbody: document.getElementById('rr-tbody'), pill: 'rr-pill', redirect: 'requests', visitor: false };
}

/* Renders one sidebar action badge. Hidden entirely at zero, capped at 99+, and
   it pulses only when the count goes UP (something just landed). The page and the
   wording come off the element, so the same function serves all four badges and
   keeps the owning link's accessible name in step. */
function rrdPaintBadge(badge, n, pulse){
  if (!badge) { return; }
  n = parseInt(n, 10);
  if (isNaN(n) || n < 0) { n = 0; }
  var prev = parseInt(badge.getAttribute('data-count') || '0', 10);
  if (isNaN(prev)) { prev = 0; }
  badge.setAttribute('data-count', String(n));
  badge.textContent = (n > 99) ? '99+' : String(n);
  badge.hidden = n <= 0;
  var page = badge.getAttribute('data-page') || '';
  var name = badge.getAttribute('data-label') || '';
  /* Guest requests read as "waiting"; the other three keep "need action". */
  var unit = badge.getAttribute('data-unit') || 'need action';
  if (page && name && badge.closest) {
    var link = badge.closest('a[data-page]');
    if (link && link.getAttribute('data-page') === page) {
      link.setAttribute('aria-label', (n > 0) ? (name + ', ' + n + ' ' + unit) : name);
    }
  }
  if (pulse && n > prev) {
    badge.classList.remove('pulse');
    /* Force a reflow so the animation restarts on every new item. */
    void badge.offsetWidth;
    badge.classList.add('pulse');
  }
}

/* Repaints every badge the server just recounted. Each badge only ever looks at
   its own number, so one page's backlog can never move another page's badge.
   A page that is not in the payload is left alone rather than blanked. */
function rrdApplySidebarCounts(counts, pulse){
  if (!counts) { return; }
  var badges = document.querySelectorAll('.nav-badge');
  for (var i = 0; i < badges.length; i++) {
    var b = badges[i];
    var page = b.getAttribute('data-page');
    if (!page || !Object.prototype.hasOwnProperty.call(counts, page)) { continue; }
    rrdPaintBadge(b, counts[page], pulse);
  }
}

function rrdRowActionsHtml(ctx, fresh){
  var ref = String((RRD.d && RRD.d.ref_code) || '');
  var html = '<button type="button" class="btn btn-view" onclick=\'showReservationDetails(' + RRD.id + ',"visitor")\'>View Details</button>';
  if (RRD.key === 'ready') {
    html += '<form method="post">' +
      '<input type="hidden" name="rr_id" value="' + RRD.id + '">' +
      '<input type="hidden" name="action" value="approve_resident_reservation">' +
      '<input type="hidden" name="redirect_page" value="' + ctx.redirect + '">' +
      /* The Approve button only exists once the receipt was approved, so on the
         visitor list it fades in rather than popping. */
      '<button type="submit" class="btn btn-approve' + ((fresh && ctx.visitor) ? ' vr-btn-fade' : '') + '">Approve</button></form>';
  } else if (RRD.key === 'approved' && ref) {
    html += '<a class="btn btn-qr" href="qr_view.php?code=' + encodeURIComponent(ref) + '" target="_blank" rel="noopener"><i class="fa-solid fa-qrcode"></i> View QR</a>';
  }
  return html;
}
function rrdSyncList(){
  if (!RRD.id) { return; }
  var ctx = rrdListCtx();
  if (!ctx) { return; }
  var rows = ctx.tbody.querySelectorAll('tr[data-status]');

  var row = null;
  Array.prototype.forEach.call(rows, function(r){
    if (parseInt(r.getAttribute('data-id'), 10) === RRD.id) { row = r; }
  });
  var fresh = false;
  if (row) {
    fresh = (row.getAttribute('data-status') === 'to_verify' && RRD.key === 'ready');
    row.setAttribute('data-status', RRD.key);
    var pill = row.querySelector('.' + ctx.pill);
    if (pill) {
      pill.className = ctx.pill + ' ' + ctx.pill + '-' + RRD.key;
      pill.textContent = RRD_LABELS[RRD.key];
      var reason = String((RRD.d && RRD.d.denial_reason) || '').trim();
      if (RRD.key === 'rejected' && reason) { pill.setAttribute('title', 'Reason: ' + reason); }
      else { pill.removeAttribute('title'); }
    }
    var cell = row.querySelector('td.actions');
    if (cell) { cell.innerHTML = rrdRowActionsHtml(ctx, fresh); }
  }

  /* Recount the badge for the list this dialog is sitting on. The rows here are
     exactly the rows behind the filter boxes, and the rule is the same one the
     server uses (To verify + Ready to approve), so the badge and the boxes move
     together the moment an admin acts. The other two badges are not touched:
     their rows are not on this page, so the poll owns them. */
  var badgePage = ctx.visitor ? 'visitor_requests' : 'requests';
  var badge = document.querySelector('.nav-badge[data-page="' + badgePage + '"]');
  if (badge) {
    var n = 0;
    Array.prototype.forEach.call(rows, function(r){
      var s = r.getAttribute('data-status');
      if (s === 'to_verify' || s === 'ready') { n++; }
    });
    rrdPaintBadge(badge, n);
  }

  if (ctx.visitor) {
    /* The visitor list owns its own filtering and counting. */
    if (window.VR_LIST) { window.VR_LIST.recount(); window.VR_LIST.apply(); }
    return;
  }

  var counts = { all: 0, to_verify: 0, ready: 0, approved: 0, rejected: 0 };
  Array.prototype.forEach.call(rows, function(r){
    var s = r.getAttribute('data-status');
    counts.all++;
    if (Object.prototype.hasOwnProperty.call(counts, s) && s !== 'all') { counts[s]++; }
  });
  Array.prototype.forEach.call(ctx.panel.querySelectorAll('[data-rr-filter]'), function(btn){
    var k = btn.getAttribute('data-rr-filter');
    var c = btn.querySelector('.rr-filter-count');
    if (c && Object.prototype.hasOwnProperty.call(counts, k)) { c.textContent = counts[k]; }
    var lbl = btn.querySelector('.rr-filter-label');
    if (!lbl) { return; }
    var wants = (k === 'to_verify' || k === 'ready') && counts[k] > 0;
    var dot = lbl.querySelector('.rr-filter-dot');
    if (wants && !dot) {
      var s = document.createElement('span');
      s.className = (k === 'ready') ? 'rr-filter-dot is-ready' : 'rr-filter-dot';
      s.setAttribute('aria-hidden', 'true');
      lbl.insertBefore(s, lbl.firstChild);
    } else if (!wants && dot) {
      dot.parentNode.removeChild(dot);
    }
  });

  /* Re-run the list's own private filter so the row it no longer matches leaves. */
  var si = document.getElementById('search-input');
  if (si && si.dispatchEvent) { si.dispatchEvent(new Event('input')); }
}

/* ---------- open / close ---------- */
/* The skeleton inside #reservationDetailsContent is permanent. Only the
   generated right-hand column and the receipt frame are torn down, otherwise
   the dialog would lose its own nodes the first time it is closed. */
function rrdReset(){
  var right = document.getElementById('rrdRight');
  if (right) { right.innerHTML = ''; }
  var box = document.getElementById('rrdReceipt');
  if (box) {
    var img = box.querySelector('img');
    if (img) { img.parentNode.removeChild(img); }
  }
  var state = document.getElementById('rrdReceiptState');
  if (state) { state.hidden = false; state.className = 'rrd-receipt-state'; }
  var cap = document.getElementById('rrdReceiptCaption');
  if (cap) { cap.textContent = ''; }
  var zbtn = document.getElementById('rrdZoomBtn');
  if (zbtn) { zbtn.hidden = true; }
  var dbtn = document.getElementById('rrdDownloadBtn');
  if (dbtn) { dbtn.hidden = true; dbtn.removeAttribute('href'); }
  var steps = document.getElementById('rrdSteps');
  if (steps) { steps.innerHTML = ''; }
  var pill = document.getElementById('rrdPill');
  if (pill) { pill.hidden = true; }
  var sub = document.getElementById('rrdSub');
  if (sub) { sub.innerHTML = ''; }
  var name = document.getElementById('rrdName');
  if (name) { name.textContent = 'Reservation Details'; }
  var warn = document.getElementById('rrdFootWarn');
  if (warn) { warn.hidden = true; warn.innerHTML = ''; }
  var hint = document.getElementById('rrdHint');
  if (hint) { hint.className = 'rrd-foot-hint'; hint.textContent = ''; }
  var acts = document.getElementById('rrdActions');
  if (acts) { acts.innerHTML = ''; }
  rrdToggleReject(false);
  rrdSetBusy(false);
  RRD.id = null;
  RRD.d = null;
  RRD.amt = null;
  RRD.expDate = '';
}

function showReservationDetails(reservationId, expectedType){
  var m = document.getElementById('reservationModal');
  if (!m) { return; }
  if (m.style.display !== 'flex') {
    RRD.lastFocus = document.activeElement;
    /* The list row's action cell is rebuilt after an action, which detaches the
       button that opened this dialog. Keep the row so focus can go back to it. */
    var active = document.activeElement;
    RRD.lastRowId = null;
    if (active && active.closest) {
      /* Any of the admin request tables, not just the resident one. */
      var r = active.closest('tr[data-id]');
      if (r) { RRD.lastRowId = r.getAttribute('data-id'); }
    }
  }
  rrdOpenZoom(false);
  rrdReset();
  m.style.display = 'flex';
  var closeBtn = m.querySelector('.rrd-head .close');
  if (closeBtn) { closeBtn.focus(); }
  var right = document.getElementById('rrdRight');
  if (right) {
    right.innerHTML = '<div style="padding:10px 0;font-size:0.82rem;color:var(--text-secondary);">Loading reservation details&hellip;</div>';
  }

  function fail(message){
    /* The receipt frame would otherwise sit on its spinner forever. */
    var state = document.getElementById('rrdReceiptState');
    if (state) {
      state.hidden = false;
      state.className = 'rrd-receipt-state is-error';
      state.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><span>Receipt unavailable.</span>';
    }
    var el = document.getElementById('rrdRight');
    if (el) {
      el.innerHTML = '<div class="rrd-warn"><i class="fa-solid fa-triangle-exclamation"></i><span>' + rrdEsc(message) + '</span></div>';
    }
  }

  fetch('admin.php?action=get_reservation_details&id=' + encodeURIComponent(reservationId))
    .then(function(r){ return r.json(); })
    .then(function(data){
      if (!data || !data.success) {
        fail('Could not load this reservation: ' + ((data && data.message) || 'unknown error') + '.');
        return;
      }
      rrdRender(data.details || {});
    })
    .catch(function(err){
      console.error(err);
      fail('Could not reach the server to load this reservation.');
    });
}

function closeReservationModal(){
  var m = document.getElementById('reservationModal');
  rrdOpenZoom(false);
  if (m) { m.style.display = 'none'; }
  rrdReset();
  var rf = RRD.lastFocus;
  var rowId = RRD.lastRowId;
  RRD.lastFocus = null;
  RRD.lastRowId = null;
  if (rf && typeof rf.focus === 'function' && document.body.contains(rf)) {
    try { rf.focus(); return; } catch (e) {}
  }
  if (rowId) {
    var btn = document.querySelector('tr[data-id="' + rowId + '"] td.actions .btn-view');
    if (btn) { try { btn.focus(); } catch (e) {} }
  }
}

document.addEventListener('input', function(e){
  if (e.target && (e.target.id === 'rrdInAmount' || e.target.id === 'rrdInDate')) { rrdEval(); }
}, true);
document.addEventListener('change', function(e){
  if (e.target && e.target.id === 'rrdRejectSelect') {
    document.getElementById('rrdRejectConfirm').disabled = !e.target.value;
  }
}, true);
document.addEventListener('keydown', function(e){
  var m = document.getElementById('reservationModal');
  if (!m || m.style.display !== 'flex') { return; }
  if (e.key === 'Escape' || e.key === 'Esc') {
    if (RRD.zoomOpen) { rrdOpenZoom(false); } else { closeReservationModal(); }
    if (e.preventDefault) { e.preventDefault(); }
    return;
  }
  if (e.key !== 'Tab') { return; }
  var f = m.querySelectorAll('button:not([disabled]), a[href], input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
  var vis = Array.prototype.filter.call(f, function(el){
    if (el.hidden || el.closest('[hidden]')) { return false; }
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  });
  if (!vis.length) { return; }
  var first = vis[0], last = vis[vis.length - 1];
  if (e.shiftKey && document.activeElement === first) { last.focus(); e.preventDefault(); }
  else if (!e.shiftKey && document.activeElement === last) { first.focus(); e.preventDefault(); }
});

window.addEventListener('click', function(event){
  var rmodal = document.getElementById('reservationModal');
  if(event.target === rmodal){ closeReservationModal(); }
});
</script>

<!-- Resident Reservation Details Modal -->
<div id="residentReservationModal" class="modal">
  <div class="modal-content">
    <button type="button" class="close" onclick="closeResidentReservationModal()" aria-label="Close">×</button>
    <h3>Resident Reservation</h3>
    <div id="residentReservationDetailsContent"></div>
  </div>
</div>

<script>
function fmtTime(t){ if(!t) return ''; var p=String(t).split(':'), hh=parseInt(p[0]||'0',10), m=(p[1]||'00'); var ap=hh>=12?'PM':'AM'; var h=hh%12; if(h===0) h=12; return (String(h)+":"+String(m).padStart(2,'0')+" "+ap); }
function fmtDate(d){ if(!d) return ''; var p=String(d).split('-'); if(p.length!==3) return d; var m=(p[1]||'').padStart(2,'0'); var dd=(p[2]||'').padStart(2,'0'); var y=String(p[0]).slice(-2); return m+'/'+dd+'/'+y; }
function fmtDuration(st, et){ if(!st || !et) return ''; var m1=String(st).match(/^(\d{1,2}):(\d{2})/); var m2=String(et).match(/^(\d{1,2}):(\d{2})/); if(!m1 || !m2) return ''; var sm=parseInt(m1[1],10)*60+parseInt(m1[2],10); var em=parseInt(m2[1],10)*60+parseInt(m2[2],10); var diff=em-sm; if(diff===0) return ''; if(diff<0) diff=(24*60)-sm+em; if(diff<=0) return ''; var h=Math.floor(diff/60); var mins=diff%60; var out=[]; if(h>0) out.push(h+(h===1?' hr':' hrs')); if(mins>0) out.push(mins+' min'); return out.join(' ')||''; }
function fmtSubmittedOn(dt){ try{ var d=new Date(dt); var months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; var mm=months[d.getMonth()]; var dd=d.getDate(); var yy=d.getFullYear(); var hh=d.getHours(); var m=String(d.getMinutes()).padStart(2,'0'); var ap=hh>=12?'PM':'AM'; var h=hh%12; if(h===0) h=12; return (mm+' '+dd+', '+yy+' at '+h+':'+m+' '+ap); }catch(e){ return String(dt); } }
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
      const isFullyRedeemed2 = (parseInt(d.use_points,10) === 1 && parseInt(d.points_used,10) > 0 && Math.abs(durationHours(d.start_time, d.end_time) - 1) < 0.001);
      const pointsUsed2 = (parseInt(d.use_points,10) === 1) ? (parseInt(d.points_used,10) || 0) : 0;
      const discountValue2 = pointsUsed2 > 0 ? (amenityHourlyRate(d.amenity, d.account_type, d.user_type) || 0) : 0;
      const payMethodBlock2 = (pointsUsed2 > 0) ? (isFullyRedeemed2 ? (()=>{ 
        const pts = pointsUsed2;
        const rate = amenityHourlyRate(d.amenity, d.account_type, d.user_type) || 0;
        return `<div class="price-section">
          <div class="info-row"><span class="info-label">VHEcoPoint Redemption</span><span class="info-value">Fully Redeemed</span></div>
          <div class="info-row"><span class="info-label">Original Duration</span><span class="info-value">1 hour</span></div>
          <div class="info-row"><span class="info-label">EcoPoints Used</span><span class="info-value">${fmtNum(pts)} pts</span></div>
          <div class="info-row"><span class="info-label">Benefit</span><span class="info-value">1 Free Hour</span></div>
          <div class="info-row"><span class="info-label">Paid Duration</span><span class="info-value">0 hours</span></div>
          <div class="info-row"><span class="info-label">Original Amount</span><span class="info-value">${fmtMoney(rate)}</span></div>
          <div class="info-row price-down"><span class="info-label">VHEcoPoint Discount</span><span class="info-value">-${fmtMoney(rate)} (${fmtNum(pts)} pts)</span></div>
          <div class="info-row total-price"><span class="info-label">Final Amount</span><span class="info-value">${fmtMoney(0)}</span></div>
          <div class="info-row price-down"><span class="info-label">Downpayment</span><span class="info-value">${fmtMoney(0)}</span></div>
          <div class="info-row price-balance"><span class="info-label">Remaining Balance</span><span class="info-value">${fmtMoney(0)}</span></div>
          <div class="info-row total-price"><span class="info-label">Payment Status</span><span class="info-value">Fully Redeemed</span></div>
        </div>`; 
      })() : (()=>{
        const hours = durationHours(d.start_time, d.end_time);
        const rate = amenityHourlyRate(d.amenity, d.account_type, d.user_type) || 0;
        const finalAmount = parseFloat(d.price) || Math.max(0, hours - 1) * rate;
        const originalAmount = hours * rate;
        const requiredDownpayment = finalAmount * 0.5;
        const paidDownpayment = d.downpayment != null && parseFloat(d.downpayment) > 0 ? parseFloat(d.downpayment) : requiredDownpayment;
        const remainingBalance = Math.max(0, finalAmount - paidDownpayment);
        const paymentLabel = ps === 'verified' ? 'Verified' : (ps === 'rejected' ? 'Rejected' : 'Submitted - Awaiting Verification');
        return `<div class="price-section">
          <div class="info-row"><span class="info-label">VHEcoPoint Redemption</span><span class="info-value">Discounted Redemption</span></div>
          <div class="info-row"><span class="info-label">Original Duration</span><span class="info-value">${hours} hours</span></div>
          <div class="info-row"><span class="info-label">EcoPoints Used</span><span class="info-value">${fmtNum(pointsUsed2)} pts</span></div>
          <div class="info-row"><span class="info-label">Benefit</span><span class="info-value">1 Free Hour</span></div>
          <div class="info-row"><span class="info-label">Paid Duration</span><span class="info-value">${Math.max(0, hours - 1)} hours</span></div>
          <div class="info-row"><span class="info-label">Original Amount</span><span class="info-value">${fmtMoney(originalAmount)}</span></div>
          <div class="info-row price-down"><span class="info-label">VHEcoPoint Discount</span><span class="info-value">-${fmtMoney(discountValue2)} (${fmtNum(pointsUsed2)} pts)</span></div>
          <div class="info-row total-price"><span class="info-label">Final Amount</span><span class="info-value">${fmtMoney(finalAmount)}</span></div>
          <div class="info-row price-down"><span class="info-label">Required Downpayment</span><span class="info-value">${fmtMoney(requiredDownpayment)}</span></div>
          <div class="info-row price-down"><span class="info-label">Downpayment</span><span class="info-value">${fmtMoney(paidDownpayment)}</span></div>
          <div class="info-row price-balance"><span class="info-label">Remaining Balance</span><span class="info-value">${fmtMoney(remainingBalance)}</span></div>
          <div class="info-row total-price"><span class="info-label">Payment Status</span><span class="info-value">${paymentLabel}</span></div>
        </div>`;
      })()) : '';
      const ecoBadge2 = (pointsUsed2 > 0) ? `<span class="status-badge-lg eco-badge">♻ EcoPoints Used <a class="eco-confirm-btn" href="?page=smart_waste_logs" target="_blank" rel="noopener"><?php echo vh_eco_logo('', 'eco-confirm-logo'); ?><span>Confirm in VHEcoPoint</span></a></span>` : '';
      const redemptionSection2 = (pointsUsed2 > 0) ? (`
        <div class="section-title">VHEcoPoint Redemption</div>
        <div class="info-grid">
          <div class="info-row"><span class="info-label">Resident</span><span class="info-value">${isResidentGuest ? (guestName || 'Resident’s Guest') : residentName}</span></div>
          ${d.ref_code?`<div class="info-row"><span class="info-label">Reservation Reference</span><span class="info-value">${d.ref_code}</span></div>`:''}
          ${d.amenity?`<div class="info-row"><span class="info-label">Amenity</span><span class="info-value">${d.amenity}</span></div>`:''}
        ${d.start_date?`<div class="info-row"><span class="info-label">Reservation Date</span><span class="info-value">${fmtDate(d.start_date)}</span></div>`:''}
          ${fmtDuration(d.start_time,d.end_time)?`<div class="info-row"><span class="info-label">Reserved Duration</span><span class="info-value">${fmtDuration(d.start_time,d.end_time)}</span></div>`:''}
          <div class="info-row"><span class="info-label">EcoPoints Used</span><span class="info-value">${fmtNum(pointsUsed2)} pts</span></div>
          <div class="info-row"><span class="info-label">Benefit</span><span class="info-value">1 Free Hour</span></div>
          <div class="info-row"><span class="info-label">Discount / Savings</span><span class="info-value">${fmtMoney(discountValue2)}</span></div>
          <div class="info-row"><span class="info-label">Redemption Status</span><span class="info-value">${isFullyRedeemed2 ? 'Fully Redeemed' : 'Partially Redeemed'}</span></div>
          <div class="info-row" style="flex-wrap:wrap;"><span class="info-label">Note</span><span class="info-value" style="font-weight:500;font-size:0.85rem;">No payment proof is required for the redeemed portion.</span></div>
        </div>
      `) : '';
      
      const bookedByRole = (d.booked_by_role || '').toLowerCase();
      const bookedByName = d.booked_by_name || '';
      const isBookedByGuest = (bookedByRole === 'guest' || bookedByRole === 'co_owner');
      const isResidentGuest = !!d.gf_id || isBookedByGuest;
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
      const priceBlock = buildReservationPriceBlock(d);
      const pointsBlock = (parseInt(d.use_points,10) === 1 && parseInt(d.points_used,10) > 0) ? (()=>{
        const pts = parseInt(d.points_used,10) || 0;
        return `<div class="price-section">
          <div class="info-row total-price"><span class="info-label">EcoPoints Used</span><span class="info-value">${pts.toLocaleString()} pts</span></div>
          <div class="info-row"><span class="info-label">Benefit</span><span class="info-value">1 free hour</span></div>
          <div class="info-row" style="flex-wrap:wrap;"><span class="info-label">Note</span><span class="info-value" style="font-weight:500;font-size:0.85rem;">1 free hour deducted from the duration. Remaining hours are charged at regular rate.</span></div>
        </div>`;
      })() : '';
      const receiptPath = (d.receipt_url||d.receipt_path||'').toString().trim();
      const isPdf = /\.pdf$/i.test(receiptPath);
      const denialReason = (d.denial_reason||'').toString().trim();
      const att = parseInt(d.receipt_attempts||0, 10);
      const showDenial = denialReason && (ps === 'rejected' || ps === 'pending_update' || approvalStatus.includes('denied') || approvalStatus.includes('reject'));
      const waitNote = ps === 'rejected' ? ((att>=3) ? 'Denied — Max Attempts Reached. Payment rejected 3 times. No further uploads allowed.' : 'Wait for the updated proof.') : '';
      const receiptRedirectPage = isResidentGuest ? 'resident_guest_forms' : (d.entry_pass_id || d.user_type === 'visitor' ? 'visitor_requests' : 'requests');
      const receiptHtml = receiptPath ? (
        `<div class="details-section" style="animation: fadeIn 0.5s ease;">
          <h4>Proof of Payment</h4>
          ${isPdf ? `<a href="${receiptPath}" target="_blank" style="color:#23412e;font-weight:600;">Open uploaded proof (PDF)</a>` : `<a href="${receiptPath}" target="_blank"><img src="${receiptPath}" alt="Uploaded proof of payment" style="max-width:100%; height:auto; border-radius:8px; cursor:pointer;"></a>`}
          ${ps !== 'verified' && d.id ? `<div class="receipt-action-row"><form method="post"><input type="hidden" name="reservation_id" value="${d.id}"><input type="hidden" name="action" value="verify_receipt"><input type="hidden" name="redirect_page" value="${receiptRedirectPage}"><button type="submit" class="btn btn-approve"><i class="fa-solid fa-check" aria-hidden="true"></i>Verify Payment Receipt</button></form>${att < 3 && ps !== 'rejected' ? `<form method="post" class="action-form action-deny" onsubmit="return openDenyModal(this)"><input type="hidden" name="reservation_id" value="${d.id}"><input type="hidden" name="action" value="reject_receipt"><input type="hidden" name="redirect_page" value="${receiptRedirectPage}"><input type="hidden" name="denial_reason" class="denial-reason"><button type="submit" class="btn btn-reject"><i class="fa-solid fa-xmark" aria-hidden="true"></i>Reject Receipt</button></form>` : ''}</div>` : ''}
        </div>`
      ) : `<div class="details-section"><h4>Proof of Payment</h4><p>${isFullyRedeemed2 ? 'No receipt uploaded. No proof of payment is required because this reservation was fully covered by the VHEcoPoint redemption.' : (d.receipt_uploaded_at || ['submitted', 'pending_update'].includes(ps) ? 'Payment submitted, but the receipt file is unavailable.' : 'No receipt uploaded.')}</p></div>`;
      const denialHtml = showDenial ? (
        `<div style="margin-top:12px;padding:12px;border-radius:10px;background:#fee2e2;color:#991b1b;font-weight:600;">
          <div>Reason: ${denialReason}</div>
          ${waitNote ? `<div style="margin-top:6px;color:#7f1d1d;font-weight:500;">${waitNote}</div>` : ''}
        </div>`
      ) : '';
      const content = `
          <div class="request-details">
            <div class="request-status" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><span class="status-badge-lg ${stClass}">${stLabel}</span>${ecoBadge2}</div>
            <div class="section-title">Request Status</div>
            <div class="info-grid">
              <div class="info-row"><span class="info-label">Status</span><span class="info-value">${stLabel}</span></div>
              ${d.created_at?`<div class="info-row"><span class="info-label">Request Submitted</span><span class="info-value">${fmtSubmittedOn(d.created_at)}</span></div>`:''}
            </div>
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
              ${reservedBy?`<div class="info-row"><span class="info-label">Reserved By</span><span class="info-value">${reservedBy}</span></div>`:''}
              ${d.start_date?`<div class="info-row"><span class="info-label">Start Date</span><span class="info-value">${fmtDate(d.start_date)}</span></div>`:''}
              ${d.end_date?`<div class="info-row"><span class="info-label">End Date</span><span class="info-value">${fmtDate(d.end_date)}</span></div>`:''}
              ${d.start_time?`<div class="info-row"><span class="info-label">Start Time</span><span class="info-value">${fmtTime(d.start_time)}</span></div>`:''}
              ${d.end_time?`<div class="info-row"><span class="info-label">End Time</span><span class="info-value">${fmtTime(d.end_time)}</span></div>`:''}
              ${fmtDuration(d.start_time,d.end_time)?`<div class="info-row"><span class="info-label">Duration</span><span class="info-value">${fmtDuration(d.start_time,d.end_time)}</span></div>`:''}
              ${d.persons?`<div class="info-row"><span class="info-label">Persons</span><span class="info-value">${d.persons}</span></div>`:''}
            </div>
            <div class="section-title">Payment Details</div>
            <div class="info-grid">
              ${pointsUsed2 > 0 ? payMethodBlock2 : (priceBlock + pointsBlock)}
              ${pointsUsed2 > 0 ? '' : `<div class="info-row"><span class="info-label">Downpayment</span><span class="info-value"><span class="badge ${psClass}">${ps.charAt(0).toUpperCase()+ps.slice(1)}</span></span></div>`}
            </div>
            ${receiptHtml}
            ${denialHtml}
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
    <button type="button" class="close" onclick="closeUserModal()" aria-label="Close">×</button>
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
    <button type="button" class="close" id="adminConfirmClose" aria-label="Close">×</button>
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
    /* The visitor requests page keeps its own filters, sort, page and live
       counts in place, so a full reload would throw all of that away. It does
       its own refreshing instead. Every other admin page is unaffected. */
    if (document.getElementById('visitor-requests-panel')) { return; }
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