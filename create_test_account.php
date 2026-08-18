<?php
require_once __DIR__ . '/connect.php';

// Test account details
$test_email = "test@victorianpass.com";
$test_password = password_hash("Test123!", PASSWORD_DEFAULT);
$test_first_name = "Test";
$test_last_name = "User";
$test_house_number = "101";
$test_points = 3000;
$test_user_type = 'resident';
$test_status = 'active';

$check_stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check_stmt->bind_param('s', $test_email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    // Update existing test account
    $test_user = $check_result->fetch_assoc();
    $update_stmt = $con->prepare("UPDATE users SET points = ?, status = ? WHERE id = ?");
    $update_stmt->bind_param("isi", $test_points, $test_status, $test_user['id']);
    $update_stmt->execute();
    echo "Test account updated successfully!\n";
    echo "Email: " . htmlspecialchars($test_email) . "\n";
    echo "Password: Test123!\n";
    echo "Points: " . htmlspecialchars($test_points) . "\n";
    echo "ID: " . $test_user['id'];
    $update_stmt->close();
} else {
    // Insert new test account
    $insert_stmt = $con->prepare("INSERT INTO users (email, password, first_name, last_name, house_number, points, user_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $insert_stmt->bind_param("ssssiiss", $test_email, $test_password, $test_first_name, $test_last_name, $test_house_number, $test_points, $test_user_type, $test_status);
    $insert_stmt->execute();
    echo "Test account created successfully!\n";
    echo "Email: " . htmlspecialchars($test_email) . "\n";
    echo "Password: Test123!\n";
    echo "Points: " . htmlspecialchars($test_points) . "\n";
    echo "ID: " . $con->insert_id;
    $insert_stmt->close();
}

$check_stmt->close();
$con->close();
?>