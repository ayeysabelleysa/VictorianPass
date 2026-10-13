<?php
include("connect.php");
// GET page loads only read the session (flash + logged-in identity). Reading it via
// the signed vp_auth cookie (cookie-auth) skips the PHP session file lock entirely,
// so a slow Learn More / page navigation no longer waits behind in-flight dashboard
// polling requests that hold the lock while doing DB work (prevents 502/504 on shared
// hosts like Hostinger). POST (entry-pass form) keeps a normal writable session.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  if (isset($_COOKIE['vp_auth'])) {
    if (!defined('VP_SESSION_COOKIE_AUTH')) { define('VP_SESSION_COOKIE_AUTH', true); }
  } else {
    if (!defined('VP_SESSION_READONLY')) { define('VP_SESSION_READONLY', true); }
  }
}
require_once __DIR__ . '/session_bootstrap.php';
// Canonical VHEcoPoint logo helper (single source of truth for the logo file).
require_once __DIR__ . '/ecopoint_brand.php';

// Initialize error message for inline display
$error = '';
// Flash notice from payment confirmation
$flash = isset($_SESSION['flash_notice']) ? $_SESSION['flash_notice'] : '';
$flashRef = isset($_SESSION['flash_ref_code']) ? $_SESSION['flash_ref_code'] : '';
if ($flash !== '') { unset($_SESSION['flash_notice']); }
if ($flashRef !== '') { unset($_SESSION['flash_ref_code']); }

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

// Ensure users table schema supports visitors
function ensureUserSchema($con){
  // Add 'visitor' to user_type enum if missing
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'user_type'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strpos($row['Type'], "visitor") === false) {
      $con->query("ALTER TABLE users MODIFY COLUMN user_type ENUM('resident','visitor') DEFAULT 'resident'");
    }
  }
  // Make house_number nullable
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'house_number'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN house_number VARCHAR(50) NULL");
    }
  }
  // Make address nullable
  $res = $con->query("SHOW COLUMNS FROM users LIKE 'address'");
  if ($res && ($row = $res->fetch_assoc())) {
    if (strtoupper($row['Null']) === 'NO') {
      $con->query("ALTER TABLE users MODIFY COLUMN address VARCHAR(255) NULL");
    }
  }
}

// One-time schema migration tracker (file-based flag).
// On shared Hostinger the DB user often cannot CREATE TABLE, so a DB-backed
// flag (schema_migrations) silently fails and the expensive ALTER/CREATE
// statements below ran on EVERY request → 504 Gateway Timeouts.
// A local flag file avoids DB privileges entirely: first load writes the file,
// every subsequent load sees it and skips the migration instantly.
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

if (!vpSchemaDone($con, 'mainpage_v1')) {
  ensureEntryPassesTable($con);
  ensureUserSchema($con);
  vpMarkSchemaDone($con, 'mainpage_v1');
}

$error = '';

// Load user profile data (resident or visitor)
$userName = '';
$userFirstName = '';
$userHouse = '';
$userType = '';
$userEmail = '';
$userPhone = '';
$userAddress = '';
$userSex = '';
$userBirthdate = '';
$isLoggedIn = false;
$isResident = false;
$isVisitor = false;

if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
  $uid = (int)$_SESSION['user_id'];
  $userType = $_SESSION['user_type'];
  $foundUser = false;
  if ($userType === 'resident') {
    if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, house_number, email, phone, address, sex, birthdate FROM users WHERE id = ? LIMIT 1")) {
      $stmt->bind_param("i", $uid);
      if ($stmt->execute()) {
        $stmt->bind_result($first, $middle, $last, $house, $email, $phone, $address, $sex, $birthdate);
        if ($stmt->fetch()) {
          $foundUser = true;
          $isLoggedIn = true;
          $isResident = true;
          $userName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
          $userFirstName = $first;
          $userHouse = $house ?? '';
          $userEmail = $email ?? '';
          $userPhone = $phone ?? '';
          $userAddress = $address ?? '';
          $userSex = $sex ?? '';
          $userBirthdate = $birthdate ?? '';
        }
      }
      $stmt->close();
    }
  } elseif ($userType === 'visitor') {
    if ($stmt = $con->prepare("SELECT first_name, middle_name, last_name, email, phone, address, sex, birthdate FROM users WHERE id = ? LIMIT 1")) {
      $stmt->bind_param("i", $uid);
      if ($stmt->execute()) {
        $stmt->bind_result($first, $middle, $last, $email, $phone, $address, $sex, $birthdate);
        if ($stmt->fetch()) {
          $foundUser = true;
          $isLoggedIn = true;
          $isVisitor = true;
          $userName = trim($first . ' ' . (($middle ?? '') ? ($middle . ' ') : '') . $last);
          $userFirstName = $first;
          $userHouse = 'Visitor';
          $userEmail = $email ?? '';
          $userPhone = $phone ?? '';
          $userAddress = $address ?? '';
          $userSex = $sex ?? '';
          $userBirthdate = $birthdate ?? '';
        }
      }
      $stmt->close();
    }
  }
  if ($foundUser) {
    $profilePicPath = 'images/mainpage/profile\'.jpg';
    if (file_exists('uploads/profiles/user_' . $uid . '.jpg')) {
        $profilePicPath = 'uploads/profiles/user_' . $uid . '.jpg';
    } elseif (file_exists('uploads/profiles/user_' . $uid . '.png')) {
        $profilePicPath = 'uploads/profiles/user_' . $uid . '.png';
    } elseif (file_exists('uploads/profiles/user_' . $uid . '.jpeg')) {
        $profilePicPath = 'uploads/profiles/user_' . $uid . '.jpeg';
    }
    $profilePicUrl = $profilePicPath . '?t=' . time();
  } else {
    unset($_SESSION['user_id']);
    unset($_SESSION['user_type']);
    unset($_SESSION['role']);
    $isLoggedIn = false;
    $isResident = false;
    $isVisitor = false;
    $userType = '';
  }
}

