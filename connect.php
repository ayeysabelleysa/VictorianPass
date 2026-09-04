<?php
// Set default timezone to Asia/Manila (UTC+8)
date_default_timezone_set('Asia/Manila');

// Determine environment: Windows (local XAMPP) vs Linux (Hostinger)
$isLocal = false;
if (stripos(__FILE__, 'C:') === 0 || stripos(__FILE__, 'c:') === 0) {
    $isLocal = true;
}

// ---------------------------------------------------------------------------
// Production-safe error handling
// ---------------------------------------------------------------------------
// In production we must NOT display PHP errors (they can reveal DB credentials,
// file paths, and server internals). Instead we log them to a file so the exact
// failing line can be diagnosed, while users see a clean, safe message.
if (!$isLocal) {
    @ini_set('display_errors', '0');
    @ini_set('log_errors', '1');
    @ini_set('error_reporting', (string)(E_ALL & ~E_DEPRECATED & ~E_NOTICE));
    $errLog = __DIR__ . '/php-error.log';
    @ini_set('error_log', $errLog);

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) { return false; }
        @error_log("[PHP $severity] $message in $file on line $line");
        return true;
    });
    set_exception_handler(function ($e) {
        $f = $e->getFile(); $l = $e->getLine(); $msg = $e->getMessage();
        @error_log("[EXCEPTION] $msg in $f on line $l");
        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Service Unavailable</title>'
           . '<style>body{font-family:system-ui,sans-serif;background:#fafbfc;color:#222;display:flex;'
           . 'align-items:center;justify-content:center;min-height:100vh;margin:0;}'
           . '.box{max-width:520px;text-align:center;padding:32px;border:1px solid #e5e7eb;'
           . 'border-radius:16px;background:#fff;} h1{font-size:1.4rem;margin:0 0 10px;}'
           . 'p{color:#555;line-height:1.6;margin:0;}</style></head><body><div class="box">'
           . '<h1>Something went wrong on this page.</h1>'
           . '<p>The site hit an unexpected error and it has been logged. Please try again in a moment '
           . 'or contact the site administrator.</p></div></body></html>';
        exit;
    });
}

if ($isLocal) {
    // Local XAMPP Credentials
    $host = "127.0.0.1";
    $user = "root";
    $pass = "";
    $db   = "victorianpass_db";
} else {
    // Hostinger Production Credentials
    $host = 'localhost';
    $user = 'u785375633_VHEcoPoint_';
    $pass = 'Rionne0821@';
    $db   = 'u785375633_FLS_VHEcoPoint';
}

// Create connection
// Timeout values prevent the DB handshake from hanging indefinitely on a
// cold server start (a common cause of intermittent 504 before the DB is warm).
mysqli_report(MYSQLI_REPORT_OFF);
$con = @new mysqli($host, $user, $pass, $db, 3306, 10);
if ($con && !$con->connect_error) {
    // Keep individual queries from hanging; abort after 10s rather than spinning forever.
    $con->query("SET SESSION wait_timeout = 600");
    $con->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
    if (defined('MYSQLI_OPT_READ_TIMEOUT')) {
        @$con->options(MYSQLI_OPT_READ_TIMEOUT, 60);
    }
}

// Check connection
if ($con->connect_error) {
    // Log the real cause server-side but never echo DB details to the user.
    @error_log("[DB] Connection failed: " . $con->connect_error . " (host=$host db=$db)");
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Service Unavailable</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#fafbfc;color:#222;display:flex;'
       . 'align-items:center;justify-content:center;min-height:100vh;margin:0;}'
       . '.box{max-width:520px;text-align:center;padding:32px;border:1px solid #e5e7eb;'
       . 'border-radius:16px;background:#fff;} h1{font-size:1.4rem;margin:0 0 10px;}'
       . 'p{color:#555;line-height:1.6;margin:0;}</style></head><body><div class="box">'
       . '<h1>Service temporarily unavailable.</h1>'
       . '<p>We could not reach the database right now. Please try again in a moment.</p></div></body></html>';
    exit;
}

// Set MySQL session timezone to UTC+8 to match PHP
// This ensures TIMESTAMP columns are retrieved in Manila time
if ($con) {
    // Try to set timezone using offset (works even if timezone tables are missing)
    $con->query("SET time_zone = '+08:00'");
}

// ---------------------------------------------------------------------------
// File-based schema migration helpers (shared by all pages)
// These run schema checks ONCE per flag key, then skip on every subsequent
// request. This prevents SHOW TABLES/COLUMN overhead on every page load.
// ---------------------------------------------------------------------------
if (!function_exists('vpSchemaDone')) {
  function vpSchemaDone($con, $key) {
    $dir = __DIR__ . '/schema_flags';
    if (!is_dir($dir)) return false;
    $flag = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.done';
    return @file_exists($flag);
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
?>
