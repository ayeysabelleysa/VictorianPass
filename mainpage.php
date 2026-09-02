<?php
include("connect.php");
require_once __DIR__ . '/session_bootstrap.php';

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
  <?php $mainCssVer = @filemtime(__DIR__ . '/CSS/mainpage.css') ?: time(); $respCssVer = @filemtime(__DIR__ . '/CSS/responsive.css') ?: time(); ?>
  <link rel="stylesheet" href="CSS/mainpage.css?v=<?php echo $mainCssVer; ?>">
  <link rel="stylesheet" href="CSS/responsive.css?v=<?php echo $respCssVer; ?>">
  
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
        <a href="#home">Home</a>
        <?php if (!$isVisitor): ?>
        <a href="#" class="nav-ecopoint" id="navEcoPointBtn" data-ecopoint-modal-open="true">
          <span class="nav-ecopoint-icon" aria-hidden="true">&#9851;</span>
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
      <p class="hero-eco-subtitle">Now with VHEcoPoint Rewards — Recycle &amp; Earn Points</p>
      <!-- Mobile-only: See description button for VHEcoPoint (short, close to subtitle) -->
      <button class="mobile-ecopoint-btn" data-ecopoint-modal-open="true" aria-controls="ecopointModal" aria-expanded="false">See VHEcoPoint description</button>
      <?php endif; ?>

      <div class="action-buttons" style="margin-top: 30px; gap:15px; flex-wrap:wrap;">
        <?php if (!$isLoggedIn): ?>
          <button class="btn-change btn-start" onclick="window.location.href='login.php'">Let’s Start</button>
        <?php elseif ($isVisitor): ?>
          <button class="btn-change btn-reserve" onclick="window.location.href='reserve.php'">Reserve an Amenity</button>
        <?php else: ?>
          <button class="btn-change btn-eco" onclick="window.location.href='profileresident.php?section=panel-points-history'">View VHEcoPoint</button>
          <button class="btn-change btn-dashboard" onclick="window.location.href='profileresident.php'">My Dashboard</button>
        <?php endif; ?>
      </div>
      
      <!-- Login Required Modal -->
      <div id="loginModal" class="flash-overlay" style="display:none;">
        <div class="flash-modal" style="text-align:center; padding:30px;">
          <div class="title" style="color:#23412e; font-size:1.5rem; margin-bottom:10px;">Login Required</div>
          <div class="text" style="color:#555; margin-bottom:20px;">Please login to view your status.</div>
          <div style="display:flex; gap:10px; justify-content:center;">
             <button onclick="window.location.href='login.php'" style="padding:10px 20px; background:#23412e; color:#fff; border:none; border-radius:5px; cursor:pointer;">Login</button>
             <button onclick="document.getElementById('loginModal').style.display='none'" style="padding:10px 20px; background:#ccc; color:#333; border:none; border-radius:5px; cursor:pointer;">Cancel</button>
          </div>
        </div>
      </div>

      <script>
         document.getElementById('loginModal').addEventListener('click', function(e) {
             if (e.target === this) this.style.display = 'none';
         });
      </script>
    </div>
  </section>

  <?php if (!$isVisitor && ($isResident || !$isLoggedIn)): ?>
  <div id="ecopointModal" class="flash-overlay ecopoint-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="ecopointModalTitle">
    <div class="flash-modal">
      <button type="button" class="ecopoint-modal-close" id="ecopointModalClose" aria-label="Close">&times;</button>
      <div class="ecopoint-shell">
        <div class="ecopoint-intro-card" style="margin-bottom:18px;">
          <h2 class="section-title ecopoint-title" id="ecopointModalTitle"><span class="ecopoint-title-icon" aria-hidden="true"><i class="fa-solid fa-leaf"></i></span><span>Learn About VHEcoPoint</span></h2>
          <div class="section-divider"></div>          <?php if (!$isLoggedIn): ?>
          <div style="background:#fef3c7; border:1px solid #fcd34d; border-radius:8px; padding:12px 14px; margin-bottom:16px; color:#92400e;">
            <strong style="display:block; margin-bottom:4px;">For Residents Exclusively</strong>
            <small>This feature is available to Victorian Heights Subdivision residents only. Please register as a resident to participate.</small>
          </div>
          <?php endif; ?>          <p class="section-subtitle ecopoint-description">VHEcoPoint is Victorian Heights Subdivision’s Smart Waste Segregation Station that automatically sorts recyclables and rewards you with points redeemable for free amenity bookings. Scan your VictorianPass QR code, deposit recyclables, and points are credited to your account instantly.</p>
            <?php if ($isResident): ?>
            <p style="background:#dbeafe; border:1px solid #7dd3fc; border-radius:8px; padding:10px 12px; color:#0c4a6e; font-weight:600;"><strong><i class="fa-solid fa-lightbulb" style="margin-right:6px;"></i>Tip:</strong> You can view your personal QR code on your <a href="profileresident.php" style="color:#0369a1; text-decoration:underline;">profile dashboard</a> page.</p>
            <?php endif; ?>
            <h3 class="ecopoint-card-title">How It Works</h3>
            <div class="ecopoint-step-list">
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></div>
                <div class="ecopoint-step-copy">
                  <h4>Scan</h4>
                  <p>Scan your VictorianPass QR code at the VHEcoPoint station.</p>
                </div>
              </div>
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><i class="fa-solid fa-recycle"></i></div>
                <div class="ecopoint-step-copy">
                  <h4>Deposit</h4>
                  <p>Drop your recyclables (Plastic, Aluminum Cans, Paper &amp; Cardboard).</p>
                </div>
              </div>
              <div class="ecopoint-step-item">
                <div class="ecopoint-step-icon" aria-hidden="true"><i class="fa-solid fa-gift"></i></div>
                <div class="ecopoint-step-copy">
                  <h4>Earn &amp; Redeem</h4>
                  <p>Points are credited automatically and redeemable for free amenity bookings.</p>
                </div>
              </div>
            </div>
          </article>

          <?php if ($isResident): ?>
          <div class="ecopoint-duo-grid">
          <article class="ecopoint-card">
            <h3 class="ecopoint-card-title">Points Guide</h3>
            <div class="pg-list">
              <div class="pg-row">
                <span class="pg-item"><span class="pg-dot" aria-hidden="true"></span>Plastic (≤1000ml)</span>
                <span class="pg-pts">55<small>pts/kg</small></span>
              </div>
              <div class="pg-row">
                <span class="pg-item"><span class="pg-dot" aria-hidden="true"></span>Aluminum Cans</span>
                <span class="pg-pts">140<small>pts/kg</small></span>
              </div>
              <div class="pg-row">
                <span class="pg-item"><span class="pg-dot" aria-hidden="true"></span>Paper &amp; Cardboard</span>
                <span class="pg-pts">30<small>pts/kg</small></span>
              </div>
            </div>
            <div class="pg-rate">
              <span class="pg-rate-label"><i class="fa-solid fa-coins" aria-hidden="true"></i>1 point value</span>
              <span class="pg-rate-value">&#8369;0.30</span>
            </div>
            <div class="pg-limits">
              <div class="pg-limit">
                <i class="fa-solid fa-calendar-week" aria-hidden="true"></i>
                <span><strong>Weekly Cap:</strong> 250 pts</span>
              </div>
              <div class="pg-limit">
                <i class="fa-solid fa-calendar-day" aria-hidden="true"></i>
                <span><strong>Maximum:</strong> 3 recycling sessions/day</span>
              </div>
            </div>
            <div class="ecopoint-footnote pg-note">Maximum balance: 3,000 pts</div>
          </article>

          <article class="ecopoint-card">
            <h3 class="ecopoint-card-title">Redeem Your Points</h3>
            <p class="redeem-sub">Your EcoPoints redeem free hours at our subdivision amenities.</p>
            <div class="redeem-list">
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">300</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-basketball" aria-hidden="true"></i>Basketball Court or Tennis Court</span>
                  <span class="reward-desc">Redeem 300 points for 1 complimentary hour of court use.</span>
                </div>
              </div>
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">600</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-building-columns" aria-hidden="true"></i>Clubhouse</span>
                  <span class="reward-desc">Redeem 600 points for 1 complimentary hour of clubhouse use.</span>
                </div>
              </div>
              <div class="reward-card">
                <div class="reward-pts"><span class="reward-pts-num">750</span><span class="reward-pts-unit">pts</span></div>
                <div class="reward-info">
                  <span class="reward-label">1 FREE HOUR</span>
                  <span class="reward-amenity"><i class="fa-solid fa-building" aria-hidden="true"></i>Multi-Purpose Building</span>
                  <span class="reward-desc">Redeem 750 points for 1 complimentary hour of facility use.</span>
                </div>
              </div>
            </div>
          </article>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <section id="about-us" class="section reveal-on-scroll">
    <h2 class="section-title">About Us</h2>
    <div class="section-divider"></div>
    <div class="section-body">
      <p>Victorian Heights Subdivision is a gated residential community located along Dahlia, Fairview, Brgy. Sauyo, Quezon City. Developed by Swire Land Corporation, it offers both accessibility and exclusivity within a secure environment. The subdivision consists of 222 houses with an estimated population of about 2,220 residents, providing a safe and well-protected living space. In addition, the community features thoughtfully designed homes that allow residents to enjoy convenient access to essential services while experiencing peace and comfort in a suburban setting.