// No longer storing downpayment on entry_passes; we link it to reservations via ref_code

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $error = '';
  $formErrors = [];
  // Collect form data
  $first = trim($_POST['first_name'] ?? '');
  $middle = trim($_POST['middle_name'] ?? '');
  $last = trim($_POST['last_name'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $sex = $_POST['sex'] ?? '';
  $birthdate = $_POST['birthdate'] ?? '';
  $contact = trim($_POST['contact'] ?? '');

  // Normalize phone number to +63 format
  $phoneClean = preg_replace('/[\s\-]/', '', $contact);
  if (preg_match('/^0(9\d{9})$/', $phoneClean, $matches)) {
      $contact = '+63' . $matches[1];
  } elseif (preg_match('/^\+63(9\d{9})$/', $phoneClean, $matches)) {
      $contact = '+63' . $matches[1];
  } elseif (preg_match('/^63(9\d{9})$/', $phoneClean, $matches)) {
      $contact = '+63' . $matches[1];
  } elseif (preg_match('/^(9\d{9})$/', $phoneClean, $matches)) {
      $contact = '+63' . $matches[1];
  }

  // Basic validation mirroring client rules
  if ($first === '' || preg_match('/\d/', $first)) { $formErrors[] = 'Please provide a valid First Name.'; }
  if ($last === '' || preg_match('/\d/', $last)) { $formErrors[] = 'Please provide a valid Last Name.'; }
  if ($address === '') { $formErrors[] = 'Address is required.'; }
  if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $formErrors[] = 'A valid email is required.'; }
  if ($sex === '') { $formErrors[] = 'Sex is required.'; }
  if ($birthdate === '') { $formErrors[] = 'Birthdate is required.'; }
  if ($contact !== '' && !preg_match('/^\+639\d{9}$/', $contact)) { $formErrors[] = 'Use valid PH mobile format (+63 9XX XXX XXXX or 09XX XXX XXXX).'; }
  if (!isset($_POST['terms'])) { $formErrors[] = 'You must agree to the Terms and Services.'; }
  if (!isset($_POST['privacy'])) { $formErrors[] = 'You must acknowledge the Privacy Policy.'; }

  // Handle valid ID upload (REQUIRED)
  $validIdPath = null;
  if (!empty($_FILES['valid_id']['name']) && isset($_FILES['valid_id']['tmp_name']) && is_uploaded_file($_FILES['valid_id']['tmp_name'])) {
    $uploadDir = "uploads/";
    if (!is_dir($uploadDir)) mkdir($uploadDir);
    $fileName = time() . "_" . basename($_FILES["valid_id"]["name"]);
    $targetFile = $uploadDir . $fileName;
    if (move_uploaded_file($_FILES["valid_id"]["tmp_name"], $targetFile)) {
      $validIdPath = $targetFile;
    } else {
      $formErrors[] = 'Failed to upload ID. Please try again.';
    }
  } else {
    $formErrors[] = 'Valid ID upload is required.';
  }

  if (empty($formErrors)) {
    // Insert into entry_passes ONLY when complete and validated
    $stmt = $con->prepare("INSERT INTO entry_passes (full_name, middle_name, last_name, sex, birthdate, contact, email, address, valid_id_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssssss", $first, $middle, $last, $sex, $birthdate, $contact, $email, $address, $validIdPath);
    if ($stmt->execute()) {
      $entryPassId = $stmt->insert_id;
      $_SESSION['entry_pass_id'] = $entryPassId;
      $_SESSION['entry_pass_name'] = $first . ' ' . $last;
      header("Location: reserve.php?entry_pass_id=" . $entryPassId);
      exit;
    } else {
      $error = 'Failed to save entry pass. Please try again.';
    }
    $stmt->close();
  } else {
    // Aggregate errors for display
    $error = implode(' ', $formErrors);
  }
}
?>

<!DOCTYPE html> 
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>VictorianPass</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
  <?php $mainCssVer = substr(@md5_file(__DIR__ . '/css/mainpage.css') ?: '', 0, 12); $respCssVer = substr(@md5_file(__DIR__ . '/css/responsive.css') ?: '', 0, 12); $ecoBrandCss = substr(@md5_file(__DIR__ . '/css/ecopoint-brand.css') ?: '', 0, 12); ?>
  <link rel="stylesheet" href="css/mainpage.css?v=<?php echo $mainCssVer; ?>">
  <link rel="stylesheet" href="css/responsive.css?v=<?php echo $respCssVer; ?>">
  <!-- Canonical VHEcoPoint brand lockup: one file, one size, one placement for the whole system. -->
  <link rel="stylesheet" href="css/ecopoint-brand.css?v=<?php echo $ecoBrandCss; ?>">
  
