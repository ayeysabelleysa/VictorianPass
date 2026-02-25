<?php
session_start();
include 'connect.php';

// Ensure scanned_at columns exist in relevant tables (Self-healing schema)
function ensureScannedAtColumns($con) {
    $tables = ['guest_forms', 'reservations', 'resident_reservations'];
    foreach ($tables as $tbl) {
        $res = $con->query("SHOW COLUMNS FROM $tbl LIKE 'scanned_at'");
        if ($res && $res->num_rows === 0) {
            $con->query("ALTER TABLE $tbl ADD COLUMN scanned_at DATETIME NULL");
        }
    }
}
ensureScannedAtColumns($con);
if ($con instanceof mysqli) {
    $con->query("CREATE TABLE IF NOT EXISTS entry_scans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ref_code VARCHAR(50) NOT NULL,
        scanned_by_guard_id INT NULL,
        scanned_by_name VARCHAR(150) NULL,
        subject_name VARCHAR(150) NULL,
        entry_type VARCHAR(50) NULL,
        status VARCHAR(50) NULL,
        start_date DATE NULL,
        end_date DATE NULL,
        scanned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ref_code (ref_code),
        INDEX idx_guard (scanned_by_guard_id),
        INDEX idx_scanned_at (scanned_at)
    ) ENGINE=InnoDB");
}

function calculateAgeYears($birthRaw) {
    if (empty($birthRaw)) return null;
    try {
        $dob = new DateTime($birthRaw);
        $today = new DateTime('today');
        if ($dob > $today) return null;
        return $dob->diff($today)->y;
    } catch (Exception $e) {
        return null;
    }
}

function requiresGuardianBlock($birthRaw, $isAmenity) {
    if (!$isAmenity) return false;
    $age = calculateAgeYears($birthRaw);
    return ($age !== null && $age < 16);
}

$code = isset($_GET['code']) ? trim($_GET['code']) : '';
$error = '';
if ($code === '') { $error = 'Status code is required.'; }

$data = null;
$showConfirmPopup = false;
$today = date('Y-m-d');
$now = date('Y-m-d H:i:s');

// Authorization Check
// Strict check: Only 'guard' role can see the confirm button. Admins/Residents/Visitors cannot.
$isAuthorizedScanner = (isset($_SESSION['role']) && $_SESSION['role'] === 'guard');

// URL Construction Helpers
$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/\\');
$verificationLink = sprintf('%s://%s%s/qr_view.php?code=%s', $scheme, $host, $basePath, urlencode($code));

