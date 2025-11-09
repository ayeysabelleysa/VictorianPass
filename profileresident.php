<?php
session_start();
require_once 'connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details
if ($con) {
$stmt = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address FROM users WHERE id = ?");
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

// Handle profile updates (phone, address)
$updateMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile' && $con) {
  $newPhone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
  $newAddress = isset($_POST['address']) ? trim($_POST['address']) : '';
  $errs = [];
  if ($newPhone !== '' && !preg_match('/^\+63\d+$/', $newPhone)) {
    $errs[] = 'Phone must start with +63 and contain digits only.';
  }
  if ($newAddress === '') {
    $errs[] = 'Address cannot be empty.';
  }
  if (empty($errs)) {
    $stmt = mysqli_prepare($con, "UPDATE users SET phone = ?, address = ? WHERE id = ?");
    if ($stmt) {
      mysqli_stmt_bind_param($stmt, 'ssi', $newPhone, $newAddress, $userId);
      if (mysqli_stmt_execute($stmt)) {
        $updateMessage = 'Profile updated successfully.';
        // Refresh user data
        $stmt2 = mysqli_prepare($con, "SELECT id, first_name, middle_name, last_name, email, phone, birthdate, house_number, address FROM users WHERE id = ?");
        if ($stmt2) {
          mysqli_stmt_bind_param($stmt2, 'i', $userId);
          mysqli_stmt_execute($stmt2);
          $result2 = mysqli_stmt_get_result($stmt2);
          if ($result2 && mysqli_num_rows($result2) === 1) {
            $user = mysqli_fetch_assoc($result2);
          }
          mysqli_stmt_close($stmt2);
        }
      } else {
        $updateMessage = 'Failed to update profile. Please try again.';
      }
      mysqli_stmt_close($stmt);
    } else {
      $updateMessage = 'System error. Please try later.';
    }
  } else {
    $updateMessage = implode(' ', $errs);
  }
}

if (!$user) {
  // Fallback if user not found
  header('Location: mainpage.php');
  exit;
}
// Compose full name for display
$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

