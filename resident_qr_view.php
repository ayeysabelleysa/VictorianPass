<?php
session_start();
include 'connect.php';

$rid = isset($_GET['rid']) ? intval($_GET['rid']) : 0;
$code = isset($_GET['code']) ? trim($_GET['code']) : '';

function vp_resident_link($rid, $code = ''){
  $scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
  if ($code !== '') {
    return sprintf('%s://%s%s/resident_qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($code));
  }
  return sprintf('%s://%s%s/resident_qr_view.php?rid=%d', $scheme, $host, $basePath, $rid);
}

$user = null;
$guest = null;
$isGuest = false;
$topLabel = 'OFFICIAL PROOF OF RESIDENCY';
$statusBadge = '<span class="badge disabled">Unknown</span>';
$error = '';
$residentCode = '';
$displayPhone = '';
function vp_resident_status_badge(){
  return '<span class="badge active">Welcome Resident</span>';
}

if ($con instanceof mysqli) {
  // Check if this is a Guest Pass lookup via Code
  if (!empty($code) && stripos($code, 'VH-') === 0) {
    $residentCode = strtoupper($code);
  } elseif (!empty($code)) {
    $stmt = $con->prepare("SELECT g.*, u.first_name as host_fname, u.last_name as host_lname, u.house_number as host_block, u.address as host_address 
                           FROM guest_forms g 
                           LEFT JOIN users u ON g.resident_user_id = u.id 
                           WHERE g.ref_code = ?");
    if ($stmt) {
      $stmt->bind_param('s', $code);
      $stmt->execute();
      $res = $stmt->get_result();
      if ($res && $res->num_rows > 0) {
        $guest = $res->fetch_assoc();
        $isGuest = true;
        $topLabel = 'AUTHORIZED GUEST PASS';
        
        // Determine Validity
        // Resident Personal QR Codes and Approved Guest QR Passes are permanently valid once approved.
        // Removed date-based expiry checks for guest passes.

        $gStatus = strtolower(trim($guest['approval_status'] ?? 'pending'));
        if ($gStatus === 'deleted') {
            $statusBadge = '<span class="badge disabled">Guest Pass Revoked</span>';
        } elseif ($gStatus !== 'approved') {
            $statusBadge = '<span class="badge disabled">Not Approved</span>';
        } else {
            $statusBadge = '<span class="badge active">Valid Guest</span>';
        }
        
        $fullName = trim(($guest['visitor_first_name'] ?? '') . ' ' . ($guest['visitor_last_name'] ?? ''));
        $displayPhone = $guest['visitor_contact'] ?? '';
      } else {
        $residentCode = strtoupper($code);
      }
      $stmt->close();
    }
  } 
  // Resident Lookup via Code
  if (!$isGuest && $residentCode !== '') {
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, user_type, IFNULL(status,'active') as status FROM users WHERE house_number = ? LIMIT 1");
    $stmt->bind_param('s', $residentCode);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) { 
        $user = $res->fetch_assoc(); 
        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        
        if (($user['user_type'] ?? '') !== 'resident') { 
            $error = 'Invalid user type.'; 
        } else {
            $statusBadge = vp_resident_status_badge();
        }
    }
    $stmt->close();
  }
  // Resident Lookup via RID
  elseif ($rid > 0) {
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address, user_type, IFNULL(status,'active') as status FROM users WHERE id = ?");
    $stmt->bind_param('i', $rid);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $res->num_rows > 0) { 
        $user = $res->fetch_assoc(); 
        $fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        
        if (($user['status'] ?? '') !== 'active') { 
            $error = 'Account is not active.'; 
        } elseif (($user['user_type'] ?? '') !== 'resident') { 
            $error = 'Invalid user type.'; 
        } else {
            $statusBadge = '<span class="badge active">Verified Resident</span>';
        }
    } else {
        $error = 'Resident not found.';
    }
    $stmt->close();
  }
}

// Normalize phone to 09 format if stored as +63
if (!empty($displayPhone)) {
  if (preg_match('/^\+63(9\d{9})$/', $displayPhone)) { $displayPhone = '0' . substr($displayPhone, 3); }
}

