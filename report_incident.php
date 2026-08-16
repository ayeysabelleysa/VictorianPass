<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';

// Redirect to login if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'resident') {
  header('Location: login.php');
  exit;
}

$userId = intval($_SESSION['user_id']);
$user = null;

// Fetch resident details for the form
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

$fullName = trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

$myReports = [];
if ($con instanceof mysqli) {
  $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    complainant VARCHAR(150) NOT NULL,
    subject VARCHAR(150) NULL,
    address VARCHAR(255) NOT NULL,
    nature VARCHAR(255) NULL,
    other_concern VARCHAR(255) NULL,
    user_id INT NULL,
    report_date DATE NULL,
    status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL,
    escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
    escalated_by_guard_id INT NULL,
    escalated_at DATETIME NULL,
    handled_by_guard_id INT NULL,
    handled_at DATETIME NULL,
    INDEX idx_status (status),
    INDEX idx_user_id (user_id),
    INDEX idx_escalated (escalated_to_admin)
  ) ENGINE=InnoDB");
  $colsToCheck = [
    'subject' => "VARCHAR(150) NULL",
    'report_date' => "DATE NULL",
    'escalated_to_admin' => "TINYINT(1) NOT NULL DEFAULT 0",
    'escalated_by_guard_id' => "INT NULL",
    'escalated_at' => "DATETIME NULL",
    'handled_by_guard_id' => "INT NULL",
    'handled_at' => "DATETIME NULL"
  ];
  foreach ($colsToCheck as $col => $def) {
    $check = $con->query("SHOW COLUMNS FROM incident_reports LIKE '$col'");
    if ($check && $check->num_rows === 0) {
      $con->query("ALTER TABLE incident_reports ADD COLUMN $col $def");
    }
  }
  $stmtM = $con->prepare("SELECT id, subject, address, nature, other_concern, report_date, status, created_at, escalated_to_admin, handled_by_guard_id FROM incident_reports WHERE user_id = ? ORDER BY created_at DESC");
  if ($stmtM) {
    $stmtM->bind_param('i', $userId);
    $stmtM->execute();
    $resM = $stmtM->get_result();
    while ($rowM = $resM->fetch_assoc()) {
      $myReports[] = $rowM;
    }
    $stmtM->close();
  }
}

