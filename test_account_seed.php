<?php

if (!function_exists('vpEnsureTestAccount')) {
    function vpEnsureTestAccount(\mysqli $con): string {
        $test_email = "test@victorianpass.com";
        $test_password = password_hash("Test123!", PASSWORD_DEFAULT);
        $test_first_name = "Test";
        $test_last_name = "User";
        $test_house_number = "101";
        $test_points = 3000;
        $test_user_type = 'resident';
        $test_status = 'active';

        $cols = [];
        $r = $con->query("SHOW COLUMNS FROM users");
        if ($r) {
            while ($row = $r->fetch_assoc()) { $cols[$row['Field']] = $row['Type']; }
        }

        if (!isset($cols['points'])) {
            @$con->query("ALTER TABLE users ADD COLUMN points INT NOT NULL DEFAULT 0");
            $cols['points'] = 'int';
        }
        if (!isset($cols['status'])) {
            @$con->query("ALTER TABLE users ADD COLUMN status ENUM('pending','active','denied','disabled') NOT NULL DEFAULT 'active'");
            $cols['status'] = 'enum';
        }

        $house = $test_house_number;
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
        $house = $found;

        $message = '';
        $check_stmt = $con->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $check_stmt->bind_param('s', $test_email);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();

        if ($check_result->num_rows > 0) {
            $test_user = $check_result->fetch_assoc();
            $userId = intval($test_user['id']);
            $update_stmt = $con->prepare("UPDATE users SET password = ?, points = ?, status = ?, user_type = ?, first_name = ?, last_name = ?, house_number = ? WHERE id = ?");
            $update_stmt->bind_param("sississi", $test_password, $test_points, $test_status, $test_user_type, $test_first_name, $test_last_name, $house, $test_user['id']);
            $update_stmt->execute();
            $message = "Test account updated successfully!";
            $update_stmt->close();
        } else {
            $insert_stmt = $con->prepare("INSERT INTO users (email, password, first_name, last_name, house_number, points, user_type, status, phone, sex, birthdate, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $phone = '09171234567';
            $sex = 'Male';
            $birthdate = '1995-05-15';
            $address = 'Unit 101, Victorian Heights, Brgy. San Isidro';
            $insert_stmt->bind_param("sssssissssss", $test_email, $test_password, $test_first_name, $test_last_name, $house, $test_points, $test_user_type, $test_status, $phone, $sex, $birthdate, $address);
            if ($insert_stmt->execute()) {
                $message = "Test account created successfully!";
            } else {
                $message = "ERROR creating test account: " . $con->error;
            }
            $userId = $con->insert_id;
            $insert_stmt->close();
        }

        $ptR = $con->query("SHOW TABLES LIKE 'point_transactions'");
        $ptExists = $ptR && $ptR->num_rows > 0;
        if ($ptR) { $ptR->free(); }
        if (!$ptExists) {
            @$con->query("CREATE TABLE IF NOT EXISTS point_transactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                transaction_type ENUM('earn','redeem','adjustment') NOT NULL DEFAULT 'earn',
                amount INT NOT NULL DEFAULT 0,
                description VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_point_transactions_user_id (user_id),
                INDEX idx_point_transactions_type (transaction_type),
                INDEX idx_point_transactions_created (created_at)
            ) ENGINE=InnoDB");
        }

        $seedTag = 'VHEcoPoint test balance (seed)';
        $del = $con->prepare("DELETE FROM point_transactions WHERE user_id = ? AND description = ?");
        if ($del) {
            $del->bind_param('is', $userId, $seedTag);
            @$del->execute();
            $del->close();
        }

        $balance = 0;
        $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
        if (!$stmt) {
            $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, NULL AS ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
        }
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $desc = (string)($row['description'] ?? '');
                $isEco = (!empty($row['ecopoint_session_id']) && intval($row['ecopoint_session_id']) > 0)
                      || (stripos($desc, 'VHEcoPoint') !== false)
                      || (stripos($desc, 'recycling') !== false)
                      || (stripos($desc, 'Redeemed points') !== false)
                      || (stripos($desc, 'redeem') !== false && !empty($row['reservation_ref_code']));
                if (!$isEco) { continue; }
                $txType = strtolower(trim((string)($row['transaction_type'] ?? 'earn')));
                $amt = intval($row['amount'] ?? 0);
                if ($txType === 'earn') { $balance += $amt; }
                elseif ($txType === 'redeem') { $balance -= $amt; }
                elseif ($txType === 'adjustment') { $balance += $amt; }
            }
            $stmt->close();
        }
        $delta = 3000 - $balance;
        if ($delta > 0) {
            $ins = $con->prepare("INSERT INTO point_transactions (user_id, transaction_type, amount, description) VALUES (?, 'earn', ?, ?)");
            if ($ins) {
                $ins->bind_param('iis', $userId, $delta, $seedTag);
                @$ins->execute();
                $ins->close();
            }
        } elseif ($delta < 0) {
            $ins = $con->prepare("INSERT INTO point_transactions (user_id, transaction_type, amount, description) VALUES (?, 'redeem', ?, ?)");
            if ($ins) {
                $redeemAmt = -$delta;
                $ins->bind_param('iis', $userId, $redeemAmt, $seedTag);
                @$ins->execute();
                $ins->close();
            }
        }

        $check_stmt->close();
        return $message;
    }
}