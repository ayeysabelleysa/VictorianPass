<?php
/**
 * Resident EcoPoint Session SSE (Server-Sent Events)
 * ====================================================
 * Live push channel for the resident's VictorianPass dashboard.
 * - Authenticated by resident's existing PHP session (same as profile).
 * - Content-Type: text/event-stream; every ~800ms we check the DB
 *   for session / event changes and push a `snapshot` SSE event
 *   only when something changed (no spamming empty frames).
 * - If the resident has no active session, we send an empty snapshot.
 * - Browser's EventSource AUTOMATICALLY reconnects on network drops
 *   (LAN / Wi-Fi blips at VHEcoPoint stations), and the next snapshot
 *   will have the latest state — no data loss!
 *
 * USAGE IN JS (dashboard):
 *   const es = new EventSource('/VictorianPass/api/ecopoint_sse.php', { withCredentials: true });
 *   es.addEventListener('snapshot', ev => { const snap = JSON.parse(ev.data); render(snap); });
 *   es.onerror = () => { /* browser reconnects on its own * / };
 */
require_once __DIR__ . '/../ecopoint_core.php';

// Display label for a stored material_type. The station combines
// Paper and Cardboard into one category, so both map to the same label.
function eco_material_display_label(string $raw): string {
    $hay = strtolower(trim($raw));
    if (strpos($hay, 'plastic') !== false || strpos($hay, 'pet') !== false) return 'Plastic (PET)';
    if (strpos($hay, 'aluminum') !== false || strpos($hay, 'aluminium') !== false || strpos($hay, 'can') !== false) return 'Aluminum Cans';
    if (strpos($hay, 'cardboard') !== false || strpos($hay, 'paper') !== false) return 'Paper & Cardboard';
    return ($raw !== '' && $raw !== '-') ? $raw : '-';
}

// SSE headers (required for browser to keep connection alive)
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // disable Nginx buffering if ever moved there
@ini_set('output_buffering', 0);
@ini_set('zlib.output_compression', 0);
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
while (ob_get_level() > 0) @ob_end_flush();
flush();

require_once __DIR__ . '/../session_bootstrap.php';

// Authenticate resident (exactly like the poll API — multiple $_SESSION key fallbacks
// since the rest of VictorianPass may use different names across routes.)
$userId = null;
$possible = ['user_id', 'uid', 'userId', 'resident_id'];
foreach ($possible as $k) {
    if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k])) { $userId = (int)$_SESSION[$k]; break; }
}
if ($userId === null) {
    echo "event: error\n";
    echo "data: {\"success\":false,\"message\":\"Not authenticated\"}\n\n";
    flush();
    exit;
}

