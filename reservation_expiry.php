<?php
/**
 * Reservation booking-expiry helpers.
 *
 * A booking that was created but never paid for (payment_status IS NULL or
 * 'pending') becomes Expired once its payment window passes. The window is
 * stored in reservations.expires_at and falls back to
 * created_at + vp_downpayment_hold_hours when the column is not deployed yet.
 *
 * Expiring a booking:
 *   - status = 'expired'          -> frees the slot (conflict queries exclude it)
 *   - approval_status = 'cancelled'
 *   - releases any slot hold still tied to the ref
 *   - refunds any VHEcoPoint points redeemed for that booking
 *
 * A booking that already has a receipt on file (payment_status 'submitted',
 * 'verified' or 'rejected') is NEVER touched.
 */

require_once __DIR__ . '/amenity_holds.php';

if (!function_exists('vpReservationExpiryColumnExists')) {
    /**
     * Cached per request. Schema changes are deliberately NOT made during a
     * user request (see the note at the top of reserve.php) - run
     * setup/migrate_reservation_expiry.php instead.
     */
    function vpReservationExpiryColumnExists(mysqli $con): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $r = @$con->query("SHOW COLUMNS FROM reservations LIKE 'expires_at'");
        return $cache = ($r !== false && $r->num_rows > 0);
    }
}

if (!function_exists('vpReservationExpiryReady')) {
    function vpReservationExpiryReady(mysqli $con): bool
    {
        return ($con instanceof mysqli);
    }
}

if (!function_exists('vpReservationExpiryDeadlineSql')) {
    /**
     * SQL fragment that resolves the deadline of a reservation row aliased $alias.
     * Binds one INT (the hold window in hours).
     */
    function vpReservationExpiryDeadlineSql(mysqli $con, string $alias = ''): string
    {
        $p = ($alias !== '') ? ($alias . '.') : '';
        $hours = vpDownpaymentHoldHours($con);
        if (vpReservationExpiryColumnExists($con)) {
            return "COALESCE({$p}expires_at, DATE_ADD({$p}created_at, INTERVAL " . (int)$hours . " HOUR))";
        }
        return "DATE_ADD({$p}created_at, INTERVAL " . (int)$hours . " HOUR)";
    }
}

if (!function_exists('vpRefundReservationPoints')) {
    /**
     * Give back the VHEcoPoints redeemed for an expired booking.
     * Removes the redeem ledger rows (they are what every balance formula
     * reads) and mirrors the original deduction on users.points.
     * Returns the number of points refunded.
     */
    function vpRefundReservationPoints(mysqli $con, array $row): int
    {
        $uid  = (int)($row['user_id'] ?? 0);
        $ref  = (string)($row['ref_code'] ?? '');
        $refund = 0;

        if ($uid > 0 && $ref !== '') {
            $st = $con->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM point_transactions
                  WHERE user_id = ? AND reservation_ref_code = ? AND transaction_type = 'redeem'"
            );
            if ($st) {
                $st->bind_param('is', $uid, $ref);
                if ($st->execute()) {
                    $st->bind_result($sum);
                    if ($st->fetch()) { $refund = (int)$sum; }
                }
                $st->close();
            }
        }

        if ($refund <= 0 && !empty($row['use_points']) && !empty($row['points_used'])) {
            $refund = (int)$row['points_used'];
        }
        if ($refund <= 0) {
            return 0;
        }

        if ($uid > 0 && $ref !== '') {
            $del = $con->prepare(
                "DELETE FROM point_transactions
                  WHERE user_id = ? AND reservation_ref_code = ? AND transaction_type = 'redeem'"
            );
            if ($del) {
                $del->bind_param('is', $uid, $ref);
                $del->execute();
                $del->close();
            }
            $upd = $con->prepare("UPDATE users SET points = points + ? WHERE id = ?");
            if ($upd) {
                $upd->bind_param('ii', $refund, $uid);
                $upd->execute();
                $upd->close();
            }
        }
        return $refund;
    }
}

if (!function_exists('vpReleaseReservationHolds')) {
    /**
     * Free whatever the booking still holds: the row itself already releases
     * the slot to the conflict queries, so this only sweeps live holds.
     */
    function vpReleaseReservationHolds(mysqli $con, string $refCode): void
    {
        if (function_exists('vpExpireHolds')) {
            vpExpireHolds($con);
        }
        if ($refCode === '') {
            return;
        }
        $st = $con->prepare(
            "UPDATE amenity_slot_holds
                SET status = 'released'
              WHERE status = 'held' AND (ref_code = ? OR converted_ref_code = ?)"
        );
        if ($st) {
            $st->bind_param('ss', $refCode, $refCode);
            $st->execute();
            $st->close();
        }
    }
}