// Prepare unique QR code data and local image path
$qrData = 'VPRES:' . ($user['id'] ?? $userId) . '|' . ($user['email'] ?? '') . '|' . substr(hash('sha256', ($user['id'] ?? $userId) . '|' . ($user['email'] ?? '')), 0, 12);
$qrRelPath = 'uploads/qr_resident_' . ($user['id'] ?? $userId) . '.png';
$qrAbsPath = __DIR__ . '/' . $qrRelPath;
if (!file_exists($qrAbsPath)) {
  // Generate QR via external service and cache locally
  $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($qrData);
  $img = @file_get_contents($qrUrl);
  if ($img !== false) {
    @file_put_contents($qrAbsPath, $img);
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>User Profile - Residents</title>
<link rel="icon" type="image/png" href="mainpage/logo.svg">


<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
  /* GLOBAL */
  body {
    margin: 0;
    font-family: 'Poppins', sans-serif;
    background: #f5f5f5;
    color: #333;
  }
  .header {
    background: #305c3c;
    padding: 12px 20px;
    font-weight: 600;
    border-bottom: 1px solid #ddd;
    display: flex;
    align-items: center;
    justify-content: flex-start;
  }
  .brand {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #f1f9f1;
  }
  .brand img { width: 28px; height: 28px; }
  /* Header actions removed in favor of sidebar-only navigation */

  .container { display: flex; min-height: calc(100vh - 50px); }

  /* SIDEBAR */
  .sidebar {
    width: 250px;
    background: #2b2b2b;
    color: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between; /* ✅ pushes report button to bottom */
    padding-top: 20px;
  }
  .menu-top {
    display: flex;
    flex-direction: column;
  }
  .menu-bottom {
    padding: 20px 15px;
    border-top: 1px solid #444;
  }
  .menu-item {
    background: #f3ebd2;
    color: #333;
    margin: 10px 15px;
    padding: 12px 20px;
    border-radius: 20px 0 0 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.3s ease;
  }
  .menu-item.compact { margin: 6px 15px; }
  .menu-item:hover { background: #ddd3b8; transform: translateX(5px); }
  .menu-item a {
    text-decoration: none;
    color: inherit;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .menu-item img { width: 20px; height: 20px; flex-shrink: 0; }
  .menu-item.logout { color: #c62828; font-weight: 600; }
  .menu-item.logout:hover { background: #f8d7da; }

  .menu-item.report { color: #3e8e41; font-weight: 600; }
  .menu-item.report:hover { background: #d4d6d2; }
  .report-note {
    display: none; /* removed: bottom section will only have logout */
  }
  .menu-note, .menu-note-pair {
    font-size: 12px;
    color: #aaaaaa;
    margin: 4px 15px 8px 15px;
    line-height: 1.3;
  }
  .menu-note-pair { margin-top: 2px; }

  /* MAIN */
  .main {
    flex: 1;
    padding: 20px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
  }
  .card {
    background: #fff;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 4px 10px rgba(0,0,0,0.1);
    font-size: 14px;
  }

  /* PROFILE */
  .profile-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 20px;
  }
  .profile-header img { width: 60px; height: 60px; border-radius: 50%; }
  .profile-header h3 { margin: 0; font-size: 18px; font-weight: 600; }
  .profile-header p { font-size: 13px; color: #666; margin: 0; }
  .info-row {
    display: flex;
    justify-content: space-between;
    margin: 12px 0;
    font-size: 14px;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
  }
  .info-label { font-weight: 500; color: #444; }
  .info-value { color: #666; }
  .save-btn {
    background: #305c3c;
    color: #fff;
    padding: 10px 20px;
    border-radius: 8px;
    margin-top: 15px;
    font-size: 14px;
    cursor: pointer;
    border: none;
    transition: all 0.3s ease;
  }
  .save-btn:hover { background: #264d31; transform: scale(1.05); }

  /* (Removed guest form duplicate button styles) */

  /* ENTRIES TABLE */
  .entries-card h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    font-weight: 600;
    color: #fff;
    background: #305c3c;
    padding: 8px 12px;
    border-radius: 6px 6px 0 0;
  }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th, td { padding: 10px; text-align: left; }
  th { background: #f1f1f1; color: #333; }
  tr:nth-child(even) { background: #f9f9f9; }
  tr:hover { background: #f1f9f1; transition: 0.3s; }
  .status { padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .active  { background: #4caf50; color: #fff; }
  .pending { background: #2196f3; color: #fff; }
  .expired { background: #9e9e9e; color: #fff; }
  .denied  { background: #e53935; color: #fff; }

  /* VIEW BUTTONS */
  .view-btn {
    display: inline-block;
    padding: 6px 14px;
    background: #305c3c;
    color: #fff;
    font-size: 13px;
    font-weight: 600;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-family: 'Poppins', sans-serif;
    transition: all 0.25s ease;
    text-decoration: none;
  }
  .view-btn:hover {
    background: #264d31;
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
  }

  .qr-container { text-align: center; padding-top: 20px; }
  .qr-container img { width: 200px; height: 200px; object-fit: contain; margin-bottom: 10px; border-radius: 8px; box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
  .qr-container p { font-size: 14px; margin: 5px 0; color: #23412e; }

  /* RESPONSIVE */
  @media (max-width: 992px) { .main { grid-template-columns: 1fr; } }
  @media (max-width: 768px) { .sidebar { width: 200px; } }
  @media (max-width: 576px) {
    .container { flex-direction: column; }
    .sidebar {
      width: 100%;
      flex-direction: column;
    }
    .menu-item { border-radius: 10px; margin: 5px 15px; justify-content: center; font-size: 12px; }
    .main { padding: 15px; }
  }
</style>
</head>

<body>
  <div class="header">
    <div class="brand">
      <img src="mainpage/logo.svg" alt="VictorianPass">
      <span>Your Profile & Dashboard</span>
    </div>
  </div>

  <div class="container">
    <!-- Sidebar -->
    <div class="sidebar">
      <div class="menu-top">
        <div class="menu-item"><a href="profileresident.php"><img src="dashboard.svg">Dashboard</a></div>
        <div class="menu-item"><a href="profileresident.php"><img src="dashboard.svg">Profile</a></div>
        <br><div class="menu-item compact"><a href="guestform.php"><img src="mainpage/ticket.svg">Guest Form</a></div>
         <div class="menu-note-pair">Create a visitor request and share the status code.</div>
        <br><div class="menu-item compact report"><a href="residentreport.php"><img src="mainpage/report.svg">Report Incident</a></div>
        <div class="menu-note-pair"><br>Submit complaints or concerns to the subdivision admin.
        </div>
      </div>

      <div class="menu-bottom">
        <div class="menu-item logout"><a href="logout.php"><img src="mainpage/login.svg">Log Out</a></div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="main">
      <!-- Profile Info -->
      <div class="card">
        <div class="profile-header">
          <img src="mainpage/profile'.jpg" alt="Profile Picture">
          <div>
            <h3><?php echo htmlspecialchars($fullName ?: 'Resident'); ?></h3>
            <p><?php echo htmlspecialchars($user['email']); ?></p>
          </div>
        </div>
        <?php if (!empty($updateMessage)) { ?>
          <div style="margin-bottom:10px;padding:10px 12px;border-left:4px solid #23412e;background:#f1f9f1;color:#23412e;border-radius:6px;">
            <?php echo htmlspecialchars($updateMessage); ?>
          </div>
        <?php } ?>

        <div class="info-row"><span class="info-label">Name:</span><span class="info-value"><?php echo htmlspecialchars($fullName); ?></span></div>
        <div class="info-row"><span class="info-label">Email:</span><span class="info-value" id="emailVal"><?php echo htmlspecialchars($user['email']); ?></span></div>

        <form id="updateForm" method="POST" action="">
          <input type="hidden" name="action" value="update_profile">
          <div class="info-row"><span class="info-label">Mobile:</span>
            <span class="info-value">
              <input type="tel" name="phone" value="<?php echo htmlspecialchars($user['phone'] ?? '+63'); ?>" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;">
            </span>
          </div>
          <div class="info-row"><span class="info-label">Address:</span>
            <span class="info-value">
              <input type="text" name="address" value="<?php echo htmlspecialchars($user['address'] ?? ''); ?>" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:8px;">
            </span>
          </div>
          <div class="info-row"><span class="info-label">Birth date:</span><span class="info-value"><?php echo htmlspecialchars($user['birthdate'] ?? ''); ?></span></div>
          <button type="submit" class="save-btn">Save Changes</button>
        </form>
      </div>

      <!-- Entries & Requests -->
      <div class="card entries-card">
        <h3>Entries & Requests</h3>
        <?php
          // Fetch latest reservations/requests for this resident
          $reservations = [];
          if ($con) {
            $stmtR = mysqli_prepare($con, "SELECT ref_code, amenity, start_date, end_date, approval_status FROM reservations WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
            if ($stmtR) {
              mysqli_stmt_bind_param($stmtR, 'i', $userId);
              mysqli_stmt_execute($stmtR);
              $resR = mysqli_stmt_get_result($stmtR);
              if ($resR) {
                while ($rowR = mysqli_fetch_assoc($resR)) { $reservations[] = $rowR; }
              }
              mysqli_stmt_close($stmtR);
            }
          }

          // Helper to compute status and css class
          function vp_compute_status($row) {
            $today = date('Y-m-d');
            $raw = isset($row['approval_status']) && $row['approval_status'] !== '' ? strtolower($row['approval_status']) : 'pending';
            if ($raw === 'approved') {
              if (!empty($row['end_date']) && $row['end_date'] < $today) { return ['expired','expired']; }
              return ['approved','active'];
            }
            if ($raw === 'denied' || $raw === 'rejected') { return ['denied','denied']; }
            // fallback check for expiry
            if (!empty($row['end_date']) && $row['end_date'] < $today) { return ['expired','expired']; }
            return ['pending','pending'];
          }
        ?>
        <table>
          <tr>
            <th>Action</th>
            <th>Dates</th>
            <th>Status</th>
            <th>View</th>
          </tr>
          <?php if (!empty($reservations)) { ?>
            <?php foreach ($reservations as $r) { list($statusText, $cssClass) = vp_compute_status($r); ?>
              <tr>
                <td><?php echo htmlspecialchars($r['amenity']); ?></td>
                <td>
                  <small>
                    <?php echo htmlspecialchars(date('M d, Y', strtotime($r['start_date']))); ?>
                    —
                    <?php echo htmlspecialchars(date('M d, Y', strtotime($r['end_date']))); ?>
                  </small>
                </td>
                <td><span class="status <?php echo $cssClass; ?>"><?php echo ucfirst($statusText); ?></span></td>
                <td>
                  <?php if (!empty($r['ref_code'])) { ?>
                    <a class="view-btn" href="status_view.php?code=<?php echo urlencode($r['ref_code']); ?>">View</a>
                  <?php } else { ?>
                    <span style="color:#777;">N/A</span>
                  <?php } ?>
                </td>
              </tr>
            <?php } ?>
          <?php } else { ?>
            <tr>
              <td colspan="4" style="text-align:center;color:#777;">No requests yet. Create one via Guest Form or Amenity Reservation.</td>
            </tr>
          <?php } ?>
        </table>

        <div class="qr-container">
          <img src="<?php echo htmlspecialchars($qrRelPath); ?>" alt="Resident QR Code">
          <p><?php echo htmlspecialchars($fullName ?: 'Your name'); ?> <br><small>Verified User</small></p>
          <a class="save-btn" href="<?php echo htmlspecialchars($qrRelPath); ?>" download="VictorianPass_QR_<?php echo intval($user['id'] ?? $userId); ?>.png">Download QR Code</a>
        </div>
      </div>
    </div>
  </div>
  <script>
    function promptUpdate(){
      var newEmail = prompt('Enter new email (leave blank to keep):', document.getElementById('emailVal').textContent.trim());
      if (newEmail === null) return; // cancelled
      var newPass = prompt('Enter new password (leave blank to keep):');
      if (newEmail !== null) document.getElementById('emailInput').value = newEmail.trim();
      if (newPass !== null) document.getElementById('passwordInput').value = newPass.trim();
      document.getElementById('updateForm').submit();
    }
  </script>
</body>
</html>