<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';
if (!defined('ECO_WEEKLY_POINT_CAP')) { define('ECO_WEEKLY_POINT_CAP', 250); }

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details
if ($con) {
  $stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, status FROM users WHERE id = ?");
  if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($result && mysqli_num_rows($result) === 1) {
      $user = mysqli_fetch_assoc($result);
    }
    mysqli_stmt_close($stmt);
  }
}

if (!$user) {
  header('Location: mainpage.php');
  exit;
}
// Compose full name for display
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

$showReportWait = false;
$reportWaitMessage = 'The report incident will be reviewed by the admin and guard.';
if (!empty($_SESSION['report_wait_popup'])) {
  $showReportWait = true;
  if (!empty($_SESSION['report_wait_message'])) {
    $reportWaitMessage = $_SESSION['report_wait_message'];
  }
  unset($_SESSION['report_wait_popup'], $_SESSION['report_wait_message']);
}
$flashNotice = $_SESSION['flash_notice'] ?? '';
if ($flashNotice !== '') {
  unset($_SESSION['flash_notice'], $_SESSION['flash_ref_code']);
}

// Profile Picture Logic
$profilePicPath = 'images/mainpage/profile\'.jpg'; // Default
if (file_exists('uploads/profiles/user_' . $userId . '.jpg')) {
    $profilePicPath = 'uploads/profiles/user_' . $userId . '.jpg';
} elseif (file_exists('uploads/profiles/user_' . $userId . '.png')) {
    $profilePicPath = 'uploads/profiles/user_' . $userId . '.png';
} elseif (file_exists('uploads/profiles/user_' . $userId . '.jpeg')) {
    $profilePicPath = 'uploads/profiles/user_' . $userId . '.jpeg';
}
$profilePicUrl = $profilePicPath . '?t=' . time();

// Change Password Handler
$pwdMsg = '';
$pwdOk = false;
function ensurePasswordField($con){
  if(!($con instanceof mysqli)) return;
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'password'");
  if($res && ($row = $res->fetch_assoc())){
    $type = strtolower($row['Type'] ?? '');
    if (strpos($type, 'varchar(255)') === false && strpos($type, 'text') === false) {
      @$con->query("ALTER TABLE users MODIFY COLUMN password VARCHAR(255)");
    }
  } else {
    @$con->query("ALTER TABLE users ADD COLUMN password VARCHAR(255)");
  }
}
ensurePasswordField($con);
function residentEcoPointMaterialLabel($materialType = '', $description = ''){
  $value = strtolower(trim((string)$materialType));
  $desc = strtolower((string)$description);
  $haystack = trim($value . ' ' . $desc);
  if (strpos($haystack, 'plastic') !== false || strpos($haystack, 'pet') !== false) return 'Plastic (PET)';
  if (strpos($haystack, 'aluminum') !== false || strpos($haystack, 'aluminium') !== false || strpos($haystack, 'can') !== false) return 'Aluminum Cans';
  if (strpos($haystack, 'cardboard') !== false || strpos($haystack, 'paper') !== false) return 'Paper & Cardboard';
  return 'Other';
}

function formatReservationTime($timeValue) {
  if ($timeValue === null) {
    return '';
  }
  $raw = trim((string)$timeValue);
  if ($raw === '') {
    return '';
  }
  $formats = ['H:i:s', 'H:i', 'g:i A', 'g:i a', 'h:i A', 'h:i a'];
  foreach ($formats as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $raw);
    if ($dt !== false) {
      return $dt->format('g:i A');
    }
  }
  $ts = strtotime($raw);
  if ($ts !== false) {
    return date('g:i A', $ts);
  }
  return '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
  $current = $_POST['current_password'] ?? '';
  $newPass = $_POST['new_password'] ?? '';
  $confirm = $_POST['confirm_password'] ?? '';
  if ($current === '' || $newPass === '' || $confirm === '') {
    $pwdMsg = 'Please fill in all password fields.';
  } elseif ($newPass !== $confirm) {
    $pwdMsg = 'New password and confirm password do not match.';
  } elseif (strlen($newPass) < 8) {
    $pwdMsg = 'New password must be at least 8 characters.';
  } else {
    if (!($con instanceof mysqli)) {
      $pwdMsg = 'Database connection error.';
    } else {
      $stmtP = $con->prepare("SELECT `password` FROM users WHERE id = ? LIMIT 1");
      if (!$stmtP) {
        $pwdMsg = 'Database error: ' . $con->error;
      } else {
        $stmtP->bind_param('i', $userId);
        $stmtP->execute();
        $stmtP->bind_result($hash);
        $hasRow = $stmtP->fetch();
        $stmtP->close();
        if ($hasRow) {
          if (!password_verify($current, $hash)) {
            $pwdMsg = 'Current password is incorrect.';
          } else {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $updP = $con->prepare("UPDATE users SET `password` = ? WHERE id = ?");
            if (!$updP) {
              $esc = $con->real_escape_string($newHash);
              $ok = $con->query("UPDATE users SET `password` = '".$esc."' WHERE id = ".intval($userId));
              if ($ok) {
                $pwdOk = true;
                $pwdMsg = 'Your password has been updated.';
              } else {
                $pwdMsg = 'Database error: ' . $con->error;
              }
            } else {
              $updP->bind_param('si', $newHash, $userId);
              $updP->execute();
              $updP->close();
              $pwdOk = true;
              $pwdMsg = 'Your password has been updated.';
            }
          }
        } else {
          $pwdMsg = 'Unable to verify your account.';
        }
      }
    }
  }
}

function ensureNotificationsTable($con) {
    if (!($con instanceof mysqli)) { return; }
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

function getUserNotifications($con, $userId, $limit = 20) {
    $items = [];
    if (!($con instanceof mysqli) || !$userId) { return $items; }
    $stmt = $con->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
    if (!$stmt) { return $items; }
    $stmt->bind_param('ii', $userId, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($row = $res->fetch_assoc())) {
        $items[] = $row;
    }
    $stmt->close();
    return $items;
}

function getUserUnreadNotificationCount($con, $userId) {
    if (!($con instanceof mysqli) || !$userId) { return 0; }
    $stmt = $con->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    if (!$stmt) { return 0; }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $count = 0;
    if ($res && ($row = $res->fetch_assoc())) { $count = intval($row['c']); }
    $stmt->close();
    return $count;
}

// Normalize phone and prepare resident fields for guest form
$houseNumber = $user['house_number'] ?? '';
$email = $user['email'] ?? '';
$phoneRaw = $user['phone'] ?? '';
$phoneNormalized = $phoneRaw;
if (preg_match('/^\+63(9\d{9})$/', $phoneNormalized)) {
  $phoneNormalized = '0' . substr($phoneNormalized, 3);
}
$displayPhone = $phoneNormalized ?: $phoneRaw;

// Fetch saved guests for resident (Approved only)
$guestRows = [];
if ($con instanceof mysqli) {
  $stmtG = $con->prepare("SELECT id, visitor_first_name, visitor_middle_name, visitor_last_name, visitor_email, visitor_contact, created_at, ref_code FROM guest_forms WHERE resident_user_id = ? AND approval_status IN ('approved','permission_granted') ORDER BY created_at DESC");
  if ($stmtG) {
    $stmtG->bind_param('i', $userId);
    $stmtG->execute();
    $resG = $stmtG->get_result();
    while ($rowG = $resG->fetch_assoc()) {
      $guestRows[] = $rowG;
    }
    $stmtG->close();
  }
}


// Prepare resident QR link and local image path
$userStatus = $user['status'] ?? 'pending';
$normalizedUserStatus = strtolower(trim($userStatus));
$isAccountBlocked = in_array($normalizedUserStatus, ['denied', 'disabled'], true);
$qrLink = '';
$qrRelPath = '';
$qrAbsPath = '';
$qrImg = '';

if (!$isAccountBlocked) {
    $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
    $houseCode = strtoupper(trim((string)($user['house_number'] ?? '')));
    if ($houseCode !== '') {
        $qrLink = sprintf('%s://%s%s/resident_qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($houseCode));
        $qrRelPath = 'uploads/qr_resident_' . preg_replace('/[^A-Z0-9]+/', '_', $houseCode) . '.png';
    } else {
        $qrLink = sprintf('%s://%s%s/resident_qr_view.php?rid=%d', $scheme, $host, $basePath, intval($user['id'] ?? $userId));
        $qrRelPath = 'uploads/qr_resident_' . intval($user['id'] ?? $userId) . '.png';
    }
    $qrAbsPath = __DIR__ . '/' . $qrRelPath;
    // Always ensure the cached QR encodes the current resident ID link
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($qrLink);
    $img = @file_get_contents($qrUrl);
    if ($img !== false) { @file_put_contents($qrAbsPath, $img); } else { $qrRelPath = $qrUrl; }
}

$allowedSections = ['panel-requests', 'panel-points-history', 'panel-guest-form', 'panel-my-guests', 'panel-history'];
$activeSection = $_GET['section'] ?? 'panel-requests';
$activeSection = is_string($activeSection) ? trim($activeSection) : 'panel-requests';
if (!in_array($activeSection, $allowedSections, true)) {
  $activeSection = 'panel-requests';
}

// Fetch point transactions - ONLY those tied to VHEcoPoint sessions so dashboard numbers
// (balance, weekly points, daily sessions, activity history, expiry) share ONE source of truth.
$ecoPointTransactions = [];
if ($con instanceof mysqli) {
  try {
    $tblChk = $con->query("SHOW TABLES LIKE 'point_transactions'");
    if ($tblChk && $tblChk->num_rows > 0) {
      $colQ = $con->query("SHOW COLUMNS FROM point_transactions LIKE 'ecopoint_session_id'");
      $hasFk = ($colQ && $colQ->num_rows > 0);
      if ($hasFk) {
        $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
      } else {
        $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, NULL AS ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
      }
      if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $desc = (string)($row['description'] ?? '');
          $isEcoTx = (!empty($row['ecopoint_session_id']) && intval($row['ecopoint_session_id']) > 0)
                   || (stripos($desc, 'VHEcoPoint') !== false)
                   || (stripos($desc, 'recycling') !== false)
                   || (stripos($desc, 'Redeemed points') !== false)
                   || (stripos($desc, 'redeem') !== false && !empty($row['reservation_ref_code']));
          if ($isEcoTx) {
            $ecoPointTransactions[] = $row;
          }
        }
        $stmt->close();
      }
    }
  } catch (Throwable $e) { /* point_transactions may be missing; skip VHEcoPoint history */ }
}

// VHEcoPoint Current Balance = net of ONLY VHEcoPoint-sourced earn/redeem/adjustment transactions.
// This guarantees Current Point Balance stays in sync with Weekly/Daily/History widgets.
$currentPoints = 0;
foreach ($ecoPointTransactions as $tx) {
    $txType = strtolower(trim((string)($tx['transaction_type'] ?? 'earn')));
    $amt = intval($tx['amount'] ?? 0);
    if ($txType === 'earn') {
        $currentPoints += $amt;
    } elseif ($txType === 'redeem') {
        $currentPoints -= $amt;
    } elseif ($txType === 'adjustment') {
        $currentPoints += $amt;
    }
}
$currentPoints = max(0, $currentPoints);

$ecoPointWeeklyCap = 250;
$ecoPointDailySessionsMax = 3;
$ecoPointExpiryDays = 365;
$ecoPointWeeklyStats = [
    'Plastic (PET)' => ['points' => 0, 'weight' => 0.0],
    'Aluminum Cans' => ['points' => 0, 'weight' => 0.0],
    'Paper & Cardboard' => ['points' => 0, 'weight' => 0.0]
];
$ecoPointWeeklyPoints = 0;
$ecoPointTodaySessionsUsed = 0;
$ecoPointRecyclingHistory = [];
$allPointHistory = [];
$ecoPointNextExpiryTs = null;
$todayDateKey = date('Y-m-d');
$weekStartDateKey = date('Y-m-d', strtotime('monday this week'));
$weekEndDateKey = date('Y-m-d', strtotime('sunday this week'));
foreach ($ecoPointTransactions as $tx) {
    $txType = strtolower(trim((string)($tx['transaction_type'] ?? '')));
    if ($txType !== 'earn') {
        continue;
    }
    $createdAt = (string)($tx['created_at'] ?? '');
    $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
    // Robustness: if created_at is unparsable (e.g. '0000-00-00 00:00:00' from a
    // prior migration that inserted rows without explicit dates), fall back to the
    // current PHP time so daily / weekly / expiry counters still reflect the fact that
    // the points exist in the ledger.
    if ($createdTs === false || $createdTs <= 0) {
        $createdTs = time();
    }
    $createdDateKey = date('Y-m-d', $createdTs);
    $materialLabel = residentEcoPointMaterialLabel($tx['material_type'] ?? '', $tx['description'] ?? '');
    $weightKg = floatval($tx['weight_kg'] ?? 0);
    $pointsEarned = intval($tx['amount'] ?? 0);
    $ecoPointRecyclingHistory[] = [
        'date_label' => $createdTs ? date('m/d/y g:i A', $createdTs) : '-',
        'material_label' => $materialLabel,
        'weight_kg' => $weightKg,
        'points_earned' => $pointsEarned,
        'description' => (string)($tx['description'] ?? '')
    ];
    if ($createdDateKey === $todayDateKey) {
        $ecoPointTodaySessionsUsed++;
    }
    if ($createdDateKey !== '' && $createdDateKey >= $weekStartDateKey && $createdDateKey <= $weekEndDateKey) {
        $ecoPointWeeklyPoints += $pointsEarned;
        if (isset($ecoPointWeeklyStats[$materialLabel])) {
            $ecoPointWeeklyStats[$materialLabel]['points'] += $pointsEarned;
            $ecoPointWeeklyStats[$materialLabel]['weight'] += $weightKg;
        }
    }
    if ($createdTs) {
        $expiryTs = strtotime('+' . $ecoPointExpiryDays . ' days', $createdTs);
        if ($expiryTs && $expiryTs > time() && ($ecoPointNextExpiryTs === null || $expiryTs < $ecoPointNextExpiryTs)) {
            $ecoPointNextExpiryTs = $expiryTs;
        }
    }
}

// Build full points history (earn + redeem) for McDonald's-style display
foreach ($ecoPointTransactions as $tx) {
    $txType = strtolower(trim((string)($tx['transaction_type'] ?? '')));
    if ($txType !== 'earn' && $txType !== 'redeem') continue;
    $createdAt = (string)($tx['created_at'] ?? '');
    $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
    if ($createdTs === false || $createdTs <= 0) $createdTs = time();
    $amount = intval($tx['amount'] ?? 0);
    $description = (string)($tx['description'] ?? '');
    $materialLabel = residentEcoPointMaterialLabel($tx['material_type'] ?? '', $description);
    $weightKg = floatval($tx['weight_kg'] ?? 0);
    $allPointHistory[] = [
        'type' => $txType,
        'amount' => $amount,
        'date_ts' => $createdTs,
        'date_label' => $createdTs ? date('M j, Y', $createdTs) : '-',
        'time_label' => $createdTs ? date('g:i A', $createdTs) : '',
        'description' => $description,
        'material_label' => $materialLabel,
        'weight_kg' => $weightKg,
    ];
}
usort($allPointHistory, function ($a, $b) { return $b['date_ts'] <=> $a['date_ts']; });

$ecoPointWeeklyRemaining = max(0, $ecoPointWeeklyCap - $ecoPointWeeklyPoints);
$ecoPointWeeklyProgress = $ecoPointWeeklyCap > 0 ? min(100, round(($ecoPointWeeklyPoints / $ecoPointWeeklyCap) * 100)) : 0;
$ecoPointSessionsRemaining = max(0, $ecoPointDailySessionsMax - $ecoPointTodaySessionsUsed);
$ecoPointExpiryCountdownLabel = 'No active recycling points yet';
$ecoPointExpiryCountdownSubtext = 'Start using VHEcoPoint to begin your points cycle. Points expire 12 months after they are earned.';
if ($ecoPointNextExpiryTs !== null) {
    $secondsLeft = max(0, $ecoPointNextExpiryTs - time());
    $daysLeft = (int)floor($secondsLeft / 86400);
    $hoursLeft = (int)floor(($secondsLeft % 86400) / 3600);
    $ecoPointExpiryCountdownLabel = $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
    if ($daysLeft < 1) {
        $ecoPointExpiryCountdownLabel = max(1, $hoursLeft) . ' hour' . (max(1, $hoursLeft) === 1 ? '' : 's');
    }
    $ecoPointExpiryCountdownSubtext = 'Estimated next points expiry: ' . date('M d, Y', $ecoPointNextExpiryTs) . ' • Points expire 12 months after they are earned.';
}

// Participation stats for resident dashboard
$vpResidentCount = 0;
$vpParticipantCount = 0;
$vpParticipantRate = 0;
try {
    if ($con instanceof mysqli) {
        $r = $con->query("SELECT COUNT(*) AS c FROM users WHERE user_type='resident' AND status='active'");
        if ($r && $row = $r->fetch_assoc()) $vpResidentCount = intval($row['c'] ?? 0);
        $r = $con->query("SELECT COUNT(DISTINCT ws.user_id) AS c FROM ecopoint_waste_sessions ws INNER JOIN users u ON u.id = ws.user_id WHERE ws.status='COMPLETED' AND u.user_type='resident' AND u.status='active'");
        if ($r && $row = $r->fetch_assoc()) $vpParticipantCount = intval($row['c'] ?? 0);
        $vpParticipantRate = $vpResidentCount > 0 ? round(($vpParticipantCount / $vpResidentCount) * 100, 1) : 0;
    }
} catch (Throwable $e) {}

// Fetch Activities (Reservations, Reports, Guest Forms)
$activities = [];
$reservationRefs = [];

// 1. Reservations
// Ensure start_time/end_time exist, if not use created_at or defaults
$colsToCheck = [
    'booking_for' => "VARCHAR(50) NULL",
    'booked_by_role' => "VARCHAR(50) NULL",
    'booked_by_name' => "VARCHAR(150) NULL",
    'scanned_at' => "DATETIME NULL",
    'updated_at' => "DATETIME NULL"
];
foreach ($colsToCheck as $col => $def) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN $col $def");
    }
}
if ($con instanceof mysqli) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'denial_reason'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN denial_reason TEXT NULL");
    }
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'receipt_attempts'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN receipt_attempts INT NULL DEFAULT 0");
    }
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'denial_reason'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE guest_forms ADD COLUMN denial_reason TEXT NULL");
    }
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'scanned_at'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE guest_forms ADD COLUMN scanned_at DATETIME NULL");
    }
}
$stmt = $con->prepare("SELECT 'reservation' as type, r.amenity, r.start_date, r.end_date, r.start_time, r.end_time, r.status, r.approval_status, r.payment_status, r.denial_reason, r.created_at, r.updated_at, r.ref_code, r.booking_for, r.booked_by_role, r.booked_by_name, r.scanned_at, r.receipt_attempts, gf.id AS gf_id, gf.visitor_first_name, gf.visitor_middle_name, gf.visitor_last_name FROM reservations r LEFT JOIN guest_forms gf ON r.ref_code = gf.ref_code WHERE r.user_id = ? AND r.status <> 'deleted' AND r.approval_status <> 'deleted' ORDER BY r.created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $end = $row['end_date'] ?? null;
        $startTimeText = formatReservationTime($row['start_time'] ?? '');
        $endTimeText = formatReservationTime($row['end_time'] ?? '');
        $dateText = '';
        if (!empty($start)) {
            $startText = date('m/d/y', strtotime($start));
            $endText = !empty($end) ? date('m/d/y', strtotime($end)) : $startText;
            $dateText = $startText . ' - ' . $endText;
        } else {
            $dateText = 'Date not set';
        }
        if ($startTimeText !== '' || $endTimeText !== '') {
            $timeStr = trim(($startTimeText !== '' ? $startTimeText : '') . ($endTimeText !== '' ? ' - ' . $endTimeText : ''));
            if ($timeStr !== '') {
                $dateText = trim($dateText . ' ' . $timeStr);
            }
        }
        $eTime = null;
        if (!empty($row['start_date'])) {
            $eTime = strtotime(trim($row['start_date'] . ' ' . ($row['start_time'] ?? '00:00:00')));
        }
        if ($eTime === false || $eTime === null) {
            $eTime = strtotime($row['created_at'] ?? 'now');
        }
        $statusVal = $row['approval_status'] ?? '';
        if ($statusVal === '' || $statusVal === null) {
            $statusVal = $row['status'] ?? 'pending';
        }
        $paymentStatusLower = strtolower((string)($row['payment_status'] ?? ''));
        if ($paymentStatusLower === 'rejected') {
            $svLower = strtolower((string)$statusVal);
            if (strpos($svLower, 'moved_to_history') === false && strpos($svLower, 'permission_granted') === false) {
                $statusVal = 'rejected';
            }
        } elseif ($paymentStatusLower === 'pending_update') {
            $svLower = strtolower((string)$statusVal);
            if (strpos($svLower, 'moved_to_history') === false && strpos($svLower, 'permission_granted') === false) {
                $statusVal = 'pending_update';
            }
        }
        if (!empty($row['scanned_at'])) {
            $statusLower = strtolower((string)$statusVal);
            if (strpos($statusLower, 'moved_to_history') === false && strpos($statusLower, 'denied') === false && strpos($statusLower, 'reject') === false && strpos($statusLower, 'cancel') === false && strpos($statusLower, 'expired') === false) {
                $statusVal = 'permission_granted';
            }
        }
        $resTitle = 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity');
        if (stripos($statusVal ?? '', 'cancel') !== false) {
            $resTitle .= ' - Cancelled';
        }
        $reservedBy = '';
        $bookedRole = $row['booked_by_role'] ?? '';
        $bookedName = trim((string)($row['booked_by_name'] ?? ''));
        if ($bookedRole === 'guest' || $bookedRole === 'co_owner') {
            if ($bookedName !== '') {
                $reservedBy = 'Booked by: ' . $bookedName;
            }
        }
        if ($reservedBy === '' && (string)($row['booking_for'] ?? '') === 'guest') {
            if ($bookedName !== '') {
                $reservedBy = 'Booked by: ' . $bookedName;
            }
        } else if ($reservedBy === '' && !empty($row['gf_id'])) {
            $guestNameParts = [];
            if (!empty($row['visitor_first_name'])) { $guestNameParts[] = $row['visitor_first_name']; }
            if (!empty($row['visitor_middle_name'])) { $guestNameParts[] = $row['visitor_middle_name']; }
            if (!empty($row['visitor_last_name'])) { $guestNameParts[] = $row['visitor_last_name']; }
            $guestName = trim(implode(' ', $guestNameParts));
            if ($guestName !== '') {
                $reservedBy = 'Booked by: ' . $guestName;
            }
        }
        $refCodeVal = $row['ref_code'] ?? 'RES';
        $reservationRefs[$refCodeVal] = true;
        $details = trim($dateText);
        $statusLower = strtolower((string)($statusVal ?? ''));
        $reason = trim((string)($row['denial_reason'] ?? ''));
        $isDenied = (strpos($statusLower, 'denied') !== false || strpos($statusLower, 'rejected') !== false);
        if (strtolower((string)($row['payment_status'] ?? '')) === 'rejected') { $isDenied = true; }
        if ($isDenied && $reason !== '') {
            $details = trim($details . ' Reason: ' . $reason);
        }
        $actionDate = $row['created_at'];
        if ((strpos($statusLower, 'cancel') !== false || strpos($statusLower, 'moved_to_history') !== false || strpos($statusLower, 'deleted') !== false) && !empty($row['updated_at'])) {
            $actionDate = $row['updated_at'];
        }
        $activities[] = [
            'type' => 'reservation',
            'title' => $resTitle,
            'details' => $details,
            'status' => $statusVal ?? 'pending',
            'date' => $actionDate,
            'event_timestamp' => $eTime ? $eTime : strtotime($start . ' 23:59:59'),
            'ref_code' => $refCodeVal,
            'reserved_by' => $reservedBy,
            'payment_status' => $row['payment_status'] ?? null,
            'attempts' => intval($row['receipt_attempts'] ?? 0),
            'scanned_at' => $row['scanned_at'] ?? null,
            'start_date_raw' => $row['start_date'] ?? '',
            'end_date_raw' => $row['end_date'] ?? '',
            'start_time_raw' => $row['start_time'] ?? '',
            'end_time_raw' => $row['end_time'] ?? ''
        ];
    }
    $stmt->close();
}

$hasGuestStartTime = false;
$hasGuestEndTime = false;
if ($con instanceof mysqli) {
    $chk = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'start_time'");
    $hasGuestStartTime = $chk && $chk->num_rows > 0;
    $chk = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'end_time'");
    $hasGuestEndTime = $chk && $chk->num_rows > 0;
}
$guestAmenitySelect = "SELECT visitor_first_name, visitor_middle_name, visitor_last_name, amenity, start_date, end_date, " . ($hasGuestStartTime ? "start_time" : "NULL as start_time") . ", " . ($hasGuestEndTime ? "end_time" : "NULL as end_time") . ", approval_status, denial_reason, created_at, updated_at, ref_code, scanned_at FROM guest_forms WHERE resident_user_id = ? AND approval_status <> 'deleted' AND (wants_amenity = 1 OR amenity IS NOT NULL OR start_date IS NOT NULL OR end_date IS NOT NULL) ORDER BY created_at DESC";
$stmt = $con->prepare($guestAmenitySelect);
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $refCodeVal = $row['ref_code'] ?? 'GST';
        if (!empty($reservationRefs[$refCodeVal])) {
            continue;
        }
        $start = $row['start_date'];
        $end = $row['end_date'];
        if ($start) {
            $startText = date('m/d/y', strtotime($start));
            $endText = $end ? date('m/d/y', strtotime($end)) : $startText;
            $dateText = $startText . ' - ' . $endText;
        } else {
            $dateText = '';
        }
        $startTimeText = formatReservationTime($row['start_time'] ?? '');
        $endTimeText = formatReservationTime($row['end_time'] ?? '');
        $timeStr = '';
        if ($startTimeText !== '' || $endTimeText !== '') {
            $timeStr = trim(($startTimeText !== '' ? $startTimeText : '') . ($endTimeText !== '' ? ' - ' . $endTimeText : ''));
        }
        $details = trim($dateText . ($timeStr ? ' ' . $timeStr : ''));
        $statusVal = $row['approval_status'] ?? 'pending';
        if (!empty($row['scanned_at'])) {
            $statusLower = strtolower((string)$statusVal);
            if (strpos($statusLower, 'moved_to_history') === false && strpos($statusLower, 'denied') === false && strpos($statusLower, 'reject') === false && strpos($statusLower, 'cancel') === false && strpos($statusLower, 'expired') === false) {
                $statusVal = 'permission_granted';
            }
        }
        $reason = trim((string)($row['denial_reason'] ?? ''));
        $statusLower = strtolower((string)$statusVal);
        if ($reason !== '' && (strpos($statusLower, 'denied') !== false || strpos($statusLower, 'reject') !== false)) {
            $details = trim($details . ' Reason: ' . $reason);
        }
        $resTitle = 'Reservation Schedule - ' . ($row['amenity'] ?? 'Amenity');
        if (stripos($statusVal ?? '', 'cancel') !== false) {
            $resTitle .= ' - Cancelled';
        }
        $guestNameParts = [];
        if (!empty($row['visitor_first_name'])) { $guestNameParts[] = $row['visitor_first_name']; }
        if (!empty($row['visitor_middle_name'])) { $guestNameParts[] = $row['visitor_middle_name']; }
        if (!empty($row['visitor_last_name'])) { $guestNameParts[] = $row['visitor_last_name']; }
        $guestName = trim(implode(' ', $guestNameParts));
        $reservedBy = $guestName !== '' ? 'Booked for: ' . $guestName : '';
        $endTs = $end ? strtotime($end . ' 23:59:59') : ($start ? strtotime($start . ' 23:59:59') : time());
        $actionDate = $row['created_at'];
        if ((strpos($statusLower, 'cancel') !== false || strpos($statusLower, 'moved_to_history') !== false || strpos($statusLower, 'deleted') !== false) && !empty($row['updated_at'])) {
            $actionDate = $row['updated_at'];
        }
        $activities[] = [
            'type' => 'reservation',
            'title' => $resTitle,
            'details' => $details,
            'status' => $statusVal,
            'date' => $actionDate,
            'event_timestamp' => $endTs,
            'ref_code' => $refCodeVal,
            'reserved_by' => $reservedBy,
            'payment_status' => null,
            'scanned_at' => $row['scanned_at'] ?? null,
            'start_date_raw' => $row['start_date'] ?? '',
            'end_date_raw' => $row['end_date'] ?? '',
            'start_time_raw' => $row['start_time'] ?? '',
            'end_time_raw' => $row['end_time'] ?? ''
        ];
    }
    $stmt->close();
}

