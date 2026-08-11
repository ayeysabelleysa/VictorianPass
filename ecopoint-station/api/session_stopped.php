<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../../connect.php';

$input = json_decode(file_get_contents('php://input'), true);
$response = ['success' => false, 'message' => ''];

if (!isset($input['resident_id'])) {
    $response['message'] = 'resident_id required';
    echo json_encode($response);
    exit;
}

$resident_id = (int)$input['resident_id'];

// Record the stop event
$stmt = $con->prepare("INSERT INTO ecopoint_session_events (resident_id, event_type) VALUES (?, 'stop')");
$stmt->bind_param('i', $resident_id);
$stmt->execute();

// Attempt to finalize the session by using the most recent weight_update event
try {
    // Get latest weight_update for this resident
    $stmt2 = $con->prepare("SELECT id, meta, created_at FROM ecopoint_session_events WHERE resident_id = ? AND event_type = 'weight_update' ORDER BY id DESC LIMIT 1");
    if ($stmt2) {
        $stmt2->bind_param('i', $resident_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        $weight_kg = 0.0;
        $points = 0;
        $material = null;
        if ($row2 = $res2->fetch_assoc()) {
            $meta = json_decode($row2['meta'], true);
            if (is_array($meta)) {
                if (isset($meta['weight_kg'])) $weight_kg = floatval($meta['weight_kg']);
                if (isset($meta['points'])) $points = intval($meta['points']);
                if (isset($meta['material'])) $material = trim($meta['material']);
            }
        }
        $stmt2->close();

        // Check daily session limit (max 3 per day)
        $today = date('Y-m-d');
        $stmtDaily = $con->prepare("SELECT COUNT(*) as daily_sessions FROM ecopoint_sessions WHERE resident_id = ? AND DATE(created_at) = ?");
        $stmtDaily->bind_param('is', $resident_id, $today);
        $stmtDaily->execute();
        $daily_used = $stmtDaily->get_result()->fetch_assoc()['daily_sessions'] ?? 0;
        $stmtDaily->close();
        if ($daily_used >= 3) {
            $response['success'] = false;
            $response['message'] = 'Daily session limit reached';
            echo json_encode($response);
            exit;
        }

        // Compute weekly remaining (max 250)
        $start_of_week = date('Y-m-d', strtotime('monday this week'));
        $stmtWeek = $con->prepare("SELECT COALESCE(SUM(points_earned),0) as total_weekly FROM ecopoint_sessions WHERE resident_id = ? AND created_at >= ?");
        $stmtWeek->bind_param('is', $resident_id, $start_of_week);
        $stmtWeek->execute();
        $weekly_result = $stmtWeek->get_result()->fetch_assoc();
        $weekly_earned = intval($weekly_result['total_weekly'] ?? 0);
        $stmtWeek->close();
        $weekly_remaining = max(0, 250 - $weekly_earned);

        // Clamp points to weekly remaining
        $award_points = max(0, min($points, $weekly_remaining));

        // Insert session and update resident balance in transaction
        $con->begin_transaction();
        try {
            $stmtIns = $con->prepare("INSERT INTO ecopoint_sessions (resident_id, material, weight_kg, points_earned) VALUES (?, ?, ?, ?)");
            $stmtIns->bind_param('isdi', $resident_id, $material, $weight_kg, $award_points);
            $stmtIns->execute();
            $session_id = $con->insert_id;

            // Update resident balance (cap 3000 as before)
            $stmtUpd = $con->prepare("UPDATE residents SET ecopoint_balance = LEAST(ecopoint_balance + ?, 3000) WHERE id = ?");
            $stmtUpd->bind_param('ii', $award_points, $resident_id);
            $stmtUpd->execute();

            // Get new balance
            $stmtNew = $con->prepare("SELECT ecopoint_balance FROM residents WHERE id = ?");
            $stmtNew->bind_param('i', $resident_id);
            $stmtNew->execute();
            $new_balance = $stmtNew->get_result()->fetch_assoc()['ecopoint_balance'] ?? null;

            $con->commit();
            $response['success'] = true;
            $response['message'] = 'Session finalized';
            $response['data'] = ['session_id' => intval($session_id), 'awarded_points' => intval($award_points), 'new_balance' => intval($new_balance)];
        } catch (Exception $e) {
            $con->rollback();
            $response['success'] = false;
            $response['message'] = 'Finalize error: ' . $e->getMessage();
        }
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Error finalizing session: ' . $e->getMessage();
}

echo json_encode($response);
exit;
?>