// -------------------------------------------------------------------------
// POST Action: Mark as Scanned
// -------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'confirm_entry' && !empty($_POST['ref_code']) && !empty($_POST['source_table']) && !empty($_POST['source_id'])) {
    if (!$isAuthorizedScanner) {
        http_response_code(403);
        exit;
    }

    $tbl = $_POST['source_table'];
    $sid = intval($_POST['source_id']);
    $ref = $_POST['ref_code'];

    $blocked = false;
    if ($con instanceof mysqli) {
        if ($tbl === 'guest_forms') {
            $stmt = $con->prepare("SELECT visitor_birthdate, amenity, wants_amenity FROM guest_forms WHERE id = ? AND ref_code = ? LIMIT 1");
            $stmt->bind_param('is', $sid, $ref);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $birthRaw = $row['visitor_birthdate'] ?? null;
                $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
                if (requiresGuardianBlock($birthRaw, $isAmenity)) { $blocked = true; }
            }
            $stmt->close();
        } elseif ($tbl === 'reservations') {
            $stmt = $con->prepare("SELECT r.amenity, u.birthdate AS user_birthdate, e.birthdate AS ep_birthdate FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN entry_passes e ON r.entry_pass_id = e.id WHERE r.id = ? AND r.ref_code = ? LIMIT 1");
            $stmt->bind_param('is', $sid, $ref);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $birthRaw = !empty($row['ep_birthdate']) ? $row['ep_birthdate'] : ($row['user_birthdate'] ?? null);
                if (requiresGuardianBlock($birthRaw, true)) { $blocked = true; }
            }
            $stmt->close();
        } elseif ($tbl === 'resident_reservations') {
            $stmt = $con->prepare("SELECT rr.amenity, u.birthdate AS user_birthdate FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.id = ? AND rr.ref_code = ? LIMIT 1");
            $stmt->bind_param('is', $sid, $ref);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $birthRaw = $row['user_birthdate'] ?? null;
                if (requiresGuardianBlock($birthRaw, true)) { $blocked = true; }
            }
            $stmt->close();
        }
    }

    if ($blocked) {
        $error = 'Guardian required: Approved for entry once accompanied by a guardian for amenity reservations.';
    } elseif (in_array($tbl, ['guest_forms', 'reservations', 'resident_reservations'])) {
        if ($tbl === 'guest_forms') {
            $upStmt = $con->prepare("UPDATE guest_forms SET approval_status='permission_granted', scanned_at = NOW(), updated_at = NOW() WHERE id = ? AND ref_code = ? AND (approval_status IS NULL OR approval_status NOT IN ('permission_granted','cancelled','denied','expired','moved_to_history','deleted'))");
            $upStmt->bind_param('is', $sid, $ref);
            $upStmt->execute();
            $upStmt->close();
        } elseif ($tbl === 'reservations') {
            $upStmt = $con->prepare("UPDATE reservations SET approval_status='permission_granted', status='permission_granted', scanned_at = NOW(), updated_at = NOW() WHERE id = ? AND ref_code = ? AND (status IS NULL OR status NOT IN ('permission_granted','cancelled','denied','expired','moved_to_history','deleted'))");
            $upStmt->bind_param('is', $sid, $ref);
            $upStmt->execute();
            $upStmt->close();
        } elseif ($tbl === 'resident_reservations') {
            $upStmt = $con->prepare("UPDATE resident_reservations SET approval_status='permission_granted', scanned_at = NOW(), updated_at = NOW() WHERE id = ? AND ref_code = ? AND (approval_status IS NULL OR approval_status NOT IN ('permission_granted','cancelled','denied','expired','moved_to_history','deleted'))");
            $upStmt->bind_param('is', $sid, $ref);
            $upStmt->execute();
            $upStmt->close();
        }
        if ($con instanceof mysqli) {
            $gid = isset($_SESSION['staff_id']) ? intval($_SESSION['staff_id']) : null;
            $gname = isset($_SESSION['guard_surname']) ? trim($_SESSION['guard_surname']) : '';
            if ($gname === '' && isset($_SESSION['email'])) {
                $local = explode('@', $_SESSION['email'])[0] ?? '';
                $s = $local;
                if (strpos($local, '_') !== false) { $parts = explode('_', $local); $s = end($parts); }
                if (substr($s, -3) === 'gar') { $s = substr($s, 0, -3); }
                $s = preg_replace('/[^a-zA-Z]/', '', $s);
                $gname = strlen($s) ? ucfirst(strtolower($s)) : 'Guard';
            }
            if ($gname === '') { $gname = 'Guard'; }
            $subject = null;
            $etype = null;
            $sd = null;
            $ed = null;
            if ($tbl === 'guest_forms') {
                $stmtLog = $con->prepare("SELECT visitor_first_name, visitor_middle_name, visitor_last_name, visit_date, start_date, end_date FROM guest_forms WHERE id = ? AND ref_code = ? LIMIT 1");
                $stmtLog->bind_param('is', $sid, $ref);
                $stmtLog->execute();
                $resLog = $stmtLog->get_result();
                if ($resLog && ($row = $resLog->fetch_assoc())) {
                    $subject = trim(($row['visitor_first_name'] ?? '') . ' ' . ($row['visitor_middle_name'] ?? '') . ' ' . ($row['visitor_last_name'] ?? ''));
                    if ($subject === '') { $subject = 'Guest'; }
                    $etype = 'Guest Entry';
                    $sd = $row['visit_date'] ?? ($row['start_date'] ?? null);
                    $ed = $row['end_date'] ?? $sd;
                }
                $stmtLog->close();
            } elseif ($tbl === 'reservations') {
                $stmtLog = $con->prepare("SELECT r.start_date, r.end_date, r.booking_for, r.account_type, r.guest_id, r.guest_ref_code, r.entry_pass_id, u.first_name, u.middle_name, u.last_name, e.full_name FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN entry_passes e ON r.entry_pass_id = e.id WHERE r.id = ? AND r.ref_code = ? LIMIT 1");
                $stmtLog->bind_param('is', $sid, $ref);
                $stmtLog->execute();
                $resLog = $stmtLog->get_result();
                if ($resLog && ($row = $resLog->fetch_assoc())) {
                    $full = trim($row['full_name'] ?? '');
                    if ($full === '') { $full = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? '')); }
                    $bookingFor = strtolower(trim($row['booking_for'] ?? ''));
                    $accountType = strtolower(trim($row['account_type'] ?? ''));
                    $hasGuestRef = !empty($row['guest_ref_code']) || !empty($row['guest_id']);
                    $isVisitor = ($bookingFor === 'guest') || ($accountType === 'visitor') || $hasGuestRef || !empty($row['entry_pass_id']);
                    $etype = $isVisitor ? 'Visitor Reservation' : 'Resident Reservation';
                    $subject = $full !== '' ? $full : ($isVisitor ? 'Visitor' : 'Resident');
                    $sd = $row['start_date'] ?? null;
                    $ed = $row['end_date'] ?? $sd;
                }
                $stmtLog->close();
            } elseif ($tbl === 'resident_reservations') {
                $stmtLog = $con->prepare("SELECT rr.start_date, rr.end_date, u.first_name, u.middle_name, u.last_name FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.id = ? AND rr.ref_code = ? LIMIT 1");
                $stmtLog->bind_param('is', $sid, $ref);
                $stmtLog->execute();
                $resLog = $stmtLog->get_result();
                if ($resLog && ($row = $resLog->fetch_assoc())) {
                    $subject = trim(($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($subject === '') { $subject = 'Resident'; }
                    $etype = 'Resident Reservation';
                    $sd = $row['start_date'] ?? null;
                    $ed = $row['end_date'] ?? $sd;
                }
                $stmtLog->close();
            }
            $skipInsert = false;
            $chk = $con->prepare("SELECT status FROM entry_scans WHERE ref_code = ? AND DATE(scanned_at) = CURDATE() ORDER BY scanned_at DESC LIMIT 1");
            if ($chk) {
                $chk->bind_param('s', $ref);
                $chk->execute();
                $cres = $chk->get_result();
                if ($cres && ($row = $cres->fetch_assoc())) {
                    $st = strtolower(trim($row['status'] ?? ''));
                    if ($st !== '' && (strpos($st, 'permission') !== false || strpos($st, 'granted') !== false || strpos($st, 'access') !== false)) {
                        $skipInsert = true;
                    }
                }
                $chk->close();
            }
            if (!$skipInsert) {
                $stmtLog = $con->prepare("INSERT INTO entry_scans (ref_code, scanned_by_guard_id, scanned_by_name, subject_name, entry_type, status, start_date, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stat = 'permission_granted';
                $stmtLog->bind_param('sissssss', $ref, $gid, $gname, $subject, $etype, $stat, $sd, $ed);
                @$stmtLog->execute();
                @$stmtLog->close();
            }
        }
        $_SESSION['guard_confirmed_ref'] = $ref;
        $_SESSION['guard_confirmed_time'] = time();
        header("Location: guard.php");
        exit;
    }
}

