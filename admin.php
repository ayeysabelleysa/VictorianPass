<?php
session_start();
include 'connect.php';

// Handle AJAX request for visitor details
if (isset($_GET['action']) && $_GET['action'] == 'get_visitor_details' && isset($_GET['id'])) {
    $reservation_id = intval($_GET['id']);
    
    $query = "SELECT r.*, ep.full_name, ep.middle_name, ep.last_name, ep.sex, ep.birthdate, 
                     ep.contact, ep.address, ep.valid_id_path, ep.created_at as entry_created
              FROM reservations r 
              JOIN entry_passes ep ON r.entry_pass_id = ep.id 
              WHERE r.id = ? AND r.entry_pass_id IS NOT NULL";
    
    $stmt = $con->prepare($query);
    $stmt->bind_param("i", $reservation_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result && $row = $result->fetch_assoc()) {
        echo json_encode([
            'success' => true,
            'details' => $row
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Visitor details not found'
        ]);
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
    $query = "SELECT * FROM reservations ORDER BY created_at DESC";
    $result = $con->query($query);
    if ($result) {
        return $result;
    }
    return false;
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

ensureReservationStatusColumn($con);
autoExpireReservations($con);

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Handle visitor request approval/denial
        if ($action == 'approve_request' || $action == 'deny_request') {
            $reservation_id = $_POST['reservation_id'];
            $approval_status = ($action == 'approve_request') ? 'approved' : 'denied';
            $staff_id = $_SESSION['staff_id'] ?? null;
            
            // Update reservation approval status
            $query = "UPDATE reservations SET approval_status = ?, approved_by = ?, approval_date = NOW() WHERE id = ?";
            $stmt = $con->prepare($query);
            $stmt->bind_param("sii", $approval_status, $staff_id, $reservation_id);
            $stmt->execute();
            
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
            
            // Redirect to prevent form resubmission
            header("Location: admin.php?page=requests");
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
       <a href="?page=requests" class="nav-item <?php echo $currentPage == 'requests' ? 'active' : ''; ?>" data-page="requests"><img src="dashboard.svg"><span>Requests</span></a>
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
      <h2 id="page-title"><?php echo ucfirst($currentPage); ?></h2>
      <div class="search"><input id="search-input" placeholder="Search <?php echo ucfirst($currentPage); ?>..."></div>
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
              echo "</tr>";
          }
      } else {
          echo "<tr><td colspan='5' style='text-align:center;'>No residents found</td></tr>";
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
  <h3>All Requests</h3>
  
  <table class="table">
    <thead>
      <tr>
        <th>Request Type</th>
        <th>Reference/Name</th>
        <th>Amenity</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Persons</th>
        <th>Price</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Get amenity reservations (non-visitor requests)
      $reservations = getReservations($con);
      $hasReservations = false;
      if ($reservations && $reservations->num_rows > 0) {
          while ($reservation = $reservations->fetch_assoc()) {
              // Skip visitor requests (those with entry_pass_id)
              if ($reservation['entry_pass_id']) continue;
              
              $hasReservations = true;
              echo "<tr>";
              echo "<td><span class='badge badge-info'>Amenity Reservation</span></td>";
              echo "<td>" . $reservation['ref_code'] . "</td>";
              echo "<td>" . $reservation['amenity'] . "</td>";
              echo "<td>" . date('M d, Y', strtotime($reservation['start_date'])) . "</td>";
              echo "<td>" . date('M d, Y', strtotime($reservation['end_date'])) . "</td>";
              echo "<td>" . $reservation['persons'] . "</td>";
              echo "<td>₱" . number_format($reservation['price'], 2) . "</td>";
              
              // Status badge
              $status = isset($reservation['status']) ? $reservation['status'] : 'pending';
              $statusClass = '';
              switch ($status) {
                  case 'approved': $statusClass = 'badge-approved'; break;
                  case 'rejected': $statusClass = 'badge-rejected'; break;
                  case 'expired': $statusClass = 'badge-expired'; break;
                  default: $statusClass = 'badge-pending';
              }
              echo "<td><span class='badge $statusClass'>" . ucfirst($status) . "</span></td>";
              
              // Actions
              echo "<td class='actions'>";
              if ($status == 'pending') {
                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $reservation['id'] . "'>";
                  echo "<input type='hidden' name='action' value='approve_reservation'>";
                  echo "<button type='submit' class='btn btn-approve'>Approve</button>";
                  echo "</form>";
                  
                  echo "<form method='post' style='display:inline;'>";
                  echo "<input type='hidden' name='reservation_id' value='" . $reservation['id'] . "'>";
                  echo "<input type='hidden' name='action' value='reject_reservation'>";
                  echo "<button type='submit' class='btn btn-reject'>Reject</button>";
                  echo "</form>";
              } else {
                  echo "<span class='muted'>No actions</span>";
              }
              echo "</td>";
              echo "</tr>";
          }
      }
      
      // Get visitor requests
      $visitorRequests = getVisitorRequests($con);
      $hasVisitorRequests = false;
      if ($visitorRequests && $visitorRequests->num_rows > 0) {
          while ($request = $visitorRequests->fetch_assoc()) {
              $hasVisitorRequests = true;
              echo "<tr>";
              echo "<td><span class='badge badge-warning'>Visitor Entry Pass</span></td>";
              
              // Full name
              $fullName = trim($request['full_name'] . ' ' . $request['middle_name'] . ' ' . $request['last_name']);
              echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
              
              // Reserved Amenity
              echo "<td>";
              if ($request['amenity']) {
                  echo htmlspecialchars($request['amenity']);
              } else {
                  echo "<span class='muted'>No amenity</span>";
              }
              echo "</td>";
              
              // Start and End dates
              echo "<td>";
              if ($request['start_date']) {
                  echo date('M d, Y', strtotime($request['start_date']));
              } else {
                  echo "<span class='muted'>-</span>";
              }
              echo "</td>";
              
              echo "<td>";
              if ($request['end_date']) {
                  echo date('M d, Y', strtotime($request['end_date']));
              } else {
                  echo "<span class='muted'>-</span>";
              }
              echo "</td>";
              
              // Persons and Price
              echo "<td>" . ($request['persons'] ? $request['persons'] : '-') . "</td>";
              echo "<td>" . ($request['price'] ? '₱' . number_format($request['price'], 2) : '-') . "</td>";
              
              // Status
              $approval_status = $request['approval_status'] ?? 'pending';
              $statusClass = '';
              switch ($approval_status) {
                  case 'approved': $statusClass = 'badge-approved'; break;
                  case 'denied': $statusClass = 'badge-rejected'; break;
                  default: $statusClass = 'badge-pending';
              }
              echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
              
              // Actions
              echo "<td class='actions'>";
              
              // View Details button for visitor requests
              echo "<button type='button' class='btn btn-view' onclick='showVisitorDetails(" . $request['id'] . ")' style='margin-bottom: 5px;'>View Details</button><br>";
              
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
              } else {
                  $approvedBy = $request['approved_by'] ? "by Staff ID " . $request['approved_by'] : "";
                  $approvalDate = $request['approval_date'] ? date('M d, Y', strtotime($request['approval_date'])) : "";
                  echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
              }
              echo "</td>";
              echo "</tr>";
          }
      }
      
      // Show message if no requests found
      if (!$hasReservations && !$hasVisitorRequests) {
          echo "<tr><td colspan='9' style='text-align:center;'>No requests found</td></tr>";
      }
      ?>
    </tbody>
  </table>
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

<!-- Visitor Details Modal -->
<div id="visitorModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
  <div class="modal-content" style="background-color: #fefefe; margin: 5% auto; padding: 20px; border: 1px solid #888; width: 80%; max-width: 600px; border-radius: 8px;">
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
function showVisitorDetails(reservationId) {
  // Make AJAX request to get visitor details
  fetch('admin.php?action=get_visitor_details&id=' + reservationId)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        const details = data.details;
        const content = `
          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Personal Information</h4>
              <p><strong>Full Name:</strong> ${details.full_name} ${details.middle_name || ''} ${details.last_name}</p>
              <p><strong>Sex:</strong> ${details.sex}</p>
              <p><strong>Birthdate:</strong> ${new Date(details.birthdate).toLocaleDateString()}</p>
              <p><strong>Contact:</strong> ${details.contact}</p>
              <p><strong>Address:</strong> ${details.address}</p>
              ${details.valid_id_path ? `<p><strong>Valid ID:</strong> <a href="${details.valid_id_path}" target="_blank" class="btn btn-view">View ID</a></p>` : '<p><strong>Valid ID:</strong> Not uploaded</p>'}
            </div>
            <div>
              <h4 style="color: #23412e; margin-bottom: 10px;">Reservation Details</h4>
              ${details.amenity ? `<p><strong>Amenity:</strong> ${details.amenity}</p>` : '<p><strong>Amenity:</strong> Not specified</p>'}
              ${details.start_date ? `<p><strong>Start Date:</strong> ${new Date(details.start_date).toLocaleDateString()}</p>` : ''}
              ${details.end_date ? `<p><strong>End Date:</strong> ${new Date(details.end_date).toLocaleDateString()}</p>` : ''}
              ${details.persons ? `<p><strong>Persons:</strong> ${details.persons}</p>` : ''}
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

</main>
</div>
</body>
</html>

 <!-- VISITOR REQUESTS -->
 <?php if ($currentPage == 'visitor_requests'): ?>
 <section class="panel" id="visitor-requests-panel">
   <h3>Visitor Entry Pass Requests</h3>
   <table class="table">
     <thead>
       <tr>
         <th>Visitor Name</th>
         <th>Personal Details</th>
         <th>Contact</th>
         <th>Address</th>
         <th>Valid ID</th>
         <th>Request Date</th>
         <th>Status</th>
         <th>Actions</th>
       </tr>
     </thead>
     <tbody>
       <?php
       $visitorRequests = getVisitorRequests($con);
       if ($visitorRequests && $visitorRequests->num_rows > 0) {
           while ($request = $visitorRequests->fetch_assoc()) {
               echo "<tr>";
               
               // Full name
               $fullName = trim($request['full_name'] . ' ' . $request['middle_name'] . ' ' . $request['last_name']);
               echo "<td><strong>" . htmlspecialchars($fullName) . "</strong></td>";
               
               // Personal details
               echo "<td>";
               echo "<div><strong>Sex:</strong> " . htmlspecialchars($request['sex']) . "</div>";
               echo "<div><strong>Birthdate:</strong> " . date('M d, Y', strtotime($request['birthdate'])) . "</div>";
               echo "</td>";
               
               // Contact
               echo "<td>" . htmlspecialchars($request['contact']) . "</td>";
               
               // Address
               echo "<td>" . htmlspecialchars($request['address']) . "</td>";
               
               // Valid ID
               echo "<td>";
               if ($request['valid_id_path']) {
                   echo "<a href='" . htmlspecialchars($request['valid_id_path']) . "' target='_blank' class='btn btn-view'>View ID</a>";
               } else {
                   echo "<span class='muted'>No ID uploaded</span>";
               }
               echo "</td>";
               
               // Request date
               echo "<td>" . date('M d, Y H:i', strtotime($request['entry_created'])) . "</td>";
               
               // Status
               $approval_status = $request['approval_status'] ?? 'pending';
               $statusClass = '';
               switch ($approval_status) {
                   case 'approved': $statusClass = 'badge-approved'; break;
                   case 'denied': $statusClass = 'badge-rejected'; break;
                   default: $statusClass = 'badge-pending';
               }
               echo "<td><span class='badge $statusClass'>" . ucfirst($approval_status) . "</span></td>";
               
               // Actions
               echo "<td class='actions'>";
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
               } else {
                   $approvedBy = $request['approved_by'] ? "by Staff ID " . $request['approved_by'] : "";
                   $approvalDate = $request['approval_date'] ? date('M d, Y', strtotime($request['approval_date'])) : "";
                   echo "<span class='muted'>" . ucfirst($approval_status) . " $approvedBy<br>$approvalDate</span>";
               }
               echo "</td>";
               echo "</tr>";
           }
       } else {
           echo "<tr><td colspan='8' style='text-align:center;'>No visitor requests found</td></tr>";
       }
       ?>
     </tbody>
   </table>
 </section>
 <?php endif; ?>