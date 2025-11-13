<?php
session_start();
include 'connect.php';

// Ensure new guest_forms table exists for admin operations
ensureGuestFormsTable($con);
ensureGuestFormsWantsAmenityColumn($con);
ensureGuestFormsAmenityColumns($con);

// Handle AJAX request for user details (admin resident profile)
if (isset($_GET['action']) && $_GET['action'] == 'get_user_details' && isset($_GET['id'])) {
    $user_id = intval($_GET['id']);
    $stmt = $con->prepare("SELECT id, first_name, middle_name, last_name, email, phone, sex, birthdate, house_number, address, created_at, user_type, IFNULL(status,'active') as status FROM users WHERE id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && $row = $res->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'User not found']);
    }
    $stmt->close();
    exit;
}

// Handle AJAX request for visitor details (guest_forms first, legacy fallback)
if (isset($_GET['action']) && $_GET['action'] == 'get_visitor_details' && isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $source = isset($_GET['source']) ? $_GET['source'] : '';

    // Try new guest_forms source unless explicitly a reservation
    if ($source !== 'reservation') {
    $stmtGF = $con->prepare("SELECT gf.*, 
                                    u.first_name AS res_first_name, u.middle_name AS res_middle_name, u.last_name AS res_last_name,
                                    u.email AS res_email, u.phone AS res_phone, u.house_number AS res_house_number
                             FROM guest_forms gf
                             LEFT JOIN users u ON gf.resident_user_id = u.id
                             WHERE gf.id = ?");
    $stmtGF->bind_param('i', $id);
    $stmtGF->execute();
    $resGF = $stmtGF->get_result();
    if ($resGF && $row = $resGF->fetch_assoc()) {
        $isAmenity = (!empty($row['amenity'])) || (isset($row['wants_amenity']) && intval($row['wants_amenity']) === 1);
        $details = [
            'id' => intval($row['id']),
            'user_id' => isset($row['resident_user_id']) ? intval($row['resident_user_id']) : null,
            'full_name' => $row['visitor_first_name'],
            'middle_name' => $row['visitor_middle_name'],
            'last_name' => $row['visitor_last_name'],
            'sex' => $row['visitor_sex'],
            'birthdate' => $row['visitor_birthdate'],
            'contact' => $row['visitor_contact'],
            'email' => $row['visitor_email'],
            'address' => $row['resident_house'],
            'valid_id_path' => $row['valid_id_path'],
            'entry_created' => $row['created_at'],
            'amenity' => $isAmenity ? ($row['amenity'] ?: 'Amenity Reservation') : 'Guest Entry',
            'start_date' => $isAmenity ? ($row['start_date'] ?: $row['visit_date']) : $row['visit_date'],
            'end_date' => $isAmenity ? ($row['end_date'] ?: $row['visit_date']) : $row['visit_date'],
            'persons' => !empty($row['persons']) ? intval($row['persons']) : null,
            'purpose' => $row['purpose'],
            'price' => $isAmenity && isset($row['price']) ? floatval($row['price']) : null,
            'approval_status' => $row['approval_status'],
            'approved_by' => $row['approved_by'],
            'approval_date' => $row['approval_date'],
            'res_first_name' => $row['res_first_name'],
            'res_middle_name' => $row['res_middle_name'],
            'res_last_name' => $row['res_last_name'],
            'res_house_number' => $row['res_house_number'],
            'res_phone' => $row['res_phone'],
            'res_email' => $row['res_email']
        ];
        echo json_encode(['success' => true, 'details' => $details]);
        exit;
    }
    }

    // Legacy visitor flow: reservations + entry_passes
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.id = ? AND r.entry_pass_id IS NOT NULL";
    if ($source !== 'guest_form') {
        $stmt = $con->prepare($query);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result && $row = $result->fetch_assoc()) {
            echo json_encode(['success' => true, 'details' => $row]);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Visitor details not found']);
    exit;
}

// Handle AJAX request for standard amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_reservation_details' && isset($_GET['id'])) {
    $reservation_id = intval($_GET['id']);
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              WHERE r.id = ? AND (r.entry_pass_id IS NULL OR r.entry_pass_id = 0)";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Reservation details not found']);
    }
    exit;
}

// Handle AJAX request for resident amenity reservation details
if (isset($_GET['action']) && $_GET['action'] == 'get_resident_reservation_details' && isset($_GET['id'])) {
    $rr_id = intval($_GET['id']);
    $query = "SELECT rr.*, u.first_name, u.middle_name, u.last_name, u.email, u.phone, u.house_number
              FROM resident_reservations rr
              LEFT JOIN users u ON rr.user_id = u.id
              WHERE rr.id = ?";
    $stmt = $con->prepare($query);
    $stmt->bind_param('i', $rr_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode(['success' => true, 'details' => $row]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Resident reservation not found']);
    }
    exit;
}

// Handle incident report status updates
if (isset($_POST['incident_action']) && isset($_POST['report_id'])) {
    $rid = intval($_POST['report_id']);
    $action = $_POST['incident_action'];
    $newStatus = null;
    if ($action === 'start') $newStatus = 'in_progress';
    elseif ($action === 'resolve') $newStatus = 'resolved';
    elseif ($action === 'reject') $newStatus = 'rejected';
    if ($newStatus) {
        $stmt = $con->prepare("UPDATE incident_reports SET status = ?, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('si', $newStatus, $rid);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: admin.php?page=report");
    exit;
}

// Handle resident account actions (delete/deactivate/activate)
if (isset($_POST['user_action']) && isset($_POST['user_id'])) {
    $uid = intval($_POST['user_id']);
    $action = $_POST['user_action'];

    // Ensure users table has status column
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'");
    }

    if ($action === 'deactivate_user') {
        $stmt = $con->prepare("UPDATE users SET status='disabled' WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) {
            echo "<script>alert('User has been deactivated.'); window.location.href='admin.php?page=residents';</script>";
        } else {
            echo "<script>alert('Failed to deactivate user.'); window.location.href='admin.php?page=residents';</script>";
        }
        exit;
    }
    if ($action === 'activate_user') {
        $stmt = $con->prepare("UPDATE users SET status='active' WHERE id = ?");
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute();
        $stmt->close();
        echo "<script>alert('User has been reactivated.'); window.location.href='admin.php?page=residents';</script>";
        exit;
    }
    if ($action === 'delete_user') {
        // Safely unlink reservations before deleting user
        $con->begin_transaction();
        try {
            $stmt1 = $con->prepare("UPDATE reservations SET user_id = NULL WHERE user_id = ?");
            $stmt1->bind_param('i', $uid);
            $stmt1->execute();
            $stmt1->close();
            
            $stmt2 = $con->prepare("DELETE FROM users WHERE id = ?");
            $stmt2->bind_param('i', $uid);
            $stmt2->execute();
            $stmt2->close();
            
            $con->commit();
            echo "<script>alert('User account deleted.'); window.location.href='admin.php?page=residents';</script>";
        } catch (Exception $e) {
            $con->rollback();
            echo "<script>alert('Failed to delete user: " . htmlspecialchars($e->getMessage()) . "'); window.location.href='admin.php?page=residents';</script>";
        }
        exit;
    }
}

// Ensure admin session based on existing login.php (role-based)
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit;
}

