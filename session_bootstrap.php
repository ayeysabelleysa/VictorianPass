<?php
if (session_status() !== PHP_SESSION_NONE) {
    return;
}

if (!defined('VP_AUTH_KEY')) {
    define('VP_AUTH_KEY', hash('sha256', __DIR__ . '::VictorianPass::auth::v2'));
}
if (!function_exists('vpBase64UrlEncode')) {
  function vpBase64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
  }
}
if (!function_exists('vpBase64UrlDecode')) {
  function vpBase64UrlDecode(string $data): string {
    return (string)base64_decode(strtr($data, '-_', '+/'));
  }
}
if (!function_exists('vpAuthSetCookie')) {
  function vpAuthSetCookie(int $userId, string $userType): void {
    $payload = vpBase64UrlEncode(json_encode(['uid' => $userId, 'ut' => $userType, 'exp' => time() + 604800], JSON_UNESCAPED_SLASHES));
    $sig = hash_hmac('sha256', $payload, VP_AUTH_KEY);
    @setcookie('vp_auth', $payload . '.' . $sig, [
      'expires' => time() + 604800,
      'path' => '/',
      'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}
if (!function_exists('vpAuthClearCookie')) {
  function vpAuthClearCookie(): void {
    @setcookie('vp_auth', '', [
      'expires' => time() - 3600,
      'path' => '/',
      'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}
if (!function_exists('vpAuthReadCookie')) {
  function vpAuthReadCookie(): array {
    if (empty($_COOKIE['vp_auth']) || !defined('VP_AUTH_KEY')) {
      return [null, null];
    }
    $parts = explode('.', $_COOKIE['vp_auth'], 2);
    if (count($parts) !== 2) { return [null, null]; }
    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, VP_AUTH_KEY);
    if (!is_string($sig) || !hash_equals($expected, $sig)) { return [null, null]; }
    $data = json_decode(vpBase64UrlDecode($payload), true);
    if (!is_array($data) || empty($data['uid']) || empty($data['ut'])) { return [null, null]; }
    $exp = isset($data['exp']) ? intval($data['exp']) : 0;
    if ($exp < time()) { return [null, null]; }
    $userType = (string)$data['ut'];
    if (!in_array($userType, ['resident', 'visitor'], true)) { return [null, null]; }
    return [intval($data['uid']), $userType];
  }
}
if (!function_exists('vpCsrfGetToken')) {
  function vpCsrfGetToken(): string {
    $token = '';
    if (!empty($_COOKIE['vp_csrf'])) {
      $parts = explode('.', $_COOKIE['vp_csrf'], 2);
      if (count($parts) === 2) {
        [$payload, $sig] = $parts;
        $expected = hash_hmac('sha256', $payload, VP_AUTH_KEY);
        if (is_string($sig) && hash_equals($expected, $sig)) {
          $data = json_decode(vpBase64UrlDecode($payload), true);
          if (is_array($data) && !empty($data['csrf']) && intval($data['exp'] ?? 0) > time()) {
            $token = (string)$data['csrf'];
          }
        }
      }
    }
    if ($token === '') {
      $token = bin2hex(random_bytes(32));
      $payload = vpBase64UrlEncode(json_encode(['csrf' => $token, 'exp' => time() + 7200], JSON_UNESCAPED_SLASHES));
      $sig = hash_hmac('sha256', $payload, VP_AUTH_KEY);
      @setcookie('vp_csrf', $payload . '.' . $sig, [
        'expires' => time() + 7200,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
      ]);
    }
    return $token;
  }
}
if (!function_exists('vpCsrfVerify')) {
  function vpCsrfVerify(string $posted): bool {
    if ($posted === '') { return false; }
    if (empty($_COOKIE['vp_csrf']) || !defined('VP_AUTH_KEY')) { return false; }
    $parts = explode('.', $_COOKIE['vp_csrf'], 2);
    if (count($parts) !== 2) { return false; }
    [$payload, $sig] = $parts;
    $expected = hash_hmac('sha256', $payload, VP_AUTH_KEY);
    if (!is_string($sig) || !hash_equals($expected, $sig)) { return false; }
    $data = json_decode(vpBase64UrlDecode($payload), true);
    if (!is_array($data) || empty($data['csrf'])) { return false; }
    return hash_equals((string)$data['csrf'], $posted);
  }
}

$sessionRoot = __DIR__;
while ($sessionRoot !== dirname($sessionRoot) && !file_exists($sessionRoot . DIRECTORY_SEPARATOR . 'connect.php')) {
    $sessionRoot = dirname($sessionRoot);
}

$sessionDir = $sessionRoot . DIRECTORY_SEPARATOR . 'sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0777, true);
}

if (is_dir($sessionDir) && is_writable($sessionDir)) {
    @session_save_path($sessionDir);
    @ini_set('session.save_path', $sessionDir);
} else {
    // Fall back to the system temp dir if the app sessions/ folder is unusable
    $sysTmp = sys_get_temp_dir();
    if ($sysTmp !== '' && is_dir($sysTmp) && is_writable($sysTmp)) {
        @session_save_path($sysTmp);
        @ini_set('session.save_path', $sysTmp);
    }
}

@ini_set('session.use_strict_mode', '1');
@ini_set('session.use_cookies', '1');
@ini_set('session.use_only_cookies', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');

$sessionCookieParams = session_get_cookie_params();
$sessionCookieParams['secure'] = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off');
$sessionCookieParams['httponly'] = true;
$sessionCookieParams['samesite'] = 'Lax';
session_set_cookie_params($sessionCookieParams);

// Cookie-auth mode: skip the session lock entirely for read-only page loads.
// Reads user_id/user_type from a signed vp_auth cookie instead. Prevents 502/504
// gateway timeouts caused by session file lock contention under concurrent AJAX load.
$vpCookieAuthOk = false;
if (defined('VP_SESSION_COOKIE_AUTH') && VP_SESSION_COOKIE_AUTH === true) {
  [$ckUid, $ckUt] = vpAuthReadCookie();
  if ($ckUid !== null && $ckUt !== null) {
    $_SESSION['user_id'] = $ckUid;
    $_SESSION['user_type'] = $ckUt;
    $vpCookieAuthOk = true;
  }
}

if (!$vpCookieAuthOk) {
  if (defined('VP_SESSION_READONLY') && VP_SESSION_READONLY === true) {
    session_start(['read_and_close' => true]);
  } else {
    session_start();
  }
}

// Authenticated pages must never be restored from browser history after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
