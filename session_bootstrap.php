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
}

@ini_set('session.use_strict_mode', '1');
@ini_set('session.cookie_httponly', '1');
@ini_set('session.cookie_samesite', 'Lax');

session_start();