$admin_email = $_SESSION['email'] ?? '';
$admin_role = $_SESSION['role'] ?? '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// Functions to get dashboard statistics
function getResidentCount($con) {
    $query = "SELECT COUNT(*) as count FROM users";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getActivePassesCount($con) {
    // Assuming you have a passes table or similar
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations WHERE end_date >= CURDATE()";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPendingRequestsCount($con) {
    // Count pending visitor requests (entry pass reservations)
    $query = "SELECT COUNT(*) as count FROM reservations WHERE approval_status = 'pending' AND entry_pass_id IS NOT NULL";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

function getPaymentReceiptsCount($con) {
    // Count verified payments
    // Modify this query based on your actual database structure
    $query = "SELECT COUNT(*) as count FROM reservations";
    $result = $con->query($query);
    if ($result && $row = $result->fetch_assoc()) {
        return $row['count'];
    }
    return 0;
}

// Functions to get data for different sections
function getResidents($con) {
    $query = "SELECT * FROM users ORDER BY created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getReservations($con) {
    $query = "SELECT r.*, u.first_name, u.middle_name, u.last_name
              FROM reservations r
              LEFT JOIN users u ON r.user_id = u.id
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Resident amenity reservations (resident_reservations table)
function getResidentReservations($con) {
    $query = "SELECT rr.*, u.first_name, u.middle_name, u.last_name, u.house_number, u.email, u.phone
              FROM resident_reservations rr
              LEFT JOIN users u ON rr.user_id = u.id
              ORDER BY rr.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

// Guest amenity reservations (reservations with entry_pass_id and amenity)
function getGuestAmenityReservations($con) {
    $query = "SELECT gf.*, gf.id AS gf_id,
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     u.house_number AS res_house_number
              FROM guest_forms gf
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.amenity IS NOT NULL
              ORDER BY gf.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getSecurityGuards($con) {
    $query = "SELECT * FROM staff WHERE role = 'guard'";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

function getIncidentReports($con) {
    $query = "SELECT ir.*, u.first_name, u.middle_name, u.last_name FROM incident_reports ir LEFT JOIN users u ON ir.user_id = u.id ORDER BY ir.created_at DESC";
    $result = $con->query($query);
    return $result ?: false;
}

function getIncidentProofs($con, $reportId) {
    $stmt = $con->prepare("SELECT file_path FROM incident_proofs WHERE report_id = ? ORDER BY uploaded_at ASC");
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $res = $stmt->get_result();
    $files = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) { $files[] = $row['file_path']; }
    }
    $stmt->close();
    return $files;
}

// Function to get visitor requests with personal details
function getVisitorRequests($con) {
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.entry_pass_id IS NOT NULL 
              ORDER BY r.created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
}

// Split visitor-related requests by source
function getResidentVisitorRequests($con) {
    // Link guest forms to reservations via ref_code; amenity only when a reservation exists
    $query = "SELECT gf.*, 
                     gf.visitor_first_name AS full_name, gf.visitor_middle_name AS middle_name, gf.visitor_last_name AS last_name,
                     r.amenity AS amenity, COALESCE(r.persons, gf.persons) AS persons, u.house_number AS res_house_number
              FROM guest_forms gf
              LEFT JOIN reservations r ON r.ref_code = gf.ref_code
              LEFT JOIN users u ON gf.resident_user_id = u.id
              WHERE gf.resident_user_id IS NOT NULL
              ORDER BY gf.created_at DESC";
    $res = $con->query($query);
    if ($res && $res->num_rows > 0) return $res;
    // Fallback: legacy reservations + entry_passes
    $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate,
                                  ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created,
                                  u.house_number AS res_house_number, r.amenity, r.persons
                           FROM reservations r
                           JOIN entry_passes ep ON r.entry_pass_id = ep.id
                           LEFT JOIN users u ON r.user_id = u.id
                           WHERE r.entry_pass_id IS NOT NULL AND r.user_id IS NOT NULL
                           ORDER BY r.created_at DESC");
    return $legacy ?: false;
}

function getVisitorOnlyRequests($con) {
    $legacy = $con->query("SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate,
                                  ep.contact, ep.email, ep.address, ep.valid_id_path, ep.created_at as entry_created
                           FROM reservations r
                           JOIN entry_passes ep ON r.entry_pass_id = ep.id
                           WHERE r.entry_pass_id IS NOT NULL AND (r.user_id IS NULL OR r.user_id = 0)
                           ORDER BY r.created_at DESC");
    return $legacy ?: false;
}

// Add: ensure reservations has a status column and auto-expire old reservations
function ensureReservationStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        // Create a status column with sensible defaults
        $con->query("ALTER TABLE reservations ADD COLUMN status ENUM('pending','approved','rejected','expired') NOT NULL DEFAULT 'pending'");
    }
}

function autoExpireReservations($con) {
    // Mark reservations expired when past end_date
    $con->query("UPDATE reservations SET status='expired' WHERE end_date < CURDATE() AND status <> 'expired'");
}

// Ensure incident-related tables exist to prevent runtime errors
function ensureIncidentTables($con) {
    $con->query("CREATE TABLE IF NOT EXISTS incident_reports (
      id INT AUTO_INCREMENT PRIMARY KEY,
      complainant VARCHAR(150) NOT NULL,
      address VARCHAR(255) NOT NULL,
      nature VARCHAR(255) NULL,
      other_concern VARCHAR(255) NULL,
      user_id INT NULL,
      status ENUM('new','in_progress','resolved','rejected') DEFAULT 'new',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_status (status),
      INDEX idx_user_id (user_id)
    ) ENGINE=InnoDB");

    $con->query("CREATE TABLE IF NOT EXISTS incident_proofs (
      id INT AUTO_INCREMENT PRIMARY KEY,
      report_id INT NOT NULL,
      file_path VARCHAR(255) NOT NULL,
      uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX idx_report_id (report_id)
    ) ENGINE=InnoDB");
}

ensureReservationStatusColumn($con);
autoExpireReservations($con);
ensureIncidentTables($con);
// Ensure resident reservations have necessary columns
function ensureResidentApprovalColumns($con) {
    $check1 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approved_by'");
    if ($check1 && $check1->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approved_by INT NULL AFTER approval_status");
    }
    $check2 = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'approval_date'");
    if ($check2 && $check2->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN approval_date DATETIME NULL AFTER approved_by");
    }
}
ensureResidentApprovalColumns($con);
ensureResidentReservationQrColumn($con);

// Ensure users table has a status column to support deactivation
function ensureUsersStatusColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE users ADD COLUMN status ENUM('active','disabled') NOT NULL DEFAULT 'active'");
    }
}
ensureUsersStatusColumn($con);

// Ensure new guest_forms table and its QR column exist
function ensureGuestFormsTable($con) {
    $con->query("CREATE TABLE IF NOT EXISTS guest_forms (
      id INT AUTO_INCREMENT PRIMARY KEY,
      resident_user_id INT NULL,
      resident_house VARCHAR(100) NULL,
      resident_email VARCHAR(150) NULL,
      visitor_first_name VARCHAR(100) NOT NULL,
      visitor_middle_name VARCHAR(100) NULL,
      visitor_last_name VARCHAR(100) NOT NULL,
      visitor_sex VARCHAR(20) NULL,
      visitor_birthdate DATE NULL,
      visitor_contact VARCHAR(50) NULL,
      visitor_email VARCHAR(150) NULL,
      valid_id_path VARCHAR(255) NULL,
      visit_date DATE NULL,
      visit_time VARCHAR(20) NULL,
      purpose VARCHAR(255) NULL,
      wants_amenity TINYINT(1) NOT NULL DEFAULT 0,
      persons INT NULL,
      ref_code VARCHAR(50) NOT NULL UNIQUE,
      approval_status ENUM('pending','approved','denied') DEFAULT 'pending',
      approved_by INT NULL,
      approval_date DATETIME NULL,
      qr_path VARCHAR(255) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL,
      INDEX idx_resident_user_id (resident_user_id),
      INDEX idx_ref_code (ref_code)
    ) ENGINE=InnoDB");
}

// Ensure amenity preference column exists even if table was created earlier
function ensureGuestFormsWantsAmenityColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE 'wants_amenity'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE guest_forms ADD COLUMN wants_amenity TINYINT(1) NOT NULL DEFAULT 0 AFTER purpose");
    }
}

function ensureGuestFormsAmenityColumns($con) {
    $cols = ['amenity','start_date','end_date','price'];
    foreach ($cols as $c) {
        $check = $con->query("SHOW COLUMNS FROM guest_forms LIKE '".$con->real_escape_string($c)."'");
        if ($check && $check->num_rows === 0) {
            if ($c === 'amenity') $con->query("ALTER TABLE guest_forms ADD COLUMN amenity VARCHAR(100) NULL AFTER wants_amenity");
            if ($c === 'start_date') $con->query("ALTER TABLE guest_forms ADD COLUMN start_date DATE NULL AFTER amenity");
            if ($c === 'end_date') $con->query("ALTER TABLE guest_forms ADD COLUMN end_date DATE NULL AFTER start_date");
            if ($c === 'price') $con->query("ALTER TABLE guest_forms ADD COLUMN price DECIMAL(10,2) NULL AFTER persons");
        }
    }
}

function generateQrForGuestForm($con, $gfId) {
    $gfId = intval($gfId);
    if ($gfId <= 0) return;

    // Fetch guest form
    $stmt = $con->prepare("SELECT ref_code FROM guest_forms WHERE id = ?");
    $stmt->bind_param('i', $gfId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('GF-' . $gfId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_guest_' . $gfId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE guest_forms SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $gfId);
    $stmt2->execute();
    $stmt2->close();
}

// Ensure reservations has a QR path column
function ensureReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER receipt_path");
    }
}

// Ensure resident_reservations has a QR path column
function ensureResidentReservationQrColumn($con) {
    $check = $con->query("SHOW COLUMNS FROM resident_reservations LIKE 'qr_path'");
    if ($check && $check->num_rows === 0) {
        $con->query("ALTER TABLE resident_reservations ADD COLUMN qr_path VARCHAR(255) NULL AFTER updated_at");
    }
}

