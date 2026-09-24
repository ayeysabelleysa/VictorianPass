<?php
/**
 * Resident Notifications SSE (Server-Sent Events)
 * =================================================
 * Live push channel for the resident's VictorianPass dashboard
 * (profileresident.php). Whenever a new `notifications` row is
 * inserted for the logged-in resident (e.g. admin approves a
 * reservation), this stream pushes a `snapshot` event within ~1s.
 *
 * - Authenticated by the resident's existing PHP session (same as profile).
 * - Only pushes when the notification set actually CHANGED (no spam frames).
 * - Browser's EventSource auto-reconnects on network drops; the next
 *   snapshot carries the latest state, so nothing is lost while offline.
 * - The existing 15s AJAX poll remains as a fallback; the two channels
 *   share the same dedupe (notification id) on the client, so no
 *   double popups.
 *
 * USAGE IN JS (dashboard):
 *   const es = new EventSource('/VictorianPass/api/notifications_sse.php', { withCredentials: true });
 *   es.addEventListener('snapshot', ev => { const s = JSON.parse(ev.data); apply(s); });
 *   es.onerror = () => { /* browser reconnects on its own * / };
 */
require_once __DIR__ . '/../connect.php';

// SSE headers (required for the browser to keep the connection alive)
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

// Authenticate resident (multiple $_SESSION key fallbacks — the rest of
// VictorianPass may use different key names across routes.)
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

// Release the session file lock BEFORE the long-lived loop. Otherwise this
// open connection would block every other request that needs $_SESSION
// (e.g. the 15s AJAX poll) and can trigger 502/504 gateway timeouts.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// -------------------------------------------------------------------------
// Build a snapshot — same rows/count the resident page already renders via
// `profileresident.php?ajax=1` (getUserNotifications/getUserUnreadCount).
// Returns a hash used to avoid pushing duplicate frames.
// -------------------------------------------------------------------------
function notif_build_snapshot(mysqli $con, int $userId): array {
    $items = [];
    $stmt = $con->prepare("SELECT id, title, message, type, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 20");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $items[] = [
                'id'         => (int)$row['id'],
                'title'      => (string)$row['title'],
                'message'    => (string)$row['message'],
                'type'       => (string)$row['type'],
                'is_read'    => (int)$row['is_read'],
                'created_at' => (string)$row['created_at'],
            ];
        }
        $stmt->close();
    }

    $unreadCount = 0;
    $stmt2 = $con->prepare("SELECT COUNT(*) AS c FROM notifications WHERE user_id = ? AND is_read = 0");
    if ($stmt2) {
        $stmt2->bind_param('i', $userId);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($res2 && ($row2 = $res2->fetch_assoc())) {
            $unreadCount = intval($row2['c'] ?? 0);
        }
        $stmt2->close();
    }

    return [
        'success'        => true,
        'user_id'        => $userId,
        'ts'             => microtime(true),
        'polled_at'      => date('Y-m-d H:i:s'),
        'notifications'  => $items,
        'unread_count'   => $unreadCount,
    ];
}

// Emit an SSE `event: snapshot` data frame
function notif_sse_emit(array $snap): void {
    $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
    echo "event: snapshot\n";
    echo "id: " . (int)(1000 * $snap['ts']) . "\n";
    echo "data: " . $json . "\n\n";
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

// Welcome comment so the browser network tab shows the channel is alive
echo ": welcome notifications SSE resident=$userId\n";
echo "retry: 2000\n\n";
while (ob_get_level() > 0) @ob_end_flush();
flush();

// Main loop: ~1s interval; only push when the snapshot hash changed.
// Max runtime ~5 minutes (PHP max_execution_time typically 300s). After
// that the browser auto-reconnects and a fresh SSE request begins.
$lastHash = '';
$endAt    = time() + 280; // ~4:40 (stays under 300s default limit)
$tickMs   = 1000 * 1000;  // 1000ms in microseconds
do {
    try {
        $snap = notif_build_snapshot($con, $userId);
        // Hash everything except ts/polled_at so duplicate content isn't pushed
        $cmp = $snap; unset($cmp['ts'], $cmp['polled_at']);
        $hash = md5(json_encode($cmp, JSON_UNESCAPED_UNICODE));
        if ($hash !== $lastHash) {
            $lastHash = $hash;
            notif_sse_emit($snap);
        } else {
            // Still send a lightweight keepalive (colon = comment; browsers ignore)
            echo ": keepalive " . date('H:i:s') . "\n";
            while (ob_get_level() > 0) @ob_end_flush();
            flush();
        }
    } catch (Throwable $e) {
        echo ": loop-error: " . $e->getMessage() . "\n";
    }

    // Stop if the client closed the tab (EventSource closes the TCP connection)
    if (connection_aborted()) break;
    if (connection_status() !== CONNECTION_NORMAL) break;

    usleep($tickMs);
} while (time() < $endAt);

// Graceful end — browser's EventSource will reconnect per the retry header
echo "event: end_of_stream\n";
echo "data: {\"reconnect\": true}\n\n";
while (ob_get_level() > 0) @ob_end_flush();
flush();