if (!function_exists('vpExpireDueReservations')) {
    /**
     * Expire every unpaid reservation whose payment window has passed.
     *
     * When $currentUserId is supplied the refs that belonged to that resident
     * are returned under 'mine' so the caller can redirect them to the Profile
     * popup. Returns ['count'=>int,'points'=>int,'refs'=>[],'mine'=>[]].
     */
    function vpExpireDueReservations($con, ?int $currentUserId = null): array
    {
        $out = ['count' => 0, 'points' => 0, 'refs' => [], 'mine' => []];
        if (!($con instanceof mysqli)) {
            return $out;
        }

        $deadline = vpReservationExpiryDeadlineSql($con, 'r');
        $sql = "SELECT r.id, r.ref_code, r.user_id, r.use_points, r.points_used
                  FROM reservations r
                 WHERE r.status = 'pending'
                   AND r.approval_status = 'pending'
                   AND (r.payment_status IS NULL OR r.payment_status = 'pending')
                   AND r.created_at IS NOT NULL
                   AND {$deadline} <= NOW()";
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return $out;
        }
        if (!$stmt->execute()) {
            $stmt->close();
            return $out;
        }
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        if (!$rows) {
            return $out;
        }

        // Guarded transition: only rows still unpaid and pending can expire, so
        // a receipt submitted a moment ago (or an approved booking) is safe.
        $upd = $con->prepare(
            "UPDATE reservations
                SET status = 'expired', approval_status = 'cancelled', updated_at = NOW()
              WHERE id = ?
                AND status = 'pending'
                AND approval_status = 'pending'
                AND (payment_status IS NULL OR payment_status = 'pending')"
        );
        foreach ($rows as $row) {
            $ok = false;
            if ($upd) {
                $id = (int)$row['id'];
                $upd->bind_param('i', $id);
                if ($upd->execute() && $upd->affected_rows > 0) {
                    $ok = true;
                }
            }
            if (!$ok) {
                continue;
            }
            $ref = (string)($row['ref_code'] ?? '');
            vpReleaseReservationHolds($con, $ref);
            $refunded = vpRefundReservationPoints($con, $row);
            $out['count']++;
            $out['points'] += $refunded;
            if ($ref !== '') { $out['refs'][] = $ref; }
            if ($currentUserId !== null && (int)($row['user_id'] ?? 0) === (int)$currentUserId) {
                $out['mine'][] = $ref;
            }
        }
        if ($upd) {
            $upd->close();
        }
        return $out;
    }
}

if (!function_exists('vpReservationExpiryFlash')) {
    /**
     * Store the one-time Profile popup for the current resident. Reopens the
     * session when the page already released the lock (GET page loads do).
     * Returns the Profile URL the browser should go to.
     */
    function vpReservationExpiryFlash(array $mine): string
    {
        $active = (session_status() === PHP_SESSION_ACTIVE);
        if (!$active) {
            @session_start();
        }
        $_SESSION['booking_expired'] = ['refs' => array_values($mine), 'at' => time()];
        // Restart the stepper at Step 1 on the next visit to the booking page.
        $_SESSION['reserve_clear_client_draft'] = 1;
        if (!$active) {
            @session_write_close();
        }
        return 'profileresident.php?section=panel-requests&booking_expired=1';
    }
}

if (!function_exists('vpReservationExpirySweepAndRedirect')) {
    /**
     * Run the sweep for every unpaid booking and, when the current resident
     * just lost one, store the popup flash and hand back the Profile URL.
     * Call this on a page LOAD. Returns null when nothing happened.
     */
    function vpReservationExpirySweepAndRedirect($con, ?int $userId): ?string
    {
        if (!($con instanceof mysqli) || $userId === null || $userId <= 0) {
            vpExpireDueReservations($con, null);
            return null;
        }
        $res = vpExpireDueReservations($con, $userId);
        if (empty($res['mine'])) {
            return null;
        }
        return vpReservationExpiryFlash($res['mine']);
    }
}

if (!function_exists('vpReservationExpiryGuardRef')) {
    /**
     * POST guard: expire due bookings, then report whether the given reference
     * is now (or was already) expired. Returns a Profile URL when it is.
     */
    function vpReservationExpiryGuardRef($con, ?int $userId, string $refCode): ?string
    {
        if (!($con instanceof mysqli) || $refCode === '') {
            return null;
        }
        vpExpireDueReservations($con, $userId);
        $st = $con->prepare("SELECT status FROM reservations WHERE ref_code = ? LIMIT 1");
        if (!$st) {
            return null;
        }
        $st->bind_param('s', $refCode);
        $expired = false;
        if ($st->execute()) {
            $st->bind_result($status);
            if ($st->fetch() && strtolower(trim((string)$status)) === 'expired') {
                $expired = true;
            }
        }
        $st->close();
        if (!$expired) {
            return null;
        }
        return vpReservationExpiryFlash([$refCode]);
    }
}

if (!function_exists('vpReservationExpiryTakeFlash')) {
    /** Read-and-clear the one-time popup payload on the Profile page. */
    function vpReservationExpiryTakeFlash(): ?array
    {
        if (empty($_SESSION['booking_expired']) || !is_array($_SESSION['booking_expired'])) {
            return null;
        }
        $payload = $_SESSION['booking_expired'];
        unset($_SESSION['booking_expired']);
        $payload['refs'] = array_values(array_filter(array_map('strval', (array)($payload['refs'] ?? []))));
        return $payload;
    }
}