// Generate and store QR code for a reservation
function generateQrForReservation($con, $reservationId) {
    $reservationId = intval($reservationId);
    if ($reservationId <= 0) return;

    ensureReservationQrColumn($con);
    // Fetch reservation details
    $stmt = $con->prepare("SELECT ref_code, start_date, end_date FROM reservations WHERE id = ?");
    $stmt->bind_param('i', $reservationId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RES-' . $reservationId);
    $start = isset($row['start_date']) ? $row['start_date'] : '';
    $end   = isset($row['end_date']) ? $row['end_date'] : '';

    // Build a direct status URL so scanners open the details page
    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    // Generate QR for the status link
    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return; // fail silently

    $relPath = 'uploads/qr_reservation_' . $reservationId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    // Update reservation with QR path
    $stmt2 = $con->prepare("UPDATE reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $reservationId);
    $stmt2->execute();
    $stmt2->close();
}

// Generate and store QR code for a resident reservation
function generateQrForResidentReservation($con, $rrId) {
    $rrId = intval($rrId);
    if ($rrId <= 0) return;

    ensureResidentReservationQrColumn($con);

    $stmt = $con->prepare("SELECT ref_code FROM resident_reservations WHERE id = ?");
    $stmt->bind_param('i', $rrId);
    $stmt->execute();
    $res = $stmt->get_result();
    if (!$res || !$res->num_rows) { $stmt->close(); return; }
    $row = $res->fetch_assoc();
    $stmt->close();

    $ref = $row['ref_code'] ?? ('RR-' . $rrId);

    $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $basePath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/VictorianPass'), '/');
    $statusLink = $scheme . '://' . $host . $basePath . '/status_view.php?code=' . urlencode($ref);

    $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=' . urlencode($statusLink);
    $img = @file_get_contents($qrUrl);
    if ($img === false) return;

    $relPath = 'uploads/qr_resident_' . $rrId . '.png';
    $absPath = __DIR__ . '/' . $relPath;
    @file_put_contents($absPath, $img);

    $stmt2 = $con->prepare("UPDATE resident_reservations SET qr_path = ? WHERE id = ?");
    $stmt2->bind_param('si', $relPath, $rrId);
    $stmt2->execute();
    $stmt2->close();
}

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Handle visitor request approval/denial (guest_forms first)
        if ($action == 'approve_request' || $action == 'deny_request') {
            $reservation_id = intval($_POST['reservation_id']);
            $approval_status = ($action == 'approve_request') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;

            // Try updating guest_forms
            $stmtGFCheck = $con->prepare("SELECT id FROM guest_forms WHERE id = ?");
            $stmtGFCheck->bind_param('i', $reservation_id);
            $stmtGFCheck->execute();
            $resGFCheck = $stmtGFCheck->get_result();
            if ($resGFCheck && $resGFCheck->num_rows > 0) {
                $stmtUp = $con->prepare("UPDATE guest_forms SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?");
                $stmtUp->bind_param('sii', $approval_status, $staff_id, $reservation_id);
                $stmtUp->execute();
                $stmtUp->close();
                if ($approval_status === 'approved') {
                    generateQrForGuestForm($con, $reservation_id);
                }
            } else {
                // Legacy: Update reservation approval status
                $query = "UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?";
                $stmt = $con->prepare($query);
                $stmt->bind_param("sii", $approval_status, $staff_id, $reservation_id);
                $stmt->execute();
                $stmt->close();
                if ($approval_status === 'approved') {
                    generateQrForReservation($con, $reservation_id);
                }
            }

            // Redirect to prevent form resubmission
            header("Location: admin.php?page=requests");
            exit;
        }
        
        // Handle reservation approval/rejection
        if ($action == 'approve_reservation' || $action == 'reject_reservation') {
            $reservation_id = $_POST['reservation_id'];
            $status = ($action == 'approve_reservation') ? 'approved' : 'rejected';
            
            // Update reservation status (column ensured above)
            $query = "UPDATE reservations SET status = ? WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("si", $status, $reservation_id);
            $stmt->execute();

            // Generate QR code upon approval
            if ($status === 'approved') {
                generateQrForReservation($con, intval($reservation_id));
            }
            
            // Redirect to prevent form resubmission
            header("Location: admin.php?page=requests");
            exit;
        }

        // Handle deletion of denied/rejected reservations or guest_forms
        if ($action == 'delete_reservation') {
            $reservation_id = intval($_POST['reservation_id'] ?? 0);
            if ($reservation_id > 0) {
                // Prefer guest_forms
                $stmtGF = $con->prepare("SELECT approval_status FROM guest_forms WHERE id = ?");
                $stmtGF->bind_param('i', $reservation_id);
                $stmtGF->execute();
                $resGF = $stmtGF->get_result();
                $stmtGF->close();
                if ($resGF && $rowGF = $resGF->fetch_assoc()) {
                    if (($rowGF['approval_status'] ?? '') === 'denied') {
                        $stmtDelGF = $con->prepare("DELETE FROM guest_forms WHERE id = ?");
                        $stmtDelGF->bind_param('i', $reservation_id);
                        $stmtDelGF->execute();
                        $stmtDelGF->close();
                    }
                } else {
                    // Legacy reservation path
                    $stmt = $con->prepare("SELECT id, entry_pass_id, approval_status, status FROM reservations WHERE id = ?");
                    $stmt->bind_param('i', $reservation_id);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res && $row = $res->fetch_assoc()) {
                        $isDenied = (isset($row['approval_status']) && $row['approval_status'] === 'denied') || (isset($row['status']) && $row['status'] === 'rejected');
                        if ($isDenied) {
                            $entryId = intval($row['entry_pass_id'] ?? 0);
                            if ($entryId > 0) {
                                $stmtDelEP = $con->prepare("DELETE FROM entry_passes WHERE id = ?");
                                $stmtDelEP->bind_param('i', $entryId);
                                $stmtDelEP->execute();
                                $stmtDelEP->close();
                            }
                            $stmtDelR = $con->prepare("DELETE FROM reservations WHERE id = ?");
                            $stmtDelR->bind_param('i', $reservation_id);
                            $stmtDelR->execute();
                            $stmtDelR->close();
                        }
                    }
                    $stmt->close();
                }
            }
            header("Location: admin.php?page=requests");
            exit;
        }

        // Handle resident reservation approval/denial
        if ($action == 'approve_resident_reservation' || $action == 'deny_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            $approval_status = ($action == 'approve_resident_reservation') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;

            if ($rr_id > 0) {
                $stmt = $con->prepare("UPDATE resident_reservations SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?");
                $stmt->bind_param('sii', $approval_status, $staff_id, $rr_id);
                $stmt->execute();
                $stmt->close();

                if ($approval_status === 'approved') {
                    generateQrForResidentReservation($con, $rr_id);
                }
            }
            header("Location: admin.php?page=reservations");
            exit;
        }

        // Handle deletion of denied resident reservations
        if ($action == 'delete_resident_reservation') {
            $rr_id = intval($_POST['rr_id'] ?? 0);
            if ($rr_id > 0) {
                // Only allow deletion when denied
                $stmt = $con->prepare("SELECT approval_status FROM resident_reservations WHERE id = ?");
                $stmt->bind_param('i', $rr_id);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && $row = $res->fetch_assoc()) {
                    if (($row['approval_status'] ?? '') === 'denied') {
                        $stmtDel = $con->prepare("DELETE FROM resident_reservations WHERE id = ?");
                        $stmtDel->bind_param('i', $rr_id);
                        $stmtDel->execute();
                        $stmtDel->close();
                    }
                }
                $stmt->close();
            }
            header("Location: admin.php?page=reservations");
            exit;
        }

        // Handle receipt verification
        if ($action == 'verify_receipt' || $action == 'reject_receipt') {
            $reservation_id = $_POST['reservation_id'];
            $payment_status = ($action == 'verify_receipt') ? 'verified' : 'rejected';
            $staff_id = $_SESSION['staff_id'] ?? null;
            
            // Update payment status
            $query = "UPDATE reservations SET payment_status = ?, verified_by = ?, verification_date = NOW() WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("sii", $payment_status, $staff_id, $reservation_id);
            $stmt->execute();
            
            // Redirect to prevent form resubmission
            header("Location: admin.php?page=verify");
            exit;
        }
    }
}

// Get current page from URL parameter or default to dashboard
$currentPage = isset($_GET['page']) ? $_GET['page'] : 'dashboard';
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/>
<meta name="viewport" content="width=device-width,initial-scale=1"/>
<title>VictorianPass | Admin</title>
<link rel="icon" type="image/png" href="mainpage/logo.svg">

