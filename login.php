<?php
$staffInactivityLimit = 2700;
ini_set('session.gc_maxlifetime', (string)$staffInactivityLimit);
require_once __DIR__ . '/session_bootstrap.php';
include("connect.php");  

$loginError = '';
$loginErrorMessage = '';
$loginSuccessMessage = '';
$loginRedirect = '';
$prefillEmail = '';
$cooldownRemaining = 0;
$staffSessionActive = false;
$activeRole = strtolower(trim($_SESSION['role'] ?? ''));
$activeAdminRole = strtolower(trim($_SESSION['admin_role'] ?? ''));
if (in_array($activeRole, ['admin', 'guard'], true) || in_array($activeAdminRole, ['admin', 'guard'], true) || isset($_SESSION['admin_id'])) {
    $staffSessionActive = true;
    $loginError = 'already_logged_in';
    $loginErrorMessage = 'You are already logged in. Please log out before signing in to another account.';
}

  function redirectAfterLogin($location) {
    session_write_close();
    header('Location: ' . $location, true, 303);
    exit;
  }

if ($_SERVER["REQUEST_METHOD"] == "POST" && !$staffSessionActive) {
    if (isset($_POST['vp_account']) && isset($_POST['password'])) {
        $email = trim($_POST['vp_account']);
        $password = trim($_POST['password']);
        $prefillEmail = $email;

        $cooldownActive = false;
        if (isset($_SESSION['login_cooldown_until'])) {
            $remain = intval($_SESSION['login_cooldown_until']) - time();
            if ($remain > 0) {
                $cooldownActive = true;
                $loginError = 'cooldown';
                $loginErrorMessage = 'Too many failed attempts. Try again in ' . max(0, $remain) . ' seconds.';
            } else {
                unset($_SESSION['login_cooldown_until']);
                $_SESSION['login_failure_count'] = 0;
            }
        }

        if (!$cooldownActive) {
        // Step 1: Check if account exists in staff (admin/guard)
        $sql_staff = "SELECT id, email, password, role FROM staff WHERE email = ? LIMIT 1";
        $stmt_staff = $con->prepare($sql_staff);
        $stmt_staff->bind_param("s", $email);
        $stmt_staff->execute();
        $result_staff = $stmt_staff->get_result();

        $skipUserCheck = false;
        if ($result_staff->num_rows > 0) {
            $row = $result_staff->fetch_assoc();

            $storedStaffPassword = (string)($row['password'] ?? '');
            $staffPasswordValid = password_verify($password, $storedStaffPassword) || hash_equals($storedStaffPassword, $password);
            if ($staffPasswordValid) {
              session_regenerate_id(true);
                $_SESSION['email'] = $row['email'];
              $_SESSION['role']  = strtolower(trim((string)$row['role']));
                $_SESSION['staff_id'] = $row['id'];

              if ($_SESSION['role'] === "admin") {
                    $_SESSION['staff_last_activity'] = time();
                    $_SESSION['staff_session_timeout'] = $staffInactivityLimit;
                redirectAfterLogin('admin.php');
              } elseif ($_SESSION['role'] === "guard") {
                    $_SESSION['staff_last_activity'] = time();
                    $_SESSION['staff_session_timeout'] = $staffInactivityLimit;
                $local = explode('@', (string)$_SESSION['email'])[0] ?? '';
                    $s = $local;
                    if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
                    if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
                    $s = preg_replace('/[^a-zA-Z]/', '', $s);
                    $s = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
                    $_SESSION['guard_surname'] = $s;
                redirectAfterLogin('guard.php');
                }
                $skipUserCheck = true;
            } else {
                $loginError = 'invalid_password';
                $loginErrorMessage = 'Incorrect password.';
                $_SESSION['login_failure_count'] = intval($_SESSION['login_failure_count'] ?? 0) + 1;
                if (intval($_SESSION['login_failure_count']) >= 3) {
                    $_SESSION['login_cooldown_until'] = time() + 60;
                    $loginError = 'cooldown';
                    $loginErrorMessage = 'Too many failed attempts. Try again in 60 seconds.';
                }
                $skipUserCheck = true;
            }
        }
        $stmt_staff->close();

        // ✅ Step 2: Check if account exists in users (resident/visitor)
        if (!$skipUserCheck) {
            $sql_user = "SELECT id, email, password, user_type, status, suspension_reason FROM users WHERE email = ? LIMIT 1";
            $stmt_user = $con->prepare($sql_user);
            $stmt_user->bind_param("s", $email);
            $stmt_user->execute();
            $result_user = $stmt_user->get_result();

            if ($result_user->num_rows > 0) {
                $row = $result_user->fetch_assoc();

                if (password_verify($password, $row['password'])) {
                    $status = strtolower(trim($row['status'] ?? ''));
                    if ($status === 'disabled') {
                        $loginError = 'suspended_account';
                        $reason = trim($row['suspension_reason'] ?? '');
                        if ($reason !== '') {
                            $loginErrorMessage = 'Your account has been suspended by the admin. Reason: ' . $reason;
                        } else {
                            $loginErrorMessage = 'Your account has been suspended by the admin.';
                        }
                    } else {
                      session_regenerate_id(true);
                        $_SESSION['user_id']   = $row['id'];
                        $_SESSION['email']     = $row['email'];
                      $_SESSION['user_type'] = strtolower(trim((string)$row['user_type']));
                      $_SESSION['role']      = $_SESSION['user_type'];

                      if ($_SESSION['user_type'] === 'resident') {
                        redirectAfterLogin('profileresident.php');
                        } else {
                        redirectAfterLogin('dashboardvisitor.php');
                        }
                    }
                } else {
                    $loginError = 'invalid_password';
                    $loginErrorMessage = 'Incorrect password.';
                    $_SESSION['login_failure_count'] = intval($_SESSION['login_failure_count'] ?? 0) + 1;
                    if (intval($_SESSION['login_failure_count']) >= 3) {
                        $_SESSION['login_cooldown_until'] = time() + 60;
                        $loginError = 'cooldown';
                        $loginErrorMessage = 'Too many failed attempts. Try again in 60 seconds.';
                    }
                }
            } else {
                $loginError = 'account_not_found';
                $loginErrorMessage = 'This account doesn’t exist';
            }
            $stmt_user->close();
        }
        } // end cooldown check

    } else {
        $loginError = 'missing_fields';
        $loginErrorMessage = 'Please enter both email and password.';
    }
}
$cooldownRemaining = isset($_SESSION['login_cooldown_until']) ? max(0, intval($_SESSION['login_cooldown_until']) - time()) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <link rel="icon" type="image/png" href="images/logo.svg">

  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
        body {
  animation: fadeIn 0.6s ease-in-out;
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
@keyframes fadeOut {
  from { opacity: 1; transform: translateY(0); }
  to { opacity: 0; transform: translateY(-8px); }
}
.page-fade-out {
  animation: fadeOut 0.35s ease-in forwards;
}
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }

    body {
      display: flex;
      min-height: 100vh;
    }

    .login-modal {
      position: fixed;
      inset: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(0,0,0,0.5);
      z-index: 9999;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity 0.22s ease, visibility 0.22s ease;
    }
    .login-modal.is-visible {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    .login-modal .modal-content {
      background: #fff;
      border-radius: 12px;
      padding: 28px 24px;
      width: 90%;
      max-width: 380px;
      text-align: center;
      position: relative;
      box-shadow: 0 12px 30px rgba(0,0,0,0.18);
      transform: translateY(8px) scale(0.98);
      transition: transform 0.22s ease;
    }
    .login-modal.is-visible .modal-content {
      transform: translateY(0) scale(1);
    }
    .login-modal .modal-close {
      position: absolute;
      right: 12px;
      top: 10px;
      background: transparent;
      border: 0;
      font-size: 22px;
      cursor: pointer;
      color: #666;
    }
    .login-modal .modal-title {
      font-size: 1.15rem;
      font-weight: 700;
      color: #c0392b;
      margin-bottom: 10px;
    }
    .login-modal .modal-message {
      color: #444;
      font-size: 0.95rem;
      margin-bottom: 18px;
    }
    .login-modal .modal-btn {
      border: 0;
      background: #23412e;
      color: #fff;
      padding: 10px 18px;
      border-radius: 8px;
      cursor: pointer;
      font-weight: 600;
      width: 100%;
    }
    .login-modal.success .modal-title {
      color: #1e8f3e;
    }
    .login-modal.success .modal-btn {
      background: #1e8f3e;
    }

    .login-wrapper {
      display: flex;
      width: 100%;
    }

    /* LEFT SIDE */
    .login-left {
      flex: 1;
      background: url("images/signuppage/loginsingup.png") center/cover no-repeat;
      display: flex;
      align-items: center;
      padding: 60px;
      position: relative;
      color: #fff;
    }

    .login-left::before {
      content: "";
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.28);
    }

    .branding {
      position: relative;
      z-index: 2;
      max-width: 400px;
    }

    .branding h1 {
      font-size: 2.4rem;
      font-weight: 700;
      margin-bottom: 15px;
    }

    .branding p {
      font-size: 1rem;
      line-height: 1.5;
    }

    /* RIGHT SIDE */
    .login-right {
      flex: 1;
      background: #fff;
      display: flex;
      justify-content: center;
      align-items: center;
      padding: 40px;
      position: relative;
    }

