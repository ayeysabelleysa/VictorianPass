<?php
$staffInactivityLimit = 2700;
ini_set('session.gc_maxlifetime', (string)$staffInactivityLimit);
session_start();
require_once 'connect.php';

$now = time();
$last = intval($_SESSION['staff_last_activity'] ?? 0);
$timeout = intval($_SESSION['staff_session_timeout'] ?? $staffInactivityLimit);
if ($last > 0 && $timeout > 0 && ($now - $last) > $timeout) {
  $con->query("CREATE TABLE IF NOT EXISTS login_history (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_time DATETIME NULL, INDEX idx_staff_id (staff_id)) ENGINE=InnoDB");
  $loginId = intval($_SESSION['login_history_id'] ?? 0);
  if ($loginId > 0) {
    $stmt = $con->prepare('UPDATE login_history SET logout_time = NOW() WHERE id = ? AND logout_time IS NULL');
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $stmt->close();
  }
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header("Location: login.php");
  exit;
}
if (isset($_SESSION['role']) && $_SESSION['role'] === 'guard') {
  $_SESSION['staff_last_activity'] = $now;
  if (!isset($_SESSION['staff_session_timeout'])) {
    $_SESSION['staff_session_timeout'] = $staffInactivityLimit;
  }
}
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'guard') { header('Location: login.php'); exit; }
$email = $_SESSION['email'] ?? '';
$local = explode('@', $email)[0] ?? '';
$s = $local;
if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
$s = preg_replace('/[^a-zA-Z]/', '', $s);
$surname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
$staffId = intval($_SESSION['staff_id'] ?? 0);
$currentLoginId = intval($_SESSION['login_history_id'] ?? 0);
$guardConfirmRef = '';
if (!empty($_SESSION['guard_confirmed_ref'])) {
  $age = time() - intval($_SESSION['guard_confirmed_time'] ?? 0);
  if ($age >= 0 && $age < 120) {
    $guardConfirmRef = (string)$_SESSION['guard_confirmed_ref'];
  }
  unset($_SESSION['guard_confirmed_ref'], $_SESSION['guard_confirmed_time']);
}
$con->query("CREATE TABLE IF NOT EXISTS login_history (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_time DATETIME NULL, INDEX idx_staff_id (staff_id)) ENGINE=InnoDB");
$currentLogin = null;
if ($currentLoginId > 0) {
  $stmt = $con->prepare('SELECT id, staff_id, login_time, logout_time FROM login_history WHERE id = ? LIMIT 1');
  $stmt->bind_param('i', $currentLoginId);
  $stmt->execute();
  $res = $stmt->get_result();
  if ($res && $res->num_rows === 1) { $currentLogin = $res->fetch_assoc(); }
  $stmt->close();
}
$history = [];
if ($staffId > 0) {
  $stmt = $con->prepare('SELECT id, login_time, logout_time FROM login_history WHERE staff_id = ? ORDER BY login_time DESC LIMIT 10');
  $stmt->bind_param('i', $staffId);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($res && ($row = $res->fetch_assoc())) { $history[] = $row; }
$stmt->close();
}

// Ensure incident tables and escalation columns exist
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
$c1 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_to_admin'");
if ($c1 && $c1->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0 AFTER status"); }
$c2 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_by_guard_id'");
if ($c2 && $c2->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_by_guard_id INT NULL AFTER escalated_to_admin"); }
$c3 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'escalated_at'");
if ($c3 && $c3->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN escalated_at DATETIME NULL AFTER escalated_by_guard_id"); }
// Track guard-handled incidents
$c4 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'handled_by_guard_id'");
if ($c4 && $c4->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN handled_by_guard_id INT NULL AFTER escalated_at"); }
$c5 = $con->query("SHOW COLUMNS FROM incident_reports LIKE 'handled_at'");
if ($c5 && $c5->num_rows === 0) { $con->query("ALTER TABLE incident_reports ADD COLUMN handled_at DATETIME NULL AFTER handled_by_guard_id"); }

function ensureReservationColumnsForGuard($con){
  if (!($con instanceof mysqli)) { return; }
  $cols = [
    'booking_for' => "ENUM('resident','guest') NULL",
    'account_type' => "ENUM('visitor','resident') NULL",
    'guest_id' => "INT NULL",
    'guest_ref_code' => "VARCHAR(50) NULL",
    'entry_pass_id' => "INT NULL"
  ];
  foreach($cols as $col => $def){
    $c = $con->query("SHOW COLUMNS FROM reservations LIKE '".$con->real_escape_string($col)."'");
    if (!$c || $c->num_rows === 0) {
      $con->query("ALTER TABLE reservations ADD COLUMN $col $def");
    }
  }
}
ensureReservationColumnsForGuard($con);

// Ensure entry_scans table exists
if ($con instanceof mysqli) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_scans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ref_code VARCHAR(50) NOT NULL,
    scanned_by_guard_id INT NULL,
    scanned_by_name VARCHAR(150) NULL,
    subject_name VARCHAR(150) NULL,
    entry_type VARCHAR(50) NULL,
    status VARCHAR(50) NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref_code (ref_code),
    INDEX idx_guard (scanned_by_guard_id),
    INDEX idx_scanned_at (scanned_at)
  ) ENGINE=InnoDB");
}
function ensureNotificationsTable($con) {
  if (!($con instanceof mysqli)) { return; }
  $con->query("CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    entry_pass_id INT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read)
  ) ENGINE=InnoDB");
}

// API: list incidents for guards (not yet escalated)
if (isset($_GET['action']) && $_GET['action'] === 'list_incidents') {
  header('Content-Type: application/json');
  $rows = [];
  $q = "SELECT ir.id, ir.complainant, ir.subject, ir.address, ir.nature, ir.other_concern, ir.created_at, ir.status,
               u.first_name, u.middle_name, u.last_name
        FROM incident_reports ir
        LEFT JOIN users u ON ir.user_id = u.id
        WHERE COALESCE(ir.escalated_to_admin,0) = 0
          AND (ir.status IS NULL OR LOWER(TRIM(ir.status)) <> 'cancelled')
        ORDER BY ir.created_at DESC";
  $res = $con->query($q);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $full = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $rows[] = [
        'id' => intval($r['id']),
        'resident_name' => ($full !== '' ? $full : $r['complainant']),
        'subject' => $r['subject'] ?? '',
        'address' => $r['address'] ?? '',
        'nature' => $r['nature'] ?? ($r['other_concern'] ?? ''),
        'created_at' => $r['created_at'],
        'status' => $r['status']
      ];
    }
  }
  echo json_encode(['success' => true, 'incidents' => $rows]);
  exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'incident_details' && isset($_GET['id'])) {
  header('Content-Type: application/json');
  $rid = intval($_GET['id']);
  $data = null;
  $files = [];
  if ($rid > 0) {
    $stmt = $con->prepare("SELECT ir.id, ir.complainant, ir.subject, ir.address, ir.nature, ir.other_concern, ir.report_date, ir.created_at, ir.status, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id WHERE ir.id = ? LIMIT 1");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows === 1) {
      $r = $res->fetch_assoc();
      $full = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
      $data = [
        'id' => intval($r['id']),
        'resident_name' => ($full !== '' ? $full : $r['complainant']),
        'subject' => $r['subject'] ?? '',
        'address' => $r['address'] ?? '',
        'nature' => $r['nature'] ?? '',
        'other_concern' => $r['other_concern'] ?? '',
        'report_date' => $r['report_date'] ?? null,
        'created_at' => $r['created_at'] ?? null,
        'status' => $r['status'] ?? ''
      ];
    }
    $stmt->close();
    $stmtP = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
    $stmtP->bind_param('i', $rid);
    $stmtP->execute();
    $resP = $stmtP->get_result();
    if ($resP) { while ($rowP = $resP->fetch_assoc()) { $files[] = $rowP['file_path']; } }
    $stmtP->close();
  }
  echo json_encode(['success' => ($data !== null), 'report' => $data, 'proofs' => $files]);
  exit;
}

