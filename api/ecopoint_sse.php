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

// Resident-facing material label. Must match residentEcoPointMaterialLabel()
// in profileresident.php so the live-updated tracker rows line up with the
// server-rendered tracker instead of creating a THIRD naming scheme.
function eco_resident_material_label(string $materialType, string $description = ''): string {
    $haystack = strtolower(trim($materialType) . ' ' . $description);
    if (strpos($haystack, 'plastic') !== false || strpos($haystack, 'pet') !== false) return 'Plastic Bottles';
    if (strpos($haystack, 'aluminum') !== false || strpos($haystack, 'aluminium') !== false || strpos($haystack, 'can') !== false) return 'Aluminum Cans';
    if (strpos($haystack, 'cardboard') !== false || strpos($haystack, 'paper') !== false) return 'Paper (Old Documents)';
    return 'Other';
}

// Consolidated dashboard aggregate, mirroring the exact aggregation loops in
// profileresident.php. Shipping it inside the SSE snapshot lets the live JS
// refresh the weekly Material Cap Tracker and the Points History from the SAME
// source of truth as the KPI cards, so those sections can no longer sit stale
// (showing 0) while the balance cards already show the new points.
function eco_build_dashboard_block(mysqli $con, int $userId, array $cap): array {
    $weeklyStats = [
        'Plastic Bottles'       => ['points' => 0, 'weight' => 0.0],
        'Aluminum Cans'         => ['points' => 0, 'weight' => 0.0],
        'Paper (Old Documents)' => ['points' => 0, 'weight' => 0.0],
    ];
    $week    = eco_week_bounds();
    $wStart  = (string)$week['week_start_date'];
    $wEnd    = (string)$week['week_end_date'];
    $today   = date('Y-m-d');
    $weeklyPoints = 0;
    $todayPoints  = 0;
    $history = [];

    $stmt = $con->prepare("
        SELECT transaction_type, amount, description, material_type, weight_kg, created_at
        FROM point_transactions
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 200
    ");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = strtolower(trim((string)($row['transaction_type'] ?? '')));
            if ($type !== 'earn' && $type !== 'redeem') continue;
            $createdAt = (string)($row['created_at'] ?? '');
            $ts = $createdAt !== '' ? strtotime($createdAt) : false;
            if ($ts === false || $ts <= 0) $ts = time();
            $dateKey  = date('Y-m-d', $ts);
            $amount   = intval($row['amount'] ?? 0);
            $label    = eco_resident_material_label((string)($row['material_type'] ?? ''), (string)($row['description'] ?? ''));
            $weightKg = floatval($row['weight_kg'] ?? 0);
            if ($type !== 'earn') continue;

            $history[] = [
                'type'           => 'earn',
                'amount'         => $amount,
                'date_ts'        => $ts,
                'date_label'     => date('M j, Y', $ts),
                'time_label'     => date('g:i A', $ts),
                'description'    => (string)($row['description'] ?? ''),
                'material_label' => $label,
                'weight_kg'      => $weightKg,
            ];
            if ($dateKey >= $wStart && $dateKey <= $wEnd) {
                $weeklyPoints += $amount;
                if (isset($weeklyStats[$label])) {
                    $weeklyStats[$label]['points'] += $amount;
                    $weeklyStats[$label]['weight'] += $weightKg;
                }
            }
            if ($dateKey === $today) $todayPoints += $amount;
        }
        $stmt->close();
    }
    usort($history, function ($a, $b) { return $b['date_ts'] <=> $a['date_ts']; });

    if (!isset($cap['weekly_points_used'])) $cap['weekly_points_used'] = $weeklyPoints;
    if (!isset($cap['daily_points_used']))  $cap['daily_points_used']  = $todayPoints;
    $weeklyUsed   = (int)round((float)$cap['weekly_points_used']);
    $todayUsed    = (int)round((float)$cap['daily_points_used']);
    $sessionsUsed = (int)($cap['daily_sessions_used'] ?? 0);

    return [
        'current_points'     => (int)round((float)eco_user_balance($con, $userId)),
        'weekly_points'      => $weeklyUsed,
        'weekly_values'      => $weeklyStats,
        'today_points'       => $todayUsed,
        'today_sessions_used'=> $sessionsUsed,
        'history'            => $history,
        'weekly_remaining'   => max(0, (int)ECO_WEEKLY_POINT_CAP - $weeklyUsed),
        'weekly_progress'    => (int)ECO_WEEKLY_POINT_CAP > 0 ? min(100, (int)round(($weeklyUsed / (int)ECO_WEEKLY_POINT_CAP) * 100)) : 0,
        'daily_remaining'    => max(0, (int)ECO_DAILY_POINT_CAP - $todayUsed),
        'sessions_remaining' => max(0, (int)ECO_DAILY_SESSION_CAP - $sessionsUsed),
    ];
}

// SSE headers (required for browser to keep connection alive).
// NOTE: header() only QUEUES a header — nothing is sent to the client yet,
// so the PHP session can still be started below. The actual send happens
// after authentication (see the flush() after session_write_close()).
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Accel-Buffering: no'); // disable Nginx buffering if ever moved there
@ini_set('output_buffering', 0);
@ini_set('zlib.output_compression', 0);
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');

// This stream is read-only: it never writes to $_SESSION. Start the session
// read-only (same pattern as api/notifications_sse.php) so no session file
// lock is kept while we stream for minutes.
define('VP_SESSION_READONLY', true);

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

