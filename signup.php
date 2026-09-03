<?php 
include("connect.php");
require_once __DIR__ . '/session_bootstrap.php';
$registration_success = false;

$verified_house = isset($_GET['house_number']) ? trim($_GET['house_number']) : '';

function ensureVisitorSchema($con){
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'user_type'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strpos($row['Type'], "visitor") === false) {
      $con->query("ALTER TABLE users MODIFY COLUMN user_type ENUM('resident','visitor') DEFAULT 'resident'");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'house_number'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN house_number VARCHAR(50) NULL");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'address'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN address VARCHAR(255) NULL");
    }
  }
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'valid_id_path'");
  if ($res && $res->num_rows === 0) {
    $con->query("ALTER TABLE users ADD COLUMN valid_id_path VARCHAR(255) NULL");
  }
  
  // Ensure status column includes 'pending'
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
  if ($res && $res->num_rows === 0) {
    $con->query("ALTER TABLE users ADD COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'pending'");
  } else if ($res && ($row = $res->fetch_assoc())) {
    if (stripos($row['Type'], 'pending') === false) {
      $con->query("ALTER TABLE users MODIFY COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'pending'");
    }
  }
}
if (!vpSchemaDone($con, 'signup_v1')) { ensureVisitorSchema($con); vpMarkSchemaDone($con, 'signup_v1'); }