// API: escalate incident to admin
if (isset($_POST['action']) && $_POST['action'] === 'escalate' && isset($_POST['report_id'])) {
  header('Content-Type: application/json');
  $rid = intval($_POST['report_id']);
  $gid = intval($staffId);
  if ($rid > 0 && $gid > 0) {
    $stmt = $con->prepare("UPDATE incident_reports SET escalated_to_admin = 1, escalated_by_guard_id = ?, escalated_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $gid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}

// API: mark incident as handled by guard (in progress)
if (isset($_POST['action']) && $_POST['action'] === 'handle' && isset($_POST['report_id'])) {
  header('Content-Type: application/json');
  $rid = intval($_POST['report_id']);
  $gid = intval($staffId);
  if ($rid > 0 && $gid > 0) {
    $stmt = $con->prepare("UPDATE incident_reports SET status = 'in_progress', handled_by_guard_id = ?, handled_at = NOW(), updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('ii', $gid, $rid);
    $ok = $stmt->execute();
    $stmt->close();
    echo json_encode(['success' => $ok]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}

// API: resolve incident (mark as resolved and notify resident)
if (isset($_POST['action']) && $_POST['action'] === 'resolve' && isset($_POST['report_id'])) {
  header('Content-Type: application/json');
  $rid = intval($_POST['report_id']);
  $gid = intval($staffId);
  if ($rid > 0 && $gid > 0) {
    $stmt = $con->prepare("UPDATE incident_reports SET status = 'resolved', updated_at = NOW() WHERE id = ?");
    $stmt->bind_param('i', $rid);
    $ok = $stmt->execute();
    $stmt->close();
    // Notify resident if report is linked to a user
    if ($ok && ($con instanceof mysqli)) {
      $uid = 0;
      $q = $con->prepare("SELECT user_id FROM incident_reports WHERE id = ? LIMIT 1");
      $q->bind_param('i', $rid);
      $q->execute();
      $q->bind_result($uid);
      $q->fetch();
      $q->close();
      if ($uid && $uid > 0) {
        // Ensure notifications table exists
        $con->query("CREATE TABLE IF NOT EXISTS notifications (
          id INT AUTO_INCREMENT PRIMARY KEY,
          user_id INT NULL,
          entry_pass_id INT NULL,
          title VARCHAR(255) NOT NULL,
          message TEXT NOT NULL,
          is_read TINYINT(1) DEFAULT 0,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
          INDEX idx_user_id (user_id),
          INDEX idx_is_read (is_read)
        ) ENGINE=InnoDB");
        $title = 'Incident Resolved';
        $message = 'Your incident report (ID: ' . $rid . ') has been resolved.';
        $type = 'success';
        $n = $con->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?, ?, ?, ?)");
        if ($n) { $n->bind_param('isss', $uid, $title, $message, $type); $n->execute(); $n->close(); }
      }
    }
    echo json_encode(['success' => $ok]);
  } else {
    echo json_encode(['success' => false]);
  }
  exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'get_notifications') {
  header('Content-Type: application/json');
  ensureNotificationsTable($con);
  $items = [];
  $incidentCount = 0;
  $ir = $con->query("SELECT id, status, created_at, UNIX_TIMESTAMP(created_at) AS epoch FROM incident_reports WHERE COALESCE(escalated_to_admin,0) = 0 AND (status IS NULL OR LOWER(TRIM(status)) <> 'cancelled') ORDER BY created_at DESC LIMIT 12");
  if ($ir) {
    while ($row = $ir->fetch_assoc()) {
      $items[] = [
        'id' => intval($row['id']),
        'type' => 'incident',
        'label' => 'Incident',
        'source' => 'report',
        'title' => 'Incident reported',
        'message' => 'Status: ' . ($row['status'] ?? 'new'),
        'time' => $row['created_at'],
        'epoch' => intval($row['epoch'] ?? 0)
      ];
      $incidentCount++;
    }
  }
  $arrivalCount = 0;
  $start = date('Y-m-d');
  $end = date('Y-m-d', strtotime('+7 day'));
  $normalize = function($v){
    if(!$v) return null;
    $formats = [
      'Y-m-d','m/d/Y','d/m/Y','M d, Y','F d, Y','Y/m/d','d-m-Y',
      'Y-m-d H:i:s','m/d/Y H:i:s','d/m/Y H:i:s','d-m-Y H:i:s'
    ];
    foreach($formats as $fmt){
      $dt = DateTime::createFromFormat($fmt, trim($v));
      if($dt) return $dt->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d',$ts) : null;
  };
  $addArrival = function($ref, $name, $sd, $ed, $status) use (&$items, &$arrivalCount){
    $startDate = $sd ?: null;
    if(!$startDate) return;
    $endDate = $ed ?: $startDate;
    $dateLabel = ($endDate && $endDate !== $startDate) ? ($startDate . ' → ' . $endDate) : $startDate;
    $epoch = strtotime($startDate);
    $items[] = [
      'id' => $ref ?: ('arrival_' . ($arrivalCount + 1)),
      'type' => 'arrival',
      'label' => 'Arrival',
      'source' => 'schedule',
      'title' => 'Scheduled arrival approved',
      'message' => 'Code: ' . ($ref ?: '-') . ' • ' . ($name ?: '-') . ' • ' . $dateLabel,
      'time' => $startDate,
      'epoch' => $epoch ? intval($epoch) : 0
    ];
    $arrivalCount++;
  };
  $resGF = $con->query("SELECT ref_code, visitor_first_name, visitor_middle_name, visitor_last_name, visit_date, start_date, end_date, TRIM(approval_status) AS approval_status, approval_date FROM guest_forms WHERE LOWER(TRIM(approval_status))='approved'");
  if ($resGF) {
    while ($r = $resGF->fetch_assoc()) {
      $nm = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $sd = $normalize($r['visit_date'] ?? '') ?: $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $addArrival($r['ref_code'] ?? '', $nm, $sd, $ed, $r['approval_status'] ?? '');
    }
  }
  $resR = $con->query("SELECT r.ref_code, r.start_date, r.end_date, r.approval_date, TRIM(COALESCE(r.approval_status, r.status)) AS status, r.entry_pass_id, r.booking_for, r.account_type, r.guest_id, r.guest_ref_code, e.full_name AS ep_full_name, u.first_name, u.middle_name, u.last_name, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE LOWER(TRIM(COALESCE(r.approval_status, r.status)))='approved'");
  if ($resR) {
    while ($r = $resR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $full = trim(($r['ep_full_name'] ?? '')) ?: trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? '')) ?: trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $addArrival($r['ref_code'] ?? '', $full, $sd, $ed, $r['status'] ?? '');
    }
  }
  $resRR = $con->query("SELECT rr.ref_code, rr.start_date, rr.end_date, rr.approval_date, rr.approval_status, u.first_name, u.middle_name, u.last_name, r2.booking_for, r2.account_type, r2.guest_id, r2.guest_ref_code, r2.entry_pass_id, gf2.visitor_first_name, gf2.visitor_middle_name, gf2.visitor_last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id LEFT JOIN reservations r2 ON rr.ref_code = r2.ref_code LEFT JOIN guest_forms gf2 ON rr.ref_code = gf2.ref_code WHERE LOWER(rr.approval_status)='approved'");
  if ($resRR) {
    while ($r = $resRR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $fullResident = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $fullGuest = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $bookingFor = strtolower(trim($r['booking_for'] ?? ''));
      $accountType = strtolower(trim($r['account_type'] ?? ''));
      $hasGuestRef = !empty($r['guest_ref_code']) || !empty($r['guest_id']);
      $isVisitor = ($bookingFor === 'guest') || ($accountType === 'visitor') || $hasGuestRef || !empty($r['entry_pass_id']);
      $full = ($isVisitor && $fullGuest !== '') ? $fullGuest : $fullResident;
      $addArrival($r['ref_code'] ?? '', $full, $sd, $ed, $r['approval_status'] ?? '');
    }
  }
  usort($items, function($a, $b){
    $ea = isset($a['epoch']) ? intval($a['epoch']) : 0;
    $eb = isset($b['epoch']) ? intval($b['epoch']) : 0;
    if ($eb === $ea) return 0;
    return ($eb > $ea) ? 1 : -1;
  });
  echo json_encode([
    'total' => ($incidentCount + $arrivalCount),
    'items' => array_slice($items, 0, 12)
  ]);
  exit;
}
if (isset($_GET['action']) && $_GET['action'] === 'dismiss_notification' && isset($_GET['id'])) {
  header('Content-Type: application/json');
  ensureNotificationsTable($con);
  $nid = intval($_GET['id']);
  if ($nid > 0) {
    $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE id = ?");
    if ($stmt) {
      $stmt->bind_param('i', $nid);
      $stmt->execute();
      $stmt->close();
    }
  }
  echo json_encode(['success' => true]);
  exit;
}
// API: list today's entry scans
if (isset($_GET['action']) && $_GET['action'] === 'list_today_scans') {
  header('Content-Type: application/json');
  $rows = [];
  $hasGFVisitTime = false;
  $chkGFVisitTime = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'visit_time'");
  if ($chkGFVisitTime && $chkGFVisitTime->num_rows > 0) { $hasGFVisitTime = true; }
  $hasResStartTime = false;
  $chkResStartTime = $con->query("SHOW COLUMNS FROM reservations LIKE 'start_time'");
  if ($chkResStartTime && $chkResStartTime->num_rows > 0) { $hasResStartTime = true; }
  $hasResEndTime = false;
  $chkResEndTime = $con->query("SHOW COLUMNS FROM reservations LIKE 'end_time'");
  if ($chkResEndTime && $chkResEndTime->num_rows > 0) { $hasResEndTime = true; }
  $hasRRStartTime = false;
  $chkRRStartTime = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
  if ($chkRRStartTime && $chkRRStartTime->num_rows > 0) { $hasRRStartTime = true; }
  $hasRREndTime = false;
  $chkRREndTime = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
  if ($chkRREndTime && $chkRREndTime->num_rows > 0) { $hasRREndTime = true; }
  $q = "SELECT e.ref_code, e.subject_name, e.entry_type, e.status, e.start_date, e.end_date, e.scanned_at, e.scanned_by_name, " .
       ($hasGFVisitTime ? "gf.visit_time AS gf_start_time" : "NULL AS gf_start_time") . ", " .
       ($hasResStartTime ? "r.start_time AS r_start_time" : "NULL AS r_start_time") . ", " .
       ($hasResEndTime ? "r.end_time AS r_end_time" : "NULL AS r_end_time") . ", " .
       ($hasRRStartTime ? "rr.start_time AS rr_start_time" : "NULL AS rr_start_time") . ", " .
       ($hasRREndTime ? "rr.end_time AS rr_end_time" : "NULL AS rr_end_time") . ", " .
       "gf.amenity AS gf_amenity, r.amenity AS r_amenity, rr.amenity AS rr_amenity, r.booked_by_name AS r_booked_by, " .
       "u_gf.first_name AS gf_res_first, u_gf.middle_name AS gf_res_middle, u_gf.last_name AS gf_res_last
        FROM entry_scans e
        LEFT JOIN guest_forms gf ON e.ref_code = gf.ref_code
        LEFT JOIN users u_gf ON gf.resident_user_id = u_gf.id
        LEFT JOIN reservations r ON e.ref_code = r.ref_code
        LEFT JOIN resident_reservations rr ON e.ref_code = rr.ref_code
        INNER JOIN (
          SELECT ref_code, MAX(scanned_at) AS ms
          FROM entry_scans
          WHERE DATE(scanned_at) = CURDATE()
          GROUP BY ref_code
        ) t ON e.ref_code = t.ref_code AND e.scanned_at = t.ms
        WHERE (e.scanned_by_guard_id IS NOT NULL OR TRIM(COALESCE(e.scanned_by_name,'')) <> '')
        ORDER BY e.scanned_at DESC";
  $res = $con->query($q);
  if ($res) {
    while ($r = $res->fetch_assoc()) {
      $statusLower = strtolower(trim($r['status'] ?? ''));
      if ($statusLower === '' || (strpos($statusLower, 'permission') === false && strpos($statusLower, 'granted') === false && strpos($statusLower, 'access') === false)) {
        continue;
      }
      $startTime = $r['r_start_time'] ?? null;
      if (!$startTime) { $startTime = $r['rr_start_time'] ?? null; }
      if (!$startTime) { $startTime = $r['gf_start_time'] ?? null; }
      $endTime = $r['r_end_time'] ?? null;
      if (!$endTime) { $endTime = $r['rr_end_time'] ?? null; }
      $amenity = $r['r_amenity'] ?? null;
      if (!$amenity) { $amenity = $r['rr_amenity'] ?? null; }
      if (!$amenity) { $amenity = $r['gf_amenity'] ?? null; }
      $scannedBy = $r['scanned_by_name'] ?? null;
      $addedBy = $scannedBy ?: ($r['r_booked_by'] ?? null);
      if (!$addedBy) {
        $addedBy = trim(($r['gf_res_first'] ?? '') . ' ' . ($r['gf_res_middle'] ?? '') . ' ' . ($r['gf_res_last'] ?? ''));
      }
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $r['subject_name'],
        'added_by' => $addedBy !== '' ? $addedBy : null,
        'type' => $r['entry_type'],
        'amenity' => $amenity,
        'start_date' => $r['start_date'],
        'end_date' => $r['end_date'],
        'start_time' => $startTime,
        'end_time' => $endTime,
        'status' => $r['status']
      ];
    }
  }
  echo json_encode(['success' => true, 'entries' => $rows]);
  exit;
}
?>
<?php
if (isset($_GET['action']) && $_GET['action'] === 'list_expected') {
  header('Content-Type: application/json');
  $startParam = isset($_GET['start']) ? $_GET['start'] : null;
  $endParam = isset($_GET['end']) ? $_GET['end'] : null;
  $start = $startParam ? date('Y-m-d', strtotime($startParam)) : date('Y-m-d');
  $end = $endParam ? date('Y-m-d', strtotime($endParam)) : date('Y-m-d', strtotime('+6 day'));
  $rows = [];
  $normalize = function($v){
    if(!$v) return null;
    $formats = [
      'Y-m-d','m/d/Y','d/m/Y','M d, Y','F d, Y','Y/m/d','d-m-Y',
      'Y-m-d H:i:s','m/d/Y H:i:s','d/m/Y H:i:s','d-m-Y H:i:s'
    ];
    foreach($formats as $fmt){
      $dt = DateTime::createFromFormat($fmt, trim($v));
      if($dt) return $dt->format('Y-m-d');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d',$ts) : null;
  };
  $hasGFResDate = false;
  $chkGFResDate = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'reservation_date'");
  if ($chkGFResDate && $chkGFResDate->num_rows > 0) { $hasGFResDate = true; }
  $hasResDate = false;
  $chkResDate = $con->query("SHOW COLUMNS FROM reservations LIKE 'reservation_date'");
  if ($chkResDate && $chkResDate->num_rows > 0) { $hasResDate = true; }
  $hasRRResDate = false;
  $chkRRResDate = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'reservation_date'");
  if ($chkRRResDate && $chkRRResDate->num_rows > 0) { $hasRRResDate = true; }
  $hasGFVisitTime = false;
  $chkGFVisitTime = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'visit_time'");
  if ($chkGFVisitTime && $chkGFVisitTime->num_rows > 0) { $hasGFVisitTime = true; }
  $hasResStartTime = false;
  $chkResStartTime = $con->query("SHOW COLUMNS FROM reservations LIKE 'start_time'");
  if ($chkResStartTime && $chkResStartTime->num_rows > 0) { $hasResStartTime = true; }
  $hasResEndTime = false;
  $chkResEndTime = $con->query("SHOW COLUMNS FROM reservations LIKE 'end_time'");
  if ($chkResEndTime && $chkResEndTime->num_rows > 0) { $hasResEndTime = true; }
  $hasRRStartTime = false;
  $chkRRStartTime = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'start_time'");
  if ($chkRRStartTime && $chkRRStartTime->num_rows > 0) { $hasRRStartTime = true; }
  $hasRREndTime = false;
  $chkRREndTime = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'end_time'");
  if ($chkRREndTime && $chkRREndTime->num_rows > 0) { $hasRREndTime = true; }
  $gfScanWhere = " AND NOT EXISTS (SELECT 1 FROM entry_scans es WHERE es.ref_code = gf.ref_code)";
  $resScanWhere = " AND NOT EXISTS (SELECT 1 FROM entry_scans es WHERE es.ref_code = r.ref_code)";
  $rrScanWhere = " AND NOT EXISTS (SELECT 1 FROM entry_scans es WHERE es.ref_code = rr.ref_code)";
$resGF = $con->query("SELECT gf.ref_code, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name, gf.visit_date, gf.start_date, gf.end_date, gf.amenity, " . ($hasGFResDate ? "gf.reservation_date" : "NULL AS reservation_date") . ", " . ($hasGFVisitTime ? "gf.visit_time AS start_time" : "NULL AS start_time") . ", NULL AS end_time, TRIM(gf.approval_status) AS approval_status, gf.approval_date, u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name FROM guest_forms gf LEFT JOIN users u ON gf.resident_user_id = u.id WHERE LOWER(TRIM(gf.approval_status)) IN ('approved','permission_granted','permission granted','access_granted','access granted')" . $gfScanWhere);
  if ($resGF) {
    while ($r = $resGF->fetch_assoc()) {
      $nm = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $sd = $normalize($r['visit_date'] ?? '') ?: $normalize($r['start_date'] ?? '') ?: $normalize($r['reservation_date'] ?? '');
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd){
        $sd = $start;
        $ed = $start;
      }
      if($ed < $start || $sd > $end) continue;
      $addedBy = trim(($r['res_first_name'] ?? '').' '.($r['res_middle_name'] ?? '').' '.($r['res_last_name'] ?? ''));
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => ($nm !== '' ? $nm : '-'),
        'added_by' => ($addedBy !== '' ? $addedBy : '-'),
        'type' => 'Guest Entry',
        'amenity' => $r['amenity'] ?? null,
        'start_date' => $sd,
        'end_date' => $ed,
        'start_time' => $r['start_time'] ?? null,
        'end_time' => $r['end_time'] ?? null,
        'status' => $r['approval_status']
      ];
    }
  }
  $resR = $con->query("SELECT r.ref_code, r.start_date, r.end_date, r.amenity, " . ($hasResDate ? "r.reservation_date" : "NULL AS reservation_date") . ", " . ($hasResStartTime ? "r.start_time" : "NULL AS start_time") . ", " . ($hasResEndTime ? "r.end_time" : "NULL AS end_time") . ", r.approval_date, TRIM(COALESCE(r.approval_status, r.status)) AS status, r.entry_pass_id, r.booking_for, r.account_type, r.guest_id, r.guest_ref_code, e.full_name AS ep_full_name, u.first_name, u.middle_name, u.last_name, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name FROM reservations r LEFT JOIN entry_passes e ON r.entry_pass_id = e.id LEFT JOIN users u ON r.user_id = u.id LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE LOWER(TRIM(COALESCE(r.approval_status, r.status)))='approved'" . $resScanWhere);
  if ($resR) {
    while ($r = $resR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: $normalize($r['reservation_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $full = trim(($r['ep_full_name'] ?? '')) ?: trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? '')) ?: trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $bookingFor = strtolower(trim($r['booking_for'] ?? ''));
      $accountType = strtolower(trim($r['account_type'] ?? ''));
      $hasGuestRef = !empty($r['guest_ref_code']) || !empty($r['guest_id']);
      $isVisitor = ($bookingFor === 'guest') || ($accountType === 'visitor') || $hasGuestRef || !empty($r['entry_pass_id']);
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $full,
        'type' => ($isVisitor ? 'Visitor Reservation' : 'Resident Reservation'),
        'amenity' => $r['amenity'] ?? null,
        'start_date' => $sd,
        'end_date' => $ed,
        'start_time' => $r['start_time'] ?? null,
        'end_time' => $r['end_time'] ?? null,
        'status' => $r['status']
      ];
    }
  }
  $resRR = $con->query("SELECT rr.ref_code, rr.start_date, rr.end_date, rr.amenity, " . ($hasRRResDate ? "rr.reservation_date" : "NULL AS reservation_date") . ", " . ($hasRRStartTime ? "rr.start_time" : "NULL AS start_time") . ", " . ($hasRREndTime ? "rr.end_time" : "NULL AS end_time") . ", rr.approval_date, rr.approval_status, u.first_name, u.middle_name, u.last_name, r2.booking_for, r2.account_type, r2.guest_id, r2.guest_ref_code, r2.entry_pass_id, gf2.visitor_first_name, gf2.visitor_middle_name, gf2.visitor_last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id LEFT JOIN reservations r2 ON rr.ref_code = r2.ref_code LEFT JOIN guest_forms gf2 ON rr.ref_code = gf2.ref_code WHERE LOWER(TRIM(rr.approval_status))='approved'" . $rrScanWhere);
  if ($resRR) {
    while ($r = $resRR->fetch_assoc()) {
      $sd = $normalize($r['start_date'] ?? '') ?: $normalize($r['reservation_date'] ?? '') ?: ($r['approval_date'] ? date('Y-m-d', strtotime($r['approval_date'])) : null);
      $ed = $normalize($r['end_date'] ?? '') ?: $sd;
      if(!$sd) continue;
      if($ed < $start || $sd > $end) continue;
      $fullResident = trim(($r['first_name'] ?? '').' '.($r['middle_name'] ?? '').' '.($r['last_name'] ?? ''));
      $fullGuest = trim(($r['visitor_first_name'] ?? '').' '.($r['visitor_middle_name'] ?? '').' '.($r['visitor_last_name'] ?? ''));
      $bookingFor = strtolower(trim($r['booking_for'] ?? ''));
      $accountType = strtolower(trim($r['account_type'] ?? ''));
      $hasGuestRef = !empty($r['guest_ref_code']) || !empty($r['guest_id']);
      $isVisitor = ($bookingFor === 'guest') || ($accountType === 'visitor') || $hasGuestRef || !empty($r['entry_pass_id']);
      $full = ($isVisitor && $fullGuest !== '') ? $fullGuest : $fullResident;
      $rows[] = [
        'code' => $r['ref_code'],
        'name' => $full,
        'type' => ($isVisitor ? 'Visitor Reservation' : 'Resident Reservation'),
        'amenity' => $r['amenity'] ?? null,
        'start_date' => $sd,
        'end_date' => $ed,
        'start_time' => $r['start_time'] ?? null,
        'end_time' => $r['end_time'] ?? null,
        'status' => $r['approval_status']
      ];
    }
  }
  echo json_encode(['success' => true, 'entries' => $rows, 'range' => ['start'=>$start, 'end'=>$end]]);
  exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Victorian Pass | Guard</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="css/reserve.css">
<style>
/* Modern Admin Dashboard CSS (Imported from admin.php) */
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
    gap: 0;
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
    transition: width 0.25s ease, transform 0.25s ease;
    will-change: width, transform;
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
.brand .title { display: flex; flex-direction: column; align-items: center; text-align: center; overflow: hidden; max-width: 200px; transition: var(--transition); }
.brand h1 { font-size: 1rem; color: #f4efe6; line-height: 1.2; }
.brand p { font-size: 0.75rem; color: rgba(255,255,255,0.7); margin: 0; }

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
    cursor: pointer;
}
.nav-item span { overflow: hidden; max-width: 220px; transition: var(--transition); }

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
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    color: rgba(255,255,255,0.7);
    transition: var(--transition);
}
.nav-item:hover i, .nav-item.active i {
    color: #fff;
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
.sidebar-footer .text-muted-link span { overflow: hidden; max-width: 180px; transition: var(--transition); }
.sidebar-footer .text-muted-link:hover { background: #a93226; color: #fff; }
.sidebar-footer .text-muted-link svg { width: 18px; height: 18px; flex-shrink: 0; }

/* Main Content Area */
.main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    background: var(--bg-body);
    animation: dashboardEntry 0.6s cubic-bezier(0.22, 1, 0.36, 1) forwards;
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
    z-index: 99;
    color: #fff;
    flex-wrap: wrap;
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
    cursor: pointer;
    transition: var(--transition);
    align-items: center;
    justify-content: center;
}
.sidebar-toggle:hover { background: rgba(255,255,255,0.2); }

.icon-btn {
    border: none;
    background: transparent;
    color: #fff;
    width: 40px;
    height: 40px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: var(--transition);
}
.icon-btn:hover { background: rgba(255,255,255,0.15); }
.icon-btn svg { width: 20px; height: 20px; }
.icon-btn img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }

.notifications { position: relative; }
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
.notif-btn img { width: 20px; height: 20px; display: block; }
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
@keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.5); } 100% { transform: scale(1); } }
.notif-panel {
    position: fixed;
    top: calc(var(--header-height) + 12px);
    right: 24px;
    margin-top: 0;
    width: 340px;
    max-height: 450px;
    background: var(--bg-surface);
    border-radius: var(--radius);
    box-shadow: var(--shadow-lg);
    overflow-y: auto;
    z-index: 200;
    border: 1px solid var(--border);
    display: none;
}
.notif-panel-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    position: sticky;
    top: 0;
    background: var(--bg-surface);
    z-index: 1;
}
.notif-panel-title { font-size: 0.88rem; font-weight: 600; color: var(--text-main); }
.notif-panel-close {
    border: none;
    background: transparent;
    color: var(--text-muted);
    font-size: 1rem;
    cursor: pointer;
    line-height: 1;
    padding: 0;
}
.notif-panel-body { padding: 6px 0; }
.notif-item {
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    display: flex;
    gap: 12px;
    transition: var(--transition);
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
    background: transparent;
    border: none;
    padding: 0;
    text-align: left;
    cursor: pointer;
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
.notif-unread-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--danger);
    display: inline-block;
    margin-left: 6px;
}
.notif-empty { padding: 10px 14px; font-size: 0.82rem; color: var(--text-muted); }
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideIn { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
@keyframes slideInLeft { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes dashboardEntry { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
@keyframes highlightRow { 0% { background-color: var(--warning-bg); } 100% { background-color: var(--primary-light); } }
.row-highlight { animation: highlightRow 2s ease-out; background-color: var(--primary-light) !important; }

body.sidebar-collapsed .sidebar { width: 72px; }
body.sidebar-collapsed .brand { justify-content: center; padding: 16px 12px; }
body.sidebar-collapsed .brand .title { opacity: 0; max-width: 0; }
body.sidebar-collapsed .nav-list { padding: 16px 8px; }
body.sidebar-collapsed .nav-item { justify-content: center; padding: 10px; gap: 0; }
body.sidebar-collapsed .nav-item span { opacity: 0; max-width: 0; }
body.sidebar-collapsed .sidebar-footer { padding: 16px 10px; }
body.sidebar-collapsed .sidebar-footer .text-muted-link span { opacity: 0; max-width: 0; }
body.sidebar-collapsed .sidebar-footer .text-muted-link { padding: 10px; width: 100%; }

.sidebar-overlay {
    display: block;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.5);
    z-index: 95;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s ease-in-out;
}
.sidebar-overlay.show {
    opacity: 1;
    pointer-events: auto;
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
    padding: 18px 30px 6px;
    display: flex;
    justify-content: flex-start;
    align-items: center;
    margin-bottom: 10px;
}

.page-header h2 { font-size: 1.5rem; color: var(--text-main); }

/* Dashboard Widgets & Panels */
.dashboard-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 24px;
    padding: 0 30px;
    margin-bottom: 30px;
}

.panel, .card, .card-box {
    background: var(--bg-surface);
    border-radius: var(--radius);
    padding: 24px;
    box-shadow: var(--shadow-sm);
    border: 1px solid var(--border);
    margin: 0 30px 30px 30px;
    overflow-x: auto;
    transition: var(--transition);
    will-change: transform;
}

.panel h3, .card-header, .card-box h3 {
    margin: 0 0 20px 0;
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text-main);
    border-bottom: 1px solid var(--border-light);
    padding-bottom: 15px;
    background: transparent;
}

