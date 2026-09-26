<?php
/**
 * Shared top-header — replicates the Profile Dashboard (profileresident.php)
 * compact header: logo + brand text on the left, user profile on the right.
 * No hamburger / sidebar / nav links.  Used by reserve.php and downpayment.php.
 *
 * Only reads the session + a lightweight user lookup; never changes any
 * payment/reservation logic.  Definitions are namespaced with $nav* so they
 * cannot collide with page-level variables.
 */
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'] ?? '')) { exit; }

$navUid = 0;
$navUserType = isset($_SESSION['user_type']) ? (string)$_SESSION['user_type'] : '';
$navUserName = '';
$navUserFirstName = '';
$navIsLoggedIn = false;
$navProfilePicUrl = 'images/logo.svg';

if (isset($_SESSION['user_id'])) { $navUid = (int)$_SESSION['user_id']; }

if ($navUid > 0 && isset($con) && ($con instanceof mysqli)) {
    try {
        $stmtN = $con->prepare("SELECT first_name, middle_name, last_name, user_type FROM users WHERE id = ? LIMIT 1");
        if ($stmtN) {
            $stmtN->bind_param('i', $navUid);
            if ($stmtN->execute()) {
                $resN = $stmtN->get_result();
                if ($rowN = $resN->fetch_assoc()) {
                    $navIsLoggedIn = true;
                    $navUserName = trim(($rowN['first_name'] ?? '') . ' ' . (($rowN['middle_name'] ?? '') ? ($rowN['middle_name'] . ' ') : '') . ($rowN['last_name'] ?? ''));
                    $navUserFirstName = (string)($rowN['first_name'] ?? '');
                    if (!empty($rowN['user_type'])) { $navUserType = (string)$rowN['user_type']; }
                    $picPath = 'images/logo.svg';
                    foreach (['jpg', 'png', 'jpeg'] as $extN) {
                        if (file_exists(__DIR__ . '/uploads/profiles/user_' . $navUid . '.' . $extN)) {
                            $picPath = 'uploads/profiles/user_' . $navUid . '.' . $extN;
                            break;
                        }
                    }
                    $navProfilePicUrl = $picPath . '?t=' . time();
                }
            }
            $stmtN->close();
        }
    } catch (Throwable $_) { /* non-fatal */ }
}

$navDashboardUrl = (strtolower($navUserType) === 'resident') ? 'profileresident.php' : 'dashboardvisitor.php';
$navGreeting = $navUserFirstName !== '' ? $navUserFirstName : ($navUserName !== '' ? $navUserName : 'User');
$navShowEcoPoint = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'reserve.php' && strtolower($navUserType) === 'resident';
?>
<header class="top-header">
  <div class="header-brand">
    <a href="mainpage.php" class="header-brand-link" aria-label="Go to Main Page"><img src="images/logo.svg" alt="VictorianPass Logo"></a>
    <div class="brand-text">
      <span class="brand-main">VictorianPass</span>
      <span class="brand-sub">Victorian Heights Subdivision</span>
    </div>
  </div>
  <?php if ($navShowEcoPoint): ?>
    <a href="<?php echo htmlspecialchars($navDashboardUrl . '?section=panel-points-history', ENT_QUOTES); ?>" class="header-ecopoint-link" aria-label="Open VHEcoPoint dashboard" title="VHEcoPoint dashboard"><?php echo vh_eco_logo('VHEcoPoint', 'nav-eco-logo'); ?></a>
  <?php else: ?>
    <div class="header-actions">
      <?php if ($navIsLoggedIn): ?>
        <a href="<?php echo htmlspecialchars($navDashboardUrl); ?>" class="user-profile">
          <span class="user-name">Hi, <?php echo htmlspecialchars($navGreeting); ?></span>
          <img src="<?php echo htmlspecialchars($navProfilePicUrl); ?>" alt="Profile" class="user-avatar" onerror="this.onerror=null;this.src='images/logo.svg'">
        </a>
      <?php else: ?>
        <div class="nav-links" style="display:flex; gap:10px;">
          <a href="login.php" class="btn-nav btn-login">Login</a>
          <a href="signup.php" class="btn-nav btn-register">Register</a>
        </div>
      <?php endif; ?>
      </div>
  <?php endif; ?>
</header>