// Handle Incident Report Submission
$reportSuccess = false;
$reportSuccessMessage = '';
$reportErrors = [];
$reportValues = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_report') {
    $complainant = trim($_POST['complainant'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $report_date = trim($_POST['report_date'] ?? '');
    $addr = trim($_POST['address'] ?? '');
    $other = trim($_POST['other'] ?? '');
    $natureArr = isset($_POST['nature']) && is_array($_POST['nature']) ? $_POST['nature'] : [];

    $reportValues = [
        'subject' => $subject,
        'report_date' => $report_date,
        'address' => $addr,
        'other' => $other,
        'nature' => $natureArr
    ];

    if ($subject === '') { $reportErrors[] = 'Complainee is required.'; }
    if ($addr === '') { $reportErrors[] = 'Address is required.'; }
    if ($report_date === '') { $reportErrors[] = 'Date is required.'; }
    if ($report_date !== '') {
        $tz = new DateTimeZone('Asia/Manila');
        $dt = DateTime::createFromFormat('Y-m-d', $report_date, $tz);
        if (!($dt && $dt->format('Y-m-d') === $report_date)) { $reportErrors[] = 'Date format is invalid.'; }
        else {
            $dt->setTime(0, 0, 0);
            $today = new DateTime('today', $tz);
            if ($dt > $today) { $reportErrors[] = 'Date must be today or earlier.'; }
        }
    }
    if (count($natureArr) === 0) { $reportErrors[] = 'Please select at least one nature of concern.'; }

    $uploadDir = 'resident_reports_uploads/';
    $allowed_exts = ['jpg','jpeg','png','pdf','docx'];
    $saved_files = [];
    $upload_errors = [];
    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
    
    if (!empty($_FILES['proof']) && is_array($_FILES['proof']['name'])) {
        $names = $_FILES['proof']['name'];
        $tmps  = $_FILES['proof']['tmp_name'];
        $errs  = $_FILES['proof']['error'];
        $sizes = $_FILES['proof']['size'];
        for ($i = 0; $i < count($names); $i++) {
            if ($errs[$i] !== UPLOAD_ERR_OK) { continue; }
            $ext = strtolower(pathinfo($names[$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts, true)) { $upload_errors[] = 'File type is not allowed: ' . $names[$i]; continue; }
            if ($sizes[$i] > 5 * 1024 * 1024) { $upload_errors[] = 'File size exceeds 5 MB: ' . $names[$i]; continue; }
            $safeName = preg_replace('/[^A-Za-z0-9_\-.]/', '_', $names[$i]);
            $newName = 'report_' . time() . '_' . $safeName;
            $dest = $uploadDir . $newName;
            if (move_uploaded_file($tmps[$i], $dest)) { $saved_files[] = $dest; }
        }
    }
    if (!empty($upload_errors)) { $reportErrors = array_merge($reportErrors, $upload_errors); }

    if (empty($reportErrors)) {
        // Ensure tables exist
        $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
          id INT AUTO_INCREMENT PRIMARY KEY,
          complainant VARCHAR(150) NOT NULL,
          subject VARCHAR(150) NULL,
          address VARCHAR(255) NOT NULL,
          nature VARCHAR(255) NULL,
          other_concern VARCHAR(255) NULL,
          user_id INT NULL,
          report_date DATE NULL,
          status ENUM('new','in_progress','resolved','rejected','cancelled') DEFAULT 'new',
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL,
          escalated_to_admin TINYINT(1) NOT NULL DEFAULT 0,
          escalated_by_guard_id INT NULL,
          escalated_at DATETIME NULL,
          handled_by_guard_id INT NULL,
          handled_at DATETIME NULL,
          INDEX idx_status (status),
          INDEX idx_user_id (user_id),
          INDEX idx_escalated (escalated_to_admin)
        ) ENGINE=InnoDB");
        
        // Ensure columns exist if table was already created without them
        $colsToCheck = [
            'subject' => "VARCHAR(150) NULL",
            'report_date' => "DATE NULL",
            'escalated_to_admin' => "TINYINT(1) NOT NULL DEFAULT 0",
            'escalated_by_guard_id' => "INT NULL",
            'escalated_at' => "DATETIME NULL",
            'handled_by_guard_id' => "INT NULL",
            'handled_at' => "DATETIME NULL"
        ];
        foreach ($colsToCheck as $col => $def) {
            $check = $con->query("SHOW COLUMNS FROM incident_reports LIKE '$col'");
            if ($check && $check->num_rows === 0) {
                $con->query("ALTER TABLE incident_reports ADD COLUMN $col $def");
            }
        }

        $con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          report_id INT NOT NULL,
          file_path VARCHAR(255) NOT NULL,
          uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          INDEX idx_report_id (report_id),
          FOREIGN KEY (report_id) REFERENCES incident_reports(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");

        $natureStr = implode(', ', array_map('strval', $natureArr));
        $uID = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
        $stmtR = $con->prepare("INSERT INTO incident_reports (complainant, subject, address, nature, other_concern, user_id, report_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmtR) {
            $stmtR->bind_param('sssssis', $complainant, $subject, $addr, $natureStr, $other, $uID, $report_date);
            if ($stmtR->execute()) {
                $report_id = $stmtR->insert_id;
                $stmtR->close();
                if (!empty($saved_files)) {
                    foreach ($saved_files as $p) {
                        $stmtP = $con->prepare("INSERT INTO incident_proofs (report_id, file_path) VALUES (?, ?)");
                        if ($stmtP) { $stmtP->bind_param('is', $report_id, $p); $stmtP->execute(); $stmtP->close(); }
                    }
                }
                $_SESSION['report_wait_popup'] = true;
                $_SESSION['report_wait_message'] = 'The report incident will be reviewed by the admin and guard.';
                header('Location: profileresident.php');
                exit;
            } else {
                $reportErrors[] = 'Failed to save report database entry.';
            }
        } else {
            $reportErrors[] = 'Failed to prepare report statement.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Report Incident - Victorian Heights</title>
<link rel="icon" type="image/png" href="images/logo.svg">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="css/dashboard.css">
<link rel="stylesheet" href="css/guestform.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
  .explanation-panel {
      background-color: #f9fafb;
      border-left: 4px solid #23412e;
      padding: 12px 14px;
      border-radius: 10px;
      margin-bottom: 12px;
  }
  .explanation-panel h3 { margin: 0 0 6px; color: #23412e; font-size: 1.05rem; }
  .explanation-panel p { color: #555; font-size: 0.85rem; line-height: 1.4; margin-bottom: 0; }
  .explanation-links { display: flex; gap: 16px; flex-wrap: wrap; }
  .explanation-links a { color: #23412e; font-weight: 600; text-decoration: none; border-bottom: 1px dashed #23412e; }
  
  .report-header { text-align: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 1px solid #f0f0f0; }
  .report-header h2 { margin: 0 0 4px; color: #23412e; font-size: 1.15rem; }
  .report-sub { color: #777; font-size: 0.82rem; margin-bottom: 8px; }
  .report-title { background: #23412e; color: #fff; display: inline-block; padding: 6px 16px; border-radius: 16px; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.6px; }

  .checkbox-group { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 6px; margin-top: 4px; }
  .checkbox-item { display: flex; align-items: center; gap: 6px; font-size: 0.82rem; cursor: pointer; padding: 6px 8px; border-radius: 8px; border: 1px solid #e5e7eb; background: #f8fafc; }
  .checkbox-item input { width: 18px; height: 18px; margin: 0; accent-color: #23412e; }
  .checkbox-item label { line-height: 1.3; }

  .agreement-group .checkbox-group { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
  .agreement-group .checkbox-item { align-items: flex-start; padding: 8px 10px; background: #fff; border-color: #e5e7eb; }
  .agreement-group .checkbox-item input { margin-top: 2px; }
  .agreement-group .checkbox-item label { font-size: 0.82rem; line-height: 1.3; }

  .error-box { background-color: #fee2e2; border: 1px solid #fecaca; color: #b91c1c; padding: 15px; border-radius: 10px; margin-bottom: 20px; }
  
  .hero { padding: 10px 2%; min-height: calc(100vh - 72px); }
  .entry-form { max-width: 920px; padding: 16px 16px; }
  .top-actions { width: 100%; max-width: 920px; margin-bottom: 8px; }
  .top-actions .btn-secondary { padding: 8px 12px; font-size: 0.82rem; }
  .btn-secondary.back-account-btn {
      background: #facc6b;
      color: #4b2e12;
      border-color: #fbbf24;
  }
  .btn-secondary.back-account-btn:hover {
      background: #fbbf24;
  }
  .static-group { margin-bottom: 12px; }
  .static-group label { display: block; margin-bottom: 4px; font-weight: 500; color: #23412e; font-size: 0.85rem; }
  #reportForm .form-group input,
  #reportForm .form-group textarea,
  #reportForm input[type="date"] {
      padding: 8px 10px;
      font-size: 0.85rem;
  }
  #reportForm textarea { min-height: 64px; }
  #reportForm .form-row { gap: 10px; }
  #reportForm .upload-box { padding: 8px; margin-bottom: 8px; font-size: 0.82rem; }
  #reportForm .upload-hint { font-size: 0.75rem; }

  .modal-content { max-width: 520px; padding: 26px 28px; text-align: left; position: relative; }
  .modal-content h3 { text-align: center; margin: 0 0 10px; color: #23412e; font-size: 1.2rem; }
  .modal-content p { margin-bottom: 12px; font-size: 0.92rem; color: #555; line-height: 1.6; }
  .modal-content .btn.btn-login { width: 100%; margin-top: 16px; padding: 12px 16px; font-size: 1rem; border-radius: 10px; }
  .modal-content .close-btn {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: #eef2f0;
      color: #23412e;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      border: 0;
      padding: 0;
      cursor: pointer;
  }
  .my-reports-content { max-width: 720px; max-height: 70vh; overflow-y: auto; }
  .my-reports-title { margin: 0 0 6px; color: #23412e; font-size: 1.2rem; text-align: left; }
  .my-reports-sub { margin: 0 0 14px; color: #6b7280; font-size: 0.92rem; text-align: left; }
  .my-reports-list { display: flex; flex-direction: column; gap: 14px; }
  .my-reports-list .list-item { cursor: default; }
  .my-reports-meta { display: flex; flex-wrap: wrap; gap: 8px 14px; font-size: 0.86rem; color: #6b7280; margin-top: 6px; }
  .my-reports-empty { padding: 16px; text-align: center; color: #777; background: #f8fafc; border: 1px dashed #d1d5db; border-radius: 10px; }
  .status-approved-guard { background-color: #e8f5e9; color: #2e7d32; }
  .status-approved-admin { background-color: #dcfce7; color: #166534; }
  .report-steps {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      box-shadow: 0 10px 24px rgba(0,0,0,0.12);
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      gap: 12px;
  }
  .report-steps-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
  }
  .report-steps-label {
      font-weight: 700;
      color: #23412e;
      font-size: 0.9rem;
  }
  .report-steps-toggle {
      border: 0;
      background: #edf2f0;
      color: #23412e;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      font-size: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
  }
  .report-steps-body { display: flex; flex-direction: column; gap: 10px; }
  .report-steps.is-collapsed .report-steps-body { display: none; }
  @media (min-width: 1024px){
      .report-steps{
          position: fixed;
          right: 32px;
          top: 110px;
          z-index: 900;
          max-width: 320px;
      }
  }
  @media (max-width: 900px) {
      .hero { padding: 8px 8px; min-height: auto; }
      .entry-form { padding: 12px 12px; transform: scale(0.92); transform-origin: top center; }
      .top-actions { max-width: 100%; }
      .checkbox-group { grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); }
  }
  @media (max-width: 600px) {
      .entry-form { padding: 10px; transform: scale(0.85); transform-origin: top center; }
      .report-title { font-size: 0.68rem; padding: 5px 12px; }
      .checkbox-group { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
      .report-steps { position: static; max-width: 100%; }
  }
</style>
</head>
<body>

    <header class="navbar">
      <div class="logo-wrap">
        <div class="logo">
          <img src="images/logo.svg" alt="Logo">
          <div class="brand-text">
            <h1>VictorianPass</h1>
            <p>Victorian Heights Subdivision</p>
          </div>
        </div>
      </div>
    </header>

    <div class="hero">
        <div class="top-actions" style="display:flex; gap:10px; flex-wrap:wrap;">
            <button type="button" class="btn-secondary back-account-btn" onclick="window.location.href='profileresident.php'">&#8592; Back to Account</button>
            <button type="button" class="btn-secondary" onclick="openModal('myReportsModal')">My Reported Incidents</button>
        </div>
        <div class="report-steps" aria-label="Report steps">
            <div class="report-steps-header">
                <div class="report-steps-label">Report steps</div>
                <button type="button" class="report-steps-toggle" id="reportStepsToggle" aria-label="Minimize instructions" aria-expanded="true">−</button>
            </div>
            <div class="report-steps-body">
                <div class="booking-step is-active">
                    <div class="step-index">1</div>
                    <div class="step-content">
                        <div class="step-title">Identify the incident</div>
                        <div class="step-subtitle">Enter the subject, location, and date of the incident</div>
                    </div>
                </div>
                <div class="booking-step">
                    <div class="step-index">2</div>
                    <div class="step-content">
                        <div class="step-title">Describe the concern</div>
                        <div class="step-subtitle">Select the nature and provide detailed notes</div>
                    </div>
                </div>
                <div class="booking-step">
                    <div class="step-index">3</div>
                    <div class="step-content">
                        <div class="step-title">Attach proof</div>
                        <div class="step-subtitle">Upload any images or documents if available</div>
                    </div>
                </div>
                <div class="booking-step">
                    <div class="step-index">4</div>
                    <div class="step-content">
                        <div class="step-title">Confirm and submit</div>
                        <div class="step-subtitle">Read the terms and rules before submitting</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="entry-form">
            <!-- Explanation / Intro -->
            <div class="explanation-panel">
                <h3>Resident Incident Report</h3>
                <p>Reporting complaints ensures that every resident’s voice is heard and that community standards are properly maintained. Please be responsible when filing a complaint.</p>
                <!--<div class="explanation-links">
                    <a href="#" onclick="openModal('termsModal'); return false;">Terms and Conditions</a>
                    <a href="#" onclick="openModal('rulesModal'); return false;">Rules and Regulations</a>
                </div> -->
            </div>

            <?php if (!empty($reportErrors)): ?>
                <div class="error-box">
                    <?php foreach ($reportErrors as $e): ?><div><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($e); ?></div><?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($reportSuccess): ?>
                <div style="background:#dcfce7; color:#166534; border:1px solid #bbf7d0; padding:15px; border-radius:8px; margin-bottom:20px; text-align:center;">
                    <i class="fa-solid fa-check-circle" style="font-size:1.2rem; margin-bottom:8px; display:block;"></i>
                    <strong><?php echo htmlspecialchars($reportSuccessMessage); ?></strong>
                </div>
            <?php endif; ?>

            <form id="reportForm" method="POST" enctype="multipart/form-data">
              <input type="hidden" name="action" value="submit_report">
              <div class="report-header">
                <h2>Victorian Heights Subdivision</h2>
                <div class="report-sub">Dahlia Fairview, BRGY. Sauyo, Quezon City</div>
                <div class="report-title">CASE REPORT FORM</div>
              </div>

              <input type="hidden" id="complainant" name="complainant" value="<?php echo htmlspecialchars($fullName); ?>">

              <div class="form-group">
                  <input type="text" id="subject" name="subject" placeholder=" " value="<?php echo htmlspecialchars($reportValues['subject'] ?? ''); ?>" required>
                  <label for="subject">Complainee / Subject Name*</label>
              </div>

          <div class="form-row">
              <div class="form-group">
                  <input type="text" id="address" name="address" placeholder=" " value="<?php echo htmlspecialchars($reportValues['address'] ?? ''); ?>" required>
                  <label for="address">Address / Location*</label>
              </div>
              <div class="form-group">
                  <input type="date" id="report_date" name="report_date" value="<?php echo htmlspecialchars($reportValues['report_date'] ?? date('Y-m-d')); ?>" required max="<?php echo date('Y-m-d'); ?>">
                  <label for="report_date">Date of Incident*</label>
              </div>
          </div>

          <div class="static-group">
              <label>Nature of Concern / Complaint*</label>
              <div class="checkbox-group">
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Noise"> Noise</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Harassment"> Harassment</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Vandalism"> Vandalism</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Theft"> Theft</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Parking"> Parking</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Pet Issue"> Pet Issue</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Garbage"> Garbage</label>
                  <label class="checkbox-item"><input type="checkbox" name="nature[]" value="Other"> Other</label>
              </div>
          </div>

          <div class="static-group">
              <label for="other">If Other, please specify details:</label>
              <textarea id="other" name="other" rows="4" placeholder="Describe the incident..."><?php echo htmlspecialchars($reportValues['other'] ?? ''); ?></textarea>
          </div>

          <div class="static-group">
              <label>Attach Proof (Images/Docs) <span style="color:#6b7280; font-weight:500;">Optional</span></label>
              <label class="upload-box">
                  <input type="file" id="proofInput" name="proof[]" multiple accept=".jpg,.jpeg,.png,.pdf,.docx" style="display:none;" onchange="updateFileList(this)">
                  <div class="upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                  <div class="upload-text">Click to upload files</div>
                  <div class="upload-hint">Max 5MB per file (JPG, PNG, PDF, DOCX)</div>
                  <div id="fileList" style="margin-top:10px; font-size:0.85rem; color:#23412e;"></div>
              </label>
              <div style="margin-top:6px; font-size:0.82rem; color:#6b7280;">Uploading proof is optional.</div>
          </div>

          <div class="static-group agreement-group">
              <label>Confirm Agreements*</label>
              <div class="checkbox-group">
                  <div class="checkbox-item" onclick="checkIfRead('terms_agree', event)">
                      <input type="checkbox" id="terms_agree" name="terms_agree" required disabled>
                      <label for="terms_agree" style="cursor:pointer;">
                        I have read and agree to the Terms and Conditions.
                        <a href="#" onclick="openModal('termsModal'); event.stopPropagation(); return false;" style="text-decoration: underline; color:#23412e; font-weight:600;">Read Terms and Conditions</a>
                      </label>
                  </div>
                  <div class="checkbox-item" onclick="checkIfRead('rules_agree', event)">
                      <input type="checkbox" id="rules_agree" name="rules_agree" required disabled>
                      <label for="rules_agree" style="cursor:pointer;">
                        I have read and agree to the Rules and Regulations.
                        <a href="#" onclick="openModal('rulesModal'); event.stopPropagation(); return false;" style="text-decoration: underline; color:#23412e; font-weight:600;">Read Rules and Regulations</a>
                      </label>
                  </div>
              </div>
          </div>

          <button type="submit" class="btn btn-login" style="width:100%; margin-top:20px; font-size:1.1rem; padding:15px;">Submit Report</button>
        </form>
    </div>

    <div class="modal" id="myReportsModal">
      <div class="modal-content my-reports-content">
        <span class="close-btn" onclick="closeModal('myReportsModal')">&times;</span>
        <h3 class="my-reports-title">My Reported Incidents</h3>
        <p class="my-reports-sub">All incidents you have reported in the system.</p>
        <?php if (empty($myReports)): ?>
          <div class="my-reports-empty">No incidents reported yet.</div>
        <?php else: ?>
          <div class="my-reports-list">
            <?php foreach ($myReports as $r): ?>
              <?php
                $approvalLabel = 'Pending';
                $badgeClass = 'status-badge status-pending';
                if (!empty($r['escalated_to_admin'])) {
                  $approvalLabel = 'Admin Approved';
                  $badgeClass = 'status-badge status-approved-admin';
                } elseif (!empty($r['handled_by_guard_id'])) {
                  $approvalLabel = 'Guard Approved';
                  $badgeClass = 'status-badge status-approved-guard';
                }
                $rDate = !empty($r['report_date']) ? date('m/d/y', strtotime($r['report_date'])) : date('m/d/y', strtotime($r['created_at']));
                $natureLabel = $r['nature'] ?: ($r['other_concern'] ?: '-');
                $subjectLabel = $r['subject'] ?: 'Untitled Report';
              ?>
              <div class="list-item">
                <div class="item-icon"><i class="fa-solid fa-file-lines"></i></div>
                <div class="item-content">
                  <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                    <div>
                      <span class="<?php echo $badgeClass; ?>"><?php echo htmlspecialchars($approvalLabel); ?></span>
                      <span class="item-title"><?php echo htmlspecialchars($subjectLabel); ?></span>
                    </div>
                    <div class="item-time"><?php echo htmlspecialchars($rDate); ?></div>
                  </div>
                  <div class="my-reports-meta">
                    <span><strong>Nature:</strong> <?php echo htmlspecialchars($natureLabel); ?></span>
                    <span><strong>Address:</strong> <?php echo htmlspecialchars($r['address'] ?? '-'); ?></span>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

<script>
let selectedProofs = [];
function updateFileList(input) {
    const list = document.getElementById('fileList');
    list.innerHTML = '';
    if (input.files && input.files.length > 0) {
        selectedProofs = Array.from(input.files);
    }
    if (selectedProofs.length === 0) { return; }
    let ul = document.createElement('ul');
    ul.style.listStyle = 'none';
    ul.style.padding = '0';
    selectedProofs.forEach(function(file, idx){
        let li = document.createElement('li');
        li.style.display = 'flex';
        li.style.alignItems = 'center';
        li.style.justifyContent = 'space-between';
        li.style.gap = '10px';
        li.style.padding = '6px 0';
        let left = document.createElement('div');
        left.textContent = file.name;
        let actions = document.createElement('div');
        actions.style.display = 'flex';
        actions.style.gap = '8px';
        if (/(\.png|\.jpg|\.jpeg)$/i.test(file.name)) {
            let viewBtn = document.createElement('button');
            viewBtn.type = 'button';
            viewBtn.textContent = 'View';
            viewBtn.style.border = '1px solid #e5e7eb';
            viewBtn.style.background = '#f9fafb';
            viewBtn.style.color = '#111827';
            viewBtn.style.padding = '4px 8px';
            viewBtn.style.borderRadius = '6px';
            viewBtn.style.cursor = 'pointer';
            viewBtn.onclick = function(){
                const url = URL.createObjectURL(file);
                window.open(url, '_blank');
                setTimeout(function(){ URL.revokeObjectURL(url); }, 1000);
            };
            actions.appendChild(viewBtn);
        }
        let removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.textContent = 'Remove';
        removeBtn.style.border = '1px solid #e5e7eb';
        removeBtn.style.background = '#fee2e2';
        removeBtn.style.color = '#b91c1c';
        removeBtn.style.padding = '4px 8px';
        removeBtn.style.borderRadius = '6px';
        removeBtn.style.cursor = 'pointer';
        removeBtn.onclick = function(){
            selectedProofs = selectedProofs.filter(function(_, i){ return i !== idx; });
            const dt = new DataTransfer();
            selectedProofs.forEach(function(f){ dt.items.add(f); });
            input.files = dt.files;
            updateFileList(input);
        };
        actions.appendChild(removeBtn);
        li.appendChild(left);
        li.appendChild(actions);
        ul.appendChild(li);
    });
    list.appendChild(ul);
}

function openModal(id) {
    document.getElementById(id).style.display = "flex";
}

function closeModal(id) {
    document.getElementById(id).style.display = "none";
}

function checkIfRead(type, event) {
    const checkbox = document.getElementById(type);
    if (checkbox && checkbox.disabled) {
        let msg = '';
        if (type === 'terms_agree') {
            msg = 'Please read the Terms and Conditions first.';
        } else {
            msg = 'Please read the Rules and Regulations first.';
        }
        alert(msg);
    }
}

function agreeTerms() {
    const terms = document.getElementById('terms_agree');
    if (terms) {
        terms.checked = true;
        terms.disabled = false;
    }
    closeModal('termsModal');
}

function agreeRules() {
    const rules = document.getElementById('rules_agree');
    if (rules) {
        rules.checked = true;
        rules.disabled = false;
    }
    closeModal('rulesModal');
}

window.onclick = function(e) {
    if (e.target.className === 'modal') {
        e.target.style.display = "none";
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('reportForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        const terms = document.getElementById('terms_agree');
        const rules = document.getElementById('rules_agree');
        if (terms && !terms.checked) {
            e.preventDefault();
            openModal('termsModal');
            return;
        }
        if (rules && !rules.checked) {
            e.preventDefault();
            openModal('rulesModal');
        }
    });
    const panel=document.querySelector('.report-steps');
    const toggle=document.getElementById('reportStepsToggle');
    if(panel && toggle){
        toggle.addEventListener('click', function(){
            const collapsed=panel.classList.toggle('is-collapsed');
            toggle.textContent=collapsed?'+':'−';
            toggle.setAttribute('aria-expanded',collapsed?'false':'true');
        });
    }
});
</script>

<!-- Terms Modal -->
<div class="modal" id="termsModal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('termsModal')">&times;</span>
        <h3>Terms and Conditions</h3>
        <p>By submitting this complaint form, you agree to the following:</p>
        <p style="text-align: left; font-size: 0.9rem;">
            1. Complaints must be submitted in good faith and based on facts.<br>
            2. False or malicious complaints may result in penalties.<br>
            3. The HOA reserves the right to investigate and validate all concerns.<br>
            4. Confidentiality will be respected, but information may be shared when necessary.<br>
            5. Filing a complaint does not guarantee resolution in favor of the complainant.<br>
            6. All parties involved will be treated fairly during the resolution process.
        </p>
        <button type="button" class="btn btn-login" onclick="agreeTerms()">Confirm</button>
    </div>
</div>

<!-- Rules Modal -->
<div class="modal" id="rulesModal">
    <div class="modal-content">
        <span class="close-btn" onclick="closeModal('rulesModal')">&times;</span>
        <h3>Rules and Regulations</h3>
        <p>In using this website you are deemed to have read and agreed to the following rules and regulations:</p>
        <div style="text-align: left; max-height: 300px; overflow-y: auto; font-size: 0.9rem;">
            <p><strong>1. Use Accurate Information</strong><br>All users must provide truthful and complete information when signing up.</p>
            <p><strong>2. Respect Assigned Roles</strong><br>Users must act according to their roles: Residents may book amenities/register visitors; Visitors must present valid QR codes.</p>
            <p><strong>3. QR Code Use</strong><br>QR codes are personal, time-bound, and must not be shared or reused.</p>
            <p><strong>4. Follow Subdivision Policies</strong><br>All users must follow community rules. Misuse of the system may lead to suspension or blacklisting.</p>
        </div>
        <button type="button" class="btn btn-login" onclick="agreeRules()">Confirm</button>
    </div>
</div>
</body>
</html>
