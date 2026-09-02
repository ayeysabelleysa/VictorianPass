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
$con = @new mysqli($host, $user, $pass, $db);

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
?>