.back-arrow {
  position: absolute;
  top: 1.5rem;
  left: 1.5rem;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: #d4a017;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  box-shadow: 0 6px 14px rgba(212, 160, 23, 0.35);
}
.back-arrow i {
  font-size: 18px;
  color: #ffffff;
  cursor: pointer;
}

    .login-box {
      width: 100%;
      max-width: 420px; /* ✅ wider form */
    }

    .login-box img.biglogo {
      width: 80px;
      display: block;
      margin: 0 auto 15px;
    }

    .login-box h2 {
      text-align: center;
      font-size: 1.8rem;
      margin-bottom: 5px;
    }

    .subtitle {
      text-align: center;
      margin-bottom: 25px;
      color: #666;
    }

    .login-box form {
      display: flex;
      flex-direction: column;
    }

    .login-box label {
      font-size: 0.9rem;
      margin-bottom: 6px;
      font-weight: 500;
    }

    .login-box input {
      padding: 14px;
      margin-bottom: 18px;
      border-radius: 8px;
      border: 1px solid #ccc;
      font-size: 1rem;
    }

    .login-box input:focus {
      border-color: #23412e;
    }

    .password-field {
      position: relative;
    }

    .password-field input {
      width: 100%;
      padding-right: 40px;
    }

    .toggle-password {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: #666;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 24px;
      height: 24px;
      transition: color 0.2s ease;
    }
    .toggle-password:hover { color: #23412e; }
    .toggle-password svg { width: 20px; height: 20px; }

    .forgot {
      text-align: right;
      margin-bottom: 20px;
    }

    .forgot a {
      color: #23412e;
      font-size: 0.85rem;
      text-decoration: none;
    }

    .btn-login {
      padding: 14px;
      border: none;
      border-radius: 8px;
      background: #23412e;
      color: #fff;
      font-weight: 600;
      font-size: 1rem;
      cursor: pointer;
      transition: 0.2s;
    }

    .btn-login:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
    }

    .signup-link {
      margin-top: 15px;
      text-align: center;
      font-size: 0.9rem;
    }

    .signup-link a {
      color: #23412e;
      font-weight: 600;
      text-decoration: none;
    }

    .signup-link a:hover {
      text-decoration: underline;
    }

    @media (max-width: 900px) {
      body {
        display: block;
      }
      .login-wrapper {
        flex-direction: column;
        min-height: 100vh;
      }
      .login-left {
        min-height: 240px;
        padding: 40px 28px;
      }
      .login-right {
        padding: 32px 24px;
      }
      .back-arrow {
        top: 1rem;
        left: 1rem;
      }
      .login-box {
        max-width: 520px;
        margin: 0 auto;
      }
    }

    @media (max-width: 600px) {
      .login-left {
        min-height: 200px;
        padding: 32px 20px;
      }
      .branding h1 {
        font-size: 1.9rem;
      }
      .branding p {
        font-size: 0.95rem;
      }
      .login-right {
        padding: 24px 16px;
      }
      .login-box img.biglogo {
        width: 64px;
        margin-bottom: 12px;
      }
      .login-box h2 {
        font-size: 1.6rem;
      }
      .subtitle {
        font-size: 0.95rem;
        margin-bottom: 18px;
      }
      .login-box input {
        padding: 12px;
        font-size: 0.95rem;
      }
      .btn-login {
        padding: 12px;
        font-size: 0.95rem;
      }
      .btn-login:hover {
        transform: none;
        box-shadow: none;
      }
      .login-modal .modal-content {
        width: 92%;
        max-width: 340px;
      }
    }

    @media (max-width: 420px) {
      .login-left {
        min-height: 170px;
        padding: 24px 16px;
      }
      .branding h1 {
        font-size: 1.6rem;
      }
      .branding p {
        font-size: 0.85rem;
      }
      .forgot {
        text-align: left;
      }
    }
  </style>
