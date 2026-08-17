<?php
require_once 'connect.php';

// Test account details
$test_email = "test@victorianpass.com";
$test_password_plain = "Test123!";
// Use the same hashing the site uses for resident passwords
$test_password = password_hash($test_password_plain, PASSWORD_BCRYPT);
$test_first_name = "Test";
$test_last_name = "User";
$test_house_number = "101";
$test_points = 3000;
$test_user_type = "resident";
$test_status = "active";

// Check if test account already exists
$check_stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check_stmt->bind_param("s", $test_email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    // Update existing test account: update ALL required fields, do not create duplicate
    $test_user = $check_result->fetch_assoc();
    $update_stmt = $con->prepare("UPDATE users SET `password` = ?, first_name = ?, last_name = ?, house_number = ?, points = ?, user_type = ?, status = ? WHERE id = ?");
    if ($update_stmt) {
        // types: s,s,s,s,i,s,s,i => "ssssissi"
        $update_stmt->bind_param("ssssissi", $test_password, $test_first_name, $test_last_name, $test_house_number, $test_points, $test_user_type, $test_status, $test_user['id']);
        if ($update_stmt->execute()) {
            echo "Test account updated successfully!\n";
            echo "Email: " . htmlspecialchars($test_email) . "\n";
            echo "Password: " . $test_password_plain . "\n";
            echo "Points: " . htmlspecialchars($test_points) . "\n";
            echo "ID: " . $test_user['id'];
        } else {
            echo "Failed to update test account: " . $update_stmt->error . "\n";
        }
        $update_stmt->close();
    } else {
        echo "Failed to prepare update statement: " . $con->error . "\n";
    }
} else {
    // Insert new test account
    $insert_stmt = $con->prepare("INSERT INTO users (email, password, first_name, last_name, house_number, points, user_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if ($insert_stmt) {
        $insert_stmt->bind_param("ssssiiss", $test_email, $test_password, $test_first_name, $test_last_name, $test_house_number, $test_points, $test_user_type, $test_status);
        if ($insert_stmt->execute()) {
            echo "Test account created successfully!\n";
            echo "Email: " . htmlspecialchars($test_email) . "\n";
            echo "Password: " . $test_password_plain . "\n";
            echo "Points: " . htmlspecialchars($test_points) . "\n";
            echo "ID: " . $con->insert_id;
        } else {
            echo "Failed to create test account: " . $insert_stmt->error . "\n";
        }
        $insert_stmt->close();
    } else {
        echo "Failed to prepare insert statement: " . $con->error . "\n";
    }
}

$check_stmt->close();
$con->close();
?>