<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
  --bg-dark:#2b2623;
  --nav-cream:#f4efe6;
  --nav-cream-active:#e8dfca;
  --accent:#23412e;
  --header-beige:#f7efe3;
  --card:#ffffff;
  --muted:#8b918d;
  --status-active:#2f80ed;
  --status-approved:#27ae60;
  --status-pending:#6c5ce7;
  --status-expired:#95a5a6;
  --status-rejected:#e74c3c;
  --shadow:0 8px 18px rgba(0,0,0,0.08);
}
*{box-sizing:border-box;font-family:'Poppins',sans-serif;}
body{margin:0;background:#f3efe9;color:#222;}
.app{display:flex;min-height:100vh;}

/* Sidebar */
.sidebar{width:280px;background:var(--bg-dark);color:#fff;display:flex;flex-direction:column;}
.brand{padding:20px;border-bottom:3px solid rgba(255,255,255,0.07);display:flex;gap:12px;align-items:center;}
.brand img{height:52px;}
.brand .title{display:flex;flex-direction:column;color:var(--nav-cream);}
.brand .title h1{margin:0;font-size:1.05rem;font-weight:700;}
.brand .title p{margin:0;font-size:0.78rem;color:#d6cfc2;}
.nav-list{margin:20px 12px;display:flex;flex-direction:column;gap:12px;}
.nav-item{
  background:var(--nav-cream);color:var(--accent);padding:14px 18px;border-radius:0 20px 20px 0;
  font-weight:600;font-size:0.96rem;display:flex;align-items:center;gap:12px;cursor:pointer;
  transition:transform .12s ease,background-color .12s ease;
}
.nav-item img{width:20px;height:20px;}
.nav-item:hover{transform:translateX(4px);background:#efe7d6;}
.nav-item.active{background:var(--nav-cream-active);box-shadow:0 6px 14px rgba(0,0,0,0.06) ; }
.nav-item {text-decoration: none;}
.sidebar-footer{margin-top:auto;padding:18px;color:#bfb7aa;font-size:0.84rem;}

/* Main */
.main{flex:1;padding:20px 28px;display:flex;flex-direction:column;gap:18px;
  background:linear-gradient(180deg,#f7f3ec 0%,#f3efe9 100%);
}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;background:var(--header-beige);
  padding:14px 18px;border-radius:10px;box-shadow:var(--shadow);}
.header h2{margin:0;font-weight:700;}
.search{flex:1;margin:0 18px;display:flex;align-items:center;background:#fff;padding:10px;border-radius:999px;
  box-shadow:0 3px 8px rgba(0,0,0,0.04);}
.search input{border:0;background:transparent;outline:none;font-size:0.95rem;width:100%;}
.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:3px solid #fff;}

.panel{background:var(--card);border-radius:12px;padding:16px;box-shadow:var(--shadow);}
.panel h3{margin:0 0 12px 0;font-size:1.05rem;font-weight:600;}
.table{width:100%;border-collapse:collapse}
.table thead th{padding:10px 12px;background:#fbfbfb;color:#6b6b6b;text-align:left;font-weight:600;border-bottom:1px solid #eee}
.table td{padding:12px;border-bottom:1px solid #f0f0f0;vertical-align:middle;}
.table img.avatar-xs{width:36px;height:36px;border-radius:50%;object-fit:cover;margin-right:10px;vertical-align:middle}

.badge{display:inline-block;padding:6px 12px;border-radius:999px;font-weight:600;font-size:0.82rem;color:#fff;}
.badge-active{background:var(--status-active)}
.badge-approved{background:var(--status-approved)}
.badge-pending{background:var(--status-pending)}
.badge-expired{background:var(--status-expired)}
.badge-rejected{background:var(--status-rejected)}

.actions{display:flex;gap:8px;align-items:center}
.btn{padding:6px 12px;border-radius:6px;border:0;font-weight:600;cursor:pointer;font-size:0.85rem;}
.btn-view{background:#23412e;color:#fff}
.btn-approve{background:var(--status-approved);color:#fff}
.btn-reject{background:var(--status-rejected);color:#fff}
.btn-edit{background:#2f80ed;color:#fff}
.btn-remove{background:#a83b3b;color:#fff}

.receipt-thumbnail{width:50px;height:50px;object-fit:cover;border-radius:6px;border:1px solid #ddd;cursor:pointer;transition:transform 0.2s;}
.receipt-thumbnail:hover{transform:scale(1.1);}
.receipt-link{text-decoration:none;}

.muted{color:var(--muted);font-size:0.9rem}

@media(max-width:1000px){
  .sidebar{width:68px}
  .nav-item{padding:12px 8px;font-size:0.78rem}
  .nav-item span{display:none}
  .brand .title{display:none}
  .main{padding:12px}
}
</style>
</head>
<body>
<div class="app">
  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="brand">
      <img src="mainpage/logo.svg" alt="VictorianPass logo">
      <div class="title">
        <h1>Admin Dashboard</h1>
        <p>Victorian Heights Subdivision</p>
      </div>
    </div>
    <nav class="nav-list">
       <a href="?page=dashboard" class="nav-item <?php echo $currentPage == 'dashboard' ? 'active' : ''; ?>" data-page="dashboard"><img src="dashboard.svg"><span>Dashboard</span></a>
       <a href="?page=residents" class="nav-item <?php echo $currentPage == 'residents' ? 'active' : ''; ?>" data-page="residents"><img src="dashboard.svg"><span>Residents</span></a>
       <a href="?page=requests" class="nav-item <?php echo $currentPage == 'requests' ? 'active' : ''; ?>" data-page="requests"><img src="dashboard.svg"><span>Resident Requests</span></a>
       <a href="?page=visitor_requests" class="nav-item <?php echo $currentPage == 'visitor_requests' ? 'active' : ''; ?>" data-page="visitor_requests"><img src="dashboard.svg"><span>Visitor Requests</span></a>
       <a href="?page=report" class="nav-item <?php echo $currentPage == 'report' ? 'active' : ''; ?>" data-page="report"><img src="dashboard.svg"><span>View Reported Incidents</span></a>
       <a href="?page=security" class="nav-item <?php echo $currentPage == 'security' ? 'active' : ''; ?>" data-page="security"><img src="dashboard.svg"><span>Security Guards</span></a>
       <a href="?page=verify" class="nav-item <?php echo $currentPage == 'verify' ? 'active' : ''; ?>" data-page="verify"><img src="dashboard.svg"><span>Verify Payment Receipts</span></a>
     </nav>
    <div class="sidebar-footer">
      <a href="?logout=1" style="color:#bfb7aa;text-decoration:none;">Log Out</a>
    </div>
  </aside>

  <!-- MAIN CONTENT -->
  <main class="main">
    <div class="header">
      <?php $pageTitles = [
        'requests' => 'Resident Requests',
        'visitor_requests' => 'Visitor Requests',
        'reservations' => 'Reservations',
        'report' => 'View Reported Incidents',
        'security' => 'Security Guards',
        'verify' => 'Verify Payment Receipts',
        'residents' => 'Residents',
        'dashboard' => 'Dashboard'
      ];
      $pageTitle = $pageTitles[$currentPage] ?? ucfirst($currentPage); ?>
      <h2 id="page-title"><?php echo htmlspecialchars($pageTitle); ?></h2>
      <div class="search"><input id="search-input" placeholder="Search <?php echo htmlspecialchars($pageTitle); ?>..."></div>
      <img class="avatar" src="mainpage/profile'.jpg" alt="admin">
    </div>

<!-- DASHBOARD -->
<?php if ($currentPage == 'dashboard'): ?>
<section class="panel" id="dashboard-panel">
  <h3>Community Overview</h3>
  <div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:12px">
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getResidentCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Residents</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getActivePassesCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Active Passes</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getPendingRequestsCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Pending Requests</div>
    </div>
    <div style="flex:1;min-width:180px;background:#ffffff;border-radius:12px;padding:18px;
                box-shadow:0 8px 18px rgba(0,0,0,0.08);">
      <div style="font-size:1.9rem;font-weight:800;margin:0;"><?php echo getPaymentReceiptsCount($con); ?></div>
      <div style="font-size:0.92rem;color:#8b918d;margin-top:6px">Verified Payment Receipts</div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RESERVATIONS -->
<?php if ($currentPage == 'reservations'): ?>
<section class="panel" id="reservations-panel">
  <style>
    .reservations-row { display: flex; flex-direction: column; gap: 20px; align-items: stretch; max-width: 1100px; margin: 0 auto; }
    .card-box { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 16px; width: 100%; }
    .card-box h3 { margin-top: 0; color: #23412e; }
  </style>
  <div class="reservations-row">
    <div class="card-box">
      <h3>Resident Reservations</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>House #</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $residentRes = getResidentReservations($con);
          $hasRR = false;
          if ($residentRes && $residentRes->num_rows > 0) {
              while ($rr = $residentRes->fetch_assoc()) {
                  $hasRR = true;
                  echo "<tr>";
                  $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                  echo "<td>" . htmlspecialchars($rr['amenity'] ?? '-') . "</td>";
                  $dateRange = (!empty($rr['start_date']) && !empty($rr['end_date'])) ? (date('M d', strtotime($rr['start_date'])) . ' - ' . date('M d, Y', strtotime($rr['end_date']))) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $dateRange . "</td>";
                  $approval_status = $rr['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                  echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                  echo "<td class='actions'>";
                  echo "<button type='button' class='btn btn-view' onclick='showResidentReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'>View Details</button><br>";
                  if ($approval_status == 'pending') {
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                      echo "</form>";

                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                      echo "</form>";
                  } elseif ($approval_status == 'denied') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($rr['approved_by']) ? "by Staff ID " . $rr['approved_by'] : "";
                      $approvalDate = !empty($rr['approval_date']) ? date('M d, Y', strtotime($rr['approval_date'])) : "";
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasRR) {
              echo "<tr><td colspan='6' style='text-align:center;'>No resident reservations found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>

    <div class="card-box">
      <h3>Guest Reservations</h3>
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Amenity</th>
            <th>Dates</th>
            <th>Persons</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $guestRes = getGuestAmenityReservations($con);
          $hasGR = false;
          if ($guestRes && $guestRes->num_rows > 0) {
              while ($gr = $guestRes->fetch_assoc()) {
                  $hasGR = true;
                  echo "<tr>";
                  $fullName = trim(($gr['full_name'] ?? '') . ' ' . ($gr['middle_name'] ?? '') . ' ' . ($gr['last_name'] ?? ''));
                  if ($fullName === '') { $fullName = 'Guest Visitor'; }
                  echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                  echo "<td>" . htmlspecialchars($gr['amenity'] ?? '-') . "</td>";
                  $dateRange = (!empty($gr['start_date']) && !empty($gr['end_date'])) ? (date('M d', strtotime($gr['start_date'])) . ' - ' . date('M d, Y', strtotime($gr['end_date']))) : '<span class=\'muted\'>-</span>';
                  echo "<td>" . $dateRange . "</td>";
                  echo "<td>" . (!empty($gr['persons']) ? intval($gr['persons']) : '-') . "</td>";
                  $approval_status = $gr['approval_status'] ?? 'pending';
                  $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                  echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                  echo "<td class='actions'>";
                  $viewHandler = "showVisitorDetails(" . intval($gr['gf_id'] ?? $gr['id']) . ", 'guest_form')";
                  echo "<button type='button' class='btn btn-view' onclick='$viewHandler' style='margin-bottom: 5px;'>View Details</button><br>";
                  if ($approval_status == 'pending') {
                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='approve_request'>";
                      echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                      echo "</form>";

                      echo "<form method='post' style='display:inline;'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='deny_request'>";
                      echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                      echo "</form>";
                  } elseif ($approval_status == 'denied') {
                      echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                      echo "<input type='hidden' name='reservation_id' value='" . intval($gr['id']) . "'>";
                      echo "<input type='hidden' name='action' value='delete_reservation'>";
                      echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                      echo "</form>";
                  } else {
                      $approvedBy = !empty($gr['approved_by']) ? "by Staff ID " . $gr['approved_by'] : "";
                      $approvalDate = !empty($gr['approval_date']) ? date('M d, Y', strtotime($gr['approval_date'])) : "";
                      echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                  }
                  echo "</td>";
                  echo "</tr>";
              }
          }
          if (!$hasGR) {
              echo "<tr><td colspan='6' style='text-align:center;'>No guest reservations found</td></tr>";
          }
          ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- RESIDENTS -->
<?php if ($currentPage == 'residents'): ?>
<section class="panel" id="residents-panel">
  <h3>Registered Residents</h3>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>House Number</th>
        <th>Email</th>
        <th>Phone</th>
        <th>Registered On</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $residents = getResidents($con);
      if ($residents && $residents->num_rows > 0) {
          while ($resident = $residents->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $resident['first_name'] . " " . $resident['last_name'] . "</td>";
              echo "<td>" . $resident['house_number'] . "</td>";
              echo "<td>" . $resident['email'] . "</td>";
              echo "<td>" . $resident['phone'] . "</td>";
              echo "<td>" . date('M d, Y', strtotime($resident['created_at'])) . "</td>";
              echo "<td class='actions'>";
              // View Details button
              echo "<button type='button' class='btn btn-view' onclick='showUserDetails(" . intval($resident['id']) . ")' style='margin-right:6px;'>View Details</button>";

              // Deactivate/Activate toggle
              $isDisabled = isset($resident['status']) && $resident['status'] === 'disabled';
              echo "<form method='post' style='display:inline;'>";
              echo "<input type='hidden' name='user_id' value='" . intval($resident['id']) . "'>";
              echo "<input type='hidden' name='user_action' value='" . ($isDisabled ? "activate_user" : "deactivate_user") . "'>";
              echo "<button type='submit' class='btn " . ($isDisabled ? "btn-approve" : "btn-reject") . "' style='margin-right:6px;' onclick='return confirm(" . json_encode($isDisabled ? "Reactivate this user?" : "Deactivate this user?") . ")'>" . ($isDisabled ? "Activate" : "Deactivate") . "</button>";
              echo "</form>";

              // Delete button
              echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this account? This cannot be undone.\")'>";
              echo "<input type='hidden' name='user_id' value='" . intval($resident['id']) . "'>";
              echo "<input type='hidden' name='user_action' value='delete_user'>";
              echo "<button type='submit' class='btn btn-remove'>Delete</button>";
              echo "</form>";
              echo "</td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='6' style='text-align:center;'>No residents found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- SECURITY GUARDS -->
<?php if ($currentPage == 'security'): ?>
<section class="panel" id="security-panel">
  <h3>Security Guards on Duty</h3>
  <table class="table">
    <thead>
      <tr>
        <th>ID</th>
        <th>Email</th>
        <th>Role</th>
        <th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $guards = getSecurityGuards($con);
      if ($guards && $guards->num_rows > 0) {
          while ($guard = $guards->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $guard['id'] . "</td>";
              echo "<td>" . $guard['email'] . "</td>";
              echo "<td>" . $guard['role'] . "</td>";
              echo "<td><span class='badge badge-active'>On Duty</span></td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='4' style='text-align:center;'>No security guards found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- REQUESTS -->
<?php if ($currentPage == 'requests'): ?>
<section class="panel" id="requests-panel">
  <style>
    .requests-row { display: flex; flex-direction: column; gap: 20px; align-items: stretch; max-width: 1100px; margin: 0 auto; }
    .card-box { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.06); padding: 16px; width: 100%; }
    .card-box h3 { margin-top: 0; color: #23412e; }
  </style>
  <div class="requests-row">
  <!-- Resident Amenity Requests (from resident_reservations) -->
  <div class="card-box">
    <h3>Resident Amenity Requests</h3>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>House #</th>
          <th>Amenity</th>
          <th>Dates</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $residentRes = getResidentReservations($con);
        $hasRR = false;
        if ($residentRes && $residentRes->num_rows > 0) {
            while ($rr = $residentRes->fetch_assoc()) {
                $hasRR = true;
                echo "<tr>";
                $fullName = trim(($rr['first_name'] ?? '') . ' ' . ($rr['middle_name'] ?? '') . ' ' . ($rr['last_name'] ?? ''));
                echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
                echo "<td>" . htmlspecialchars($rr['house_number'] ?? '-') . "</td>";
                echo "<td>" . htmlspecialchars($rr['amenity'] ?? '-') . "</td>";
                $dateRange = (!empty($rr['start_date']) && !empty($rr['end_date'])) ? (date('M d', strtotime($rr['start_date'])) . ' - ' . date('M d, Y', strtotime($rr['end_date']))) : '<span class=\'muted\'>-</span>';
                echo "<td>" . $dateRange . "</td>";
                $approval_status = $rr['approval_status'] ?? 'pending';
                $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
                echo "<td class='actions'>";
                echo "<button type='button' class='btn btn-view' onclick='showResidentReservationDetails(" . intval($rr['id']) . ")' style='margin-bottom: 5px;'>View Details</button><br>";
                if ($approval_status == 'pending') {
                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='approve_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                    echo "</form>";

                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='deny_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                    echo "</form>";
                } elseif ($approval_status == 'denied') {
                    echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied reservation? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='rr_id' value='" . intval($rr['id']) . "'>";
                    echo "<input type='hidden' name='action' value='delete_resident_reservation'>";
                    echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                    echo "</form>";
                } else {
                    $approvedBy = !empty($rr['approved_by']) ? "by Staff ID " . $rr['approved_by'] : "";
                    $approvalDate = !empty($rr['approval_date']) ? date('M d, Y', strtotime($rr['approval_date'])) : "";
                    echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
        }
        if (!$hasRR) {
            echo "<tr><td colspan='6' style='text-align:center;'>No resident amenity requests found</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
  <div class="card-box">
  <h3>Resident Guest Forms</h3>
  <div style="background:#f2fbf5;border:1px solid #cfe5d3;color:#23412e;padding:10px 12px;border-radius:8px;font-size:0.9rem;margin:8px 0 14px;">
    Guest forms submitted by residents (linked to resident accounts)
  </div>
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>House #</th>
        <th>Amenity</th>
        <th>Purpose of Visit</th>
        <th>Persons</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $residentRequests = getResidentVisitorRequests($con);
      $hasResidentRequests = false;
      if ($residentRequests && $residentRequests->num_rows > 0) {
          while ($req = $residentRequests->fetch_assoc()) {
              $hasResidentRequests = true;
              echo "<tr>";
              // Visitor full name from resident-submitted guest form
              $fullName = trim(($req['full_name'] ?? '') . ' ' . ($req['middle_name'] ?? '') . ' ' . ($req['last_name'] ?? ''));
              echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";

              // Resident House Number
              $houseNo = !empty($req['res_house_number']) ? htmlspecialchars($req['res_house_number']) : '<span class=\'muted\'>-</span>';
              echo "<td>" . $houseNo . "</td>";

              // Amenity: show only if reservation exists; leave blank otherwise
              echo "<td>" . (!empty($req['amenity']) ? htmlspecialchars($req['amenity']) : "") . "</td>";

              // Purpose of Visit: show purpose text from guest_forms
              echo "<td>" . (!empty($req['purpose']) ? htmlspecialchars($req['purpose']) : "") . "</td>";

              // Persons
              echo "<td>" . (!empty($req['persons']) ? intval($req['persons']) : '-') . "</td>";

              // Status (approval_status)
              $approval_status = $req['approval_status'] ?? 'pending';
              $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
              echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";

              // Actions
              echo "<td class='actions'>";
              echo "<button type='button' class='btn btn-view' onclick=\"showVisitorDetails(" . $req['id'] . ", 'guest_form')\" style='margin-bottom: 5px;'>View More Details</button><br>";
              if ($approval_status == 'pending') {
                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                  echo "<input type='hidden' name='action' value='approve_request'>";
                  echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                  echo "</form>";

                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                  echo "<input type='hidden' name='action' value='deny_request'>";
                  echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                  echo "</form>";
              } elseif ($approval_status == 'denied') {
                  echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied request? This cannot be undone.\")'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $req['id'] . "'>";
                  echo "<input type='hidden' name='action' value='delete_reservation'>";
                  echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                  echo "</form>";
              } else {
                  $approvedBy = !empty($req['approved_by']) ? "by Staff ID " . $req['approved_by'] : "";
                  $approvalDate = !empty($req['approval_date']) ? date('M d, Y', strtotime($req['approval_date'])) : "";
                  echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
              }
              echo "</td>";
              echo "</tr>";
          }
      }
      if (!$hasResidentRequests) {
          echo "<tr><td colspan='6' style='text-align:center;'>No resident requests found</td></tr>";
      }
      ?>
    </tbody>
  </table>

  </div>
  </div>
</section>
<?php endif; ?>

<!-- VERIFY PAYMENTS -->
<?php if ($currentPage == 'verify'): ?>
<section class="panel" id="verify-panel">
  <h3>Payment Receipts</h3>
  <table class="table">
    <thead>
      <tr>
        <th>Reference Code</th>
        <th>Amenity</th>
        <th>Reservation Type</th>
        <th>Amount</th>
        <th>Date</th>
        <th>Receipt</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Get reservations with uploaded receipts
      $query = "SELECT * FROM reservations WHERE receipt_path IS NOT NULL AND receipt_path != '' ORDER BY created_at DESC";
      $payments = $con->query($query);
      if ($payments && $payments->num_rows > 0) {
          while ($payment = $payments->fetch_assoc()) {
              echo "<tr>";
              echo "<td>" . $payment['ref_code'] . "</td>";
              echo "<td>" . $payment['amenity'] . "</td>";
              // Reservation Type label
              $typeLabel = !empty($payment['entry_pass_id']) ? 'Visitor Entry Pass' : 'Amenity Reservation';
              echo "<td><span class='badge " . (!empty($payment['entry_pass_id']) ? "badge-warning" : "badge-info") . "'>" . $typeLabel . "</span></td>";
              echo "<td>₱" . number_format($payment['price'], 2) . "</td>";
              echo "<td>" . date('M d, Y', strtotime($payment['created_at'])) . "</td>";
              
              // Receipt preview
              echo "<td>";
              if ($payment['receipt_path']) {
                  echo "<a href='" . $payment['receipt_path'] . "' target='_blank' class='receipt-link'>";
                  echo "<img src='" . $payment['receipt_path'] . "' alt='Receipt' class='receipt-thumbnail'>";
                  echo "</a>";
              } else {
                  echo "<span class='muted'>No receipt</span>";
              }
              echo "</td>";
              
              // Status badge
              $status = isset($payment['payment_status']) ? $payment['payment_status'] : 'pending';
              $statusClass = '';
              switch ($status) {
                  case 'verified': $statusClass = 'badge-approved'; break;
                  case 'rejected': $statusClass = 'badge-rejected'; break;
                  default: $statusClass = 'badge-pending';
              }
              echo "<td><span class='badge $statusClass'>" . ucfirst($status) . "</span></td>";
              
              // Actions
              echo "<td class='actions'>";
              // Always include View Details in payments table
              echo "<button type='button' class='btn btn-view' onclick='" . (!empty($payment['entry_pass_id']) ? "showVisitorDetails(" . $payment['id'] . ", \'reservation\')" : "showReservationDetails(" . $payment['id'] . ")") . "' style='margin-bottom:5px;'>View Details</button><br>";
              if ($status == 'pending') {
                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $payment['id'] . "'>";
                  echo "<input type='hidden' name='action' value='verify_receipt'>";
                  echo "<button type='submit' class='btn btn-approve'>Verify</button>";
                  echo "</form>";
                  
                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $payment['id'] . "'>";
                  echo "<input type='hidden' name='action' value='reject_receipt'>";
                  echo "<button type='submit' class='btn btn-reject'>Reject</button>";
                  echo "</form>";
              } else {
                  echo "<span class='muted'>No actions</span>";
              }
              echo "</td>";
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='7' style='text-align:center;'>No payment receipts found</td></tr>";
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- REPORTS -->
<?php if ($currentPage == 'report'): ?>
<section class="panel" id="report-panel">
  <h3>Reported Incidents</h3>
  <table class="table">
    <thead>
      <tr>
        <th>Reported By</th>
        <th>Nature</th>
        <th>Address</th>
        <th>Date</th>
        <th>Status</th>
        <th>Proofs</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $reports = getIncidentReports($con);
      if ($reports && $reports->num_rows > 0) {
          while ($r = $reports->fetch_assoc()) {
              $fullName = trim(($r['first_name'] ?? '') . ' ' . ($r['middle_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
              $displayName = $fullName !== '' ? $fullName : $r['complainant'];
              echo '<tr>';
              echo '<td>' . htmlspecialchars($displayName) . '</td>';
              echo '<td>' . htmlspecialchars($r['nature'] ?: ($r['other_concern'] ?: '-')) . '</td>';
              echo '<td>' . htmlspecialchars($r['address']) . '</td>';
              echo '<td>' . date('M d, Y', strtotime($r['created_at'])) . '</td>';
              $status = $r['status'];
              $badgeClass = $status === 'resolved' ? 'badge badge-approved' : ($status === 'rejected' ? 'badge badge-rejected' : 'badge badge-warning');
              echo '<td><span class="' . $badgeClass . '">' . ucfirst($status) . '</span></td>';
              // Proofs
              $files = getIncidentProofs($con, intval($r['id']));
              echo '<td>';
              if (count($files) > 0) {
                  foreach ($files as $f) {
                      echo '<a href="' . htmlspecialchars($f) . '" target="_blank">View</a><br/>';
                  }
              } else {
                  echo '<span class="muted">No proofs</span>';
              }
              echo '</td>';
              // Actions
              echo '<td>';
              echo '<form method="POST" style="display:inline-block;margin-right:6px;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              if ($status === 'new') {
                  echo '<input type="hidden" name="incident_action" value="start">';
                  echo '<button type="submit" class="btn btn-view">Start</button>';
              } elseif ($status === 'in_progress') {
                  echo '<input type="hidden" name="incident_action" value="resolve">';
                  echo '<button type="submit" class="btn btn-remove">Resolve</button>';
              }
              echo '</form>';
              echo '<form method="POST" style="display:inline-block;">';
              echo '<input type="hidden" name="report_id" value="' . intval($r['id']) . '">';
              echo '<input type="hidden" name="incident_action" value="reject">';
              echo '<button type="submit" class="btn btn-reject">Reject</button>';
              echo '</form>';
              echo '</td>';
              echo '</tr>';
          }
      } else {
          echo '<tr><td colspan="7" style="text-align:center;">No incidents reported yet</td></tr>';
      }
      ?>
    </tbody>
  </table>
</section>
<?php endif; ?>

<!-- VISITOR REQUESTS -->
<?php if ($currentPage == 'visitor_requests'): ?>
<section class="panel" id="visitor-requests-panel">
  <style>
    .visitor-requests-row { display: flex; flex-direction: column; gap: 20px; align-items: stretch; max-width: 1100px; margin: 0 auto; }
    .vreq-info { background: #f7fdf9; border: 1px solid #d9efe0; color: #23412e; padding: 10px 12px; border-radius: 8px; font-size: 0.9rem; margin: 8px 0 14px; }
  </style>
  <div class="visitor-requests-row">
    <h3>Visitor Requests</h3>
    <div class="vreq-info">Visitor forms without linked resident account</div>
    <table class="table">
      <thead>
        <tr>
          <th>Name</th>
          <th>Amenity</th>
          <th>Request Type</th>
          <th>Persons</th>
          <th>Status Code</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $visitorOnly = getVisitorOnlyRequests($con);
        $hasVisitorOnly = false;
        if ($visitorOnly && $visitorOnly->num_rows > 0) {
            while ($request = $visitorOnly->fetch_assoc()) {
                $hasVisitorOnly = true;
                echo "<tr>";
                // Visitor name
                $fullName = trim(($request['full_name'] ?? '') . ' ' . ($request['middle_name'] ?? '') . ' ' . ($request['last_name'] ?? ''));
                echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";

                // Amenity (often Guest Entry)
                echo "<td>" . (!empty($request['amenity']) ? htmlspecialchars($request['amenity']) : '<span class=\'muted\'>No amenity</span>') . "</td>";

                $reqTypeBadge = "<span class='badge badge-warning'>Visitor Entry Pass</span>";
                echo "<td>$reqTypeBadge</td>";

                // Persons
                echo "<td>" . (!empty($request['persons']) ? intval($request['persons']) : '-') . "</td>";

                // Status Code
                $approval_status = $request['approval_status'] ?? 'pending';
                $statusClass = $approval_status === 'approved' ? 'badge-approved' : ($approval_status === 'denied' ? 'badge-rejected' : 'badge-pending');
                echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";

                // Actions
                echo "<td class='actions'>";
                echo "<button type='button' class='btn btn-view' onclick=\"showVisitorDetails(" . $request['id'] . ", 'reservation')\" style='margin-bottom: 5px;'>View More Details</button><br>";
                if ($approval_status == 'pending') {
                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='approve_request'>";
                    echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                    echo "</form>";

                    echo "<form method='post' style='display:inline;'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='deny_request'>";
                    echo "<button type='submit' class='btn btn-reject'>Deny</button>";
                    echo "</form>";
                } elseif ($approval_status == 'denied') {
                    echo "<form method='post' style='display:inline;' onsubmit='return confirm(\"Delete this denied request? This cannot be undone.\")'>";
                    echo "<input type='hidden' name='reservation_id' value='" . $request['id'] . "'>";
                    echo "<input type='hidden' name='action' value='delete_reservation'>";
                    echo "<button type='submit' class='btn btn-remove'>Delete</button>";
                    echo "</form>";
                } else {
                    $approvedBy = !empty($request['approved_by']) ? "by Staff ID " . $request['approved_by'] : "";
                    $approvalDate = !empty($request['approval_date']) ? date('M d, Y', strtotime($request['approval_date'])) : "";
                    echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
                }
                echo "</td>";
                echo "</tr>";
            }
        }
        if (!$hasVisitorOnly) {
            echo "<tr><td colspan='5' style='text-align:center;'>No visitor requests found</td></tr>";
        }
        ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<!-- Visitor Details Modal -->
<div id="visitorModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; max-height: 85vh; overflow-y: auto; border-radius: 8px;">
    <span class="close" onclick="closeVisitorModal()" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    <h3 style="margin-top: 0; color: #23412e;">Visitor Details</h3>
    <div id="visitorDetailsContent">
      <!-- Content will be loaded here -->
    </div>
  </div>
</div>

<script>
// JavaScript to handle navigation
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('click', function() {
    // Update active class
    document.querySelectorAll('.nav-item').forEach(navItem => {
      navItem.classList.remove('active');
    });
    this.classList.add('active');
    
    // Update page title
    const pageTitle = this.querySelector('span').textContent;
    document.getElementById('page-title').textContent = pageTitle;
    
    // Update search placeholder
    document.getElementById('search-input').placeholder = `Search ${pageTitle}...`;
  });
});

// Function to show visitor details modal
function showVisitorDetails(id, source) {
  // Make AJAX request to get visitor details
  const url = 'admin.php?action=get_visitor_details&id=' + encodeURIComponent(id) + (source? ('&source=' + encodeURIComponent(source)) : '');
  fetch(url)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const details = data.details;
        const isResident = details.user_id && String(details.user_id) !== '0';
        // Update modal title depending on source
        const modalTitleEl = document.querySelector('#visitorModal h3');
        if (modalTitleEl) modalTitleEl.textContent = isResident ? 'Resident Request Details' : 'Visitor Request Details';

        const residentName = [details.res_first_name || '', details.res_middle_name || '', details.res_last_name || ''].join(' ').replace(/\s+/g, ' ').trim();
        const content = isResident
          ? `
          <div style="display: grid; grid-template-columns: 1fr; gap: 12px;">
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Resident Information</h4>
              ${residentName ? `<p><strong>Name:</strong> ${residentName}</p>` : ''}
              ${details.res_house_number ? `<p><strong>House No.:</strong> ${details.res_house_number}</p>` : ''}
              ${details.res_email ? `<p><strong>Email:</strong> ${details.res_email}</p>` : ''}
              ${details.res_phone ? `<p><strong>Contact:</strong> ${details.res_phone}</p>` : ''}
            </div>
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Visitor Information</h4>
              <p><strong>Full Name:</strong> ${details.full_name} ${details.middle_name || ''} ${details.last_name}</p>
              <p><strong>Sex:</strong> ${details.sex || '-'}</p>
              ${details.birthdate ? `<p><strong>Birthdate:</strong> ${new Date(details.birthdate).toLocaleDateString()}</p>` : ''}
              <p><strong>Contact:</strong> ${details.contact || '-'}</p>
              ${details.email ? `<p><strong>Email:</strong> ${details.email}</p>` : ''}
              ${details.valid_id_path ? `<p><strong>Valid ID:</strong> <a href="${details.valid_id_path}" target="_blank" class="btn btn-view">View ID</a></p>` : '<p><strong>Valid ID:</strong> Not uploaded</p>'}
            </div>
            <div>
              <h4 style="color:#23412e;margin-bottom:10px;">Visit Details</h4>
              ${details.start_date ? `<p><strong>Date:</strong> ${new Date(details.start_date).toLocaleDateString()}${details.end_date ? ' - ' + new Date(details.end_date).toLocaleDateString() : ''}</p>` : ''}
              ${details.persons ? `<p><strong>Persons:</strong> ${details.persons}</p>` : ''}
              ${details.purpose ? `<p><strong>Purpose of Visit:</strong> ${details.purpose}</p>` : ''}
              ${details.amenity && details.amenity !== 'Guest Entry' ? `<p><strong>Amenity:</strong> ${details.amenity}</p>` : ''}
              <p><strong>Status:</strong> <span class="badge badge-${details.approval_status || 'pending'}">${(details.approval_status || 'pending').charAt(0).toUpperCase() + (details.approval_status || 'pending').slice(1)}</span></p>
              <p><strong>Request Date:</strong> ${new Date(details.entry_created).toLocaleString()}</p>
              ${details.approved_by ? `<p><strong>Approved By:</strong> Staff ID ${details.approved_by}</p>` : ''}
              ${details.approval_date ? `<p><strong>Approval Date:</strong> ${new Date(details.approval_date).toLocaleString()}</p>` : ''}
            </div>
          </div>
          `
          : `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Personal Information</h4>
              <p><strong>Full Name:</strong> ${details.full_name} ${details.middle_name || ''} ${details.last_name}</p>
              <p><strong>Sex:</strong> ${details.sex}</p>
              <p><strong>Birthdate:</strong> ${new Date(details.birthdate).toLocaleDateString()}</p>
              <p><strong>Contact:</strong> ${details.contact}</p>
              ${details.email ? `<p><strong>Email:</strong> ${details.email}</p>` : ''}
              <p><strong>Address:</strong> ${details.address}</p>
              ${details.valid_id_path ? `<p><strong>Valid ID:</strong> <a href="${details.valid_id_path}" target="_blank" class="btn btn-view">View ID</a></p>` : '<p><strong>Valid ID:</strong> Not uploaded</p>'}
            </div>
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Reservation Details</h4>
              ${details.amenity && details.amenity !== 'Guest Entry' ? `<p><strong>Amenity:</strong> ${details.amenity}</p>` : ''}
              ${details.start_date ? `<p><strong>Date:</strong> ${new Date(details.start_date).toLocaleDateString()}${details.end_date ? ' - ' + new Date(details.end_date).toLocaleDateString() : ''}</p>` : ''}
              ${details.persons ? `<p><strong>Persons:</strong> ${details.persons}</p>` : ''}
              ${details.purpose ? `<p><strong>Purpose of Visit:</strong> ${details.purpose}</p>` : ''}
              ${details.price ? `<p><strong>Price:</strong> ₱${parseFloat(details.price).toLocaleString()}</p>` : ''}
              <p><strong>Request Date:</strong> ${new Date(details.entry_created).toLocaleString()}</p>
              <p><strong>Status:</strong> <span class="badge badge-${details.approval_status || 'pending'}">${(details.approval_status || 'pending').charAt(0).toUpperCase() + (details.approval_status || 'pending').slice(1)}</span></p>
              ${details.approved_by ? `<p><strong>Approved By:</strong> Staff ID ${details.approved_by}</p>` : ''}
              ${details.approval_date ? `<p><strong>Approval Date:</strong> ${new Date(details.approval_date).toLocaleString()}</p>` : ''}
            </div>
          </div>
          `;
        document.getElementById('visitorDetailsContent').innerHTML = content;
        document.getElementById('visitorModal').style.display = 'block';
      } else {
        alert('Error loading visitor details: ' + data.message);
      }
    })
    .catch(error => {
      console.error('Error:', error);
      alert('Error loading visitor details');
    });
}

// Function to close visitor details modal
function closeVisitorModal() {
  document.getElementById('visitorModal').style.display = 'none';
}

// Close modal when clicking outside of it
window.onclick = function(event) {
  const modal = document.getElementById('visitorModal');
  if (event.target == modal) {
    modal.style.display = 'none';
  }
}
</script>

<!-- Reservation Details Modal -->
<div id="reservationModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; max-height: 85vh; overflow-y: auto; border-radius: 8px;">
    <span class="close" onclick="closeReservationModal()" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    <h3 style="margin-top: 0; color: #23412e;">Reservation Details</h3>
    <div id="reservationDetailsContent"></div>
  </div>
</div>

<script>
function showReservationDetails(reservationId){
  fetch('admin.php?action=get_reservation_details&id=' + reservationId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading reservation details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Resident</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Reservation</h4>
            ${d.ref_code?`<p><strong>Ref Code:</strong> ${d.ref_code}</p>`:''}
            ${d.amenity?`<p><strong>Amenity:</strong> ${d.amenity}</p>`:''}
            ${d.start_date?`<p><strong>Start Date:</strong> ${new Date(d.start_date).toLocaleDateString()}</p>`:''}
            ${d.end_date?`<p><strong>End Date:</strong> ${new Date(d.end_date).toLocaleDateString()}</p>`:''}
            ${d.persons?`<p><strong>Persons:</strong> ${d.persons}</p>`:''}
            ${d.price?`<p><strong>Price:</strong> ₱${parseFloat(d.price).toLocaleString()}</p>`:''}
            ${d.created_at?`<p><strong>Requested:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.approval_status?`<p><strong>Status:</strong> <span class="badge badge-${d.approval_status}">${d.approval_status.charAt(0).toUpperCase()+d.approval_status.slice(1)}</span></p>`:''}
            ${d.approved_by?`<p><strong>Approved By:</strong> Staff ID ${d.approved_by}</p>`:''}
            ${d.approval_date?`<p><strong>Approval Date:</strong> ${new Date(d.approval_date).toLocaleString()}</p>`:''}
          </div>
        </div>`;
      document.getElementById('reservationDetailsContent').innerHTML = content;
      document.getElementById('reservationModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading reservation details'); });
}

function closeReservationModal(){
  document.getElementById('reservationModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const rmodal = document.getElementById('reservationModal');
  if(event.target === rmodal){ rmodal.style.display = 'none'; }
});
</script>

<!-- Resident Reservation Details Modal -->
<div id="residentReservationModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; max-height: 85vh; overflow-y: auto; border-radius: 8px;">
    <span class="close" onclick="closeResidentReservationModal()" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    <h3 style="margin-top: 0; color: #23412e;">Resident Reservation Details</h3>
    <div id="residentReservationDetailsContent"></div>
  </div>
</div>

<script>
function showResidentReservationDetails(rrId){
  fetch('admin.php?action=get_resident_reservation_details&id=' + rrId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading resident reservation details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Resident</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Reservation</h4>
            ${d.ref_code?`<p><strong>Ref Code:</strong> ${d.ref_code}</p>`:''}
            ${d.amenity?`<p><strong>Amenity:</strong> ${d.amenity}</p>`:''}
            ${d.start_date?`<p><strong>Start Date:</strong> ${new Date(d.start_date).toLocaleDateString()}</p>`:''}
            ${d.end_date?`<p><strong>End Date:</strong> ${new Date(d.end_date).toLocaleDateString()}</p>`:''}
            ${d.persons?`<p><strong>Persons:</strong> ${d.persons}</p>`:''}
            ${d.created_at?`<p><strong>Requested:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.approval_status?`<p><strong>Status:</strong> <span class="badge badge-${d.approval_status}">${d.approval_status.charAt(0).toUpperCase()+d.approval_status.slice(1)}</span></p>`:''}
            ${d.approved_by?`<p><strong>Approved By:</strong> Staff ID ${d.approved_by}</p>`:''}
            ${d.approval_date?`<p><strong>Approval Date:</strong> ${new Date(d.approval_date).toLocaleString()}</p>`:''}
          </div>
        </div>`;
      document.getElementById('residentReservationDetailsContent').innerHTML = content;
      document.getElementById('residentReservationModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading resident reservation details'); });
}

function closeResidentReservationModal(){
  document.getElementById('residentReservationModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const rmodal2 = document.getElementById('residentReservationModal');
  if(event.target === rmodal2){ rmodal2.style.display = 'none'; }
});
</script>

<!-- User Details Modal -->
<div id="userModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; max-height: 85vh; overflow-y: auto; border-radius: 8px;">
    <span class="close" onclick="closeUserModal()" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>
    <h3 style="margin-top: 0; color: #23412e;">Resident Profile</h3>
    <div id="userDetailsContent"></div>
  </div>
  </div>

<script>
function showUserDetails(userId){
  fetch('admin.php?action=get_user_details&id=' + userId)
    .then(r => r.json())
    .then(data => {
      if(!data.success){ alert('Error loading user details: ' + (data.message||'Unknown error')); return; }
      const d = data.details || {};
      const fullName = [d.first_name||'', d.middle_name||'', d.last_name||''].join(' ').replace(/\s+/g,' ').trim();
      const content = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Personal</h4>
            ${fullName?`<p><strong>Name:</strong> ${fullName}</p>`:''}
            ${d.sex?`<p><strong>Sex:</strong> ${d.sex}</p>`:''}
            ${d.birthdate?`<p><strong>Birthdate:</strong> ${new Date(d.birthdate).toLocaleDateString()}</p>`:''}
            ${d.email?`<p><strong>Email:</strong> ${d.email}</p>`:''}
            ${d.phone?`<p><strong>Phone:</strong> ${d.phone}</p>`:''}
          </div>
          <div>
            <h4 style="color:#23412e;margin-bottom:10px;">Residence</h4>
            ${d.house_number?`<p><strong>House No.:</strong> ${d.house_number}</p>`:''}
            ${d.address?`<p><strong>Address:</strong> ${d.address}</p>`:''}
            ${d.created_at?`<p><strong>Registered:</strong> ${new Date(d.created_at).toLocaleString()}</p>`:''}
            ${d.status?`<p><strong>Status:</strong> ${d.status.charAt(0).toUpperCase()+d.status.slice(1)}</p>`:''}
          </div>
        </div>`;
      document.getElementById('userDetailsContent').innerHTML = content;
      document.getElementById('userModal').style.display = 'block';
    })
    .catch(err => { console.error(err); alert('Error loading user details'); });
}

function closeUserModal(){
  document.getElementById('userModal').style.display = 'none';
}

window.addEventListener('click', function(event){
  const umodal = document.getElementById('userModal');
  if(event.target === umodal){ umodal.style.display = 'none'; }
});
</script>

</main>
</div>
</body>
</html>
