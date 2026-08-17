<?php
require_once __DIR__ . '/connect.php';

$test_email = 'test@victorianpass.com';
$test_plain_password = 'Test123!';
$test_password_hash = password_hash($test_plain_password, PASSWORD_DEFAULT);
$test_first_name = 'Test';
$test_last_name = 'User';
$test_phone = '09123456789';
$test_sex = 'Male';
$test_birthdate = '1990-01-01';
$test_house_number = '101';
$test_address = '101 Test Street';
$test_points = 3000;
$test_user_type = 'resident';
$test_status = 'active';

$check_stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check_stmt->bind_param('s', $test_email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    $test_user = $check_result->fetch_assoc();

    $update_stmt = $con->prepare(
        'UPDATE users
         SET password = ?,
             first_name = ?,
             last_name = ?,
             phone = ?,
             sex = ?,
             birthdate = ?,
             house_number = ?,
             address = ?,
             points = ?,
             user_type = ?,
             status = ?
         WHERE id = ?'
    );

    $update_stmt->bind_param(
        'ssssssssissi',
        $test_password_hash,
        $test_first_name,
        $test_last_name,
        $test_phone,
        $test_sex,
        $test_birthdate,
        $test_house_number,
        $test_address,
        $test_points,
        $test_user_type,
        $test_status,
        $test_user['id']
    );

    $update_stmt->execute();
    echo "Test account repaired successfully!\n";
    echo "Email: " . htmlspecialchars($test_email) . "\n";
    echo "Password: " . htmlspecialchars($test_plain_password) . "\n";
    echo "Points: " . htmlspecialchars($test_points) . "\n";
    echo "User Type: " . htmlspecialchars($test_user_type) . "\n";
    echo "Status: " . htmlspecialchars($test_status) . "\n";
    echo "ID: " . (int)$test_user['id'];
    $update_stmt->close();
} else {
    $insert_stmt = $con->prepare(
        'INSERT INTO users (first_name, last_name, phone, email, password, sex, birthdate, house_number, address, user_type, status, points)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $insert_stmt->bind_param(
        'sssssssssssi',
        $test_first_name,
        $test_last_name,
        $test_phone,
        $test_email,
        $test_password_hash,
        $test_sex,
        $test_birthdate,
        $test_house_number,
        $test_address,
        $test_user_type,
        $test_status,
        $test_points
    );

    $insert_stmt->execute();
    echo "Test account created successfully!\n";
    echo "Email: " . htmlspecialchars($test_email) . "\n";
    echo "Password: " . htmlspecialchars($test_plain_password) . "\n";
    echo "Points: " . htmlspecialchars($test_points) . "\n";
    echo "User Type: " . htmlspecialchars($test_user_type) . "\n";
    echo "Status: " . htmlspecialchars($test_status) . "\n";
    echo "ID: " . (int)$con->insert_id;
    $insert_stmt->close();
}

$check_stmt->close();
$con->close();
?>