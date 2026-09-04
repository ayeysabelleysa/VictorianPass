<?php
require_once __DIR__ . '/connect.php';

$con->set_charset('utf8mb4');
$result = $con->query('SELECT 1 AS connection_ok');

if ($result && $result->fetch_assoc()['connection_ok'] == 1) {
    echo 'Database connection successful.';
} else {
    http_response_code(500);
    echo 'Database connection failed.';
}
