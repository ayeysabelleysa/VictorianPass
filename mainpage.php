<?php
include("connect.php");
session_start();

// Initialize error message for inline display
$error = '';

// Ensure entry_passes table exists
function ensureEntryPassesTable($con) {
  $con->query("CREATE TABLE IF NOT EXISTS entry_passes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    sex VARCHAR(10) NULL,
    birthdate DATE NULL,
    contact VARCHAR(50) NULL,
    email VARCHAR(120) NOT NULL,
    address VARCHAR(255) NOT NULL,
    valid_id_path VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

ensureEntryPassesTable($con);

// Load resident mini profile data (for dropdown)
$residentName = '';
$residentHouse = '';
$hasResidentProfile = false;
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'resident') {
  $uid = (int)$_SESSION['user_id'];
  if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, house_number FROM users WHERE id = ? LIMIT 1")) {
    $stmt->bind_param("i", $uid);
    if ($stmt->execute()) {
      $stmt->bind_result($first, $middle, $last, $house);
      if ($stmt->fetch()) {
        $residentName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
        $residentHouse = $house ?? '';
        $hasResidentProfile = true;
      }
    }
    $stmt->close();
  }
}

// No longer storing downpayment on entry_passes; we link it to reservations via ref_code

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  // Collect form data
  $first = trim($_POST['first_name'] ?? '');
  $middle = trim($_POST['middle_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  // Email removed from form; store as empty string
  $email = '';
  $sex = $_POST['sex'] ?? '';
  $birthdate = $_POST['birthdate'] ?? '';
  $contact = trim($_POST['contact'] ?? '');

  // Handle valid ID upload
  $validIdPath = null;
  if (!empty($_FILES['valid_id']['name'])) {
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir);
    $fileName = time() . "_" . basename($_FILES["valid_id"]["name"]);
    $targetFile = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $targetFile)) {
      $validIdPath = $targetFile;
    }
  }

  // Insert into entry_passes
  $stmt = $con->prepare("INSERT INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stmt->bind_param("sssssssss", $first, $middle, $last, $sex, $birthdate, $contact, $email, $address, $validIdPath);
  if ($stmt->execute()) {
    $entryPassId = $stmt->insert_id;

    // Store minimal info in session (optional)
    $_SESSION['entry_pass_id'] = $entryPassId;
    $_SESSION['entry_pass_name'] = $first . ' ' . $last;

    // Redirect to downpayment step (visitor-only) before reservation
    header("Location: downpayment.php?entry_pass_id=" . $entryPassId . "&continue=reserve");
    exit;
  } else {
    // Fallback: keep previous behavior
    header("Location: reserve.php");
    exit;
  }
}
?>