// 2. Incident Reports
$colsToEnsure = [
    'subject' => "VARCHAR(150) NULL",
    'report_date' => "DATE NULL"
];
foreach ($colsToEnsure as $col => $def) {
    $check = $con->query("SHOW COLUMNS FROM incident_reports LIKE '$col'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE incident_reports ADD COLUMN $col $def");
    }
}
$stmt = $con->prepare("SELECT 'report' as type, subject, address, nature, other_concern, report_date, status, created_at, id FROM incident_reports WHERE user_id = ? ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $activities[] = [
            'type' => 'report',
            'title' => 'Report Incident - ' . ($row['nature'] ?? 'Incident'),
            'details' => '',
            'report_id' => $row['id'] ?? null,
            'subject' => $row['subject'] ?? '',
            'address' => $row['address'] ?? '',
            'nature' => $row['nature'] ?? '',
            'other_concern' => $row['other_concern'] ?? '',
            'report_date' => $row['report_date'] ?? '',
            'status' => $row['status'] ?? 'new',
            'date' => $row['created_at'],
            'event_timestamp' => strtotime($row['created_at']),
            'ref_code' => 'RIVH-' . str_pad((string)(abs(crc32((string)$row['id'])) % 1000000), 6, '0', STR_PAD_LEFT)
        ];
    }
    $stmt->close();
}

// 3. Guest Forms
$stmt = $con->prepare("SELECT 'guest_form' as type, visitor_first_name, visitor_middle_name, visitor_last_name, visit_date, visit_time, approval_status, denial_reason, created_at, updated_at, ref_code, scanned_at FROM guest_forms WHERE resident_user_id = ? AND (wants_amenity IS NULL OR wants_amenity = 0) AND amenity IS NULL AND start_date IS NULL AND end_date IS NULL ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $guestNameParts = [];
        if (!empty($row['visitor_first_name'])) { $guestNameParts[] = $row['visitor_first_name']; }
        if (!empty($row['visitor_middle_name'])) { $guestNameParts[] = $row['visitor_middle_name']; }
        if (!empty($row['visitor_last_name'])) { $guestNameParts[] = $row['visitor_last_name']; }
        $guestName = trim(implode(' ', $guestNameParts));
        $title = 'Guest Request' . ($guestName !== '' ? ' - ' . $guestName : '');
        if (stripos($row['approval_status'] ?? '', 'cancel') !== false) {
            $title .= ' - Cancelled';
        }
        $visitTs = strtotime(($row['visit_date']??date('Y-m-d')) . ' ' . ($row['visit_time']??'23:59:59'));
        $details = '';
        $reason = trim((string)($row['denial_reason'] ?? ''));
        $statusLower = strtolower((string)($row['approval_status'] ?? ''));
        if ($reason !== '' && (strpos($statusLower, 'denied') !== false || strpos($statusLower, 'reject') !== false)) {
            $details = 'Reason: ' . $reason;
        }
        $statusVal = $row['approval_status'] ?? 'pending';
        if (!empty($row['scanned_at'])) {
            $statusLower = strtolower((string)$statusVal);
            if (strpos($statusLower, 'moved_to_history') === false && strpos($statusLower, 'denied') === false && strpos($statusLower, 'reject') === false && strpos($statusLower, 'cancel') === false && strpos($statusLower, 'expired') === false) {
                $statusVal = 'permission_granted';
            }
        }
        $actionDate = $row['created_at'];
        if ((strpos($statusLower, 'cancel') !== false || strpos($statusLower, 'moved_to_history') !== false || strpos($statusLower, 'deleted') !== false) && !empty($row['updated_at'])) {
            $actionDate = $row['updated_at'];
        }
        $activities[] = [
            'type' => 'guest_form',
            'title' => $title,
            'details' => $details,
            'guest_name' => $guestName,
            'status' => $statusVal,
            'date' => $actionDate,
            'event_timestamp' => $visitTs,
            'ref_code' => $row['ref_code'] ?? 'GST',
            'scanned_at' => $row['scanned_at'] ?? null
        ];
    }
    $stmt->close();
}

usort($activities, function($a, $b) {
    return strtotime($b['date']) - strtotime($a['date']);
});

// Split into Active and History
$activeActivities = [];
$historyActivities = [];

foreach ($activities as $act) {
    $s = strtolower($act['status']);
    $isHistory = false;

    if (strpos($s, 'deleted') !== false || strpos($s, 'cancel') !== false || strpos($s, 'complete') !== false || strpos($s, 'finish') !== false || strpos($s, 'moved_to_history') !== false || strpos($s, 'permission_granted') !== false) {
        $isHistory = true;
    }

    if ($isHistory) {
        $historyActivities[] = $act;
    } else {
        $activeActivities[] = $act;
    }
}


