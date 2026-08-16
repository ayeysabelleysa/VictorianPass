<?php
require_once __DIR__ . '/session_bootstrap.php';
$confirmed = isset($_GET['confirm']) && $_GET['confirm'] === 'yes';
if ($confirmed) {
  require_once 'connect.php';
  $con->query("CREATE TABLE IF NOT EXISTS login_history (id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT NOT NULL, login_time DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, logout_time DATETIME NULL, INDEX idx_staff_id (staff_id)) ENGINE=InnoDB");
  $loginId = intval($_SESSION['login_history_id'] ?? 0);
  if ($loginId > 0) {
    $stmt = $con->prepare('UPDATE login_history SET logout_time = NOW() WHERE id = ? AND logout_time IS NULL');
    $stmt->bind_param('i', $loginId);
    $stmt->execute();
    $stmt->close();
  }
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
  header('Location: mainpage.php');
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