<!DOCTYPE html> 
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass</title>
  <link rel="icon" type="image/png" href="mainpage/logo.svg">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <?php $mainCssVer = @filemtime(__DIR__ . '/mainpage.css') ?: time(); $respCssVer = @filemtime(__DIR__ . '/responsive.css') ?: time(); ?>
  <link rel="stylesheet" href="mainpage.css?v=<?php echo $mainCssVer; ?>">
  <link rel="stylesheet" href="responsive.css?v=<?php echo $respCssVer; ?>">

  <style>
    /* Global Poppins Font Application */
    * {
      font-family: 'Poppins', sans-serif !important;
    }
    
    body {
      animation: fadeIn 0.6s ease-in-out;
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    h1, h2, h3, h4, h5, h6 {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    p, span, div, a, button, input, select, textarea, label {
      font-family: 'Poppins', sans-serif;
    }
    
    .brand-text h1 {
      font-family: 'Poppins', sans-serif;
      font-weight: 700;
    }
    
    .brand-text p {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .hero-content h1 {
      font-family: 'Poppins', sans-serif;
      font-weight: 900;
    }
    
    .tagline {
      font-family: 'Poppins', sans-serif;
      font-weight: 300;
    }
    
    .btn-qr, .btn-nav {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    .entry-form input, .entry-form select, .entry-form textarea {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .form-header span {
      font-family: 'Poppins', sans-serif;
      font-weight: 600;
    }
    
    .dropdown-btn {
      font-family: 'Poppins', sans-serif;
      font-weight: 500;
    }
    
    .dropdown-content a {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .page-instructions, .form-note {
      font-family: 'Poppins', sans-serif;
      font-weight: 400;
    }
    
    .page-instructions strong {
      font-weight: 600;
    }
    
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }               
    .action-buttons {
      display: flex;
      gap: 15px;
      margin-top: 20px;
      flex-wrap: wrap;
      justify-content: center;
    }
    .btn-qr {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 8px 12px;
      border-radius: 10px;
      font-weight: 600;
      text-decoration: none;
      color: #222;
      background: #e5ddc6;
      transition: 0.2s;
      white-space: nowrap;
      line-height: 1;
    }
    .btn-qr img { width: 18px; height: 18px; }
    .btn-qr:hover { transform: translateY(-2px); opacity: 0.9; }
    .btn-referral { background: #4CAF50; color: #fff; }
    .form-note, .page-instructions {
      margin-top: 15px;
      font-size: 0.9rem;
      color: #ddd;
      text-align: center;
      max-width: 520px;
      line-height: 1.5;
    }
    .page-instructions strong { color: #fff; }
    .hero-icons { display: flex; justify-content: center; }
    .form-instruction { text-align:center; color:#ddd; margin:10px 0 6px; font-size:0.95rem; }
    .error { background:#ffe5e5; color:#b00020; padding:10px; border-radius:6px; margin:10px 0; text-align:center; }

    .entry-form select { 
      width: 95%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; 
      font-size: 0.9rem; font-family: 'Poppins', sans-serif; 
      background: #fff url("mainpage/arrow.svg") no-repeat right 12px center; 
      background-size: 14px; appearance: none; color: #333; cursor: pointer; 
      margin-bottom: 14px;
    } 
    .entry-form select:focus { border-color: #4CAF50; }

    .form-group { position: relative; flex: 1; }
    .form-group input[type="date"] {
      width: 100%; padding: 12px; border: 1px solid #ccc;
      border-radius: 8px; font-size: 0.95rem;
      font-family: 'Poppins', sans-serif; background: #fff; color: #222;
    }
    input[type="date"]:not(:focus):placeholder-shown::-webkit-datetime-edit {
      color: transparent;
    }
    input[type="date"]::-webkit-calendar-picker-indicator {
      position: absolute; right: 12px; cursor: pointer;
    }
    .form-group label {
      position: absolute; left: 12px; top: 12px; color: #888;
      font-size: 0.95rem; pointer-events: none; transition: 0.2s ease all;
    }
    .form-group input:focus + label,
    .form-group input:not(:placeholder-shown) + label {
      top: -8px; left: 8px; font-size: 0.75rem; color: #23412e;
      background: #fff; padding: 0 4px;
    }

    /* User Type Dropdown Styles */
    .user-type-dropdown {
      position: relative;
      display: inline-block;
    }

    .dropdown-btn {
      background: #23412e;
      color: #fff;
      padding: 8px 16px;
      border: none;
      border-radius: 20px;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.2s ease;
      width: 100%;
      justify-content: space-between;
    }

    .dropdown-btn span {
      pointer-events: none;
    }

    .dropdown-btn:hover {
      background: #1a2f21;
      transform: scale(1.05);
    }

    .dropdown-arrow {
      font-size: 12px;
      transition: transform 0.2s ease;
      user-select: none;
      pointer-events: none;
    }

    .dropdown-content {
      display: none;
      position: absolute;
      right: 0;
      background: #fff;
      min-width: 160px;
      box-shadow: 0px 8px 16px rgba(0,0,0,0.2);
      border-radius: 8px;
      z-index: 1000;
      overflow: hidden;
      margin-top: 5px;
    }

    .dropdown-content a {
      color: #222;
      padding: 12px 16px;
      text-decoration: none;
      display: block;
      transition: background-color 0.2s ease;
    }

    .dropdown-content a:hover {
      background-color: #f1f1f1;
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    /* Centered placement for User Type selector in hero */
    .user-type-center {
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 16px 0 20px;
    }
    .btn-change {
      background: #6c757d;
      color: #fff;
      border-radius: 20px;
      padding: 8px 16px;
      border: none;
      cursor: pointer;
    }
    .btn-change:hover { opacity: 0.9; }

    /* Profile icon + dropdown */
    .profile-icon { width: 36px; height: 36px; border-radius: 50%; border: 2px solid #23412e; cursor: pointer; object-fit: cover; }
    .profile-icon-wrap { position: relative; display: inline-block; }
    .profile-dropdown { position: absolute; right: 0; top: 125%; width: 260px; background: #fff; color: #222; border-radius: 12px; box-shadow: 0 10px 20px rgba(0,0,0,0.15); border: 1px solid #eee; z-index: 1100; display: none; overflow: hidden; }
    .profile-dropdown .mini-profile { display: flex; align-items: center; gap: 10px; padding: 12px; border-bottom: 1px solid #f0f0f0; }
    .profile-dropdown .mini-avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #23412e; }
    .profile-dropdown .mini-text { display: flex; flex-direction: column; }
    .profile-dropdown .mini-name { font-weight: 600; font-size: 0.95rem; }
    .profile-dropdown .mini-house { color: #666; font-size: 0.85rem; }
    .profile-dropdown .actions { display: flex; gap: 8px; padding: 10px 12px; }
    .profile-dropdown .btn { flex: 1; text-align: center; padding: 8px 10px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.9rem; }
    .profile-dropdown .btn-view { background: #23412e; color: #fff; }
    .profile-dropdown .btn-view:hover { background: #1a2f21; }
    .profile-dropdown .btn-logout { background: #e5ddc6; color: #222; }
    .profile-dropdown .btn-logout:hover { opacity: 0.9; }
  </style>
</head>

<body>
  <!-- HEADER -->
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="mainpage/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>

    <div class="nav-actions">
      <a href="checkurstatus.php" class="btn-nav btn-status" id="checkStatusNav" style="display: none;">Check Status</a>
      <!-- Navigation Links (initially hidden) -->
      <div class="nav-links" id="navLinks" style="display: none;">
        <a href="login.php" class="btn-nav btn-login">Login</a>
        <a href="signup.php" class="btn-nav btn-register">Register</a>
        <div id="profileIcon" class="profile-icon-wrap" style="display: none;">
          <img src="mainpage/profile'.jpg" alt="Profile" class="profile-icon">
          <?php if ($hasResidentProfile): ?>
          <div id="profileDropdown" class="profile-dropdown" role="dialog" aria-label="Resident quick profile">
            <div class="mini-profile">
              <img src="mainpage/profile'.jpg" alt="Avatar" class="mini-avatar">
              <div class="mini-text">
                <span class="mini-name"><?php echo htmlspecialchars($residentName); ?></span>
                <span class="mini-house">House No.: <?php echo htmlspecialchars($residentHouse); ?></span>
              </div>
            </div>
            <div class="actions">
              <a href="profileresident.php" class="btn btn-view">View More</a>
              <a href="logout.php" class="btn btn-logout">Log Out</a>
            </div>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    
  </header>

  <!-- HERO SECTION -->
  <section class="hero">
    <?php if ($error !== '') { echo '<div class="error">' . htmlspecialchars($error) . '</div>'; } ?>
    <div class="hero-content">
      

      <div class="hero-icons" id="entryPassButtonWrapper" style="display:none;">
        <a href="entrypass.html" class="icon-box" id="entryFormButton">
          <img src="mainpage/entrypass.svg" alt="Entry Pass">
          <span>Entry Pass Form</span>
        </a>
      </div>
      <h1>WELCOME TO VictorianPass</h1>
      <div class="hero-divider"></div>
      <p class="welcome-subtitle">
        VictorianPass: An Online Amenity Reservation System<br>
        with QR-based Entry Pass Security<br>
        for Victorian Heights Subdivision
      </p>
      <p class="tagline">
        Every home holds a story —<br>
        start yours in a place worth remembering.
      </p>

      

      <!-- Moved User Type Dropdown to bottom of hero -->
      <div class="user-type-center">
        <div class="user-type-dropdown" id="userTypeDropdown">
          <button class="dropdown-btn" id="dropdownBtn">
            <span>Select User Type</span>
            <span class="dropdown-arrow">▼</span>
          </button>
          <div class="dropdown-content" id="dropdownContent">
            <a href="#" onclick="selectUserType('resident')">Resident</a>
            <a href="#" onclick="selectUserType('visitor')">Visitor</a>
          </div>
        </div>
      </div>

      <!-- Change user type helper (shown after selection) -->
      <div class="user-type-center" id="userTypeSwitch" style="display:none;">
        <button class="btn-change" onclick="resetUserType()">Change User Type</button>
      </div>
    </div>
  </section>

  <!-- Visitor-friendly instructions box fixed at the bottom-left -->
  <div class="bottom-instructions" id="bottomInstructions" style="display: none;">
    <strong>Visitor Tips</strong><br>
    • Click <b>Entry Pass Form</b> to apply for a visitor pass.<br>
    • Use <b>Check Status</b> to look up your code or track your request.
  </div>

  <script>
    function selectUserType(type) {
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const checkStatusNav = document.getElementById('checkStatusNav');
      const bottomInstructions = document.getElementById('bottomInstructions');
      const switcher = document.getElementById('userTypeSwitch');

      if (type === 'resident') {
        // Hide dropdown and show navigation links
        dropdown.style.display = 'none';
        navLinks.style.display = 'flex';
        if (checkStatusNav) checkStatusNav.style.display = 'none';
        if (bottomInstructions) bottomInstructions.style.display = 'none';
        switcher.style.display = 'block';
        // Hide Entry Pass button/instruction for residents
        var epBtn = document.getElementById('entryPassButtonWrapper');
        var epInst = document.getElementById('entryPassInstruction');
        if (epBtn) epBtn.style.display = 'none';
        if (epInst) epInst.style.display = 'none';

        // Check if user is logged in (you can modify this logic based on your session handling)
        <?php if (isset($_SESSION['user_id'])): ?>
          profileIcon.style.display = 'block';
        <?php endif; ?>

      } else if (type === 'visitor') {
        // Hide dropdown and show entry form and check status
        dropdown.style.display = 'none';
        navLinks.style.display = 'none';
        profileIcon.style.display = 'none';
        if (checkStatusNav) checkStatusNav.style.display = 'inline-block';
        if (bottomInstructions) bottomInstructions.style.display = 'block';
        switcher.style.display = 'block';
        // Show Entry Pass button/instruction for visitors
        var epBtn = document.getElementById('entryPassButtonWrapper');
        var epInst = document.getElementById('entryPassInstruction');
        if (epBtn) epBtn.style.display = 'flex';
        if (epInst) epInst.style.display = 'block';
      }
    }

    function resetUserType(){
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const checkStatusNav = document.getElementById('checkStatusNav');
      const bottomInstructions = document.getElementById('bottomInstructions');
      const switcher = document.getElementById('userTypeSwitch');
      const epBtn = document.getElementById('entryPassButtonWrapper');
      const epInst = document.getElementById('entryPassInstruction');

      // Reset to initial state
      dropdown.style.display = 'block';
      const content = document.getElementById('dropdownContent');
      if (content) content.style.display = 'none';
      navLinks.style.display = 'none';
      // No inline entry form on main page
      if (profileIcon) profileIcon.style.display = 'none';
      if (checkStatusNav) checkStatusNav.style.display = 'none';
      if (bottomInstructions) bottomInstructions.style.display = 'none';
      switcher.style.display = 'none';
      if (epBtn) epBtn.style.display = 'none';
      if (epInst) epInst.style.display = 'none';
    }

    // Toggle dropdown visibility
    document.getElementById('dropdownBtn').addEventListener('click', function(event) {
      event.stopPropagation();
      const content = document.getElementById('dropdownContent');
      content.style.display = content.style.display === 'block' ? 'none' : 'block';
    });

    // Close dropdown when clicking outside
    window.addEventListener('click', function(event) {
      if (!event.target.closest('.dropdown-btn')) {
        const dropdowns = document.getElementsByClassName('dropdown-content');
        for (let i = 0; i < dropdowns.length; i++) {
          dropdowns[i].style.display = 'none';
        }
      }
    });
  </script>
  <script>
    // Auto-show resident nav state after login
    document.addEventListener('DOMContentLoaded', function(){
      const dropdown = document.getElementById('userTypeDropdown');
      const navLinks = document.getElementById('navLinks');
      const profileIcon = document.getElementById('profileIcon');
      const loginBtn = document.querySelector('.btn-login');
      const registerBtn = document.querySelector('.btn-register');
      <?php if (isset($_SESSION['user_id'])): ?>
        if (dropdown) dropdown.style.display = 'none';
        if (navLinks) navLinks.style.display = 'flex';
        if (profileIcon) profileIcon.style.display = 'block';
        if (loginBtn) loginBtn.style.display = 'none';
        if (registerBtn) registerBtn.style.display = 'none';
      <?php endif; ?>
    });
  </script>
  <script>
    // Profile dropdown interactions: click/hover to open, click outside to close
    document.addEventListener('DOMContentLoaded', function(){
      const iconWrap = document.getElementById('profileIcon');
      const dd = document.getElementById('profileDropdown');
      if (!iconWrap || !dd) return;

      const openDD = () => { dd.style.display = 'block'; };
      const closeDD = () => { dd.style.display = 'none'; };
      const toggleDD = () => { dd.style.display = (dd.style.display === 'block') ? 'none' : 'block'; };

      // Toggle when clicking the icon itself; allow clicks inside dropdown to navigate
      iconWrap.addEventListener('click', function(e){
        if (dd.contains(e.target)) return; // let dropdown links work normally
        e.stopPropagation();
        toggleDD();
      });
      iconWrap.addEventListener('mouseenter', function(){ openDD(); });
      iconWrap.addEventListener('mouseleave', function(){ setTimeout(function(){ if (!dd.matches(':hover')) closeDD(); }, 160); });
      dd.addEventListener('mouseleave', function(){ closeDD(); });
      window.addEventListener('click', function(e){ if (!iconWrap.contains(e.target) && !dd.contains(e.target)) closeDD(); });
    });
  </script>

</body>
</html>