</head>
<body>
  <div class="login-wrapper">
    <!-- Left Side -->
    <div class="login-left">
    </div>

    <!-- Right Side -->
    <div class="login-right">
      <a href="mainpage.php" class="back-arrow" aria-label="Back">
        <i class="fa-solid fa-arrow-left"></i>
      </a>

      <div class="login-box">
        <img src="images/loginpage/biglogo.svg" alt="Logo" class="biglogo">
        <h2>Welcome Back!</h2>
        <p class="subtitle">To Victorian Heights</p>

        <form action="login.php" method="POST">
          <label for="vp_account">Email</label>
          <input type="email" id="vp_account" name="vp_account" placeholder="Email*" required value="<?php echo htmlspecialchars($prefillEmail, ENT_QUOTES); ?>" <?php echo $staffSessionActive ? 'disabled' : ''; ?>>

          <label for="password">Password</label>
          <div class="password-field">
            <input type="password" id="password" name="password" placeholder="Password*" required <?php echo $staffSessionActive ? 'disabled' : ''; ?>>
            <span class="toggle-password" onclick="togglePassword('password', this)">
              <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="feather feather-eye"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
            </span>
          </div>

          <div class="forgot">
            <a href="forgot_password.php" id="forgotLink">Forgot Password?</a>
          </div>

          <button type="submit" class="btn-login" id="btnLogin" <?php echo $staffSessionActive ? 'disabled' : ''; ?>>Login</button>
          <div id="cooldownInfo" style="text-align:center;margin-top:8px;font-size:0.9rem;color:#c0392b;"></div>
        </form>

        <p class="signup-link">
          Don’t have an account yet? <a href="signup.php">Sign up</a>
        </p>
      </div>
    </div>
  </div>

  <div id="loginErrorModal" class="login-modal">
    <div class="modal-content">
      <button type="button" class="modal-close" onclick="closeLoginError()">&times;</button>
      <div class="modal-title">Error</div>
      <div class="modal-message" id="loginErrorMessage"></div>
      <button type="button" class="modal-btn" onclick="closeLoginError()">OK</button>
    </div>
  </div>
  <div id="loginSuccessModal" class="login-modal success">
    <div class="modal-content">
      <button type="button" class="modal-close" onclick="closeLoginSuccess()">&times;</button>
      <div class="modal-title">Success</div>
      <div class="modal-message" id="loginSuccessMessage"></div>
      <button type="button" class="modal-btn" onclick="closeLoginSuccess()">Continue</button>
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

    function setLoginModalVisible(modal, visible) {
      if (!modal) return;
      if (visible) {
        modal.classList.add('is-visible');
      } else {
        modal.classList.remove('is-visible');
      }
    }
    function openLoginError(message) {
      const modal = document.getElementById('loginErrorModal');
      const msg = document.getElementById('loginErrorMessage');
      if (msg) msg.textContent = message;
      setLoginModalVisible(modal, true);
    }

    function closeLoginError() {
      const modal = document.getElementById('loginErrorModal');
      setLoginModalVisible(modal, false);
    }
    function openLoginSuccess(message) {
      const modal = document.getElementById('loginSuccessModal');
      const msg = document.getElementById('loginSuccessMessage');
      if (msg) msg.textContent = message;
      setLoginModalVisible(modal, true);
    }
    function closeLoginSuccess() {
      const modal = document.getElementById('loginSuccessModal');
      setLoginModalVisible(modal, false);
      if (window._loginRedirect) {
        window.location.href = window._loginRedirect;
      }
    }

    document.addEventListener('DOMContentLoaded', function() {
      const errMsg = "<?php echo htmlspecialchars($loginErrorMessage, ENT_QUOTES); ?>";
      if (errMsg) {
        openLoginError(errMsg);
        const pwd = document.getElementById('password');
        if (pwd) pwd.value = '';
      }
      const successMsg = "<?php echo htmlspecialchars($loginSuccessMessage, ENT_QUOTES); ?>";
      const redirectTo = "<?php echo htmlspecialchars($loginRedirect, ENT_QUOTES); ?>";
      if (successMsg) {
        window._loginRedirect = redirectTo || '';
        openLoginSuccess(successMsg);
      }
      const btn = document.getElementById('btnLogin');
      const info = document.getElementById('cooldownInfo');
      let remain = parseInt("<?php echo intval($cooldownRemaining); ?>", 10);
      if (btn && info && remain > 0) {
        btn.disabled = true;
        const tick = function(){
          if (remain <= 0) {
            btn.disabled = false;
            info.textContent = '';
            clearInterval(timer);
            return;
          }
          info.textContent = 'Please wait ' + remain + ' seconds before retrying.';
          remain -= 1;
        };
        tick();
        const timer = setInterval(tick, 1000);
      }
      const forgotLink = document.getElementById('forgotLink');
      if (forgotLink) {
        forgotLink.addEventListener('click', function(e){
          if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
          }
          e.preventDefault();
          document.body.classList.add('page-fade-out');
          setTimeout(function(){
            window.location.href = forgotLink.href;
          }, 320);
        });
      }
    });
  </script>
</body>
</html>
