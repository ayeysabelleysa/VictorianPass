<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';

// Redirect if not authenticated
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$user_type = $_SESSION['user_type'];

?>
<!DOCTYPE html>
<html>
<head>
    <title>Debug - Check Saved Reservation Times</title>
    <style>
        body { font-family: Arial; margin: 20px; }
        table { border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        th { background: #f0f0f0; }
        .time-cell { font-weight: bold; background: #fffacd; }
    </style>
</head>
<body>
    <h1>Debug: Reservation Times</h1>
    <p>This shows exactly what's stored in your database for recent reservations.</p>
    
    <table>
        <tr>
            <th>Ref Code</th>
            <th>Amenity</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Start Time (Raw DB)</th>
            <th>End Time (Raw DB)</th>
            <th>Duration Calc</th>
            <th>Status</th>
        </tr>
        <?php
        $stmt = $con->prepare("SELECT ref_code, amenity, start_date, end_date, start_time, end_time, approval_status, created_at FROM reservations WHERE user_id = ? ORDER BY created_at DESC LIMIT 10");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            
            while ($row = $res->fetch_assoc()) {
                $startTime = $row['start_time'] ?? '';
                $endTime = $row['end_time'] ?? '';
                
                // Calculate duration
                $duration = '';
                if ($startTime && $endTime) {
                    $start_parts = explode(':', $startTime);
                    $end_parts = explode(':', $endTime);
                    $start_mins = intval($start_parts[0]) * 60 + intval($start_parts[1]);
                    $end_mins = intval($end_parts[0]) * 60 + intval($end_parts[1]);
                    $diff = $end_mins - $start_mins;
                    if ($diff < 0) {
                        $diff = (24 * 60) - $start_mins + $end_mins;
                    }
                    $hours = $diff / 60;
                    $duration = number_format($hours, 1) . ' hrs';
                }
                
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['ref_code']) . '</td>';
                echo '<td>' . htmlspecialchars($row['amenity']) . '</td>';
                echo '<td>' . htmlspecialchars($row['start_date']) . '</td>';
                echo '<td>' . htmlspecialchars($row['end_date']) . '</td>';
                echo '<td class="time-cell">' . htmlspecialchars($startTime) . '</td>';
                echo '<td class="time-cell">' . htmlspecialchars($endTime) . '</td>';
                echo '<td>' . htmlspecialchars($duration) . '</td>';
                echo '<td>' . htmlspecialchars($row['approval_status']) . '</td>';
                echo '</tr>';
            }
            
            $stmt->close();
        }
        ?>
    </table>
    
    <p><a href="profileresident.php">← Back to Profile</a></p>
</body>
</html>