.panel .card-box {
    box-shadow: none;
    border: none;
    padding: 0;
    margin: 0;
    background: transparent;
}

/* Tables */
table { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 800px; }
th, td { padding: 14px 16px; text-align: left; border-bottom: 1px solid var(--border-light); font-size: 0.9rem; vertical-align: middle; }
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
tbody tr { transition: background-color 0.2s ease-in-out; }

/* Responsive Table Container for Mobile Horizontal Scrolling */
.table-responsive-wrapper {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    border-radius: var(--radius);
}
.table-responsive-wrapper table {
    width: 100%;
    min-width: 800px;
}
@media (max-width: 768px) {
    .table-responsive-wrapper {
        margin: 0 -16px;
        padding: 0 16px;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        scroll-behavior: smooth;
    }
    .table-responsive-wrapper::after {
        content: '';
        display: block;
        width: 8px;
        flex-shrink: 0;
    }
    table {
        font-size: 0.85rem;
    }
    th, td {
        padding: 10px 12px;
        font-size: 0.80rem;
        white-space: nowrap;
    }
}

/* Buttons */
.action-btn, .btn {
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
    color: #fff;
    gap: 6px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
    transform: translateZ(0);
}
.action-btn.approve, .btn-approve { background: var(--success); }
.action-btn.deny, .btn-reject { background: var(--danger); }
.action-btn:hover, .btn:hover {
    filter: brightness(92%);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.action-btn:active, .btn:active { transform: translateY(0); box-shadow: none; }
.action-btn:focus-visible, .btn:focus-visible { box-shadow: 0 0 0 3px rgba(35, 65, 46, 0.2); }
.btn-view { background: var(--info); color: #fff; }
.btn-view:hover { background: #2563eb; }
.btn.is-clicked {
    background: #111827 !important;
    color: #fff !important;
    box-shadow: 0 0 0 3px rgba(17, 24, 39, 0.2) !important;
}

/* Guard Specific Adapters */
.section.hidden { display: none; }
.dashboard { display: grid; grid-template-columns: 1fr; gap: 24px; padding: 0 30px; margin-bottom: 30px; }

/* Toast */
.toast {
    display: none;
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: linear-gradient(135deg, #f59e0b, #fcd34d);
    border-left: 6px solid #b45309;
    box-shadow: 0 12px 24px rgba(180, 83, 9, 0.35);
    border-radius: 8px;
    padding: 16px;
    width: min(96vw, 380px);
    z-index: 2000;
    opacity: 0;
    transition: opacity 0.2s ease-in-out, transform 0.2s ease-in-out;
    color: #1f2937;
    flex-direction: column;
    gap: 8px;
    max-height: 40vh;
    overflow-y: auto;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    white-space: normal;
    hyphens: auto;
    font-weight: 700;
    border: 1px solid #f59e0b;
}
.toast.show { display: flex; opacity: 1; transform: translateY(0); animation: slideInLeft 0.3s; }

.nav-item {
    transition: var(--transition);
}

.panel h3, .card-header, .card-box h3 {
    transition: var(--transition);
}

.fade-in {
    animation: fadeInUp 0.4s ease both;
}
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(6px); }
    to { opacity: 1; transform: translateY(0); }
}
@media (prefers-reduced-motion: reduce) {
    * { transition: none !important; animation: none !important; }
}

.scan-row{
    display:flex;
    gap:15px;
    align-items:center;
    margin-bottom:25px;
    flex-wrap:wrap;
}
.scan-input{ flex:1; min-width:250px; }
.scan-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
}

@media (max-width: 768px) {
    .scan-row {
        flex-direction: column;
        align-items: stretch;
    }
    .scan-input {
        min-width: auto;
        width: 100%;
        flex: none;
    }
    .scan-input input {
        width: 100%;
        box-sizing: border-box;
    }
    .scan-actions {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        width: 100%;
    }
}

/* Responsive */
@media(max-width:900px){
    .app { display: block; }
    .sidebar {
        position: fixed;
        left: 0;
        top: 0;
        height: 100vh;
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        box-shadow: 2px 0 10px rgba(0,0,0,0.2);
        z-index: 100;
        width: min(86vw, var(--sidebar-width));
    }
    .sidebar.open { transform: translateX(0); }
    .sidebar-overlay.show { display: block; }
    .main { width: 100%; }
    .dashboard { grid-template-columns: 1fr; }
    .top-header { 
        height: auto;
        min-height: var(--header-height);
        position: sticky;
        top: 0;
        left: 0;
        right: 0;
        z-index: 99;
        width: 100%;
        box-sizing: border-box;
    }
    .main {
        padding-top: 0;
    }
    table { min-width: 100%; }
    th, td { white-space: normal; word-break: break-word; }
    th { position: static; }
    .panel { overflow-x: auto; max-width: 100%; }
    .notif-panel { right: 16px; width: min(92vw, 360px); }
    .table-responsive-wrapper { max-width: 100%; }
    .main { overflow-x: hidden; }
}
.dashboard .panel { margin: 0; }

.scan-search {
    background: var(--bg-surface);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 10px 15px;
    width: 100%;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
    color: var(--text-main);
    font-size: 0.95rem;
    box-sizing: border-box;
}
.scan-search:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(35, 65, 46, 0.1);
    transform: translateY(-1px);
}