// -------------------------------------------------------------------------
// Data Fetching & Logic
// -------------------------------------------------------------------------
if (empty($error)) {

    // 1. Check GUEST FORMS
    if (!$data) {
        $stmtGF = $con->prepare("SELECT gf.*, u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name FROM guest_forms gf LEFT JOIN users u ON gf.resident_user_id = u.id WHERE gf.ref_code = ?");
        $stmtGF->bind_param('s', $code);
        $stmtGF->execute();
        $resGF = $stmtGF->get_result();
        $stmtGF->close();

        if ($resGF && $resGF->num_rows > 0) {
            $row = $resGF->fetch_assoc();
            
            // Basic Fields
            $id = $row['id'];
            $table = 'guest_forms';
            $statusVal = $row['approval_status'] ?? 'pending';
            $visitDate = $row['visit_date'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;

            $fullName = trim(implode(' ', array_filter([$row['visitor_first_name']??'', $row['visitor_middle_name']??'', $row['visitor_last_name']??''])));
            $residentName = trim(($row['res_first_name']??'') . ' ' . ($row['res_last_name']??''));
            
            $birthRaw = $row['visitor_birthdate'] ?? null;
            $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
            $guardianBlocked = requiresGuardianBlock($birthRaw, $isAmenity);
            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => "Guest",
                'name' => $fullName ?: 'Guest',
                'resident_name' => $residentName,
                'house' => $row['res_house_number'] ?? 'N/A',
                'pax' => isset($row['persons']) && $row['persons'] !== null ? (int)$row['persons'] : 1,
                'status' => $statusVal,
                'scanned_at' => $scannedAt,
                'guardian_block' => $guardianBlocked
            ];
        }
    }

    // 2. Check RESERVATIONS (Amenity)
    if (!$data) {
        $stmtRes = $con->prepare("SELECT r.*, r.amenity AS amenity_name, u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name, u.birthdate AS user_birthdate, e.birthdate AS ep_birthdate FROM reservations r LEFT JOIN users u ON r.user_id = u.id LEFT JOIN entry_passes e ON r.entry_pass_id = e.id WHERE r.ref_code = ?");
        $stmtRes->bind_param('s', $code);
        $stmtRes->execute();
        $resRes = $stmtRes->get_result();
        $stmtRes->close();

        if ($resRes && $resRes->num_rows > 0) {
            $row = $resRes->fetch_assoc();

            $id = $row['id'];
            $table = 'reservations';
            $statusVal = $row['approval_status'] ?? ($row['status'] ?? 'pending');
            $startDate = $row['start_date'] ?? ($row['reservation_date'] ?? null);
            $endDate = $row['end_date'] ?? null;
            $startTime = $row['start_time'] ?? null;
            $endTime = $row['end_time'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;

            $nowDt = new DateTime('now');
            $startAt = null;
            $endAt = null;
            $dateForTime = $startDate ?: $endDate;
            if (!empty($dateForTime) && !empty($startTime)) { $startAt = new DateTime($dateForTime . ' ' . $startTime); }
            if (!empty($dateForTime) && !empty($endTime)) { $endAt = new DateTime($dateForTime . ' ' . $endTime); }

            $startDateYmd = !empty($startDate) ? date('Y-m-d', strtotime($startDate)) : null;
            $endDateYmd = !empty($endDate) ? date('Y-m-d', strtotime($endDate)) : null;

            $isExpired = false;
            if ($statusVal === 'approved') {
                if ($endAt) {
                    if ($nowDt > $endAt) { $isExpired = true; }
                } elseif ($endDateYmd && $endDateYmd < $today) {
                    $isExpired = true;
                } elseif (!$endDateYmd && $startDateYmd && $startDateYmd < $today) {
                    $isExpired = true;
                }
                if ($isExpired) { $statusVal = 'expired'; }
            }

            $fullName = trim(($row['res_first_name']??'') . ' ' . ($row['res_last_name']??''));

            $validityLabel = '';
            if (!empty($startDate) && !empty($endDate)) {
                $validityLabel = (date('m/d/y', strtotime($startDate)) !== date('m/d/y', strtotime($endDate)))
                    ? (date('m/d/y', strtotime($startDate)) . ' - ' . date('m/d/y', strtotime($endDate)))
                    : date('m/d/y', strtotime($startDate));
            } elseif (!empty($startDate)) {
                $validityLabel = date('m/d/y', strtotime($startDate));
            }
            $timeRange = '';
            if (!empty($startTime) && !empty($endTime)) {
                $timeRange = date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime));
            } elseif (!empty($startTime)) {
                $timeRange = date('g:i A', strtotime($startTime));
            }
            $birthRaw = !empty($row['ep_birthdate']) ? $row['ep_birthdate'] : ($row['user_birthdate'] ?? null);
            $guardianBlocked = requiresGuardianBlock($birthRaw, true);
            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => "Amenity",
                'name' => $fullName,
                'amenity' => $row['amenity_name'] ?? 'Unknown',
                'validity_label' => $validityLabel,
                'schedule_date' => $validityLabel,
                'schedule_time' => $timeRange,
                'pax' => isset($row['persons']) && $row['persons'] !== null ? (int)$row['persons'] : 1,
                'pool_booking_type' => $row['pool_booking_type'] ?? '',
                'status' => $statusVal,
                'scanned_at' => $scannedAt,
                'guardian_block' => $guardianBlocked
            ];
        }
    }
    
    // 3. Check RESIDENT RESERVATIONS (Amenity for Resident)
    if (!$data) {
        $stmtRR = $con->prepare("SELECT rr.*, rr.amenity AS amenity_name, u.house_number AS res_house_number, u.first_name AS res_first_name, u.last_name AS res_last_name, u.birthdate AS user_birthdate FROM resident_reservations rr LEFT JOIN users u ON rr.user_id = u.id WHERE rr.ref_code = ?");
        $stmtRR->bind_param('s', $code);
        $stmtRR->execute();
        $resRR = $stmtRR->get_result();
        $stmtRR->close();

        if ($resRR && $resRR->num_rows > 0) {
            $row = $resRR->fetch_assoc();

            $id = $row['id'];
            $table = 'resident_reservations';
            $statusVal = $row['approval_status'] ?? ($row['status'] ?? 'pending');
            $startDate = $row['start_date'] ?? ($row['reservation_date'] ?? null);
            $endDate = $row['end_date'] ?? null;
            $startTime = $row['start_time'] ?? null;
            $endTime = $row['end_time'] ?? null;
            $scannedAt = $row['scanned_at'] ?? null;

            $nowDt = new DateTime('now');
            $startAt = null;
            $endAt = null;
            $dateForTime = $startDate ?: $endDate;
            if (!empty($dateForTime) && !empty($startTime)) { $startAt = new DateTime($dateForTime . ' ' . $startTime); }
            if (!empty($dateForTime) && !empty($endTime)) { $endAt = new DateTime($dateForTime . ' ' . $endTime); }

            $startDateYmd = !empty($startDate) ? date('Y-m-d', strtotime($startDate)) : null;
            $endDateYmd = !empty($endDate) ? date('Y-m-d', strtotime($endDate)) : null;

            $isExpired = false;
            if ($statusVal === 'approved') {
                if ($endAt) {
                    if ($nowDt > $endAt) { $isExpired = true; }
                } elseif ($endDateYmd && $endDateYmd < $today) {
                    $isExpired = true;
                } elseif (!$endDateYmd && $startDateYmd && $startDateYmd < $today) {
                    $isExpired = true;
                }
                if ($isExpired) { $statusVal = 'expired'; }
            }

            $fullName = trim(($row['res_first_name']??'') . ' ' . ($row['res_last_name']??''));

            $validityLabel = '';
            if (!empty($startDate) && !empty($endDate)) {
                $validityLabel = (date('m/d/y', strtotime($startDate)) !== date('m/d/y', strtotime($endDate)))
                    ? (date('m/d/y', strtotime($startDate)) . ' - ' . date('m/d/y', strtotime($endDate)))
                    : date('m/d/y', strtotime($startDate));
            } elseif (!empty($startDate)) {
                $validityLabel = date('m/d/y', strtotime($startDate));
            }
            $timeRange = '';
            if (!empty($startTime) && !empty($endTime)) {
                $timeRange = date('g:i A', strtotime($startTime)) . ' - ' . date('g:i A', strtotime($endTime));
            } elseif (!empty($startTime)) {
                $timeRange = date('g:i A', strtotime($startTime));
            }
            $birthRaw = $row['user_birthdate'] ?? null;
            $guardianBlocked = requiresGuardianBlock($birthRaw, true);
            $data = [
                'id' => $id,
                'table' => $table,
                'code' => $row['ref_code'],
                'type_label' => "Amenity",
                'name' => $fullName,
                'amenity' => $row['amenity_name'] ?? 'Unknown',
                'validity_label' => $validityLabel,
                'schedule_date' => $validityLabel,
                'schedule_time' => $timeRange,
                'pax' => isset($row['persons']) && $row['persons'] !== null ? (int)$row['persons'] : 1,
                'pool_booking_type' => $row['pool_booking_type'] ?? '',
                'status' => $statusVal,
                'scanned_at' => $scannedAt,
                'guardian_block' => $guardianBlocked
            ];
        }
    }

    // 4. Check USERS (Resident Personal QR)
    if (!$data) {
        $stmtU = $con->prepare("SELECT * FROM users WHERE ref_code = ?");
        $stmtU->bind_param('s', $code);
        $stmtU->execute();
        $resU = $stmtU->get_result();
        $stmtU->close();

        if ($resU && $resU->num_rows > 0) {
            $row = $resU->fetch_assoc();
            
            $statusVal = $row['status'] ?? 'pending';
            $fullName = trim($row['first_name'] . ' ' . $row['last_name']);
            
            // Resident QR is always valid if status is active/approved
            // Mapping user status to 'approved' for UI
            if (stripos($statusVal, 'active') !== false || stripos($statusVal, 'approv') !== false) {
                $statusVal = 'approved';
            }

            $data = [
                'id' => $row['id'],
                'table' => 'users',
                'code' => $row['ref_code'],
                'type_label' => "Resident",
                'name' => $fullName,
                'house' => $row['house_number'],
                'status' => $statusVal,
                'scanned_at' => null // Users table doesn't have scanned_at usually, or we don't track it same way
            ];
        }
    }

    // Prepare UI Data
    if ($data) {
        $justConfirmed = false;
        if (!empty($_SESSION['just_confirmed_ref']) && $_SESSION['just_confirmed_ref'] === $data['code']) {
            $age = time() - intval($_SESSION['just_confirmed_time'] ?? 0);
            if ($age >= 0 && $age < 120) {
                $justConfirmed = true;
            }
            unset($_SESSION['just_confirmed_ref'], $_SESSION['just_confirmed_time']);
        }
        if (isset($_GET['just_scanned']) && $_GET['just_scanned'] === '1') {
            $justConfirmed = true;
        }
        $showConfirmPopup = $isAuthorizedScanner && !empty($_SESSION['confirm_popup']);
        if (!empty($_SESSION['confirm_popup'])) {
            unset($_SESSION['confirm_popup']);
        }
        if (!empty($data['guardian_block'])) {
            $data['ui_state'] = 'invalid';
            $data['ui_title'] = 'GUARDIAN REQUIRED';
            $data['ui_color'] = '#ef4444';
            $data['ui_msg'] = 'Guardian required: Approved for entry once accompanied by a guardian for amenity reservations.';
        } else {
            $s = strtolower($data['status']);
            if ($s === 'approved' || $s === 'permission_granted') {
            $oneTimeTables = ['guest_forms', 'reservations', 'resident_reservations'];
            if ($data['scanned_at'] && in_array($data['table'], $oneTimeTables, true) && !$justConfirmed) {
                $data['ui_state'] = 'used';
                $data['ui_title'] = 'PASS ALREADY USED';
                $data['ui_color'] = '#f59e0b'; // Orange
                $data['ui_msg'] = 'QR pass already scanned.';
            } else {
                $data['ui_state'] = 'valid';
                $data['ui_title'] = 'VALID ENTRY PASS';
                $data['ui_color'] = '#22c55e'; // Green
                $data['ui_msg'] = 'Access Granted';
            }
            } elseif ($s === 'deleted') {
            $data['ui_state'] = 'invalid';
            $data['ui_title'] = 'PASS REVOKED';
            $data['ui_color'] = '#ef4444';
            $data['ui_msg'] = 'This guest pass has been revoked.';
            } elseif ($s === 'expired') {
            $data['ui_state'] = 'expired';
            $data['ui_title'] = 'EXPIRED PASS';
            $data['ui_color'] = '#6b7280'; // Gray
            $data['ui_msg'] = 'This pass is no longer valid.';
            } elseif ($s === 'pending') {
            $data['ui_state'] = 'pending';
            $data['ui_title'] = 'PENDING APPROVAL';
            $data['ui_color'] = '#eab308'; // Yellow
            $data['ui_msg'] = 'This pass is awaiting approval.';
            } else {
            $data['ui_state'] = 'invalid';
            $data['ui_title'] = 'INVALID / DENIED';
            $data['ui_color'] = '#ef4444'; // Red
            $data['ui_msg'] = 'Access Denied.';
            }
        }
    } else {
        $error = 'Invalid or Unknown QR Code.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entry Pass Details - VictorianPass</title>
    <link rel="icon" type="image/png" href="images/logo.svg" />
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Empty script tag for clean removal -->
    <style>
        * { box-sizing: border-box; font-family: 'Poppins', sans-serif; margin: 0; padding: 0; }
        body { 
            background: #121212; 
            color: #fff; 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            min-height: 100vh; 
            padding: 20px; 
        }
        
        .container { 
            width: 100%; 
            max-width: 400px; 
            background: #1e1e1e; 
            border-radius: 16px; 
            overflow: hidden; 
            box-shadow: 0 10px 25px rgba(0,0,0,0.5); 
            display: flex;
            flex-direction: column;
        }

        .top-bar {
            background: #000;
            padding: 15px;
            text-align: center;
        }
        .top-bar img {
            height: 24px;
            vertical-align: middle;
            margin-right: 8px;
        }
        .top-bar span {
            color: #e5ddc6;
            font-weight: 600;
            vertical-align: middle;
            font-size: 1rem;
        }

        .status-header {
            padding: 30px 20px;
            text-align: center;
            color: #fff;
        }
        .status-header h1 {
            font-size: 1.6rem;
            font-weight: 800;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .status-header p {
            font-size: 0.95rem;
            font-weight: 500;
            opacity: 0.9;
        }

        .qr-display {
            background: #1e1e1e;
            padding: 20px;
            text-align: center;
        }
        .qr-box {
            background: #fff;
            padding: 15px;
            border-radius: 12px;
            display: inline-block;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            width: 210px;
            height: 210px;
        }
        .qr-box img, .qr-box canvas {
            width: 100% !important;
            height: 100% !important;
            display: block;
            object-fit: contain;
        }
        .qr-instruction {
            margin-top: 15px;
            color: #aaa;
            font-size: 0.85rem;
        }

        .details-section {
            padding: 20px 25px;
            background: #1e1e1e;
        }
        .details-title {
            color: #9bd08f; /* Light Green */
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 15px;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 0.9rem;
        }
        .detail-row .label {
            color: #888;
        }
        .detail-row .value {
            color: #fff;
            font-weight: 600;
            text-align: right;
        }

        .action-area {
            padding: 25px;
            text-align: center;
        }
        .btn-confirm {
            background: #d4af37;
            color: #1f2937;
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 8px;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            text-transform: uppercase;
            box-shadow: 0 4px 15px rgba(212, 175, 55, 0.35);
        }
        .btn-confirm:active,
        .btn-confirm.is-clicked {
            background: #b8892e;
            box-shadow: 0 2px 8px rgba(184, 137, 46, 0.5);
            transform: translateY(1px);
        }
        .btn-confirm:focus-visible {
            outline: 2px solid #f5d76e;
            outline-offset: 2px;
        }
        .btn-confirm:disabled {
            background: #444;
            color: #888;
            cursor: not-allowed;
            box-shadow: none;
        }
        .btn-home {
            background: transparent;
            color: #aaa;
            border: 1px solid #444;
            margin-top: 15px;
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .pass-type-title {
            text-align: center;
            font-size: 0.85rem;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: #9ca3af;
            margin: 6px 0 14px;
            text-transform: uppercase;
        }
        .footer {
            margin-top: 30px;
            color: #555;
            font-size: 0.75rem;
            text-align: center;
        }
    </style>
</head>
<body>

    <?php if ($error): ?>
        <div class="container" style="text-align:center; padding:40px;">
            <h2 style="color:#ef4444; margin-bottom:10px;">Error</h2>
            <p style="color:#ccc;"><?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php elseif ($data): ?>
        <div class="container">
            <!-- Top Bar -->
            <div class="top-bar">
                <img src="images/logo.svg" alt="Logo">
                <span>Victorian Heights</span>
            </div>

            <?php
              $passTypeTitle = ($data['type_label'] ?? '') === 'Guest' ? 'Guest Pass'
                : (($data['type_label'] ?? '') === 'Amenity' ? 'Amenity Pass'
                : (($data['type_label'] ?? '') === 'Resident' ? 'Resident Pass'
                : trim(($data['type_label'] ?? 'Entry') . ' Pass')));
            ?>
            <div class="pass-type-title"><?php echo htmlspecialchars($passTypeTitle); ?></div>

            <!-- Status Header -->
            <div class="status-header" style="background: <?php echo $data['ui_color']; ?>;">
                <h1><?php echo htmlspecialchars($data['ui_title']); ?></h1>
                <p><?php echo htmlspecialchars($data['ui_msg']); ?></p>
            </div>

            <!-- QR Code Area -->
            <div class="qr-display">
                <div class="qr-box" id="qrcode">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?php echo urlencode($verificationLink); ?>" alt="QR Code">
                </div>
                <div class="qr-instruction">Present this QR code to the guard</div>
            </div>
            
            <style>
                .qr-box img {
                    width: 100%;
                    height: 100%;
                    object-fit: contain;
                    display: block;
                }
            </style>

            <?php if (isset($data['type_label']) && $data['type_label'] === 'Amenity'): ?>
                <p style="text-align:center; color:#aaa; font-size:0.85rem; margin-bottom: 20px;">Do not scan<br><span style="font-size:12px; font-weight:normal;">(For verification only)</span></p>
            <?php endif; ?>

            <!-- Pass Details -->
            <div class="details-section">
                <div class="details-title">Pass Details</div>
                
                <div class="detail-row">
                    <span class="label">Pass Type</span>
                    <span class="value"><?php echo htmlspecialchars($data['type_label']); ?></span>
                </div>
                
                <div class="detail-row">
                    <span class="label"><?php echo (isset($data['type_label']) && $data['type_label'] === 'Guest') ? 'Guest Name' : 'Name'; ?></span>
                    <span class="value"><?php echo htmlspecialchars($data['name']); ?></span>
                </div>

                <?php if (isset($data['type_label']) && $data['type_label'] === 'Guest'): ?>
                <?php if (!empty($data['resident_name'])): ?>
                <div class="detail-row">
                    <span class="label">Referred by Resident</span>
                    <span class="value"><?php echo htmlspecialchars($data['resident_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($data['house'])): ?>
                <div class="detail-row">
                    <span class="label">Resident House No.</span>
                    <span class="value"><?php echo htmlspecialchars($data['house']); ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
                
                <div class="detail-row">
                    <span class="label">Ref Code</span>
                    <span class="value"><?php echo htmlspecialchars($data['code']); ?></span>
                </div>
                
                <?php if (!empty($data['amenity'])): ?>
                <div class="detail-row">
                    <span class="label">Amenity</span>
                    <span class="value"><?php echo htmlspecialchars($data['amenity']); ?></span>
                </div>
                <?php
                    $poolTypeLabel = '';
                    $amenityRaw = trim((string)($data['amenity'] ?? ''));
                    if (strcasecmp($amenityRaw, 'Pool') === 0) {
                        $pt = strtolower(trim((string)($data['pool_booking_type'] ?? '')));
                        if ($pt === 'whole_pool') { $poolTypeLabel = 'Whole Pool'; }
                        else if ($pt === 'per_person') { $poolTypeLabel = 'Shared Pool'; }
                        else {
                            $personsVal = isset($data['pax']) ? intval($data['pax']) : 0;
                            if ($personsVal >= 20) { $poolTypeLabel = 'Whole Pool'; }
                            else if ($personsVal > 0) { $poolTypeLabel = 'Shared Pool'; }
                        }
                    }
                ?>
                <?php if ($poolTypeLabel !== ''): ?>
                <div class="detail-row">
                    <span class="label">Pool Type</span>
                    <span class="value"><?php echo htmlspecialchars($poolTypeLabel); ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <?php if (!empty($data['schedule_date'])): ?>
                <div class="detail-row">
                    <span class="label">Reservation Date</span>
                    <span class="value"><?php echo htmlspecialchars($data['schedule_date']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['schedule_time'])): ?>
                <div class="detail-row">
                    <span class="label">Reservation Time</span>
                    <span class="value"><?php echo htmlspecialchars($data['schedule_time']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['scanned_at'])): ?>
                <div class="detail-row">
                    <span class="label">Scanned At</span>
                    <span class="value"><?php echo htmlspecialchars(date('m/d/y g:i A', strtotime($data['scanned_at']))); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['pax']) && (!isset($data['type_label']) || $data['type_label'] !== 'Guest')): ?>
                <div class="detail-row">
                    <span class="label">Persons</span>
                    <span class="value"><?php echo htmlspecialchars($data['pax']); ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($data['house']) && (!isset($data['type_label']) || $data['type_label'] !== 'Guest')): ?>
                <div class="detail-row">
                    <span class="label">House No.</span>
                    <span class="value"><?php echo htmlspecialchars($data['house']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="action-area">
                <?php if ($isAuthorizedScanner && $data['ui_state'] === 'valid'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="confirm_entry">
                        <input type="hidden" name="ref_code" value="<?php echo htmlspecialchars($data['code']); ?>">
                        <input type="hidden" name="source_table" value="<?php echo htmlspecialchars($data['table']); ?>">
                        <input type="hidden" name="source_id" value="<?php echo htmlspecialchars($data['id']); ?>">
                        <button type="submit" class="btn-confirm">CONFIRM ENTRY</button>
                    </form>
                <?php elseif ($data['ui_state'] === 'used'): ?>
                     <button class="btn-confirm" style="background:#f59e0b; cursor:default;">ALREADY USED</button>
                <?php elseif ($data['ui_state'] === 'expired'): ?>
                     <button class="btn-confirm" style="background:#6b7280; cursor:default;" disabled>EXPIRED</button>
                <?php else: ?>
                    <!-- Optional: Link to go home or something -->
                <?php endif; ?>
            </div>
            
            <?php if (isset($data['type_label']) && $data['type_label'] === 'Amenity' && !empty($data['validity_label'])): ?>
            <div class="details-section" style="margin-top:18px;">
                <div class="details-title">Validity</div>
                <div class="detail-row">
                    <span class="label">Valid Dates</span>
                    <span class="value"><?php echo htmlspecialchars($data['validity_label']); ?></span>
                </div>
                <div class="detail-row" style="border-bottom:none;">
                    <span class="label">Reminder</span>
                    <span class="value">QR code will expire once the reservation schedule ends. If your reservation is expired, this QR code can no longer be used.</span>
                </div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="footer">
        VictorianPass Validation System &copy; <?php echo date('Y'); ?>
    </div>
    <?php if (!empty($showConfirmPopup)): ?>
    <script>
        window.addEventListener('DOMContentLoaded', function(){
            alert('Confirm entry');
        });
    </script>
    <?php endif; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.btn-confirm').forEach(function(btn){
                if (btn.getAttribute('type') !== 'submit') { return; }
                btn.addEventListener('click', function(){
                    if (btn.disabled) { return; }
                    btn.classList.add('is-clicked');
                });
            });
        });
    </script>

</body>
</html>