// AJAX endpoint: check email availability
if (isset($_GET['action']) && $_GET['action'] === 'check_email') {
  header('Content-Type: application/json');
  $email = isset($_GET['email']) ? strtolower(trim($_GET['email'])) : '';
  $resp = ['ok' => false, 'message' => 'Invalid email'];
  if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
    try {
      $stmt = $con->prepare("SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1");
      $stmt->bind_param('s', $email);
      $stmt->execute();
      $stmt->store_result();
      if ($stmt->num_rows > 0) {
        $resp = ['ok' => false, 'message' => 'Email already exists.'];
      } else {
        $resp = ['ok' => true, 'message' => 'Available'];
      }
      $stmt->close();
    } catch (Throwable $e) {
      $resp = ['ok' => false, 'message' => 'Lookup failed'];
    }
  }
  echo json_encode($resp);
  exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $first_name = trim($_POST['first_name']);
  $middle_name = trim($_POST['middle_name']);
  $last_name = trim($_POST['last_name']);
  $phone = trim($_POST['phone']);
  $email = strtolower(trim($_POST['email']));
  $password = trim($_POST['password']);
  $confirm_password = trim($_POST['confirm_password']);
  $sex = $_POST['sex'];
  $birthdate = $_POST['birthdate'];
  $house_number = isset($_POST['house_number']) ? trim($_POST['house_number']) : '';
  $address = isset($_POST['address']) ? trim($_POST['address']) : '';
  $terms_agreed = isset($_POST['terms_agreed']) ? $_POST['terms_agreed'] : '0';
  
  $is_visitor = isset($_POST['is_visitor']) && $_POST['is_visitor'] === '1';
  $serverErrors = [];

  // File Upload Logic
  $valid_id_path = null;
  if ($is_visitor) {
      if (isset($_FILES['valid_id']) && $_FILES['valid_id']['error'] === UPLOAD_ERR_OK) {
          $uploadDir = 'uploads/ids/';
          if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
          $fileName = time() . '_' . basename($_FILES['valid_id']['name']);
          $targetPath = $uploadDir . $fileName;
          if (move_uploaded_file($_FILES['valid_id']['tmp_name'], $targetPath)) {
              $valid_id_path = $targetPath;
          } else {
              $serverErrors['valid_id'] = 'Failed to upload Valid ID.';
          }
      } elseif (!isset($_FILES['valid_id']) || $_FILES['valid_id']['error'] !== UPLOAD_ERR_OK) {
          $serverErrors['valid_id'] = 'Valid ID is required for visitors.';
      }
  }

  if ($password !== $confirm_password) {
    $serverErrors['confirm_password'] = 'Passwords do not match.';
  }

  // Password strength validation
  $commonPasswords = file('common_passwords.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (in_array($password, $commonPasswords)) {
    $serverErrors['password'] = 'Password is too weak.';
  }
  if (strlen($password) < 6) {
    $serverErrors['password'] = 'Password must be at least 6 characters long.';
  }
  if (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
    $serverErrors['password'] = 'Password must include uppercase, lowercase, number, and special character.';
  }

  if ($terms_agreed !== '1') {
    $serverErrors['terms'] = 'Please read and agree to the Terms and Services.';
  }

  if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $serverErrors['email'] = 'Invalid email address.';
  } else {
    $checkEmail = $con->prepare("SELECT id FROM users WHERE LOWER(email) = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    $checkEmail->store_result();
    if ($checkEmail->num_rows > 0) {
      $serverErrors['email'] = 'This email is already registered.';
    }
    $checkEmail->close();
  }

  if (empty($birthdate)) {
    $serverErrors['birthdate'] = 'Birthdate is required.';
  } else {
    $dob = DateTime::createFromFormat('Y-m-d', $birthdate);
    $dobErrors = DateTime::getLastErrors();
    $hasDobErrors = is_array($dobErrors) && ($dobErrors['warning_count'] > 0 || $dobErrors['error_count'] > 0);
    if (!$dob || $hasDobErrors) {
      $serverErrors['birthdate'] = 'Birthdate is invalid.';
    } else {
      $today = new DateTime('today');
      if ($dob > $today) {
        $serverErrors['birthdate'] = 'Birthdate cannot be in the future.';
      }
    }
  }

  if (!$is_visitor) {
    if (empty($house_number)) {
      $serverErrors['houseHidden'] = 'Please verify your House Number before signing up.';
    } else {
      $checkHouse = $con->prepare("SELECT id FROM houses WHERE house_number = ?");
      $checkHouse->bind_param("s", $house_number);
      $checkHouse->execute();
      $checkHouse->store_result();
      if ($checkHouse->num_rows === 0) {
        $serverErrors['houseHidden'] = 'Invalid house number.';
      }
      $checkHouse->close();

      $checkDuplicate = $con->prepare("SELECT id FROM users WHERE house_number = ?");
      $checkDuplicate->bind_param("s", $house_number);
      $checkDuplicate->execute();
      $checkDuplicate->store_result();
      if ($checkDuplicate->num_rows > 0) {
        $serverErrors['houseHidden'] = 'This house number is already in use.';
      }
      $checkDuplicate->close();
    }
    
    if (empty($address)) {
      $serverErrors['addressField'] = 'Please enter your full address.';
    }
  } else {
    $house_number = null;
    $address = null;
  }

  // Normalize phone number to 09 format (11 digits)
  $phoneClean = preg_replace('/[\s\-]/', '', $phone);
  // Remove leading +63 or 63
  if (preg_match('/^(\+63|63)(9\d{9})$/', $phoneClean, $matches)) {
      $phone = '0' . $matches[2];
  } elseif (preg_match('/^0(9\d{9})$/', $phoneClean, $matches)) {
      $phone = '0' . $matches[1];
  } elseif (preg_match('/^(9\d{9})$/', $phoneClean, $matches)) {
      $phone = '0' . $matches[1];
  } else {
      // Keep original for error reporting
  }

  if (empty($phone) || !preg_match('/^09\d{9}$/', $phone)) {
    $serverErrors['phone'] = 'Phone number must be 11 digits and start with 09 (e.g., 09XX XXX XXXX).';
  }

  if (empty($serverErrors)) {
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $user_type = $is_visitor ? 'visitor' : 'resident';
    $status = ($user_type === 'visitor') ? 'active' : 'pending';
    $stmt = $con->prepare("INSERT INTO users 
      (first_name, middle_name, last_name, phone, email, password, sex, birthdate, house_number, address, user_type, valid_id_path, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssssssss", $first_name, $middle_name, $last_name, $phone, $email, $hashed, $sex, $birthdate, $house_number, $address, $user_type, $valid_id_path, $status);

    if ($stmt->execute()) {
      $newUserId = $stmt->insert_id;
      $_SESSION['user_id'] = $newUserId;
      $_SESSION['user_type'] = $user_type;
      
      $redirect = ($user_type === 'resident') ? 'profileresident.php' : 'dashboardvisitor.php';

      if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        echo json_encode(['success' => true, 'redirect' => $redirect]);
        exit;
      }

      header("Location: $redirect");
      exit;
    } else {
      if ($con->errno === 1062) {
        $serverErrors['email'] = 'This email is already registered.';
      } else {
        $serverErrors['form'] = 'An error occurred. Please try again.';
      }
    }
  }

  if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
    echo json_encode(['success' => false, 'errors' => $serverErrors]);
    exit;
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sign Up</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <?php $_signupCss = @filemtime(__DIR__ . '/css/signup.css') ?: 1; ?>
<link rel="stylesheet" href="css/signup.css?v=<?php echo $_signupCss; ?>">
  <style>
    .success-banner {
      background: #e6ffed;
      color: #135f2a;
      border: 1px solid #b7e3c3;
      padding: 12px 16px;
      border-radius: 8px;
      margin-bottom: 12px;
      text-align: center;
      box-shadow: 0 2px 8px rgba(0,0,0,0.12);
      font-weight: 600;
    }
    .form-group { position: relative; flex: 1; }
    .form-group input[type="date"], .form-group select {
      width: 100%; padding: 12px; border: 1px solid #ccc;
      border-radius: 8px; font-size: 0.95rem; font-family: 'Poppins', sans-serif;
    }
    .form-group label {
      position: absolute; left: 12px; top: 12px;
      color: #888; font-size: 0.95rem;
      transition: 0.2s ease all; background: #fff; padding: 0 4px;
    }
    .form-group input[type="date"]:focus + label,
    .form-group input[type="date"]:not(:placeholder-shown) + label,
    .form-group select:focus + label,
    .form-group select:valid + label {
      top: -8px; left: 8px; font-size: 0.75rem; color: #23412e;
    }

    .instructions {
      font-size: 0.75rem;
      color: #555;
      margin-top: 10px;
      text-align: center;
    }

    .verify-link {
      display: flex;
      align-items: center;
      gap: 8px;
      color: green;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
    }

    .verify-link img {
      width: 20px;
      height: 20px;
    }

    .verify-link:hover {
      text-decoration: underline;
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
      display: flex; /* notification-style inline card */
      align-items: flex-start;
      gap: 8px;
      position: relative;
      z-index: 2; /* ensure close button clickable over toggle icon */
    }
    .password-field { position: relative; display: block; }
    .password-field input { padding-right: 40px !important; }
    .toggle-password { 
      position: absolute; 
      right: 12px; 
      top: 50%; 
      transform: translateY(-50%); 
      z-index: 10; 
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #666;
      width: 24px;
      height: 24px;
      transition: color 0.2s ease;
    }
    .toggle-password:hover { color: #23412e; }
    .toggle-password svg { width: 20px; height: 20px; }
    .field-warning .warn-icon {
      width: 18px; height: 18px; border-radius: 50%;
      background: #c0392b; color: #fff; display: inline-flex;
      align-items: center; justify-content: center; font-size: 0.75rem;
      flex-shrink: 0; line-height: 1;
    }
    .field-warning .msg { color: #333; }
    .field-warning .close-warn { margin-left: auto; background: transparent; border: 0; font-size: 1rem; }
    .field-warning:empty { display: none; }
    .close-warn { cursor: pointer; color: #888; line-height: 1; }
    .close-warn:hover { color: #555; }
    /* compact inline warning for house number to avoid layout shifts */
    .field-warning-inline {
      color: #333;
      font-size: 0.85rem;
      margin-top: 6px;
      background: #fff;
      border-left: 4px solid #c0392b;
      border-radius: 6px;
      padding: 6px 8px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      max-width: 220px;
      box-shadow: 0 1px 6px rgba(0,0,0,0.08);
    }
    .field-warning-inline .warn-icon{ width:14px; height:14px; font-size:0.7rem; }
    
    /* Role Selector Styles */
    .role-selector {
      display: flex;
      gap: 10px;
      margin-bottom: 20px;
      background: #f0fdf4;
      padding: 5px;
      border-radius: 8px;
      border: 1px solid #dcfce7;
    }
    .role-option {
      flex: 1;
      text-align: center;
      padding: 12px;
      cursor: pointer;
      border-radius: 6px;
      transition: all 0.2s;
      border: 1px solid transparent;
      color: #166534;
      display: flex;
      flex-direction: column;
      gap: 4px;
      position: relative;
    }
    .role-option:hover {
      background: rgba(22, 101, 52, 0.05);
    }
    .role-option.active {
      background: #fff;
      color: #15803d;
      box-shadow: 0 2px 4px rgba(0,0,0,0.05);
      border-color: #bbf7d0;
    }
    .role-option .role-title {
      font-size: 1rem;
      font-weight: 600;
      display: block;
    }
    .role-option .role-desc {
      font-size: 0.75rem;
      font-weight: 400;
      opacity: 0.8;
      display: block;
    }

    /* Center Modal Styles */
    .center-modal {
      display: none;
      position: fixed;
      z-index: 9999;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
    }
    .center-modal-content {
      background-color: #fff;
      padding: 30px;
      border-radius: 12px;
      width: 90%;
      max-width: 400px;
      text-align: center;
      position: relative;
      box-shadow: 0 4px 15px rgba(0,0,0,0.2);
      animation: fadeIn 0.3s;
    }
    .center-modal-content h3 {
      margin-top: 0;
      color: #c0392b;
    }
    .center-modal-content p {
      color: #333;
      margin: 15px 0;
    }
    .center-modal-btn {
      display: inline-block;
      margin-top: 15px;
      padding: 10px 20px;
      background-color: #23412e;
      color: white;
      text-decoration: none;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      font-weight: 500;
    }
    .center-modal-btn:hover {
      background-color: #1a3022;
    }
    .close,
    .close-center {
      position: absolute;
      top: 12px;
      right: 12px;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      background: #e5e7eb;
      color: #111827;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      cursor: pointer;
      line-height: 1;
      border: 0;
      padding: 0;
    }
    .close:hover,
    .close-center:hover {
      filter: brightness(0.95);
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
    @keyframes slideDownFade {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .field-warning, .field-warning-inline {
      animation: slideDownFade 0.3s ease-out forwards;
    }
  </style>
</head>

<body>
  <div class="page-wrapper">
    <div class="image-side">
    </div>

    <div class="form-side">
      <a href="mainpage.php" class="back-arrow" aria-label="Back">
        <i class="fa-solid fa-arrow-left"></i>
      </a>

      <img src="images/loginpage/biglogo.svg" alt="Logo" class="biglogo">

      <?php if ($registration_success): ?>
        <div class="success-banner"><i class="fa-solid fa-circle-check"></i> You have successfully registered! Redirecting to login…</div>
      <?php endif; ?>
      <h1>Sign Up</h1>
      <p class="subtitle">Create your VictorianPass account to get started.</p>

      <form class="signup-form" id="signupForm" method="POST" action="signup.php" enctype="multipart/form-data" novalidate <?php if ($registration_success) echo 'style="display:none"'; ?>>
        <input type="hidden" id="terms_agreed" name="terms_agreed" value="0">
        
        <!-- Role Selector -->
        <input type="hidden" id="isVisitor" name="is_visitor" value="0">
        <div class="role-selector">
          <div class="role-option active" id="optResident" data-value="0">
            <span class="role-title">Resident</span>
            <span class="role-desc">I live in this subdivision</span>
          </div>
          <div class="role-option" id="optVisitor" data-value="1">
            <span class="role-title">Visitor</span>
            <span class="role-desc">I am going to reserve amenities</span>
          </div>
        </div>
        <div class="form-row">
          <div class="input-wrap">
            <input type="text" name="first_name" id="first_name" placeholder="First Name*" required>
          </div>
          <div class="input-wrap">
            <input type="text" name="middle_name" id="middle_name" placeholder="Middle Name (optional)">
          </div>
          <div class="input-wrap">
            <input type="text" name="last_name" id="last_name" placeholder="Last Name*" required>
          </div>
        </div>

        <div class="form-row">
          <div class="input-wrap">
            <input type="tel" name="phone" id="phone" placeholder="Phone Number*" required>
            <span style="display:block; font-size:0.75rem; color:#666; margin-top:4px;">Format: 09XX XXX XXXX (11 digits)</span>
          </div>
        </div>

        <div class="input-wrap">
          <input type="email" name="email" id="email" placeholder="Email*" required>
        </div>



        <!-- House verification section -->
        <div class="form-row homeowner" style="align-items: center; justify-content: space-between; gap: 1rem; border: 1px solid #eee; padding: 10px; border-radius: 8px; background: #fafafa;">
          <div style="flex: 1;">
            <div style="display: flex; align-items: center; gap: 8px;">
              <img src="images/signuppage/location.svg" alt="Location" style="width: 20px;">
              <span style="font-weight: 600; color: #23412e; font-size: 1.1rem;">House Number</span>
            </div>
            <div style="font-size: 0.8rem; color: #555; margin-top: 4px; font-weight: 500;">
              Please include your house number to complete your resident registration.
            </div>
          </div>
          
          <input type="text" id="houseHidden" name="house_number" placeholder="VH-0000" value="<?php echo htmlspecialchars($verified_house); ?>" style="flex: 0 0 140px; padding: 12px; border: 1px solid #ddd; border-radius: 6px; background: #fff; color: #333; font-weight: 500; font-family: inherit; font-size: 0.9rem;">
        </div>

        <div class="input-wrap" id="visitorIdWrap" style="margin-bottom: 15px;">
          <label for="valid_id" style="display:block; margin-bottom:5px; font-weight:600; color:#23412e;">Upload Valid ID (Required for Visitors • Max 5MB)</label>
          
          <div id="fileUploadContainer">
            <label class="upload-box">
              <input type="file" name="valid_id" id="valid_id" accept="image/*,.pdf" hidden>
              <img src="images/mainpage/upload.svg" alt="Upload">
              <p>Upload Valid ID*<br><small>(e.g. National ID, Driver’s License)</small><br><small>Max 5MB</small></p>
            </label>
          </div>

          <div id="filePreviewContainer" style="display:none; margin-top: 10px; border: 1px solid #ddd; padding: 10px; border-radius: 8px; align-items: center; justify-content: space-between; background: #fafafa;">
            <div id="previewContent" style="display: flex; align-items: center; gap: 10px;">
              <!-- Preview content will be injected here -->
            </div>
            <button type="button" id="removeFileBtn" style="background: #ff4d4d; color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 0.85rem; transition: background 0.2s;">Remove</button>
          </div>

          <p style="font-size: 0.75rem; color: #555; margin-top: 10px; line-height: 1.5; background: #f0f7f4; padding: 10px; border-radius: 6px; border-left: 4px solid #23412e;">
             <strong>Data Privacy Notice:</strong> Your uploaded ID is used only for verification and stored securely. Access is limited to authorized staff, in accordance with the Data Privacy Act of 2012.
          </p>

          <?php if (isset($serverErrors['valid_id'])): ?>
            <div class="field-warning" role="alert" style="display: flex;">
              <span class="warn-icon">!</span>
              <span class="warn-msg"><?php echo htmlspecialchars($serverErrors['valid_id']); ?></span>
            </div>
          <?php endif; ?>
        </div>

        <!-- House verification section (Duplicate removed) -->

        <div class="input-wrap">
          <input type="text" id="addressField" name="address" placeholder="Address (e.g., Blk 00 Lot 00, Street, Subdivision)*" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <select name="sex" required>
              <option value="" disabled selected></option>
              <option value="Male">Male</option>
              <option value="Female">Female</option>
            </select>
            <label>Sex*</label>
          </div>
          <div class="form-group">
            <input type="date" id="birthdate" name="birthdate" placeholder=" " required>
            <label>Birthdate*</label>
          </div>
        </div>

        <div class="input-wrap">
          <div class="password-field">
            <input type="password" id="password" name="password" placeholder="Password*" required>
            <span class="toggle-password" onclick="togglePassword('password', this)">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </span>
          </div>
        </div>

        <div class="input-wrap">
          <div class="password-field">
            <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm Password*" required>
            <span class="toggle-password" onclick="togglePassword('confirm_password', this)">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </span>
          </div>
        </div>

        <script>
          const passwordInput = document.getElementById('password');
          const confirmPwd = document.getElementById('confirm_password');

      // Password complexity check - removed real-time validation
      // passwordInput.addEventListener('blur', () => { ... });

      // Password match check - removed real-time validation
      // if (confirmPwd) { confirmPwd.addEventListener('blur', ...); }

        </script>

        <div class="terms" style="display: flex; flex-direction: column; gap: 15px;">
          <!-- Terms Checkbox -->
          <div class="checkbox-wrapper" onclick="checkIfRead('terms', event)" style="display: flex; align-items: flex-start; gap: 8px;">
            <input type="checkbox" id="terms" required disabled style="width:auto; margin-top: 4px;">
            <label for="terms" style="position:static; margin:0; font-size:0.9rem; line-height: 1.4; cursor: pointer;">
              By using our service, you agree to our Terms and Services. Here’s what you need to know. 
              <a onclick="openTerms(); event.stopPropagation();" style="text-decoration: underline; color: rgb(245, 63, 169); cursor:pointer; font-weight: 600;">Read Terms And Services</a>
            </label>
          </div>

          <!-- Privacy Checkbox -->
          <div class="checkbox-wrapper" onclick="checkIfRead('privacy', event)" style="display: flex; align-items: flex-start; gap: 8px;">
            <input type="checkbox" id="privacy" required disabled style="width:auto; margin-top: 4px;">
            <label for="privacy" style="position:static; margin:0; font-size:0.9rem; line-height: 1.4; cursor: pointer;">
              By using our service, you agree to our Privacy Policy. Here’s what you need to know. 
              <a onclick="openPrivacy(); event.stopPropagation();" style="text-decoration: underline; color: rgb(245, 63, 169); cursor:pointer; font-weight: 600;">Read Privacy Policy</a>
            </label>
          </div>
        </div>

        <div class="form-actions" style="display:flex;gap:10px;justify-content:space-between;margin-top:12px;">
          <button type="button" class="btn cancel" onclick="window.location.href='mainpage.php'" style="background:#e5e7eb;color:#111;border:none;padding:10px 20px;border-radius:10px;font-weight:600;">Cancel</button>
          <button type="submit" class="btn confirm" style="background:#23412e;color:#fff;border:none;padding:10px 20px;border-radius:10px;font-weight:600;">Confirm</button>
        </div>

        <p class="login-link">Already have an account? <a href="login.php">Log in</a></p>
      </form>

      
    </div>

    <!-- Terms Modal -->
    <div id="termsModal" class="modal">
      <div class="modal-content" style="max-width: 700px; padding: 40px; border-radius: 20px;">
        <button type="button" class="close" onclick="closeTerms()" aria-label="Close">&times;</button>
        <h2 style="text-align: center; font-weight: 700; font-size: 1.5rem; margin-bottom: 20px; color: #222;">Terms & Services</h2>
        
        <p style="text-align: center; font-weight: 600; margin-bottom: 25px; line-height: 1.5; color: #000;">
          In using this website you are deemed to have read and agreed to the following Terms and Services:
        </p>

        <div style="font-size: 0.95rem; color: #333; line-height: 1.6;">
          <p style="margin-bottom: 15px;">
            The following terminology applies to these Terms and Services, Privacy Statement and Disclaimer Notice and any or all Agreements: “Customer”, “You” and “Your” refers to you, the person accessing this website and accepting the Company’s Terms and Services.
          </p>
          <p style="margin-bottom: 25px;">Effective Date: [September 2026]</p>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">User Roles</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Residents: Must provide accurate info, manage guest entries responsibly, and use the system for valid purposes only.</li>
              <li>Visitors: Must present valid QR codes and follow subdivision rules.</li>
              <li>Admins/Guards/HOA: Manage logs, approve entries, and maintain system security.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">Privacy and Data</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Your data is used only for entry validation and amenity booking.</li>
              <li>No data will be shared without consent unless required by law.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">Amenity Booking</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Bookings are first-come, first-served.</li>
              <li>Cancel if unable to attend.</li>
              <li>Misuse may result in account restriction.</li>
              <li>All billings will be done by walk-in.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">Age-Based Rules (Children must have an adult)</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>Children below 16 cannot reserve amenities without the supervision of their guardians. during the QR scanning phase, All age requirements must be met before entry is granted.</li>
            </ul>
          </div>

          <div style="margin-bottom: 20px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;"></h4>QR Code Rules</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>QR codes are unique and time-limited.</li>
              <li>Sharing or tampering with codes is prohibited.</li>
            </ul>
          </div>

          <div style="margin-bottom: 30px;">
            <h4 style="margin: 0 0 8px 0; font-weight: 600; color: #444; font-size: 1rem;">System Use</h4>
            <ul style="padding-left: 20px; list-style-type: disc; margin: 0;">
              <li>System may go offline for updates.</li>
              <li>Users accept possible downtime.</li>
            </ul>
          </div>
        </div>

        <button class="btn confirm" onclick="agreeTerms()" style="width: 100%; background-color: #355340; padding: 12px; border-radius: 6px; font-size: 1rem;">Confirm</button>
      </div>
    </div>

    <!-- Privacy Modal -->
    <div id="privacyModal" class="modal">
      <div class="modal-content" style="max-width: 700px; padding: 40px; border-radius: 20px;">
        <button type="button" class="close" onclick="closePrivacy()" aria-label="Close">&times;</button>
        <h2 style="text-align: center; font-weight: 700; font-size: 1.5rem; margin-bottom: 20px; color: #222;">Privacy Policy</h2>
        
        <div style="font-size: 0.95rem; color: #333; line-height: 1.6;">
          <p style="margin-bottom: 20px;">
            Data Privacy Act of 2012 Notice: In accordance with the law Republic Act NO. 10173 - It is the policy of the State to protect the fundamental human right of privacy, of communication while ensuring free flow of information to promote innovation and growth. its inherent obligation to ensure that personal information in information and communications systems in the government and in the private sector are secured and protected.
          </p>
          <p style="margin-bottom: 30px;">
            The Data Gathered is only limited on verification and for recordings limited only to the authorized staff
          </p>
        </div>

        <button class="btn confirm" onclick="agreePrivacy()" style="width: 100%; background-color: #355340; padding: 12px; border-radius: 6px; font-size: 1rem;">Confirm</button>
      </div>
    </div>
    
  </div>
  

  <script>
    function togglePassword(id, el) {
      const input = document.getElementById(id);
      if (!input) return;
      
      const isPassword = input.type === 'password';
      input.type = isPassword ? 'text' : 'password';
      
      if (isPassword) {
        el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye-off"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
      } else {
        el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
      }
    }

    function openTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'block';
    }

    function closeTerms() {
      const modal = document.getElementById('termsModal');
      if (modal) modal.style.display = 'none';
    }

    function openPrivacy() {
      const modal = document.getElementById('privacyModal');
      if (modal) modal.style.display = 'block';
    }

    function closePrivacy() {
      const modal = document.getElementById('privacyModal');
      if (modal) modal.style.display = 'none';
    }

    function agreePrivacy() {
      const privacy = document.getElementById('privacy');
      if (privacy) {
        privacy.disabled = false;
        privacy.checked = true;
      }
      closePrivacy();
      if (typeof setWarning === 'function') setWarning('privacy', '');
    }

    function checkIfRead(type, event) {
      const checkbox = document.getElementById(type);
      // If checkbox is disabled, it means they haven't agreed via modal yet.
      // We check if the click target was NOT the link (which has its own handler).
      // Since we use stopPropagation on the link, this function fires for clicks on the wrapper/label/checkbox.
      
      if (checkbox && checkbox.disabled) {
        // Show warning
        let msg = '';
        if (type === 'terms') {
          msg = 'Please read the Terms and Services first.';
        } else {
          msg = 'Please read the Privacy Policy first.';
        }
        
        // We can use the existing setWarning or a simple alert/popover. 
        // Given the requirement "add a warning if they didnt read those 2", using setWarning is consistent.
        if (typeof setWarning === 'function') setWarning(type, msg);
      } else if (checkbox && !checkbox.disabled) {
          // If enabled, allow toggle (browser handles click on input/label, but we caught it on wrapper)
          // If the user clicked the wrapper but not the input, we might need to manually toggle if the wrapper is not a label.
          // However, our wrapper contains the label which triggers the input.
          // But wait, if we click the wrapper (div), it doesn't automatically trigger the input unless the input is inside a label or we do it manually.
          // In my HTML structure:
          // <div class="checkbox-wrapper" onclick="...">
          //   <input ...>
          //   <label ...>
          // </div>
          // Clicking the label triggers the input. Clicking the div outside label/input does nothing by default.
          // But 'checkIfRead' is on the wrapper.
          
          // If the event target is the input or label, let it propagate (if enabled).
          // If disabled, we want to intercept.
          // Since the input is disabled, clicking it or the label won't change its state.
          
          // So the logic is fine: if disabled, warn.
      }
    }

    function agreeTerms() {
      const terms = document.getElementById('terms');
      if (terms) {
        terms.checked = true;
        terms.disabled = false;
      }
      const ta = document.getElementById('terms_agreed');
      if (ta) { ta.value = '1'; }
      closeTerms();
      // Clear any popover on terms
      if (typeof setWarning === 'function') setWarning('terms', '');
    }

    // Floating popover warnings anchored under inputs
    const _popovers = {};
    function _ensureLayer(){ return document.getElementById('warnLayer'); }
    function _positionPopover(inputEl, pop){
      const rect = inputEl.getBoundingClientRect();
      const top = rect.bottom + 8 + window.scrollY;
      let left = rect.left + window.scrollX;
      // Clamp within viewport
      const vw = document.documentElement.clientWidth;
      const popW = pop.offsetWidth || 260;
      const minLeft = 12 + window.scrollX;
      const maxLeft = vw - popW - 12 + window.scrollX;
      left = Math.max(minLeft, Math.min(left, maxLeft));
      pop.style.top = top + 'px';
      pop.style.left = left + 'px';
    }
    function _removePopover(key){
      const layer = _ensureLayer();
      const pop = _popovers[key];
      if (pop && layer && pop.parentNode === layer) layer.removeChild(pop);
      delete _popovers[key];
    }
    function _showPopover(inputId, message){
      const inputEl = document.getElementById(inputId);
      if (!inputEl || !message) return;
      const layer = _ensureLayer();
      if (!layer) return;
      let pop = _popovers[inputId];
      if (!pop){
        pop = document.createElement('div');
        pop.className = 'field-popover';
        pop.setAttribute('role','alert');
        pop.dataset.key = inputId;
        pop.innerHTML = '<span class="popover-icon">!</span><span class="msg"></span><span class="popover-close" aria-label="Dismiss" title="Dismiss">&times;</span>';
        layer.appendChild(pop);
        _popovers[inputId] = pop;
        const closer = pop.querySelector('.popover-close');
        closer.setAttribute('tabindex','0');
        closer.addEventListener('click', function(){ _removePopover(inputId); });
        closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter'||evt.key===' ') { evt.preventDefault(); _removePopover(inputId);} });
      }
      const msg = pop.querySelector('.msg');
      if (msg) msg.textContent = message;
      _positionPopover(inputEl, pop);
    }
    function _repositionAll(){
      Object.keys(_popovers).forEach(function(key){
        const inp = document.getElementById(key);
        const pop = _popovers[key];
        if (inp && pop) _positionPopover(inp, pop);
      });
    }
    window.addEventListener('scroll', _repositionAll, { passive: true });
    window.addEventListener('resize', _repositionAll);
    function setWarning(key, message){
      const inputEl = document.getElementById(key);
      let container = null;
      if (key === 'houseHidden') {
        container = document.querySelector('.homeowner');
      } else if (key === 'terms' || key === 'privacy') {
        container = document.querySelector('.terms');
      } else if (inputEl) {
        container = inputEl.closest('.input-wrap') || inputEl.closest('.password-field') || inputEl.closest('.form-group');
      }
      if (!container) return;
      // Special-case: houseHidden should show a compact inline warning next to the input
      let warnEl;
      if (key === 'houseHidden') {
        const inputEl = document.getElementById('houseHidden');
        if (!inputEl) return;
        // attach after the input so it doesn't expand the whole homeowner container
        warnEl = inputEl.parentNode.querySelector('.field-warning-inline[data-for="'+key+'"]');
        if (message){
          if (!warnEl){
            warnEl = document.createElement('div');
            warnEl.className = 'field-warning-inline';
            warnEl.setAttribute('data-for', key);
            warnEl.setAttribute('role','alert');
            // insert after the input element
            if (inputEl.nextSibling) inputEl.parentNode.insertBefore(warnEl, inputEl.nextSibling);
            else inputEl.parentNode.appendChild(warnEl);
          }
        }
      } else {
        warnEl = container.querySelector('.field-warning[data-for="'+key+'"]');
        if (message){
          if (!warnEl){
            warnEl = document.createElement('div');
            warnEl.className = 'field-warning';
            warnEl.setAttribute('data-for', key);
            warnEl.setAttribute('role','alert');
            container.appendChild(warnEl);
          }
        }
      }
      if (message){
        // Build notification-style content
        let icon = warnEl.querySelector('.warn-icon');
        if (!icon){
          icon = document.createElement('span');
          icon.className = 'warn-icon';
          icon.textContent = '!';
          warnEl.appendChild(icon);
        }
        let msgSpan = warnEl.querySelector('.msg');
        if (!msgSpan){
          msgSpan = document.createElement('span');
          msgSpan.className = 'msg';
          warnEl.appendChild(msgSpan);
        }
        msgSpan.innerHTML = message;
        let closer = warnEl.querySelector('.close-warn');
        if (!closer){
          closer = document.createElement('button');
          closer.type = 'button';
          closer.className = 'close-warn';
          closer.setAttribute('aria-label','Dismiss');
          closer.textContent = '\u00d7';
          warnEl.appendChild(closer);
          closer.addEventListener('click', function(){ warnEl.remove(); });
          closer.addEventListener('keydown', function(evt){ if (evt.key==='Enter' || evt.key===' '){ evt.preventDefault(); warnEl.remove(); } });
        }
        // Auto-dismiss warning if input becomes valid
        if (inputEl && (key === 'phone' || key === 'email')) {
          inputEl.addEventListener('input', function autoDismiss(){
            if ((key === 'phone' && /^09\d{9}$/.test(inputEl.value.replace(/\D/g, ''))) || (key === 'email' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(inputEl.value.trim()))) {
              if (warnEl) warnEl.remove();
              inputEl.removeEventListener('input', autoDismiss);
            }
          });
        }
      } else {
        if (warnEl) warnEl.remove();
      }
    }

    // Prevent Enter key from submitting form
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        return false;
      }
    });

    document.addEventListener('DOMContentLoaded', function() {
      const first = document.getElementById('first_name');
      const last = document.getElementById('last_name');
      const middle = document.getElementById('middle_name');
      const phone = document.getElementById('phone');
      const terms = document.getElementById('terms');
      const password = document.getElementById('password');
      const confirmPwd = document.getElementById('confirm_password');
      const houseHidden = document.getElementById('houseHidden');
      const isVisitor = document.getElementById('isVisitor');
      const homeownerRow = document.querySelector('.homeowner');
      const addressField = document.getElementById('addressField');
      const birthdate = document.getElementById('birthdate');
      const form = document.getElementById('signupForm');

      function blockInvalidNameChars(e) {
        // Allow navigation keys, backspace, tab, etc.
        if (['Backspace', 'Tab', 'ArrowLeft', 'ArrowRight', 'Delete', 'Enter'].includes(e.key)) return;
        
        // Allow letters, spaces, and hyphens
        if (!/^[a-zA-Z\s\-]$/.test(e.key)) {
          e.preventDefault();
          setWarning(e.target.id, 'Only letters, spaces, and hyphens are allowed.');
        } else {
           setWarning(e.target.id, '');
        }
      }

      function sanitizeNameInput(e) {
        const val = e.target.value;
        // Keep only letters, spaces, and hyphens
        const cleaned = val.replace(/[^a-zA-Z\s\-]/g, '');
        if (val !== cleaned) {
          e.target.value = cleaned;
          setWarning(e.target.id, 'Only letters, spaces, and hyphens are allowed.');
        } else {
           setWarning(e.target.id, '');
        }
      }

      [first, last, middle].forEach(function(inp) {
        if (!inp) return;
        inp.addEventListener('keydown', blockInvalidNameChars);
        inp.addEventListener('input', sanitizeNameInput);
      });

      if (phone) {
        phone.setAttribute('maxlength', '20');
        
        phone.addEventListener('input', function(e) {
          // Allow digits, plus sign, and spaces
          let val = e.target.value.replace(/[^0-9+\s]/g, '');
          
          if (e.target.value !== val) {
            e.target.value = val;
          }
          
          // Basic format guidance while typing
          const clean = val.replace(/\D/g, '');
          if (clean.length > 0) {
             // Just ensure it looks vaguely like a phone number
             setWarning('phone', '');
          }
        });
        
        phone.addEventListener('blur', function(e) {
           let val = e.target.value.trim();
           // Normalize logic
           // 1. Strip everything except digits
           let clean = val.replace(/\D/g, '');
           
           // 2. Check patterns
           // 09XX... (11 digits) -> 09XX...
           // 639XX... (12 digits) -> 09XX...
           // 9XX... (10 digits) -> 09XX...
           
           let normalized = '';
           
           if (clean.length === 11 && clean.startsWith('09')) {
              normalized = clean;
           } else if (clean.length === 12 && clean.startsWith('639')) {
              normalized = '0' + clean.substring(2);
           } else if (clean.length === 10 && clean.startsWith('9')) {
              normalized = '0' + clean;
           } else {
              // Invalid or unrecognized format
              if (val.length > 0) {
                 setWarning('phone', 'Format: 09XX XXX XXXX (11 digits)');
              }
              return;
           }
           
           // Format for display: 09XX XXX XXXX
           // 09171234567 -> 0917 123 4567
           if (normalized) {
              const part1 = normalized.substring(0, 4); // 0917
              const part2 = normalized.substring(4, 7); // 123
              const part3 = normalized.substring(7);    // 4567
              
              e.target.value = `${part1} ${part2} ${part3}`;
              setWarning('phone', '');
           }
        });
      }

      // Real-time password validation (debounce)
      let pwdTimer = null;
      if (password) {
        password.addEventListener('input', function() {
          clearTimeout(pwdTimer);
          // Clear warning while typing to avoid flickering or annoying messages
          setWarning('password', ''); 
          
          pwdTimer = setTimeout(function() {
             const val = password.value || '';
             if (val.length === 0) return;
             
             // Check requirements: at least 6 chars, letters, and numbers
             if (val.length < 6 || !/[a-zA-Z]/.test(val) || !/[0-9]/.test(val)) {
                setWarning('password', 'Password must be at least 6 characters and include letters and numbers.');
             } else {
                setWarning('password', '');
             }
             
             // Also re-check match if confirm password has value
             if (confirmPwd && confirmPwd.value) {
                 if (val !== confirmPwd.value) {
                     setWarning('confirm_password', 'Passwords do not match.');
                 } else {
                     setWarning('confirm_password', '');
                 }
             }
          }, 800); // Check after user stops typing
        });
      }

      // Confirm Password validation (debounce)
      let confirmTimer = null;
      if (confirmPwd) {
        confirmPwd.addEventListener('input', function() {
           clearTimeout(confirmTimer);
           setWarning('confirm_password', '');
           
           confirmTimer = setTimeout(function() {
              const val = confirmPwd.value || '';
              // No mismatch error will be shown while the Confirm Password field is empty
              if (!val) {
                 setWarning('confirm_password', '');
                 return;
              }
              
              if (password && val !== password.value) {
                 setWarning('confirm_password', 'Passwords do not match.');
              } else {
                 setWarning('confirm_password', '');
              }
           }, 800);
        });
      }

        // Email format check + async existence check
        const emailEl = document.getElementById('email');
        let emailTimer = null;
        if (emailEl) {
          // Real-time email validation removed
          /*
          emailEl.addEventListener('input', function(e){ ... });
          */
        }



      // House number verification on blur - removed real-time validation
      /*
      if (houseHidden) {
        houseHidden.addEventListener('blur', function(e) {
             const val = e.target.value.trim();
             if (!val) {
                setWarning('houseHidden', '');
                return;
             }
             
             // Check against server
             fetch('verify_house.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ajax:'1', house_number: val }).toString()
             })
             .then(r => r.json())
             .then(data => {
                if (!data.success) {
                    setWarning('houseHidden', data.message || 'Invalid House Number');
                } else {
                    setWarning('houseHidden', '');
                }
             })
             .catch(err => console.error(err));
        });
      }
      */

      function updateFormMode() {
        const isVisitorInput = document.getElementById('isVisitor');
        let isVis = false;
        if (isVisitorInput.type === 'checkbox') {
            isVis = isVisitorInput.checked;
        } else {
            isVis = isVisitorInput.value === '1';
        }

        const idWrap = document.getElementById('visitorIdWrap');
        
        // Update Selector UI
        const optRes = document.getElementById('optResident');
        const optVis = document.getElementById('optVisitor');
        if(optRes && optVis) {
            if(isVis) {
                optRes.classList.remove('active');
                optVis.classList.add('active');
            } else {
                optRes.classList.add('active');
                optVis.classList.remove('active');
            }
        }

        if (isVis) {
            // Visitor Mode
            if (homeownerRow) homeownerRow.style.display = 'none';
            if (addressField && addressField.closest('.input-wrap')) addressField.closest('.input-wrap').style.display = 'none';
            if (idWrap) idWrap.style.display = 'block';

            if (houseHidden) houseHidden.value = ''; // Clear house number
            if (addressField) addressField.required = false;
        } else {
            // Resident Mode
            if (homeownerRow) homeownerRow.style.display = 'flex'; // Restore flex layout
            if (addressField && addressField.closest('.input-wrap')) addressField.closest('.input-wrap').style.display = 'block';
            if (idWrap) idWrap.style.display = 'none';

            if (addressField) addressField.required = true;
        }
      }

      // Attach listeners to role options
      const optRes = document.getElementById('optResident');
      const optVis = document.getElementById('optVisitor');
      
      if(optRes && optVis) {
          optRes.addEventListener('click', function() {
              const inp = document.getElementById('isVisitor');
              if(inp) inp.value = '0';
              updateFormMode();
          });
          optVis.addEventListener('click', function() {
              const inp = document.getElementById('isVisitor');
              if(inp) inp.value = '1';
              updateFormMode();
          });
      }

      const isVisitorCheckbox = document.getElementById('isVisitor');
      if (isVisitorCheckbox) {
        isVisitorCheckbox.addEventListener('change', updateFormMode);
        updateFormMode();
      }

      // ID Upload Preview Logic
      const validIdInput = document.getElementById('valid_id');
      const fileUploadContainer = document.getElementById('fileUploadContainer');
      const filePreviewContainer = document.getElementById('filePreviewContainer');
      const previewContent = document.getElementById('previewContent');
      const removeFileBtn = document.getElementById('removeFileBtn');

      if (validIdInput && filePreviewContainer && previewContent && removeFileBtn && fileUploadContainer) {
        
        validIdInput.addEventListener('change', function(e) {
          const file = e.target.files[0];
          if (!file) return;

          // Clear previous preview
          previewContent.innerHTML = '';

          if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.style.maxWidth = '60px';
            img.style.maxHeight = '60px';
            img.style.borderRadius = '4px';
            img.style.objectFit = 'cover';
            img.style.border = '1px solid #ddd';
            img.style.cursor = 'pointer';

            const reader = new FileReader();
            reader.onload = function(evt) {
              const src = evt.target.result;
              img.src = src;
              img.addEventListener('click', function() {
                openImagePreview(src);
              });
            };
            reader.readAsDataURL(file);
            previewContent.appendChild(img);
          } else {
            // For PDF or other types
            const icon = document.createElement('span');
            icon.innerHTML = '<i class="fa-solid fa-file-lines"></i>';
            icon.style.fontSize = '24px';
            previewContent.appendChild(icon);
          }

          const infoDiv = document.createElement('div');
          infoDiv.style.display = 'flex';
          infoDiv.style.flexDirection = 'column';
          // infoDiv.style.marginLeft = '10px';
          
          const nameSpan = document.createElement('span');
          nameSpan.textContent = file.name;
          nameSpan.style.fontSize = '0.85rem';
          nameSpan.style.fontWeight = '500';
          nameSpan.style.color = '#333';
          nameSpan.style.wordBreak = 'break-all'; 
          
          const sizeSpan = document.createElement('span');
          sizeSpan.textContent = (file.size / 1024).toFixed(1) + ' KB';
          sizeSpan.style.fontSize = '0.75rem';
          sizeSpan.style.color = '#777';

          infoDiv.appendChild(nameSpan);
          infoDiv.appendChild(sizeSpan);
          previewContent.appendChild(infoDiv);

          // Show preview, hide upload input
          fileUploadContainer.style.display = 'none';
          filePreviewContainer.style.display = 'flex';
        });

        removeFileBtn.addEventListener('click', function() {
          validIdInput.value = ''; // Clear input
          previewContent.innerHTML = '';
          filePreviewContainer.style.display = 'none';
          fileUploadContainer.style.display = 'block';
        });
      }

      if (form) {
        form.addEventListener('submit', function(e) {
          e.preventDefault();
          let valid = true;

          // Names: require first and last; middle optional but must not contain digits
          [first, last].forEach(function(inp) {
            if (!inp) return;
            if (/\d/.test(inp.value)) {
              setWarning(inp.id, 'Numbers are not allowed in this field.');
              valid = false;
            }
            if (!inp.value.trim()) {
              setWarning(inp.id, 'This field is required.');
              valid = false;
            }
          });
          if (middle) {
            const val = middle.value || '';
            if (/\d/.test(val)) {
              setWarning('middle_name', 'Numbers are not allowed in this field.');
              valid = false;
            } else {
              setWarning('middle_name', '');
            }
          }

          // Phone format: 09 followed by 9 digits (PH mobile)
          if (phone) {
            // Remove spaces and other non-digits before checking format
            const val = phone.value.replace(/\D/g, '');
            if (!/^09\d{9}$/.test(val)) {
              setWarning('phone', 'Phone number must be 11 digits and start with 09.');
              valid = false;
            } else {
               // Update value to stripped version for submission? 
               // Actually, PHP handles stripping spaces, so we just need to validate the stripped version here.
               setWarning('phone', '');
            }
          }

          // Password complexity check
          if (password) {
            const v = password.value || '';
            if (v.length < 6 || !/[a-zA-Z]/.test(v) || !/[0-9]/.test(v)) {
              setWarning('password', 'Password must be at least 6 characters and include letters and numbers.');
              valid = false;
            } else {
              setWarning('password', '');
            }
          }

          // Password match check
          if (password && confirmPwd && password.value !== confirmPwd.value) {
            setWarning('confirm_password', 'Passwords do not match.');
            valid = false;
          }

          let isVisVal = false;
          if (isVisitor) {
             isVisVal = (isVisitor.type === 'checkbox') ? isVisitor.checked : (isVisitor.value === '1');
          }
          if (!isVisVal) {
            if (houseHidden && !houseHidden.value.trim()) {
              setWarning('houseHidden', 'Please enter your House Number.');
              valid = false;
            }
            if (addressField && !addressField.value.trim()) {
              setWarning('addressField', 'Please enter your full address.');
              valid = false;
            }
          } else {
            setWarning('houseHidden', '');
            if (addressField) setWarning('addressField', '');

            const validIdInp = document.getElementById('valid_id');
            if (validIdInp && !validIdInp.value) {
                setWarning('valid_id', 'Please upload a Valid ID.');
                valid = false;
            } else {
                setWarning('valid_id', '');
            }
          }

          if (birthdate) {
            const val = birthdate.value;
            if (!val) {
              setWarning('birthdate', 'Birthdate is required.');
              valid = false;
            } else {
              const today = new Date();
              today.setHours(0,0,0,0);
              const todayStr = today.toISOString().split('T')[0];
              if (val > todayStr) {
                setWarning('birthdate', 'Birthdate cannot be in the future.');
                valid = false;
              } else {
                setWarning('birthdate', '');
              }
            }
          }

          // Terms: show inline warning and block submit until agreed
          if (terms && !terms.checked) {
            setWarning('terms', 'Please read and agree to the Terms & Conditions.');
            valid = false;
          }
          if (document.getElementById('privacy') && !document.getElementById('privacy').checked) {
             setWarning('privacy', 'Please read and agree to the Privacy Policy.');
             valid = false;
          }

          if (!valid) return;

          function doAjaxSubmit() {
            const formData = new FormData(form);
            formData.append('ajax', '1');
            
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.textContent;
            submitBtn.disabled = true;
            submitBtn.textContent = 'Processing...';

            fetch('signup.php', {
              method: 'POST',
              body: formData
            })
            .then(response => response.json())
            .then(data => {
              if (data.success) {
                 window.location.href = data.redirect;
              } else {
                 submitBtn.disabled = false;
                 submitBtn.textContent = originalBtnText;
                 
                 const errors = data.errors || {};
                 let modalShown = false;

                 const hasHouseError = errors.houseHidden && (errors.houseHidden.includes('already in use') || errors.houseHidden.includes('Invalid'));
                 const hasEmailError = errors.email && errors.email.includes('already registered');

                if (hasHouseError) {
                  const onCloseAction = hasEmailError ? () => {
                      showCenterModal('Email Already Exists', 
                        '<p>This email is already registered.</p><a href="login.php" class="center-modal-btn">Go to Login Page</a>'
                      );
                  } : null;

                  showCenterModal('House Number Error', 
                    `<p>${errors.houseHidden}</p>`,
                    onCloseAction
                  );
                  modalShown = true;
                }
                 else if (hasEmailError) {
                   showCenterModal('Email Already Exists', 
                     '<p>This email is already registered.</p><a href="login.php" class="center-modal-btn">Go to Login Page</a>'
                   );
                   modalShown = true;
                 }

                 Object.entries(errors).forEach(([key, msg]) => {
                   setWarning(key, msg);
                 });
                 
                 if (!modalShown) {
                   const firstErrorKey = Object.keys(errors)[0];
                   const firstErrorEl = document.getElementById(firstErrorKey) || document.querySelector(`[name="${firstErrorKey}"]`);
                   if (firstErrorEl) firstErrorEl.scrollIntoView({behavior: 'smooth', block: 'center'});
                 }
              }
            })
            .catch(err => {
              console.error(err);
              submitBtn.disabled = false;
              submitBtn.textContent = originalBtnText;
              alert('An error occurred. Please try again.');
            });
          }

          showSignupConfirm(doAjaxSubmit);
        });
      }
    });
  </script>

  <!-- House Verification Modal (Removed) -->
  <!-- 
  <div id="houseModal" class="center-modal">
    ...
  </div>
  -->

  <!-- Center Warning Modal -->
  <div id="centerWarningModal" class="center-modal">
    <div class="center-modal-content">
      <button type="button" class="close-center" onclick="closeCenterModal()" aria-label="Close">&times;</button>
      <h3 id="centerModalTitle">Warning</h3>
      <div id="centerModalBody"></div>
    </div>
  </div>

  <script>
    window._modalCloseCallback = null;

    function closeCenterModal() {
      document.getElementById('centerWarningModal').style.display = 'none';
      if (typeof window._modalCloseCallback === 'function') {
        const cb = window._modalCloseCallback;
        window._modalCloseCallback = null;
        cb();
      }
    }
    
    function showCenterModal(title, bodyHtml, onClose) {
      document.getElementById('centerModalTitle').textContent = title;
      document.getElementById('centerModalBody').innerHTML = bodyHtml;
      document.getElementById('centerWarningModal').style.display = 'flex';
      window._modalCloseCallback = onClose || null;
    }

    function showSignupConfirm(onConfirm) {
      const modal = document.getElementById('centerWarningModal');
      const titleEl = document.getElementById('centerModalTitle');
      const bodyEl = document.getElementById('centerModalBody');
      if (!modal || !titleEl || !bodyEl) {
        if (typeof onConfirm === 'function') onConfirm();
        return;
      }
      titleEl.textContent = 'Confirm Sign Up';
      bodyEl.innerHTML = '<p style="margin-bottom:16px;">Please review your details before creating your account. Do you want to continue?</p><div style="display:flex;gap:10px;justify-content:center;margin-top:12px;flex-wrap:wrap;"><button type="button" id="signupConfirmCancelBtn" class="center-modal-btn" style="background-color:#e5e7eb;color:#111827;">Cancel</button><button type="button" id="signupConfirmProceedBtn" class="center-modal-btn">Proceed</button></div>';
      modal.style.display = 'flex';
      window._modalCloseCallback = null;
      setTimeout(function(){
        const cancelBtn = document.getElementById('signupConfirmCancelBtn');
        const proceedBtn = document.getElementById('signupConfirmProceedBtn');
        if (cancelBtn) {
          cancelBtn.onclick = function() {
            closeCenterModal();
          };
        }
        if (proceedBtn) {
          proceedBtn.onclick = function() {
            closeCenterModal();
            if (typeof onConfirm === 'function') onConfirm();
          };
        }
      },0);
    }
  </script>

  <script>
    (function(){
      var form = document.getElementById('signupForm');
      var bd = document.querySelector('input[name="birthdate"]');
      if (bd) {
        var d = new Date();
        d.setHours(0,0,0,0);
        bd.setAttribute('max', d.toISOString().split('T')[0]);
      }
      if (form) {
        form.addEventListener('submit', function(e){
          if (bd) {
            if (!bd.value) {
              setWarning('birthdate', 'Birthdate is required.');
              e.preventDefault();
              return;
            }
            var today = new Date();
            today.setHours(0,0,0,0);
            var todayStr = today.toISOString().split('T')[0];
            if (bd.value > todayStr) {
              setWarning('birthdate', 'Birthdate cannot be in the future.');
              e.preventDefault();
              return;
            }
            setWarning('birthdate', '');
          }
        });
      }
    })();
  </script>

  <!-- Image Preview Modal -->
  <div id="imagePreviewModal" class="center-modal" style="display:none; background-color: rgba(0,0,0,0.8);">
    <div class="center-modal-content" style="background: transparent; box-shadow: none; width: auto; max-width: 90%; text-align: center; padding: 0;">
      <button type="button" class="close-center" onclick="closeImagePreview()" aria-label="Close">&times;</button>
      <img id="fullImagePreview" src="" alt="Full Preview" style="max-width: 100%; max-height: 80vh; border-radius: 8px; border: 2px solid #fff;">
    </div>
  </div>

  <script>
    function openImagePreview(src) {
      const modal = document.getElementById('imagePreviewModal');
      const img = document.getElementById('fullImagePreview');
      if (modal && img) {
        img.src = src;
        modal.style.display = 'flex';
      }
    }

    function closeImagePreview() {
      const modal = document.getElementById('imagePreviewModal');
      if (modal) {
        modal.style.display = 'none';
      }
    }
    
    // Close modal when clicking outside the image
    document.getElementById('imagePreviewModal').addEventListener('click', function(e) {
      if (e.target === this) {
        closeImagePreview();
      }
    });
  </script>

  <!-- Inline warnings are inserted per field; floating layer removed -->
  <?php if (!empty($serverErrors ?? [])) { ?>
  <!-- Old server-side error rendering removed since we use AJAX now, but kept for fallback if needed -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const warnings = <?php echo json_encode($serverErrors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
      
      // Check for errors that should be displayed in a modal
       let modalMessage = '';
       if (warnings.email && (warnings.email.includes('already exists') || warnings.email.includes('Invalid') || warnings.email.includes('valid') || warnings.email.includes('registered'))) {
           modalMessage = warnings.email;
       } else if (warnings.houseHidden && (warnings.houseHidden.includes('already registered') || warnings.houseHidden.includes('Invalid') || warnings.houseHidden.includes('registered') || warnings.houseHidden.includes('use'))) {
           modalMessage = warnings.houseHidden;
       }
 
       if (modalMessage) {
           const modal = document.getElementById('errorModal');
           const msg = document.getElementById('errorModalMessage');
           if (modal && msg) {
               msg.textContent = modalMessage;
               modal.style.display = 'block';
           }
       }
 
       Object.entries(warnings).forEach(function([key, msg]) {
         setWarning(key, msg);
       });
    });
  </script>
  <?php } ?>

  <?php if ($registration_success) { ?>
  <script>
    // Auto-redirect to login after brief success message
    setTimeout(function(){ window.location.href = 'login.php'; }, 2500);
  </script>
  <?php } ?>

  <script>
    function openHouseModal(){
      const modal = document.getElementById('houseModal');
      const hidden = document.getElementById('houseHidden').value;
      if(hidden){
        document.getElementById('houseInput').value = hidden;
      }
      document.getElementById('verifyStatus').style.display = 'none';
      document.getElementById('verifyStatus').textContent = '';
      modal.style.display = 'block';
    }
    function closeHouseModal(){
      document.getElementById('houseModal').style.display = 'none';
    }
    async function performHouseVerify(){
      const house = document.getElementById('houseInput').value.trim();
      const status = document.getElementById('verifyStatus');
      status.style.display = 'none';
      status.textContent = '';
      if(!house){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Please enter a house number.';
        return;
      }
      try{
        const res = await fetch('verify_house.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ ajax:'1', house_number: house }).toString()
        });
        const data = await res.json();
        if(data && data.success){
          document.getElementById('houseHidden').value = house;
          
          // Update button appearance
          const btn = document.getElementById('houseVerifyBtn');
          if(btn) {
            btn.textContent = house;
            btn.style.background = '#e6ffed';
            btn.style.borderColor = '#23412e';
            btn.style.color = '#23412e';
            btn.style.fontWeight = '600';
          }

          status.style.display = 'block';
          status.style.color = '#23412e';
          status.textContent = 'House number is valid and saved.';
          // Keep modal open briefly for feedback, then close
          setTimeout(closeHouseModal, 800);
        } else {
          status.style.display = 'block';
          status.style.color = '#c0392b';
          status.textContent = data && data.message ? data.message : 'Invalid or unregistered house number.';
        }
      } catch(err){
        status.style.display = 'block';
        status.style.color = '#c0392b';
        status.textContent = 'Verification failed. Please try again.';
      }
    }
  </script>


    <!-- Generic Error Modal -->
  <div id="errorModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center; padding: 30px; border-radius: 12px; position: relative; top: 50%; transform: translateY(-50%); margin: auto;">
      <button type="button" class="close" onclick="document.getElementById('errorModal').style.display='none'" aria-label="Close">&times;</button>
      <div style="margin-bottom: 15px;">
         <i class="fa-solid fa-triangle-exclamation" style="font-size: 3rem; color: #e67e22;"></i>
      </div>
      <h3 style="color: #c0392b; margin-bottom: 10px;">Error</h3>
      <div id="errorModalMessage" style="color: #555; font-size: 1rem;"></div>
      <button class="btn confirm" onclick="document.getElementById('errorModal').style.display='none'" style="margin-top: 20px; background-color: #23412e; width: 100%;">OK</button>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const validIdInput = document.getElementById('valid_id');
      const previewContainer = document.getElementById('filePreviewContainer');
      const previewContent = document.getElementById('previewContent');
      const removeBtn = document.getElementById('removeFileBtn');
      const uploadContainer = document.getElementById('fileUploadContainer');

      if (validIdInput) {
          validIdInput.addEventListener('change', function(e) {
              const file = e.target.files[0];
              if (file) {
                  // Show preview
                  previewContainer.style.display = 'flex';
                  uploadContainer.style.display = 'none'; 
                  
                  previewContent.innerHTML = '';
                  
                  if (file.type.startsWith('image/')) {
                      const img = document.createElement('img');
                      img.src = URL.createObjectURL(file);
                      img.style.maxWidth = '60px';
                      img.style.maxHeight = '60px';
                      img.style.borderRadius = '4px';
                      img.style.objectFit = 'cover';
                      previewContent.appendChild(img);
                      
                      const name = document.createElement('span');
                      name.textContent = file.name;
                      name.style.marginLeft = '10px';
                      name.style.fontSize = '0.9rem';
                      name.style.color = '#333';
                      name.style.fontWeight = '500';
                      // Truncate long names
                      if (name.textContent.length > 20) {
                          name.textContent = name.textContent.substring(0, 17) + '...';
                      }
                      previewContent.appendChild(name);
                  } else {
                      const icon = document.createElement('span');
                      icon.innerHTML = '<i class="fa-solid fa-file-lines"></i>';
                      icon.style.fontSize = '24px';
                      previewContent.appendChild(icon);
                      
                      const name = document.createElement('span');
                      name.textContent = file.name;
                      name.style.marginLeft = '10px';
                      name.style.fontSize = '0.9rem';
                      name.style.color = '#333';
                      name.style.fontWeight = '500';
                       if (name.textContent.length > 20) {
                          name.textContent = name.textContent.substring(0, 17) + '...';
                      }
                      previewContent.appendChild(name);
                  }
              }
          });
      }

      if (removeBtn) {
          removeBtn.addEventListener('click', function() {
              validIdInput.value = ''; // Clear input
              previewContainer.style.display = 'none';
              uploadContainer.style.display = 'block';
          });
      }
    });
  </script>
</body>
</html>
