<?php
/**
 * cron/amenity_hold_cleanup.php
 * ---------------------------------------------------------------------------
 * Scheduled cleanup for the amenity booking flow. Run it every minute:
 *
 *   CLI  : php cron/amenity_hold_cleanup.php
 *   HTTP : /cron/amenity_hold_cleanup.php?key=<VP_CLEANUP_TOKEN>
 *          (HTTP is also allowed from localhost when no token is configured)
 *
 * What it does:
 *   1. Marks and DELETES slot holds whose window has passed (a hold is
 *      expired the moment expires_at is in the past, regardless of its stored
 *      status), so abandoned holds can never block a slot or linger forever.
 *   2. Expires unpaid bookings past their payment window and cancels their
 *      companion resident requests, which frees the slot in availability,
 *      history and report totals.
 *
 * Kept deliberately free of sessions and page output so it is safe to call
 * from a scheduler without holding a user's session lock.
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $token = (string)(getenv('VP_CLEANUP_TOKEN') ?: '');
    $given  = (string)($_GET['key'] ?? '');
    $local  = in_array(($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1', 'caf:f93b:6b4a:0:0:0:0:1'], true);
    $ok = ($token !== '' && $given !== '' && hash_equals($token, $given))
       || ($token === '' && $local);
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';
require_once __DIR__ . '/reservation_expiry.php';

$result = ['success' => false, 'holds_deleted' => 0, 'bookings_expired' => 0, 'points_refunded' => 0];

if (!($con instanceof mysqli) || $con->connect_errno) {
    $result['message'] = 'Database unavailable';
    if ($isCli) {
        fwrite(STDERR, "amenity_hold_cleanup: database unavailable\n");
        exit(1);
    }
    http_response_code(503);
    echo json_encode($result);
    exit;
}

try {
    if (function_exists('vpCleanupExpiredHolds')) {
        $result['holds_deleted'] = (int)vpCleanupExpiredHolds($con);
    }
    if (function_exists('vpExpireDueReservations')) {
        $expired = vpExpireDueReservations($con, null);
        $result['bookings_expired'] = (int)($expired['count'] ?? 0);
        $result['points_refunded'] = (int)($expired['points'] ?? 0);
    }
    $result['success'] = true;
} catch (Throwable $e) {
    @error_log('[amenity_hold_cleanup] ' . $e->getMessage());
    $result['message'] = 'Cleanup failed';
}

if ($isCli) {
    echo 'amenity_hold_cleanup: holds_deleted=' . $result['holds_deleted']
       . ' bookings_expired=' . $result['bookings_expired']
       . ' points_refunded=' . $result['points_refunded'] . "\n";
    exit($result['success'] ? 0 : 1);
}

echo json_encode($result);