// -------------------------------------------------------------------------
// Build a full snapshot — returns a hash we can compare to avoid pushing
// duplicate frames. Includes: balance, cap_state, active_session (with
// station info + recent events), recent 5 sessions.
// -------------------------------------------------------------------------
function eco_build_snapshot(mysqli $con, int $userId): array {
    $cap      = eco_resident_cap_state($con, $userId);
    $balance  = eco_user_balance($con, $userId);
    $active   = eco_get_active_session_for_user($con, $userId);

    $activeOut = null;
    if ($active) {
        $sid = (int)$active['id'];
        $stationRow = $con->query("SELECT station_name, location, station_code FROM ecopoint_stations WHERE id = " . (int)$active['station_id'] . " LIMIT 1")->fetch_assoc();
        $events = [];
        $q = $con->query("SELECT id, event_type, event_payload, source, created_at FROM ecopoint_session_events WHERE session_id = $sid ORDER BY id DESC LIMIT 12");
        while ($q && ($r = $q->fetch_assoc())) {
            $events[] = [
                'event_type' => (string)$r['event_type'],
                'payload'    => json_decode((string)$r['event_payload'], true) ?: null,
                'source'     => (string)$r['source'],
                'created_at' => (string)$r['created_at'],
            ];
        }
        // Waste breakdown items (WasteTransactionItem table)
        $items = [];
        $q2 = $con->query("SELECT waste_type, material_label, weight_kg, rate_pts_per_kg, points_calculated, points_awarded, created_at FROM ecopoint_waste_items WHERE session_id = $sid ORDER BY id ASC");
        while ($q2 && ($r2 = $q2->fetch_assoc())) { $items[] = $r2; }

        $activeOut = [
            'id'                 => (int)$active['id'],
            'session_token_safe' => substr((string)($active['session_token'] ?? ''), 0, 8) . '…',
            'status'             => (string)($active['status'] ?? ''),
            'material'           => eco_material_display_label((string)($active['material_type'] ?? '')),
            'weight_kg'          => (float)($active['weight_kg'] ?? 0),
            'points_calculated'  => (int)($active['points_calculated'] ?? 0),
            'points_awarded'     => (int)($active['points_awarded'] ?? 0),
            'total_weight_kg'    => (float)($active['total_weight_kg'] ?? 0),
            'total_points'       => (int)($active['total_points'] ?? 0),
            'created_at'         => (string)($active['created_at'] ?? ''),
            'waiting_at'         => $active['waiting_at']   ?? null,
            'started_at'         => $active['started_at']   ?? null,
            'completed_at'       => $active['completed_at'] ?? null,
            'cancelled_at'       => $active['cancelled_at'] ?? null,
            'error_at'           => $active['error_at']     ?? null,
            'error_message'      => $active['error_message'] ?? null,
            'station'            => $stationRow ? [
                'code'     => (string)($stationRow['station_code'] ?? ''),
                'name'     => (string)($stationRow['station_name'] ?? ''),
                'location' => (string)($stationRow['location'] ?? ''),
            ] : null,
            'recent_events' => $events,
            'waste_items'   => $items,
        ];
    }

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
        while ($r = $res->fetch_assoc()) {
            $history[] = [
                'id'              => (int)$r['id'],
                'status'          => (string)$r['status'],
                'material'        => eco_material_display_label((string)($r['material_type'] ?? '-')),
                'weight_kg'       => (float)($r['weight_kg'] ?? 0),
                'points_awarded'  => (int)($r['points_awarded'] ?? 0),
                'created_at'      => (string)$r['created_at'],
                'completed_at'    => $r['completed_at'] ?? null,
                'station_name'    => (string)($r['station_name'] ?? ''),
            ];
        }
        $histQ->close();
    }

    return [
        'success'         => true,
        'user_id'         => $userId,
        'ts'              => microtime(true),
        'polled_at'       => date('Y-m-d H:i:s'),
        'current_balance' => $balance,
        'cap_state'       => $cap,
        'active_session'  => $activeOut,
        'recent_sessions' => $history,
    ];
}

// -------------------------------------------------------------------------
// Helper: emit an SSE `event: snapshot` data frame
// -------------------------------------------------------------------------
function sse_emit_snapshot(array $snap): void {
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    echo "event: snapshot\n";
    echo "id: "  . (int)(1000 * $snap['ts']) . "\n";
    echo "data: " . $json . "\n\n";
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

// Initial welcome frame so browser network tab shows connection is alive
echo ": welcome VHEcoPoint SSE resident=$userId\n";
echo "retry: 2000\n\n";
while (ob_get_level() > 0) @ob_end_flush();
flush();

// Main loop: ~800ms interval; only push if snapshot's JSON hash changed
// Max runtime ~5 minutes (PHP max_execution_time typically 300s). After
// that the browser auto-reconnects and a fresh SSE request begins.
$lastHash = '';
$endAt    = time() + 280; // ~4:40 (stays under 300s default limit)
$tickMs   = 800 * 1000;   // 800ms in microseconds
do {
    try {
        $snap = eco_build_snapshot($con, $userId);
        // Hash everything except ts/polled_at so duplicate content isn't pushed
        $cmp = $snap; unset($cmp['ts'], $cmp['polled_at']);
        $hash = md5(json_encode($cmp, JSON_UNESCAPED_UNICODE));
        if ($hash !== $lastHash) {
            $lastHash = $hash;
            sse_emit_snapshot($snap);
        } else {
            // Still send a lightweight keepalive (colon = comment; browsers ignore)
            echo ": keepalive " . date('H:i:s') . "\n";
            while (ob_get_level() > 0) @ob_end_flush();
            flush();
        }
    } catch (Throwable $e) {
        echo ": loop-error: " . $e->getMessage() . "\n";
    }

    // Check client disconnect (if browser closes tab EventSource closes TCP)
    if (connection_aborted()) break;
    if (connection_status()  !== CONNECTION_NORMAL) break;

    // Sleep ~800ms (microtime for precision)
    usleep($tickMs);
} while (time() < $endAt);

// Graceful end — browser's EventSource will reconnect per retry header
echo "event: end_of_stream\n";
echo "data: {\"reconnect\": true}\n\n";
while (ob_get_level() > 0) @ob_end_flush();
flush();
