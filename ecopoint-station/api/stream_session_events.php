<?php
require_once __DIR__ . '/../../session_bootstrap.php';
require_once '../../connect.php';

// SSE endpoint: streams ecopoint_session_events for a resident
if (!isset($_GET['resident_id'])) {
    http_response_code(400);
    echo "Resident id required";
    exit;
}
$resident_id = (int)$_GET['resident_id'];

// Ensure the requester is the resident (simple protection)
if (!isset($_SESSION['user_id']) || intval($_SESSION['user_id']) !== $resident_id) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Headers for SSE
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');

@ini_set('output_buffering', 'off');
@ini_set('zlib.output_compression', 0);
set_time_limit(0);

$lastId = 0;
if (isset($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $lastId = intval($_SERVER['HTTP_LAST_EVENT_ID']);
} elseif (isset($_GET['last_id'])) {
    $lastId = intval($_GET['last_id']);
}

// Helper to send an SSE event
function sse_send($id, $data) {
    echo "id: {$id}\n";
    echo "data: {$data}\n\n";
    @ob_flush();
    @flush();
}

// Initial ping
sse_send($lastId, json_encode(['event_type' => 'ping', 'ts' => time()]));

while (!connection_aborted()) {
    try {
        $stmt = $con->prepare("SELECT id, event_type, meta, created_at FROM ecopoint_session_events WHERE resident_id = ? AND id > ? ORDER BY id ASC LIMIT 10");
        if ($stmt) {
            $stmt->bind_param('ii', $resident_id, $lastId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $eid = intval($row['id']);
                $etype = $row['event_type'];
                $meta = $row['meta'];
                $payload = json_encode([
                    'event_type' => $etype,
                    'meta' => $meta !== null ? json_decode($meta, true) : null,
                    'created_at' => $row['created_at']
                ]);
                sse_send($eid, $payload);
                $lastId = $eid;
            }
            $stmt->close();
        }
    } catch (Exception $e) {
        // ignore DB errors and keep connection alive
    }
    // Sleep briefly to avoid busy loop
    sleep(1);
}

exit;
?>