</head>
<body>
  <!-- HEADER -->
  <header class="navbar">
    <div class="logo">
      <a href="mainpage.php"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
      <div class="brand-text">
        <h1>VictorianPass</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
    
    <button class="hamburger" id="navToggle" aria-label="Menu" aria-expanded="false" aria-controls="navCollapse"><span></span><span></span><span></span></button>
    <div class="nav-collapse" id="navCollapse">
      <nav class="page-nav" id="primaryNav">
        <a href="#home" class="nav-active" aria-current="location">Home</a>
        <?php if (!$isVisitor): ?>
        <a href="#" class="nav-ecopoint" id="navEcoPointBtn" data-ecopoint-modal-open="true">
          <span class="nav-ecopoint-icon"><?php echo vh_eco_logo('', 'nav-eco-logo'); ?></span>
          <span class="nav-ecopoint-label">VHEcoPoint</span>
          <span class="nav-ecopoint-badge" aria-label="New feature">New!</span>
          <span class="nav-ecopoint-tooltip" role="tooltip">Earn points by recycling and redeem them for free amenity hours!</span>
        </a>
        <?php endif; ?>
        <a href="#about-us">About Us</a>
        <a href="#facilities">Amenities</a>
        <a href="#about-system">About the System</a>
      </nav>
      <div class="nav-actions">
        <?php if ($isLoggedIn): ?>
          <div style="display:flex; align-items:center; gap:12px; color:#f4f4f4; font-weight:600;">
             <span aria-hidden="true" style="width:1px; align-self:stretch; background:rgba(255,255,255,.4); flex:none; display:block;"></span>
             <span>Hi, <?php echo htmlspecialchars($userFirstName ?: 'User'); ?> <small style="font-weight:400; opacity:0.8;">(<?php echo ucfirst($userType); ?>)</small></span>
             <div class="profile-icon-wrap" id="profileWrap">
               <button id="profileAccountTrigger" type="button" class="profile-account-btn" style="background:none;border:none;padding:0;cursor:pointer;">
                 <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="profile-icon">
               </button>
               <div class="profile-dropdown" id="profileDropdown">
                 <div class="mini-profile">
                    <img src="<?php echo $profilePicUrl; ?>" alt="Profile" class="mini-avatar">
                    <div class="mini-text" style="text-align:left;">
                      <div class="mini-name" style="color:#222;"><?php echo htmlspecialchars($userName); ?></div>
                      <div style="font-size:0.8rem; color:#666;"><?php echo ucfirst($userType); ?></div>
                    </div>
                 </div>
                 <div class="profile-dropdown-actions">
                    <a href="<?php echo $userType === 'resident' ? 'profileresident.php' : 'dashboardvisitor.php'; ?>" class="btn-dashboard-view">
                      Open Full View of Profile Dashboard
                    </a>
                 </div>
               </div>
             </div>
          </div>
        <?php else: ?>
          <div class="nav-links" style="display:flex;">
            <a href="login.php" class="btn-nav btn-login">Login</a>
            <a href="signup.php" class="btn-nav btn-register">Register</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    
  </header>

  <!-- HERO SECTION -->
  <section class="hero" id="home">
    <?php if ($error !== '') { echo '<div class="error">' . htmlspecialchars($error) . '</div>'; } ?>
    <div class="hero-content reveal-on-scroll is-visible">
      
      <h2>WELCOME TO</h2>
      <div class="hero-brand">
        <h1>VictorianPass</h1>
      </div>

      <div class="hero-emblem">
        <span class="line"></span>
        <img src="images/logo.svg" alt="Emblem">
        <span class="line"></span>
      </div>

      <p class="tagline">Every home has a story — start yours in a place worth remembering.</p>
      <?php if (($isResident || !$isLoggedIn) && !$isVisitor): ?>
      <p class="hero-eco-subtitle" data-ecopoint-modal-open="true" role="button" tabindex="0" aria-haspopup="dialog"><span class="hero-eco-subtitle-top"><span class="hero-eco-subtitle-main">Now with VHEcoPoint!</span></span><span class="hero-eco-subtitle-sub"><span class="hero-eco-subtitle-icon" aria-hidden="true"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7a6.5 6.5 0 0 1 10.8-1.2L20 8"/><path d="M20 4v4h-4"/><path d="M17 17a6.5 6.5 0 0 1-10.8 1.2L4 16"/><path d="M4 20v-4h4"/></svg></span>Recycle Smart. Earn Eco Points. Enjoy Victorian Heights.</span><span class="hero-eco-subtitle-hint">See description</span></p>
      <script>
        (function(){
          var title = document.querySelector('.hero-brand');
          var banner = document.querySelector('.hero-eco-subtitle');
          if(!title || !banner) return;

          function syncBannerWidth(){
            banner.style.setProperty('--hero-title-width', title.getBoundingClientRect().width + 'px');
          }

          syncBannerWidth();
          if(typeof ResizeObserver === 'function'){
            new ResizeObserver(syncBannerWidth).observe(title);
          } else {
            window.addEventListener('resize', syncBannerWidth);
          }
        })();
      </script>
      <?php endif; ?>

      <div class="action-buttons" style="margin-top: 30px; gap:15px; flex-wrap:wrap;">
        <button type="button" class="btn-change btn-reserve" data-goto="reserve.php" data-login-msg="Please log in first to reserve an amenity.">Reserve an Amenity</button>
        <button type="button" class="btn-change btn-dashboard" data-goto="<?php echo $isVisitor ? 'dashboardvisitor.php' : 'profileresident.php'; ?>" data-login-msg="Please log in first to view your profile." style="display:inline-flex;align-items:center;justify-content:center;gap:8px;"><i class="fa-solid fa-user" aria-hidden="true"></i> My Profile</button>
      </div>

      <!-- Login Required popup now lives at root level (below) so its overlay stacks above the sticky navbar. -->

      <script>
        (function(){
          window.vpUpdateScrollLock = function(){
            var eco = document.getElementById('ecopointModal');
            var login = document.getElementById('loginModal');
            var anyOpen = (eco && eco.style.display === 'flex') || (login && login.style.display === 'flex');
            document.documentElement.classList.toggle('vp-modal-open', !!anyOpen);
          };
          function initLoginModal(){
            var loggedIn = <?php echo $isLoggedIn ? 'true' : 'false'; ?>;
            var modal = document.getElementById('loginModal');
            var msgEl = document.getElementById('loginModalMessage');
            var defaultMsg = msgEl ? msgEl.textContent : '';
            window.openLoginRequired = function(message){
              if (msgEl) { msgEl.textContent = message || defaultMsg; }
              if (modal) { modal.style.display = 'flex'; }
              if (window.vpUpdateScrollLock) { window.vpUpdateScrollLock(); }
            };
            if (modal) {
              modal.addEventListener('click', function(e){ if (e.target === modal) closeLoginModal(); });
            }
            var confirmBtn = document.getElementById('loginModalConfirm');
            var cancelBtn = document.getElementById('loginModalCancel');
            var closeBtn = document.getElementById('loginModalClose');
            function closeLoginModal(){ if (modal) { modal.style.display = 'none'; } if (window.vpUpdateScrollLock) { window.vpUpdateScrollLock(); } }
            if (confirmBtn) confirmBtn.addEventListener('click', function(){ window.location.href = 'login.php'; });
            if (cancelBtn) cancelBtn.addEventListener('click', closeLoginModal);
            if (closeBtn) closeBtn.addEventListener('click', closeLoginModal);
            document.addEventListener('keydown', function(e){ if (modal && modal.style.display === 'flex' && e.key === 'Escape') closeLoginModal(); });
            var triggers = document.querySelectorAll('.action-buttons [data-goto]');
            for (var i = 0; i < triggers.length; i++) {
              triggers[i].addEventListener('click', function(){
                if (loggedIn) {
                  window.location.href = this.getAttribute('data-goto');
                } else {
                  window.openLoginRequired(this.getAttribute('data-login-msg'));
                }
              });
            }
          }
          if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initLoginModal);
          } else {
            initLoginModal();
          }
        })();
      </script>
    </div>
  </section>

  <?php if (!$isVisitor && ($isResident || !$isLoggedIn)): ?>
  <div id="ecopointModal" class="flash-overlay ecopoint-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ecopointModalTitle">
    <div class="flash-modal">
      <div class="eco-modal-header">
        <div class="eco-modal-brand">
          <span class="eco-modal-brand-icon"><?php echo vh_eco_logo('VHEcoPoint'); ?></span>
          <div class="eco-modal-brand-text">
            <h2 class="eco-modal-title" id="ecopointModalTitle">Learn About VHEcoPoint</h2>
            <p class="eco-modal-subtitle">Victorian Heights Smart Waste Segregation Station</p>
          </div>
        </div>
        <button type="button" class="close-profile-modal" id="ecopointModalClose" aria-label="Close">&times;</button>
      </div>
      <div class="eco-modal-body">
        <?php if (!$isLoggedIn): ?>
          <div class="eco-notice eco-notice-warn">
            <strong>For Residents Exclusively</strong>
            <small>This feature is available to Victorian Heights Subdivision residents only.</small>
          </div>
          <?php endif; ?>

          <p class="eco-modal-intro">VHEcoPoint is Victorian Heights Subdivision’s Smart Waste Segregation Station that automatically sorts recyclables and rewards you with points redeemable for free amenity bookings. Scan your VictorianPass QR code, deposit recyclables, and points are credited to your account instantly.</p>

          <div class="eco-chip-row">
            <span class="eco-chip"><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Clubhouse</span>
            <span class="eco-chip"><i class="fa-regular fa-calendar" aria-hidden="true"></i>Monday to Friday</span>
            <span class="eco-chip"><i class="fa-regular fa-clock" aria-hidden="true"></i>9:00 AM &ndash; 9:00 PM</span>
          </div>
        <h3 class="eco-section-title">How It Works</h3>
        <div class="eco-how-grid">
          <div class="eco-how-card">
            <span class="eco-how-tile" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></span>
            <span class="eco-how-badge" aria-hidden="true">1</span>
            <h4>Scan</h4>
            <p>Scan your VictorianPass QR code at the VHEcoPoint station. Your Personal QR code is in the VHEcoPoint dashboard and your Profile.</p>
          </div>
          <div class="eco-how-card">
            <span class="eco-how-tile" aria-hidden="true"><i class="fa-solid fa-recycle"></i></span>
            <span class="eco-how-badge" aria-hidden="true">2</span>
            <h4>Deposit</h4>
            <p>Drop your recyclable materials &mdash; Paper (Old Documents), Plastic Bottles, and Aluminum Cans. <a href="#acceptedMaterials" class="ecopoint-learn-more" data-ecopoint-jump="acceptedMaterials">Learn more</a></p>
          </div>
          <div class="eco-how-card">
            <span class="eco-how-tile" aria-hidden="true"><i class="fa-solid fa-gift"></i></span>
            <span class="eco-how-badge" aria-hidden="true">3</span>
            <h4>Earn &amp; Redeem</h4>
            <p>Points are credited automatically and redeemable for free amenity bookings.</p>
          </div>
        </div>

        <?php if ($isResident): ?>
        <div class="eco-cols">
          <article class="eco-panel">
            <h3 class="eco-panel-title">Points Guide</h3>
            <div class="eco-rate-panel">
              <div class="eco-rate-pts">0.303<small>pts per gram</small></div>
              <div class="eco-rate-materials">Paper (Old Documents) &middot; Plastic Bottles &middot; Aluminum Cans</div>
            </div>
            <div class="eco-value-bar">
              <span class="eco-value-bar-label"><i class="fa-solid fa-coins" aria-hidden="true"></i>EcoPoint Value</span>
              <span class="eco-value-bar-value">1 EcoPoint = &#8369;0.30</span>
            </div>
            <div class="eco-stat-grid">
              <div class="eco-stat"><span class="eco-stat-label">Daily Limit</span><span class="eco-stat-value">100 pts</span></div>
              <div class="eco-stat"><span class="eco-stat-label">Sessions / Day</span><span class="eco-stat-value">3 max</span></div>
              <div class="eco-stat"><span class="eco-stat-label">Weekly Cap</span><span class="eco-stat-value">250 pts</span></div>
              <div class="eco-stat"><span class="eco-stat-label">Max Balance</span><span class="eco-stat-value">3,000 pts</span></div>
            </div>
          </article>

          <article class="eco-panel">
            <h3 class="eco-panel-title">EcoPoint Redemption Guide</h3>
            <p class="eco-panel-sub">Use your EcoPoints for free amenity hours.</p>
            <div class="redeem-list">
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">300</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-basketball" aria-hidden="true"></i>Basketball Court or Tennis Court</span>
                  <span class="reward-desc">Requires 300 EcoPoints for 1 free hour of court use.</span>
                </div>
              </div>
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">600</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-building-columns" aria-hidden="true"></i>Clubhouse</span>
                  <span class="reward-desc">Requires 600 EcoPoints for 1 free hour of clubhouse use.</span>
                </div>
              </div>
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">750</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-building" aria-hidden="true"></i>Multi-Purpose Building</span>
                  <span class="reward-desc">Requires 750 EcoPoints for 1 free hour of facility use.</span>
                </div>
              </div>
            </div>
          </article>
        </div>

          <article class="eco-panel eco-panel-full" id="acceptedMaterials">
            <h3 class="eco-panel-title">Accepted Materials</h3>
            <div class="ecopoint-step-list">
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><i class="fa-solid fa-file-lines"></i></div>
                <div class="ecopoint-step-copy">
                  <h4>Paper (Old Documents)</h4>
                  <p>Accepted paper materials include old documents, Long Bond Paper, Short Bond / Letter Paper, and A4 Paper.</p>
                </div>
              </div>
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><i class="fa-solid fa-bottle-water"></i></div>
                <div class="ecopoint-step-copy">
                  <h4>Plastic Bottles</h4>
                  <p>Accepted plastic bottles include sturdy PET bottles in 1000ml, 500ml, and 240ml sizes, and 80ml Yakult bottles, which are polystyrene but are still included as accepted materials.</p>
                </div>
              </div>
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><img src="images/accepted-materials/aluminum-can.png" alt="" width="48" height="48" loading="lazy"></div>
                <div class="ecopoint-step-copy">
                  <h4>Aluminum Cans</h4>
                  <p>Accepted aluminum and metal tin can materials are limited to small to medium-sized cans.</p>
                </div>
              </div>
            </div>
          </article>
        <?php endif; ?>
      </div>

      <?php if ($isResident): ?>
      <div class="eco-modal-footer">
        <p class="eco-modal-footer-text">Your personal QR code is in your VHEcoPoint dashboard.</p>
        <a href="profileresident.php?section=panel-points-history" class="eco-dash-cta-btn" aria-label="Go to my VHEcoPoint dashboard">
          <span class="eco-dash-cta-logo"><?php echo vh_eco_logo('VHEcoPoint'); ?></span>
          <span>Go to my Dashboard</span>
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Login Required popup: root-level so its overlay stacks above the sticky navbar, matching the VHEcoPoint popup -->
  <div id="loginModal" class="flash-overlay" style="display:none;">
    <div class="vp-login-card" role="dialog" aria-modal="true" aria-labelledby="loginModalTitle">
      <button type="button" class="login-modal-close" id="loginModalClose" aria-label="Close">&times;</button>
      <div class="vp-login-icon" aria-hidden="true"><i class="fa-solid fa-user-lock"></i></div>
      <div class="title" id="loginModalTitle">Login Required</div>
      <div class="text" id="loginModalMessage">Please log in first to continue.</div>
      <div class="vp-login-actions">
        <button type="button" class="btn btn-confirm" id="loginModalConfirm">Login</button>
        <button type="button" class="btn btn-cancel" id="loginModalCancel">Cancel</button>
      </div>
    </div>
  </div>

  <section id="about-us" class="section reveal-on-scroll">
    <h2 class="section-title">About Us</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Heights Subdivision is a gated residential community located along Dahlia, Fairview, Brgy. Sauyo, Quezon City. Developed by Swire Land Corporation, it offers both accessibility and exclusivity within a secure environment. The subdivision consists of 222 houses with an estimated population of about 2,220 residents, providing a safe and well-protected living space. In addition, the community features thoughtfully designed homes that allow residents to enjoy convenient access to essential services while experiencing peace and comfort in a suburban setting.

