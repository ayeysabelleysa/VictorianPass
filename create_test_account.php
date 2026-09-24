<?php
require_once __DIR__ . '/connect.php';

$test_email = "test@victorianpass.com";
$test_password = password_hash("Test123!", PASSWORD_DEFAULT);
$test_first_name = "Test";
$test_last_name = "User";
$test_house_number = "101";
$test_points = 3000;
$test_user_type = 'resident';
$test_status = 'active';

// Ensure schema compatibility. Fresh / older deployments may be missing
// columns the rest of the app already expects (points, status, valid_id_path).
$cols = [];
$r = $con->query("SHOW COLUMNS FROM users");
if ($r) {
    while ($row = $r->fetch_assoc()) { $cols[$row['Field']] = $row['Type']; }
}

if (!isset($cols['points'])) {
    @$con->query("ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0");
    echo "  [ok] Added missing 'points' column\n";
    $cols['points'] = 'int';
}
if (!isset($cols['status'])) {
    @$con->query("ALTER TABLE users ADD COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'active'");
    echo "  [ok] Added missing 'status' column\n";
    $cols['status'] = 'enum';
}

// house_number is UNIQUE; pick the next free number if "101" is already taken
// so the INSERT never dies on a duplicate key in a populated deployment.
$house = $test_house_number;
if (!isset($cols['house_number']) || (isset($cols['house_number']) && $test_house_number !== null)) {
    $found = null;
    do {
        $qh = $con->prepare("SELECT id FROM users WHERE house_number = ? LIMIT 1");
        $qh->bind_param('s', $house);
        $qh->execute();
        $qh->store_result();
        $taken = $qh->num_rows > 0;
        $qh->close();
        if ($taken) {
            $num = (int)preg_replace('/[^0-9]/', '', $house);
            $house = ($num ? $num + 1 : 102);
        } else {
            $found = $house;
        }
    } while ($found === null);
    if ($found !== $test_house_number) {
        echo "  [note] house_number '$test_house_number' is taken; using '$found'\n";
    }
    $house = $found;
}

$check_stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$check_stmt->bind_param('s', $test_email);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    $test_user = $check_result->fetch_assoc();
    // Reset the password too, so login always succeeds with the documented
    // credentials no matter what hash or state the account was left in.
    $update_stmt = $con->prepare("UPDATE users SET password = ?, points = ?, status = ?, user_type = ?, first_name = ?, last_name = ?, house_number = ? WHERE id = ?");
    $update_stmt->bind_param("sississi", $test_password, $test_points, $test_status, $test_user_type, $test_first_name, $test_last_name, $house, $test_user['id']);
    $update_stmt->execute();
    echo "Test account updated successfully!\n";
    echo "Email: " . htmlspecialchars($test_email) . "\n";
    echo "Password: Test123!\n";
    echo "Points: " . htmlspecialchars($test_points) . "\n";
    echo "ID: " . $test_user['id'];
    $update_stmt->close();
} else {
    // Provide every required NOT NULL column so this works on any deployed schema.
    $insert_stmt = $con->prepare("INSERT INTO users (email, password, first_name, last_name, house_number, points, user_type, status, phone, sex, birthdate, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $phone = '09171234567';
    $sex = 'Male';
    $birthdate = '1995-05-15';
    $address = 'Unit 101, Victorian Heights, Brgy. San Isidro';
    $insert_stmt->bind_param("sssssissssss", $test_email, $test_password, $test_first_name, $test_last_name, $house, $test_points, $test_user_type, $test_status, $phone, $sex, $birthdate, $address);
    if ($insert_stmt->execute()) {
        echo "Test account created successfully!\n";
        echo "Email: " . htmlspecialchars($test_email) . "\n";
        echo "Password: Test123!\n";
        echo "Points: " . htmlspecialchars($test_points) . "\n";
        echo "ID: " . $con->insert_id;
    } else {
        echo "ERROR creating test account: " . $con->error . "\n";
    }
    $insert_stmt->close();
}

$check_stmt->close();
$con->close();
?>