if ($isGuest && $guest) {
    $link = vp_resident_link(0, $code);
} else {
    $residentHouseCode = $user ? strtoupper(trim((string)($user['house_number'] ?? ''))) : $residentCode;
    if ($residentHouseCode !== '') {
      $link = vp_resident_link(0, $residentHouseCode);
    } else {
      $link = ($user ? vp_resident_link(intval($user['id'])) : vp_resident_link($rid));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Resident ID - VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg" />
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet" />
  <style>
    * { font-family: 'Poppins', sans-serif; box-sizing: border-box; }
    body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:#0f0f0f; }
    .wrap { padding: 24px; }
    .card { width: 360px; max-width: 92vw; background: #1e1e1e; border-radius: 16px; box-shadow: 0 10px 28px rgba(0,0,0,0.35); overflow: hidden; color:#fff; }
    .card-header { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; background:#111; }
    .pass-title { text-align:center; font-size:0.8rem; letter-spacing:0.08em; font-weight:600; color:#a9b1a6; padding:8px 16px 0; text-transform:uppercase; }
    .brand { display:flex; align-items:center; gap:8px; }
    .brand img { height:28px; }
    .brand .text { font-weight:700; color:#e5ddc6; }
    .id-top { display:flex; gap:12px; padding:14px 16px; background:#181818; }
    .avatar { width:160px; height:160px; border-radius:0; padding:8px; background:#ffffff; color:#23412e; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.4rem; overflow: hidden; }
    .avatar img, .avatar canvas { width:100% !important; height:100% !important; object-fit:contain; }
    .top-info { flex:1; }
    .top-info .name { font-size:1.05rem; font-weight:700; margin:0 0 4px 0; color:#eaeaea; }
    .top-info .contact { font-size:0.86rem; color:#cfcfcf; }
    .divider { height:1px; background:#2f2f2f; margin:0 16px; }
    .id-body { padding:14px 16px; }
    .row { display:flex; align-items:center; justify-content:space-between; margin:6px 0; }
    .label { color:#bdbdbd; font-weight:600; font-size:0.85rem; }
    .value { color:#eaeaea; font-size:0.95rem; }
    .badge { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:12px; font-size:0.85rem; font-weight:700; }
    .badge.active { background:#23412e; color:#e5ddc6; }
    .badge.disabled { background:#a83b3b; color:#fff; }
    .foot { padding:10px 16px; color:#aaa; font-size:0.82rem; }
    .download-btn { background:#e5ddc6; color:#23412e; border:none; border-radius:8px; padding:8px 12px; font-weight:700; text-decoration:none; display:inline-block; margin-top:6px; }
    @media (max-width:420px){ .id-top{ gap:10px; } .avatar{ width:120px; height:120px; } }
  </style>
  <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <!-- Empty script tag for clean removal -->
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="card-header">
        <div class="brand"><img src="images/logo.svg" alt="VictorianPass"><div class="text">Victorian Pass</div></div>
      </div>
      <div class="pass-title"><?php echo $isGuest ? 'Guest Pass' : 'Resident Pass'; ?></div>
      <?php if (!empty($error)): ?>
        <div class="foot" style="color:#ffb3b3">⚠️ <?php echo htmlspecialchars($error); ?></div>
      <?php else: ?>
      <div class="id-top">
        <div class="avatar">
          <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($link); ?>" alt="Resident QR" crossorigin="anonymous">
        </div>
        <style>
          .avatar {
            width: 160px;
            height: 160px;
            border-radius: 0;
            padding: 8px;
            background: #ffffff;
            overflow: hidden;
            margin: 0 auto;
          }
          .avatar img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
          }
        </style>
        <div class="top-info">
          <div style="color:#e5ddc6; font-size:0.75rem; font-weight:600; text-transform:uppercase; letter-spacing:1px; margin-bottom:4px;"><?php echo htmlspecialchars($topLabel); ?></div>
          <div class="name"><?php echo htmlspecialchars($fullName); ?></div>
          <div class="contact">
            <?php 
              if($isGuest) echo htmlspecialchars($guest['visitor_email'] ?? '');
              else echo htmlspecialchars($user['email'] ?? '');
            ?>
            <?php if(!empty($displayPhone)){ echo ' • ' . htmlspecialchars($displayPhone); } ?>
          </div>
          <div style="margin-top:6px;"><?php echo $statusBadge; ?></div>
        </div>
      </div>
      <div class="divider"></div>
      <div class="id-body">
        <?php if($isGuest): ?>
        <div class="row"><div class="label">Host Resident</div><div class="value"><?php echo htmlspecialchars(trim(($guest['host_fname']??'').' '.($guest['host_lname']??''))); ?></div></div>
        <div class="row"><div class="label">Destination</div><div class="value"><?php echo htmlspecialchars(($guest['host_block']??'').' '.$guest['host_address']); ?></div></div>
        <div class="row"><div class="label">Valid Date</div><div class="value"><?php echo date('m/d/y', strtotime($guest['visit_date'])); ?></div></div>
        <div class="row"><div class="label">Time</div><div class="value"><?php echo date('g:i A', strtotime($guest['visit_time'])); ?></div></div>
        <?php else: ?>
        <div class="row"><div class="label">Block</div><div class="value"><?php echo htmlspecialchars($user['house_number'] ?? '-'); ?></div></div>
        <div class="row"><div class="label">Unit / Address</div><div class="value"><?php echo htmlspecialchars($user['address'] ?? '-'); ?></div></div>
        <div class="row"><div class="label">Contact</div><div class="value"><?php echo htmlspecialchars($displayPhone ?: '-') ; ?></div></div>
        <div class="row"><div class="label">Email</div><div class="value"><?php echo htmlspecialchars($user['email'] ?? '-') ; ?></div></div>
        <?php endif; ?>
      </div>
      <div class="divider"></div>
      <div class="foot">Scan QR to open this digital ID • Code linked to <?php echo $isGuest ? 'guest pass' : 'resident profile'; ?><br>
        <a class="download-btn" href="#" onclick="downloadQR();return false;">Download QR</a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div id="qrDownloadTemplate" style="position:fixed; left:-9999px; top:0; z-index:-9999; width:400px; background:#fff; padding:20px; box-sizing:border-box; font-family:'Poppins',sans-serif;">
    <div style="border: 2px solid #23412e; padding: 20px; border-radius: 12px; background: #f9f9f9; text-align: center;">
      <div style="margin-bottom: 15px; font-weight: 700; color: #23412e; display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 1.1rem;">
         <img src="images/logo.svg" alt="Logo" style="width: 32px; height: 32px; margin: 0;">
         <span>Victorian Pass</span>
      </div>
      <div style="background:#fff; padding:10px; border:1px solid #ddd; display:inline-block; border-radius:0;">
        <div id="qrDownloadCode" style="width:200px;height:200px;display:flex;align-items:center;justify-content:center;overflow:hidden;"></div>
      </div>
      <div style="color: #d9534f; font-weight: 600; margin: 15px auto 5px auto; font-size: 0.85rem; line-height: 1.5; border: 1px dashed #d9534f; padding: 10px; border-radius: 8px; background: #fff5f5;">
          Do not scan. One-time use only. Once scanned, the QR code is permanently disabled. Authorized guards only.
      </div>
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
  <script>
    function downloadQR(){
      var isGuest = <?php echo $isGuest ? 'true' : 'false'; ?>;
      var fname = '';
      if(isGuest){
        fname = 'GuestPass_' + (<?php echo json_encode($code); ?> || 'ID') + '.png';
      } else {
        fname = 'Resident_' + (<?php echo json_encode($user['id'] ?? $rid); ?> || 'ID') + '_QR.png';
      }
      var qrLink = <?php echo json_encode($link); ?> || '';
      var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' + encodeURIComponent(qrLink);
      function doDownload(){
        var link = document.createElement('a');
        link.href = qrUrl;
        link.download = fname;
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
  </script>
</body>
</html>
