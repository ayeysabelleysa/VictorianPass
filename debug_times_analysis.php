<?php
session_start();
require_once 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);
$userType = $_SESSION['user_type'];

echo '<!DOCTYPE html>
<html>
<head>
    <title>Reservation Time Debug Analysis</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #333; margin-bottom: 20px; text-align: center; }
        .back-link { display: inline-block; margin-bottom: 15px; padding: 8px 16px; background: #e0e0e0; border-radius: 4px; text-decoration: none; color: #333; }
        .back-link:hover { background: #d0d0d0; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .section h2 { color: #2c3e50; font-size: 18px; margin-bottom: 15px; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background: #3498db; color: white; font-weight: 600; }
        tr:nth-child(even) { background: #f9f9f9; }
        tr:hover { background: #f0f0f0; }
        .time-cell { font-family: monospace; font-weight: bold; background: #fffacd; padding: 8px; border-radius: 3px; }
        .error { color: #e74c3c; font-weight: bold; }
        .warning { color: #f39c12; font-weight: bold; }
        .success { color: #27ae60; font-weight: bold; }
        .info-box { background: #ecf0f1; padding: 12px; border-left: 4px solid #3498db; margin: 10px 0; border-radius: 3px; }
        .duration-calc { background: #e8f4f8; padding: 12px; border-radius: 4px; margin: 10px 0; font-family: monospace; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-family: monospace; }
    </style>
</head>
<body>
<div class="container">
    <a href="profileresident.php" class="back-link">← Back to Profile</a>
    <h1>🔍 Reservation Time Debug Report</h1>
    <div class="info-box">
        <strong>Purpose:</strong> This page shows exactly what times are stored in your database and how they are being calculated.
    </div>';

// Get all reservations for this user
$query = "SELECT 
    ref_code, 
    amenity, 
    start_date, 
    end_date, 
    start_time, 
    end_time, 
    approval_status, 
    created_at,
    CAST(start_time AS CHAR) as start_time_text,
    CAST(end_time AS CHAR) as end_time_text
FROM reservations 
WHERE user_id = ? 
ORDER BY created_at DESC 
LIMIT 20";

$stmt = $con->prepare($query);
if (!$stmt) {
    echo '<div class="section"><div class="error">Error preparing query: ' . htmlspecialchars($con->error) . '</div></div>';
} else {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo '<div class="section"><div class="info-box">No reservations found.</div></div>';
    } else {
        echo '<div class="section">
            <h2>Your Recent Reservations (' . $result->num_rows . ' total)</h2>
            <table>
                <thead>
                    <tr>
                        <th>Ref Code</th>
                        <th>Amenity</th>
                        <th>Dates</th>
                        <th>Stored Times (DB)</th>
                        <th>Duration Analysis</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>';
        
        while ($row = $result->fetch_assoc()) {
            $refCode = htmlspecialchars($row['ref_code']);
            $amenity = htmlspecialchars($row['amenity']);
            $startDate = $row['start_date'];
            $endDate = $row['end_date'];
            $startTime = $row['start_time_text'];
            $endTime = $row['end_time_text'];
            $status = htmlspecialchars($row['approval_status']);
            $created = $row['created_at'];
            
            // Parse times
            $startTimeParts = explode(':', $startTime);
            $endTimeParts = explode(':', $endTime);
            $startHour = intval($startTimeParts[0] ?? 0);
            $startMin = intval($startTimeParts[1] ?? 0);
            $endHour = intval($endTimeParts[0] ?? 0);
            $endMin = intval($endTimeParts[1] ?? 0);
            
            // Calculate duration
            $startMins = $startHour * 60 + $startMin;
            $endMins = $endHour * 60 + $endMin;
            $diffMins = $endMins - $startMins;
            if ($diffMins < 0) {
                $diffMins = (24 * 60) - $startMins + $endMins;
            }
            $hours = $diffMins / 60;
            
            // Format for display
            $startDisplay = str_pad($startHour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($startMin, 2, '0', STR_PAD_LEFT);
            $endDisplay = str_pad($endHour, 2, '0', STR_PAD_LEFT) . ':' . str_pad($endMin, 2, '0', STR_PAD_LEFT);
            $startDisplay12 = formatTo12Hour($startHour, $startMin);
            $endDisplay12 = formatTo12Hour($endHour, $endMin);
            $dateDisplay = $startDate . ($endDate !== $startDate ? ' to ' . $endDate : '');
            
            $durationAnalysis = "
<div class='duration-calc'>
<strong>24-hour format:</strong> $startDisplay to $endDisplay<br>
<strong>12-hour format:</strong> $startDisplay12 to $endDisplay12<br>
<strong>Calculation:</strong> " . ($diffMins < 0 ? 'Cross-midnight' : 'Same day') . "<br>
<strong>Duration:</strong> <span class='success'>" . number_format($hours, 1) . ' hours</span><br>
<strong>Minutes:</strong> ' . $diffMins . " min
</div>";
            
            echo "<tr>
                <td><code>$refCode</code></td>
                <td>$amenity</td>
                <td>$dateDisplay</td>
                <td><span class='time-cell'>$startDisplay → $endDisplay</span></td>
                <td>$durationAnalysis</td>
                <td>$status</td>
                <td>" . substr($created, 0, 10) . "</td>
            </tr>";
        }
        
        echo '</tbody>
            </table>
        </div>';
    }
    
    $stmt->close();
}

// Add a section for expected vs actual comparison
echo '<div class="section">
    <h2>📋 How to Verify Your Times Are Correct</h2>
    <div class="info-box">
        <p><strong>Step 1:</strong> Click on a reservation in the "View Details" modal</p>
        <p><strong>Step 2:</strong> Check the "Time & Hours" displayed</p>
        <p><strong>Step 3:</strong> Compare with the table above</p>
        <p><strong>Step 4:</strong> Tell us if the duration matches what you expected!</p>
    </div>
    
    <h3 style="margin-top: 20px; color: #2c3e50;">Expected vs Actual Examples:</h3>
    <table>
        <tr>
            <th>If you selected...</th>
            <th>Database should show...</th>
            <th>Duration should be...</th>
        </tr>
        <tr>
            <td>9:00 AM start + 3 hours</td>
            <td><span class="time-cell">09:00 → 12:00</span></td>
            <td><span class="success">3.0 hours</span></td>
        </tr>
        <tr>
            <td>2:00 PM start + 4 hours</td>
            <td><span class="time-cell">14:00 → 18:00</span></td>
            <td><span class="success">4.0 hours</span></td>
        </tr>
        <tr>
            <td>8:00 PM start + 3 hours (crosses midnight)</td>
            <td><span class="time-cell">20:00 → 23:00</span></td>
            <td><span class="success">3.0 hours</span></td>
        </tr>
    </table>
</div>';

echo '</div>
</body>
</html>';

function formatTo12Hour($hour, $min) {
    $ampm = $hour >= 12 ? 'PM' : 'AM';
    $h = $hour % 12;
    if ($h === 0) $h = 12;
    return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT) . ' ' . $ampm;
}
?>
