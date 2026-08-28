<?php
// Set default timezone to Asia/Manila (UTC+8)
date_default_timezone_set('Asia/Manila');

// Check current file path to determine environment
// If the path starts with 'C:', we are on Windows (Local XAMPP)
// Otherwise, we assume Linux (Hostinger)
$isLocal = false;
if (stripos(__FILE__, 'C:') === 0 || stripos(__FILE__, 'c:') === 0) {
    $isLocal = true;
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
$con = new mysqli($host, $user, $pass, $db);

// Check connection
if ($con->connect_error) {
    die("Connection failed: " . $con->connect_error);
}

// Set MySQL session timezone to UTC+8 to match PHP
// This ensures TIMESTAMP columns are retrieved in Manila time
if ($con) {
    // Try to set timezone using offset (works even if timezone tables are missing)
    $con->query("SET time_zone = '+08:00'");
}
?>