ensureNotificationsTable($con);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    header('Content-Type: application/json');
    if ($con instanceof mysqli) {
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_guest') {
    header('Content-Type: application/json');
    $code = trim($_POST['code'] ?? '');
    if ($code === '' || !($con instanceof mysqli)) {
        echo json_encode(['success' => false, 'message' => 'Invalid request.']);
        exit;
    }
    $stmt = $con->prepare("SELECT id FROM guest_forms WHERE ref_code = ? AND resident_user_id = ? LIMIT 1");
    if (!$stmt) {
        echo json_encode(['success' => false, 'message' => 'Server error.']);
        exit;
    }
    $stmt->bind_param('si', $code, $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $stmt->close();
    if (!$res || $res->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => 'Guest not found.']);
        exit;
    }
    $row = $res->fetch_assoc();
    $guestId = intval($row['id']);
    $stmtU = $con->prepare("UPDATE guest_forms SET approval_status='deleted', qr_path=NULL, scanned_at=NULL, updated_at=NOW() WHERE id = ?");
    if ($stmtU) {
        $stmtU->bind_param('i', $guestId);
        $stmtU->execute();
        $stmtU->close();
    }
    $stmtR = $con->prepare("UPDATE reservations SET approval_status='deleted', status='deleted', updated_at=NOW() WHERE ref_code = ?");
    if ($stmtR) {
        $stmtR->bind_param('s', $code);
        $stmtR->execute();
        $stmtR->close();
    }
    $stmtRR = $con->prepare("UPDATE resident_reservations SET approval_status='deleted', updated_at=NOW() WHERE ref_code = ?");
    if ($stmtRR) {
        $stmtRR->bind_param('s', $code);
        $stmtRR->execute();
        $stmtRR->close();
    }
    echo json_encode(['success' => true]);
    exit;
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $notifications = getUserNotifications($con, $userId, 20);
    $unreadCount = getUserUnreadNotificationCount($con, $userId);
    echo json_encode([
        'success' => true, 
        'active' => $activeActivities, 
        'history' => $historyActivities,
        'account_status' => $userStatus,
        'notifications' => $notifications,
        'unread_count' => $unreadCount
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/guestform.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
body.account-blocked { overflow: hidden; }
.account-blocked-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); align-items: center; justify-content: center; z-index: 3000; }
.account-blocked-content { background: #fff; border-radius: 14px; padding: 28px 30px; width: 92%; max-width: 420px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
.account-blocked-content h3 { margin: 0 0 10px; color: #a83b3b; font-size: 1.2rem; }
.account-blocked-content p { margin: 0 0 20px; color: #333; font-size: 0.95rem; line-height: 1.5; }
.account-blocked-content .btn-logout-only { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 18px; border-radius: 8px; background: #c0392b; color: #fff; text-decoration: none; font-weight: 600; border: 0; cursor: pointer; }
.account-blocked-content .btn-logout-only:hover { filter: brightness(0.95); }
.toast-stack { position: fixed; top: 16px; right: 16px; z-index: 2500; display: flex; flex-direction: column; gap: 8px; }
.main-content.ecopoint-active .top-header {
  background: linear-gradient(135deg, #0d2018 0%, #163e2f 35%, #0b1c15 100%);
  border-bottom: 1px solid rgba(212, 175, 55, 0.45);
  box-shadow: inset 0 -1px 0 rgba(212, 175, 55, 0.25);
}
.main-content.ecopoint-active .top-header .brand-main { color: #fde886; }
.main-content.ecopoint-active .top-header .brand-sub { color: rgba(255,255,255,0.82); }
.main-content.ecopoint-active .top-header .icon-btn,
.main-content.ecopoint-active .top-header .user-profile,
.main-content.ecopoint-active .top-header .menu-toggle { border-color: rgba(212, 175, 55, 0.5); }
.main-content.ecopoint-active .top-header .icon-btn i,
.main-content.ecopoint-active .top-header .user-profile,
.main-content.ecopoint-active .top-header .menu-toggle i { color: #fde886; }
.main-content.ecopoint-active .top-header .notif-count { background: #d4af37; color: #10251b; }

/* Panel shell — light background, not dark green */
.main-content.ecopoint-active #panel-points-history {
  background: #f4f7f5;
  border: 1px solid #dce8e2;
  border-radius: 18px;
  box-shadow: 0 6px 20px rgba(9,18,14,0.06);
  padding: 20px;
}

/* Activity list header */
.main-content.ecopoint-active #panel-points-history .activity-list-header {
  background: linear-gradient(135deg, #0f2f27, #174a3c);
  border: 1px solid rgba(212,175,55,0.35);
  color: #f0ebe2;
  border-radius: 14px;
}
.main-content.ecopoint-active #panel-points-history .activity-list-header div:first-child { color: #f0ebe2; }
.main-content.ecopoint-active #panel-points-history .activity-list-header div:first-child div { color: #cfc6a3; }

.main-content.ecopoint-active #panel-points-history .ecopoint-panel-shell { position: relative; z-index: 1; }

/* Header card — subtle light green, not heavy dark */
.main-content.ecopoint-active #panel-points-history .ecopoint-header-card {
  background: linear-gradient(135deg, #ecfdf5, #d1fae5);
  border: 1px solid #86efac;
  box-shadow: 0 4px 14px rgba(16,163,74,0.08);
  color: #14532d;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-header-kicker { color: #166534; }
.main-content.ecopoint-active #panel-points-history .ecopoint-header-title { color: #14532d; }
.main-content.ecopoint-active #panel-points-history .ecopoint-header-desc { color: #166534; }

/* KPI cards + section cards — white/light, not dark green */
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-card,
.main-content.ecopoint-active #panel-points-history .ecopoint-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  box-shadow: 0 2px 10px rgba(15,23,42,0.04);
}
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-card:first-child {
  background: linear-gradient(135deg, #0f2f27, #1a5240);
  border: 1px solid rgba(212,175,55,0.4);
  box-shadow: 0 4px 16px rgba(10,30,22,0.15);
}

/* KPI text colors — light theme for most cards, gold for balance */
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-label { color: #6b7280; }
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-value { color: #111827; font-weight: 800; }
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-subtext { color: #6b7280; }
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-card:first-child .ecopoint-kpi-label { color: rgba(253,232,134,0.8); }
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-card:first-child .ecopoint-kpi-value { color: #fde886; font-size: 2rem; }
.main-content.ecopoint-active #panel-points-history .ecopoint-kpi-card:first-child .ecopoint-kpi-subtext { color: rgba(240,235,226,0.7); }

/* Section card text colors */
.main-content.ecopoint-active #panel-points-history .ecopoint-card-title { color: #111827; }
.main-content.ecopoint-active #panel-points-history .ecopoint-card-note { color: #6b7280; }
.main-content.ecopoint-active #panel-points-history .ecopoint-material-title { color: #111827; }
.main-content.ecopoint-active #panel-points-history .ecopoint-material-subtitle { color: #6b7280; }
.main-content.ecopoint-active #panel-points-history .ecopoint-table th { color: #6b7280; background: #f8fafc; }
.main-content.ecopoint-active #panel-points-history .ecopoint-table td { color: #374151; }

/* Material values — green accent */
.main-content.ecopoint-active #panel-points-history .ecopoint-material-value { color: #14532d; }

/* Live panel — prominent dark card */
.main-content.ecopoint-active #panel-points-history .ecopoint-live-panel {
  background: linear-gradient(135deg, #0f2f27, #174a3c);
  border: 1px solid rgba(212,175,55,0.35);
  box-shadow: 0 6px 20px rgba(8,22,18,0.12);
  padding: 18px 18px 16px;
  border-radius: 18px;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-card-title {
  margin: 0 0 10px;
  font-size: 0.82rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: #fde886;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-header-text {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-status-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  background: rgba(253,232,134,0.12);
  border: 1px solid rgba(253,232,134,0.35);
  color: #fde886;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-station-name {
  font-size: 1rem;
  font-weight: 700;
  color: #f7f4ea;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-indicator {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  background: #fde886;
  box-shadow: 0 0 0 6px rgba(253,232,134,0.2);
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-message {
  background: rgba(255,255,255,0.06);
  border-left: 3px solid rgba(253,232,134,0.6);
  border-radius: 10px;
  padding: 10px 12px;
  margin: 10px 0 14px;
  line-height: 1.5;
  font-size: 0.9rem;
  color: #f0ebe2;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-meta {
  display: grid;
  grid-template-columns: repeat(4, minmax(120px, 1fr));
  gap: 10px;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-metric {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(212,175,55,0.18);
  border-radius: 10px;
  padding: 10px 12px 8px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 70px;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-metric-label {
  font-size: 0.68rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #cfc6a3;
  margin-bottom: 6px;
}
.main-content.ecopoint-active #panel-points-history .ecopoint-live-metric strong {
  font-size: 1rem;
  font-weight: 700;
  color: #fde886;
}

/* Progress bars */
.main-content.ecopoint-active #panel-points-history .ecopoint-progress { background: #e5e7eb; border-radius: 999px; }
.main-content.ecopoint-active #panel-points-history .ecopoint-progress-bar { background: linear-gradient(90deg, #16a34a, #22c55e); }
.main-content.ecopoint-active #panel-points-history .ecopoint-material-row { border-bottom-color: #e5e7eb; }

/* Table */
.main-content.ecopoint-active #panel-points-history .ecopoint-table { background: transparent; }
.main-content.ecopoint-active #panel-points-history .ecopoint-table th,
.main-content.ecopoint-active #panel-points-history .ecopoint-table td { border-bottom-color: #e5e7eb; }

/* Empty state */
.main-content.ecopoint-active #panel-points-history .ecopoint-empty {
  background: #f9fafb;
  border-color: #d1d5db;
  color: #6b7280;
}

/* Action buttons */
.main-content.ecopoint-active #panel-points-history .ecopoint-action-btn {
  background: linear-gradient(135deg, #d4af37, #f1d77d);
  color: #0d2018;
  box-shadow: 0 6px 14px rgba(212,175,55,0.18);
}
.main-content.ecopoint-active #panel-points-history .ecopoint-action-btn:hover { filter: brightness(1.04); }

@media (max-width: 768px) {
  .main-content.ecopoint-active #panel-points-history { padding: 14px; }
  .main-content.ecopoint-active #panel-points-history .ecopoint-live-meta { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
  .main-content.ecopoint-active #panel-points-history .ecopoint-live-meta { grid-template-columns: 1fr; }
  .main-content.ecopoint-active #panel-points-history .ecopoint-live-header { align-items: flex-start; }
}
.toast-item { background: #fff; border-left: 4px solid #23412e; box-shadow: 0 4px 12px rgba(0,0,0,0.18); border-radius: 10px; padding: 10px 12px; min-width: 260px; display: flex; align-items: flex-start; gap: 8px; color: #333; }
.toast-item .toast-message { flex: 1; font-size: 0.85rem; }
.toast-item .toast-close { background: transparent; border: 0; color: #888; font-size: 1rem; cursor: pointer; line-height: 1; }
.toast-item.toast-success { border-left-color: #28a745; }
.toast-item.toast-warning { border-left-color: #d97706; }
.toast-item.toast-error { border-left-color: #c0392b; }
.nav-item.ecopoint-link {
  color: #14532d;
  background-color: #dcfce7;
  border-left: 4px solid #16a34a;
}
.nav-item.ecopoint-link:hover {
  background-color: #bbf7d0;
  color: #14532d;
}
.nav-item.ecopoint-link.active {
  background-color: #bbf7d0;
  color: #14532d;
  font-weight: 700;
}
.sidebar-footer .download-qr-btn {
  position: relative;
  background-image: linear-gradient(135deg, #fcd34d 0%, #eab308 50%, #d97706 100%);
  box-shadow: 0 6px 14px rgba(234, 179, 8, 0.28);
  transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
}
.sidebar-footer .download-qr-btn:hover {
  background-image: linear-gradient(135deg, #fbbf24 0%, #d97706 50%, #b45309 100%);
}
.sidebar-footer .download-qr-btn.qr-highlight {
  background: linear-gradient(135deg, #f8d76a, #eab308);
  color: #153426;
  box-shadow: 0 0 0 3px rgba(248, 215, 106, 0.18), 0 12px 22px rgba(234, 179, 8, 0.35);
  transform: translateY(-1px) scale(1.01);
  filter: saturate(1.08);
}
.qr-help-tooltip {
  position: fixed;
  z-index: 2400;
  width: min(260px, calc(100vw - 24px));
  background: rgba(11, 29, 23, 0.95);
  color: #f7f1d8;
  border: 1px solid rgba(241, 215, 130, 0.45);
  border-radius: 12px;
  box-shadow: 0 18px 28px rgba(6, 20, 17, 0.18);
  padding: 12px 14px;
  pointer-events: none;
  opacity: 0;
  transform: translate(10px, -50%);
  transition: opacity 0.18s ease, transform 0.18s ease;
}
.qr-help-tooltip.visible {
  opacity: 1;
  transform: translate(0, -50%);
}
.qr-help-tooltip::after {
  content: "";
  position: absolute;
  left: -7px;
  top: 50%;
  width: 12px;
  height: 12px;
  background: rgba(11, 29, 23, 0.95);
  border-left: 1px solid rgba(241, 215, 130, 0.45);
  border-bottom: 1px solid rgba(241, 215, 130, 0.45);
  transform: translateY(-50%) rotate(45deg);
}
.qr-help-tooltip strong {
  display: block;
  font-size: 0.84rem;
  color: #fde886;
  margin-bottom: 6px;
  letter-spacing: 0.02em;
}
.qr-help-tooltip span {
  display: block;
  font-size: 0.78rem;
  line-height: 1.5;
  color: #f0ebe2;
}
.field-warning {
  color: #333;
  font-size: 0.85rem;
  margin-top: 6px;
  background: #fff;
  border-left: 4px solid #c0392b;
  box-shadow: 0 2px 8px rgba(0,0,0,0.12);
  border-radius: 8px;
  padding: 8px 10px;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  position: relative;
  z-index: 2;
}
.field-warning .warn-icon {
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #c0392b;
  color: #fff;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 0.75rem;
  flex-shrink: 0;
  line-height: 1;
}
.field-warning .msg {
  color: #333;
}
.field-warning .close-warn {
  margin-left: auto;
  background: transparent;
  border: 0;
  font-size: 1rem;
  cursor: pointer;
  color: #888;
  line-height: 1;
}
.field-warning .close-warn:hover {
  color: #555;
}

.report-wait-content {
  max-width: 520px;
  padding: 28px 30px;
  text-align: center;
}
.report-wait-content h3 {
  margin: 0 0 8px;
  color: #23412e;
  font-size: 1.2rem;
}
.report-wait-content p {
  margin: 0 0 18px;
  color: #555;
  font-size: 0.95rem;
  line-height: 1.6;
}
.report-wait-close {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: #eef2f0;
  color: #23412e;
  border: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: 20px;
}

/* Removed view-details-btn styles as they are now in dashboard.css */

/* Resident ID Card Styles (Hidden but used for Canvas) - Matches resident_qr_view.php */
.resident-id-card { width: 360px; background: #1e1e1e; border-radius: 16px; box-shadow: 0 10px 28px rgba(0,0,0,0.35); overflow: hidden; color:#fff; font-family: 'Poppins', sans-serif; }
.resident-id-card .card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#111; }
.resident-id-card .brand { display:flex; align-items:center; gap:8px; }
.resident-id-card .brand img { height:28px; }
.resident-id-card .brand .text { font-weight:700; color:#e5ddc6; }
.resident-id-card .id-top { display:flex; gap:12px; padding:14px 16px; background:#181818; }
.resident-id-card .avatar { width:160px; height:160px; border-radius:12px; background:#ffffff; color:#23412e; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.4rem; overflow:hidden; }
.resident-id-card .avatar img { width:100%; height:100%; object-fit:contain; }
.resident-id-card .top-info { flex:1; }
.resident-id-card .top-info .name { font-size:1.05rem; font-weight:700; margin:0 0 4px 0; color:#eaeaea; }
.resident-id-card .top-info .contact { font-size:0.86rem; color:#cfcfcf; }
.resident-id-card .divider { height:1px; background:#2f2f2f; margin:0 16px; }
.resident-id-card .id-body { padding:14px 16px; }
.resident-id-card .row { display:flex; align-items:center; justify-content:space-between; margin:6px 0; }
.resident-id-card .label { color:#bdbdbd; font-weight:600; font-size:0.85rem; }
.resident-id-card .value { color:#eaeaea; font-size:0.95rem; }
.resident-id-card .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:0.85rem; font-weight:700; }
.resident-id-card .badge.active { background:#23412e; color:#e5ddc6; }
.resident-id-card .badge.disabled { background:#a83b3b; color:#fff; }
.resident-id-card .foot { padding:10px 16px; color:#aaa; font-size:0.82rem; }

    .report-card {
        background: #fff;
        padding: 30px;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        border: 1px solid #eee;
        max-width: 900px;
        margin: 0 auto;
    }
    .report-header { text-align:center; margin-bottom:25px; border-bottom:1px solid #f0f0f0; padding-bottom:15px; }
    .report-header h2 { margin: 0; font-size: 1.4rem; color: #23412e; font-weight:700; }
    .report-sub { font-size: 0.85rem; color:#666; margin: 2px 0; }
    .report-title { text-align:center; font-weight:700; margin: 15px 0 0; font-size:1.1rem; color:#333; }
    
    .checkbox-group { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 8px; }
    .checkbox-group label { display: flex; align-items: center; gap: 8px; font-size: 0.9rem; cursor:pointer; }
    .checkbox-group input[type="checkbox"] { width:18px; height:18px; accent-color:#23412e; }
    
    .file-list { margin-top: 10px; font-size: 0.85rem; color: #555; }
    
    .error-box { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; padding:12px; border-radius:8px; margin-bottom:20px; font-size:0.9rem; }
    .error-text { color:#dc2626; font-size:0.8rem; margin-top:4px; }
    
    .explanation-panel {
        background: #2b2b2b; color: #eee; padding: 25px; border-radius: 12px; margin-bottom:25px;
        display:flex; flex-direction:column; gap:10px;
        border-left: 5px solid #e5b84a;
    }
    .explanation-panel h3 { margin:0; color:#fff; }
    .explanation-panel p { margin:0; font-size:0.9rem; opacity:0.9; line-height:1.5; }
    .explanation-links { display:flex; gap:15px; margin-top:10px; }
    .explanation-links a { color:#e5b84a; text-decoration:none; font-size:0.85rem; font-weight:600; }
    .explanation-links a:hover { text-decoration:underline; }

    /* Modal Tweaks for Profile Page context */
    .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center; z-index: 2000; }
    .modal-content { background: #fff; padding: 25px; border-radius: 12px; width: 90%; max-width: 600px; position: relative; max-height:80vh; overflow-y:auto; }
    .modal .close { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; background: #e5e7eb; color: #111827; border: 0; display: flex; align-items: center; justify-content: center; line-height: 1; cursor: pointer; font-size: 18px; }
    .modal .close:hover { filter: brightness(0.95); }
    .close-btn { position: absolute; top: 15px; right: 15px; font-size: 20px; cursor: pointer; color: #555; }
#guestPassModal .modal-content { width: 92%; max-width: 420px; padding: 24px; text-align: center; max-height: calc(100vh - 120px); }
#guestPassModal h3 { margin: 0 0 12px; color: #23412e; font-size: 1.05rem; font-weight: 700; }
#guestPassModal .guest-pass-header { margin-bottom: 10px; text-align: center; }
#guestPassModal .guest-pass-name { font-size: 1rem; font-weight: 600; color: #333; }
#guestPassModal .guest-pass-sub { margin-top: 4px; color: #666; font-size: 0.9rem; text-align: center; }
#guestPassModal .guest-pass-note { margin-top: 12px; font-size: 0.85rem; color: #888; text-align: center; }
#guestPassModal .guest-pass-actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
#guestPassModal .guest-pass-link { display: inline-flex; margin-top: 10px; color: #4f46e5; text-decoration: underline; font-size: 0.9rem; }
#submitNoticeModal { display: flex; align-items: center; justify-content: center; }
#submitNoticeModal .modal-content { width: 92%; max-width: 420px; padding: 24px; text-align: center; height: auto; min-height: unset; }
#submitNoticeModal .close { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; background: #e5e7eb; color: #111827; border: 0; display: flex; align-items: center; justify-content: center; line-height: 1; padding: 0; cursor: pointer; }

    /* Fix for Resident Dashboard Modal to ensure it fits screen and close button is visible */
    #activityModal .modal-content {
        max-height: 90vh;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        padding: 0;
    }
    #activityModal .close {
        position: absolute;
        top: 10px;
        right: 15px;
        font-size: 28px;
        font-weight: bold;
        color: #555;
        cursor: pointer;
        z-index: 100;
        background: rgba(255,255,255,0.9);
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        transition: all 0.2s ease;
    }
    #activityModal .close:hover {
        color: #000;
        background: #fff;
        transform: scale(1.1);
    }
    #activityModalBody {
        overflow-y: auto;
        padding: 30px;
        flex: 1;
    }

/* Sidebar UI Improvements */
/* Moved to dashboard.css */
.note-error{color:#b91c1c;font-weight:700;}
.notif-error { color:#b91c1c; font-weight:700; }
.ecopoint-panel-shell{display:flex;flex-direction:column;gap:14px}
.ecopoint-header-card{background:linear-gradient(135deg,#ecfdf5,#d1fae5);border:1px solid #86efac;border-radius:14px;padding:16px 18px;color:#14532d}
.ecopoint-header-kicker{font-size:0.74rem;font-weight:800;text-transform:uppercase;letter-spacing:0.08em}
.ecopoint-header-title{font-size:1.3rem;font-weight:800;margin-top:4px}
.ecopoint-header-desc{font-size:0.9rem;line-height:1.5;margin-top:6px;color:#166534}
.ecopoint-promo{display:inline-block;background:linear-gradient(90deg,#f0fdf4,#dcfce7);color:#14532d;padding:8px 12px;border-radius:12px;font-weight:800;margin-bottom:8px}
.ecopoint-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}
.ecopoint-kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;box-shadow:0 2px 10px rgba(15,23,42,0.04)}
.ecopoint-kpi-label{font-size:0.78rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280}
.ecopoint-kpi-value{font-size:1.4rem;font-weight:800;color:#111827;margin-top:4px}
.ecopoint-kpi-subtext{font-size:0.8rem;color:#4b5563;line-height:1.4;margin-top:4px}
.ecopoint-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;box-shadow:0 2px 10px rgba(15,23,42,0.04)}
.ecopoint-card-title{font-size:1.02rem;font-weight:800;color:#111827;margin:0 0 6px}
.ecopoint-card-note{font-size:0.85rem;color:#6b7280;line-height:1.45;margin-bottom:14px}
.ecopoint-progress{width:100%;height:10px;border-radius:999px;background:#e5e7eb;overflow:hidden}
.ecopoint-progress-bar{height:100%;border-radius:inherit;background:linear-gradient(90deg,#16a34a,#22c55e)}
.ecopoint-material-list{display:flex;flex-direction:column;gap:12px}
.ecopoint-material-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9}
.ecopoint-material-row:last-child{border-bottom:none;padding-bottom:0}
.ecopoint-material-main{flex:1;min-width:0}
.ecopoint-material-title{font-weight:700;color:#111827;font-size:0.92rem}
.ecopoint-material-subtitle{font-size:0.8rem;color:#6b7280;margin-top:2px}
.ecopoint-material-value{font-weight:800;color:#14532d;font-size:0.9rem;white-space:nowrap}
.ecopoint-actions{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
.ecopoint-action-btn{display:inline-flex;align-items:center;gap:8px;padding:11px 16px;border-radius:12px;background:#23412e;color:#fff;text-decoration:none;font-weight:700;border:none;cursor:pointer}
.ecopoint-action-btn:hover{background:#1b3424}
.ecopoint-action-note{font-size:0.82rem;color:#6b7280}
.ecopoint-table-wrap{width:100%;overflow-x:auto}
.ecopoint-table{width:100%;border-collapse:collapse;min-width:640px}
.ecopoint-table th,.ecopoint-table td{padding:12px 10px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:0.88rem;vertical-align:top}
.ecopoint-table th{font-size:0.78rem;font-weight:800;text-transform:uppercase;letter-spacing:0.06em;color:#6b7280;background:#f8fafc}
.ecopoint-empty{padding:18px;border:1px dashed #d1d5db;border-radius:14px;background:#f9fafb;color:#6b7280;text-align:center}
@media (max-width: 768px){
  .ecopoint-kpi-grid{grid-template-columns:1fr}
  .ecopoint-header-title{font-size:1.2rem}
}
#qrChoiceModal .modal-content,#qrViewModal .modal-content{width:min(92vw,420px);padding:20px}
#qrChoiceModal .modal-content h3,#qrViewModal .modal-content h3{font-size:1.05rem}
#qrViewCardContainer .resident-id-card .id-top{flex-wrap:wrap}
#qrViewCardContainer .resident-id-card .avatar{width:120px;height:120px;min-width:100px;font-size:1.1rem}
#qrViewCardContainer .resident-id-card .top-info .name{font-size:.95rem}
@media (max-width:400px){
  #qrChoiceModal .modal-content,#qrViewModal .modal-content{width:96vw;padding:16px}
  #qrViewCardContainer .resident-id-card .id-top{gap:8px}
  #qrViewCardContainer .resident-id-card .avatar{width:90px;height:90px;min-width:80px}
}
</style>
<style>
.item-extra-link.item-extra-cancel{background:#ef4444;color:#ffffff;border:1px solid #ef4444;padding:8px 16px;border-radius:50px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-weight:500;text-decoration:none}
.item-extra-link.item-extra-cancel:hover{background:#dc2626;color:#ffffff;transform:translateY(-2px);box-shadow:0 4px 6px rgba(239, 68, 68, 0.2);text-decoration:none}
.cancel-modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:nowrap;padding:6px 0 0 0}
.cancel-modal-actions .cancel-modal-keep,.cancel-modal-actions .cancel-modal-confirm{padding:10px 20px;border-radius:10px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;border:0}
.cancel-modal-actions .cancel-modal-keep{background:#e5e7eb;color:#111827}
.cancel-modal-actions .cancel-modal-confirm{background:#c0392b;color:#fff}
.cancel-modal-content{width:90%;max-width:450px;padding:30px;border-radius:18px}
.cancel-modal-body{text-align:center}
.cancel-modal-note{color:#c0392b;font-weight:600;font-size:0.85rem}
.item-extra-link.update-proof-btn{background:#7c3aed;color:#ffffff;border:1px solid #7c3aed;padding:8px 16px;border-radius:50px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-weight:500;text-decoration:none}
.item-extra-link.update-proof-btn:hover{background:#6d28d9;color:#ffffff;transform:translateY(-2px);box-shadow:0 4px 6px rgba(124, 58, 237, 0.25);text-decoration:none}
.item-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.item-extra-link.item-extra-move-history{background:#e5e7eb;color:#6b7280!important;border:1px solid #d1d5db!important;padding:8px 16px;border-radius:50px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-weight:500;text-decoration:none;line-height:1;margin-top:0!important;box-sizing:border-box}
.item-extra-link.item-extra-move-history:hover{background:#d1d5db;color:#6b7280!important;transform:translateY(-2px);box-shadow:0 4px 6px rgba(0,0,0,0.12);text-decoration:none}
.item-extra-schedule.status-neutral{background:#ffffff;color:#111827;border:1px solid #e5e7eb;border-radius:12px;padding:12px}
</style>
</head>
<body class="<?php echo $isAccountBlocked ? 'account-blocked' : ''; ?>">
<?php if ($flashNotice !== '') { ?>
  <div id="submitNoticeModal" class="modal" style="display:flex;">
    <div class="modal-content" style="max-width:420px;text-align:center;">
      <button type="button" class="close" id="submitNoticeClose" aria-label="Close">&times;</button>
      <div style="font-size:1.1rem;font-weight:700;color:#23412e;margin-bottom:6px;">Request submitted</div>
      <div style="color:#555;"><?php echo htmlspecialchars($flashNotice); ?></div>
      <button type="button" id="submitNoticeBtn" style="margin-top:18px;background:#23412e;color:#fff;border:none;border-radius:8px;padding:10px 16px;cursor:pointer;font-weight:600;">Close</button>
    </div>
  </div>
  <script>
    (function(){
      var modal=document.getElementById('submitNoticeModal');
      var closeBtn=document.getElementById('submitNoticeClose');
      var okBtn=document.getElementById('submitNoticeBtn');
      function close(){ if(modal) modal.style.display='none'; }
      if(closeBtn) closeBtn.addEventListener('click', close);
      if(okBtn) okBtn.addEventListener('click', close);
      if(modal) modal.addEventListener('click', function(e){ if(e.target===modal) close(); });
    })();
  </script>
<?php } ?>

<div class="app-container">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <a href="mainpage.php" class="back-btn"><i class="fa-solid fa-arrow-left"></i></a>
    </div>

    <nav class="nav-menu">
      <a href="#" class="nav-item <?php echo $activeSection === 'panel-requests' ? 'active' : ''; ?>" data-section="panel-requests"><i class="fa-solid fa-list"></i> <span>My Requests</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span></a>
      <a href="#" class="nav-item ecopoint-link <?php echo $activeSection === 'panel-points-history' ? 'active' : ''; ?>" data-section="panel-points-history">
        <i class="fa-solid fa-coins"></i>
        <span>
          VHEcoPoint
          <small style="display:block; font-size:0.7rem; color:#166534; font-weight:700; margin-top:2px;">Smart Waste Segregation Station</small>
        </span>
      </a>
      <a href="#" class="nav-item <?php echo $activeSection === 'panel-guest-form' ? 'active' : ''; ?>" data-section="panel-guest-form"><i class="fa-solid fa-user-plus"></i> <span>Guest Form</span></a>
      <a href="#" class="nav-item <?php echo $activeSection === 'panel-my-guests' ? 'active' : ''; ?>" data-section="panel-my-guests"><i class="fa-solid fa-user-group"></i> <span>My Guests</span></a>
      <a href="report_incident.php" class="nav-item"><i class="fa-solid fa-triangle-exclamation"></i> <span>Report Incident</span></a>
      <a href="#" class="nav-item <?php echo $activeSection === 'panel-history' ? 'active' : ''; ?>" data-section="panel-history"><i class="fa-solid fa-clock-rotate-left"></i> <span>History</span></a>
    </nav>

    <div class="sidebar-footer">
      <?php if (!$isAccountBlocked): ?>
      <a href="#" onclick="openQRChoice(); return false;" class="download-qr-btn" title="My QR" aria-label="My QR Code">
        <i class="fa-solid fa-qrcode"></i> <span>My QR</span>
      </a>
      <?php endif; ?>
      <div id="qrHelpTooltip" class="qr-help-tooltip" role="tooltip" aria-hidden="true">
        <strong>This is your QR Code</strong>
        <span>Use this QR code at the VHEcoPoint Station to start your recycling session.</span>
      </div>
    <!-- QR Choice Modal -->
    <div id="qrChoiceModal" class="modal" style="display:none;">
      <div class="modal-content" style="max-width:420px;text-align:center;position:relative;">
        <button type="button" class="close" aria-label="Close" id="qrChoiceClose">&times;</button>
        <h3 style="margin-top:6px;">My QR Code</h3>
        <div style="margin:18px 0 4px;color:#475569;line-height:1.6;">
          Choose one of the options below to continue with your resident QR ID.
        </div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:18px;">
          <button type="button" class="btn-confirm" id="qrViewBtn">View</button>
          <button type="button" class="btn-confirm" id="qrDownloadBtn">Download</button>
        </div>
      </div>
    </div>

    <!-- QR View Modal (full ID card preview) -->
    <div id="qrViewModal" class="modal" style="display:none;">
      <div class="modal-content" style="max-width:560px;text-align:center;">
        <button type="button" class="close" aria-label="Close" id="qrViewClose">&times;</button>
        <h3 style="margin-top:6px;">My Personal QR ID</h3>
        <div id="qrViewCardContainer" style="margin:12px 0; display:flex; justify-content:center;"></div>
        <div style="margin-top:8px;color:#6b7280;font-size:0.95rem;">This is your resident QR ID card, including your personal details and scan-ready code.</div>
        <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top:12px;">
          <button type="button" class="btn-confirm" id="qrViewDownloadBtn">Download</button>
        </div>
      </div>
    </div>
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i> <span>Log Out</span></a>
    </div>

    <!-- Hidden ID Card for Download Generation -->
    <?php if (!$isAccountBlocked): ?>
    <div id="residentCardWrap" style="position:fixed; left:-9999px; top:0; opacity:0;">
        <div class="resident-id-card" id="residentCard">
          <div class="card-header">
            <div class="brand"><img src="images/logo.svg" alt="VictorianPass"><div class="text">Victorian Pass</div></div>
          </div>
          <div class="id-top">
            <div class="avatar">
              <img src="<?php echo htmlspecialchars($qrRelPath); ?>" alt="Resident QR">
            </div>
            <div class="top-info">
              <div style="color:#e5ddc6; font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;">OFFICIAL PROOF OF RESIDENCY</div>
              <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="contact">
                <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                <?php if(!empty($displayPhone)){ echo ' • ' . htmlspecialchars($displayPhone); } ?>
              </div>
              <div style="margin-top:6px;"><span class="badge active">Verified Resident</span></div>
            </div>
          </div>
          <div class="divider"></div>
          <div class="id-body">
            <div class="row"><div class="label">Block</div><div class="value"><?php echo htmlspecialchars($user['house_number'] ?? '-'); ?></div></div>
            <div class="row"><div class="label">Unit / Address</div><div class="value"><?php echo htmlspecialchars($user['address'] ?? '-'); ?></div></div>
            <div class="row"><div class="label">Contact</div><div class="value"><?php echo htmlspecialchars($displayPhone ?: '-'); ?></div></div>
            <div class="row"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($user['email'] ?? '-'); ?></div></div>
          </div>
          <div class="divider"></div>
          <div class="foot">Scan QR to open this digital ID • Code linked to resident profile</div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    var personalQRSrc = <?php echo json_encode($qrRelPath); ?>;
    var personalQRDownloadName = 'My_Personal_QR_ID.png';

    function downloadPersonalQR(){
      var src = personalQRSrc || '';
      if(!src) return;

      function doDownload(downloadUrl){
        var link = document.createElement('a');
        link.href = downloadUrl;
        link.download = personalQRDownloadName;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      }

      function downloadFromBlob(url){
        fetch(url)
          .then(function(response){ return response.blob(); })
          .then(function(blob){
            var blobUrl = URL.createObjectURL(blob);
            doDownload(blobUrl);
            setTimeout(function(){ URL.revokeObjectURL(blobUrl); }, 1000);
          })
          .catch(function(){
            doDownload(src);
          });
      }

      function startDownload(){
        if(src.indexOf('http://') === 0 || src.indexOf('https://') === 0){
          downloadFromBlob(src);
        } else {
          doDownload(src);
        }
      }

      if(typeof window.openQRWarning === 'function'){
        window.openQRWarning(startDownload, {
          title: 'My QR Code',
          message: 'Choose Download to save your personal QR code for offline use.',
          cancelText: 'Close',
          proceedText: 'Download'
        });
      } else {
        startDownload();
      }
    }
    </script>
  </aside>

  <!-- MAIN CONTENT -->
  <?php $isEcoPointThemeActive = ($activeSection === 'panel-points-history'); ?>
  <main class="main-content<?php echo $isEcoPointThemeActive ? ' ecopoint-active' : ''; ?>">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Top Header -->
    <header class="top-header<?php echo $isEcoPointThemeActive ? ' ecopoint-theme-header' : ''; ?>">
      <div class="header-brand">
        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
        <a href="mainpage.php" aria-label="Go to Main Page"><img src="images/logo.svg" alt="Logo"></a>
        <div class="brand-text">
          <span class="brand-main"><?php echo $isEcoPointThemeActive ? 'VHEcoPoint' : 'VictorianPass'; ?></span>
          <span class="brand-sub"><?php echo $isEcoPointThemeActive ? 'Smart Waste Segregation Station' : 'Victorian Heights Subdivision'; ?></span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <div id="notifPopup" class="notif-popup" style="display:none;"></div>
        <a href="#" class="user-profile" id="profileTrigger">
          <span class="user-name">Hi, <?php echo htmlspecialchars($user['first_name'] ?? 'Resident'); ?></span>
          <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="user-avatar" id="headerProfileImg">
        </a>
      </div>
    </header>

    <div class="content-wrapper">
      <div class="right-panel">
        <div class="panel-section" id="panel-requests" style="<?php echo $activeSection === 'panel-requests' ? '' : 'display:none;'; ?>">
          <div class="activity-list-header">
            <div>My Requests</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search by code or keyword" id="requestSearch">
            </div>
          </div>

          <div class="item-list">
            <?php if (empty($activeActivities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No active requests.</div>
            <?php else: ?>
              <?php foreach ($activeActivities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  if (strpos($s, 'permission')!==false || strpos($s, 'granted')!==false) $statusClass = 'status-access-granted';
                  elseif (strpos($s, 'approv')!==false) $statusClass = 'status-approved';
                  elseif (strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-ongoing';
                  elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false || strpos($s, 'moved_to_history')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  $displayStatus = ucwords(str_replace('_',' ', (string)$act['status']));
                  if (strpos($s, 'permission_granted') !== false) $displayStatus = 'Access Granted';
                  if (strpos($s, 'moved_to_history') !== false) $displayStatus = !empty($act['scanned_at']) ? 'Access Granted' : 'Denied';
                  if ($s === 'new') $displayStatus = 'Filed';
                  $att = isset($act['attempts']) ? intval($act['attempts']) : 0;
                  $pay = strtolower((string)($act['payment_status'] ?? ''));
                  if ($pay === 'rejected' && $att >= 3) {
                    $displayStatus = 'Denied – Max Attempts Reached';
                    $statusClass = 'status-denied';
                  }
                  $isReservation = (($act['type'] ?? '') === 'reservation');
                  $detailsText = (string)($act['details'] ?? '');
                  $reasonText = '';
                  $scheduleText = $detailsText;
                  if ($isReservation && strpos($detailsText, 'Reason:') !== false) {
                    $reasonText = trim(substr($detailsText, strpos($detailsText, 'Reason:')));
                    $scheduleText = trim(substr($detailsText, 0, strpos($detailsText, 'Reason:')));
                  }
                  $displayTitle = (string)($act['title'] ?? '');
                  if ($isReservation) {
                    $rawTitle = $displayTitle;
                    $prefix = 'Reservation Schedule - ';
                    if (stripos($rawTitle, $prefix) === 0) {
                      $rest = substr($rawTitle, strlen($prefix));
                      $parts = explode(' - ', $rest);
                      $displayTitle = trim($parts[0] ?? '');
                    }
                    if ($displayTitle === '') { $displayTitle = 'Amenity'; }
                    $displayTitle = 'Reservation – ' . $displayTitle;
                  }
                  $createdText = date('m/d/y g:i A', strtotime($act['date']));
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-reserved-by="<?php echo htmlspecialchars($act['reserved_by'] ?? ''); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>" data-start-date="<?php echo htmlspecialchars($act['start_date_raw'] ?? ''); ?>" data-end-date="<?php echo htmlspecialchars($act['end_date_raw'] ?? ''); ?>" data-start-time="<?php echo htmlspecialchars($act['start_time_raw'] ?? ''); ?>" data-end-time="<?php echo htmlspecialchars($act['end_time_raw'] ?? ''); ?>" data-schedule="<?php echo htmlspecialchars($scheduleText); ?>" data-reason="<?php echo htmlspecialchars($reasonText); ?>" data-attempts="<?php echo isset($act['attempts']) ? intval($act['attempts']) : 0; ?>" data-scanned-at="<?php echo htmlspecialchars($act['scanned_at'] ?? ''); ?>"<?php if (($act['type'] ?? '') === 'report') { echo ' data-report-id="' . htmlspecialchars($act['report_id'] ?? '') . '"'; echo ' data-report-subject="' . htmlspecialchars($act['subject'] ?? '') . '"'; echo ' data-report-address="' . htmlspecialchars($act['address'] ?? '') . '"'; echo ' data-report-date="' . htmlspecialchars($act['report_date'] ?? '') . '"'; echo ' data-report-nature="' . htmlspecialchars($act['nature'] ?? '') . '"'; echo ' data-report-other="' . htmlspecialchars($act['other_concern'] ?? '') . '"'; } ?><?php if (($act['type'] ?? '') === 'guest_form') { echo ' data-guest-name="' . htmlspecialchars($act['guest_name'] ?? '') . '"'; } ?>>
                 <div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>
                 <div class="item-content">
                   <div class="item-row" style="display:flex; justify-content:space-between; margin-bottom:5px;">
                     <div class="item-left">
                       <span class="status-badge <?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                       <?php if ($isReservation): ?>
                       <span class="item-amenity"><?php echo htmlspecialchars($displayTitle); ?></span>
                       <?php else: ?>
                       <span class="item-title"><?php echo htmlspecialchars($displayTitle); ?></span>
                       <?php endif; ?>
                     <?php if ($isReservation): ?>
                        <span class="item-details" style="display:none;"></span>
                      <?php else: ?>
                        <?php if(!empty($act['details'])): ?>
                        <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                        <?php else: ?>
                        <span class="item-details" style="display:none;"></span>
                        <?php endif; ?>
                      <?php endif; ?>
                     </div>
                     <div class="item-created"><?php echo htmlspecialchars($createdText); ?></div>
                   </div>
                  <?php if (!$isReservation && (($act['type'] ?? '') !== 'guest_form') && (($act['type'] ?? '') !== 'report')): ?>
                   <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                     <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                   </div>
                   <?php endif; ?>
                  <?php if (($act['type'] ?? '') === 'reservation' && !empty($act['reserved_by'])): ?>
                  <div style="font-size:0.8rem; color:#6b7280; margin-left: 48px;" class="item-reserved-by">
                    <?php echo htmlspecialchars($act['reserved_by']); ?>
                  </div>
                  <?php endif; ?>
                   <div class="item-extra" data-loaded="0"></div>
                 </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel-section" id="panel-points-history" style="<?php echo $activeSection === 'panel-points-history' ? '' : 'display:none;'; ?>">

          <div class="ecopoint-panel-shell" style="margin-top:20px;">
            <div class="ecopoint-header-card">
              <div class="ecopoint-header-kicker">Resident Dashboard</div>
              <div class="ecopoint-header-title"><i class="fa-solid fa-leaf" style="margin-right:8px; font-size:0.9em;"></i>Your VHEcoPoint Dashboard</div>
              <div class="ecopoint-header-desc">Track your current point balance, weekly recycling progress, daily session usage, expiry countdown, and station-ready QR access in one place.</div>
            </div>

            <div class="ecopoint-kpi-grid">
              <div class="ecopoint-kpi-card">
                <div class="ecopoint-kpi-label"><i class="fa-solid fa-coins" style="margin-right:5px; opacity:0.7;"></i>Current Point Balance</div>
                <div class="ecopoint-kpi-value"><i class="fa-solid fa-star" style="font-size:0.6em; margin-right:4px; opacity:0.6;"></i><?php echo number_format($currentPoints); ?> pts</div>
                <div class="ecopoint-kpi-subtext">Net balance from VHEcoPoint recycling ledger (earn − redeem ± adjustments).</div>
              </div>
              <div class="ecopoint-kpi-card">
                <div class="ecopoint-kpi-label"><i class="fa-solid fa-chart-line" style="margin-right:5px; opacity:0.7;"></i>Weekly Points Earned</div>
                <div class="ecopoint-kpi-value"><?php echo number_format($ecoPointWeeklyPoints); ?> / <?php echo number_format($ecoPointWeeklyCap); ?> pts</div>
                <div class="ecopoint-kpi-subtext"><?php echo number_format($ecoPointWeeklyRemaining); ?> pts remain before this week's program cap resets.</div>
              </div>
              <div class="ecopoint-kpi-card">
                <div class="ecopoint-kpi-label"><i class="fa-solid fa-right-to-bracket" style="margin-right:5px; opacity:0.7;"></i>Daily Sessions Used</div>
                <div class="ecopoint-kpi-value"><?php echo number_format($ecoPointTodaySessionsUsed); ?> / <?php echo number_format($ecoPointDailySessionsMax); ?> Used Today</div>
                <div class="ecopoint-kpi-subtext"><?php echo number_format($ecoPointSessionsRemaining); ?> session<?php echo $ecoPointSessionsRemaining === 1 ? '' : 's'; ?> remaining. Maximum of 3 VHEcoPoint station visits per day.</div>
              </div>
              <div class="ecopoint-kpi-card">
                <div class="ecopoint-kpi-label"><i class="fa-solid fa-clock" style="margin-right:5px; opacity:0.7;"></i>Points Expiry Countdown</div>
                <div class="ecopoint-kpi-value"><?php echo htmlspecialchars($ecoPointExpiryCountdownLabel); ?></div>
                <div class="ecopoint-kpi-subtext"><?php echo htmlspecialchars($ecoPointExpiryCountdownSubtext); ?></div>
              </div>
            </div>
            <!-- Live session panel: shows real-time weight/points when using VHEcoPoint station -->
            <div class="ecopoint-card ecopoint-live-panel" id="ecopoint-live-panel" style="display:block; margin-top:14px;">
              <div class="ecopoint-live-card-title">Live Station Session</div>
              <div class="ecopoint-live-header">
                <div class="ecopoint-live-header-text">
                  <div class="ecopoint-live-status-badge" id="ecopoint-live-status">No Active Session</div>
                  <div class="ecopoint-live-station-name" id="ecopoint-live-station-name">VHEcoPoint Station</div>
                </div>
                <div class="ecopoint-live-indicator" aria-hidden="true"></div>
              </div>
              <div class="ecopoint-live-message" id="ecopoint-live-message" tabindex="0" aria-describedby="qrHelpTooltip">Scan your VictorianPass QR at the VHEcoPoint Station to begin.</div>
              <div class="ecopoint-live-meta" id="ecopoint-live-meta">
                <div class="ecopoint-live-metric">
                  <span class="ecopoint-live-metric-label">Session start time</span>
                  <strong id="ecopoint-live-start-time">—</strong>
                </div>
                <div class="ecopoint-live-metric">
                  <span class="ecopoint-live-metric-label">Current material</span>
                  <strong id="ecopoint-live-material">—</strong>
                </div>
                <div class="ecopoint-live-metric">
                  <span class="ecopoint-live-metric-label">Current weight</span>
                  <strong id="ecopoint-live-weight">0.00 kg</strong>
                </div>
                <div class="ecopoint-live-metric">
                  <span class="ecopoint-live-metric-label">Current points</span>
                  <strong id="ecopoint-live-points">0 pts</strong>
                </div>
              </div>
            </div>

            <div class="ecopoint-card">
              <h3 class="ecopoint-card-title">Weekly Material Cap Tracker</h3>
              <div class="ecopoint-card-note">Weekly contributions count toward the current 250-point program cap. Material rows show your contribution by recyclables logged this week.</div>
              <div style="display:flex;justify-content:space-between;align-items:flex-end;margin:10px 0 6px 0;">
                <div style="font-weight:700;color:#374151;font-size:13px;">Total Weekly Progress</div>
                <div style="font-weight:800;color:#14532d;font-size:15px;"><?php echo number_format($ecoPointWeeklyPoints); ?> / <?php echo number_format($ecoPointWeeklyCap); ?> pts &middot; <?php echo intval($ecoPointWeeklyProgress); ?>%</div>
              </div>
              <div class="ecopoint-progress" style="margin-bottom:14px;">
                <div class="ecopoint-progress-bar" style="width:<?php echo intval($ecoPointWeeklyProgress); ?>%;"></div>
              </div>
              <!-- Download Station QR removed per request -->
              <div class="ecopoint-material-list">
                <?php foreach ($ecoPointWeeklyStats as $materialLabel => $materialStat): ?>
                  <?php $materialProgress = $ecoPointWeeklyCap > 0 ? min(100, round(($materialStat['points'] / $ecoPointWeeklyCap) * 100)) : 0; ?>
                  <div class="ecopoint-material-row">
                    <div class="ecopoint-material-main">
                      <div class="ecopoint-material-title"><?php echo htmlspecialchars($materialLabel); ?></div>
                      <div class="ecopoint-material-subtitle"><?php echo number_format($materialStat['weight'], 2); ?> kg recycled this week</div>
                      <div class="ecopoint-progress" style="margin-top:8px;">
                        <div class="ecopoint-progress-bar" style="width:<?php echo intval($materialProgress); ?>%;"></div>
                      </div>
                    </div>
                    <div class="ecopoint-material-value"><?php echo number_format($materialStat['points']); ?> pts</div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="ecopoint-card">
              <h3 class="ecopoint-card-title"><i class="fa-solid fa-receipt" style="margin-right:6px; font-size:0.85em; opacity:0.7;"></i>Points History</h3>
              <div class="ecopoint-card-note">Your VHEcoPoint earn and redeem transactions.</div>
              <?php if (empty($allPointHistory)): ?>
                <div class="ecopoint-empty">No VHEcoPoint activity yet.</div>
              <?php else: ?>
                <div style="display:flex; flex-direction:column;">
                  <?php
                  $currentDateGroup = '';
                  foreach ($allPointHistory as $hpIdx => $hp):
                    $dateGroup = $hp['date_label'];
                    if ($dateGroup !== $currentDateGroup):
                      $currentDateGroup = $dateGroup;
                  ?>
                    <div style="font-size:0.75rem; font-weight:700; color:#6b7280; text-transform:uppercase; letter-spacing:0.04em; padding:14px 0 6px 0; border-bottom:1px solid #f1f5f9;">
                      <?php echo htmlspecialchars($dateGroup); ?>
                    </div>
                  <?php endif; ?>
                    <div style="border-bottom:1px solid #f1f5f9;">
                      <div class="vp-tx-row" style="display:flex; align-items:center; gap:12px; padding:12px 0; cursor:pointer;" onclick="var d=document.getElementById('vp-detail-<?php echo $hpIdx; ?>'); d.style.display=d.style.display==='none'?'block':'none';">
                        <div style="width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; flex-shrink:0; <?php echo $hp['type'] === 'earn' ? 'background:#dcfce7; color:#166534;' : 'background:#fef2f2; color:#991b1b;'; ?>">
                          <i class="fa-solid <?php echo $hp['type'] === 'earn' ? 'fa-arrow-down' : 'fa-arrow-up'; ?>" style="font-size:0.8rem;"></i>
                        </div>
                        <div style="flex:1; min-width:0;">
                          <div style="font-size:0.82rem; font-weight:600; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                            <?php
                              if ($hp['type'] === 'earn') {
                                echo htmlspecialchars($hp['material_label']);
                                if ($hp['weight_kg'] > 0) echo ' - ' . number_format($hp['weight_kg'], 2) . ' kg';
                              } else {
                                echo htmlspecialchars($hp['description'] !== '' ? $hp['description'] : 'Points Redeemed');
                              }
                            ?>
                          </div>
                          <div style="font-size:0.7rem; color:#9ca3af; margin-top:1px;"><?php echo $hp['time_label']; ?></div>
                        </div>
                        <div style="font-size:0.9rem; font-weight:800; <?php echo $hp['type'] === 'earn' ? 'color:#166534;' : 'color:#991b1b;'; ?> white-space:nowrap;">
                          <?php echo $hp['type'] === 'earn' ? '+' : '-'; ?><?php echo number_format($hp['amount']); ?> pts
                        </div>
                        <div style="flex-shrink:0; width:18px; text-align:center; color:#9ca3af; font-size:0.65rem;">
                          <i class="fa-solid fa-chevron-down" id="vp-chevron-<?php echo $hpIdx; ?>"></i>
                        </div>
                      </div>
                      <div id="vp-detail-<?php echo $hpIdx; ?>" style="display:none; padding:0 0 14px 48px;">
                        <div style="background:#f9fafb; border-radius:10px; padding:14px 16px; font-size:0.78rem; color:#374151; line-height:1.7;">
                          <?php if ($hp['type'] === 'earn'): ?>
                            <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Type</span><span style="font-weight:600; color:#166534;">Points Earned</span></div>
                            <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Material</span><span style="font-weight:600;"><?php echo htmlspecialchars($hp['material_label']); ?></span></div>
                            <?php if ($hp['weight_kg'] > 0): ?>
                              <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Weight</span><span style="font-weight:600;"><?php echo number_format($hp['weight_kg'], 2); ?> kg</span></div>
                            <?php endif; ?>
                            <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Points Earned</span><span style="font-weight:800; color:#166534;">+<?php echo number_format($hp['amount']); ?> pts</span></div>
                          <?php else: ?>
                            <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Type</span><span style="font-weight:600; color:#991b1b;">Points Redeemed</span></div>
                            <?php
                              $desc = $hp['description'];
                              $amenityName = '';
                              $refCode = '';
                              if (preg_match('/for\s+(.+?)(?:\s+\(Ref:.*)?$/i', $desc, $m)) {
                                $amenityName = trim($m[1]);
                              }
                              if (preg_match('/\(Ref:\s*([^)]+)\)/', $desc, $m2)) {
                                $refCode = trim($m2[1]);
                              }
                              if ($amenityName !== ''): ?>
                                <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Amenity</span><span style="font-weight:600;"><?php echo htmlspecialchars($amenityName); ?></span></div>
                              <?php endif; ?>
                              <?php if ($refCode !== ''): ?>
                                <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Reference</span><span style="font-weight:600; font-family:monospace; font-size:0.72rem;"><?php echo htmlspecialchars($refCode); ?></span></div>
                              <?php endif; ?>
                            <div style="display:flex; justify-content:space-between;"><span style="color:#6b7280;">Points Used</span><span style="font-weight:800; color:#991b1b;">-<?php echo number_format($hp['amount']); ?> pts</span></div>
                          <?php endif; ?>
                          <div style="display:flex; justify-content:space-between; margin-top:4px; padding-top:8px; border-top:1px solid #e5e7eb;"><span style="color:#6b7280;">Date & Time</span><span style="font-weight:600;"><?php echo htmlspecialchars($hp['date_label'] . ' at ' . $hp['time_label']); ?></span></div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="panel-section" id="panel-history" style="<?php echo $activeSection === 'panel-history' ? '' : 'display:none;'; ?>">
          <div class="activity-list-header">
            <div>History</div>
          </div>

          <div class="item-list">
            <?php if (empty($historyActivities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No history records found.</div>
            <?php else: ?>
              <?php foreach ($historyActivities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  if (strpos($s, 'permission')!==false || strpos($s, 'granted')!==false || (!empty($act['scanned_at']) && strpos($s, 'moved_to_history')!==false)) $statusClass = 'status-access-granted';
                  elseif (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-approved';
                  elseif (strpos($s, 'deleted')!==false || strpos($s, 'denied')!==false || strpos($s, 'reject')!==false || strpos($s, 'moved_to_history')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  elseif (strpos($s, 'expired')!==false) $statusClass = 'status-denied';
                  $displayStatus = ucwords(str_replace('_',' ', (string)$act['status']));
                  if (strpos($s, 'permission_granted') !== false) $displayStatus = 'Access Granted';
                  if (strpos($s, 'moved_to_history') !== false) $displayStatus = !empty($act['scanned_at']) ? 'Access Granted' : 'Denied';
                  if (strpos($s, 'deleted') !== false) $displayStatus = 'Deleted';
                  if ($s === 'new') $displayStatus = 'Filed';
                  $att = isset($act['attempts']) ? intval($act['attempts']) : 0;
                  $pay = strtolower((string)($act['payment_status'] ?? ''));
                  if ($pay === 'rejected' && $att >= 3) {
                    $displayStatus = 'Denied – Max Attempts Reached';
                    $statusClass = 'status-denied';
                  } elseif (stripos($displayStatus, 'denied') !== false) {
                    $statusClass = 'status-denied';
                  }
                  $isReservation = (($act['type'] ?? '') === 'reservation');
                  $detailsText = (string)($act['details'] ?? '');
                  $reasonText = '';
                  $scheduleText = $detailsText;
                  if ($isReservation && strpos($detailsText, 'Reason:') !== false) {
                    $reasonText = trim(substr($detailsText, strpos($detailsText, 'Reason:')));
                    $scheduleText = trim(substr($detailsText, 0, strpos($detailsText, 'Reason:')));
                  }
                  $displayTitle = (string)($act['title'] ?? '');
                  if ($isReservation) {
                    $rawTitle = $displayTitle;
                    $prefix = 'Reservation Schedule - ';
                    if (stripos($rawTitle, $prefix) === 0) {
                      $rest = substr($rawTitle, strlen($prefix));
                      $parts = explode(' - ', $rest);
                      $displayTitle = trim($parts[0] ?? '');
                    }
                    if ($displayTitle === '') { $displayTitle = 'Amenity'; }
                    $displayTitle = 'Reservation – ' . $displayTitle;
                  }
                  $createdText = date('m/d/y g:i A', strtotime($act['date']));
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-reserved-by="<?php echo htmlspecialchars($act['reserved_by'] ?? ''); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>" data-start-date="<?php echo htmlspecialchars($act['start_date_raw'] ?? ''); ?>" data-end-date="<?php echo htmlspecialchars($act['end_date_raw'] ?? ''); ?>" data-start-time="<?php echo htmlspecialchars($act['start_time_raw'] ?? ''); ?>" data-end-time="<?php echo htmlspecialchars($act['end_time_raw'] ?? ''); ?>" data-schedule="<?php echo htmlspecialchars($scheduleText); ?>" data-reason="<?php echo htmlspecialchars($reasonText); ?>" data-attempts="<?php echo isset($act['attempts']) ? intval($act['attempts']) : 0; ?>" data-scanned-at="<?php echo htmlspecialchars($act['scanned_at'] ?? ''); ?>"<?php if (($act['type'] ?? '') === 'report') { echo ' data-report-id="' . htmlspecialchars($act['report_id'] ?? '') . '"'; echo ' data-report-subject="' . htmlspecialchars($act['subject'] ?? '') . '"'; echo ' data-report-address="' . htmlspecialchars($act['address'] ?? '') . '"'; echo ' data-report-date="' . htmlspecialchars($act['report_date'] ?? '') . '"'; echo ' data-report-nature="' . htmlspecialchars($act['nature'] ?? '') . '"'; echo ' data-report-other="' . htmlspecialchars($act['other_concern'] ?? '') . '"'; } ?><?php if (($act['type'] ?? '') === 'guest_form') { echo ' data-guest-name="' . htmlspecialchars($act['guest_name'] ?? '') . '"'; } ?>>
                 <div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>
                 <div class="item-content">
                   <div class="item-row" style="display:flex; justify-content:space-between; margin-bottom:5px;">
                     <div class="item-left">
                       <span class="status-badge <?php echo $statusClass; ?>"><?php echo $displayStatus; ?></span>
                       <?php if ($isReservation): ?>
                       <span class="item-amenity"><?php echo htmlspecialchars($displayTitle); ?></span>
                       <?php else: ?>
                       <span class="item-title"><?php echo htmlspecialchars($displayTitle); ?></span>
                       <?php endif; ?>
                       <?php if ($isReservation): ?>
                         <span class="item-details" style="display:none;"></span>
                       <?php else: ?>
                         <?php if(!empty($act['details'])): ?>
                         <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                         <?php else: ?>
                         <span class="item-details" style="display:none;"></span>
                         <?php endif; ?>
                       <?php endif; ?>
                     </div>
                     <div class="item-created"><?php echo htmlspecialchars($createdText); ?></div>
                   </div>
                 <?php if ((($act['type'] ?? '') !== 'guest_form') && (($act['type'] ?? '') !== 'report')): ?>
                  <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                    <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                  </div>
                  <?php endif; ?>
                  <?php if (($act['type'] ?? '') === 'reservation' && !empty($act['reserved_by'])): ?>
                  <div style="font-size:0.8rem; color:#6b7280; margin-left: 48px;" class="item-reserved-by">
                    <?php echo htmlspecialchars($act['reserved_by']); ?>
                  </div>
                  <?php endif; ?>
                   <div class="item-extra" data-loaded="0"></div>
                 </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel-section" id="panel-guest-form" style="<?php echo $activeSection === 'panel-guest-form' ? '' : 'display:none;'; ?>">
          <div class="activity-list-header">
            <div>Guest Form</div>
          </div>
          <div style="max-width:720px;margin:0 auto;">
            <form class="entry-form" id="entryForm" enctype="multipart/form-data">
              <div class="booking-steps" aria-label="Guest form steps">
                <div class="booking-steps-header">
                  <div class="booking-steps-label">Guest form steps</div>
                  <button type="button" class="booking-steps-toggle" id="bookingStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
                </div>
                <div class="booking-steps-body">
                  <div class="booking-step is-active" id="step-resident">
                    <div class="step-index">1</div>
                    <div class="step-content">
                      <div class="step-title">Resident information</div>
                      <div class="step-subtitle">Confirm your name, house/unit, and contact details</div>
                    </div>
                  </div>
                  <div class="booking-step" id="step-guest">
                    <div class="step-index">2</div>
                    <div class="step-content">
                      <div class="step-title">Guest information</div>
                      <div class="step-subtitle">Enter your guest’s personal and contact details</div>
                    </div>
                  </div>
                  <div class="booking-step" id="step-upload">
                    <div class="step-index">3</div>
                    <div class="step-content">
                      <div class="step-title">Upload ID &amp; save</div>
                      <div class="step-subtitle">Add a valid ID and save the guest to your list</div>
                    </div>
                  </div>
                </div>
              </div>
              <div class="form-header">
                <img src="images/mainpage/ticket.svg" alt="Entry Icon">
                <span>Add Guest</span>
              </div>

              <h4 style="margin:10px 0 5px;color:#111827;">Resident Information</h4>
              <div class="form-row">
                <input type="text" id="resident_full_name" name="resident_full_name" placeholder="Resident Full Name*" value="<?php echo htmlspecialchars($fullName); ?>" required readonly>
                <input type="text" id="resident_house" name="resident_house" placeholder="House/Unit No.*" value="<?php echo htmlspecialchars($houseNumber); ?>" required readonly>
              </div>
              <div class="form-row">
                <div style="flex:1;">
                  <input type="email" id="resident_email" name="resident_email" placeholder="Resident Email*" value="<?php echo htmlspecialchars($email); ?>" required readonly>
                </div>
                <div style="flex:1;">
                  <input type="tel" id="resident_contact" name="resident_contact" placeholder="Resident Phone Number*" value="<?php echo htmlspecialchars($phoneNormalized); ?>" required readonly>
                </div>
              </div>

              <h4 style="margin:20px 0 5px;color:#111827;">Guest Information</h4>
              <div class="form-row">
                <input type="text" id="visitor_first_name" name="visitor_first_name" placeholder="Guest First Name*" required>
                <input type="text" id="visitor_last_name" name="visitor_last_name" placeholder="Guest Last Name*" required>
              </div>
              <div class="form-row">
                <select id="visitor_sex" name="visitor_sex" required>
                  <option value="" disabled selected>Sex*</option>
                  <option>Male</option>
                  <option>Female</option>
                </select>
                <div class="form-group">
                  <input type="date" id="birthdate" name="visitor_birthdate" placeholder=" " required>
                  <label for="birthdate">Birthdate*</label>
                </div>
              </div>
              <div class="input-wrap">
                <input type="tel" id="visitor_contact" name="visitor_contact" placeholder="Guest Phone Number*" required>
              </div>
              <div class="form-group">
                <input type="email" id="visitor_email" name="visitor_email" placeholder="Guest Email">
              </div>
              <div class="input-wrap">
                <input type="text" id="visitor_address" name="visitor_address" placeholder="Guest Address (e.g., Blk 00 Lot 00)*" required>
              </div>

              <label class="upload-box">
                <input type="file" id="visitor_valid_id" name="visitor_valid_id" accept="image/*" hidden required>
                <img src="images/mainpage/upload.svg" alt="Upload">
                <p>Upload Guest’s Valid ID*<br><small>(e.g. National ID, Driver’s License)</small><br><small>Max 5MB</small></p>
              </label>
              <div class="privacy-note" style="background:#f9fafb;border:1px solid #e5e7eb;color:#374151;padding:10px 12px;border-radius:8px;margin:10px 0;font-size:0.92rem;line-height:1.35;">
                Data Privacy Notice: The guest’s ID is used only for verification and stored securely. Access is limited to authorized staff, following the Data Privacy Act of 2012.
              </div>

              <div id="idPreviewWrap" style="display:none;margin:8px 0 14px;">
                <img id="idPreview" alt="Valid ID Preview" style="max-width:240px;border-radius:10px;border:1px solid #e6ebe6;display:block;">
                <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap;">
                  <button type="button" id="btnClearId" class="btn-next" style="background:#e6ebe6;color:#23412e;">Remove Selected ID</button>
                </div>
              </div>

              <div class="form-actions">
                <a href="#" class="btn-back" id="guestFormBackBtn"><i class="fa-solid fa-arrow-left"></i> Back</a>
                <button type="submit" class="btn-next" id="submitBtn">Save Guest</button>
              </div>
            </form>
          </div>
        </div>

        <div class="panel-section" id="panel-my-guests" style="<?php echo $activeSection === 'panel-my-guests' ? '' : 'display:none;'; ?>">
          <div class="activity-list-header">
            <div>My Guests</div>
          </div>
          <div id="guestListSection" style="margin-top:8px;background:#ffffff;border-radius:16px;padding:20px 22px;box-shadow:0 4px 16px rgba(15,23,42,0.08);border:1px solid #e5e7eb;max-width:860px;width:100%;margin-left:auto;margin-right:auto;">
            <h4 style="margin:0 0 10px;color:#111827;">My Saved Guests</h4>
            <?php if (empty($guestRows)): ?>
              <p style="margin:4px 0 0;font-size:0.95rem;color:#555;">You have not added any guests yet. Use the Guest Form to add a guest.</p>
            <?php else: ?>
              <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                  <thead>
                    <tr style="background:#f5f7f5;color:#333;">
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Name</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Contact</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Email</th>
                      <th style="text-align:left;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Added</th>
                      <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Entry Pass</th>
                      <th style="text-align:center;padding:8px 10px;border-bottom:1px solid #e2e6e2;">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($guestRows as $g): ?>
                      <?php
                        $nameParts = [];
                        if (!empty($g['visitor_first_name'])) { $nameParts[] = $g['visitor_first_name']; }
                        if (!empty($g['visitor_middle_name'])) { $nameParts[] = $g['visitor_middle_name']; }
                        if (!empty($g['visitor_last_name'])) { $nameParts[] = $g['visitor_last_name']; }
                        $guestName = trim(implode(' ', $nameParts));
                        if ($guestName === '') { $guestName = 'Guest'; }
                        $contact = $g['visitor_contact'] ?? '';
                        $emailG = $g['visitor_email'] ?? '';
                        $created = $g['created_at'] ?? '';
                        $createdLabel = $created ? date('m/d/y', strtotime($created)) : '';
                        $refCode = $g['ref_code'] ?? '';
                      ?>
                      <tr>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;font-weight:600;"><?php echo htmlspecialchars($guestName); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($contact); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;"><?php echo htmlspecialchars($emailG); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;color:#777;"><?php echo htmlspecialchars($createdLabel); ?></td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;text-align:center;">
                          <button type="button" class="btn-view-pass" 
                                  data-ref="<?php echo htmlspecialchars($refCode); ?>"
                                  data-name="<?php echo htmlspecialchars($guestName); ?>"
                                  data-resident="<?php echo htmlspecialchars($fullName); ?>"
                                  style="padding:6px 12px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;">
                            View Pass
                          </button>
                        </td>
                        <td style="padding:7px 10px;border-bottom:1px solid #e9ece9;text-align:center;">
                          <button type="button" class="btn-delete-guest"
                                  data-ref="<?php echo htmlspecialchars($refCode); ?>"
                                  data-name="<?php echo htmlspecialchars($guestName); ?>"
                                  style="padding:6px 12px;background:#ef4444;color:#fff;border:none;border-radius:6px;cursor:pointer;font-size:0.85rem;">
                            Delete
                          </button>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>



      </div>
    </div>
  </main>
  <div id="cancelModal" class="cancel-modal" style="display:none;">
    <div class="cancel-modal-content">
      <div class="cancel-modal-header">
        <h3>Cancel Reservation</h3>
        <button type="button" class="cancel-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="cancel-modal-body">
        <p>Are you sure you want to cancel this reservation?</p>
        <p class="cancel-modal-note">Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.</p>
      </div>
      <div class="cancel-modal-actions">
        <button type="button" class="cancel-modal-keep">Keep Reservation</button>
        <button type="button" class="cancel-modal-confirm">Confirm Cancel</button>
      </div>
    </div>
  </div>
  <div id="moveHistoryModal" class="cancel-modal" style="display:none;">
    <div class="cancel-modal-content">
      <div class="cancel-modal-header">
        <h3>Move to History</h3>
        <button type="button" class="cancel-modal-close" aria-label="Close">&times;</button>
      </div>
      <div class="cancel-modal-body">
        <p>Are you sure you want to move this request to history?</p>
        <p class="cancel-modal-note" style="display:none;"></p>
      </div>
      <div class="cancel-modal-actions">
        <button type="button" class="cancel-modal-keep">Keep in My Requests</button>
        <button type="button" class="cancel-modal-confirm">Move to History</button>
      </div>
    </div>
  </div>
  <div id="updateProofModal" class="update-proof-modal">
    <div class="update-proof-content">
      <button type="button" class="update-proof-close" aria-label="Close">&times;</button>
      <h3>Upload the Updated Proof Here</h3>
      <input type="file" id="updateProofFile" class="update-proof-file" accept="image/*,application/pdf">
      <div class="update-proof-hint">Max 5MB (JPG, PNG, PDF)</div>
      <div id="updateProofFileName" class="update-proof-file-name">No file selected</div>
      <div id="updateProofPreview" class="update-proof-preview" style="display:none; margin-top:10px;"></div>
      <div class="update-proof-actions">
        <button type="button" id="updateProofEditBtn" class="update-proof-btn update-proof-edit">Edit</button>
        <button type="button" id="updateProofRemoveBtn" class="update-proof-btn update-proof-remove" disabled>Remove</button>
        <button type="button" id="updateProofSubmitBtn" class="update-proof-btn update-proof-submit" disabled>Submit</button>
      </div>
    </div>
  </div>
  <div id="refModal" class="modal">
    <div class="modal-content">
      <h2>Request Submitted!</h2>
      <p>Your guest has been successfully saved to your account.</p>
      <p><small><em>You can view and manage all guests from your resident dashboard.</em></small></p>
      <div style="display:flex;gap:10px;justify-content:center;margin-top:10px;flex-wrap:wrap;">
        <button type="button" class="btn-confirm" id="refModalCloseBtn">Back to My Requests</button>
      </div>
    </div>
  </div>
  <div id="verifyModal" class="modal">
    <div class="modal-content">
      <h2>Confirm Guest Request</h2>
      <div id="verifySummary" style="text-align:left;margin-top:10px"></div>
      <div style="text-align:center;margin-top:12px;display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
        <button type="button" class="btn-cancel" id="verifyCancelBtn">Cancel</button>
        <button type="button" class="btn-confirm" id="verifyConfirmBtn">Confirm</button>
      </div>
    </div>
  </div>

  <div id="activityModal" class="modal">
    <div class="modal-content">
      <span class="close">&times;</span>
      <div id="activityModalBody"></div>
    </div>
  </div>
  
  <div id="guestPassModal" class="modal">
    <div class="modal-content">
      <span class="close" id="closeGuestPassModal">&times;</span>
      <h3>Guest Entry Pass</h3>
      <div id="guestPassContent" class="guest-pass-content"></div>
    </div>
  </div>
  <div id="qrWarningModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; z-index:3500;">
    <div style="background:#fff; border-radius:12px; padding:22px 20px; width:360px; max-width:92vw; box-shadow:0 12px 30px rgba(0,0,0,0.25); text-align:center;">
      <div id="qrWarningTitle" style="font-weight:700; color:#23412e; font-size:1.05rem; margin-bottom:8px;">Warning</div>
      <div id="qrWarningMessage" style="font-size:0.9rem; color:#444; line-height:1.5;">Do not scan. Authorized guards only.</div>
      <div style="display:flex; gap:10px; justify-content:center; margin-top:16px;">
        <button type="button" id="qrWarningCancel" style="background:#e5e7eb; color:#111827; border:none; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Cancel</button>
        <button type="button" id="qrWarningProceed" style="background:#23412e; color:#fff; border:none; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Proceed</button>
      </div>
    </div>
  </div>

  <!-- Hidden Template for Amenity Pass Download -->
  <div id="amenityPassTemplate" style="position:fixed; left:-9999px; top:0; z-index:-9999; width:400px; background:#fff; padding:20px; box-sizing:border-box; font-family:'Poppins',sans-serif;">
      <div style="border: 2px solid #23412e; padding: 20px; border-radius: 12px; background: #f9f9f9; text-align: center;">
        <div style="margin-bottom: 15px; font-weight: 700; color: #23412e; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 1.1rem;">
           <img src="images/logo.svg" alt="Logo" style="width: 32px; height: 32px; margin: 0;">
           <span>Victorian Pass</span>
        </div>
        <div style="background:#fff; padding:10px; border:1px solid #ddd; display:inline-block; border-radius:0;">
            <div id="amenityPassQR" style="width: 200px; height: 200px; display: flex; justify-content: center; align-items: center; margin: 0 auto; overflow: hidden;"></div>
            <style>#amenityPassQR img, #amenityPassQR canvas { width: 100% !important; height: 100% !important; object-fit: contain; }</style>
        </div>
        <div id="amenityPassWarning" style="color: #d9534f; font-weight: 600; margin: 15px auto 5px auto; font-size: 0.85rem; line-height: 1.5; border: 1px dashed #d9534f; padding: 10px; border-radius: 8px; background: #fff5f5;">
            Do not scan. One-time use only. Once scanned, the QR code is permanently disabled. Authorized guards only.
        </div>
      </div>
  </div>
</div>
<script>
(function(){
  var accountBlockedShown = false;
  window.showAccountBlockedModal = function() {
    if (accountBlockedShown) return;
    var modal = document.getElementById('accountBlockedModal');
    if (!modal) return;
    modal.style.display = 'flex';
    document.body.classList.add('account-blocked');
    accountBlockedShown = true;
  };
  // Guest Pass Modal Logic
  var guestPassModal = document.getElementById('guestPassModal');
  var closeGuestPassBtn = document.getElementById('closeGuestPassModal');
  if(closeGuestPassBtn) {
      closeGuestPassBtn.onclick = function() { if(guestPassModal) guestPassModal.style.display = "none"; }
  }
  window.addEventListener('click', function(e) {
      if (guestPassModal && e.target == guestPassModal) {
          guestPassModal.style.display = "none";
      }
  });
  (function(){
    var modal = document.getElementById('qrWarningModal');
    var cancelBtn = document.getElementById('qrWarningCancel');
    var proceedBtn = document.getElementById('qrWarningProceed');
    function close(){ if(modal) modal.style.display='none'; }
    if(cancelBtn) cancelBtn.onclick = close;
    if(proceedBtn) proceedBtn.onclick = function(){
      close();
      if(typeof window.qrWarningConfirm === 'function'){
        var cb = window.qrWarningConfirm;
        window.qrWarningConfirm = null;
        cb();
      }
    };
    if(modal) modal.addEventListener('click', function(e){ if(e.target === modal) close(); });
    var titleEl = document.getElementById('qrWarningTitle');
    var msgEl = document.getElementById('qrWarningMessage');
    var defaultTitle = titleEl ? titleEl.textContent : '';
    var defaultMsg = msgEl ? msgEl.textContent : '';
    var defaultCancel = cancelBtn ? cancelBtn.textContent : '';
    var defaultProceed = proceedBtn ? proceedBtn.textContent : '';
    window.openQRWarning = function(cb, message){
      window.qrWarningConfirm = typeof cb === 'function' ? cb : null;
      var opts = message && typeof message === 'object' ? message : { message: message };
      if(titleEl) titleEl.textContent = opts.title || defaultTitle;
      if(msgEl) msgEl.textContent = opts.message || defaultMsg;
      if(cancelBtn) cancelBtn.textContent = opts.cancelText || defaultCancel;
      if(proceedBtn) proceedBtn.textContent = opts.proceedText || defaultProceed;
      if(modal) modal.style.display = 'flex';
    };
  })();
  function escapeText(t){
    return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function downloadGuestPassQR(qrSrc, filename){
    function doDownload(){
      var link = document.createElement('a');
      link.href = qrSrc;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
    }
    if(typeof window.openQRWarning === 'function'){
      window.openQRWarning(doDownload, 'Do not scan. Authorized guards only.');
    } else {
      doDownload();
    }
  }
  function openGuestPassModal(ref, name, resident) {
      if(!guestPassModal) return;
      var basePath = window.location.pathname.replace(/\/[^\/]*$/, '');
      var statusLink = location.origin + basePath + '/qr_view.php?code=' + encodeURIComponent(ref);
      var qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' + encodeURIComponent(statusLink);
      
      var html = '';
      html += '<div class="guest-pass-header">';
      html += '<div class="guest-pass-name">' + escapeText(name) + '</div>';
      html += '<div class="guest-pass-sub">Referred by Resident: ' + escapeText(resident) + '</div>';
      html += '</div>';
      html += '<div class="guest-pass-note">Present this QR code at the gate for entry.</div>';
      html += '<div class="guest-pass-actions">';
      html += '<button type="button" class="btn-confirm guest-pass-download" data-qr="' + qrSrc + '" data-fn="GuestPass_' + escapeText(ref) + '.png">Download QR</button>';
      html += '</div>';
      
      var contentEl = document.getElementById('guestPassContent');
      if(contentEl) contentEl.innerHTML = html;
      var downloadBtn = contentEl ? contentEl.querySelector('.guest-pass-download') : null;
      if(downloadBtn){
        downloadBtn.onclick = function(){
          var src = this.getAttribute('data-qr') || '';
          var fn = this.getAttribute('data-fn') || 'GuestPass.png';
          downloadGuestPassQR(src, fn);
        };
      }
      guestPassModal.style.display = "block";
  }
  // QR Choice / View handlers
  function openQRChoice(){
    closeQRView();
    var modal = document.getElementById('qrChoiceModal');
    if(!modal) return;
    var sb=document.querySelector('.sidebar');
    var ov=document.getElementById('sidebarOverlay');
    if(sb) sb.classList.remove('open');
    if(ov) ov.classList.remove('show');
    modal.style.display = 'flex';
  }
  function closeQRChoice(){ var m=document.getElementById('qrChoiceModal'); if(m) m.style.display='none'; }
  function openQRView(){
    closeQRChoice();
    var view = document.getElementById('qrViewModal');
    var container = document.getElementById('qrViewCardContainer');
    if(!view || !container) return;
    container.innerHTML = '';
    var card = document.getElementById('residentCard');
    if(card){
      var clone = card.cloneNode(true);
      clone.removeAttribute('id');
    clone.style.width = '100%';
    clone.style.maxWidth = 'min(360px, 100%)';
    clone.style.display = 'block';
    clone.style.margin = '0 auto';
      container.appendChild(clone);
    }
    view.style.display = 'flex';
  }
  function closeQRView(){ var m=document.getElementById('qrViewModal'); if(m) m.style.display='none'; }
  document.addEventListener('DOMContentLoaded', function() {
    var qrButton = document.querySelector('.sidebar-footer .download-qr-btn[title="My QR"]');
    var qrTooltip = document.getElementById('qrHelpTooltip');
    var ecopointNav = document.querySelector('.nav-menu .nav-item[data-section="panel-points-history"]');

    function setQRHighlight(active) {
      if (!qrButton) return;
      qrButton.classList.toggle('qr-highlight', !!active);
      qrButton.setAttribute('aria-pressed', active ? 'true' : 'false');
      if (qrTooltip) {
        qrTooltip.classList.toggle('visible', !!active);
        qrTooltip.setAttribute('aria-hidden', active ? 'false' : 'true');
      }
    }

    function positionTooltip(anchor) {
      if (!qrButton || !qrTooltip || !qrTooltip.classList.contains('visible')) return;
      var el = anchor || qrButton;
      var rect = el.getBoundingClientRect();
      if (rect.right < 0 || rect.left > window.innerWidth || rect.width === 0) {
        setQRHighlight(false);
        return;
      }
      var tooltipWidth = qrTooltip.offsetWidth || 220;
      var left = rect.right + 16;
      var top = rect.top + (rect.height / 2);
      var maxLeft = window.innerWidth - tooltipWidth - 12;
      left = Math.min(Math.max(left, 12), maxLeft);
      qrTooltip.style.left = left + 'px';
      qrTooltip.style.top = top + 'px';
    }

    function showQRHelp(anchor) {
      setQRHighlight(true);
      positionTooltip(anchor);
    }

    if (qrButton) {
      qrButton.addEventListener('click', function(e) {
        e.preventDefault();
        openQRChoice();
      });
      qrButton.addEventListener('mouseenter', function() {
        showQRHelp(qrButton);
      });
      qrButton.addEventListener('mouseleave', function() {
        setQRHighlight(false);
      });
      qrButton.addEventListener('focus', function() {
        showQRHelp(qrButton);
      });
      qrButton.addEventListener('blur', function() {
        setQRHighlight(false);
      });
    }

    if (ecopointNav) {
      ecopointNav.addEventListener('click', function() {
        showQRHelp(qrButton);
      });
      document.querySelectorAll('.nav-menu .nav-item[data-section]').forEach(function(item) {
        if (item !== ecopointNav) {
          item.addEventListener('click', function() { setQRHighlight(false); });
        }
      });
    }

    if (ecopointNav && ecopointNav.classList.contains('active')) {
      showQRHelp(qrButton);
    }

    window.addEventListener('resize', function() {
      if (qrTooltip && qrTooltip.classList.contains('visible')) {
        positionTooltip();
      }
    });
  });
  // Wire buttons
  document.addEventListener('click', function(e){
    if(e.target && e.target.id === 'qrChoiceClose') closeQRChoice();
    if(e.target && e.target.id === 'qrViewClose') closeQRView();
    if(e.target && e.target.id === 'qrViewBtn'){ openQRView(); }
    if(e.target && e.target.id === 'qrDownloadBtn'){ closeQRChoice(); downloadPersonalQR(); }
    if(e.target && e.target.id === 'qrViewDownloadBtn'){ closeQRView(); downloadPersonalQR(); }
  });
  // Close modals when clicking outside
  window.addEventListener('click', function(e){
    var qc = document.getElementById('qrChoiceModal'); if(qc && e.target === qc) qc.style.display='none';
    var qv = document.getElementById('qrViewModal'); if(qv && e.target === qv) qv.style.display='none';
  });
  // Event Delegation for View Pass buttons
  document.addEventListener('click', function(e){
      if(e.target.classList.contains('btn-view-pass')){
          var ref = e.target.getAttribute('data-ref');
          var name = e.target.getAttribute('data-name');
          var resident = e.target.getAttribute('data-resident');
          openGuestPassModal(ref, name, resident);
      }
      if(e.target.classList.contains('btn-delete-guest')){
          var refDel = e.target.getAttribute('data-ref');
          var nameDel = e.target.getAttribute('data-name') || '';
          var row = e.target.closest('tr');
          if(refDel && row){
            openDeleteGuestModal(row, refDel, nameDel);
          }
      }
  });

  var searchInput=document.getElementById('requestSearch');
    function filterList(){
    var q=(searchInput.value||'').trim().toLowerCase();
    var panel = document.getElementById('panel-requests');
    if(panel) {
        panel.querySelectorAll('.item-list .list-item').forEach(function(li){
            var text=li.textContent.toLowerCase();
            li.style.display=text.indexOf(q)!==-1?'':'none';
        });
    }
  }
  if(searchInput){ searchInput.addEventListener('input',filterList); }
  var prevStatuses={};
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    var code=li.getAttribute('data-ref-code')||'';
    var st=li.getAttribute('data-status')||'';
    if(code) prevStatuses[code]=st;
  });
  function statusClassFor(s){
    s=(s||'').toLowerCase();
    if(s.indexOf('permission')!==-1 || s.indexOf('granted')!==-1) return 'status-access-granted';
    if(s.indexOf('approv')!==-1) return 'status-approved';
    if(s.indexOf('resolved')!==-1||s.indexOf('ongoing')!==-1) return 'status-ongoing';
    if(s.indexOf('deleted')!==-1||s.indexOf('denied')!==-1||s.indexOf('reject')!==-1||s.indexOf('moved_to_history')!==-1) return 'status-denied';
    if(s.indexOf('cancel')!==-1) return 'status-cancelled';
    if(s.indexOf('expired')!==-1) return 'status-denied';
    if(s.indexOf('complete')!==-1) return 'status-completed';
    return 'status-pending';
  }
  function fmtLabel(s){
    s=String(s||'').replace(/[_-]+/g,' ').toLowerCase();
    if(s.indexOf('access granted')!==-1 || s.indexOf('permission granted')!==-1) return 'Access Granted';
    if(s.indexOf('moved to history')!==-1) return 'Denied';
    if(s.indexOf('deleted')!==-1) return 'Deleted';
    if(s === 'new') return 'Filed';
    return s.replace(/\b\w/g,function(m){ return m.toUpperCase(); });
  }
  var notifCountEl=document.getElementById('notifCount');
  var notifBtn=document.getElementById('notifBtn');
  var notifPanel=document.getElementById('notifPanel');
  var notifItems=[];
  var notifKnownIds={};
  var notifBootstrapped=false;
  var notifPopup=document.getElementById('notifPopup');
  var notifPopupTimer=null;
  var cancelModal=document.getElementById('cancelModal');
  var cancelModalKeep=cancelModal?cancelModal.querySelector('.cancel-modal-keep'):null;
  var cancelModalConfirm=cancelModal?cancelModal.querySelector('.cancel-modal-confirm'):null;
  var cancelModalClose=cancelModal?cancelModal.querySelector('.cancel-modal-close'):null;
  var cancelModalRef=null;
  var cancelModalLi=null;
  var modalAction = 'cancel';
  var moveHistoryModal=document.getElementById('moveHistoryModal');
  var moveHistoryKeep=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-keep'):null;
  var moveHistoryConfirm=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-confirm'):null;
  var moveHistoryClose=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-close'):null;
  var moveHistoryRef=null;
  var moveHistoryLi=null;
  var updateProofModal=document.getElementById('updateProofModal');
  var updateProofClose=updateProofModal?updateProofModal.querySelector('.update-proof-close'):null;
  var updateProofFile=document.getElementById('updateProofFile');
  var updateProofFileName=document.getElementById('updateProofFileName');
  var updateProofEditBtn=document.getElementById('updateProofEditBtn');
  var updateProofRemoveBtn=document.getElementById('updateProofRemoveBtn');
  var updateProofSubmitBtn=document.getElementById('updateProofSubmitBtn');
  var updateProofPreview=document.getElementById('updateProofPreview');
  var updateProofActions=updateProofModal?updateProofModal.querySelector('.update-proof-actions'):null;
  var updateProofRef=null;
  var updateProofLi=null;

  function resetUpdateProofForm(){
    if(updateProofFile) updateProofFile.value='';
    if(updateProofFileName) updateProofFileName.textContent='No file selected';
    if(updateProofRemoveBtn) updateProofRemoveBtn.disabled=true;
    if(updateProofSubmitBtn) updateProofSubmitBtn.disabled=true;
    if(updateProofPreview){ updateProofPreview.innerHTML=''; updateProofPreview.style.display='none'; }
    if(updateProofActions) updateProofActions.classList.remove('is-visible');
  }

  function openUpdateProofModal(li, ref){
    if(!updateProofModal) return;
    updateProofLi=li;
    updateProofRef=ref;
    resetUpdateProofForm();
    updateProofModal.style.display='flex';
    requestAnimationFrame(function(){ updateProofModal.classList.add('is-open'); });
  }

  function closeUpdateProofModal(){
    if(!updateProofModal) return;
    updateProofModal.classList.remove('is-open');
    setTimeout(function(){
      if(updateProofModal) updateProofModal.style.display='none';
    }, 250);
    updateProofLi=null;
    updateProofRef=null;
    resetUpdateProofForm();
  }

  function openMoveHistoryModal(li, ref){
    if(!moveHistoryModal) return;
    moveHistoryLi=li;
    moveHistoryRef=ref;
    moveHistoryModal.style.display='flex';
  }

  function closeMoveHistoryModal(){
    if(!moveHistoryModal) return;
    moveHistoryModal.style.display='none';
    moveHistoryLi=null;
    moveHistoryRef=null;
  }

  function renderNotifPopup(items){
    if(!notifPopup) return;
    if(!items || !items.length){
      notifPopup.style.display='none';
      notifPopup.innerHTML='';
      return;
    }
    var html='';
    for(var i=0;i<items.length;i++){
      var it=items[i]||{};
      var title=String(it.title||'').replace(/[<>]/g,'');
      var message=formatNotifDisplay(it.message||'');
      var time=formatNotifDateTime(it.created_at||'');
      var t=String(it.type||'').toLowerCase();
      var subCls='notif-popup-sub'+(t==='error'?' notif-error':'');
      html+='<div class="notif-popup-item"><div class="notif-popup-title">'+title+'</div><div class="'+subCls+'">'+message+(time?' • '+time:'')+'</div></div>';
    }
    notifPopup.innerHTML=html;
    notifPopup.style.display='block';
    if(notifPopupTimer) clearTimeout(notifPopupTimer);
    notifPopupTimer=setTimeout(function(){
      if(notifPopup){ notifPopup.style.display='none'; }
    },5000);
  }

  function openCancelModal(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    modalAction = 'cancel';
    
    var type = (li.getAttribute('data-type')||'').toLowerCase();
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    
    if(type === 'report'){
      modalAction = 'cancel_report';
      if(h3) h3.textContent = 'Cancel Report';
      if(pBody) pBody.textContent = 'Are you sure you want to cancel this report?';
      if(pNote) pNote.style.display = 'none';
      if(btnKeep) btnKeep.textContent = 'Keep Report';
    } else if(type === 'guest_form'){
      if(h3) h3.textContent = 'Cancel Request';
      if(pBody) pBody.textContent = 'Are you sure you want to cancel this request?';
      if(pNote) pNote.style.display = 'none';
      if(btnKeep) btnKeep.textContent = 'Keep Request';
    } else {
      if(h3) h3.textContent = 'Cancel Reservation';
      if(pBody) pBody.textContent = 'Are you sure you want to cancel this reservation?';
      if(pNote) {
        pNote.style.display = 'block';
        pNote.textContent = 'Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.';
      }
      if(btnKeep) btnKeep.textContent = 'Keep Reservation';
    }

    cancelModal.style.display='flex';
  }

  function openDeleteModal(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    modalAction = 'delete';
    
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    
    if(h3) h3.textContent = 'Delete Request';
    if(pBody) pBody.textContent = 'Are you sure you want to permanently delete this request from your history?';
    if(pNote) pNote.style.display = 'none';
    if(btnKeep) btnKeep.textContent = 'Keep Request';
    if(btnConfirm) btnConfirm.textContent = 'Confirm Delete';
    
    cancelModal.style.display='flex';
  }

  function openDeleteGuestModal(row, ref, name){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=row;
    modalAction = 'delete_guest';
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    if(h3) h3.textContent = 'Delete Guest';
    if(pBody) pBody.textContent = 'Are you sure you want to remove this guest? The guest pass will be revoked.';
    if(pNote) pNote.style.display = 'none';
    if(btnKeep) btnKeep.textContent = 'Keep Guest';
    if(btnConfirm) btnConfirm.textContent = 'Confirm Delete';
    cancelModal.style.display='flex';
  }

  function closeCancelModalResident(){
    if(!cancelModal) return;
    cancelModal.style.display='none';
    cancelModalRef=null;
    cancelModalLi=null;
  }
  function performCancelResident(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalResident();
      return;
    }
    // Reset modal text for next time (or handle in openCancelModal)
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    if(btnConfirm) btnConfirm.textContent = 'Confirm Cancel';

    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'cancel',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to cancel reservation.');
        return;
      }
      try { localStorage.setItem('cancelled:'+ref, String(Date.now())); } catch(_){}
      li.setAttribute('data-status','cancelled');
      li.setAttribute('data-payment-status','cancelled');
      prevStatuses[ref]='cancelled';

      // Move to History Panel
      var activePanel = document.getElementById('panel-requests');
      var historyPanel = document.getElementById('panel-history');
      
      if(activePanel && historyPanel){
        var activeList = activePanel.querySelector('.item-list');
        var historyList = historyPanel.querySelector('.item-list');
        
        if(activeList && historyList){
          // Check if list is now empty and show "No active requests" message if needed
          var remainingActiveItems = activeList.querySelectorAll('.list-item');
          
          // Remove from active list completely
          li.remove();
          
          // If no more items in active list, show empty message
          if(remainingActiveItems.length === 1){ // Will be 0 after remove
            var emptyDiv = document.createElement('div');
            emptyDiv.style.cssText = 'padding:20px; text-align:center; color:#777;';
            emptyDiv.textContent = 'No active requests.';
            activeList.appendChild(emptyDiv);
          }
          
          // Remove "No history records" message if present
          var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
          if(noHistoryMsg) noHistoryMsg.remove();
          
          // Add to history list at top
          historyList.insertBefore(li, historyList.firstChild);
          li.style.display = '';
          li.classList.remove('expanded');
          
          // Switch to History tab
          var historyNav = document.querySelector('.nav-menu .nav-item[data-section="panel-history"]');
          if(historyNav){
            var navItems = document.querySelectorAll('.nav-menu .nav-item[data-section]');
            navItems.forEach(function(n){ n.classList.remove('active'); });
            historyNav.classList.add('active');
            
            var sections = document.querySelectorAll('.right-panel .panel-section');
            sections.forEach(function(s){ s.style.display = 'none'; });
            historyPanel.style.display = 'block';
          } else {
            var sections = document.querySelectorAll('.right-panel .panel-section');
            sections.forEach(function(s){ s.style.display = 'none'; });
            historyPanel.style.display = 'block';
          }
        }
      }

      // Update Title immediately
      var titleEl = li.querySelector('.item-title');
      if (titleEl && titleEl.textContent.indexOf('Cancelled') === -1) {
          titleEl.textContent += ' - Cancelled';
      }
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('cancelled');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
        badge.classList.add(statusClassFor('cancelled'));
      }
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }
      closeCancelModalResident();
    })["catch"](function(){
      // alert('Network error. Please try again.');
    });
  }
  function performCancelReport(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalResident();
      return;
    }
    var reportId=parseInt(li.getAttribute('data-report-id')||'0',10);
    if(!reportId){
      closeCancelModalResident();
      return;
    }
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    if(btnConfirm) btnConfirm.textContent = 'Confirm Cancel';

    fetch('submit_report.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'cancel',report_id:String(reportId)})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to cancel report.');
        return;
      }
      try { localStorage.setItem('cancelled:'+ref, String(Date.now())); } catch(_){}
      li.setAttribute('data-status','cancelled');
      prevStatuses[ref]='cancelled';

      var activePanel = document.getElementById('panel-requests');
      var historyPanel = document.getElementById('panel-history');
      if(activePanel && historyPanel){
        var activeList = activePanel.querySelector('.item-list');
        var historyList = historyPanel.querySelector('.item-list');
        if(activeList && historyList){
          var remainingActiveItems = activeList.querySelectorAll('.list-item');
          li.remove();
          if(remainingActiveItems.length === 1){
            var emptyDiv = document.createElement('div');
            emptyDiv.style.cssText = 'padding:20px; text-align:center; color:#777;';
            emptyDiv.textContent = 'No active requests.';
            activeList.appendChild(emptyDiv);
          }
          var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
          if(noHistoryMsg) noHistoryMsg.remove();
          historyList.insertBefore(li, historyList.firstChild);
          li.style.display = '';
          li.classList.remove('expanded');
          var historyNav = document.querySelector('.nav-menu .nav-item[data-section="panel-history"]');
          if(historyNav){
            var navItems = document.querySelectorAll('.nav-menu .nav-item[data-section]');
            navItems.forEach(function(n){ n.classList.remove('active'); });
            historyNav.classList.add('active');
            var sections = document.querySelectorAll('.right-panel .panel-section');
            sections.forEach(function(s){ s.style.display = 'none'; });
            historyPanel.style.display = 'block';
          } else {
            var sections = document.querySelectorAll('.right-panel .panel-section');
            sections.forEach(function(s){ s.style.display = 'none'; });
            historyPanel.style.display = 'block';
          }
        }
      }

      var titleEl = li.querySelector('.item-title');
      if (titleEl && titleEl.textContent.indexOf('Cancelled') === -1) {
        titleEl.textContent += ' - Cancelled';
      }
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('cancelled');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed');
        badge.classList.add(statusClassFor('cancelled'));
      }
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }
      closeCancelModalResident();
    })["catch"](function(){
      // alert('Network error. Please try again.');
    });
  }
  function performMoveToHistory(li, ref){
    if(!li || !ref) return;
    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'move_to_history',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to move to history.');
        return;
      }
      var scannedAt=li.getAttribute('data-scanned-at')||'';
      var hasScan=!!scannedAt;
      li.setAttribute('data-status', hasScan ? 'permission_granted' : 'denied');
      prevStatuses[ref]= hasScan ? 'permission_granted' : 'denied';
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent = hasScan ? 'Access Granted' : 'Denied';
        badge.className = 'status-badge ' + statusClassFor(hasScan ? 'permission_granted' : 'denied');
      }
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }
      var activePanel = document.getElementById('panel-requests');
      var historyPanel = document.getElementById('panel-history');
      if(activePanel && historyPanel){
        var activeList = activePanel.querySelector('.item-list');
        var historyList = historyPanel.querySelector('.item-list');
        if(activeList && historyList){
          var remainingActiveItems = activeList.querySelectorAll('.list-item');
          li.remove();
          if(remainingActiveItems.length === 1){
            var emptyDiv = document.createElement('div');
            emptyDiv.style.cssText = 'padding:20px; text-align:center; color:#777;';
            emptyDiv.textContent = 'No active requests.';
            activeList.appendChild(emptyDiv);
          }
          var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
          if(noHistoryMsg) noHistoryMsg.remove();
          historyList.insertBefore(li, historyList.firstChild);
          li.style.display = '';
          li.classList.remove('expanded');
        }
      }
    })["catch"](function(){
      alert('Network error. Please try again.');
    });
  }
  
  function performDeleteResident(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalResident();
      return;
    }
    fetch('status.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'delete',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to delete request.');
        return;
      }
      li.remove();
      closeCancelModalResident();
    })["catch"](function(){
       // alert('Network error. Please try again.');
    });
  }

  function performDeleteGuest(){
    var ref=cancelModalRef;
    var row=cancelModalLi;
    if(!ref||!row){
      closeCancelModalResident();
      return;
    }
    fetch('profileresident.php',{
      method:'POST',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({action:'delete_guest',code:ref})
    }).then(function(r){return r.json();}).then(function(data){
      if(!data||!data.success){
        alert(data && data.message ? data.message : 'Unable to delete guest.');
        return;
      }
      var tbody=row.closest('tbody');
      row.remove();
      if(tbody){
        var remaining=tbody.querySelectorAll('tr');
        if(remaining.length===0){
          var tr=document.createElement('tr');
          tr.innerHTML='<td colspan="6" style="text-align:center;color:#6b6b6b;padding:10px 0;">No saved guests.</td>';
          tbody.appendChild(tr);
        }
      }
      closeCancelModalResident();
    })["catch"](function(){
      alert('Network error. Please try again.');
    });
  }
  
  if(cancelModalKeep){
    cancelModalKeep.addEventListener('click',function(){
      closeCancelModalResident();
    });
  }
  if(cancelModalClose){
    cancelModalClose.addEventListener('click',function(){
      closeCancelModalResident();
    });
  }
  if(cancelModalConfirm){
    cancelModalConfirm.addEventListener('click',function(){
      if(modalAction === 'delete'){
        performDeleteResident();
      } else if(modalAction === 'delete_guest'){
        performDeleteGuest();
      } else if(modalAction === 'cancel_report'){
        performCancelReport();
      } else {
        performCancelResident();
      }
    });
  }
  if(moveHistoryKeep){
    moveHistoryKeep.addEventListener('click',function(){
      closeMoveHistoryModal();
    });
  }
  if(moveHistoryClose){
    moveHistoryClose.addEventListener('click',function(){
      closeMoveHistoryModal();
    });
  }
  if(moveHistoryConfirm){
    moveHistoryConfirm.addEventListener('click',function(){
      var ref=moveHistoryRef;
      var li=moveHistoryLi;
      closeMoveHistoryModal();
      if(ref && li){
        performMoveToHistory(li, ref);
      }
    });
  }

  if(updateProofClose){
    updateProofClose.addEventListener('click',function(){
      closeUpdateProofModal();
    });
  }
  if(updateProofEditBtn && updateProofFile){
    updateProofEditBtn.addEventListener('click',function(){
      updateProofFile.click();
    });
  }
  if(updateProofRemoveBtn){
    updateProofRemoveBtn.addEventListener('click',function(){
      resetUpdateProofForm();
    });
  }
  if(updateProofFile){
    updateProofFile.addEventListener('change',function(){
      var file = updateProofFile.files && updateProofFile.files[0];
      if(updateProofFileName) updateProofFileName.textContent = file ? file.name : 'No file selected';
      if(updateProofRemoveBtn) updateProofRemoveBtn.disabled = !file;
      if(updateProofSubmitBtn) updateProofSubmitBtn.disabled = !file;
      if(updateProofPreview){
        updateProofPreview.innerHTML='';
        updateProofPreview.style.display='none';
        if(file){
          var type=(file.type||'').toLowerCase();
          if(type.indexOf('image/')===0){
            var reader=new FileReader();
            reader.onload=function(e){
              updateProofPreview.innerHTML='<img src="'+e.target.result+'" alt="Preview" style="border:1px solid #e5e7eb;border-radius:8px;">';
              updateProofPreview.style.display='block';
            };
            reader.readAsDataURL(file);
          } else if(type.indexOf('pdf')!==-1){
            var url=URL.createObjectURL(file);
            updateProofPreview.innerHTML='<a href="'+url+'" target="_blank" style="color:#23412e;text-decoration:underline;font-weight:600;">Open selected PDF</a>';
            updateProofPreview.style.display='block';
          }
        }
      }
      if(updateProofActions){
        if(file){
          updateProofActions.classList.add('is-visible');
        } else {
          updateProofActions.classList.remove('is-visible');
        }
      }
    });
  }
  if(updateProofSubmitBtn){
    updateProofSubmitBtn.addEventListener('click',function(){
      var file = updateProofFile && updateProofFile.files ? updateProofFile.files[0] : null;
      if(!file || !updateProofRef || !updateProofLi) return;
      var fd=new FormData();
      fd.append('ref_code', updateProofRef);
      fd.append('receipt', file);
      updateProofSubmitBtn.disabled=true;
      fetch('upload_receipt.php',{method:'POST',body:fd})
        .then(function(r){ return r.json(); })
        .then(function(data){
          if(!data || !data.success){
            updateProofSubmitBtn.disabled=false;
            alert(data && data.message ? data.message : 'Upload failed.');
            return;
          }
          var newPayStatus = (data.payment_status || 'pending_update');
          updateProofLi.setAttribute('data-payment-status', newPayStatus);
          updateProofLi.setAttribute('data-status', newPayStatus);
          var badge=updateProofLi.querySelector('.status-badge');
          if(badge){
            badge.textContent=fmtLabel(newPayStatus);
            badge.className='status-badge '+statusClassFor(newPayStatus);
          }
          var extraEl=updateProofLi.querySelector('.item-extra');
          if(extraEl){
            extraEl.setAttribute('data-loaded','0');
            extraEl.innerHTML='';
            if(updateProofLi.classList.contains('expanded')){
              buildExtraContent(updateProofLi, extraEl);
              extraEl.setAttribute('data-loaded','1');
            }
          }
          closeUpdateProofModal();
        })
        ["catch"](function(){
          updateProofSubmitBtn.disabled=false;
          alert('Network error. Please try again.');
        });
    });
  }

  // Sidebar Toggle Logic
  var menuToggle = document.getElementById('menuToggle');
  var sidebar = document.querySelector('.sidebar');
  var overlay = document.getElementById('sidebarOverlay');

  if(menuToggle && sidebar && overlay) {
      function closeSidebar() {
          sidebar.classList.remove('open');
          overlay.classList.remove('show');
      }

      menuToggle.addEventListener('click', function() {
          sidebar.classList.add('open');
          overlay.classList.add('show');
      });

      overlay.addEventListener('click', closeSidebar);
  }

  function buildExtraContent(li, extra){
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var status=li.getAttribute('data-status')||'';
    var ref=li.getAttribute('data-ref-code')||'';
    var scannedAt=li.getAttribute('data-scanned-at')||'';
    var hasScan=!!scannedAt;
    var effectiveStatus=(hasScan && String(status||'').toLowerCase().indexOf('moved_to_history')!==-1) ? 'permission_granted' : status;
    var label=fmtLabel(effectiveStatus);
    var reservedBy=li.getAttribute('data-reserved-by')||'';
    var paymentStatus=(li.getAttribute('data-payment-status')||'').toLowerCase();
    var attempts = parseInt(li.getAttribute('data-attempts')||'0',10);
    var scheduleText=li.getAttribute('data-schedule')||'';
    var startDateRaw=li.getAttribute('data-start-date')||'';
    var endDateRaw=li.getAttribute('data-end-date')||'';
    var startTimeRaw=li.getAttribute('data-start-time')||'';
    var endTimeRaw=li.getAttribute('data-end-time')||'';
    var reasonText=li.getAttribute('data-reason')||'';
    var statusNote='';
    var s=String(effectiveStatus||'').toLowerCase();
    var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
    var isApproved=s.indexOf('approv')!==-1 || s.indexOf('permission')!==-1 || s.indexOf('granted')!==-1;
    if(type==='reservation' && paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3){
      isApproved=false;
    }
    if(isApproved) {
        if(type==='guest_form') statusNote='Guest Request Approved';
        else statusNote='This request is approved. Use this QR pass at the gate.';
    }
    else if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) statusNote='This request was denied. Please contact the subdivision office for details.';
    else if(s.indexOf('cancelled')!==-1) statusNote='This request was cancelled by the user.';
    else if(s.indexOf('pending')!==-1||s===''||s==='new') {
        if(type==='guest_form') statusNote='Wait until the guest is approved. Once approved, a unique QR code will be available in "My Guests" under the guest\'s name.';
        else statusNote='This request is pending. Wait for the admin to review it. The QR entry pass will be available after approval.';
    }
    else if(s.indexOf('resolved')!==-1) statusNote='This item has been marked as resolved by the admin.';
    else if(s.indexOf('expired')!==-1) statusNote='This pass is expired and can no longer be used.';
    if(type==='reservation' && paymentStatus==='rejected'){
        var att = isNaN(attempts)?0:attempts;
        if(att >= 3){
          statusNote='Denied — Max Attempts Reached. Payment was rejected 3 times. No further uploads are allowed. The reservation is permanently denied.';
        }else{
          statusNote='Your reservation payment was rejected. Please upload a clear and legible payment receipt to avoid denial. You have 3 attempts. ';
          statusNote+='Attempt '+Math.max(att,1)+' of 3.';
        }
    } else if (type==='reservation' && paymentStatus==='pending_update'){
        statusNote='Payment proof resubmitted. Awaiting verification.';
    }
    var titleEl=li.querySelector('.item-title');
    var detailsEl=li.querySelector('.item-details');
    var refSpan=li.querySelector('.item-ref span');
    function esc(t){
      return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }
    function formatRawDate(value){
      var raw = String(value||'').trim();
      if(!raw) return '';
      var m = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);
      if(m){
        return m[2] + '/' + m[3] + '/' + m[1].slice(-2);
      }
      var d = new Date(raw);
      if(isNaN(d.getTime())) return '';
      var mm = String(d.getMonth()+1).padStart(2,'0');
      var dd = String(d.getDate()).padStart(2,'0');
      var yy = String(d.getFullYear()).slice(-2);
      return mm+'/'+dd+'/'+yy;
    }
    function formatRawTime(value){
      var t = String(value||'').trim();
      var m = t.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
      if(m){
        var hh = parseInt(m[1],10);
        var minute = m[2];
        var ampm = hh >= 12 ? 'PM' : 'AM';
        var displayHour = hh % 12;
        if(displayHour === 0) displayHour = 12;
        return displayHour + ':' + minute + ' ' + ampm;
      }
      return t;
    }
    function parseTimeToMinutes(rawValue){
      var value = String(rawValue || '').trim();
      if(!value) return null;
      var m = value.match(/^(\d{1,2}):(\d{2})(?::\d{2})?$/);
      if(m){
        var hh = parseInt(m[1], 10);
        var mm = parseInt(m[2], 10);
        if(Number.isNaN(hh) || Number.isNaN(mm)) return null;
        return hh * 60 + mm;
      }
      var m12 = value.match(/^(\d{1,2}):(\d{2})\s*([AP]M)$/i);
      if(m12){
        var hh12 = parseInt(m12[1], 10);
        var mm12 = parseInt(m12[2], 10);
        var suffix = String(m12[3]).toUpperCase();
        if(Number.isNaN(hh12) || Number.isNaN(mm12)) return null;
        if(suffix === 'AM' && hh12 === 12) hh12 = 0;
        if(suffix === 'PM' && hh12 < 12) hh12 += 12;
        return hh12 * 60 + mm12;
      }
      return null;
    }
    function computeReservationDurationHours(startTimeRaw, endTimeRaw){
      var startMinutes = parseTimeToMinutes(startTimeRaw);
      var endMinutes = parseTimeToMinutes(endTimeRaw);
      if(startMinutes === null || endMinutes === null) return '';
      var diffMinutes = endMinutes - startMinutes;
      if(diffMinutes <= 0){
        if(startMinutes > endMinutes){
          diffMinutes = (24 * 60) - startMinutes + endMinutes;
        } else {
          return '';
        }
      }
      var hours = diffMinutes / 60;
      if(hours <= 0) return '';
      var formatted = Number.isInteger(hours) ? String(parseInt(hours, 10)) : hours.toFixed(1).replace(/\.0$/, '');
      return formatted + ' ' + (parseFloat(formatted) === 1 ? 'hr' : 'hrs');
    }
    function scheduleParts(text){
      var t=String(text||'').trim();
      if(!t) return { date:'', time:'' };
      var timeRange=t.match(/(\d{1,2}:\d{2}\s*[AP]M)\s*-\s*(\d{1,2}:\d{2}\s*[AP]M)$/i);
      if(timeRange){
        var timePart=timeRange[1]+' - '+timeRange[2];
        var datePart=t.replace(timeRange[0],'').trim();
        return { date:datePart, time:timePart };
      }
      return { date:t, time:'' };
    }
    function schedulePartsFromRaw(){
      var dateLabel = '';
      if(startDateRaw || endDateRaw){
        var startDateLabel = formatRawDate(startDateRaw);
        var endDateLabel = formatRawDate(endDateRaw) || startDateLabel;
        if(startDateLabel && endDateLabel){
          dateLabel = startDateLabel === endDateLabel ? startDateLabel : startDateLabel + ' - ' + endDateLabel;
        } else {
          dateLabel = startDateLabel || endDateLabel;
        }
      }
      var timeLabel = '';
      if(startTimeRaw || endTimeRaw){
        var startLabel = formatRawTime(startTimeRaw);
        var endLabel = formatRawTime(endTimeRaw);
        if(startLabel && endLabel){
          timeLabel = startLabel + ' - ' + endLabel;
        } else {
          timeLabel = startLabel || endLabel;
        }
      }
      return { date: dateLabel, time: timeLabel };
    }
    var summaryParts=[];
    if(titleEl){ summaryParts.push(titleEl.textContent.trim()); }
    if(detailsEl){ summaryParts.push(detailsEl.textContent.replace(/^\s*-\s*/,'').trim()); }
    if(refSpan){ summaryParts.push('Code: '+refSpan.textContent.trim()); }
    if(reservedBy && type==='reservation'){ summaryParts.push('Reserved by: '+reservedBy); }
    var summaryText=summaryParts.join(' • ');
    var canCancel=(type==='reservation'||type==='guest_form')&&((s.indexOf('pending')!==-1||s.indexOf('pending_update')!==-1||s===''||s==='new')||paymentStatus==='pending_update');
    var isHistoryPanel=!!li.closest('#panel-history');
    var canCancelReport=(type==='report') && !isHistoryPanel && s.indexOf('cancel')===-1 && s.indexOf('reject')===-1 && s.indexOf('resolved')===-1;
    var rawStatus=(String(status||'').toLowerCase());
    var canDelete=isHistoryPanel && (rawStatus.indexOf('cancel')!==-1 || rawStatus.indexOf('denied')!==-1 || rawStatus.indexOf('reject')!==-1 || rawStatus.indexOf('expired')!==-1 || rawStatus.indexOf('moved_to_history')!==-1);
    var isDeniedMax = (type==='reservation' && paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3);
    var canMoveHistory=(!isHistoryPanel) && !isDeniedMax && (rawStatus.indexOf('denied')!==-1 || rawStatus.indexOf('reject')!==-1 || rawStatus.indexOf('permission')!==-1 || rawStatus.indexOf('granted')!==-1);
    var canUpdateProof=(type==='reservation' && paymentStatus==='rejected' && s.indexOf('cancel')===-1);
    var isRejectedReason=(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1||paymentStatus==='rejected');
    var showStatusLabel=!isRejectedReason;
    if(s.indexOf('deleted')!==-1) showStatusLabel=false;
    var highlightReason=(paymentStatus==='rejected');
    if (type==='reservation' && paymentStatus==='rejected') {
      var att = isNaN(attempts)?0:attempts;
      var headerBadge = li.querySelector('.status-badge');
      if(att >= 3){
        label = 'Denied – Max Attempts Reached';
        if (headerBadge) { headerBadge.textContent = label; }
        canUpdateProof=false; canCancel=false; canMoveHistory=!isHistoryPanel; canDelete=false;
      }else{
        label = 'Rejected (Attempt '+Math.max(att,1)+' of 3)';
        if (headerBadge) { headerBadge.textContent = label; }
        canUpdateProof=true; canCancel=false; canMoveHistory=false; canDelete=false;
      }
    }
    var isAccessGranted = s.indexOf('permission_granted')!==-1 || s.indexOf('access granted')!==-1 || s.indexOf('access_granted')!==-1 || s.indexOf('permission granted')!==-1;
    var html='';
    if((type==='reservation'||type==='guest_form') && isAccessGranted){
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      html+='<div class="item-extra-note">'+esc('Access granted. Your QR entry pass has already been scanned by the guard.')+'</div>';
      html+='<div class="item-actions">';
      if(ref){
        html+='<button type="button" class="item-extra-link view-details-btn view-details-trigger" data-ref="'+esc(ref)+'">View details</button>';
      }
      if(canMoveHistory && ref){
        html+='<button type="button" class="item-extra-link item-extra-move-history"><i class="fa-solid fa-box-archive"></i> Move to History</button>';
      }
      html+='</div>';
      html+='</div></div></div>';
      extra.innerHTML=html;
      var moveBtn = extra.querySelector('.item-extra-move-history');
      if(moveBtn && ref && canMoveHistory){
        moveBtn.addEventListener('click', function(ev){
          ev.stopPropagation();
          openMoveHistoryModal(li, ref);
        });
      }
      var viewBtns = extra.querySelectorAll('.view-details-trigger');
      viewBtns.forEach(function(btn){
        btn.addEventListener('click', function(ev){
          ev.stopPropagation();
          var code = btn.getAttribute('data-ref') || ref;
          if(code) openActivityModal(code);
        });
      });
      return;
    }
    if(type==='reservation'||type==='guest_form'){
      html+='<div class="item-extra-section">';
      var qrSrcForDownload = '';
      if(type!=='guest_form' && isApproved && ref){
        var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
        var statusLink=location.origin+basePath+'/qr_view.php?code='+encodeURIComponent(ref);
        var qrSrc='https://api.qrserver.com/v1/create-qr-code/?size=220x220&data='+encodeURIComponent(statusLink);
        qrSrcForDownload = qrSrc;
        html+='<div class="item-extra-title">Entry QR Pass</div>';
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-qr-wrap"><img class="item-extra-qr" src="'+qrSrc+'" alt="Entry QR Code"></div>';
        html+='<div class="item-extra-info">';
      }else{
        html+='<div class="item-extra-body">';
        html+='<div class="item-extra-info-only">';
      }
      var lowerLabel=String(label||'').toLowerCase();
      if(showStatusLabel && lowerLabel !== 'pending' && lowerLabel !== 'approved'){
        html+='<div class="item-extra-status"><span class="status-label '+statusClassFor(effectiveStatus)+'">'+label+'</span></div>';
      }
    var noteClass='item-extra-note'+((type==='reservation' && paymentStatus==='rejected' && (isNaN(attempts)?0:attempts) < 3)?' note-error':'');
    if(statusNote) html+='<div class="'+noteClass+'">'+esc(statusNote)+'</div>';
      if(reasonText){
        html+='<div class="item-reason'+(highlightReason?' is-rejected':'')+'">'+esc(reasonText)+'</div>';
      }
      if(type==='reservation'){
        var rawParts=schedulePartsFromRaw();
        var parts = (rawParts.date || rawParts.time) ? rawParts : (scheduleText ? scheduleParts(scheduleText) : {date:'', time:''});
        var rows='';
        if(parts.date){
          rows+='<div class="schedule-row"><div class="schedule-key">Date:</div><div class="schedule-val">'+esc(parts.date)+'</div></div>';
        }
        if(parts.time){
          rows+='<div class="schedule-row"><div class="schedule-key">Time:</div><div class="schedule-val">'+esc(parts.time)+'</div></div>';
          var hoursLabel = computeReservationDurationHours(startTimeRaw, endTimeRaw);
          if(hoursLabel){
            rows+='<div class="schedule-row"><div class="schedule-key">Duration:</div><div class="schedule-val">'+esc(hoursLabel)+'</div></div>';
          }
        }
        if(!rows && scheduleText){
          rows='<div class="schedule-row"><div class="schedule-key">Schedule:</div><div class="schedule-val">'+esc(scheduleText)+'</div></div>';
        }
        if(rows){
          var scheduleClass = (isHistoryPanel || s.indexOf('denied')!==-1 || (paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3)) ? 'status-neutral' : statusClassFor(effectiveStatus);
          html+='<div class="item-extra-schedule '+scheduleClass+'"><div class="schedule-title">Reservation Schedule</div>'+rows+'</div>';
        }
      }
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      
      html+='<div class="item-actions">';
      if(qrSrcForDownload){
        html+='<button type="button" class="item-extra-link download-qr-btn" data-qr="'+esc(qrSrcForDownload)+'" data-type="'+esc(type)+'"><i class="fa-solid fa-qrcode"></i> Download QR</button>';
      }
      if(canUpdateProof && ref){
        html+='<button type="button" class="item-extra-link update-proof-btn" data-ref="'+esc(ref)+'"><i class="fa-solid fa-upload"></i> Update Proof</button>';
      }
      if(ref){
        html+='<button type="button" class="item-extra-link view-details-btn view-details-trigger" data-ref="'+esc(ref)+'">View details</button>';
      }
      if(canCancel && ref){
        html+='<button type="button" class="item-extra-link item-extra-cancel"><i class="fa-solid fa-xmark"></i> '+(type==='guest_form'?'Cancel Request':'Cancel Reservation')+'</button>';
      }
      if(canMoveHistory && ref){
        html+='<button type="button" class="item-extra-link item-extra-move-history"><i class="fa-solid fa-box-archive"></i> Move to History</button>';
      }
      html+='</div>';
      html+='</div></div></div>';
    }else if(type==='report'){
      var curStat=(String(effectiveStatus||'').toLowerCase());
      if(curStat==='new'){ label='Pending'; }
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-title">Incident Status</div>';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      var lowerLabel=String(label||'').toLowerCase();
      if(lowerLabel !== 'pending' && lowerLabel !== 'approved'){
        html+='<div class="item-extra-status"><span class="status-label '+statusClassFor(status)+'">'+label+'</span></div>';
      }
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      html+='</div>';
      var reportSubject = li.getAttribute('data-report-subject') || '';
      var reportAddress = li.getAttribute('data-report-address') || '';
      var reportDate = li.getAttribute('data-report-date') || '';
      var reportNature = li.getAttribute('data-report-nature') || '';
      var reportOther = li.getAttribute('data-report-other') || '';
      var reportId = li.getAttribute('data-report-id') || '';
      var reportRows = '';
      if(reportSubject){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Subject:</div><div class="schedule-val">'+esc(reportSubject)+'</div></div>';
      }
      if(reportAddress){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Address:</div><div class="schedule-val">'+esc(reportAddress)+'</div></div>';
      }
      if(reportDate){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Date:</div><div class="schedule-val">'+esc(reportDate)+'</div></div>';
      }
      if(reportNature){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Nature:</div><div class="schedule-val">'+esc(reportNature)+'</div></div>';
      }
      if(reportOther){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Details:</div><div class="schedule-val">'+esc(reportOther)+'</div></div>';
      }
      if(ref){
        reportRows+='<div class="schedule-row"><div class="schedule-key">Code:</div><div class="schedule-val">'+esc(ref)+'</div></div>';
      }
      if(reportRows){
        html+='<div class="item-extra-schedule report-details"><div class="schedule-title">Report Details</div>'+reportRows+'</div>';
      }
      html+='<div class="item-actions">';
      if(reportId){
        html+='<button type="button" class="view-details-btn view-report-btn" data-report-id="'+esc(reportId)+'">View details</button>';
      }
      if(canCancelReport && ref){
        html+='<button type="button" class="item-extra-link item-extra-cancel"><i class="fa-solid fa-xmark"></i> Cancel Request</button>';
      }
      html+='</div>';
      html+='</div></div>';
      html+='</div>';
    }else{
      html+='<div class="item-extra-section">';
      html+='<div class="item-extra-title">Request Details</div>';
      html+='<div class="item-extra-body">';
      html+='<div class="item-extra-info-only">';
      var lowerLabel=String(label||'').toLowerCase();
      if(lowerLabel !== 'pending' && lowerLabel !== 'approved'){
        html+='<div class="item-extra-status"><span class="status-label '+statusClassFor(status)+'">'+label+'</span></div>';
      }
      if(statusNote) html+='<div class="item-extra-note">'+esc(statusNote)+'</div>';
      if(summaryText) html+='<div class="item-extra-summary">'+esc(summaryText)+'</div>';
      html+='</div></div></div>';
    }
    extra.innerHTML=html;
    var cancelBtn=extra.querySelector('.item-extra-cancel');
    if(cancelBtn && ref && (canCancel || canCancelReport)){
      cancelBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openCancelModal(li,ref);
      });
    }
    var deleteBtn=extra.querySelector('.item-extra-delete');
    if(deleteBtn && ref && canDelete){
      deleteBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openDeleteModal(li,ref);
      });
    }
    var moveBtn=extra.querySelector('.item-extra-move-history');
    if(moveBtn && ref && canMoveHistory){
      moveBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openMoveHistoryModal(li, ref);
      });
    }
    var viewBtns=extra.querySelectorAll('.view-details-trigger');
    viewBtns.forEach(function(btn){
      btn.addEventListener('click',function(ev){
        ev.stopPropagation();
        var code=btn.getAttribute('data-ref')||ref;
        if(code) openActivityModal(code);
      });
    });
    var dropdownMove = extra.querySelector('.dropdown-move-history, [data-action="move_history"]');
    if(dropdownMove && ref && canMoveHistory){
      dropdownMove.addEventListener('click', function(ev){
        ev.stopPropagation();
        openMoveHistoryModal(li, ref);
      });
    }
    var viewReportBtn = extra.querySelector('.view-report-btn');
    if(viewReportBtn){
      viewReportBtn.addEventListener('click', function(ev){
        ev.stopPropagation();
        var rid = viewReportBtn.getAttribute('data-report-id') || '';
        if(rid){ openReportDetailsModal(rid); }
      });
    }
    var downloadBtn=extra.querySelector('.download-qr-btn');
    if(downloadBtn){
      downloadBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        var url = downloadBtn.getAttribute('data-qr') || '';
        var itemType = downloadBtn.getAttribute('data-type') || '';
        if(!url) return;
        
        function downloadRaw() {
            fetch(url)
              .then(function(resp){ return resp.blob(); })
              .then(function(blob){
                var objectUrl = window.URL.createObjectURL(blob);
                var a = document.createElement('a');
                a.href = objectUrl;
                a.download = 'QR_' + (ref || 'pass') + '.png';
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(objectUrl);
              })
              .catch(function(){});
        }

        function doDownload(){
          downloadRaw();
        }
        var warningMsg = String(itemType || '').toLowerCase() === 'reservation'
          ? 'Do not scan. One-time use only. Valid only on the selected date and time. Authorized guards only.'
          : 'Do not scan. Authorized guards only.';
        if(typeof window.openQRWarning === 'function'){
          window.openQRWarning(doDownload, warningMsg);
        } else {
          doDownload();
        }
      });
    }
    var updateBtn=extra.querySelector('.update-proof-btn');
    if(updateBtn && ref){
      updateBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openUpdateProofModal(li, ref);
      });
    }
  }
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
    return mm+'/'+dd+'/'+yy+' '+h+':'+mi+' '+ampm;
  }
  function formatNotifMessage(message){
    var safe=String(message||'').replace(/[<>]/g,'');
    var lower=safe.toLowerCase();
    var idx=lower.indexOf('reason:');
    if(idx===-1) return safe;
    var before=safe.slice(0,idx).replace(/\s+$/,'');
    var reason=safe.slice(idx).replace(/^\s+/,'');
    if(before) return before+' <span class="notif-reason">'+reason+'</span>';
    return '<span class="notif-reason">'+reason+'</span>';
  }
  function formatNotifDisplay(message){
    var formatted=formatNotifMessage(message||'');
    var cleaned=String(formatted).replace(/Code:\s*[A-Z0-9\-]+/ig,'').replace(/\s+•\s*$/,'').replace(/\s{2,}/g,' ').trim();
    return cleaned;
  }
  function extractNotifCode(message){
    var m=String(message||'').match(/Code:\s*([A-Z0-9\-]+)/i);
    return m && m[1] ? m[1].toUpperCase() : '';
  }
  function notifDedupeKey(n){
    var title=String(n.title||'').trim().toLowerCase();
    var type=String(n.type||'').trim().toLowerCase();
    var code=extractNotifCode(n.message||'');
    var key=title+'|'+type+'|'+code;
    if(key==='||'){ key=title+'|'+type+'|'+String(n.message||'').slice(0,64).toLowerCase(); }
    return key;
  }
  function notifTimeValue(it){
    var v=it.created_at||it.time||'';
    var d=new Date(v);
    if(isNaN(d.getTime())) return 0;
    return d.getTime();
  }
  function dedupeNotifications(list){
    var map={};
    for(var i=0;i<list.length;i++){
      var n=list[i]||{};
      var k=notifDedupeKey(n);
      if(!k) continue;
      if(!map[k]){ map[k]=n; }
      else{
        var a=notifTimeValue(map[k]);
        var b=notifTimeValue(n);
        if(b>a){ map[k]=n; }
      }
    }
    var out=[];
    Object.keys(map).forEach(function(k){ out.push(map[k]); });
    out.sort(function(a,b){ return notifTimeValue(b)-notifTimeValue(a); });
    return out;
  }
  function renderNotifPanel(){
    if(!notifPanel) return;
    var header='<div class="notif-panel-header"><div class="notif-panel-title">Notifications</div><button type="button" class="notif-panel-close" aria-label="Close">&times;</button></div>';
    if(!notifItems.length){
      notifPanel.innerHTML=header+'<div class="notif-panel-body"><div class="notif-empty">No recent updates</div></div>';
    } else {
      var html='';
      notifItems.sort(function(a,b){
        var ta = new Date(a.created_at||0).getTime();
        var tb = new Date(b.created_at||0).getTime();
        return tb - ta;
      });
      for(var i=0;i<notifItems.length;i++){
        var it=notifItems[i]||{};
        var title=String(it.title||'').replace(/[<>]/g,'');
        var message=formatNotifDisplay(it.message||'');
        var time=formatNotifDateTime(it.created_at||'');
        var t=String(it.type||'').toLowerCase();
        var subCls='notif-item-sub'+(t==='error'?' notif-error':'');
        html+='<div class="notif-item"><div class="notif-item-main"><div class="notif-item-title">'+title+'</div><div class="'+subCls+'">'+message+'</div>';
        if(time) html+='<div class="notif-item-time">'+time+'</div>';
        html+='</div></div>';
      }
      notifPanel.innerHTML=header+'<div class="notif-panel-body">'+html+'</div>';
    }
    var closeBtn=notifPanel.querySelector('.notif-panel-close');
    if(closeBtn){
      closeBtn.addEventListener('click',function(e){
        e.stopPropagation();
        notifPanel.style.display='none';
      });
    }
  }
  function markNotificationsRead(){
    return fetch('profileresident.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'mark_notifications_read'})})
      .then(function(r){ return r.json(); })["catch"](function(){});
  }
  // Modal handling
  var activityModal = document.getElementById('activityModal');
  var activityModalBody = document.getElementById('activityModalBody');
  var activityModalClose = activityModal ? activityModal.querySelector('.close') : null;

  window.openActivityModal = function(refCode) {
    if (!activityModal || !activityModalBody) {
      // Re-fetch in case it was missing on load
      activityModal = document.getElementById('activityModal');
      activityModalBody = document.getElementById('activityModalBody');
      if (!activityModal || !activityModalBody) return;
    }
    activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
    activityModal.style.display = 'block';

    fetch('get_activity_details.php?code=' + encodeURIComponent(refCode))
      .then(r => r.text())
      .then(html => {
        activityModalBody.innerHTML = html;
      })
      .catch(e => {
        activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>';
      });
  }

  window.openReportDetailsModal = function(reportId) {
    if (!activityModal || !activityModalBody) {
      activityModal = document.getElementById('activityModal');
      activityModalBody = document.getElementById('activityModalBody');
      if (!activityModal || !activityModalBody) return;
    }
    activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
    activityModal.style.display = 'block';
    fetch('get_report_details.php?id=' + encodeURIComponent(reportId))
      .then(r => r.text())
      .then(html => { activityModalBody.innerHTML = html; })
      .catch(e => { activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>'; });
  }

  if (activityModalClose) {
    activityModalClose.onclick = function() {
      activityModal.style.display = "none";
    }
  }

  window.addEventListener('click', function(event) {
     if (event.target == activityModal) {
       activityModal.style.display = "none";
     }
     if (event.target == moveHistoryModal) {
       closeMoveHistoryModal();
     }
     if (event.target == updateProofModal) {
       closeUpdateProofModal();
     }
   });
   
   window.downloadQRImage = function(code) {
      var container = document.querySelector('#activityModalBody .amenity-pass-container');
      if (container) {
         html2canvas(container, {
             backgroundColor: null,
             scale: 2,
             logging: false,
             useCORS: true
         }).then(function(canvas) {
             var a = document.createElement('a');
             a.href = canvas.toDataURL('image/png');
             a.download = 'VictorianPass_' + (code || 'amenity') + '.png';
             document.body.appendChild(a);
             a.click();
             document.body.removeChild(a);
         }).catch(function(e){
             console.error(e);
             alert('Could not download pass.');
         });
         return;
      }

      var img = document.querySelector('#activityModalBody img[alt="QR Code"]');
      if (!img) { alert('QR Code not found'); return; }
      fetch(img.src)
        .then(resp => resp.blob())
        .then(blob => {
            var url = window.URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'QR_' + code + '.png';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
        })
        .catch(() => alert('Could not download image.'));
   };
 
   document.querySelectorAll('.item-list .list-item').forEach(function(li){
    li.addEventListener('click',function(e){
      if(e.target.closest('a') || e.target.closest('button')) return;
      
      li.classList.toggle('expanded');
      var extra = li.querySelector('.item-extra');
      if (extra && extra.getAttribute('data-loaded') !== '1' && li.classList.contains('expanded')) {
          buildExtraContent(li, extra);
          extra.setAttribute('data-loaded', '1');
      }
    });
  });
  if(notifBtn){
    notifBtn.addEventListener('click',function(e){
      e.stopPropagation();
      if(notifPopup){ notifPopup.style.display='none'; }
      if(notifPanel){
        notifPanel.style.display=(notifPanel.style.display==='block'?'none':'block');
        if(notifPanel.style.display==='block') renderNotifPanel();
      }
      document.querySelectorAll('.item-list .list-item.status-updated').forEach(function(li){
        li.classList.remove('status-updated');
      });
      if(notifCountEl){
        notifCountEl.textContent='0';
        notifCountEl.style.display='none';
      }
      if(notifPanel && notifPanel.style.display==='block'){
        markNotificationsRead();
      }
    });
  }
  document.addEventListener('click',function(e){
    if(!notifPanel || !notifBtn) return;
    if(notifPanel.style.display!=='block' && (!notifPopup || notifPopup.style.display!=='block')) return;
    if((notifPanel && notifPanel.contains(e.target)) || (notifPopup && notifPopup.contains(e.target)) || notifBtn.contains(e.target)) return;
    notifPanel.style.display='none';
    if(notifPopup){ notifPopup.style.display='none'; }
  });

  var sections=document.querySelectorAll('.right-panel .panel-section');
  function applyResidentThemeBySection(id){
    var mainContent = document.querySelector('.main-content');
    var brandMain = document.querySelector('.top-header .brand-main');
    var brandSub = document.querySelector('.top-header .brand-sub');
    var isEcoPoint = id === 'panel-points-history';

    if (mainContent) {
      mainContent.classList.toggle('ecopoint-active', isEcoPoint);
    }
    if (brandMain) {
      brandMain.textContent = isEcoPoint ? 'VHEcoPoint' : 'VictorianPass';
    }
    if (brandSub) {
      brandSub.textContent = isEcoPoint ? 'Smart Waste Segregation Station' : 'Victorian Heights Subdivision';
    }
  }
  function showPanel(id){
    sections.forEach(function(sec){
      if(!sec) return;
      sec.style.display=sec.id===id?'':'none';
    });
    applyResidentThemeBySection(id);
  }
  document.querySelectorAll('.nav-menu .nav-item[data-section]').forEach(function(item){
    item.addEventListener('click',function(e){
      var target=item.getAttribute('data-section');
      if(!target) return;
      e.preventDefault();
      document.querySelectorAll('.nav-menu .nav-item').forEach(function(el){
        el.classList.remove('active');
      });
      item.classList.add('active');
      showPanel(target);
      if(target==='panel-requests' && searchInput && typeof filterList==='function'){
        searchInput.value='';
        filterList();
      }
    });
  });
  if (document.querySelector('.nav-menu .nav-item[data-section="panel-points-history"].active')) {
    applyResidentThemeBySection('panel-points-history');
  } else {
    applyResidentThemeBySection('panel-requests');
  }
  var guestFormBackBtn=document.getElementById('guestFormBackBtn');
  if(guestFormBackBtn){
    guestFormBackBtn.addEventListener('click',function(e){
      e.preventDefault();
      var reqNav=document.querySelector('.nav-menu .nav-item[data-section="panel-requests"]');
      if(reqNav) reqNav.click();
    });
  }

  var entryForm=document.getElementById('entryForm');
  var birthdateEl=document.getElementById('birthdate');
  var idInput=document.getElementById('visitor_valid_id');
  var idPreviewWrap=document.getElementById('idPreviewWrap');
  var idPreview=document.getElementById('idPreview');
  var btnClearId=document.getElementById('btnClearId');
  var verifyModal=document.getElementById('verifyModal');
  var verifySummary=document.getElementById('verifySummary');
  var verifyCancelBtn=document.getElementById('verifyCancelBtn');
  var verifyConfirmBtn=document.getElementById('verifyConfirmBtn');
  var refModal=document.getElementById('refModal');
  var refModalCloseBtn=document.getElementById('refModalCloseBtn');
  var submittingGuest=false;

  function setWarning(key,message){
    var inputEl=document.getElementById(key);
    var container=null;
    if(inputEl){
      container=inputEl.closest('.form-group')||inputEl.closest('.form-row')||inputEl.closest('.upload-box')||inputEl.parentNode;
    }
    if(!container) return;
    var warnEl=container.querySelector('.field-warning[data-for="'+key+'"]');
    if(message){
      if(!warnEl){
        warnEl=document.createElement('div');
        warnEl.className='field-warning';
        warnEl.setAttribute('data-for',key);
        warnEl.setAttribute('role','alert');
        container.appendChild(warnEl);
      }
      var icon=warnEl.querySelector('.warn-icon');
      if(!icon){
        icon=document.createElement('span');
        icon.className='warn-icon';
        icon.textContent='!';
        warnEl.appendChild(icon);
      }
      var msgSpan=warnEl.querySelector('.msg');
      if(!msgSpan){
        msgSpan=document.createElement('span');
        msgSpan.className='msg';
        warnEl.appendChild(msgSpan);
      }
      msgSpan.textContent=message;
      var closer=warnEl.querySelector('.close-warn');
      if(!closer){
        closer=document.createElement('button');
        closer.type='button';
        closer.className='close-warn';
        closer.setAttribute('aria-label','Dismiss');
        closer.textContent='\u00d7';
        warnEl.appendChild(closer);
        closer.addEventListener('click',function(){ warnEl.remove(); });
      }
    }else{
      if(warnEl) warnEl.remove();
    }
  }

  function blockDigits(e){
    if(/[0-9]/.test(e.key)){
      e.preventDefault();
      setWarning(e.target.id,'Numbers are not allowed in this field.');
    }
  }
  function sanitizeNoDigits(e){
    var val=e.target.value;
    var cleaned=val.replace(/[0-9]/g,'');
    if(val!==cleaned){
      e.target.value=cleaned;
      setWarning(e.target.id,'Numbers were removed.');
    }else{
      setWarning(e.target.id,'');
    }
  }
  function isValidPhone(el){
    var val=(el.value||'').replace(/[\s\-]/g, '');
    if(/^(\+63|63)(9\d{9})$/.test(val)) val = '0' + val.replace(/^(\+63|63)/, '');
    else if(/^9\d{9}$/.test(val)) val = '0' + val;
    return /^09\d{9}$/.test(val);
  }
  function getEmailError(el) {
    var val=(el.value||'').trim();
    if(!/^[a-zA-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*$/.test(val)) return 'Please enter a valid email.';
    return '';
  }
  ['resident_full_name','visitor_first_name','visitor_last_name'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('keydown',blockDigits);
    el.addEventListener('input',sanitizeNoDigits);
  });
  ['resident_email','visitor_email'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.addEventListener('input',function(){
      var err = getEmailError(el);
      if(!el.value.trim()) err = '';
      setWarning(id, err);
    });
  });
  ['resident_contact','visitor_contact'].forEach(function(id){
    var el=document.getElementById(id);
    if(!el) return;
    el.setAttribute('maxlength', '15');
    
    el.addEventListener('input',function(){
      var val = el.value.replace(/[^0-9+\s]/g, '');
      if (el.value !== val) el.value = val;

      if(!el.value.trim()){
        setWarning(id,'');
        return;
      }
      setWarning(id,isValidPhone(el)?'':'Format must be 11 digits starting with 09 (e.g. 09XX XXX XXXX)');
    });

    el.addEventListener('blur', function(e){
      var val = e.target.value.trim();
      if (!val) return;
      
      // Normalize logic to 09XXXXXXXXX
      var clean = val.replace(/\D/g, '');
      var normalized = '';
      
      if (clean.length === 11 && clean.startsWith('09')) {
         normalized = clean;
      } else if (clean.length === 12 && clean.startsWith('639')) {
         normalized = '0' + clean.substring(2);
      } else if (clean.length === 10 && clean.startsWith('9')) {
         normalized = '0' + clean;
      } else {
         if (!isValidPhone(el)) {
            setWarning(id, 'Format must be 11 digits starting with 09 (e.g. 09XX XXX XXXX)');
         }
         return;
      }
      
      if (normalized) {
         // Display as 09XX XXX XXXX
         var part1 = normalized.substring(0, 4);
         var part2 = normalized.substring(4, 7);
         var part3 = normalized.substring(7);
         e.target.value = part1 + ' ' + part2 + ' ' + part3;
         setWarning(id, '');
      }
    });
  });

  if(birthdateEl){
    var d=new Date();
    birthdateEl.setAttribute('max',d.toISOString().split('T')[0]);
  }

  function validateGuestForm(){
    var valid=true;
    var reqIds=['resident_full_name','resident_house','resident_email','resident_contact','visitor_first_name','visitor_last_name','visitor_address','birthdate','visitor_contact'];
    reqIds.forEach(function(id){
      var el=document.getElementById(id);
      if(!el) return;
      if(!String(el.value||'').trim()){
        setWarning(id,'This field is required.');
        valid=false;
      }else{
        setWarning(id,'');
      }
    });
    var sexEl=document.getElementById('visitor_sex');
    if(sexEl){
      if(!sexEl.value){
        setWarning('visitor_sex','Please select Sex.');
        valid=false;
      }else{
        setWarning('visitor_sex','');
      }
    }
    if(birthdateEl && birthdateEl.value){
      var todayStr=new Date().toISOString().split('T')[0];
      if(birthdateEl.value>todayStr){
        setWarning('birthdate','Birthdate cannot be in the future.');
        valid=false;
      }else{
        setWarning('birthdate','');
      }
    }
    var rc=document.getElementById('resident_contact');
    var vc=document.getElementById('visitor_contact');
    var re=document.getElementById('resident_email');
    var ve=document.getElementById('visitor_email');
    if(re && getEmailError(re)){
      setWarning('resident_email', getEmailError(re));
      valid=false;
    }
    var veVal=(ve && (ve.value||'').trim())||'';
    if(ve && veVal!=='' && getEmailError(ve)){
      setWarning('visitor_email', getEmailError(ve));
      valid=false;
    }
    if(rc && !isValidPhone(rc)){
      setWarning('resident_contact','Format must be 11 digits starting with 09 (e.g. 09XX XXX XXXX)');
      valid=false;
    }
    if(vc && !isValidPhone(vc)){
      setWarning('visitor_contact','Format must be 11 digits starting with 09 (e.g. 09XX XXX XXXX)');
      valid=false;
    }
    if(idInput && !(idInput.files && idInput.files[0])){
      setWarning('visitor_valid_id','Please upload Guest’s Valid ID.');
      valid=false;
    }
    return valid;
  }

  function buildVerifySummary(){
    if(!verifyModal || !verifySummary) return;
    var resNameEl=document.getElementById('resident_full_name');
    var resHouseEl=document.getElementById('resident_house');
    var resContactEl=document.getElementById('resident_contact');
    var visFirstEl=document.getElementById('visitor_first_name');
    var visLastEl=document.getElementById('visitor_last_name');
    var visContactEl=document.getElementById('visitor_contact');
    var visEmailEl=document.getElementById('visitor_email');
    var visAddressEl=document.getElementById('visitor_address');
    var vSexEl=document.getElementById('visitor_sex');
    var resName=resNameEl?resNameEl.value.trim():'';
    var resHouse=resHouseEl?resHouseEl.value.trim():'';
    var resContact=resContactEl?resContactEl.value.trim():'';
    var visFirst=visFirstEl?visFirstEl.value.trim():'';
    var visLast=visLastEl?visLastEl.value.trim():'';
    var visContact=visContactEl?visContactEl.value.trim():'';
    var visEmail=visEmailEl?visEmailEl.value.trim():'';
    var visAddress=visAddressEl?visAddressEl.value.trim():'';
    var vSex=vSexEl?vSexEl.value:'';
    var vBirth=birthdateEl?birthdateEl.value:'';
    var items=[
      ['Resident',resName||'-'],
      ['House/Unit',resHouse||'-'],
      ['Resident Contact',resContact||'-'],
      ['Guest',(visFirst+' '+visLast).trim()||'-'],
      ['Guest Sex',vSex||'-'],
      ['Guest Birthdate',vBirth||'-'],
      ['Guest Contact',visContact||'-'],
      ['Guest Email',visEmail||'-'],
      ['Guest Address',visAddress||'-']
    ];
    verifySummary.innerHTML=items.map(function(x){
      return '<div style="display:flex;justify-content:space-between;margin:4px 0"><span style="font-weight:600">'+x[0]+'</span><span>'+x[1]+'</span></div>';
    }).join('');
    verifyModal.style.display='flex';
  }

  if(idInput){
    idInput.addEventListener('change',function(){
      var file=idInput.files && idInput.files[0];
      if(!file){
        if(idPreviewWrap) idPreviewWrap.style.display='none';
        setWarning('visitor_valid_id','Please upload Guest’s Valid ID.');
        return;
      }
      var reader=new FileReader();
      reader.onload=function(e){
        if(idPreview){
          idPreview.src=e.target.result;
        }
        if(idPreviewWrap){
          idPreviewWrap.style.display='block';
        }
      };
      reader.readAsDataURL(file);
      setWarning('visitor_valid_id','');
    });
  }
  if(btnClearId){
    btnClearId.addEventListener('click',function(){
      if(idInput){
        idInput.value='';
      }
      if(idPreviewWrap){
        idPreviewWrap.style.display='none';
      }
      setWarning('visitor_valid_id','Please upload Guest’s Valid ID.');
    });
  }

  if(verifyCancelBtn && verifyModal){
    verifyCancelBtn.addEventListener('click',function(){
      if(submittingGuest) return;
      verifyModal.style.display='none';
    });
  }

  function openGuestRefModal(){
    if(refModal){
      refModal.style.display='flex';
    }
  }
  if(refModalCloseBtn && refModal){
    refModalCloseBtn.addEventListener('click',function(){
      refModal.style.display='none';
      var reqNav=document.querySelector('.nav-menu .nav-item[data-section="panel-requests"]');
      if(reqNav) reqNav.click();
    });
  }

  function performGuestSubmit(){
    if(submittingGuest) return;
    submittingGuest=true;
    if(verifyConfirmBtn){
      verifyConfirmBtn.disabled=true;
    }
    var fd=new FormData(entryForm);
    fetch('submit_guest.php',{method:'POST',body:fd})
      .then(function(res){ return res.text().then(function(text){ return { res: res, text: text }; }); })
      .then(function(payload){
        var res = payload.res;
        var text = payload.text || '';
        var data = null;
        try { data = JSON.parse(text); } catch (e) { data = null; }
        if (!res.ok || !data) {
          setWarning('visitor_email', 'Server error. Please try again.');
          return;
        }
        if(data && data.success){
          openGuestRefModal();
        }else{
          var msg = data && data.message ? data.message : 'Failed to save guest.';
          if(msg.indexOf('Resident phone')!==-1) setWarning('resident_contact', msg);
          else if(msg.indexOf('Guest phone')!==-1) setWarning('visitor_contact', msg);
          else if(msg.indexOf('Resident name')!==-1) setWarning('resident_full_name', msg);
          else if(msg.indexOf('Guest name')!==-1) setWarning('visitor_first_name', msg);
          else if(msg.indexOf('valid ID')!==-1) setWarning('visitor_valid_id', msg);
          else if(msg.indexOf('Resident email')!==-1) setWarning('resident_email', msg);
          else if(msg.indexOf('Guest email')!==-1) setWarning('visitor_email', msg);
          else setWarning('visitor_email', msg);
        }
      })["catch"](function(){
        setWarning('visitor_email','Error connecting to server.');
      }).then(function(){
        submittingGuest=false;
        if(verifyConfirmBtn){
          verifyConfirmBtn.disabled=false;
        }
      });
  }

  if(verifyConfirmBtn){
    verifyConfirmBtn.addEventListener('click',function(){
      if(submittingGuest) return;
      if(!validateGuestForm()) return;
      if(verifyModal){
        verifyModal.style.display='none';
      }
      if(entryForm){
        performGuestSubmit();
      }
    });
  }

  if(entryForm){
    entryForm.addEventListener('submit',function(e){
      e.preventDefault();
      if(submittingGuest) return;
      if(!validateGuestForm()) return;
      buildVerifySummary();
    });
  }

  var lastDataSig = '';
  var pendingReload = false;
  function buildSig(list){
    if(!Array.isArray(list)) return '';
    return list.slice().sort(function(a,b){
      return String(a.ref_code||'').localeCompare(String(b.ref_code||''));
    }).map(function(it){
      return [it.ref_code||'', it.status||'', it.payment_status||'', it.attempts||''].join('|');
    }).join('||');
  }
  function hasVisibleModal(){
    var nodes = document.querySelectorAll('.modal,.update-proof-modal,.profile-modal,.account-blocked-modal,#qrWarningModal');
    for(var i=0;i<nodes.length;i++){
      var m = nodes[i];
      if(!m) continue;
      var ds = window.getComputedStyle ? window.getComputedStyle(m).display : (m.style && m.style.display);
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
  function refreshStatuses(){
    fetch('profileresident.php?ajax=1',{credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.success) return;
        var sig = buildSig(data.active) + '##' + buildSig(data.history) + '##' + String(data.account_status||'');
        if(lastDataSig && sig !== lastDataSig){
          pendingReload = true;
        }
        lastDataSig = sig;
        if(pendingReload && !hasVisibleModal() && !hasActiveInput()){
          location.reload();
          return;
        }
        var acct = String(data.account_status || '').toLowerCase();
        if (acct === 'denied' || acct === 'disabled') {
          if (typeof window.showAccountBlockedModal === 'function') {
            window.showAccountBlockedModal();
          }
          return;
        }
        
        function updateAndMove(list, panelId){
            var panel = document.getElementById(panelId);
            if(!panel) return;
            var container = panel.querySelector('.item-list');
            if(!container) return;
            var historyPanel = document.getElementById('panel-history');
            var historyList = historyPanel ? historyPanel.querySelector('.item-list') : null;
            
            // For history panel: DO NOT refresh - history items stay permanently until manually deleted
            if(panelId === 'panel-history') return;
            
            // Track which codes should exist in this panel
            var codesInResponse={};
            list.forEach(function(item){
              codesInResponse[String(item.ref_code||'')]=true;
            });
            
            list.forEach(function(item){
                var code = item.ref_code;
                var li = container.querySelector('.list-item[data-ref-code="'+code+'"]');
                if(li){
                    var reservedBy = item.reserved_by || '';
                    li.setAttribute('data-reserved-by', reservedBy);
                    if(item.payment_status !== undefined){
                      li.setAttribute('data-payment-status', item.payment_status || '');
                    }
                    if(item.scanned_at !== undefined){
                      li.setAttribute('data-scanned-at', item.scanned_at || '');
                    }
                    if(String(item.type||'').toLowerCase()==='report'){
                      li.setAttribute('data-report-id', item.report_id || '');
                      li.setAttribute('data-report-subject', item.subject || '');
                      li.setAttribute('data-report-address', item.address || '');
                      li.setAttribute('data-report-date', item.report_date || '');
                      li.setAttribute('data-report-nature', item.nature || '');
                      li.setAttribute('data-report-other', item.other_concern || '');
                    }
                    if(String(item.type||'').toLowerCase()==='guest_form'){
                      li.setAttribute('data-guest-name', item.guest_name || '');
                    }
                    var reservedEl = li.querySelector('.item-reserved-by');
                    if(item.type === 'reservation' && reservedBy){
                      if(!reservedEl){
                        reservedEl = document.createElement('div');
                        reservedEl.className = 'item-reserved-by';
                        reservedEl.style.cssText = 'font-size:0.8rem; color:#6b7280; margin-left: 48px;';
                        var refWrap = li.querySelector('.item-ref');
                        if(refWrap && refWrap.parentNode) refWrap.parentNode.insertBefore(reservedEl, refWrap.nextSibling);
                      }
                      reservedEl.textContent = 'Reserved by: ' + reservedBy;
                      reservedEl.style.display = '';
                    } else if(reservedEl) {
                      reservedEl.style.display = 'none';
                    }

                    // Update Data
                    var oldStatus = prevStatuses[code];
                    var newStatus = item.status;
                    var newStatusLower = String(newStatus||'').toLowerCase();
                    var hasScan = !!(item.scanned_at);
                    
                    li.setAttribute('data-status', newStatus);
                    
                    var shouldMoveHistory = newStatusLower.indexOf('cancel') !== -1 || newStatusLower.indexOf('expired') !== -1 || newStatusLower.indexOf('moved_to_history') !== -1 || newStatusLower.indexOf('permission_granted') !== -1;
                    if(panelId === 'panel-requests' && shouldMoveHistory && historyList){
                        var safeCode=String(code||'').replace(/"/g,'&quot;');
                        var existing=historyList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
                        if(existing){
                            li.remove();
                        } else {
                            var titleEl = li.querySelector('.item-title');
                            if(titleEl && item.title) titleEl.textContent = item.title;
                            var badge = li.querySelector('.status-badge');
                            if(badge){
                                if(newStatusLower.indexOf('moved_to_history') !== -1 || newStatusLower.indexOf('permission_granted') !== -1){
                                  badge.textContent = hasScan ? 'Access Granted' : 'Denied';
                                  badge.className = 'status-badge ' + statusClassFor(hasScan || newStatusLower.indexOf('permission_granted') !== -1 ? 'permission_granted' : 'denied');
                                } else {
                                  badge.textContent = fmtLabel(newStatus);
                                  badge.className = 'status-badge ' + statusClassFor(newStatus);
                                }
                            }
                            var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
                            if(noHistoryMsg) noHistoryMsg.remove();
                            historyList.insertBefore(li, historyList.firstChild);
                            li.style.display = '';
                            li.classList.remove('expanded');
                        }
                        if(container.querySelectorAll('.list-item').length===0){
                            var emptyMsg=container.querySelector('div[style*="text-align:center"]');
                            if(!emptyMsg){
                                var emptyDiv=document.createElement('div');
                                emptyDiv.style.cssText='padding:20px; text-align:center; color:#777;';
                                emptyDiv.textContent='No active requests.';
                                container.appendChild(emptyDiv);
                            }
                        }
                        return;
                    }
                    
                    // Update Visuals only if status changed
                    if(oldStatus !== newStatus){
                      var titleEl = li.querySelector('.item-title');
                      if(titleEl) titleEl.textContent = item.title;
                      
                      var badge = li.querySelector('.status-badge');
                      if(badge){
                          if(newStatusLower.indexOf('moved_to_history') !== -1 || newStatusLower.indexOf('permission_granted') !== -1){
                            badge.textContent = hasScan ? 'Access Granted' : 'Denied';
                            badge.className = 'status-badge ' + statusClassFor(hasScan || newStatusLower.indexOf('permission_granted') !== -1 ? 'permission_granted' : 'denied');
                          } else {
                            badge.textContent = fmtLabel(newStatus);
                            badge.className = 'status-badge ' + statusClassFor(newStatus);
                          }
                      }
                    }
                    
                    // Check if needs moving
                    var currentPanel = li.closest('.panel-section');
                    if(currentPanel && currentPanel.id !== panelId){
                         var noRecs = container.querySelector('div[style*="text-align:center"]');
                         if(noRecs) noRecs.remove();
                         
                         // Insert at top
                         container.insertBefore(li, container.firstChild);
                         li.style.display = ''; 
                    }
                    
                    // Ensure item stays visible if in response
                    li.style.display = '';
                    
                    prevStatuses[code] = newStatus;
                }
            });
            
            // Ensure all items in response stay visible
            var allItems=container.querySelectorAll('.list-item');
            allItems.forEach(function(item){
              var code=item.getAttribute('data-ref-code')||'';
              if(code && codesInResponse[code]){
                item.style.display='';
              }
            });
        }
        
        function esc(t){
          return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function moveHistoryItems(items){
          var activePanel = document.getElementById('panel-requests');
          var historyPanel = document.getElementById('panel-history');
          if(!activePanel||!historyPanel) return;
          var activeList = activePanel.querySelector('.item-list');
          var historyList = historyPanel.querySelector('.item-list');
          if(!activeList||!historyList) return;
          var historyCodes={};
          items.forEach(function(item){
            var code=String(item.ref_code||'');
            if(!code) return;
            historyCodes[code]=true;
            var safeCode=code.replace(/"/g,'&quot;');
            var existing=historyList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
            if(existing) return;
            var li=activeList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
            if(!li){
              var s=(String(item.status||'').toLowerCase());
              var hasScan = !!(item.scanned_at);
              var statusText=(s||'').replace(/[_-]+/g,' ').replace(/\b\w/g,function(m){return m.toUpperCase();});
              if(s.indexOf('moved_to_history')!==-1 || s.indexOf('permission_granted')!==-1) statusText = (hasScan || s.indexOf('permission_granted')!==-1) ? 'Access Granted' : 'Denied';
              var statusCls=statusClassFor((hasScan && s.indexOf('moved_to_history')!==-1) ? 'permission_granted' : (s.indexOf('permission_granted')!==-1 ? 'permission_granted' : (s||'cancelled')));
              var isReservation=(String(item.type||'').toLowerCase()==='reservation');
              var displayTitle=String(item.title||'');
              if(isReservation){
                var prefix='Reservation Schedule - ';
                if(displayTitle.toLowerCase().indexOf(prefix.toLowerCase())===0){
                  var rest=displayTitle.slice(prefix.length);
                  var parts=rest.split(' - ');
                  displayTitle=parts[0]?parts[0].trim():'Amenity';
                }
                displayTitle='Reservation – '+(displayTitle||'Amenity');
              }
              var createdText=formatNotifDateTime(item.date||'');
              li=document.createElement('div');
              li.className='list-item';
              li.setAttribute('data-ref-code',code);
              li.setAttribute('data-status',item.status||'cancelled');
              li.setAttribute('data-type',item.type||'reservation');
              if(item.scanned_at!==undefined){ li.setAttribute('data-scanned-at', item.scanned_at || ''); }
              if(item.payment_status!==undefined){ li.setAttribute('data-payment-status', item.payment_status||''); }
              var reservedBy=item.reserved_by||'';
              if(reservedBy){ li.setAttribute('data-reserved-by', reservedBy); }
              if(String(item.type||'').toLowerCase()==='report'){
                li.setAttribute('data-report-id', item.report_id || '');
                li.setAttribute('data-report-subject', item.subject || '');
                li.setAttribute('data-report-address', item.address || '');
                li.setAttribute('data-report-date', item.report_date || '');
                li.setAttribute('data-report-nature', item.nature || '');
                li.setAttribute('data-report-other', item.other_concern || '');
              }
              if(String(item.type||'').toLowerCase()==='guest_form'){
                li.setAttribute('data-guest-name', item.guest_name || '');
              }
              li.innerHTML='<div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>'
                +'<div class="item-content">'
                +  '<div class="item-row" style="display:flex; justify-content:space-between; margin-bottom:5px;">'
                +    '<div class="item-left">'
                +      '<span class="status-badge '+statusCls+'">'+statusText+'</span>'
                +      (isReservation?('<span class="item-amenity">'+esc(displayTitle)+'</span>'):('<span class="item-title">'+esc(displayTitle)+'</span>'))
                +    '</div>'
                +    '<div class="item-created">'+esc(createdText)+'</div>'
                +  '</div>'
                +  (isReservation && reservedBy ? ('<div style="font-size:0.8rem; color:#6b7280; margin-left: 48px;" class="item-reserved-by">Reserved by: '+esc(reservedBy)+'</div>') : '')
                +  '<div class="item-extra" data-loaded="0"></div>'
                +'</div>';
              li.addEventListener('click',function(e){
                if(e.target.closest('a') || e.target.closest('button')) return;
                li.classList.toggle('expanded');
                var extra=li.querySelector('.item-extra');
                if(extra && extra.getAttribute('data-loaded')!=='1' && li.classList.contains('expanded')){
                  buildExtraContent(li,extra);
                  extra.setAttribute('data-loaded','1');
                }
              });
            }
            li.setAttribute('data-status', item.status || 'cancelled');
            var reservedBy = item.reserved_by || '';
            li.setAttribute('data-reserved-by', reservedBy);
            if(item.payment_status !== undefined){
              li.setAttribute('data-payment-status', item.payment_status || '');
            }
            var reservedEl = li.querySelector('.item-reserved-by');
            if(item.type === 'reservation' && reservedBy){
              if(!reservedEl){
                reservedEl = document.createElement('div');
                reservedEl.className = 'item-reserved-by';
                reservedEl.style.cssText = 'font-size:0.8rem; color:#6b7280; margin-left: 48px;';
                var refWrap = li.querySelector('.item-ref');
                if(refWrap && refWrap.parentNode) refWrap.parentNode.insertBefore(reservedEl, refWrap.nextSibling);
                else {
                  var contentEl = li.querySelector('.item-content');
                  if(contentEl) contentEl.appendChild(reservedEl);
                }
              }
              reservedEl.textContent = 'Reserved by: ' + reservedBy;
              reservedEl.style.display = '';
            } else if(reservedEl) {
              reservedEl.style.display = 'none';
            }
            var titleEl = li.querySelector('.item-title');
            if(titleEl && item.title) titleEl.textContent = item.title;
            var badge = li.querySelector('.status-badge');
            if(badge){
              badge.textContent = fmtLabel(item.status);
              badge.className = 'status-badge ' + statusClassFor(item.status);
            }
            var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
            if(noHistoryMsg) noHistoryMsg.remove();
            historyList.insertBefore(li, historyList.firstChild);
            li.style.display = '';
            li.classList.remove('expanded');
          });
          var activeItems=activeList.querySelectorAll('.list-item');
          activeItems.forEach(function(li){
            var code=li.getAttribute('data-ref-code')||'';
            if(code && historyCodes[code]) li.remove();
          });
          if(activeList.querySelectorAll('.list-item').length===0){
            var emptyMsg=activeList.querySelector('div[style*="text-align:center"]');
            if(!emptyMsg){
              var emptyDiv=document.createElement('div');
              emptyDiv.style.cssText='padding:20px; text-align:center; color:#777;';
              emptyDiv.textContent='No active requests.';
              activeList.appendChild(emptyDiv);
            }
          }
        }
        
        if(data.active) updateAndMove(data.active, 'panel-requests');
        if(data.history) moveHistoryItems(data.history);
        
        if(Array.isArray(data.notifications)){
          var incoming = data.notifications.map(function(n){
            return {
              id: n.id,
              title: n.title||'',
              message: n.message||'',
              type: n.type||'info',
              is_read: n.is_read||0,
              created_at: n.created_at||''
            };
          });
          var newOnes=[];
          if(notifBootstrapped){
            var known = notifKnownIds;
            incoming.forEach(function(n){
              var idStr=String(n.id||'');
              if(idStr && !known[idStr]){
                newOnes.push(n);
              }
            });
          }
          notifKnownIds={};
          incoming.forEach(function(n){
            var idStr=String(n.id||'');
            if(idStr) notifKnownIds[idStr]=true;
          });
          notifBootstrapped=true;
          notifItems = dedupeNotifications(incoming);
          renderNotifPanel();
          if(newOnes.length){
            renderNotifPopup(dedupeNotifications(newOnes).slice(0,3));
          }
        }
        if(notifCountEl){
          var uc = parseInt(data.unread_count||'0',10);
          notifCountEl.textContent = uc;
          notifCountEl.style.display = uc > 0 ? 'inline-block' : 'none';
        }
      })["catch"](function(){});
  }
  refreshStatuses();
  setInterval(refreshStatuses,3000);
})();
</script>

<div class="modal" id="reportWaitModal" data-show="<?php echo $showReportWait ? '1' : '0'; ?>">
  <div class="modal-content report-wait-content">
    <button type="button" class="report-wait-close" aria-label="Close">&times;</button>
    <h3>Report Submitted</h3>
    <p><?php echo htmlspecialchars($reportWaitMessage); ?></p>
    <button type="button" class="btn-confirm report-wait-ok">OK</button>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  var reportModal = document.getElementById('reportWaitModal');
  if (!reportModal) return;
  var shouldShow = reportModal.getAttribute('data-show') === '1';
  var closeBtn = reportModal.querySelector('.report-wait-close');
  var okBtn = reportModal.querySelector('.report-wait-ok');
  function closeReportModal() { reportModal.style.display = 'none'; }
  if (shouldShow) { reportModal.style.display = 'flex'; }
  if (closeBtn) { closeBtn.addEventListener('click', closeReportModal); }
  if (okBtn) { okBtn.addEventListener('click', closeReportModal); }
  reportModal.addEventListener('click', function(e) {
    if (e.target === reportModal) closeReportModal();
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded',function(){
  var panel=document.querySelector('#panel-guest-form .booking-steps');
  var toggle=document.getElementById('bookingStepsToggle');
  if(panel&&toggle){
    toggle.addEventListener('click',function(){
      var collapsed=panel.classList.toggle('is-collapsed');
      toggle.textContent=collapsed?'+':'−';
      toggle.setAttribute('aria-expanded',collapsed?'false':'true');
    });
  }
});
</script>

<div class="account-blocked-modal" id="accountBlockedModal" data-show="<?php echo $isAccountBlocked ? '1' : '0'; ?>">
  <div class="account-blocked-content">
    <h3>Account Suspended</h3>
    <p>Your account is suspended and denied. Please log out.</p>
    <button type="button" class="btn-logout-only" id="accountBlockedLogoutBtn" data-logout-href="logout.php">Log Out</button>
  </div>
</div>

<div id="profileModal" class="profile-modal">
  <div class="profile-modal-content">
    <button class="close-profile-modal">&times;</button>
    <div class="profile-header">
      <div class="profile-icon-large">
        <img src="<?php echo $profilePicUrl; ?>" alt="Profile" id="profileModalImg">
        <label for="profileUpload" class="profile-edit-overlay" title="Change Profile Picture">
           <i class="fa-solid fa-camera"></i>
        </label>
        <input type="file" id="profileUpload" accept="image/*" style="display:none">
      </div>
      <div class="profile-title">
        <h3><?php echo htmlspecialchars($fullName); ?></h3>
        <span class="profile-role"><?php echo htmlspecialchars((isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'visitor') ? 'Visitor' : 'Resident'); ?></span>
      </div>
    </div>
    <div class="profile-details">
      <div class="detail-row">
        <div class="detail-label">Name</div>
        <div class="detail-value"><?php echo htmlspecialchars($fullName); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Email</div>
        <div class="detail-value"><?php echo htmlspecialchars($user['email'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Contact Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">House Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($user['house_number'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Address</div>
        <div class="detail-value"><?php echo htmlspecialchars($user['address'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Change Password</div>
        <div class="detail-value" style="width:100%;">
          <button type="button" id="openChangePasswordResident" style="background:#23412e; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600;">Change Password</button>
        </div>
      </div>
    </div>
    <div class="profile-actions">
       <a href="logout.php" class="btn-logout-modal">Log Out</a>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var accountModal = document.getElementById('accountBlockedModal');
    if (accountModal && accountModal.getAttribute('data-show') === '1') {
        if (typeof window.showAccountBlockedModal === 'function') {
            window.showAccountBlockedModal();
        } else {
            accountModal.style.display = 'flex';
            document.body.classList.add('account-blocked');
        }
    }
    var profileModal = document.getElementById("profileModal");
    var profileTrigger = document.getElementById("profileTrigger");
    var profileClose = document.getElementsByClassName("close-profile-modal")[0];

    if(profileTrigger) {
        profileTrigger.onclick = function(e) {
            e.preventDefault();
            profileModal.style.display = "block";
        }
    }

    if(profileClose) {
        profileClose.onclick = function() {
            profileModal.style.display = "none";
        }
    }

    window.onclick = function(event) {
        if (event.target == profileModal) {
            profileModal.style.display = "none";
        }
    }

    // Profile Picture Upload
    var profileUpload = document.getElementById('profileUpload');
    if(profileUpload) {
        profileUpload.addEventListener('change', function() {
            if (this.files && this.files[0]) {
                var formData = new FormData();
                formData.append('profile_pic', this.files[0]);

                var img = document.getElementById('profileModalImg');
                img.style.opacity = '0.5';

                fetch('upload_profile_pic.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    img.style.opacity = '1';
                    if (data.success) {
                        if(img) img.src = data.new_url;
                        var headerImg = document.getElementById('headerProfileImg');
                        if(headerImg) headerImg.src = data.new_url;
                    } else {
                        alert(data.message || 'Upload failed');
                    }
                })
                .catch(error => {
                    img.style.opacity = '1';
                    console.error('Error:', error);
                    alert('An error occurred during upload.');
                });
            }
        });
    }
});
</script>
<div id="changePasswordModalResident" class="profile-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; z-index:3000;">
  <div class="vp-logout-modal" style="position:relative; top:auto; right:auto; margin:0; width:350px; max-width:90vw; max-height:calc(100vh - 100px);">
    <button class="close-change-password" style="position:absolute; right:12px; top:10px; background:transparent; border:none; font-size:20px; cursor:pointer;">&times;</button>
    <div class="change-password-title">Change Password</div>
    <?php if ($pwdMsg !== '') { ?>
      <div style="margin-bottom:12px; padding:10px 12px; border-radius:8px; font-size:0.9rem; <?php echo $pwdOk ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca'; ?>">
        <?php echo htmlspecialchars($pwdMsg); ?>
      </div>
    <?php } ?>
    <form method="post" action="profileresident.php" style="display:grid; grid-template-columns:1fr; gap:8px; max-width:420px;">
      <input type="hidden" name="change_password" value="1">
      <input type="password" name="current_password" placeholder="Current Password" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <input type="password" name="new_password" placeholder="New Password (min 8 chars)" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <input type="password" name="confirm_password" placeholder="Confirm New Password" required style="padding:10px 12px; border:1px solid #d1d5db; border-radius:8px;">
      <button type="submit" style="background:#23412e; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600;">Update Password</button>
    </form>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
  var m = document.getElementById('changePasswordModalResident');
  var openBtn = document.getElementById('openChangePasswordResident');
  var closeBtn = document.querySelector('#changePasswordModalResident .close-change-password');
  if (openBtn && m) {
    openBtn.onclick = function(){ m.style.display = 'flex'; };
  }
  if (closeBtn && m) {
    closeBtn.onclick = function(){ m.style.display = 'none'; };
  }
  window.addEventListener('click', function(e){
    if (e.target === m) { m.style.display = 'none'; }
  });
  <?php if ($pwdMsg !== '') { echo "if(m){ m.style.display='flex'; }"; } ?>
});
</script>
<script src="js/logout-modal.js"></script>
<script>
// Global functions for incident report details modal (moved from get_report_details.php)
function addProofs(reportId){
  var input = document.getElementById('addProofInput');
  if(!input || !input.files || input.files.length===0){ alert('Select files to upload.'); return; }
  var fd = new FormData();
  fd.append('action','add_proof');
  fd.append('report_id', String(reportId));
  for(var i=0;i<input.files.length;i++){ fd.append('proof[]', input.files[i]); }
  
  // Optional: Provide feedback
  var btn = document.querySelector('#activityModalBody button[onclick*="addProofs"]');
  if(btn) { btn.disabled = true; btn.innerText = 'Uploading...'; }

  fetch('submit_report.php',{ method:'POST', body: fd })
    .then(function(r){ return r.json(); })
    .then(function(d){
      if(!d || !d.success){ 
        alert(d && d.message ? d.message : 'Upload failed'); 
        if(btn) { btn.disabled = false; btn.innerText = 'Upload'; }
        return; 
      }
      location.reload();
    })
    .catch(function(){ 
      alert('Upload failed'); 
      if(btn) { btn.disabled = false; btn.innerText = 'Upload'; }
    });
}
function removeProof(reportId, proofId){
  if(!confirm('Are you sure you want to remove this proof?')) return;
  var body = new URLSearchParams({ action:'delete_proof', report_id:String(reportId), proof_id:String(proofId) });
  fetch('submit_report.php',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body })
    .then(function(r){ return r.json(); })
    .then(function(d){ if(!d || !d.success){ alert('Remove failed'); return; } location.reload(); })
    .catch(function(){ alert('Remove failed'); });
}
function replaceProof(reportId, proofId){
  var tmp = document.createElement('input');
  tmp.type = 'file';
  tmp.accept = '.jpg,.jpeg,.png,.pdf,.doc,.docx';
  tmp.onchange = function(){
    if(!tmp.files || tmp.files.length===0) return;
    var fd = new FormData();
    fd.append('action','add_proof');
    fd.append('report_id', String(reportId));
    fd.append('proof[]', tmp.files[0]);
    fetch('submit_report.php',{ method:'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        if(!d || !d.success){ alert(d && d.message ? d.message : 'Replace failed'); return; }
        var body = new URLSearchParams({ action:'delete_proof', report_id:String(reportId), proof_id:String(proofId) });
        return fetch('submit_report.php',{ method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body: body });
      })
      .then(function(r){ if(!r) return; return r.json(); })
      .then(function(d2){ location.reload(); })
      .catch(function(){ alert('Replace failed'); });
  };
  tmp.click();
}
</script>
  <script>
    (function(){
      var sseUrl = '<?php echo rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass')), '/') . '/api_resident/ecopoint_sse.php'; ?>';
      var statusEl = document.getElementById('ecopoint-live-status');
      var weightEl = document.getElementById('ecopoint-live-weight');
      var pointsEl = document.getElementById('ecopoint-live-points');
      var lastSeenSessionId = null;
      var lastSeenSessionFinalStatus = null;
      var lastRenderedCap = null;

      function fmtKg(w){
        w = parseFloat(w || 0);
        if(isNaN(w)) w = 0;
        return w.toFixed(2) + ' kg';
      }
      function fmtPts(p){
        p = parseInt(p || 0, 10);
        if(isNaN(p)) p = 0;
        return p + ' pts';
      }
      function statusLabel(s){
        switch(String(s || '').toUpperCase()){
          case 'WAITING':    return 'Waiting for deposit — station verified your QR';
          case 'ACTIVE':     return 'Session active — station verified your QR';
          case 'PROCESSING': return 'Recycling in progress — station is weighing your deposit';
          case 'COMPLETED':  return 'Session complete — points credited <i class="fa-solid fa-circle-check" aria-hidden="true"></i>';
          case 'CANCELLED':  return 'Session cancelled (station aborted)';
          case 'ERROR':      return 'Session error — see station admin if needed';
          default:           return 'No active session';
        }
      }
      function render(snap){
        if (!snap || !snap.success) return;

        // Weekly cap live bar text (in KPI header, update card subtitle if element exists)
        if (snap.cap_state) {
          var usedW = parseInt(snap.cap_state.weekly_points_used  || 0, 10);
          var maxW  = parseInt(<?php echo (int)(ECO_WEEKLY_POINT_CAP ?? 250); ?>, 10);
          var noteEl = document.getElementById('ecopoint-weekly-cap-note');
          if (noteEl) {
            noteEl.textContent = 'Weekly: ' + usedW + ' / ' + maxW + ' pts — ' +
              (snap.cap_state.weekly_reset_note || 'Resets every Monday 12:00 AM');
          }
          // Update any dashboard-level balance texts with snap.current_balance
          var bal = parseInt(snap.current_balance || 0, 10);
          var balEls = document.querySelectorAll('[data-ecopoint-balance]');
          balEls.forEach(function(el){ el.textContent = String(bal); });
          lastRenderedCap = snap.cap_state;
        }

        var s = snap.active_session;
        if (!s) {
          statusEl.textContent = 'No active session';
          weightEl.textContent = '0.00 kg';
          pointsEl.textContent = '0 pts';
          return;
        }
        statusEl.innerHTML = statusLabel(s.status);
        weightEl.textContent = fmtKg(s.total_weight_kg && parseFloat(s.total_weight_kg) > 0 ? s.total_weight_kg : s.weight_kg);
        // For active/processing, show *calculated* points so user sees progress;
        // for completed, show final awarded points.
        var statusUp = String(s.status || '').toUpperCase();
        if (statusUp === 'COMPLETED') {
          pointsEl.textContent = fmtPts(s.points_awarded || s.total_points);
        } else {
          pointsEl.textContent = fmtPts(s.points_calculated);
        }
        // If a session just became final, reload the page once to refresh the
        // history/KPI cards on the dashboard.
        if (lastSeenSessionId !== null && String(s.id) === String(lastSeenSessionId)) {
          var isFinal = (statusUp === 'COMPLETED' || statusUp === 'CANCELLED' || statusUp === 'ERROR');
          if (isFinal && lastSeenSessionFinalStatus !== statusUp) {
            lastSeenSessionFinalStatus = statusUp;
            setTimeout(function(){ window.location.reload(); }, 1500);
          }
        }
        if (lastSeenSessionId === null || String(s.id) !== String(lastSeenSessionId)) {
          lastSeenSessionId = String(s.id);
          var up = String(s.status || '').toUpperCase();
          lastSeenSessionFinalStatus =
            (up === 'COMPLETED' || up === 'CANCELLED' || up === 'ERROR') ? up : null;
        }
      }

      // 1) First snapshot immediately over regular fetch (avoid waiting for SSE first frame)
      var firstSnapUrl = '<?php echo rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass')), '/') . '/api_resident/ecopoint_session_status.php'; ?>';
      try {
        fetch(firstSnapUrl, { credentials: 'same-origin', cache: 'no-store' })
          .then(function(r){ return r.json(); })
          .then(render)
          .catch(function(){});
      } catch(e){}

      // 2) Open persistent SSE (real-time push; reconnects automatically on drop)
      if (typeof(EventSource) !== 'undefined') {
        var es = new EventSource(sseUrl, { withCredentials: true });
        es.addEventListener('snapshot', function(ev){
          try {
            var snap = JSON.parse(ev.data);
            render(snap);
          } catch(err) {}
        });
        es.addEventListener('error', function(){
          // Browser's EventSource automatically reconnects per SSE spec; no work needed.
          // As a safety net, fall back to polling once every 4s while waiting for reconnect.
        });
        es.addEventListener('end_of_stream', function(){
          // PHP SSE ended (~4:40) — browser's EventSource will reconnect automatically
        });
      } else {
        // Fallback (very old browsers): regular polling every 2.5s
        setInterval(function(){
          try {
            fetch(firstSnapUrl, { credentials: 'same-origin', cache: 'no-store' })
              .then(function(r){ return r.json(); })
              .then(render)
              .catch(function(){});
          } catch(e){}
        }, 2500);
      }
    })();
  </script>
<script src="js/ecopoint_dashboard.js"></script>
</body>
</html>