</p>
      <img src="images/about subd.jpg" alt="Victorian Heights Subdivision" class="about-subdivision-photo">
      <ul class="about-facts">
        <li><span class="fact-value">222</span><span class="fact-label">houses</span></li>
        <li><span class="fact-value">about 2,220</span><span class="fact-label">residents</span></li>
        <li><span class="fact-value">Swire Land Corporation</span><span class="fact-label">developer</span></li>
        <li><span class="fact-value">Brgy. Sauyo, Quezon City</span><span class="fact-label">location</span></li>
      </ul>
    </div>
  </section>

  <section id="facilities" class="section reveal-on-scroll">
    <h2 class="section-title">Amenities you can book</h2>
    <div class="section-divider"></div>
    <div class="amenities-grid">
      <div class="amenity-card">
        <img src="images/multi purpose building.png" alt="Multi-Purpose Building">
        <h3 class="title">Multi-Purpose Building</h3>
        <p class="desc">A versatile space for various community activities, events, and recreational uses.</p>
        <div class="amenity-details">
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Availability</span>
              <span class="amenity-detail-value">Monday &ndash; Sunday</span>
              <span class="amenity-detail-sub">9:00 AM &ndash; 9:00 PM</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Capacity</span>
              <span class="amenity-detail-value">50 guests</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 17V7h3.2a2.6 2.6 0 0 1 0 5.2H9.5"></path><path d="M8 10h5"></path><path d="M8 12h5"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Price per hour</span>
              <span class="amenity-detail-value">&#8369;200 resident</span>
              <span class="amenity-detail-sub">&#8369;300 visitor</span>
            </div>
          </div>
        </div>
      </div>
      <div class="amenity-card">
        <img src="images/clubhouse.png" alt="Clubhouse">
        <h3 class="title">Clubhouse</h3>
        <p class="desc">A perfect venue for gatherings, celebrations, and community events within the subdivision.</p>
        <div class="amenity-details">
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Availability</span>
              <span class="amenity-detail-value">Monday &ndash; Sunday</span>
              <span class="amenity-detail-sub">9:00 AM &ndash; 9:00 PM</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Capacity</span>
              <span class="amenity-detail-value">200 guests</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 17V7h3.2a2.6 2.6 0 0 1 0 5.2H9.5"></path><path d="M8 10h5"></path><path d="M8 12h5"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Price per hour</span>
              <span class="amenity-detail-value">&#8369;300 resident</span>
              <span class="amenity-detail-sub">&#8369;450 visitor</span>
            </div>
          </div>
        </div>
      </div>
      <div class="amenity-card">
        <img src="images/basketballcourt.png" alt="Basketball Court">
        <h3 class="title">Basketball Court</h3>
        <p class="desc">Our outdoor basketball court provides residents a space for recreation, sports, and fitness activities.</p>
        <div class="amenity-details">
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Availability</span>
              <span class="amenity-detail-value">Monday &ndash; Sunday</span>
              <span class="amenity-detail-sub">9:00 AM &ndash; 6:00 PM</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Capacity</span>
              <span class="amenity-detail-value">30 guests</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 17V7h3.2a2.6 2.6 0 0 1 0 5.2H9.5"></path><path d="M8 10h5"></path><path d="M8 12h5"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Price per hour</span>
              <span class="amenity-detail-value">&#8369;100 resident</span>
              <span class="amenity-detail-sub">&#8369;150 visitor</span>
            </div>
          </div>
        </div>
      </div>
      <div class="amenity-card">
        <img src="images/tenniscourt.png" alt="Tennis Court">
        <h3 class="title">Tennis Court</h3>
        <p class="desc">Our tennis court offers residents a dedicated space for sports, recreation, and friendly matches.</p>
        <div class="amenity-details">
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v5l3 2"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Availability</span>
              <span class="amenity-detail-value">Monday &ndash; Sunday</span>
              <span class="amenity-detail-sub">9:00 AM &ndash; 6:00 PM</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M23 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Capacity</span>
              <span class="amenity-detail-value">60 guests</span>
            </div>
          </div>
          <div class="amenity-detail">
            <span class="amenity-detail-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M9.5 17V7h3.2a2.6 2.6 0 0 1 0 5.2H9.5"></path><path d="M8 10h5"></path><path d="M8 12h5"></path></svg></span>
            <div class="amenity-detail-text">
              <span class="amenity-detail-label">Price per hour</span>
              <span class="amenity-detail-value">&#8369;100 resident</span>
              <span class="amenity-detail-sub">&#8369;150 visitor</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <section id="how-it-works" class="section reveal-on-scroll">
    <h2 class="section-title">How amenity booking works</h2>
    <div class="section-divider"></div>
    <p class="section-subtitle">Every amenity reservation follows the same four steps &mdash; from your booking request to entering the subdivision.</p>
    <ol class="steps-list">
      <li class="step">
        <span class="step-number">1</span>
        <h3>Choose an amenity</h3>
        <p>Pick the facility, date, and time slot that suit you.</p>
      </li>
      <li class="step">
        <span class="step-number">2</span>
        <h3>Pay the downpayment</h3>
        <p>Settle the 50% downpayment via GCash and upload your receipt.</p>
      </li>
      <li class="step">
        <span class="step-number">3</span>
        <h3>Get approved</h3>
        <p>We verify your payment and release your QR entry pass.</p>
      </li>
      <li class="step">
        <span class="step-number">4</span>
        <h3>Scan at the gate</h3>
        <p>Show your QR pass to the guard to enter the subdivision.</p>
      </li>
    </ol>
    <div class="pass-card">
      <div class="pass-top-bar">
        <img src="images/logo.svg" alt="VictorianPass">
        <span>Victorian Heights</span>
      </div>
      <div class="pass-type-title">Amenity Pass</div>
      <div class="pass-status-header">
        <h1>Approved</h1>
        <p>Enter on your scheduled date and time</p>
      </div>
      <div class="pass-qr-wrap">
        <div class="pass-qr-box"><i class="fa-solid fa-qrcode" aria-hidden="true"></i></div>
        <div class="pass-qr-instruction">Present this QR code to the guard</div>
      </div>
      <div class="pass-details">
        <div class="pass-details-title">Pass Details</div>
        <div class="pass-row"><span class="pass-row-label">Pass Type</span><span class="pass-row-value">Amenity</span></div>
        <div class="pass-row"><span class="pass-row-label">Amenity</span><span class="pass-row-value">Clubhouse</span></div>
        <div class="pass-row"><span class="pass-row-label">Date</span><span class="pass-row-value">Oct 15, 2026</span></div>
        <div class="pass-row"><span class="pass-row-label">Time</span><span class="pass-row-value">2:00 PM &ndash; 5:00 PM</span></div>
      </div>
      <div class="pass-preview-note">Preview only &mdash; not a real entry pass</div>
    </div>
  </section>
  <section id="about-system" class="section reveal-on-scroll">
    <h2 class="section-title">About system VictorianPass</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Pass is a modern subdivision management system that utilizes QR technology to provide fast, secure, and seamless access for residents and visitors. Designed to enhance security and streamline daily processes, the system handles amenity reservations, entry pass requests, incident reporting, and user verification, all in one platform. By replacing manual checks with QR scanning, Victorian Pass ensures quicker entry and secure access while improving subdivision monitoring and welfare. The system also connects with the VHEcoPoint Smart Waste Segregation Station so residents can earn recycling rewards without blending the station identity into the main platform brand.</p>
      <div class="about-intro"><h3>Experience peace of mind designed to safeguard your neighborhood.</h3></div>
      <div class="about-system-grid">
        <div class="about-card">
          <img src="images/as1.png" alt="Community Life">
          <h3>What You'll Find in Victorian Heights Subdivision?</h3>
          <p>At Victorian Heights, life is secure, convenient, and truly connected. With Victorian Pass, residents enjoy hassle-free access to amenities, streamlined services, and a community that’s organized for comfort, safety, and a genuine sense of belonging.</p>
        </div>
        <div class="about-card">
          <img src="images/as2.png" alt="Quick Response">
          <h3>Quick response</h3>
          <p> QR-based entry and reservation systems allow fast approvals and real-time updates for residents, visitors, and security personnel, making processes smoother, safer, and easier to manage.</p>
        </div>
        <div class="about-card">
          <img src="images/as3.png" alt="A Shelter">
          <h3>A Shelter</h3>
          <p>A safe and welcoming space that provides comfort and peace of mind for every resident, with systems in place to protect and organize daily community life.</p>
        </div>
      </div>
    </div>
  </section>

  <footer class="site-footer">
    <div class="footer-lines">
      <p>Developed by Team Mantra</p>
      <p>MantraCore Solutions &middot; NU Fairview</p>
    </div>
    <hr class="footer-divider">
    <p class="footer-copy">&copy; 2026 VictorianPass &middot; Victorian Heights Subdivision, Quezon City</p>
  </footer>

  <script src="js/logout-modal.js"></script>
  <script>
    (function(){
      var toggle = document.getElementById('navToggle');
      var collapse = document.getElementById('navCollapse');
      var nav = document.getElementById('primaryNav');
      if(!toggle || !collapse) return;
      function setActiveLink(link){
        if(!nav || !link || link.classList.contains('nav-ecopoint')) return;
        nav.querySelectorAll('a[href^="#"]:not(.nav-ecopoint)').forEach(function(item){
          item.classList.remove('nav-active');
          item.removeAttribute('aria-current');
        });
        link.classList.add('nav-active');
        link.setAttribute('aria-current', 'location');
      }
      if(nav){
        var initialTarget = window.location.hash;
        var initialLink = initialTarget ? nav.querySelector('a[href="' + initialTarget + '"]') : nav.querySelector('a[href="#home"]');
        if(initialLink) setActiveLink(initialLink);
      }
      function closeMenu(){
        collapse.classList.remove('open');
        toggle.classList.remove('active');
        toggle.setAttribute('aria-expanded', 'false');
      }
      toggle.addEventListener('click', function(){
        var isOpen = collapse.classList.toggle('open');
        toggle.classList.toggle('active', isOpen);
        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
      });
      if(nav){
        nav.addEventListener('click', function(e){
          var link = e.target.closest ? e.target.closest('a[href^="#"]') : null;
          if(!link || link.getAttribute('href') === '#' || link.hasAttribute('data-ecopoint-modal-open')) return;
          setActiveLink(link);
          if(document.getElementById(decodeURIComponent(link.hash.slice(1))) && window.innerWidth <= 1080){
            closeMenu();
          }
        });
        window.addEventListener('hashchange', function(){
          var targetLink = window.location.hash ? nav.querySelector('a[href="' + window.location.hash + '"]') : nav.querySelector('a[href="#home"]');
          if(targetLink) setActiveLink(targetLink);
        });
      }
      window.addEventListener('click', function(e){
        if(!collapse.contains(e.target) && !toggle.contains(e.target)) closeMenu();
      });
      window.addEventListener('resize', function(){
        if(window.innerWidth > 1080) closeMenu();
      });
    })();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      var items = document.querySelectorAll('.reveal-on-scroll');
      if (!items.length) return;
      if (!('IntersectionObserver' in window)) {
        for (var i = 0; i < items.length; i++) {
          items[i].classList.add('is-visible');
        }
        return;
      }
      var observer = new IntersectionObserver(function(entries, obs){
        for (var i = 0; i < entries.length; i++) {
          var entry = entries[i];
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            obs.unobserve(entry.target);
          }
        }
      }, { threshold: 0.15 });
      for (var j = 0; j < items.length; j++) {
        observer.observe(items[j]);
      }
    });
  </script>
  <script>
    (function(){
      var openBtns = document.querySelectorAll('[data-ecopoint-modal-open]');
      var modal = document.getElementById('ecopointModal');
      var closeBtn = document.getElementById('ecopointModalClose');
      var ecoAppTile = modal ? modal.querySelector('.eco-app-tile') : null;
      var lastFocus = null;
      var hintTimer = null;

      function openModal(){
        if(!modal) return;
        lastFocus = document.activeElement;
        modal.style.display = 'flex';
        if(window.vpUpdateScrollLock) window.vpUpdateScrollLock();
        if(ecoAppTile && window.matchMedia('(max-width: 900px)').matches){
          window.clearTimeout(hintTimer);
          ecoAppTile.classList.add('is-hint-visible');
          hintTimer = window.setTimeout(function(){
            ecoAppTile.classList.remove('is-hint-visible');
          }, 2500);
        }
        setTimeout(function(){
          if(closeBtn) closeBtn.focus();
        }, 0);
        var ecoBtn = document.getElementById('navEcoPointBtn');
        if(ecoBtn) ecoBtn.classList.add('active');
      }

      function closeModal(){
        if(!modal) return;
        modal.style.display = 'none';
        if(window.vpUpdateScrollLock) window.vpUpdateScrollLock();
        window.clearTimeout(hintTimer);
        if(ecoAppTile) ecoAppTile.classList.remove('is-hint-visible');
        var ecoBtn = document.getElementById('navEcoPointBtn');
        if(ecoBtn) ecoBtn.classList.remove('active');
        if(lastFocus && typeof lastFocus.focus === 'function'){
          lastFocus.focus();
        }
      }

      if(openBtns && openBtns.length){
        for(var i = 0; i < openBtns.length; i++){
          openBtns[i].addEventListener('click', function(e){
            e.preventDefault();
            openModal();
          });
          openBtns[i].addEventListener('keydown', function(e){
            if(e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar'){
              e.preventDefault();
              openModal();
            }
          });
        }
      }

      if(closeBtn){
        closeBtn.addEventListener('click', function(e){
          e.preventDefault();
          closeModal();
        });
      }

      var jumpLinks = modal ? modal.querySelectorAll('[data-ecopoint-jump]') : [];
      for(var k = 0; k < jumpLinks.length; k++){
        jumpLinks[k].addEventListener('click', function(e){
          e.preventDefault();
          var card = document.getElementById(this.getAttribute('data-ecopoint-jump'));
          if(!card) return;
          if('scrollIntoView' in card){
            card.scrollIntoView({behavior:'smooth', block:'start'});
          }
          card.setAttribute('tabindex', '-1');
          card.focus({preventScroll:true});
        });
      }

      if(modal){
        modal.addEventListener('click', function(e){
          if(e.target === modal){
            closeModal();
          }
        });
      }

      document.addEventListener('keydown', function(e){
        if(!modal || modal.style.display === 'none') return;
        if(e.key === 'Escape'){
          closeModal();
        }
      });
    })();
  </script>
  <script>
    (function(){
      var params = new URLSearchParams(window.location.search);
      if(params.get('ecopoint') !== '1') return;
      var target = document.getElementById('home') || document.body;
      if(target && 'scrollIntoView' in target){
        target.scrollIntoView({behavior:'smooth', block:'start'});
      } else if(target){
        target.scrollIntoView();
      }
      window.addEventListener('load', function(){
        setTimeout(function(){
          var trigger = document.querySelector('[data-ecopoint-modal-open]');
          if(trigger && typeof trigger.click === 'function') trigger.click();
        }, 500);
      });
    })();
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function(){
      // Profile Dropdown Logic
      var profileUrl = <?php echo json_encode($userType === 'resident' ? 'profileresident.php' : 'dashboardvisitor.php'); ?>;
      var wrap = document.getElementById('profileWrap');
      var trigger = document.getElementById('profileAccountTrigger');
      var dropdown = document.getElementById('profileDropdown');
      
      if(wrap && dropdown && trigger) {
          var closeTimeout;

          function isMobile(){
            return window.innerWidth <= 900;
          }

          function openDropdown() {
              clearTimeout(closeTimeout);
              dropdown.style.display = 'block';
              requestAnimationFrame(function() {
                  dropdown.classList.add('show');
              });
              trigger.classList.add('active');
          }

          function closeDropdown() {
              closeTimeout = setTimeout(function() {
                  dropdown.classList.remove('show');
                  trigger.classList.remove('active');
                  setTimeout(function() {
                      if (!dropdown.classList.contains('show')) {
                          dropdown.style.display = 'none';
                      }
                  }, 300); // Match CSS transition duration
              }, 200); // Small delay before closing to allow moving mouse
          }

          // Hover Events (desktop only)
          if (!isMobile()) {
            wrap.addEventListener('mouseenter', openDropdown);
            wrap.addEventListener('mouseleave', closeDropdown);
          }

          // Click Toggle — on mobile, navigate to profile page directly
          trigger.addEventListener('click', function(e) {
              e.preventDefault();
              e.stopPropagation();
              if (isMobile()) {
                  window.location.href = profileUrl;
                  return;
              }
              if (dropdown.classList.contains('show')) {
                  dropdown.classList.remove('show');
                  trigger.classList.remove('active');
                  setTimeout(function() { dropdown.style.display = 'none'; }, 300);
              } else {
                  openDropdown();
              }
          });

          // Close when clicking outside
          window.addEventListener('click', function(e) {
              if (!wrap.contains(e.target)) {
                  if (dropdown.classList.contains('show')) {
                      dropdown.classList.remove('show');
                      trigger.classList.remove('active');
                      setTimeout(function() { dropdown.style.display = 'none'; }, 300);
                  }
              }
          });
      }
    });
  </script>

</body>
</html>
