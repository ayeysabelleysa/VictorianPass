<?php
if (session_status() !== PHP_SESSION_NONE) {
    return;
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

session_start();

// Authenticated pages must never be restored from browser history after logout.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
