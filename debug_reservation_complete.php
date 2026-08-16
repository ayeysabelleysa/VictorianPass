<?php
require_once __DIR__ . '/session_bootstrap.php';
require_once 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = intval($_SESSION['user_id']);

?><!DOCTYPE html>
<html>
<head>
    <title>Comprehensive Reservation Time Debug</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: monospace; background: #1e1e1e; color: #d4d4d4; padding: 20px; line-height: 1.6; }
        .container { max-width: 100%; }
        h1 { color: #4ec9b0; margin-bottom: 20px; }
        h2 { color: #569cd6; margin-top: 20px; margin-bottom: 10px; border-bottom: 1px solid #444; padding-bottom: 5px; }
        .section { background: #252526; padding: 15px; margin-bottom: 15px; border-left: 4px solid #007acc; border-radius: 3px; }
        pre { background: #1e1e1e; padding: 12px; border-radius: 3px; overflow-x: auto; font-size: 12px; }
        .error { color: #f48771; }
        .success { color: #6a9955; }
        .warning { color: #dcdcaa; }
        .info { color: #9cdcfe; }
        .data-row { display: flex; gap: 20px; padding: 8px 0; border-bottom: 1px solid #444; }
        .data-label { min-width: 200px; color: #9cdcfe; font-weight: bold; }
        .data-value { color: #ce9178; flex: 1; word-break: break-all; }
        .back-link { display: inline-block; padding: 8px 16px; background: #007acc; color: white; text-decoration: none; border-radius: 3px; margin-bottom: 20px; }
        .back-link:hover { background: #005a9e; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #444; padding: 10px; text-align: left; }
        th { background: #2d2d30; color: #4ec9b0; }
        tr:nth-child(even) { background: #2d2d30; }
        .time-highlight { background: #664444; padding: 2px 6px; border-radius: 2px; }
    </style>
</head>
<body>
<div class="container">
    <a href="profileresident.php" class="back-link">← Back to Profile</a>
    <h1>🔧 Comprehensive Reservation Time Debug</h1>
    
    <div class="section">
        <h2>Session Data</h2>
        <div class="data-row">
            <div class="data-label">User ID:</div>
            <div class="data-value"><?= $userId ?></div>
        </div>
        <div class="data-row">
            <div class="data-label">Pending Reservation in Session:</div>
            <div class="data-value">
                <?php if (isset($_SESSION['pending_reservation'])): ?>
                    <pre><?= htmlspecialchars(json_encode($_SESSION['pending_reservation'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
                <?php else: ?>
                    <span class="warning">(No pending reservation in session)</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Most Recent Reservation in Database</h2>
        <?php
        $query = "SELECT 
            ref_code, 
            amenity,
            start_date, 
            end_date, 
            start_time, 
            end_time,
            persons,
            price,
            payment_status,
            approval_status,
            created_at,
            HEX(start_time) as start_time_hex,
            HEX(end_time) as end_time_hex
        FROM reservations 
        WHERE user_id = ? 
        ORDER BY created_at DESC 
        LIMIT 1";
        
        $stmt = $con->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo '<span class="warning">No reservations found</span>';
        } else {
            $row = $result->fetch_assoc();
            ?>
            <table>
                <tr>
                    <th>Field</th>
                    <th>Value</th>
                    <th>Raw</th>
                </tr>
                <tr>
                    <td>Reference Code</td>
                    <td><?= htmlspecialchars($row['ref_code']) ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Amenity</td>
                    <td><?= htmlspecialchars($row['amenity']) ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Start Date</td>
                    <td><?= htmlspecialchars($row['start_date']) ?></td>
                    <td><code><?= bin2hex($row['start_date']) ?></code></td>
                </tr>
                <tr>
                    <td>End Date</td>
                    <td><?= htmlspecialchars($row['end_date']) ?></td>
                    <td><code><?= bin2hex($row['end_date']) ?></code></td>
                </tr>
                <tr>
                    <td><strong>Start Time</strong></td>
                    <td><span class="time-highlight"><?= htmlspecialchars($row['start_time'] ?? '(NULL)') ?></span></td>
                    <td><code><?= htmlspecialchars($row['start_time_hex'] ?? '(NULL)') ?></code></td>
                </tr>
                <tr>
                    <td><strong>End Time</strong></td>
                    <td>
                        <?php 
                        $endTime = $row['end_time'] ?? null;
                        $isZero = ($endTime === '00:00:00' || $endTime === null);
                        if ($isZero) {
                            echo '<span class="error time-highlight">' . htmlspecialchars($endTime ?? '(NULL)') . '</span>';
                        } else {
                            echo '<span class="success time-highlight">' . htmlspecialchars($endTime) . '</span>';
                        }
                        ?>
                    </td>
                    <td><code><?= htmlspecialchars($row['end_time_hex'] ?? '(NULL)') ?></code></td>
                </tr>
                <tr>
                    <td>Persons</td>
                    <td><?= $row['persons'] ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Price</td>
                    <td>₱<?= number_format($row['price'], 2) ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Payment Status</td>
                    <td><?= htmlspecialchars($row['payment_status']) ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Approval Status</td>
                    <td><?= htmlspecialchars($row['approval_status']) ?></td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Created At</td>
                    <td><?= $row['created_at'] ?></td>
                    <td>-</td>
                </tr>
            </table>
            
            <div style="margin-top: 20px; padding: 12px; background: #2d2d30; border-radius: 3px;">
                <div class="data-row">
                    <div class="data-label">Duration Calculation:</div>
                    <div class="data-value">
                        <?php
                        $st = $row['start_time'];
                        $et = $row['end_time'];
                        if ($st && $et) {
                            $stParts = explode(':', $st);
                            $etParts = explode(':', $et);
                            $stMinutes = intval($stParts[0]) * 60 + intval($stParts[1]);
                            $etMinutes = intval($etParts[0]) * 60 + intval($etParts[1]);
                            $diff = $etMinutes - $stMinutes;
                            if ($diff < 0) {
                                $diff = (24 * 60) - $stMinutes + $etMinutes;
                                echo "Cross-midnight: (24*60 - $stMinutes + $etMinutes) = " . ($diff / 60) . " hours";
                            } else {
                                echo "Same day: ($etMinutes - $stMinutes) / 60 = " . ($diff / 60) . " hours";
                            }
                        } else {
                            echo "Cannot calculate (missing times)";
                        }
                        ?>
                    </div>
                </div>
            </div>
            <?php
        }
        $stmt->close();
        ?>
    </div>

    <div class="section">
        <h2>Last 5 Reservations</h2>
        <?php
        $query = "SELECT ref_code, amenity, start_time, end_time, approval_status, created_at 
                  FROM reservations WHERE user_id = ? 
                  ORDER BY created_at DESC LIMIT 5";
        $stmt = $con->prepare($query);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        ?>
        <table>
            <tr>
                <th>Ref Code</th>
                <th>Amenity</th>
                <th>Start Time</th>
                <th>End Time</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            <?php while ($row = $result->fetch_assoc()): ?>
            <tr>
                <td><code><?= htmlspecialchars($row['ref_code']) ?></code></td>
                <td><?= htmlspecialchars($row['amenity']) ?></td>
                <td><span class="time-highlight"><?= htmlspecialchars($row['start_time'] ?? '-') ?></span></td>
                <td><span class="time-highlight" style="<?= ($row['end_time'] === '00:00:00' ? 'background: #664444;' : '') ?>"><?= htmlspecialchars($row['end_time'] ?? '-') ?></span></td>
                <td><?= htmlspecialchars($row['approval_status']) ?></td>
                <td><?= substr($row['created_at'], 0, 10) ?></td>
            </tr>
            <?php endwhile; ?>
        </table>
        <?php $stmt->close(); ?>
    </div>

    <div class="section">
        <h2>PHP Error Log (Last 30 lines related to this session)</h2>
        <pre><?php
        $logFile = ini_get('error_log');
        if ($logFile && file_exists($logFile)) {
            $lines = array_reverse(file($logFile));
            $count = 0;
            $output = '';
            foreach ($lines as $line) {
                if ($count >= 30) break;
                if (strpos($line, 'DOWNPAYMENT') !== false || 
                    strpos($line, 'RESERVE') !== false || 
                    strpos($line, 'DEBUG') !== false) {
                    $output = $line . $output;
                    $count++;
                }
            }
            echo htmlspecialchars($output ?: "(No matching debug lines found)");
        } else {
            echo "Error log not found at: " . htmlspecialchars($logFile);
        }
        ?></pre>
    </div>
</div>
</body>
</html>