</p>
      <img src="images/about subd.jpg" alt="Victorian Heights Subdivision" class="about-subdivision-photo">
    </div>
  </section>

  <section id="facilities" class="section reveal-on-scroll">
    <h2 class="section-title">Amenities</h2>
    <div class="section-divider"></div>
    <div class="amenities-grid">
      <div class="amenity-card">
        <img src="images/multi purpose building.png" alt="Multi-Purpose Building">
        <h3 class="title">Multi-Purpose Building</h3>
        <p class="desc">A versatile space for various community activities, events, and recreational uses.</p>
      </div>
      <div class="amenity-card">
        <img src="images/clubhouse.png" alt="Clubhouse">
        <h3 class="title">Clubhouse</h3>
        <p class="desc">A perfect venue for gatherings, celebrations, and community events within the subdivision.</p>
      </div>
      <div class="amenity-card">
        <img src="images/basketballcourt.png" alt="Basketball Court">
        <h3 class="title">Basketball Court</h3>
        <p class="desc">Our outdoor basketball court provides residents a space for recreation, sports, and fitness activities.</p>
      </div>
      <div class="amenity-card">
        <img src="images/tenniscourt.png" alt="Tennis Court">
        <h3 class="title">Tennis Court</h3>
        <p class="desc">Our tennis court offers residents a dedicated space for sports, recreation, and friendly matches.</p>
      </div>
    </div>
  </section>
  <section id="about-system" class="section reveal-on-scroll">
    <h2 class="section-title">About the System</h2>
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



  <script src="js/logout-modal.js"></script>
  <script>
    (function(){var t=document.getElementById('navToggle');var c=document.getElementById('navCollapse');if(!t||!c)return;t.addEventListener('click',function(){var o=c.classList.toggle('open');t.setAttribute('aria-expanded',o?'true':'false');});window.addEventListener('click',function(e){if(!c.contains(e.target)&&!t.contains(e.target)){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});window.addEventListener('resize',function(){if(window.innerWidth>900){c.classList.remove('open');t.setAttribute('aria-expanded','false');}});})();
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
      var lastFocus = null;

      function openModal(){
        if(!modal) return;
        lastFocus = document.activeElement;
        modal.style.display = 'flex';
        setTimeout(function(){
          if(closeBtn) closeBtn.focus();
        }, 0);
      }

      function closeModal(){
        if(!modal) return;
        modal.style.display = 'none';
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
        }
      }

      if(closeBtn){
        closeBtn.addEventListener('click', function(e){
          e.preventDefault();
          closeModal();
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
          }

          function closeDropdown() {
              closeTimeout = setTimeout(function() {
                  dropdown.classList.remove('show');
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
                      setTimeout(function() { dropdown.style.display = 'none'; }, 300);
                  }
              }
          });
      }
    });
  </script>

</body>
</html>
