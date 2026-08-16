<?php
require_once __DIR__ . '/session_bootstrap.php';
include 'connect.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$p1 = __DIR__ . '/PHPMailer-master/src/Exception.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/Exception.php';
require file_exists($p1) ? $p1 : $p2;
$p1 = __DIR__ . '/PHPMailer-master/src/PHPMailer.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require file_exists($p1) ? $p1 : $p2;
$p1 = __DIR__ . '/PHPMailer-master/src/SMTP.php';
$p2 = __DIR__ . '/PHPMailer-master/PHPMailer-master/src/SMTP.php';
require file_exists($p1) ? $p1 : $p2;

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$msg = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tokenPosted = isset($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if (!is_string($tokenPosted) || !hash_equals($_SESSION['csrf_token'] ?? '', $tokenPosted)) {
        $msg = 'Invalid request.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Enter a valid email.';
        } else {
            $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name FROM users WHERE LOWER(email) = ? LIMIT 1");
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $res = $stmt->get_result();
            if (!$res || $res->num_rows === 0) {
                $msg = 'Account not found.';
            } else {
                $u = $res->fetch_assoc();
                $uid = intval($u['id']);
                $full_name = trim(($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                $tempPass = bin2hex(random_bytes(4));
                $hash = password_hash($tempPass, PASSWORD_BCRYPT);
                $upd = $con->prepare("UPDATE users SET password = ? WHERE id = ?");
                $upd->bind_param('si', $hash, $uid);
                $upd->execute();
                $upd->close();

                $mail = new PHPMailer(true);
                try {
                    $mail->isSMTP();
                    $mail->Host = 'smtp.gmail.com';
                    $mail->SMTPAuth = true;
                    $mail->Username = 'victorianpass8@gmail.com';
                    $mail->Password = 'zdsl qpfu pxdf tzve';
                    $mail->SMTPSecure = 'tls';
                    $mail->Port = 587;
                    $mail->setFrom('victorianpass8@gmail.com', 'VictorianPass');
                    $mail->addAddress($email, $full_name !== '' ? $full_name : null);
                    $mail->isHTML(true);
                    $mail->Subject = 'VictorianPass Password Reset';
                    $mail->Body = "<h2>Password Reset</h2>
<p><strong>Name:</strong> ".htmlspecialchars($full_name !== '' ? $full_name : 'User')."</p>
<p>Your temporary password is: <span style=\"font-family:monospace;background:#f0f0f0;padding:6px 10px;border-radius:6px\">".$tempPass."</span></p>
<p>Use this password to log in. After logging in, go to your profile and change your password.</p>
<p>If you did not request this, please ignore this email.</p>";
                    $mail->AltBody = "Password Reset\nName: ".($full_name !== '' ? $full_name : 'User')."\nTemporary Password: ".$tempPass."\nUse this password to log in and then change it.\nIf you did not request this, ignore this email.";
                    $mail->send();
                    $ok = true;
                    $msg = 'A temporary password has been sent to your email.';
                } catch (Exception $e) {
                    $msg = 'Failed to send email. Try again later.';
                }
            }
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Forgot Password</title>
  <link rel="icon" type="image/png" href="images/logo.svg">
  <?php if (!empty($ok)) { echo '<meta http-equiv="refresh" content="10;url=login.php">'; } ?>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    *{font-family:'Poppins',sans-serif}
    *,*:before,*:after{box-sizing:border-box}
    body{margin:0;background:#fafbfc;color:#111827;min-height:100vh;display:flex;align-items:center;justify-content:center;animation:fadeIn .6s ease-in-out}
    .wrap{max-width:520px;margin:0 auto;padding:0 16px}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 4px 16px rgba(15,23,42,0.08)}
    .title{font-weight:700;font-size:1.5rem;margin:0 0 6px;color:#111827}
    .meta{color:#4b5563;font-size:.95rem;margin-bottom:8px}
    .input{width:100%;padding:12px 14px;border:1px solid #d1d5db;border-radius:8px;font-size:.95rem}
    .input:focus{outline:none;border-color:#23412e;box-shadow:0 0 0 3px rgba(35,65,46,0.15)}
    .input.error{border-color:#fecaca;box-shadow:0 0 0 3px rgba(254,202,202,0.35)}
    .btn{background:#23412e;color:#fff;border:none;padding:12px 20px;border-radius:8px;cursor:pointer;font-weight:600;transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;font-size:.95rem}
    .btn:hover{transform:translateY(-2px);box-shadow:0 4px 12px rgba(35,65,46,0.4);opacity:.95}
    .btn[disabled]{opacity:.6;cursor:not-allowed;transform:none;box-shadow:none}
    .back-arrow{width:42px;height:42px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;text-decoration:none;background:#d4a017;box-shadow:0 6px 14px rgba(212,160,23,0.35);transition:transform .2s ease,box-shadow .2s ease,opacity .2s ease;margin-bottom:10px}
    .back-arrow i{font-size:18px;color:#ffffff}
    .back-arrow:hover{transform:translateY(-2px);box-shadow:0 6px 14px rgba(212,160,23,0.45);opacity:.95}
    .notice{padding:12px 14px;border-radius:8px;margin-bottom:10px}
    .ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}
    .err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
    .field-msg{color:#991b1b;font-size:.85rem;margin-top:8px}
    @keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
    @media (max-width:480px){
      .card{padding:18px;border-radius:12px}
      .title{font-size:1.35rem}
      .meta{font-size:.9rem}
      .btn{width:100%}
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <a href="login.php" class="back-arrow" aria-label="Back to login"><i class="fa-solid fa-arrow-left"></i></a>
      <h1 class="title">Forgot Password</h1>
      <?php if ($msg !== '') { ?>
        <div class="notice <?php echo $ok ? 'ok' : 'err'; ?>"><?php echo htmlspecialchars($msg); ?></div>
      <?php } ?>
      <form method="post" action="forgot_password.php" id="fpForm">
        <div class="meta">Enter your account email to receive a temporary password.</div>
        <input type="email" name="email" id="email" class="input" placeholder="you@example.com" required autocomplete="email" inputmode="email">
        <div id="emailError" class="field-msg" style="display:none"></div>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <div style="margin-top:12px">
          <button type="submit" class="btn" id="submitBtn">Send Temporary Password</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    (function(){
      var form = document.getElementById('fpForm');
      var email = document.getElementById('email');
      var btn = document.getElementById('submitBtn');
      var emailErr = document.getElementById('emailError');
      function isValidEmail(v){
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
      }
      function updateEmailState(){
        var v = (email.value || '').trim();
        var ok = v !== '' && isValidEmail(v);
        if (!ok){
          email.classList.add('error');
          emailErr.style.display = 'block';
          emailErr.textContent = 'Enter a valid email address.';
        } else {
          email.classList.remove('error');
          emailErr.style.display = 'none';
          emailErr.textContent = '';
        }
        btn.disabled = !ok;
      }
      if (email){
        email.addEventListener('input', updateEmailState);
        email.addEventListener('blur', updateEmailState);
        updateEmailState();
      }
      if (form && btn){
        form.addEventListener('submit', function(){
          btn.disabled = true;
          btn.textContent = 'Sending...';
        });
      }
    })();
  </script>
  <?php if (!empty($ok)) { echo '<script>setTimeout(function(){window.location.href="login.php"},10000)</script>'; } ?>
</body>
</html>
