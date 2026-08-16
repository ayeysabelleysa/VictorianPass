<?php
/**
 * Resident Session Status Poll API
 * --------------------------------------------------------
 * Called by the resident's own mobile/web profile page to
 * show the live session status when they're at a VHEcoPoint
 * station. Returns null session if there is none active.
 *
 * Authentication: uses the resident's existing PHP session.
 * (Requires the user to be logged in via normal VictorianPass
 *  resident auth — the same as profileresident.php.)
 */
require_once __DIR__ . '/../ecopoint_core.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../session_bootstrap.php';

// Use the same user-id variable the rest of the site uses.
// Fallbacks are common — check multiple likely keys.
$userId = null;
$possible = ['user_id', 'uid', 'userId', 'resident_id'];
foreach ($possible as $k) {
    if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) {
        $userId = (int)$_SESSION[$k];
        break;
    }
}
if ($userId === null) {
    // Try the same include the profile page uses (it sets $userId)
    $rel = __DIR__ . '/../profileresident.php';
    if (file_exists($rel)) {
        // We don't want to include the whole HTML output, so just check globals.
        if (isset($GLOBALS['userId']) && is_numeric($GLOBALS['userId'])) {
            $userId = (int)$GLOBALS['userId'];
        }
    }
}
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

// Return any active session, latest completed session, and cap snapshot
$capState = eco_resident_cap_state($con, $userId);
$session  = eco_get_active_session_for_user($con, $userId);

$sessionOut = null;
if ($session) {
    $sid = (int)$session['id'];
    $stationRow = $con->query("SELECT station_name, location, station_code FROM ecopoint_stations WHERE id = " . (int)$session['station_id'] . " LIMIT 1")->fetch_assoc();
    $events = [];
    $q = $con->query("SELECT id, event_type, event_payload, source, created_at FROM ecopoint_session_events WHERE session_id = $sid ORDER BY id DESC LIMIT 10");
    while ($r = $q && ($row = $q->fetch_assoc())) {
        $events[] = [
            'event_type' => $row['event_type'],
            'payload'    => json_decode((string)$row['event_payload'], true) ?: null,
            'source'     => $row['source'],
            'created_at' => $row['created_at'],
        ];
    }
    $sessionOut = [
        'id'                => (int)$session['id'],
        'status'            => (string)$session['status'],
        'material'          => (string)($session['material_type'] ?? ''),
        'weight_kg'         => (float)($session['weight_kg'] ?? 0),
        'points_calculated' => (int)($session['points_calculated'] ?? 0),
        'points_awarded'    => (int)($session['points_awarded'] ?? 0),
        'created_at'        => (string)($session['created_at'] ?? ''),
        'completed_at'      => $session['completed_at'] ?? null,
        'cancelled_at'      => $session['cancelled_at'] ?? null,
        'error_at'          => $session['error_at'] ?? null,
        'error_message'     => $session['error_message'] ?? null,
        'station'           => $stationRow ? [
            'code' => (string)$stationRow['station_code'],
            'name' => (string)$stationRow['station_name'],
            'location' => (string)($stationRow['location'] ?? ''),
        ] : null,
        'recent_events' => $events,
    ];
}

// Last 5 completed sessions summary (for card footer)
$history = [];
$histQ = $con->prepare("
    SELECT ws.*, s.station_name
    FROM ecopoint_waste_sessions ws
    LEFT JOIN ecopoint_stations s ON s.id = ws.station_id
    WHERE ws.user_id = ?
      AND ws.status IN ('COMPLETED','CANCELLED','ERROR')
    ORDER BY ws.id DESC
    LIMIT 5
");
if ($histQ) {
    $histQ->bind_param('i', $userId);
    $histQ->execute();
    $res = $histQ->get_result();
    while ($row = $res->fetch_assoc()) {
        $history[] = [
            'id'                => (int)$row['id'],
            'status'            => $row['status'],
            'material'          => (string)($row['material_type'] ?? '-'),
            'weight_kg'         => (float)($row['weight_kg'] ?? 0),
            'points_awarded'    => (int)($row['points_awarded'] ?? 0),
            'created_at'        => $row['created_at'],
            'station_name'      => (string)($row['station_name'] ?? ''),
        ];
    }
    $histQ->close();
}

echo json_encode([
    'success'         => true,
    'user_id'         => $userId,
    'polled_at'       => date('Y-m-d H:i:s'),
    'current_balance' => eco_user_balance($con, $userId),
    'cap_state'       => $capState,
    'active_session'  => $sessionOut,
    'recent_sessions' => $history,
], JSON_UNESCAPED_UNICODE);
