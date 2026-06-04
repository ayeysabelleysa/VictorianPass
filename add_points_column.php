<?php
require_once 'connect.php';

try {
    // Add points column to users table if it doesn't exist
    $checkColumn = $con->query("SHOW COLUMNS FROM users LIKE 'points'");
    if ($checkColumn && $checkColumn->num_rows == 0) {
        $con->query("ALTER TABLE users ADD COLUMN points INT DEFAULT 0");
        echo "Successfully added points column to users table with default value 0.\n";
    } else {
        echo "points column already exists in users table.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