/* Scan Row Base Styles */
.scan-row {
    display: flex;
    gap: 15px;
    align-items: center;
    margin-bottom: 25px;
    flex-wrap: wrap;
    padding: 0 30px;
}
.scan-input-row{
    display:flex;
    gap:10px;
    align-items:center;
    flex-wrap:nowrap;
    width:100%;
}
.scan-input-row .scan-search{
    flex:1;
    min-width:0;
}
.scan-confirm-btn{
    white-space:nowrap;
    min-width:140px;
}
.scan-input {
    flex: 1;
    min-width: 250px;
}
.scan-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.scan-actions .btn {
    flex: 0 1 auto;
    min-width: 120px;
}
@media (min-width: 901px) {
    .scan-row { align-items: center; }
    .scan-input { flex: 1 1 420px; max-width: 520px; }
    .scan-input-row { max-width: 520px; }
    .scan-actions { margin-left: auto; }
    .scan-actions .btn { min-width: 140px; }
    .scan-confirm-btn { min-width: 140px; }
    .qr-scanner-panel { max-width: 560px; }
}

/* Expected Controls Mobile Responsive */
.expected-controls {
    display: flex;
    flex-direction: column;
    gap: 12px;
    margin: 0 30px 12px 30px;
}
.quick-filters {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}
.quick-filters .btn {
    flex: 1;
    min-width: 100px;
}
.custom-range {
    display: flex;
    gap: 12px;
    align-items: center;
}
.custom-range input {
    flex: 1;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 0.9rem;
}
.custom-range .btn {
    white-space: nowrap;
}
.section-label {
    margin: 6px 30px 8px;
    font-weight: 600;
    color: #2c3e50;
}
.section-label.spaced {
    margin-top: 16px;
}

