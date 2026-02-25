<?php
session_start();
require_once 'connect.php';

// Redirect if not logged in or not a visitor
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'visitor') {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$user_data = [];

if ($con) {
    $hasSuspension = false;
    $chk = $con->query("SHOW COLUMNS FROM users LIKE 'suspension_reason'");
    if ($chk && $chk->num_rows > 0) { $hasSuspension = true; }
    $selectCols = "first_name, last_name, email, phone, sex, birthdate, status";
    if ($hasSuspension) { $selectCols .= ", suspension_reason"; }
    $stmt = $con->prepare("SELECT ".$selectCols." FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) {
        $user_data = $res->fetch_assoc();
    }
    $stmt->close();
}
$firstName = $user_data['first_name'] ?? 'Visitor';
$fullName = trim(($user_data['first_name'] ?? '') . ' ' . ($user_data['last_name'] ?? ''));
$birthdate = $user_data['birthdate'] ?? '';
$birthdateDisplay = $birthdate ? date('m/d/y', strtotime($birthdate)) : '';
$userStatus = $user_data['status'] ?? 'pending';
$normalizedUserStatus = strtolower(trim($userStatus));
$isAccountBlocked = in_array($normalizedUserStatus, ['denied', 'disabled'], true);
$suspensionReason = trim((string)($user_data['suspension_reason'] ?? ''));
$flashNotice = $_SESSION['flash_notice'] ?? '';
if ($flashNotice !== '') {
    unset($_SESSION['flash_notice'], $_SESSION['flash_ref_code']);
}

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
                $stmtP->bind_param('i', $user_id);
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
                            $ok = $con->query("UPDATE users SET `password` = '".$esc."' WHERE id = ".intval($user_id));
                            if ($ok) {
                                $pwdOk = true;
                                $pwdMsg = 'Your password has been updated.';
                            } else {
                                $pwdMsg = 'Database error: ' . $con->error;
                            }
                        } else {
                            $updP->bind_param('si', $newHash, $user_id);
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

// Profile Picture Logic
$profilePicPath = 'images/mainpage/profile\'.jpg'; // Default
if (file_exists('uploads/profiles/user_' . $user_id . '.jpg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpg';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.png')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.png';
} elseif (file_exists('uploads/profiles/user_' . $user_id . '.jpeg')) {
    $profilePicPath = 'uploads/profiles/user_' . $user_id . '.jpeg';
}
$profilePicUrl = $profilePicPath . '?t=' . time();

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

ensureNotificationsTable($con);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_notifications_read') {
    header('Content-Type: application/json');
    if ($con instanceof mysqli) {
        $stmt = $con->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    echo json_encode(['success' => true]);
    exit;
}

// Fetch Activities
$activities = [];

// Reservations
if ($con instanceof mysqli) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'denial_reason'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN denial_reason TEXT NULL");
    }
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'receipt_attempts'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN receipt_attempts INT NULL DEFAULT 0");
    }
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'scanned_at'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN scanned_at DATETIME NULL");
    }
}
$stmt = $con->prepare("SELECT 'reservation' as type, amenity, start_date, end_date, start_time, end_time, status, approval_status, payment_status, denial_reason, receipt_attempts, created_at, ref_code, scanned_at FROM reservations WHERE user_id = ? AND status <> 'deleted' AND approval_status <> 'deleted' ORDER BY created_at DESC");
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $start = $row['start_date'];
        $end = $row['end_date'] ?? null;
        $sTime = strtotime($row['start_time'] ?? '');
        $eTime = strtotime($row['end_time'] ?? '');
        
        $dateStr = '';
        if (!empty($start)) {
            $startText = date('m/d/y', strtotime($start));
            $endText = !empty($end) ? date('m/d/y', strtotime($end)) : $startText;
            $dateStr = $startText . ' - ' . $endText;
        } else {
            $dateStr = 'Date not set';
        }
        
        if ($sTime && $eTime) {
            $dateStr .= ' ' . date('g:i A', $sTime) . ' - ' . date('g:i A', $eTime);
        }
        
        $statusVal = $row['approval_status'] ?? '';
        if ($statusVal === '' || $statusVal === null) {
            $statusVal = $row['status'] ?? 'pending';
        }
        $paymentStatusLower = strtolower((string)($row['payment_status'] ?? ''));
        if ($paymentStatusLower === 'rejected') {
            $svLower = strtolower((string)$statusVal);
            if (strpos($svLower, 'moved_to_history') === false && strpos($svLower, 'permission_granted') === false) {
                $atts = intval($row['receipt_attempts'] ?? 0);
                $statusVal = ($atts >= 3) ? 'denied' : 'rejected';
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
        if (stripos($statusVal, 'cancel') !== false) {
            $resTitle .= ' - Cancelled';
        }

        // Calculate event timestamp for history sorting
        $eventTs = 0;
        if ($eTime) {
            $eventTs = $eTime; // If end time is set
            // If end date is set, combine
             if ($end) {
                $eventTs = strtotime($end . ' ' . ($row['end_time'] ?? '23:59:59'));
            } elseif ($start) {
                $eventTs = strtotime($start . ' ' . ($row['end_time'] ?? '23:59:59'));
            }
        } else {
             // Fallback to end of day of start date
             if ($start) $eventTs = strtotime($start . ' 23:59:59');
             else $eventTs = strtotime($row['created_at']);
        }

        $reason = trim((string)($row['denial_reason'] ?? ''));
        $statusLower = strtolower((string)($statusVal ?? ''));
        $isDenied = (strpos($statusLower, 'denied') !== false || strpos($statusLower, 'rejected') !== false);
        if (strtolower((string)($row['payment_status'] ?? '')) === 'rejected') { $isDenied = true; }
        if ($isDenied && $reason !== '') {
            $dateStr = trim($dateStr . ' Reason: ' . $reason);
        }
        $activities[] = [
            'type' => 'reservation',
            'title' => $resTitle,
            'details' => $dateStr,
            'status' => $statusVal,
            'date' => $row['created_at'],
            'event_timestamp' => $eventTs,
            'ref_code' => $row['ref_code'] ?? 'RES',
            'payment_status' => $row['payment_status'] ?? null,
            'attempts' => intval($row['receipt_attempts'] ?? 0),
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
    if (strpos($s, 'deleted') !== false) continue;

    $isHistory = false;

    if (strpos($s, 'cancel') !== false || strpos($s, 'complete') !== false || strpos($s, 'finish') !== false || strpos($s, 'moved_to_history') !== false) {
        $isHistory = true;
    }

    if ($isHistory) {
        $historyActivities[] = $act;
    } else {
        $activeActivities[] = $act;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
    header('Content-Type: application/json');
    $notifications = getUserNotifications($con, $user_id, 20);
    $unreadCount = getUserUnreadNotificationCount($con, $user_id);
    echo json_encode([
        'success' => true, 
        'active' => $activeActivities, 
        'history' => $historyActivities,
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
<title>Visitor Dashboard - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<link rel="stylesheet" href="css/dashboard.css">
<!-- FontAwesome for icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  /* Fix for Visitor Dashboard Modal to ensure it fits screen and close button is visible */
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
  #submitNoticeModal { display: flex; align-items: center; justify-content: center; }
  #submitNoticeModal .modal-content { width: 92%; max-width: 420px; padding: 24px; text-align: center; height: auto; min-height: unset; }
  #submitNoticeModal .close { position: absolute; top: 12px; right: 12px; width: 32px; height: 32px; border-radius: 50%; background: #eef2f0; color: #23412e; display: flex; align-items: center; justify-content: center; }
  body.account-blocked { overflow: hidden; }
  .account-blocked-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.75); align-items: center; justify-content: center; z-index: 3000; }
  .account-blocked-content { background: #fff; border-radius: 14px; padding: 28px 30px; width: 92%; max-width: 420px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.25); }
  .account-blocked-content h3 { margin: 0 0 10px; color: #a83b3b; font-size: 1.2rem; }
  .account-blocked-content p { margin: 0 0 20px; color: #333; font-size: 0.95rem; line-height: 1.5; }
  .account-blocked-content .btn-logout-only { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 18px; border-radius: 8px; background: #c0392b; color: #fff; text-decoration: none; font-weight: 600; border: 0; cursor: pointer; }
  .account-blocked-content .btn-logout-only:hover { filter: brightness(0.95); }
</style>
<style>.note-error{color:#b91c1c;font-weight:700;}</style>
<style>
.item-extra-link.item-extra-cancel{background:#ef4444;color:#ffffff;border:1px solid #ef4444;padding:8px 16px;border-radius:50px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;font-weight:500;text-decoration:none}
.item-extra-link.item-extra-cancel:hover{background:#dc2626;color:#ffffff;transform:translateY(-2px);box-shadow:0 4px 6px rgba(239, 68, 68, 0.2);text-decoration:none}
.cancel-modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:nowrap;padding:6px 0 0 0}
.cancel-modal-actions .cancel-modal-keep,.cancel-modal-actions .cancel-modal-confirm{padding:10px 20px;border-radius:10px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;border:0}
.cancel-modal-actions .cancel-modal-keep{background:#e5e7eb;color:#111827}
.cancel-modal-actions .cancel-modal-confirm{background:#c0392b;color:#fff}
.cancel-modal-content{width:90%;max-width:450px;padding:30px;border-radius:18px}
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
    <div class="modal-content" style="max-width:420px;text-align:center;padding:24px;">
      <span class="close" id="submitNoticeClose">&times;</span>
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
      <a href="#" class="nav-item active" data-section="panel-requests"><i class="fa-solid fa-list"></i> <span>My Requests</span></a>
      <a href="reserve.php" class="nav-item"><i class="fa-solid fa-ticket"></i> <span>Amenity Reservation</span></a>
      <a href="#" class="nav-item" data-section="panel-history"><i class="fa-solid fa-clock-rotate-left"></i> <span>History</span></a>
    </nav>

    <div class="sidebar-footer">
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fa-solid fa-right-from-bracket"></i> <span>Log Out</span></a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main-content">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <!-- Top Header -->
    <header class="top-header">
      <div class="header-brand">
        <button class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></button>
        <a href="mainpage.php" aria-label="Go to Main Page"><img src="images/logo.svg" alt="Logo"></a>
        <div class="brand-text">
          <span class="brand-main">VictorianPass</span>
          <span class="brand-sub">Victorian Heights Subdivision</span>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" id="notifBtn"><i class="fa-regular fa-bell"></i><span id="notifCount" class="notif-count" style="display:none;">0</span></button>
        <div id="notifPanel" class="notif-panel" style="display:none;"></div>
        <div id="notifPopup" class="notif-popup" style="display:none;"></div>
        <a href="#" class="user-profile" id="profileTrigger">
          <span class="user-name">Hi, <?php echo htmlspecialchars($firstName); ?></span>
          <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="user-avatar" id="headerProfileImg">
        </a>
      </div>
    </header>

    <div class="content-wrapper">
      <div class="right-panel">
        
        <!-- ACTIVE REQUESTS PANEL -->
        <div class="panel-section" id="panel-requests">
          <div class="activity-list-header">
            <div>My Requests</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search by code or keyword" class="request-search" data-target="active">
            </div>
          </div>

          <div class="item-list" id="list-active">
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
                    $amenityName = $displayTitle;
                    if (strcasecmp($amenityName, 'Pool') === 0) { $amenityName = 'Community Pool'; }
                    $displayTitle = 'Reservation – ' . $amenityName;
                  }
                  $createdText = date('m/d/y g:i A', strtotime($act['date']));
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>" data-schedule="<?php echo htmlspecialchars($scheduleText); ?>" data-reason="<?php echo htmlspecialchars($reasonText); ?>" data-attempts="<?php echo isset($act['attempts']) ? intval($act['attempts']) : 0; ?>" data-scanned-at="<?php echo htmlspecialchars($act['scanned_at'] ?? ''); ?>">
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
                        <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                      <?php endif; ?>
                     </div>
                     <div class="item-created"><?php echo htmlspecialchars($createdText); ?></div>
                   </div>
                   <?php if (!$isReservation): ?>
                   <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                     <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                   </div>
                   <?php endif; ?>
                   <div class="item-extra" data-loaded="0"></div>
                 </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- HISTORY PANEL -->
        <div class="panel-section" id="panel-history" style="display:none;">
          <div class="activity-list-header">
            <div>History</div>
            <div class="search-bar">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" placeholder="Search history..." class="request-search" data-target="history">
            </div>
          </div>

          <div class="item-list" id="list-history">
            <?php if (empty($historyActivities)): ?>
              <div style="padding:20px; text-align:center; color:#777;">No history records.</div>
            <?php else: ?>
              <?php foreach ($historyActivities as $act):
                  $statusClass = 'status-pending';
                  $s = strtolower($act['status']);
                  $hasScan = !empty($act['scanned_at']);
                  if (strpos($s, 'permission')!==false || strpos($s, 'granted')!==false || ($hasScan && strpos($s,'moved_to_history')!==false)) $statusClass = 'status-access-granted';
                  elseif (strpos($s, 'approv')!==false || strpos($s, 'resolved')!==false || strpos($s, 'ongoing')!==false) $statusClass = 'status-approved';
                  elseif (strpos($s, 'denied')!==false || strpos($s, 'reject')!==false || strpos($s, 'moved_to_history')!==false) $statusClass = 'status-denied';
                  elseif (strpos($s, 'cancel')!==false) $statusClass = 'status-cancelled';
                  elseif (strpos($s, 'expired')!==false) $statusClass = 'status-denied';
                  $displayStatus = ucwords(str_replace('_',' ', (string)$act['status']));
                  if (strpos($s, 'permission_granted') !== false) $displayStatus = 'Access Granted';
                  if (strpos($s, 'moved_to_history') !== false) $displayStatus = $hasScan ? 'Access Granted' : 'Denied';
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
                    $amenityName = $displayTitle;
                    if (strcasecmp($amenityName, 'Pool') === 0) { $amenityName = 'Community Pool'; }
                    $displayTitle = 'Reservation – ' . $amenityName;
                  }
                  $createdText = date('m/d/y g:i A', strtotime($act['date']));
              ?>
              <div class="list-item" data-ref-code="<?php echo htmlspecialchars($act['ref_code']); ?>" data-status="<?php echo htmlspecialchars($act['status']); ?>" data-type="<?php echo htmlspecialchars($act['type']); ?>" data-payment-status="<?php echo htmlspecialchars($act['payment_status'] ?? ''); ?>" data-schedule="<?php echo htmlspecialchars($scheduleText); ?>" data-reason="<?php echo htmlspecialchars($reasonText); ?>" data-attempts="<?php echo isset($act['attempts']) ? intval($act['attempts']) : 0; ?>" data-scanned-at="<?php echo htmlspecialchars($act['scanned_at'] ?? ''); ?>">
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
                         <span class="item-details">- <?php echo htmlspecialchars($act['details']); ?></span>
                       <?php endif; ?>
                     </div>
                     <div class="item-created"><?php echo htmlspecialchars($createdText); ?></div>
                   </div>
                   <div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref">
                     <span><?php echo htmlspecialchars($act['ref_code']); ?></span>
                   </div>
                   <div class="item-extra" data-loaded="0"></div>
                 </div>
              </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </main>
  </div>
</div>

<div id="activityModal" class="modal">
  <div class="modal-content">
    <span class="close">&times;</span>
    <div id="activityModalBody"></div>
  </div>
</div>
<div id="qrWarningModal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; z-index:3500;">
  <div style="background:#fff; border-radius:12px; padding:22px 20px; width:360px; max-width:92vw; box-shadow:0 12px 30px rgba(0,0,0,0.25); text-align:center;">
  <div style="font-weight:700; color:#23412e; font-size:1.05rem; margin-bottom:8px;">Warning</div>
  <div id="qrWarningMessage" style="font-size:0.9rem; color:#444; line-height:1.5;">Do not scan. Authorized guards only.</div>
    <div style="display:flex; gap:10px; justify-content:center; margin-top:16px;">
      <button type="button" id="qrWarningCancel" style="background:#e5e7eb; color:#111827; border:none; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Cancel</button>
      <button type="button" id="qrWarningProceed" style="background:#23412e; color:#fff; border:none; padding:8px 14px; border-radius:8px; font-weight:600; cursor:pointer;">Proceed</button>
    </div>
  </div>
</div>

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
    <div class="update-proof-hint">JPG, PNG, PDF</div>
    <div id="updateProofFileName" class="update-proof-file-name">No file selected</div>
    <div id="updateProofPreview" class="update-proof-preview" style="display:none; margin-top:10px;"></div>
    <div class="update-proof-actions">
      <button type="button" id="updateProofEditBtn" class="update-proof-btn update-proof-edit">Edit</button>
      <button type="button" id="updateProofRemoveBtn" class="update-proof-btn update-proof-remove" disabled>Remove</button>
      <button type="button" id="updateProofSubmitBtn" class="update-proof-btn update-proof-submit" disabled>Submit</button>
    </div>
  </div>
</div>

<div class="account-blocked-modal" id="accountBlockedModal" data-show="<?php echo $isAccountBlocked ? '1' : '0'; ?>">
  <div class="account-blocked-content">
    <h3>Account Suspended</h3>
    <p>Your account is suspended. Please log out.</p>
    <?php if ($suspensionReason !== '') { ?>
      <p>Reason: <?php echo htmlspecialchars($suspensionReason); ?></p>
    <?php } ?>
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
        <span class="profile-role"><?php echo htmlspecialchars((isset($_SESSION['user_type']) && strtolower($_SESSION['user_type']) === 'resident') ? 'Resident' : 'Visitor'); ?></span>
      </div>
    </div>
    <div class="profile-details">
      <div class="detail-row">
        <div class="detail-label">Name</div>
        <div class="detail-value"><?php echo htmlspecialchars($fullName); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Email</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['email'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Contact Number</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['phone'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Sex</div>
        <div class="detail-value"><?php echo htmlspecialchars($user_data['sex'] ?? ''); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Birthdate</div>
        <div class="detail-value"><?php echo htmlspecialchars($birthdateDisplay); ?></div>
      </div>
      <div class="detail-row">
        <div class="detail-label">Change Password</div>
        <div class="detail-value" style="width:100%;">
          <button type="button" id="openChangePasswordVisitor" style="background:#23412e; color:#fff; border:none; padding:10px 14px; border-radius:8px; cursor:pointer; font-weight:600;">Change Password</button>
        </div>
      </div>
    </div>
    <div class="profile-actions">
       <a href="logout.php" class="btn-logout-modal">Log Out</a>
    </div>
  </div>
</div>

<!-- Hidden Pass Template for Generation -->
<div id="hiddenPassCard" style="position:fixed; left:-9999px; top:0; width:400px; background:#1e1e1e; color:#fff; font-family:'Poppins',sans-serif; border-radius:16px; overflow:hidden; padding-bottom:20px;">
    <div class="header" style="background:#000; padding:15px; text-align:center; border-bottom:1px solid #333;">
        <img src="images/logo.svg" style="height:32px; vertical-align:middle;">
        <span style="margin-left:10px; font-weight:600; font-size:1.1rem; color:#e5ddc6; vertical-align:middle;">Victorian Heights</span>
    </div>
    <div class="status-banner" style="padding:30px 20px; text-align:center; background-color:#22c55e; color:#000;">
        <div style="font-size:1.8rem; font-weight:800; text-transform:uppercase; margin-bottom:8px;">VALID ENTRY PASS</div>
        <div style="font-size:1rem; font-weight:500;">Access Granted</div>
    </div>
    <div class="qr-section" style="background:#fff; padding:20px; text-align:center;">
        <img id="passQR" src="" style="width:180px; height:180px; display:block; margin:0 auto;">
        <div style="color:#333; margin-top:10px; font-size:0.85rem; font-weight:500;">Present this QR code to the guard</div>
    </div>
    <div class="details" style="padding:24px;">
        <div style="margin-bottom:12px; font-size:0.85rem; color:#9bd08f; font-weight:700; text-transform:uppercase;">Pass Details</div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Pass Type</span>
            <span style="font-weight:600; font-size:1rem; color:#eee;">Visitor</span>
        </div>
        <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Name</span>
            <span id="passName" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #333; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Ref Code</span>
            <span id="passRef" style="font-weight:600; font-size:1rem; color:#eee; font-family:monospace;"></span>
        </div>
            <div style="display:flex; justify-content:space-between; margin-bottom:16px; border-bottom:0; padding-bottom:8px;">
            <span style="color:#aaa; font-size:0.9rem;">Validity</span>
            <span id="passDate" style="font-weight:600; font-size:1rem; color:#eee;"></span>
        </div>
    </div>
    <div style="text-align:center; color:#666; font-size:0.8rem; margin-top:20px;">
        VictorianPass Validation System &copy; <?php echo date('Y'); ?>
    </div>
</div>

<script>
(function(){
  var visitorName = "<?php echo htmlspecialchars($fullName); ?>";
  
  // Tab Switching
  var navItems = document.querySelectorAll('.nav-item[data-section]');
  var sections = document.querySelectorAll('.panel-section');
  
  navItems.forEach(function(item) {
      item.addEventListener('click', function(e) {
          e.preventDefault();
          var sectionId = this.getAttribute('data-section');
          
          // Update Nav
          navItems.forEach(function(n) { n.classList.remove('active'); });
          this.classList.add('active');
          
          // Update Sections
          sections.forEach(function(s) { s.style.display = 'none'; });
          var target = document.getElementById(sectionId);
          if (target) target.style.display = 'block';
      });
  });

  // Search Logic
  var searchInputs = document.querySelectorAll('.request-search');
  searchInputs.forEach(function(inp){
    inp.addEventListener('input', function(){
        var targetType = this.getAttribute('data-target'); // active or history
        var listId = 'list-' + targetType;
        var listContainer = document.getElementById(listId);
        if(!listContainer) return;
        
        var q = (this.value || '').toLowerCase();
        var items = listContainer.querySelectorAll('.list-item');
        
        items.forEach(function(li){
            var text = li.textContent.toLowerCase();
            li.style.display = text.indexOf(q) !== -1 ? '' : 'none';
        });
    });
  });

  var prevStatuses={};
  document.querySelectorAll('.item-list .list-item').forEach(function(li){
    var code=li.getAttribute('data-ref-code')||'';
    var st=li.getAttribute('data-status')||'';
    if(code) prevStatuses[code]=st;
    
    // Expand/Collapse click
    li.addEventListener('click', function(e){
        // Avoid triggering if clicked on button/link
        if(e.target.closest('button') || e.target.closest('a')) return;
        
        var wasExpanded = this.classList.contains('expanded');
        // Collapse all others
        // document.querySelectorAll('.list-item.expanded').forEach(function(el){ el.classList.remove('expanded'); });
        
        if(!wasExpanded){
            this.classList.add('expanded');
            var extra = this.querySelector('.item-extra');
            if(extra && extra.getAttribute('data-loaded')!=='1'){
                buildExtraContent(this, extra);
                extra.setAttribute('data-loaded','1');
            }
        } else {
            this.classList.remove('expanded');
        }
    });
  });

  function statusClassFor(s){
    s=(s||'').toLowerCase();
    if(s.indexOf('permission')!==-1 || s.indexOf('granted')!==-1) return 'status-access-granted';
    if(s.indexOf('approv')!==-1) return 'status-approved';
    if(s.indexOf('resolved')!==-1||s.indexOf('ongoing')!==-1) return 'status-ongoing';
    if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) return 'status-denied';
    if(s.indexOf('cancel')!==-1) return 'status-cancelled';
    if(s.indexOf('expired')!==-1) return 'status-denied';
    if(s.indexOf('complete')!==-1) return 'status-completed';
    return 'status-pending';
  }
  function fmtLabel(s){
    s=String(s||'').replace(/[_-]+/g,' ').toLowerCase();
    if(s.indexOf('access granted')!==-1 || s.indexOf('permission granted')!==-1) return 'Access Granted';
    if(s.indexOf('moved to history')!==-1) return 'Denied';
    return s.replace(/\b\w/g,function(m){ return m.toUpperCase(); });
  }

  // Notification Logic
  var notifCountEl=document.getElementById('notifCount');
  var notifBtn=document.getElementById('notifBtn');
  var notifPanel=document.getElementById('notifPanel');
  var notifPopup=document.getElementById('notifPopup');
  var notifItems=[];
  var notifKnownIds={};
  var notifBootstrapped=false;
  var notifPopupTimer=null;
  
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
      var time=formatNotifDateTime(it.created_at||it.time||'');
      html+='<div class="notif-popup-item"><div class="notif-popup-title">'+title+'</div><div class="notif-popup-sub">'+message+(time?' • '+time:'')+'</div></div>';
    }
    notifPopup.innerHTML=html;
    notifPopup.style.display='block';
    if(notifPopupTimer) clearTimeout(notifPopupTimer);
    notifPopupTimer=setTimeout(function(){
      if(notifPopup){ notifPopup.style.display='none'; }
    },5000);
  }
  function renderNotifPanel(){
    if(!notifPanel) return;
    var header='<div class="notif-panel-header"><div class="notif-panel-title">Notifications</div><button type="button" class="notif-panel-close" aria-label="Close">&times;</button></div>';
    if(!notifItems.length){
      notifPanel.innerHTML=header+'<div class="notif-panel-body"><div class="notif-empty">No recent updates</div></div>';
    } else {
      var html='';
      var sorted=notifItems.slice().sort(function(a,b){ return notifTimeValue(b)-notifTimeValue(a); });
      for(var i=0;i<sorted.length;i++){
        var it=sorted[i]||{};
        var code=String(it.code||'').replace(/[<>]/g,'');
        var title=String(it.title||'').replace(/[<>]/g,'');
        var status=String(it.status||'').replace(/[<>]/g,'');
        var message=formatNotifDisplay(it.message||'');
        var time=formatNotifDateTime(it.created_at||it.time||'');
        var subText=message || status;
        html+='<div class="notif-item" data-code="'+code+'"><div class="notif-item-main"><div class="notif-item-title">'+title+'</div><div class="notif-item-sub">'+subText+'</div>';
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
    return fetch('dashboardvisitor.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'mark_notifications_read'})})
      .then(function(r){ return r.json(); })["catch"](function(){});
  }
  
  var prevStatuses={};
  var lastDataSig='';
  var pendingReload=false;
  function buildSig(list){
    if(!Array.isArray(list)) return '';
    return list.slice().sort(function(a,b){
      return String(a.ref_code||'').localeCompare(String(b.ref_code||''));
    }).map(function(it){
      return [it.ref_code||'', it.status||'', it.payment_status||'', it.attempts||''].join('|');
    }).join('||');
  }
  function hasVisibleModal(){
    var nodes = document.querySelectorAll('.modal,.update-proof-modal,.profile-modal,.account-blocked-modal');
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
    fetch('dashboardvisitor.php?ajax=1')
      .then(function(r){return r.json();})
      .then(function(data){
        if(!data||!data.active||!data.history) return;
        var sig = buildSig(data.active) + '##' + buildSig(data.history);
        if(lastDataSig && sig !== lastDataSig){
          pendingReload = true;
        }
        lastDataSig = sig;
        if(pendingReload && !hasVisibleModal() && !hasActiveInput()){
          location.reload();
          return;
        }
        
        function fmtLabel(s){
          s=String(s||'').replace(/[_-]+/g,' ').toLowerCase();
          if(s.indexOf('access granted')!==-1 || s.indexOf('permission granted')!==-1) return 'Access Granted';
          if(s.indexOf('moved to history')!==-1) return 'Denied';
          return s.replace(/\b\w/g,function(m){ return m.toUpperCase(); });
        }
        
        function updateList(items,listId,panelId){
          var container=document.getElementById(listId);
          if(!container) return;
          var historyList=document.getElementById('list-history');
          var activeList=document.getElementById('list-active');
          
          // Track which codes should exist in this list
          var codesInResponse={};
          items.forEach(function(item){
            codesInResponse[String(item.ref_code||'')]=true;
          });
          
          // Update or preserve existing items
          items.forEach(function(newItem){
            var code=String(newItem.ref_code||'');
            if(!code) return;
            
            var li=container.querySelector('.list-item[data-ref-code="'+code.replace(/"/g,'&quot;')+'"]');
            if(!li) return;
            
            var oldStatus=li.getAttribute('data-status')||'';
            var newStatus=newItem.status||'';
            var newStatusLower=String(newStatus||'').toLowerCase();
            var hasScan=!!(newItem.scanned_at);
            
            // Always update the status attribute to keep in sync with server
            li.setAttribute('data-status',newStatus);
            if(newItem.payment_status !== undefined){
              li.setAttribute('data-payment-status', newItem.payment_status || '');
            }
            if(newItem.scanned_at !== undefined){
              li.setAttribute('data-scanned-at', newItem.scanned_at || '');
            }
            if(newItem.attempts !== undefined){
              li.setAttribute('data-attempts', String(newItem.attempts || 0));
            }

            var shouldMoveHistory = newStatusLower.indexOf('cancel') !== -1 || newStatusLower.indexOf('expired') !== -1 || newStatusLower.indexOf('moved_to_history') !== -1;
            if(panelId === 'panel-requests' && shouldMoveHistory && historyList && activeList){
              var safeCode=code.replace(/"/g,'&quot;');
              var existing=historyList.querySelector('.list-item[data-ref-code="'+safeCode+'"]');
              if(existing){
                li.remove();
              } else {
                var titleEl=li.querySelector('.item-title');
                if(titleEl && newItem.title) titleEl.textContent=newItem.title;
                var badge=li.querySelector('.status-badge');
                if(badge){
                if(newStatusLower.indexOf('moved_to_history') !== -1 || newStatusLower.indexOf('permission_granted') !== -1){
                  badge.textContent = hasScan ? 'Access Granted' : 'Denied';
                  badge.className = 'status-badge ' + statusClassFor(hasScan || newStatusLower.indexOf('permission_granted') !== -1 ? 'permission_granted' : 'denied');
                  } else {
                    badge.textContent=fmtLabel(newStatus);
                    badge.className='status-badge '+statusClassFor(newStatus);
                  }
                }
                var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
                if(noHistoryMsg) noHistoryMsg.remove();
                historyList.insertBefore(li, historyList.firstChild);
                li.style.display='';
                li.classList.remove('expanded');
              }
              if(activeList.querySelectorAll('.list-item').length===0){
                var emptyMsg=activeList.querySelector('div[style*="text-align:center"]');
                if(!emptyMsg){
                  var emptyDiv=document.createElement('div');
                  emptyDiv.style.cssText='padding:20px; text-align:center; color:#777;';
                  emptyDiv.textContent='No active requests.';
                  activeList.appendChild(emptyDiv);
                }
              }
              return;
            }
            
            // Only update badge if status changed (skip for history items - they're permanent)
            if(oldStatus !== newStatus && panelId !== 'panel-history'){
              var badge=li.querySelector('.status-badge');
              if(badge){
                if(newStatusLower.indexOf('moved_to_history') !== -1 || newStatusLower.indexOf('permission_granted') !== -1){
                  badge.textContent = hasScan ? 'Access Granted' : 'Denied';
                  badge.className = 'status-badge ' + statusClassFor(hasScan || newStatusLower.indexOf('permission_granted') !== -1 ? 'permission_granted' : 'denied');
                } else {
                  badge.textContent=fmtLabel(newStatus);
                  badge.className='status-badge '+statusClassFor(newStatus);
                }
              }
              
              // Notifications (only for admin-actioned items: approved or denied)
              if(oldStatus && panelId === 'panel-requests'){
                var isApproved=newStatusLower.indexOf('approv')!==-1;
                var isDenied=newStatusLower.indexOf('denied')!==-1 || newStatusLower.indexOf('reject')!==-1;
                if(isApproved || isDenied){
                  li.classList.add('status-updated');
                }
              }
              
              prevStatuses[code]=newStatus;
            }
          });
          
          // For history panel: NEVER hide items - they stay until manually deleted
          // For active panel: ensure items in response stay visible
          if(panelId !== 'panel-history'){
            var allItems=container.querySelectorAll('.list-item');
            allItems.forEach(function(item){
              var code=item.getAttribute('data-ref-code')||'';
              if(code && codesInResponse[code]){
                item.style.display='';
              }
            });
          }
        }

        function esc(t){
          return String(t||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        }
        function moveHistoryItems(items){
          var historyList=document.getElementById('list-history');
          var activeList=document.getElementById('list-active');
          if(!historyList||!activeList) return;
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
              var statusText=(s||'').replace(/[_-]+/g,' ').replace(/\b\w/g,function(m){return m.toUpperCase();});
              var hasScan = !!(item.scanned_at);
              if(s.indexOf('moved_to_history')!==-1 || s.indexOf('permission_granted')!==-1) statusText = hasScan || s.indexOf('permission_granted')!==-1 ? 'Access Granted' : 'Denied';
              var statusCls=(function(){
          if(hasScan && s.indexOf('moved_to_history')!==-1) return 'status-approved';
          if(s.indexOf('permission_granted')!==-1) return 'status-approved';
          if(s.indexOf('approv')!==-1 || s.indexOf('resolved')!==-1 || s.indexOf('ongoing')!==-1) return 'status-approved';
          if(s.indexOf('denied')!==-1 || s.indexOf('reject')!==-1 || s.indexOf('moved_to_history')!==-1) return 'status-denied';
                if(s.indexOf('cancel')!==-1) return 'status-cancelled';
                if(s.indexOf('expired')!==-1) return 'status-denied';
                return 'status-pending';
              })();
              var isReservation=(String(item.type||'').toLowerCase()==='reservation');
              var displayTitle=String(item.title||'');
              if(isReservation){
                var rawTitle=displayTitle;
                var prefix='Reservation Schedule - ';
                if(rawTitle.toLowerCase().indexOf(prefix.toLowerCase())===0){
                  var rest=rawTitle.slice(prefix.length);
                  var parts=rest.split(' - ');
                  displayTitle=(parts[0]||'').trim();
                }
                if(displayTitle==='') displayTitle='Amenity';
                var amenityName=displayTitle;
                if(amenityName.toLowerCase()==='pool') amenityName='Community Pool';
                displayTitle='Reservation – '+amenityName;
              }
              var detailsText=String(item.details||'');
              var reasonText='';
              var scheduleText=detailsText;
              if(isReservation && detailsText.indexOf('Reason:')!==-1){
                reasonText=detailsText.slice(detailsText.indexOf('Reason:')).trim();
                scheduleText=detailsText.slice(0, detailsText.indexOf('Reason:')).trim();
              }
              var createdText='';
              try{ createdText = item.date ? formatNotifDateTime(item.date) : ''; }catch(e){}
              li=document.createElement('div');
              li.className='list-item';
              li.setAttribute('data-ref-code',code);
              li.setAttribute('data-status', item.status || 'cancelled');
              li.setAttribute('data-type', item.type || 'reservation');
              if(item.scanned_at !== undefined){ li.setAttribute('data-scanned-at', item.scanned_at || ''); }
              if(item.payment_status!==undefined){ li.setAttribute('data-payment-status', item.payment_status || ''); }
              li.setAttribute('data-schedule', scheduleText);
              li.setAttribute('data-reason', reasonText);
              if(item.attempts!==undefined){ li.setAttribute('data-attempts', String(item.attempts || 0)); }
              li.innerHTML='<div class="item-icon"><i class="fa-solid fa-chevron-right"></i></div>'
                +'<div class="item-content">'
                +  '<div class="item-row" style="display:flex; justify-content:space-between; margin-bottom:5px;">'
                +    '<div class="item-left">'
                +      '<span class="status-badge '+statusCls+'">'+statusText+'</span>'
                +      (isReservation?('<span class="item-amenity">'+esc(displayTitle)+'</span>'):('<span class="item-title">'+esc(displayTitle)+'</span>'))
                +      (isReservation?('<span class="item-details" style="display:none;"></span>'):('<span class="item-details">- '+esc(String(item.details||''))+'</span>'))
                +    '</div>'
                +    '<div class="item-created">'+esc(createdText)+'</div>'
                +  '</div>'
                +  '<div style="font-size:0.8rem; color:#999; margin-left: 48px;" class="item-ref"><span>'+esc(code)+'</span></div>'
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
            if(item.payment_status !== undefined){
              li.setAttribute('data-payment-status', item.payment_status || '');
            }
            if(item.scanned_at !== undefined){
              li.setAttribute('data-scanned-at', item.scanned_at || '');
            }
            var titleEl=li.querySelector('.item-title');
            if(titleEl && item.title) titleEl.textContent=item.title;
            var badge=li.querySelector('.status-badge');
            if(badge){
              var itemStatusLower=String(item.status||'').toLowerCase();
              var itemHasScan=!!(item.scanned_at);
              if(itemStatusLower.indexOf('moved_to_history')!==-1){
                badge.textContent = itemHasScan ? 'Access Granted' : 'Denied';
                badge.className = 'status-badge ' + statusClassFor(itemHasScan ? 'permission_granted' : 'denied');
              } else {
                badge.textContent=fmtLabel(item.status);
                badge.className='status-badge '+statusClassFor(item.status);
              }
            }
            var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
            if(noHistoryMsg) noHistoryMsg.remove();
            historyList.insertBefore(li, historyList.firstChild);
            li.style.display='';
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

        updateList(data.active,'list-active','panel-requests');
        if(data.history) moveHistoryItems(data.history);
        
        if(Array.isArray(data.notifications)){
          var incoming = data.notifications.map(function(n){
            return {
              id: n.id,
              title: n.title||'',
              message: n.message||'',
              type: n.type||'info',
              is_read: n.is_read||0,
              created_at: n.created_at||'',
              time: n.time||''
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
  
  // Modal Logic
  var cancelModal=document.getElementById('cancelModal');
  var cancelModalKeep=cancelModal?cancelModal.querySelector('.cancel-modal-keep'):null;
  var cancelModalConfirm=cancelModal?cancelModal.querySelector('.cancel-modal-confirm'):null;
  var cancelModalClose=cancelModal?cancelModal.querySelector('.cancel-modal-close'):null;
  var cancelModalRef=null;
  var cancelModalLi=null;
  var modalAction = 'cancel';
  var updateProofModal=document.getElementById('updateProofModal');
  var moveHistoryModal=document.getElementById('moveHistoryModal');
  var moveHistoryKeep=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-keep'):null;
  var moveHistoryConfirm=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-confirm'):null;
  var moveHistoryClose=moveHistoryModal?moveHistoryModal.querySelector('.cancel-modal-close'):null;
  var moveHistoryRef=null;
  var moveHistoryLi=null;
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

  window.openCancelModal = function(li,ref){
    if(!cancelModal) return;
    cancelModalRef=ref;
    cancelModalLi=li;
    modalAction = 'cancel';
    
    var h3 = cancelModal.querySelector('h3');
    var pBody = cancelModal.querySelector('.cancel-modal-body p:first-child');
    var pNote = cancelModal.querySelector('.cancel-modal-note');
    var btnKeep = cancelModal.querySelector('.cancel-modal-keep');
    var btnConfirm = cancelModal.querySelector('.cancel-modal-confirm');
    
    if(h3) h3.textContent = 'Cancel Reservation';
    if(pBody) pBody.textContent = 'Are you sure you want to cancel this reservation?';
    if(pNote) {
      pNote.style.display = 'block';
      pNote.textContent = 'Note: Downpayment is non-refundable. Cancelling will forfeit your downpayment.';
    }
    if(btnKeep) btnKeep.textContent = 'Keep Reservation';
    if(btnConfirm) btnConfirm.textContent = 'Confirm Cancel';
    
    cancelModal.style.display='flex';
  };

  window.openDeleteModal = function(li,ref){
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
  };

  function closeCancelModalVisitor(){
    if(!cancelModal) return;
    cancelModal.style.display='none';
    cancelModalRef=null;
    cancelModalLi=null;
  }
  
  function performCancelVisitor(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalVisitor();
      return;
    }

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
      // Success - Update UI without alert
      li.setAttribute('data-status','cancelled');
      li.setAttribute('data-payment-status','cancelled');
      if(typeof prevStatuses !== 'undefined') prevStatuses[ref]='cancelled';

      // Update Title immediately
      var titleEl = li.querySelector('.item-title');
      if (titleEl && titleEl.textContent.indexOf('Cancelled') === -1) {
          titleEl.textContent += ' - Cancelled';
      }
      
      // Update Status Badge
      var badge=li.querySelector('.status-badge');
      if(badge){
        badge.textContent=fmtLabel('cancelled');
        badge.classList.remove('status-pending','status-ongoing','status-denied','status-cancelled','status-completed','status-expired');
        badge.classList.add(statusClassFor('cancelled'));
      }
      
      // Rebuild Extra Content
      var extraEl=li.querySelector('.item-extra');
      if(extraEl){
        extraEl.setAttribute('data-loaded','0');
        extraEl.innerHTML='';
        if(li.classList.contains('expanded')){
          buildExtraContent(li,extraEl);
          extraEl.setAttribute('data-loaded','1');
        }
      }

      // Remove from active list and move to history
      var activeList = document.getElementById('list-active');
      var historyList = document.getElementById('list-history');
      
      if(activeList && historyList){
        // Check if list is now empty and show "No active requests" message if needed
        var remainingActiveItems = activeList.querySelectorAll('.list-item');
        
        // Remove from active list
        li.remove();
        
        // If no more items in active list, show empty message
        if(remainingActiveItems.length === 1){ // Will be 0 after remove
          var emptyDiv = document.createElement('div');
          emptyDiv.style.cssText = 'padding:20px; text-align:center; color:#777;';
          emptyDiv.textContent = 'No active requests.';
          activeList.appendChild(emptyDiv);
        }
        
        // Remove the "No history records" message if present
        var noHistoryMsg = historyList.querySelector('div[style*="text-align:center"]');
        if(noHistoryMsg) noHistoryMsg.remove();
        
        // Add to history list at the top
        historyList.insertBefore(li, historyList.firstChild);
        li.style.display = '';
        li.classList.remove('expanded');
        
        // Switch to history tab
        var historyTab = document.querySelector('.nav-item[data-section="panel-history"]');
        if(historyTab){
          var navItems = document.querySelectorAll('.nav-item[data-section]');
          navItems.forEach(function(n){ n.classList.remove('active'); });
          historyTab.classList.add('active');
          
          var sections = document.querySelectorAll('.panel-section');
          sections.forEach(function(s){ s.style.display = 'none'; });
          document.getElementById('panel-history').style.display = 'block';
        } else {
          var sections = document.querySelectorAll('.panel-section');
          sections.forEach(function(s){ s.style.display = 'none'; });
          document.getElementById('panel-history').style.display = 'block';
        }
      }
      
      closeCancelModalVisitor();

    })["catch"](function(){
      // alert('Network error. Please try again.'); // Suppress as per request
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
      li.setAttribute('data-status', 'moved_to_history');
      if(typeof prevStatuses !== 'undefined') prevStatuses[ref]='moved_to_history';
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
      var activeList = document.getElementById('list-active');
      var historyList = document.getElementById('list-history');
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
    })["catch"](function(){
      alert('Network error. Please try again.');
    });
  }

  function performDeleteVisitor(){
    var ref=cancelModalRef;
    var li=cancelModalLi;
    if(!ref||!li){
      closeCancelModalVisitor();
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
      closeCancelModalVisitor();
    })["catch"](function(){
      // alert('Network error. Please try again.');
    });
  }

  if(cancelModalKeep){
    cancelModalKeep.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalClose){
    cancelModalClose.addEventListener('click',function(){
      closeCancelModalVisitor();
    });
  }
  if(cancelModalConfirm){
    cancelModalConfirm.addEventListener('click',function(){
      if(modalAction === 'delete'){
        performDeleteVisitor();
      } else {
        performCancelVisitor();
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

  // Activity Modal Logic (View Details)
  var activityModal = document.getElementById('activityModal');
  var activityModalBody = document.getElementById('activityModalBody');
  var activityModalClose = activityModal ? activityModal.querySelector('.close') : null;

  window.downloadQRImage = function(code) {
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

  window.openActivityModal = function(refCode) {
      if(!activityModal || !activityModalBody) {
          activityModal = document.getElementById('activityModal');
          activityModalBody = document.getElementById('activityModalBody');
          if(!activityModal || !activityModalBody) return;
      }
      
      activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;">Loading...</div>';
      activityModal.style.display = 'flex';

      fetch('get_activity_details.php?code=' + encodeURIComponent(refCode))
        .then(r => r.text())
        .then(html => {
            activityModalBody.innerHTML = html;
        })
        .catch(e => {
            activityModalBody.innerHTML = '<div style="padding:20px;text-align:center;color:red;">Error loading details.</div>';
        });
  };

  if(activityModalClose) {
      activityModalClose.addEventListener('click', function() {
          activityModal.style.display = 'none';
      });
  }
  (function(){
    var modal = document.getElementById('qrWarningModal');
    var cancelBtn = document.getElementById('qrWarningCancel');
    var proceedBtn = document.getElementById('qrWarningProceed');
    var msgEl = document.getElementById('qrWarningMessage');
    var defaultMsg = msgEl ? msgEl.textContent : '';
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
    window.openQRWarning = function(cb, message){
      window.qrWarningConfirm = typeof cb === 'function' ? cb : null;
      if(msgEl) msgEl.textContent = message || defaultMsg;
      if(modal) modal.style.display = 'flex';
    };
  })();
  
  // Notification button handler
  if(notifBtn && notifPanel){
    notifBtn.addEventListener('click',function(e){
      e.stopPropagation();
      if(notifPopup){ notifPopup.style.display='none'; }
      notifPanel.style.display=(notifPanel.style.display==='block'?'none':'block');
      if(notifPanel.style.display==='block') renderNotifPanel();
      document.querySelectorAll('.item-list .list-item.status-updated').forEach(function(li){
        li.classList.remove('status-updated');
      });
      if(notifCountEl){
        notifCountEl.textContent='0';
        notifCountEl.style.display='none';
      }
      if(notifPanel.style.display==='block'){
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
  
  // Close modals when clicking outside
  window.addEventListener('click', function(e) {
      if (e.target === activityModal) {
          activityModal.style.display = 'none';
      }
      if (e.target === cancelModal) {
          cancelModal.style.display = 'none';
      }
      if (e.target === moveHistoryModal) {
          closeMoveHistoryModal();
      }
      if (e.target === updateProofModal) {
          closeUpdateProofModal();
      }
  });


  // Helper to build extra content (details)
  function buildExtraContent(li, extra){
    var type=(li.getAttribute('data-type')||'').toLowerCase();
    var status=(li.getAttribute('data-status')||'').toLowerCase();
    var ref=li.getAttribute('data-ref-code')||'';
    var scannedAt=li.getAttribute('data-scanned-at')||'';
    var hasScan=!!scannedAt;
    var effectiveStatus=(hasScan && status.indexOf('moved_to_history')!==-1) ? 'permission_granted' : status;
    var label=fmtLabel(effectiveStatus);
    var scheduleText=li.getAttribute('data-schedule')||'';
    var reasonText=li.getAttribute('data-reason')||'';
    var statusNote='';
    var s=String(effectiveStatus||'').toLowerCase();
    var paymentStatus=(li.getAttribute('data-payment-status')||'').toLowerCase();
    var basePath=window.location.pathname.replace(/\/[^\/]*$/,'');
    var isApproved=s.indexOf('approv')!==-1 || s.indexOf('permission')!==-1 || s.indexOf('granted')!==-1;
    var attempts = parseInt(li.getAttribute('data-attempts')||'0',10);
    var isDeniedMax = (type==='reservation' && paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3);
    if(isDeniedMax){
      isApproved=false;
    }

    // Visitor specific notes
    if(isApproved) {
        statusNote='This request is approved. Use this QR pass at the gate.';
    }
    else if(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1) statusNote='This request was denied. Please contact the subdivision office for details.';
    else if(s.indexOf('cancelled')!==-1) statusNote='This request was cancelled by the user.';
    else if(s.indexOf('pending')!==-1||s===''||s==='new') {
        statusNote='This request is pending. Wait for the admin to review it. The QR entry pass will be available after approval.';
    }
    else if(s.indexOf('resolved')!==-1) statusNote='This item has been marked as resolved by the admin.';
    else if(s.indexOf('expired')!==-1) statusNote='This pass is expired and can no longer be used.';
    if(type==='reservation' && paymentStatus==='rejected'){
        var att = isNaN(attempts)?0:attempts;
        if(att >= 3){
          statusNote='Denied — Max Attempts Reached.';
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
    var summaryParts=[];
    if(titleEl){ summaryParts.push(titleEl.textContent.trim()); }
    if(detailsEl){ summaryParts.push(detailsEl.textContent.replace(/^\s*-\s*/,'').trim()); }
    if(refSpan){ summaryParts.push('Code: '+refSpan.textContent.trim()); }
    var summaryText=summaryParts.join(' • ');

    var canCancel=(s.indexOf('pending')!==-1||s.indexOf('pending_update')!==-1||s===''||s==='new'||paymentStatus==='pending_update');
    var isHistoryPanel=!!li.closest('#panel-history');
    var rawStatus=(String(status||'').toLowerCase());
    var canDelete=isHistoryPanel && (rawStatus.indexOf('cancel')!==-1 || rawStatus.indexOf('denied')!==-1 || rawStatus.indexOf('reject')!==-1 || rawStatus.indexOf('expired')!==-1 || rawStatus.indexOf('moved_to_history')!==-1);
    var isDeniedMaxPanel = (type==='reservation' && paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3);
    var canMoveHistory=(!isHistoryPanel) && !isDeniedMaxPanel && (rawStatus.indexOf('denied')!==-1 || rawStatus.indexOf('reject')!==-1 || rawStatus.indexOf('permission')!==-1 || rawStatus.indexOf('granted')!==-1);
    var canUpdateProof=(type==='reservation' && paymentStatus==='rejected' && s.indexOf('cancel')===-1);
    var isRejectedReason=(s.indexOf('denied')!==-1||s.indexOf('reject')!==-1||paymentStatus==='rejected');
    var showStatusLabel=!isRejectedReason;
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
        moveBtn.addEventListener('click', function(e){
          e.stopPropagation();
          openMoveHistoryModal(li, ref);
        });
      }
      var viewBtns = extra.querySelectorAll('.view-details-trigger');
      viewBtns.forEach(function(btn){
        btn.addEventListener('click', function(e){
          e.stopPropagation();
          var code = btn.getAttribute('data-ref') || ref;
          if(code) window.openActivityModal(code);
        });
      });
      return;
    }
    html+='<div class="item-extra-section">';
    var qrSrcForDownload = '';
    if(isApproved && ref){
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
    if(type==='reservation' && scheduleText){
      var parts=scheduleParts(scheduleText);
      var rows='';
      if(parts.date){
        rows+='<div class="schedule-row"><div class="schedule-key">Date:</div><div class="schedule-val">'+esc(parts.date)+'</div></div>';
      }
      if(parts.time){
        rows+='<div class="schedule-row"><div class="schedule-key">Time:</div><div class="schedule-val">'+esc(parts.time)+'</div></div>';
      }
      if(!rows){
        rows='<div class="schedule-row"><div class="schedule-key">Schedule:</div><div class="schedule-val">'+esc(scheduleText)+'</div></div>';
      }
      var scheduleClass = (isHistoryPanel || s.indexOf('denied')!==-1 || (paymentStatus==='rejected' && (isNaN(attempts)?0:attempts)>=3)) ? 'status-neutral' : statusClassFor(effectiveStatus);
      html+='<div class="item-extra-schedule '+scheduleClass+'"><div class="schedule-title">Reservation Schedule</div>'+rows+'</div>';
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
    
    extra.innerHTML=html;

    // Attach event listeners for the new buttons
    var cancelBtn = extra.querySelector('.item-extra-cancel');
    if(cancelBtn) cancelBtn.addEventListener('click', function(){ window.openCancelModal(li, ref); });
    
    var deleteBtn = extra.querySelector('.item-extra-delete');
    if(deleteBtn) deleteBtn.addEventListener('click', function(){ window.openDeleteModal(li, ref); });
    
    var moveBtn = extra.querySelector('.item-extra-move-history');
    if(moveBtn && ref && canMoveHistory){
      moveBtn.addEventListener('click', function(e){
        e.stopPropagation();
        openMoveHistoryModal(li, ref);
      });
    }
    
    var viewBtns = extra.querySelectorAll('.view-details-trigger');
    viewBtns.forEach(function(btn){
      btn.addEventListener('click', function(e){
        e.stopPropagation();
        var code = btn.getAttribute('data-ref') || ref;
        if(code) window.openActivityModal(code);
      });
    });
    var dropdownMove = extra.querySelector('.dropdown-move-history, [data-action="move_history"]');
    if(dropdownMove && ref && canMoveHistory){
      dropdownMove.addEventListener('click', function(e){
        e.stopPropagation();
        openMoveHistoryModal(li, ref);
      });
    }

    var downloadBtn = extra.querySelector('.download-qr-btn');
    if(downloadBtn) downloadBtn.addEventListener('click', function(e){
        e.stopPropagation();
        var url = downloadBtn.getAttribute('data-qr') || '';
        if(!url){
          var img = extra.querySelector('.item-extra-qr');
          if(img) url = img.src || '';
        }
        if(!url) return;
        function downloadRaw(){
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
        var warningMsg = String(type || '').toLowerCase() === 'reservation'
          ? 'Do not scan. One-time use only. Valid only on the selected date and time. Authorized guards only.'
          : 'Do not scan. Authorized guards only.';
        if(typeof window.openQRWarning === 'function'){
          window.openQRWarning(downloadRaw, warningMsg);
        } else {
          downloadRaw();
        }
    });
    var updateBtn=extra.querySelector('.update-proof-btn');
    if(updateBtn && ref){
      updateBtn.addEventListener('click',function(ev){
        ev.stopPropagation();
        openUpdateProofModal(li, ref);
      });
    }
  }

})();
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var accountModal = document.getElementById('accountBlockedModal');
    if (accountModal && accountModal.getAttribute('data-show') === '1') {
        accountModal.style.display = 'flex';
        document.body.classList.add('account-blocked');
    }
    var profileModal = document.getElementById("profileModal");
    var profileTrigger = document.getElementById("profileTrigger");
    var profileClose = document.getElementsByClassName("close-profile-modal")[0];

    if(profileTrigger && profileModal) {
        profileTrigger.onclick = function(e) {
            e.preventDefault();
            profileModal.style.display = "block";
        };
    }

    if(profileClose && profileModal) {
        profileClose.onclick = function() {
            profileModal.style.display = "none";
        };
    }

    window.addEventListener('click', function(event) {
        if (event.target == profileModal) {
            profileModal.style.display = "none";
        }
    });

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
<div id="visitorQrDownloadTemplate" style="position:fixed; left:-9999px; top:0; z-index:-9999; width:400px; background:#fff; padding:20px; box-sizing:border-box; font-family:'Poppins',sans-serif;">
  <div style="border: 2px solid #23412e; padding: 20px; border-radius: 12px; background: #f9f9f9; text-align: center;">
    <div style="margin-bottom: 15px; font-weight: 700; color: #23412e; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 1.1rem;">
      <img src="images/logo.svg" alt="Logo" style="width: 32px; height: 32px; margin: 0;">
      <span>Victorian Pass</span>
    </div>
    <div style="background:#fff; padding:10px; border:1px solid #ddd; display:inline-block; border-radius:0;">
      <div id="visitorQrDownloadCode" style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;"></div>
    </div>
    <div style="color: #d9534f; font-weight: 600; margin: 15px auto 5px auto; font-size: 0.85rem; line-height: 1.5; border: 1px dashed #d9534f; padding: 10px; border-radius: 8px; background: #fff5f5;">
      Do not scan. One-time use only. Once scanned, the QR code is permanently disabled. Authorized guards only.
    </div>
  </div>
</div>
<div id="changePasswordModalVisitor" class="profile-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,0.6); align-items:center; justify-content:center; z-index:3000;">
  <div class="vp-logout-modal" style="position:relative; top:auto; right:auto; margin:0; width:350px; max-width:90vw; max-height:calc(100vh - 100px);">
    <button class="close-change-password" style="position:absolute; right:12px; top:10px; background:transparent; border:none; font-size:20px; cursor:pointer;">&times;</button>
    <div class="change-password-title">Change Password</div>
    <?php if ($pwdMsg !== '') { ?>
      <div style="margin-bottom:12px; padding:10px 12px; border-radius:8px; font-size:0.9rem; <?php echo $pwdOk ? 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0' : 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca'; ?>">
        <?php echo htmlspecialchars($pwdMsg); ?>
      </div>
    <?php } ?>
    <form method="post" action="dashboardvisitor.php" style="display:grid; grid-template-columns:1fr; gap:8px; max-width:420px;">
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
  var m = document.getElementById('changePasswordModalVisitor');
  var openBtn = document.getElementById('openChangePasswordVisitor');
  var closeBtn = document.querySelector('#changePasswordModalVisitor .close-change-password');
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
</body>
</html>