// Release the session file lock BEFORE any output and BEFORE the long-lived
// loop. Otherwise this open connection would block every other request that
// needs $_SESSION (e.g. the resident's "End Session" POST to
// api/ecopoint_end_session.php) and can trigger 502/504 gateway timeouts.
// Same safe pattern as api/notifications_sse.php.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Authentication + lock release are done — now commit the SSE headers and
// start streaming.
while (ob_get_level() > 0) @ob_end_flush();
flush();

// -------------------------------------------------------------------------
// Build a full snapshot — returns a hash we can compare to avoid pushing
// duplicate frames. Includes: balance, cap_state, active_session (with
// station info + recent events), recent 5 sessions.
// -------------------------------------------------------------------------
function eco_build_snapshot(mysqli $con, int $userId): array {
    $cap      = eco_resident_cap_state($con, $userId);
    $balance  = eco_user_balance($con, $userId);
    $active   = eco_get_active_session_for_user($con, $userId);

$liveTotalPoints = 0;
$liveTotalWeight = 0.0;

if ($active) {
    $liveStmt = $con->prepare("
        SELECT
            COALESCE(SUM(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(event_payload, '$.points_calc')) AS DECIMAL(10,2))
            ), 0) AS total_points,
            COALESCE(SUM(
                CAST(JSON_UNQUOTE(JSON_EXTRACT(event_payload, '$.weight_kg')) AS DECIMAL(10,3))
            ), 0) AS total_weight
        FROM ecopoint_session_events
        WHERE session_id = ?
          AND event_type = 'WASTE_DATA'
    ");

    if ($liveStmt) {
        $liveStmt->bind_param('i', $active['id']);
        $liveStmt->execute();
        $liveTotals = $liveStmt->get_result()->fetch_assoc();
        $liveStmt->close();

        $liveTotalPoints = round((float)($liveTotals['total_points'] ?? 0), 2);
        $liveTotalWeight = (float)($liveTotals['total_weight'] ?? 0);
    }
}

// Projected ELIGIBLE points for the items accumulated so far. The raw live
// total is run through the SAME cap rules used at finalization
// (eco_apply_cap_rules: daily cap, weekly cap, max-balance room), so the
// live panel cannot promise more than the resident can actually receive.
// `total_points` stays the raw calculation on purpose; the dashboard JS uses
// `estimated_points` for the headline number and shows the raw value as
// pre-cap context.
$estimatedPoints = 0.0;
if ($active && $liveTotalPoints > 0) {
    $projCalc = [
        'material'        => (string)($active['material_type'] ?? ''),
        'weight_kg'       => $liveTotalWeight,
        'rate_pts_per_kg' => (float)(ECO_MATERIAL_RATES[(string)($active['material_type'] ?? '')] ?? 0),
        'raw_points'      => $liveTotalPoints,
        'valid'           => true,
    ];
    $estimatedPoints = round((float)eco_apply_cap_rules($projCalc, $cap, $balance), 2);
}

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

        $matDisplay = eco_build_session_material_display_from_events($events, (string)($active['material_type'] ?? ''));
        $activeOut = [
            'id'                 => (int)$active['id'],
            'session_token_safe' => substr((string)($active['session_token'] ?? ''), 0, 8) . '…',
            'status'             => (string)($active['status'] ?? ''),
            'material'           => $matDisplay['label'] === '—' ? ($matDisplay['materials'][0] ?? '—') : ($matDisplay['label'] === 'Mixed' ? $matDisplay['materials'][0] ?? 'Mixed' : $matDisplay['label']),
            'material_label'     => $matDisplay['label'],
            'material_list'      => $matDisplay['materials'],
            'material_display'   => $matDisplay['hasMultiple'] ? ('Mixed') : $matDisplay['label'],
            'weight_kg'          => (float)($active['weight_kg'] ?? 0),
            'points_calculated'  => (float)($active['points_calculated'] ?? 0),
            'points_awarded'     => (float)($active['points_awarded'] ?? 0),
            'points_raw'         => $liveTotalPoints,
            'estimated_points'   => $estimatedPoints,
            'total_weight_kg'    => $liveTotalWeight,
            'total_points'       => $liveTotalPoints,
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
            $eventsH = [];
            $evQ = $con->query("SELECT id, event_type, event_payload, source, created_at FROM ecopoint_session_events WHERE session_id = " . (int)$r['id'] . " AND event_type='WASTE_DATA' ORDER BY id ASC LIMIT 50");
            while ($evQ && ($erv = $evQ->fetch_assoc())) {
                $eventsH[] = ['payload' => $erv['event_payload']];
            }
            if ($evQ) $evQ->close();
            $matDisplayH = eco_build_session_material_display_from_events($eventsH, (string)($r['material_type'] ?? '-'));
            $history[] = [
                'id'              => (int)$r['id'],
                'status'          => (string)$r['status'],
                'material'        => $matDisplayH['label'],
                'material_list'   => $matDisplayH['materials'],
                'weight_kg'       => (float)$r['weight_kg'] ?? 0,
                'points_awarded'  => (float)$r['points_awarded'] ?? 0,
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
        'dashboard'       => eco_build_dashboard_block($con, $userId, $cap),
        'active_session'  => $activeOut,
        'recent_sessions' => $history,
        'points_limit_reached' => ((float)$balance >= (float)ECO_MAX_BALANCE),
        'points_limit_message' => ((float)$balance >= (float)ECO_MAX_BALANCE) ? ECO_POINTS_LIMIT_MESSAGE : '',
        'points_max_balance'   => (float)ECO_MAX_BALANCE,
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
