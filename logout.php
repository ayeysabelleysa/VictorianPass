<?php
// Logout must never block behind the PHP session file lock. The dashboards run
// background polling requests that hold that lock while doing DB work, so on shared
// hosts (Hostinger) a normal session_start() here can wait long enough to blow past
// the gateway timeout and return 504. Cookie-auth mode reads the signed vp_auth
// cookie instead of starting/holding the PHP session, so the final Log Out always
// completes immediately.
if (!defined('VP_SESSION_COOKIE_AUTH')) {
  define('VP_SESSION_COOKIE_AUTH', true);
}
if (!defined('VP_SESSION_READONLY')) {
  define('VP_SESSION_READONLY', true);
}
require_once __DIR__ . '/session_bootstrap.php';
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
if ($confirmed) {
  if (function_exists('vpAuthClearCookie')) {
    vpAuthClearCookie();
  }
  $_SESSION = [];
  $sid = isset($_COOKIE[session_name()]) ? (string)$_COOKIE[session_name()] : '';
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  if (session_status() === PHP_SESSION_ACTIVE) {
    @session_destroy();
  }
  // Best-effort removal of the server-side session file without waiting on the lock.
  if ($sid !== '') {
    $savePath = session_save_path();
    if ($savePath !== '' && $savePath !== false && strcasecmp((string)ini_get('session.save_handler'), 'files') === 0) {
      @unlink(rtrim($savePath, '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    }
  }
  header('Location: login.php', true, 303);
  exit;
}
$back = isset($_SERVER['HTTP_REFERER']) && $_SERVER['HTTP_REFERER'] !== '' ? $_SERVER['HTTP_REFERER'] : 'mainpage.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Confirm Logout</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
  <style>
    body{margin:0;font-family:'Poppins',sans-serif;background:#111;color:#fff;min-height:100vh;display:flex;align-items:center;justify-content:center}
    .modal{background:#1b1816;border:1px solid rgba(255,255,255,.12);border-radius:14px;max-width:520px;width:92%;padding:20px;text-align:center;box-shadow:0 16px 40px rgba(0,0,0,.35);position:relative}
    .title{font-weight:800;font-size:1.2rem;margin:0 0 8px;color:#e74c3c}
    .text{font-size:.98rem;color:#ddd;margin:0 0 14px}
    .actions{display:flex;gap:10px;justify-content:center}
    .btn{padding:10px 16px;border-radius:10px;font-weight:700;text-decoration:none;display:inline-block}
    .btn-confirm{background:#23412e;color:#fff}
    .btn-cancel{background:#e5e7eb;color:#111}
    .btn:hover{transform:translateY(-1px)}
    .close-btn{position:absolute;top:10px;right:12px;width:32px;height:32px;border-radius:50%;background:#2a2623;color:#fff;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;font-size:18px}
    .close-btn:hover{filter:brightness(1.1)}
  </style>
</head>
<body>
  <div class="modal" role="dialog" aria-label="Logout confirmation">
    <a class="close-btn" href="<?php echo htmlspecialchars($back); ?>" aria-label="Close">&times;</a>
    <div class="title">Confirm Logout</div>
    <div class="text">Are you sure you want to log out?</div>
    <div class="actions">
      <a class="btn btn-confirm" href="logout.php?confirm=yes">Log Out</a>
      <a class="btn btn-cancel" href="<?php echo htmlspecialchars($back); ?>">Cancel</a>
    </div>
  </div>
</body>
</html>