@media (max-width: 768px) {
    .expected-controls {
    }
    .quick-filters {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
    }
    .quick-filters .btn {
        flex: none;
        min-width: auto;
    }
    .custom-range {
        flex-direction: column;
    }
    .custom-range input {
        width: 100%;
        box-sizing: border-box;
    }
    .custom-range .btn {
        width: 100%;
    }
}
.scan-search:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(35, 65, 46, 0.1);
    transform: translateY(-1px);
}
.qr-scanner-panel{
    margin-top:12px;
    padding:12px;
    border:1px solid #e5e7eb;
    border-radius:16px;
    background:var(--bg-surface);
    display:none;
}
.qr-scanner-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    margin-bottom:10px;
}
.qr-scanner-title{
    font-weight:700;
    color:var(--text-main);
}
.qr-scanner-close{
    background:#e5e7eb;
    color:#111827;
    border:none;
    padding:8px 12px;
    border-radius:8px;
    font-weight:600;
    font-family:'Poppins', sans-serif;
    cursor:pointer;
}
.qr-scanner-close:hover{ background:#d1d5db; }
.qr-video{
    width:100%;
    max-height:360px;
    background:#000;
    border-radius:12px;
    aspect-ratio: 4 / 3;
    object-fit: cover;
    height: auto;
}
.qr-scanner-msg{
    margin-top:8px;
    font-size:0.9rem;
    color:#6b7280;
    font-weight:600;
}
.qr-scanner-msg.error{ color:#b91c1c; font-weight:700; }
.scan-actions .btn{ min-height: 40px; }
.profile-mini {
    display: flex;
    align-items: center;
    gap: 12px;
    height: 100%;
}
.profile-mini img {
    margin: 0;
    display: block;
}
.profile-mini span {
    font-weight: 500;
    line-height: 1;
    display: block;
}
.logout-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: #c0392b;
    color: #fff;
    border-radius: 10px;
    padding: 10px 12px;
    text-decoration: none;
    font-weight: 600;
    transition: background-color 0.2s ease, transform 0.2s ease;
}
.logout-btn:hover { background: #a93226; color: #fff; }
.modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(4px); align-items: center; justify-content: center; }
.modal.open { display: flex; }
.modal.modal-top { z-index: 3000; }
.modal-content { background-color: var(--bg-surface); margin: 0; padding: 0; border: 1px solid var(--border); border-radius: 14px; box-shadow: var(--shadow-lg); position: relative; display: flex; flex-direction: column; gap: 12px; width: min(92vw, 640px); aspect-ratio: auto; max-height: 90vh; overflow: hidden; animation: slideIn 0.3s ease-out; }
.modal-content h3 { padding: 12px 16px; border-bottom: 1px solid var(--border-light); margin: 0; font-size: 1.05rem; background: var(--bg-surface); position: sticky; top: 0; z-index: 10; color: #23412e; font-weight: 700; }
.modal.closing { animation: fadeIn 0.2s ease-out reverse; }
.modal.closing .modal-content { animation: slideIn 0.2s ease-out reverse; }
.modal-close {
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
.modal-close:hover { filter: brightness(0.95); transform: scale(1.05); }
.modal-header { display: flex; justify-content: space-between; align-items: center; }
.incident-details-content { overflow-y: auto; flex: 1; padding: 18px 20px 22px; }
.details-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.proofs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.proofs img { width: 120px; height: 90px; object-fit: cover; border: 1px solid var(--border); border-radius: 6px; cursor: zoom-in; transition: transform 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.2s ease, border-color 0.2s ease; }
.proofs img:hover { transform: translateY(-1px) scale(1.02); border-color: #cbd5e1; box-shadow: 0 6px 14px rgba(15, 23, 42, 0.12); }
.image-modal .modal-content {
    background: transparent;
    border: none;
    box-shadow: none;
    width: auto;
    max-width: 90vw;
    max-height: 90vh;
}
.image-modal img {
    max-width: 90vw;
    max-height: 80vh;
    border-radius: 12px;
    border: 2px solid #fff;
    display: block;
}
.proofs a { display: inline-block; padding: 6px 10px; background: #f8fafc; border: 1px solid var(--border); border-radius: 6px; font-size: 0.85rem; }
@media(max-width:768px){
    .details-grid{ grid-template-columns: 1fr; }
    .notif-panel{ right: 12px; top: calc(var(--header-height) + 8px); }
}
@media(max-width:600px){
    .top-header{ gap: 12px; }
    .header-brand{ width: 100%; }
    .header-actions{ width: 100%; justify-content: flex-start; flex-wrap: wrap; gap: 10px; }
    .header-subtitle{ display: none; }
    .scan-actions{ grid-template-columns: repeat(2, 1fr); }
    .quick-filters{ grid-template-columns: repeat(2, 1fr); }
    .scan-input-row{
        flex-direction: column;
        align-items: stretch;
    }
    .scan-confirm-btn{
        width: 100%;
        min-width: 0;
    }
}
@media(max-width:420px){
    .scan-actions{ grid-template-columns: 1fr; }
    .quick-filters{ grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="app">
<aside class="sidebar">
  <div class="brand">
    <img src="images/logo.svg" alt="VictorianPass logo">
  </div>
  <nav class="nav-list">
    <div class="nav-item" data-section="dashboard"><i class="fa-solid fa-gauge-high"></i><span>Dashboard</span></div>
    <div class="nav-item active" data-section="expected"><i class="fa-solid fa-calendar-check"></i><span>Scheduled Arrivals</span></div>
    <div class="nav-item" data-section="entries"><i class="fa-solid fa-right-to-bracket"></i><span>Today's Entry</span></div>
    <div class="nav-item" data-section="restricted"><i class="fa-solid fa-triangle-exclamation"></i><span>Incident Reports</span></div>
  </nav>
  <div class="sidebar-footer">
    <a href="logout.php" class="text-muted-link">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M16 17v-2H7v-6h9V7l5 5-5 5zm-11 3h8v2H5a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h8v2H5v16z"/></svg>
      <span>Log Out</span>
    </a>
  </div>
</aside>
<main class="main">
  <div class="sidebar-overlay" id="sidebarOverlay"></div>
  <header class="top-header">
    <div class="header-brand">
      <button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle sidebar" title="Toggle sidebar">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 6h18v2H3V6zm0 5h18v2H3v-2zm0 5h18v2H3v-2z"/></svg>
      </button>
      <div class="header-brand-text">
        <div class="header-title">Guard Panel</div>
        <div class="header-subtitle">Victorian Heights</div>
      </div>
    </div>
    <div class="header-actions">
      <div class="notifications">
        <button id="notifToggle" class="notif-btn" aria-label="Notifications" title="Notifications">
          <img alt="Notifications" src="data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><path d='M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4a1.5 1.5 0 10-3 0v.68C7.63 5.36 6 7.92 6 11v5l-2 2v1h16v-1l-2-2z' fill='%23fff'/></svg>" />
        </button>
        <div id="notifPanel" class="notif-panel" style="display:none"></div>
      </div>
      <button type="button" class="icon-btn" aria-label="Profile" title="Profile">
        <img src="images/mainpage/profile'.jpg" alt="Profile">
      </button>
    </div>
  </header>
  <div class="page-header">
    <h2 id="page-title">Scheduled Arrivals</h2>
  </div>
  <div id="toast" class="toast"></div>
  <div id="dashboardSection" class="dashboard section hidden">
    <div class="panel">
      <h3>Today's Entry</h3>
      <div class="scan-row">
        <div class="scan-input">
             <div class="scan-input-row">
               <input id="scanCode" type="text" class="scan-search" placeholder="Enter reference code">
               <button class="btn btn-approve scan-confirm-btn" onclick="scanCode()">Confirm Scan</button>
             </div>
        </div>
        <div class="scan-actions">
            <button class="btn" id="scanQrBtn" style="background:#111827;color:#fff"><i class="fas fa-camera"></i> Scan QR Code</button>
            <button class="btn" onclick="openStatusCard()" style="background:#23412e;color:#fff">Open QR Card</button>
        </div>
      </div>
      <div id="qrScannerPanel" class="qr-scanner-panel">
        <div class="qr-scanner-head">
          <div class="qr-scanner-title">QR Scanner</div>
          <button type="button" class="qr-scanner-close" onclick="stopQrScanner()">Close</button>
        </div>
        <video id="qrVideo" class="qr-video" playsinline></video>
        <div id="qrScannerMsg" class="qr-scanner-msg"></div>
      </div>
      <div class="content-row">
      <div class="table-responsive-wrapper">
        <table id="entryTable">
          <tr><th>Code</th><th>Type</th><th>Amenity Reserve</th><th>Reservation Schedule</th><th>Status</th></tr>
          <tr id="emptyRow"><td colspan="5" style="text-align:center;color:#6b6b6b">Awaiting scans...</td></tr>
        </table>
      </div>
    </div>
    </div>
    <div class="panel">
      <h3>Restricted</h3>
      <div class="table-responsive-wrapper">
        <table>
          <tr><th>IP</th><th>Image</th><th>Name</th><th>Status</th></tr>
          <tr id="restrictedEmpty"><td colspan="4" style="text-align:center;color:#6b6b6b">No restricted entries</td></tr>
        </table>
      </div>
    </div>
  </div>
  <div id="entriesSection" class="section hidden">
    <div class="panel">
      <h3>Today's Entry (Detailed)</h3>
      <div class="table-responsive-wrapper">
        <table id="todayEntries" class="history-table">
          <tr><th>Code</th><th>Type</th><th>Amenity Reserve</th><th>Reservation Schedule</th><th>Status</th></tr>
          <tbody id="todayEntriesBody">
            <tr id="todayEmpty"><td colspan="5" style="text-align:center;color:#6b6b6b">No scans today</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div id="expectedSection" class="section">
    <div class="panel">
      <div class="expected-controls">
        <div class="quick-filters">
          <button class="btn" id="thisWeekBtn" style="background:var(--accent)">This Week</button>
          <button class="btn" id="weekFromStartBtn" style="background:#23412e">Next 7 Days</button>
          <button class="btn" id="next30Btn" style="background:#6b7280">Next 30 Days</button>
        </div>
        <div class="custom-range">
          <input type="date" id="expectedStart">
          <input type="date" id="expectedEnd">
          <button class="btn btn-view" id="applyExpected">Custom Range</button>
        </div>
      </div>
      <div class="section-label">Amenity Reservation</div>
      <div class="table-responsive-wrapper">
        <table id="expectedTable" class="history-table">
          <tr><th>Code</th><th>Name</th><th>Type</th><th>Amenity Reserve</th><th>Reservation Schedule</th><th>Status</th></tr>
          <tbody id="expectedBody">
            <tr id="expectedEmpty"><td colspan="6" style="text-align:center;color:#6b6b6b">No scheduled arrivals in selected range</td></tr>
          </tbody>
        </table>
      </div>
      <div class="section-label spaced">Guest Entries</div>
      <div class="table-responsive-wrapper">
        <table id="expectedGuestTable" class="history-table">
          <tr><th>Code</th><th>Added By</th><th>Type</th><th>Amenity Reserve</th><th>Reservation Schedule</th><th>Status</th></tr>
          <tbody id="expectedGuestBody">
            <tr id="expectedGuestEmpty"><td colspan="6" style="text-align:center;color:#6b6b6b">No guest entries in selected range</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div id="restrictedSection" class="section hidden">
    <div class="panel">
      <h3>Manage Reported Incidents</h3>
      <div class="table-responsive-wrapper">
        <table id="incidentTable">
          <thead>
            <tr><th>Report ID</th><th>Resident Name</th><th>Description</th><th>Report Date</th><th>Action</th></tr>
          </thead>
          <tbody id="incidentTableBody">
            <tr id="noIncidents"><td colspan="5" style="text-align:center;color:#6b6b6b">No incidents reported</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="section" id="historySection">
    <div class="panel">
      <h3>Your Login History</h3>
      <div style="margin-bottom:10px">
        <strong>Current Login:</strong>
        <span>
          <?php echo $currentLogin ? htmlspecialchars($currentLogin['login_time']) : '—'; ?>
        </span>
        <strong style="margin-left:16px">Current Logout:</strong>
        <span>
          <?php echo ($currentLogin && $currentLogin['logout_time']) ? htmlspecialchars($currentLogin['logout_time']) : 'Pending'; ?>
        </span>
      </div>
      <div class="table-responsive-wrapper">
        <table class="history-table">
          <tr><th>Login Time</th><th>Logout Time</th><th>Status</th></tr>
          <?php if (count($history) === 0) { ?>
            <tr><td colspan="3" style="text-align:center;color:#6b6b6b">No records</td></tr>
          <?php } else { foreach ($history as $h) { $st = ($h['logout_time'] ? 'Completed' : 'Pending'); ?>
            <tr>
              <td><?php echo htmlspecialchars($h['login_time']); ?></td>
              <td><?php echo htmlspecialchars($h['logout_time'] ?? ''); ?></td>
              <td><?php echo htmlspecialchars($st); ?></td>
            </tr>
          <?php } } ?>
        </table>
      </div>
    </div>
  </div>
</main>
</div>
<script>
const navItems = document.querySelectorAll('.nav-item');
const sections = document.querySelectorAll('.section');
const pageTitle = document.getElementById('page-title');
const sidebarToggle = document.getElementById('sidebarToggle');
const sidebar = document.querySelector('.sidebar');
const overlay = document.getElementById('sidebarOverlay');
const notifToggle = document.getElementById('notifToggle');
const notifPanel = document.getElementById('notifPanel');
let notifItems = [];
let toastTimer;
let notifMap = {};
let notifDismissed = new Set();
let lastNotifTotal = 0;
function isMobile(){
  return window.matchMedia('(max-width: 900px)').matches;
}
try { notifDismissed = new Set(JSON.parse(localStorage.getItem('guardNotifDismissed') || '[]')); } catch(_){}
function saveNotifDismissed(){ localStorage.setItem('guardNotifDismissed', JSON.stringify(Array.from(notifDismissed))); }

if(sidebarToggle && sidebar && overlay){
  function closeSidebar(){
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
  }
  sidebarToggle.addEventListener('click', function(){
    if(isMobile()){
      const willOpen = !sidebar.classList.contains('open');
      sidebar.classList.toggle('open', willOpen);
      overlay.classList.toggle('show', willOpen);
      return;
    }
    document.body.classList.toggle('sidebar-collapsed');
  });
  overlay.addEventListener('click', closeSidebar);
  window.addEventListener('resize', function(){
    if(!isMobile()){
      closeSidebar();
    }
  });
}
function setActiveSection(sectionKey){
  navItems.forEach(i=>i.classList.remove('active'));
  sections.forEach(s=>s.classList.add('hidden'));
  const activeItem = Array.from(navItems).find(i=>i.dataset.section === sectionKey);
  if(activeItem){
    activeItem.classList.add('active');
    pageTitle.textContent = activeItem.querySelector('span').textContent;
  }
  const target = document.getElementById(sectionKey+'Section');
  if(target) target.classList.remove('hidden');
  const ph = document.querySelector('.page-header');
  if(ph){ ph.style.display = ''; }
  if(sectionKey === 'entries'){ loadTodayEntries(); }
  if(sectionKey === 'expected'){
    const s=document.getElementById('expectedStart');
    const e=document.getElementById('expectedEnd');
    const sv=s&&s.value?s.value:formatInputDate(new Date());
    const ev=e&&e.value?e.value:formatInputDate(new Date(Date.now()+6*24*60*60*1000));
    loadExpected(sv,ev);
  }
}
navItems.forEach(item=>{ item.addEventListener('click',()=>{ 
  setActiveSection(item.dataset.section);
  if(isMobile() && sidebar && overlay){
    sidebar.classList.remove('open');
    overlay.classList.remove('show');
  }
}); });
function escapeHtml(value){
  return String(value||'').replace(/[&<>"']/g,function(m){
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]);
  });
}
function keyFor(it){ return [String(it.type||''), String(it.id||''), String(it.time||'')].join('|'); }
function getVisibleNotifs(){ return notifItems.filter(it=>!notifDismissed.has(keyFor(it))); }
function updateNotifBadge(total){
  if(!notifToggle) return;
  let badge = notifToggle.querySelector('.notif-badge');
  if(total > 0){
    if(!badge){ badge = document.createElement('span'); badge.className = 'notif-badge'; notifToggle.appendChild(badge); }
    badge.textContent = total > 99 ? '99+' : String(total);
    if(total > lastNotifTotal){
      badge.classList.remove('pulse');
      void badge.offsetWidth;
      badge.classList.add('pulse');
    }
  } else {
    if(badge) badge.remove();
  }
  lastNotifTotal = total;
}
function renderNotifPanel(){
  if(!notifPanel) return;
  const items = getVisibleNotifs();
  notifMap = {};
  let body = '';
  if(items.length === 0){
    body = `<div class="notif-empty">No new notifications</div>`;
  } else {
    items.forEach(it=>{
      const key = keyFor(it);
      notifMap[key] = it;
      const label = String(it.label || it.type || 'NTF').slice(0,3).toUpperCase();
      const title = escapeHtml(it.title || 'Notification');
      const msg = escapeHtml(it.message || '');
      const time = it.time ? formatDateTime(it.time) : '';
      body += `<div class="notif-item" data-key="${key}"><button type="button" class="notif-item-link"><span class="notif-type">${label}</span><div class="notif-meta"><strong>${title}<span class="notif-unread-dot"></span></strong><div>${msg}</div><div class="notif-item-time">${time}</div></div></button><button type="button" class="notif-dismiss" aria-label="Dismiss">×</button></div>`;
    });
  }
  notifPanel.innerHTML = `<div class="notif-panel-header"><div class="notif-panel-title">Notifications</div><button type="button" class="notif-panel-close" aria-label="Close">×</button></div><div class="notif-panel-body">${body}</div>`;
}
function extractRef(text){
  const val = String(text||'');
  let m = val.match(/Code:\s*([A-Z0-9\-]+)/i);
  if(m && m[1]) return m[1];
  m = val.match(/(?:Reservation|Request|Amenity|Guest)\s+([A-Z0-9\-]+)/i);
  if(m && m[1]) return m[1];
  return '';
}
function handleNotifClick(it){
  if(String(it.type||'') === 'incident'){
    setActiveSection('restricted');
    const id = parseInt(it.id||0);
    if(id){
      fetch(`guard.php?action=incident_details&id=${encodeURIComponent(id)}`).then(r=>r.json()).then(data=>{
        if(data&&data.success){ showIncidentDetailsModal(data.report, data.proofs||[]); }
      }).catch(_=>{});
    }
    return;
  }
  const ref = extractRef(it.message || it.title || '');
  if(ref){
    setActiveSection('expected');
    return;
  }
  setActiveSection('dashboard');
}
function refreshNotifications(){
  fetch('guard.php?action=get_notifications').then(r=>r.json()).then(data=>{
    notifItems = Array.isArray(data.items) ? data.items : [];
    updateNotifBadge(getVisibleNotifs().length);
    if(notifPanel && notifPanel.style.display === 'block'){ renderNotifPanel(); }
  }).catch(_=>{});
}
if(notifToggle && notifPanel){
  notifToggle.addEventListener('click', function(e){
    e.stopPropagation();
    const isOpen = notifPanel.style.display === 'block';
    if(isOpen){
      notifPanel.style.display = 'none';
      notifPanel.classList.remove('open');
      return;
    }
    renderNotifPanel();
    notifPanel.style.display = 'block';
    notifPanel.classList.add('open');
  });
  document.addEventListener('click', function(e){
    if(notifPanel.style.display !== 'block') return;
    if(notifPanel.contains(e.target) || notifToggle.contains(e.target)) return;
    notifPanel.style.display = 'none';
    notifPanel.classList.remove('open');
  });
  notifPanel.addEventListener('click', function(e){
    const closeBtn = e.target.closest('.notif-panel-close');
    if(closeBtn){
      notifPanel.style.display = 'none';
      notifPanel.classList.remove('open');
      return;
    }
    const dismissBtn = e.target.closest('.notif-dismiss');
    if(dismissBtn){
      const itemEl = dismissBtn.closest('.notif-item');
      const key = itemEl ? itemEl.getAttribute('data-key') : '';
      if(key && notifMap[key]){
        const it = notifMap[key];
        notifDismissed.add(key);
        saveNotifDismissed();
        if(String(it.type||'') === 'notification' && it.id){
          fetch(`guard.php?action=dismiss_notification&id=${encodeURIComponent(it.id)}`).catch(_=>{});
        }
      }
      renderNotifPanel();
      updateNotifBadge(getVisibleNotifs().length);
      return;
    }
    const itemBtn = e.target.closest('.notif-item-link');
    if(itemBtn){
      const itemEl = itemBtn.closest('.notif-item');
      const key = itemEl ? itemEl.getAttribute('data-key') : '';
      if(key && notifMap[key]){ handleNotifClick(notifMap[key]); }
      notifPanel.style.display = 'none';
      notifPanel.classList.remove('open');
    }
  });
}
function showToast(message, type){
  const toast = document.getElementById('toast');
  if(!toast){ return; }
  toast.textContent = message;
  const isError = type === 'error';
  toast.style.background = isError ? 'linear-gradient(135deg, #b45309, #f59e0b)' : 'linear-gradient(135deg, #f59e0b, #fcd34d)';
  toast.style.borderLeftColor = '#b45309';
  toast.style.color = '#1f2937';
  toast.classList.remove('show');
  toast.style.display = 'block';
  requestAnimationFrame(() => {
    toast.classList.add('show');
  });
  if(toastTimer){ clearTimeout(toastTimer); }
  toastTimer = setTimeout(() => {
    toast.classList.remove('show');
    setTimeout(() => {
      toast.style.display = 'none';
    }, 220);
  }, 2500);
}
const guardConfirmMsg = <?php echo json_encode($guardConfirmRef !== '' ? ('Confirmed entry of code number ' . $guardConfirmRef) : ''); ?>;
if(guardConfirmMsg){ showToast(guardConfirmMsg); }
let qrStream = null;
let qrDetector = null;
let qrScanActive = false;
let qrScanRaf = 0;
function isIOSDevice(){
  return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}
function getQrFallbackMessage(){
  if(isIOSDevice()){
    return 'In-app QR scanning is not supported. Open the Camera app to scan the QR code, or use manual code entry.';
  }
  return 'In-app QR scanning is not supported. Open your device camera app to scan the QR code, or use manual code entry.';
}
function setQrMessage(msg, isError){
  const el = document.getElementById('qrScannerMsg');
  if(!el) return;
  el.textContent = msg || '';
  if(isError){ el.classList.add('error'); }
  else { el.classList.remove('error'); }
}
function startQrScanner(){
  const panel = document.getElementById('qrScannerPanel');
  if(panel){ panel.style.display = 'block'; }
  const video = document.getElementById('qrVideo');
  if(!video){ setQrMessage('Camera view unavailable.', true); return; }
  if(!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia){
    setQrMessage(getQrFallbackMessage(), true);
    return;
  }
  setQrMessage('Requesting camera access...', false);
  navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
    .then(stream=>{
      qrStream = stream;
      video.srcObject = stream;
      video.setAttribute('playsinline','');
      return video.play();
    })
    .then(()=>{
      if(!('BarcodeDetector' in window)){
        setQrMessage(getQrFallbackMessage(), true);
        return;
      }
      try { qrDetector = new BarcodeDetector({ formats: ['qr_code'] }); }
      catch(_){ qrDetector = new BarcodeDetector(); }
      qrScanActive = true;
      setQrMessage('Point the camera at a QR code.', false);
      scanQrFrame();
    })
    .catch(err=>{
      const msg = err && err.name === 'NotAllowedError'
        ? (isIOSDevice() ? 'Camera access denied. Open the Camera app to scan the QR code, or allow camera access in Settings.' : 'Camera access denied. Allow permission to scan.')
        : (err && err.name === 'NotFoundError'
          ? getQrFallbackMessage()
          : getQrFallbackMessage());
      setQrMessage(msg, true);
    });
}
function scanQrFrame(){
  if(!qrScanActive) return;
  const video = document.getElementById('qrVideo');
  if(!video || !qrDetector){ qrScanActive = false; return; }
  qrDetector.detect(video)
    .then(codes=>{
      if(!qrScanActive) return;
      if(codes && codes.length){
        const raw = codes[0].rawValue || '';
        if(raw){
          handleQrResult(raw);
          return;
        }
      }
      qrScanRaf = requestAnimationFrame(scanQrFrame);
    })
    .catch(_=>{
      qrScanRaf = requestAnimationFrame(scanQrFrame);
    });
}
function handleQrResult(raw){
  const input = document.getElementById('scanCode');
  if(input){ input.value = raw; }
  stopQrScanner();
  scanCode();
}
function stopQrScanner(){
  qrScanActive = false;
  if(qrScanRaf){ cancelAnimationFrame(qrScanRaf); qrScanRaf = 0; }
  if(qrStream){ qrStream.getTracks().forEach(t=>t.stop()); qrStream = null; }
  const video = document.getElementById('qrVideo');
  if(video){ video.pause(); video.srcObject = null; }
  const panel = document.getElementById('qrScannerPanel');
  if(panel){ panel.style.display = 'none'; }
  setQrMessage('', false);
}
function isResidentRefCode(code){
  return /^VH-/i.test(String(code||'').trim());
}
function getResidentProofUrl(basePath, code){
  return `${location.origin}${basePath}/resident_qr_view.php?code=${encodeURIComponent(code)}`;
}
function scanCode(){
  const raw=(document.getElementById('scanCode').value||'').trim();
  if(!raw){ showToast('Enter a code to scan','error'); return; }
  const basePath = window.location.pathname.replace(/\/[^\/]*$/, '');
  let codeForLog = raw;
  let openUrl = isResidentRefCode(raw)
    ? getResidentProofUrl(basePath, raw)
    : `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(raw)}`;
  let parsedUrl = null;
  try { parsedUrl = new URL(raw); } catch(_){}
  if(parsedUrl){
    const codeParam = parsedUrl.searchParams.get('code');
    const ridParam = parsedUrl.searchParams.get('rid');
    if(codeParam){
      codeForLog = codeParam;
      openUrl = isResidentRefCode(codeParam)
        ? getResidentProofUrl(basePath, codeParam)
        : `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(codeParam)}`;
    } else if(ridParam && parsedUrl.pathname.indexOf('resident_qr_view.php') !== -1){
      codeForLog = raw;
      openUrl = parsedUrl.href;
    }
  } else {
    const codeMatch = raw.match(/[?&]code=([^&]+)/i);
    const ridMatch = raw.match(/[?&]rid=(\d+)/i);
    if(codeMatch && codeMatch[1]){
      const codeParam = decodeURIComponent(codeMatch[1]);
      codeForLog = codeParam;
      openUrl = isResidentRefCode(codeParam)
        ? getResidentProofUrl(basePath, codeParam)
        : `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(codeParam)}`;
    } else if(ridMatch && ridMatch[1]){
      codeForLog = raw;
      openUrl = `${location.origin}${basePath}/resident_qr_view.php?rid=${encodeURIComponent(ridMatch[1])}`;
    }
  }
  fetch(`status.php?code=${encodeURIComponent(codeForLog)}`)
    .then(r=>r.json())
    .then(data=>{
      if(!data||!data.success){ showToast(data&&data.message?data.message:'Invalid code','error'); return; }
      showToast('Scan recorded');
      loadDashboardEntries();
      loadTodayEntries();
      const win = window.open(openUrl,'_blank');
      if(!win){ window.location.href = openUrl; }
    })
    .catch(_=>{ showToast('Network error','error'); });
}
const scanQrBtn = document.getElementById('scanQrBtn');
if(scanQrBtn){ scanQrBtn.addEventListener('click', startQrScanner); }
window.addEventListener('beforeunload', stopQrScanner);
function renderDashboardEntries(rows){ const tbl=document.getElementById('entryTable'); if(!tbl) return; const header=tbl.querySelector('tr'); const rowsToRemove=Array.from(tbl.querySelectorAll('tr')).slice(1); rowsToRemove.forEach(tr=>tr.remove()); if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='emptyRow'; tr.innerHTML=`<td colspan="5" style="text-align:center;color:#6b6b6b">Awaiting scans...</td>`; tbl.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const scheduleDisplay=formatScheduleRow(r); const amenityDisplay=r.amenity||'-'; const statusDisplay=formatEntryStatus(r.status); tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.type||'-'}</td><td>${amenityDisplay}</td><td>${scheduleDisplay}</td><td>${statusDisplay}</td>`; tbl.appendChild(tr); }); }
function loadDashboardEntries(){ fetch('guard.php?action=list_today_scans').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderDashboardEntries(data.entries||[]); } }).catch(_=>{}); }
  function openStatusCard(){ const code=(document.getElementById('scanCode').value||'').trim(); if(!code){ showToast('Enter a code first','error'); return; } const basePath = window.location.pathname.replace(/\/[^\/]*$/, ''); const codeMatch = code.match(/[?&]code=([^&]+)/i); const extracted = codeMatch && codeMatch[1] ? decodeURIComponent(codeMatch[1]) : code; const url = isResidentRefCode(extracted) ? getResidentProofUrl(basePath, extracted) : `${location.origin}${basePath}/qr_view.php?code=${encodeURIComponent(extracted)}`; window.open(url,'_blank'); }
// Incident listing & escalation
let lastIncidentIds = new Set();
function setActionClicked(btn){
  if(!btn) return;
  const cell = btn.closest('td');
  if(cell){
    Array.from(cell.querySelectorAll('button.btn')).forEach(b=>b.classList.remove('is-clicked'));
  }
  btn.classList.add('is-clicked');
}
function renderIncidents(rows){
  const tbody = document.getElementById('incidentTableBody');
  if(!tbody) return;
  tbody.innerHTML = '';
  if(!rows || rows.length===0){
    const tr = document.createElement('tr'); tr.id='noIncidents'; tr.innerHTML = `<td colspan="5" style="text-align:center;color:#6b6b6b">No incidents reported</td>`;
    tbody.appendChild(tr);
    return;
  }
  rows.forEach(r=>{
    const isNew = !lastIncidentIds.has(r.id);
    lastIncidentIds.add(r.id);
    const tr = document.createElement('tr');
    const desc = (r.nature||'').trim();
    const dt = r.created_at ? new Date(r.created_at) : null;
    const dstr = dt ? formatMDY(dt) : '';
    tr.innerHTML = `<td>${r.id}</td><td>${(r.resident_name||'-')}</td><td>${desc||'-'}</td><td>${dstr}</td>
      <td>
        <button class="btn btn-view details-btn" data-id="${r.id}">View Details</button>
        <button class="btn handle-btn" data-id="${r.id}" style="background:#23412e;color:#fff">Handle Locally</button>
        <button class="btn btn-approve escalate-btn" data-id="${r.id}">Escalate to Admin</button>
        <button class="btn btn-approve resolve-btn" data-id="${r.id}" style="background:#22c55e;color:#000">Resolve</button>
      </td>`;
    tbody.appendChild(tr);
  });
  // Attach handle locally
  Array.from(tbody.querySelectorAll('button.handle-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      setActionClicked(this);
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch('guard.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=handle&report_id=${encodeURIComponent(id)}` })
        .then(r=>r.json()).then(data=>{
          if(data&&data.success){ showToast('Incident marked in progress'); loadIncidents(); }
          else { showToast('Failed to update','error'); }
        }).catch(_=>{ showToast('Network error','error'); });
    });
  });
  // Attach escalate handlers
  Array.from(tbody.querySelectorAll('button.escalate-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      setActionClicked(this);
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch('guard.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=escalate&report_id=${encodeURIComponent(id)}` })
        .then(r=>r.json()).then(data=>{
          if(data&&data.success){ showToast('Incident escalated to admin'); loadIncidents(); }
          else { showToast('Failed to escalate','error'); }
        }).catch(_=>{ showToast('Network error','error'); });
    });
  });
  // Attach resolve handlers
  Array.from(tbody.querySelectorAll('button.resolve-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      setActionClicked(this);
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch('guard.php', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:`action=resolve&report_id=${encodeURIComponent(id)}` })
        .then(r=>r.json()).then(data=>{
          if(data&&data.success){ showToast('Incident marked resolved'); loadIncidents(); }
          else { showToast('Failed to resolve','error'); }
        }).catch(_=>{ showToast('Network error','error'); });
    });
  });
  Array.from(tbody.querySelectorAll('button.details-btn')).forEach(btn=>{
    btn.addEventListener('click', function(){
      setActionClicked(this);
      const id = parseInt(this.getAttribute('data-id')||'0');
      if(!id) return;
      fetch(`guard.php?action=incident_details&id=${encodeURIComponent(id)}`).then(r=>r.json()).then(data=>{
        if(data&&data.success){ showIncidentDetailsModal(data.report, data.proofs||[]); }
        else { showToast('Unable to load details','error'); }
      }).catch(_=>{ showToast('Network error','error'); });
    });
  });
}
function loadIncidents(){ fetch('guard.php?action=list_incidents').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderIncidents(data.incidents||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){ loadIncidents(); setInterval(loadIncidents, 15000); });
function formatEntryStatus(s){
  const raw=String(s||'').trim();
  if(!raw) return '-';
  const v=raw.toLowerCase();
  if(v.indexOf('permission')!==-1 && v.indexOf('grant')!==-1) return 'Permission Granted';
  if(v.indexOf('access')!==-1 && v.indexOf('grant')!==-1) return 'Access Granted';
  const cleaned=raw.replace(/[_-]+/g,' ').replace(/\s+/g,' ').trim();
  return cleaned.replace(/\b\w/g,function(m){return m.toUpperCase();});
}
function renderTodayEntries(rows){ const tbody=document.getElementById('todayEntriesBody'); if(!tbody) return; tbody.innerHTML=''; if(!rows||rows.length===0){ const tr=document.createElement('tr'); tr.id='todayEmpty'; tr.innerHTML=`<td colspan="5" style="text-align:center;color:#6b6b6b">No scans today</td>`; tbody.appendChild(tr); return; } rows.forEach(r=>{ const tr=document.createElement('tr'); const scheduleDisplay=formatScheduleRow(r); const amenityDisplay=r.amenity||'-'; const statusDisplay=formatEntryStatus(r.status); tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.type||'-'}</td><td>${amenityDisplay}</td><td>${scheduleDisplay}</td><td>${statusDisplay}</td>`; tbody.appendChild(tr); }); }
function formatMDY(ymd){ try{ const d=new Date(ymd); return `${(d.getMonth()+1).toString().padStart(2,'0')}/${d.getDate().toString().padStart(2,'0')}/${String(d.getFullYear()).slice(-2)}`; }catch(e){ return ymd; } }
function formatDateTime(dt){ try{ const d=new Date(dt); const mm=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); let h=d.getHours(); const mi=d.getMinutes().toString().padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}/${dd}/${yy} ${h}:${mi} ${ap}`; }catch(e){ return dt; } }
function formatDateValue(v){ if(!v) return ''; try{ const d=new Date(v); if(isNaN(d.getTime())) return v; const mm=(d.getMonth()+1).toString().padStart(2,'0'); const dd=d.getDate().toString().padStart(2,'0'); const yy=String(d.getFullYear()).slice(-2); const hasTime=String(v).match(/\d{1,2}:\d{2}/); if(hasTime){ let h=d.getHours(); const mi=d.getMinutes().toString().padStart(2,'0'); const ap=h>=12?'PM':'AM'; h=h%12; if(h===0) h=12; return `${mm}/${dd}/${yy} ${h}:${mi} ${ap}`; } return `${mm}/${dd}/${yy}`; }catch(e){ return v; } }
function loadTodayEntries(){ fetch('guard.php?action=list_today_scans').then(r=>r.json()).then(data=>{ if(data&&data.success){ renderTodayEntries(data.entries||[]); } }).catch(_=>{}); }
function formatTimeValue(t){
  if(!t) return '';
  const raw=String(t).trim();
  const m=raw.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
  if(!m) return raw;
  let h=parseInt(m[1],10);
  const mi=m[2];
  const ap=h>=12?'PM':'AM';
  h=h%12; if(h===0) h=12;
  return `${h}:${mi} ${ap}`;
}
function formatScheduleRow(r){
  const sd=r.start_date||'';
  const ed=r.end_date||sd;
  if(!sd) return '-';
  const startDateLabel=formatMDY(sd);
  const endDateLabel=ed?formatMDY(ed):startDateLabel;
  const st=formatTimeValue(r.start_time||'');
  const et=formatTimeValue(r.end_time||'');
  const timeLabel=(st && et) ? `${st} ${et}` : (st || et);
  const startLabel=startDateLabel + (timeLabel ? ` ${timeLabel}` : '');
  const endLabel=endDateLabel + (timeLabel ? ` ${timeLabel}` : '');
  if(ed && ed!==sd){ return `${startLabel}→ ${endLabel}`; }
  if(timeLabel){ return `${startDateLabel} ${timeLabel}`; }
  return startDateLabel;
}
function isApprovedStatus(s){
  const v=String(s||'').toLowerCase();
  return v.indexOf('approv')!==-1 || v.indexOf('permission')!==-1 || v.indexOf('granted')!==-1 || v.indexOf('access')!==-1;
}
function renderExpected(rows){
  const tbody=document.getElementById('expectedBody'); if(!tbody) return;
  const guestBody=document.getElementById('expectedGuestBody');
  tbody.innerHTML='';
  if(guestBody) guestBody.innerHTML='';
  const list=Array.isArray(rows)?rows:[];
  const approved=list.filter(r=>isApprovedStatus(r.status));
  const guestRows=[];
  const otherRows=[];
  approved.forEach(r=>{ if(String(r.type||'').toLowerCase()==='guest entry'){ guestRows.push(r); } else { otherRows.push(r); } });
  if(otherRows.length===0){
    const tr=document.createElement('tr');
    tr.id='expectedEmpty';
    tr.innerHTML=`<td colspan="6" style="text-align:center;color:#6b6b6b">No scheduled arrivals in selected range</td>`;
    tbody.appendChild(tr);
  } else {
    otherRows.forEach(r=>{
      const tr=document.createElement('tr');
      const scheduleDisplay=formatScheduleRow(r||{});
      const st=String(r.status||'').replace(/[_-]+/g,' ');
      const sts=st.replace(/\b\w/g,function(m){return m.toUpperCase();});
      tr.innerHTML=`<td>${r.code||'-'}</td><td>${r.name||'-'}</td><td>${r.type||'-'}</td><td>${r.amenity||'-'}</td><td>${scheduleDisplay}</td><td>${sts}</td>`;
      tbody.appendChild(tr);
    });
  }
  if(!guestBody) return;
  if(guestRows.length===0){
    const tr=document.createElement('tr');
    tr.id='expectedGuestEmpty';
    tr.innerHTML=`<td colspan="6" style="text-align:center;color:#6b6b6b">No guest entries in selected range</td>`;
    guestBody.appendChild(tr);
    return;
  }
  guestRows.forEach(r=>{
    const tr=document.createElement('tr');
    const st=String(r.status||'').replace(/[_-]+/g,' ');
    const sts=st.replace(/\b\w/g,function(m){return m.toUpperCase();});
    const addedBy=r.added_by||'-';
    tr.innerHTML=`<td>${r.code||'-'}</td><td>${addedBy}</td><td>${r.type||'-'}</td><td>${r.amenity||'-'}</td><td>-</td><td>${sts}</td>`;
    guestBody.appendChild(tr);
  });
}
function formatInputDate(d){ const z=new Date(d); const mm=(z.getMonth()+1).toString().padStart(2,'0'); const dd=z.getDate().toString().padStart(2,'0'); const yyyy=z.getFullYear(); return `${yyyy}-${mm}-${dd}`; }
function getRange(){ const s=document.getElementById('expectedStart'); const e=document.getElementById('expectedEnd'); const sv=s&&s.value?s.value:formatInputDate(new Date()); const ev=e&&e.value?e.value:formatInputDate(new Date(Date.now()+6*24*60*60*1000)); return {start:sv,end:ev}; }
function loadExpected(start,end){ const rng = start&&end ? {start,end} : getRange(); const url = `guard.php?action=list_expected&start=${encodeURIComponent(rng.start)}&end=${encodeURIComponent(rng.end)}`; fetch(url).then(r=>r.json()).then(data=>{ if(data&&data.success){ renderExpected(data.entries||[]); } }).catch(_=>{}); }
document.addEventListener('DOMContentLoaded', function(){
  loadTodayEntries();
  loadDashboardEntries();
  refreshNotifications();
  setInterval(refreshNotifications, 60000);
  const s=document.getElementById('expectedStart'); const e=document.getElementById('expectedEnd'); if(s&&e){ const today=new Date(); const next=new Date(Date.now()+6*24*60*60*1000); s.value=formatInputDate(today); e.value=formatInputDate(next); }
  const apply=document.getElementById('applyExpected'); if(apply){ apply.addEventListener('click', function(){ const rng=getRange(); loadExpected(rng.start,rng.end); }); }
  const wk=document.getElementById('weekFromStartBtn'); if(wk){ wk.addEventListener('click', function(){ const today=new Date(); const day=today.getDay(); const daysToSunday=(7-day)%7; const thisWeekEnd=new Date(today.getTime()); thisWeekEnd.setDate(thisWeekEnd.getDate()+daysToSunday); const start=new Date(thisWeekEnd.getTime()); start.setDate(start.getDate()+1); const end=new Date(start.getTime()); end.setDate(end.getDate()+6); const sv=formatInputDate(start); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  const tw=document.getElementById('thisWeekBtn'); if(tw){ tw.addEventListener('click', function(){ const today=new Date(); const day=today.getDay(); const daysToSunday=(7-day)%7; const start=new Date(today.getTime()); const end=new Date(today.getTime()); end.setDate(end.getDate()+daysToSunday); const sv=formatInputDate(start); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  const n30=document.getElementById('next30Btn'); if(n30){ n30.addEventListener('click', function(){ const today=new Date(); const day=today.getDay(); const daysToSunday=(7-day)%7; const thisWeekEnd=new Date(today.getTime()); thisWeekEnd.setDate(thisWeekEnd.getDate()+daysToSunday); const start=new Date(thisWeekEnd.getTime()); start.setDate(start.getDate()+1+7); const end=new Date(start.getTime()); end.setDate(end.getDate()+29); const sv=formatInputDate(start); const ev=formatInputDate(end); const sIn=document.getElementById('expectedStart'); const eIn=document.getElementById('expectedEnd'); if(sIn) sIn.value=sv; if(eIn) eIn.value=ev; loadExpected(sv,ev); }); }
  if(s&&e){ s.addEventListener('change', function(){ if(!s.value) return; const base=new Date(s.value); const end=new Date(base.getTime()); end.setDate(end.getDate()+6); e.value=formatInputDate(end); }); }
  loadExpected();
  const inp = document.getElementById('scanCode');
  if (inp) {
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ scanCode(); } });
  }
  setInterval(loadTodayEntries, 60000);
  setInterval(loadDashboardEntries, 60000);
  setInterval(function(){ const rng=getRange(); loadExpected(rng.start,rng.end); }, 60000);
});
function showIncidentDetailsModal(report, proofs){
  var m = document.getElementById('incidentDetailsModal');
  if(!m || !report) return;
  m.classList.remove('closing');
  document.getElementById('incResident').textContent = report.resident_name || '-';
  document.getElementById('incSubject').textContent = report.subject || '-';
  document.getElementById('incAddress').textContent = report.address || '-';
  var n = report.nature || '';
  var o = report.other_concern || '';
  document.getElementById('incNature').textContent = n ? n : (o || '-');
  var rd = report.report_date || report.created_at || '';
  document.getElementById('incDate').textContent = rd ? formatDateValue(rd) : '-';
  document.getElementById('incStatus').textContent = report.status ? report.status : '-';
  var pf = document.getElementById('incProofs');
  pf.innerHTML = '';
  if (proofs && proofs.length > 0) {
    proofs.forEach(function(p){
      var ext = p.split('.').pop().toLowerCase();
      if (['jpg','jpeg','png','gif'].indexOf(ext) >= 0) {
        var img = document.createElement('img'); img.src = p; img.addEventListener('click', function(){ openProofImage(p); }); pf.appendChild(img);
      } else {
        var a = document.createElement('a'); a.href = p; a.target = '_blank'; a.textContent = 'View file'; pf.appendChild(a);
      }
    });
  } else {
    var span = document.createElement('span'); span.textContent = 'No proofs'; pf.appendChild(span);
  }
  m.style.display = 'flex';
}
function closeIncidentDetailsModal(){
  var m = document.getElementById('incidentDetailsModal');
  if (!m) return;
  m.classList.add('closing');
  setTimeout(function(){
    m.style.display = 'none';
    m.classList.remove('closing');
  }, 200);
}
function openProofImage(src){
  var m = document.getElementById('proofImageModal');
  var img = document.getElementById('proofImagePreview');
  if (m && img) {
    img.src = src;
    m.classList.remove('closing');
    m.style.display = 'flex';
  }
}
function closeProofImage(){
  var m = document.getElementById('proofImageModal');
  if (!m) return;
  m.classList.add('closing');
  setTimeout(function(){
    m.style.display = 'none';
    m.classList.remove('closing');
  }, 200);
}
document.addEventListener('DOMContentLoaded', function(){
  var proofModal = document.getElementById('proofImageModal');
  if (proofModal) {
    proofModal.addEventListener('click', function(e){
      if (e.target === proofModal) { closeProofImage(); }
    });
  }
});
</script>
<script src="js/logout-modal.js"></script>
<div id="incidentDetailsModal" class="modal" style="display:none">
  <div class="modal-content">
    <div class="modal-header">
      <h3>Incident Details</h3>
      <button class="modal-close" onclick="closeIncidentDetailsModal()">×</button>
    </div>
    <div id="incidentDetailsContent" class="incident-details-content">
      <div class="details-grid">
        <div><strong>Resident</strong><div id="incResident"></div></div>
        <div><strong>Complainee</strong><div id="incSubject"></div></div>
        <div><strong>Address</strong><div id="incAddress"></div></div>
        <div><strong>Nature</strong><div id="incNature"></div></div>
        <div><strong>Date</strong><div id="incDate"></div></div>
        <div><strong>Status</strong><div id="incStatus"></div></div>
      </div>
      <div style="margin-top:12px"><strong>Proofs</strong></div>
      <div id="incProofs" class="proofs"></div>
    </div>
  </div>
</div>
<div id="proofImageModal" class="modal modal-top image-modal" style="display:none">
  <div class="modal-content">
    <button class="modal-close" onclick="closeProofImage()">×</button>
    <img id="proofImagePreview" src="" alt="Proof preview">
  </div>
</div>
</body>
</html>
