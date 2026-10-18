<?php
/**
 * amenity_holds.php
 * ---------------------------------------------------------------------------
 * Race-safe amenity booking primitives for VictorianPass.
 *
 * Adds three logical layers on top of the existing (and untouched) reservation
 * tables so that a slot can be temporarily HELD, WAITLISTED, or reserved while
 * preventing double bookings even under concurrent requests.
 *
 *   amenity_slot_holds   -> short-lived temporary holds (default 10 minutes)
 *   amenity_waitlist     -> FIFO queue for a taken slot
 *   amenity_slot_locks   -> per (amenity|date) resource rows used for
 *                           SELECT ... FOR UPDATE serialization
 *   app_settings         -> small key/value config (hold_minutes, ...)
 *
 * The library never mutates the existing reservation rows; it only reads them.
 *
 * Concurrency model
 * -----------------
 * Any operation that can create a blocking row (a hold or a reservation):
 *   1. Expires stale holds.
 *   2. Ensures a resource-lock row exists for each amenity|date (autocommit,
 *      INSERT IGNORE) BEFORE the transaction starts.
 *   3. Opens a READ COMMITTED transaction and locks those rows via
 *      SELECT ... FOR UPDATE (ordered by key to avoid deadlocks).
 *   4. Re-checks for conflicts against committed data (holds + reservations)
 *      and aborts if a conflict appeared while waiting for the lock.
 *   5. Writes its row and commits (releasing the lock).
 *
 * This makes the check-then-insert atomic: two residents racing for the same
 * slot are serialized, and exactly one wins; the loser gets a conflict and is
 * offered the waitlist.
 */

if (!function_exists('vpAmenityHoldsEnsureSchema')) {
    /**
     * Idempotent schema creation. Runs at most once per deployment thanks to the
     * schema_flags helper from connect.php.
     */
    function vpAmenityHoldsEnsureSchema(mysqli $con): void
    {
        $v1Done = function_exists('vpSchemaDone') && vpSchemaDone($con, 'amenity_holds_v1');
        $v2Done = function_exists('vpSchemaDone') && vpSchemaDone($con, 'amenity_holds_v2');
        $v3Done = function_exists('vpSchemaDone') && vpSchemaDone($con, 'amenity_holds_v3');
        $con->query("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL COMMENT 'For residents',
            entry_pass_id INT NULL COMMENT 'For visitors',
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
            INDEX idx_user_id (user_id),
            INDEX idx_is_read (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if ($v1Done && $v2Done && $v3Done) {
            return;
        }

        $con->query("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
            setting_value VARCHAR(255) NOT NULL,
            updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $con->query("CREATE TABLE IF NOT EXISTS amenity_slot_locks (
            lock_key   VARCHAR(191) NOT NULL PRIMARY KEY,
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $con->query("CREATE TABLE IF NOT EXISTS amenity_slot_holds (
            id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            hold_token         CHAR(32)        NOT NULL,
            ref_code           VARCHAR(64)     DEFAULT NULL,
            amenity            VARCHAR(64)     NOT NULL,
            start_date         DATE            NOT NULL,
            end_date           DATE            NOT NULL,
            start_time         TIME            DEFAULT NULL,
            end_time           TIME            DEFAULT NULL,
            user_id            INT             DEFAULT NULL,
            entry_pass_id      INT             DEFAULT NULL,
            account_type       ENUM('resident','visitor') NOT NULL DEFAULT 'visitor',
            owner_key          VARCHAR(80)     NOT NULL,
            status             ENUM('held','converted','released','expired') NOT NULL DEFAULT 'held',
            converted_ref_code VARCHAR(64)     DEFAULT NULL,
            created_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at         DATETIME        NOT NULL,
            updated_at         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_hold_token (hold_token),
            KEY idx_hold_slot (amenity, start_date, end_date, status),
            KEY idx_hold_owner (owner_key, status),
            KEY idx_hold_user (user_id, status),
            KEY idx_hold_expires (status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $con->query("CREATE TABLE IF NOT EXISTS amenity_waitlist (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            amenity         VARCHAR(64)     NOT NULL,
            start_date      DATE            NOT NULL,
            end_date        DATE            NOT NULL,
            start_time      TIME            DEFAULT NULL,
            end_time        TIME            DEFAULT NULL,
            user_id         INT             DEFAULT NULL,
            entry_pass_id   INT             DEFAULT NULL,
            account_type    ENUM('resident','visitor') NOT NULL DEFAULT 'visitor',
            owner_key       VARCHAR(80)     NOT NULL,
            position        INT             NOT NULL DEFAULT 1,
            status          ENUM('waiting','offered','converted','declined','expired','cancelled','unavailable') NOT NULL DEFAULT 'waiting',
            offer_token     CHAR(32)        DEFAULT NULL,
            offer_hold_token CHAR(32)       DEFAULT NULL,
            offer_expires_at DATETIME       DEFAULT NULL,
            last_reason     VARCHAR(255)    DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_wl_slot (amenity, start_date, end_date, status, position),
            KEY idx_wl_owner (owner_key, status),
            KEY idx_wl_offer (offer_token)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        $settings = [
            'amenity_hold_minutes'        => '10',
            'amenity_waitlist_offer_minutes' => '5',
        ];
        $stmt = $con->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
        if ($stmt) {
            foreach ($settings as $k => $v) {
                $stmt->bind_param('ss', $k, $v);
                $stmt->execute();
            }
            $stmt->close();
        }

        // --- v2: queue offer holds + 'unavailable' state --------------------
        if (!$v2Done) {
            $col = $con->query("SHOW COLUMNS FROM amenity_waitlist LIKE 'offer_hold_token'");
            if ($col && $col->num_rows === 0) {
                $con->query("ALTER TABLE amenity_waitlist ADD COLUMN offer_hold_token CHAR(32) DEFAULT NULL AFTER offer_token");
            }
            $col = $con->query("SHOW COLUMNS FROM amenity_waitlist LIKE 'last_reason'");
            if ($col && $col->num_rows === 0) {
                $con->query("ALTER TABLE amenity_waitlist ADD COLUMN last_reason VARCHAR(255) DEFAULT NULL AFTER offer_expires_at");
            }
            // Widen the status enum to include the "slot taken by someone else" state.
            $con->query("ALTER TABLE amenity_waitlist MODIFY COLUMN status ENUM('waiting','offered','converted','declined','expired','cancelled','unavailable') NOT NULL DEFAULT 'waiting'");
            if (function_exists('vpMarkSchemaDone')) {
                vpMarkSchemaDone($con, 'amenity_holds_v2');
            }
        }

        // --- v3: configurable amenity capacity + downpayment hold -------------
        if (!$v3Done) {
            $v3Defaults = [
                'amenity_max_persons' => json_encode([
                    'Clubhouse'              => 200,
                    'Multi-Purpose Building' => 50,
                    'Tennis Court'           => 60,
                    'Basketball Court'       => 30,
                ]),
                'vp_downpayment_hold_hours' => '24',
            ];
            $v3Stmt = $con->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES (?, ?)");
            if ($v3Stmt) {
                foreach ($v3Defaults as $k => $v) {
                    $v3Stmt->bind_param('ss', $k, $v);
                    $v3Stmt->execute();
                }
                $v3Stmt->close();
            }
            if (function_exists('vpMarkSchemaDone')) {
                vpMarkSchemaDone($con, 'amenity_holds_v3');
            }
        }

        if (!$v1Done && function_exists('vpMarkSchemaDone')) {
            vpMarkSchemaDone($con, 'amenity_holds_v1');
        }
    }
}

if (!function_exists('vpAmenitySetting')) {
    function vpAmenitySetting(mysqli $con, string $key, string $default = ''): string
    {
        vpAmenityHoldsEnsureSchema($con);
        $stmt = $con->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        if (!$stmt) {
            return $default;
        }
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? (string)$row['setting_value'] : $default;
    }
}

if (!function_exists('vpHoldSeconds')) {
    /** Temporary hold window in seconds (env override -> app_settings -> 10 min). */
    function vpHoldSeconds(mysqli $con): int
    {
        $env = getenv('VP_HOLD_MINUTES');
        if ($env !== false && is_numeric($env) && (int)$env > 0) {
            return ((int)$env) * 60;
        }
        $min = (int)vpAmenitySetting($con, 'amenity_hold_minutes', '10');
        if ($min <= 0) {
            $min = 10;
        }
        return $min * 60;
    }
}

if (!function_exists('vpWaitlistOfferSeconds')) {
    function vpWaitlistOfferSeconds(mysqli $con): int
    {
        $min = (int)vpAmenitySetting($con, 'amenity_waitlist_offer_minutes', '5');
        if ($min <= 0) {
            $min = 5;
        }
        return $min * 60;
    }
}

if (!function_exists('vpAmenityMaxPersonsMap')) {
    /**
     * Per-amenity maximum persons. Built-in defaults, overridable via the
     * app_settings key 'amenity_max_persons' (JSON object). A value of 0 means
     * "no limit" for that amenity.
     */
    function vpAmenityMaxPersonsMap(mysqli $con): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [
            'Clubhouse'              => 200,
            'Multi-Purpose Building' => 50,
            'Tennis Court'           => 60,
            'Basketball Court'       => 30,
        ];
        $raw = vpAmenitySetting($con, 'amenity_max_persons', json_encode($cache));
        $decoded = json_decode((string)$raw, true);
        if (is_array($decoded)) {
            foreach ($decoded as $k => $v) {
                if (is_numeric($v)) {
                    $cache[(string)$k] = (int)$v;
                }
            }
        }
        return $cache;
    }
}

if (!function_exists('vpAmenityMaxPersons')) {
    /** Configured max persons for a single amenity; 0 means no limit. */
    function vpAmenityMaxPersons(mysqli $con, string $amenity): int
    {
        $map = vpAmenityMaxPersonsMap($con);
        return array_key_exists($amenity, $map) ? (int)$map[$amenity] : 0;
    }
}

if (!function_exists('vpDownpaymentHoldHours')) {
    /**
     * Hours a pending downpayment holds a slot before it is auto-cancelled.
     * env override -> app_settings -> 24 hours.
     */
    function vpDownpaymentHoldHours(mysqli $con): int
    {
        $env = getenv('VP_DOWNPAYMENT_HOLD_HOURS');
        if ($env !== false && is_numeric($env) && (int)$env > 0) {
            return (int)$env;
        }
        $hours = (int)vpAmenitySetting($con, 'vp_downpayment_hold_hours', '24');
        return $hours > 0 ? $hours : 24;
    }
}

if (!function_exists('vpExpireStaleDownpayments')) {
    /**
     * Cancel reservations still awaiting a downpayment (payment_status
     * 'pending' + approval_status 'pending') older than the configured hold
     * window. Returns the number of rows cancelled.
     */
    function vpExpireStaleDownpayments(mysqli $con): int
    {
        $hours = vpDownpaymentHoldHours($con);
        $stmt = $con->prepare(
            "UPDATE reservations
                SET approval_status = 'cancelled', status = 'cancelled'
              WHERE payment_status = 'pending'
                AND approval_status = 'pending'
                AND status NOT IN ('cancelled','deleted','moved_to_history')
                AND created_at IS NOT NULL
                AND created_at < (NOW() - INTERVAL ? HOUR)"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $hours);
        $stmt->execute();
        $n = $stmt->affected_rows;
        $stmt->close();
        return (int)$n;
    }
}

if (!function_exists('vpExpireHolds')) {
    function vpExpireHolds(mysqli $con): void
    {
        @$con->query("UPDATE amenity_slot_holds SET status='expired'
                      WHERE status='held' AND expires_at <= NOW()");
    }
}

if (!function_exists('vpCleanupExpiredHolds')) {
    /**
     * Delete holds whose window has passed. A hold is expired the moment
     * expires_at is in the past - even if no sweep has marked it yet - so this
     * marks first, then removes the dead rows. Converted (paid) and released
     * rows are kept for audit; only expired rows are deleted.
     */
    function vpCleanupExpiredHolds(mysqli $con): int
    {
        vpExpireHolds($con);
        @$con->query("DELETE FROM amenity_slot_holds
                      WHERE status = 'expired'
                        AND expires_at IS NOT NULL AND expires_at <= NOW()");
        $n = ($con instanceof mysqli && $con->errno === 0) ? (int)$con->affected_rows : 0;
        return $n > 0 ? $n : 0;
    }
}

if (!function_exists('vpMaybeCleanupExpiredHolds')) {
    /**
     * Cross-request throttle so opportunistic traffic cleans up expired holds
     * at most once every 60 seconds (the scheduled job in
     * cron/amenity_hold_cleanup.php runs the same sweep every minute).
     */
    function vpMaybeCleanupExpiredHolds(mysqli $con): int
    {
        $last = vpAmenitySetting($con, 'hold_cleanup_last_run_at', '');
        $lastTs = ($last !== '') ? strtotime($last) : false;
        if ($lastTs !== false && $lastTs > 0 && (time() - $lastTs) < 60) {
            return 0;
        }
        // Claim the window before doing the work so concurrent requests do not
        // all sweep; a lost race simply skips this round.
        $claimed = false;
        $stmt = $con->prepare("UPDATE app_settings SET setting_value = NOW()
                               WHERE setting_key = 'hold_cleanup_last_run_at'");
        if ($stmt) {
            $stmt->execute();
            $claimed = $stmt->affected_rows > 0;
            $stmt->close();
        }
        if (!$claimed) {
            $ins = $con->prepare("INSERT IGNORE INTO app_settings (setting_key, setting_value)
                                  VALUES ('hold_cleanup_last_run_at', NOW())");
            if ($ins) {
                $ins->execute();
                $claimed = $ins->affected_rows > 0;
                $ins->close();
            }
        }
        if (!$claimed) {
            return 0;
        }
        return vpCleanupExpiredHolds($con);
    }
}

if (!function_exists('vpOwnerKeyForUser')) {
    function vpOwnerKeyForUser($userId): string
    {
        $userId = ($userId === null || $userId === '' || !is_numeric($userId)) ? null : (int)$userId;
        if ($userId) {
            return 'u:' . $userId;
        }
        $sid = (session_status() === PHP_SESSION_ACTIVE) ? session_id() : '';
        if ($sid === '') {
            $sid = (string)($_COOKIE['PHPSESSID'] ?? '');
        }
        return 's:' . substr(hash('sha256', $sid), 0, 40);
    }
}

if (!function_exists('vpResolveIdentity')) {
    /**
     * Resolve the current actor from the session and/or the signed vp_auth
     * cookie. Returns [user_id|null, account_type, owner_key].
     */
    function vpResolveIdentity(): array
    {
        $uid = $_SESSION['user_id'] ?? null;
        $ut  = $_SESSION['user_type'] ?? null;
        if (($uid === null || $uid === false) && function_exists('vpAuthReadCookie')) {
            [$cuid, $cut] = vpAuthReadCookie();
            if ($cuid !== null) {
                $uid = $cuid;
                $ut  = $cut;
            }
        }
        $uid = ($uid === null || $uid === false || $uid === '') ? null : (int)$uid;
        $accountType = ($ut === 'resident') ? 'resident' : 'visitor';
        return [$uid, $accountType, vpOwnerKeyForUser($uid)];
    }
}

if (!function_exists('vpNormalizeTime')) {
    function vpNormalizeTime($t): ?string
    {
        if ($t === null) {
            return null;
        }
        $t = trim((string)$t);
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
            return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
        }
        return null;
    }
}

if (!function_exists('vpDateRangesOverlap')) {
    function vpDateRangesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return !($aEnd < $bStart || $aStart > $bEnd);
    }
}

if (!function_exists('vpTimeRangesOverlap')) {
    /**
     * Strict interval overlap. Adjacent bookings (end == start) do NOT conflict,
     * matching the existing reserve.php `TIME(?) < end_time AND TIME(?) > start_time`
     * rule. If either side has no time, the whole day conflicts.
     */
    function vpTimeRangesOverlap(?string $aSt, ?string $aEt, ?string $bSt, ?string $bEt): bool
    {
        if ($aSt === null || $aEt === null || $bSt === null || $bEt === null) {
            return true;
        }
        return ($aSt < $bEt) && ($aEt > $bSt);
    }
}

if (!function_exists('vpSlotLockKey')) {
    function vpSlotLockKey(string $amenity, string $date): string
    {
        return substr($amenity . '|' . $date, 0, 191);
    }
}

if (!function_exists('vpEnsureSlotLockRows')) {
    /**
     * Create the resource-lock rows in autocommit BEFORE the transaction opens.
     * Must be called outside of a transaction.
     */
    function vpEnsureSlotLockRows(mysqli $con, string $amenity, string $startDate, string $endDate): void
    {
        $stmt = $con->prepare("INSERT IGNORE INTO amenity_slot_locks (lock_key) VALUES (?)");
        if (!$stmt) {
            return;
        }
        $d = $startDate;
        $guard = 0;
        while ($d <= $endDate && $guard < 62) {
            $key = vpSlotLockKey($amenity, $d);
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
            $guard++;
        }
        $stmt->close();
    }
}

if (!function_exists('vpLockSlotRows')) {
    /**
     * Acquire FOR UPDATE locks on the resource rows. Must be called INSIDE a
     * transaction. Rows are locked in sorted key order to prevent deadlocks.
     */
    function vpLockSlotRows(mysqli $con, string $amenity, string $startDate, string $endDate): void
    {
        $keys = [];
        $d = $startDate;
        $guard = 0;
        while ($d <= $endDate && $guard < 62) {
            $keys[] = vpSlotLockKey($amenity, $d);
            $d = date('Y-m-d', strtotime($d . ' +1 day'));
            $guard++;
        }
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $stmt = $con->prepare("SELECT lock_key FROM amenity_slot_locks WHERE lock_key = ? FOR UPDATE");
            if (!$stmt) {
                continue;
            }
            $stmt->bind_param('s', $key);
            $stmt->execute();
            $stmt->get_result()->fetch_assoc();
            $stmt->close();
        }
    }
}

if (!function_exists('vpFindActiveHoldForOwner')) {
    /**
     * Return an active (non-expired) hold for the given owner / slot, if any.
     */
    function vpFindActiveHoldForOwner(mysqli $con, string $ownerKey, ?int $userId, string $amenity, string $startDate, string $endDate, ?string $startTime, ?string $endTime): ?array
    {
        vpExpireHolds($con);
        $sql = "SELECT * FROM amenity_slot_holds
                WHERE status = 'held' AND expires_at > NOW()
                  AND amenity = ? AND start_date = ? AND end_date = ?
                  AND (owner_key = ?" . ($userId ? " OR user_id = ?" : "") . ")
                  AND ((start_time IS NULL AND ? IS NULL) OR start_time = ?)
                  AND ((end_time IS NULL AND ? IS NULL) OR end_time = ?)
                ORDER BY id DESC LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stN = vpNormalizeTime($startTime);
        $etN = vpNormalizeTime($endTime);
        if ($userId) {
            $stmt->bind_param('ssssissss', $amenity, $startDate, $endDate, $ownerKey, $userId, $stN, $stN, $etN, $etN);
        } else {
            $stmt->bind_param('ssssssss', $amenity, $startDate, $endDate, $ownerKey, $stN, $stN, $etN, $etN);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('vpFindAnyActiveHoldForOwner')) {
    /**
     * Return the most recent active (non-expired) hold for an owner, regardless
     * of slot. Used to recover the countdown after a page reload.
     */
    function vpFindAnyActiveHoldForOwner(mysqli $con, string $ownerKey, ?int $userId): ?array
    {
        vpExpireHolds($con);
        $sql = "SELECT * FROM amenity_slot_holds
                WHERE status = 'held' AND expires_at > NOW()
                  AND (owner_key = ?" . ($userId ? " OR user_id = ?" : "") . ")
                ORDER BY id DESC LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($userId) {
            $stmt->bind_param('si', $ownerKey, $userId);
        } else {
            $stmt->bind_param('s', $ownerKey);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('vpFindHoldByToken')) {
    function vpFindHoldByToken(mysqli $con, string $token): ?array
    {
        $stmt = $con->prepare("SELECT * FROM amenity_slot_holds WHERE hold_token = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('vpFindConflicts')) {
    function vpReservationBlocksSlotSql(string $alias = ''): string
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        return "(COALESCE({$prefix}payment_status, 'pending') IN ('submitted','verified')
                OR COALESCE({$prefix}approval_status, 'pending') IN ('approved','permission_granted')
                OR COALESCE({$prefix}status, 'pending') IN ('approved','confirmed','booked'))";
    }

    /**
     * Return the list of active blocking rows for the given slot.
     * Reads the most recently committed data, so it MUST be called inside the
     * READ COMMITTED transaction AFTER the slot rows are locked.
     *
     * @return array<int,array{source:string,id:int,ref:string,start_date:string,end_date:string,start_time:?string,end_time:?string}>
     */
    function vpFindConflicts(mysqli $con, string $amenity, string $startDate, string $endDate, ?string $startTime, ?string $endTime, ?string $excludeHoldToken = null, ?string $excludeRefCode = null): array
    {
        $stN = vpNormalizeTime($startTime);
        $etN = vpNormalizeTime($endTime);
        $conflicts = [];

        $addIfOverlap = function (string $source, array $row, string $sDateKey, string $eDateKey, string $sTimeKey, string $eTimeKey, bool $skipUntimed = false) use (&$conflicts, $amenity, $startDate, $endDate, $stN, $etN) {
            $rS = (string)$row[$sDateKey];
            $rE = (string)($row[$eDateKey] ?: $row[$sDateKey]);
            if (!vpDateRangesOverlap($startDate, $endDate, $rS, $rE)) {
                return;
            }
            $rSt = vpNormalizeTime($row[$sTimeKey] ?? null);
            $rEt = vpNormalizeTime($row[$eTimeKey] ?? null);
            // Companion rows (resident_reservations / guest_forms) often carry no
            // times while the authoritative reservations row does. When the
            // request is timed, an untimed companion row must not block the whole
            // day, mirroring reserve.php's `TIME(?) < end_time` semantics.
            if ($skipUntimed && $stN !== null && ($rSt === null || $rEt === null)) {
                return;
            }
            if (!vpTimeRangesOverlap($stN, $etN, $rSt, $rEt)) {
                return;
            }
            $conflicts[] = [
                'source'     => $source,
                'id'         => (int)($row['id'] ?? 0),
                'ref'        => (string)($row['ref_code'] ?? ''),
                'amenity'    => (string)($row['amenity'] ?? $amenity),
                'start_date' => $rS,
                'end_date'   => $rE,
                'start_time' => $rSt,
                'end_time'   => $rEt,
                'user_id'    => (int)($row['user_id'] ?? 0),
                'approval_status' => (string)($row['approval_status'] ?? ''),
                'status'     => (string)($row['status'] ?? ''),
                'payment_status' => (string)($row['payment_status'] ?? ''),
                'has_receipt' => !empty($row['receipt_path']),
            ];
        };

        // 1) Confirmed / pending reservations ---------------------------------
        $blocksSlot = vpReservationBlocksSlotSql('r');
        $sql = "SELECT r.id, r.ref_code, r.amenity, r.user_id, r.start_date, r.end_date, r.start_time, r.end_time, r.approval_status, r.status, r.payment_status, r.receipt_path
                FROM reservations r
                WHERE r.amenity = ?
                  AND (r.approval_status IS NULL OR r.approval_status IN ('pending','approved','permission_granted'))
                  AND (r.status IS NULL OR r.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied'))
                  AND {$blocksSlot}
                  AND r.start_date <= ? AND r.end_date >= ?";
        if ($excludeRefCode !== null && $excludeRefCode !== '') {
            $sql .= " AND r.ref_code <> ?";
        }
        $stmt = $con->prepare($sql);
        if ($stmt) {
            if ($excludeRefCode !== null && $excludeRefCode !== '') {
                $stmt->bind_param('ssss', $amenity, $endDate, $startDate, $excludeRefCode);
            } else {
                $stmt->bind_param('sss', $amenity, $endDate, $startDate);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $addIfOverlap('reservation', $row, 'start_date', 'end_date', 'start_time', 'end_time');
            }
            $stmt->close();
        }

        // 2) Pending resident reservations ------------------------------------
        $sql = "SELECT rr.id, rr.ref_code, rr.start_date, rr.end_date, rr.start_time, rr.end_time, rr.approval_status, rr.status
                FROM resident_reservations rr
                WHERE rr.amenity = ?
                  AND (rr.approval_status IS NULL OR rr.approval_status IN ('pending','approved','permission_granted'))
                  AND (rr.status IS NULL OR rr.status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied'))
                  AND NOT EXISTS (SELECT 1 FROM reservations r WHERE r.ref_code = rr.ref_code)
                  AND rr.start_date <= ? AND rr.end_date >= ?";
        if ($excludeRefCode !== null && $excludeRefCode !== '') {
            $sql .= " AND rr.ref_code <> ?";
        }
        $stmt = $con->prepare($sql);
        if ($stmt) {
            if ($excludeRefCode !== null && $excludeRefCode !== '') {
                $stmt->bind_param('ssss', $amenity, $endDate, $startDate, $excludeRefCode);
            } else {
                $stmt->bind_param('sss', $amenity, $endDate, $startDate);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $addIfOverlap('resident_reservation', $row, 'start_date', 'end_date', 'start_time', 'end_time', true);
            }
            $stmt->close();
        }

        // 3) Guest forms ------------------------------------------------------
        $sql = "SELECT id, ref_code, start_date, end_date, start_time, end_time, approval_status, status
                FROM guest_forms
                WHERE amenity = ?
                  AND (approval_status IS NULL OR approval_status IN ('pending','approved','permission_granted'))
                  AND (status IS NULL OR status NOT IN ('cancelled','deleted','moved_to_history','rejected','expired','denied'))
                  AND start_date <= ? AND end_date >= ?";
        if ($excludeRefCode !== null && $excludeRefCode !== '') {
            $sql .= " AND ref_code <> ?";
        }
        $stmt = $con->prepare($sql);
        if ($stmt) {
            if ($excludeRefCode !== null && $excludeRefCode !== '') {
                $stmt->bind_param('ssss', $amenity, $endDate, $startDate, $excludeRefCode);
            } else {
                $stmt->bind_param('sss', $amenity, $endDate, $startDate);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $addIfOverlap('guest_form', $row, 'start_date', 'end_date', 'start_time', 'end_time', true);
            }
            $stmt->close();
        }

        // 4) Active temporary holds -------------------------------------------
        $sql = "SELECT id, hold_token, amenity, start_date, end_date, start_time, end_time
                FROM amenity_slot_holds
                WHERE status = 'held' AND expires_at > NOW()
                  AND amenity = ? AND start_date <= ? AND end_date >= ?";
        if ($excludeHoldToken !== null && $excludeHoldToken !== '') {
            $sql .= " AND hold_token <> ?";
        }
        $stmt = $con->prepare($sql);
        if ($stmt) {
            if ($excludeHoldToken !== null && $excludeHoldToken !== '') {
                $stmt->bind_param('ssss', $amenity, $endDate, $startDate, $excludeHoldToken);
            } else {
                $stmt->bind_param('sss', $amenity, $endDate, $startDate);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['ref_code'] = 'HOLD:' . $row['hold_token'];
                $addIfOverlap('hold', $row, 'start_date', 'end_date', 'start_time', 'end_time');
            }
            $stmt->close();
        }

        return $conflicts;
    }
}

if (!function_exists('vpValidateSlot')) {
    /** Basic validation shared by holds and waitlist. Returns [ok, error, norm]. */
    function vpValidateSlot(string $amenity, string $startDate, string $endDate, ?string $startTime, ?string $endTime): array
    {
        $amenity = trim($amenity);
        if ($amenity === '') {
            return [false, 'Missing amenity.', null];
        }
        $d1 = DateTime::createFromFormat('Y-m-d', $startDate);
        $d2 = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!$d1 || !$d2 || $d1->format('Y-m-d') !== $startDate || $d2->format('Y-m-d') !== $endDate) {
            return [false, 'Invalid date.', null];
        }
        if ($d2 < $d1) {
            return [false, 'End date cannot be before start date.', null];
        }
        $span = (int)$d1->diff($d2)->format('%a');
        if ($span > 7) {
            return [false, 'Date range is too long.', null];
        }
        $stN = vpNormalizeTime($startTime);
        $etN = vpNormalizeTime($endTime);
        if (($stN === null) !== ($etN === null)) {
            return [false, 'Both start and end time are required.', null];
        }
        if ($stN !== null && $etN !== null && $etN <= $stN) {
            return [false, 'End time must be after start time.', null];
        }
        return [true, '', ['amenity' => $amenity, 'start_date' => $startDate, 'end_date' => $endDate, 'start_time' => $stN, 'end_time' => $etN]];
    }
}

if (!function_exists('vpAcquireHold')) {
    /**
     * Atomically acquire (or reuse) a temporary hold for a slot.
     *
     * @return array{ok:bool,error?:string,conflict?:bool,conflicts?:array,hold?:array}
     */
    function vpAcquireHold(mysqli $con, array $in): array
    {
        vpAmenityHoldsEnsureSchema($con);

        [$userId, $accountType, $ownerKey] = vpResolveIdentity();
        if (array_key_exists('user_id', $in) && $in['user_id'] !== null && $in['user_id'] !== '') {
            $userId = (int)$in['user_id'];
            $ownerKey = vpOwnerKeyForUser($userId);
        }
        if (!empty($in['owner_key'])) {
            // Explicit owner override (e.g. creating a claim hold for a queued
            // resident without trusting the current HTTP session).
            $ownerKey = (string)$in['owner_key'];
            if ($userId === null && preg_match('/^u:(\d+)$/', $ownerKey, $m)) {
                $userId = (int)$m[1];
            }
        }
        if (!empty($in['account_type'])) {
            $accountType = ($in['account_type'] === 'resident') ? 'resident' : 'visitor';
        }
        $entryPassId = !empty($in['entry_pass_id']) ? (int)$in['entry_pass_id'] : null;
        $refCode = !empty($in['ref_code']) ? (string)$in['ref_code'] : null;

        [$ok, $err, $n] = vpValidateSlot(
            (string)($in['amenity'] ?? ''),
            (string)($in['start_date'] ?? ''),
            (string)($in['end_date'] ?? ''),
            $in['start_time'] ?? null,
            $in['end_time'] ?? null
        );
        if (!$ok) {
            return ['ok' => false, 'error' => $err];
        }
        $amenity = $n['amenity'];
        $startDate = $n['start_date'];
        $endDate = $n['end_date'];
        $stN = $n['start_time'];
        $etN = $n['end_time'];

        // Reuse an existing identical hold for the same owner (e.g. page refresh).
        $existing = vpFindActiveHoldForOwner($con, $ownerKey, $userId, $amenity, $startDate, $endDate, $stN, $etN);
        if ($existing) {
            return ['ok' => true, 'hold' => $existing, 'reused' => true];
        }

        vpExpireHolds($con);
        vpEnsureSlotLockRows($con, $amenity, $startDate, $endDate);

        @$con->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $con->begin_transaction();
        try {
            vpLockSlotRows($con, $amenity, $startDate, $endDate);

            // Release other stale holds for the same owner so re-selecting a
            // different slot does not leave orphans behind.
            $con->query("UPDATE amenity_slot_holds SET status='released'
                         WHERE status='held' AND owner_key='" . $con->real_escape_string($ownerKey) . "'
                           AND NOT (amenity='" . $con->real_escape_string($amenity) . "'
                                    AND start_date='" . $con->real_escape_string($startDate) . "'
                                    AND end_date='" . $con->real_escape_string($endDate) . "'
                                    AND " . ($stN === null ? "start_time IS NULL" : "start_time='" . $con->real_escape_string($stN) . "'") . "
                                    AND " . ($etN === null ? "end_time IS NULL" : "end_time='" . $con->real_escape_string($etN) . "'") . ")");

            $conflicts = vpFindConflicts($con, $amenity, $startDate, $endDate, $stN, $etN, null, $refCode);
            if (!empty($conflicts)) {
                $con->rollback();
                return ['ok' => false, 'conflict' => true, 'error' => 'That time slot is no longer available.', 'conflicts' => $conflicts];
            }

            $token = bin2hex(random_bytes(16));
            $seconds = (isset($in['seconds']) && (int)$in['seconds'] > 0) ? (int)$in['seconds'] : vpHoldSeconds($con);
            $stmt = $con->prepare("INSERT INTO amenity_slot_holds
                (hold_token, ref_code, amenity, start_date, end_date, start_time, end_time,
                 user_id, entry_pass_id, account_type, owner_key, status, created_at, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'held', NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))");
            $stmt->bind_param(
                'sssssssiissi',
                $token,
                $refCode,
                $amenity,
                $startDate,
                $endDate,
                $stN,
                $etN,
                $userId,
                $entryPassId,
                $accountType,
                $ownerKey,
                $seconds
            );
            $stmt->execute();
            $newId = $stmt->insert_id;
            $stmt->close();
            $con->commit();

            $hold = vpFindHoldByToken($con, $token);
            return ['ok' => true, 'hold' => $hold, 'reused' => false];
        } catch (Throwable $e) {
            @$con->rollback();
            return ['ok' => false, 'error' => 'Could not hold the slot: ' . $e->getMessage()];
        }
    }
}

if (!function_exists('vpReleaseHold')) {
    function vpReleaseHold(mysqli $con, string $token, ?string $ownerKey = null): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }
        $sql = "UPDATE amenity_slot_holds SET status='released'
                WHERE hold_token = ? AND status = 'held'";
        if ($ownerKey !== null) {
            $sql .= " AND owner_key = ?";
        }
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return false;
        }
        if ($ownerKey !== null) {
            $stmt->bind_param('ss', $token, $ownerKey);
        } else {
            $stmt->bind_param('s', $token);
        }
        $stmt->execute();
        $affected = $stmt->affected_rows > 0;
        $stmt->close();
        return $affected;
    }
}

if (!function_exists('vpMarkHoldConverted')) {
    function vpMarkHoldConverted(mysqli $con, string $token, string $refCode): bool
    {
        $stmt = $con->prepare("UPDATE amenity_slot_holds
                SET status='converted', converted_ref_code=?, updated_at=NOW()
                WHERE hold_token = ? AND status='held'");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $refCode, $token);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('vpValidateHoldForSlot')) {
    /**
     * Confirm a hold token exists, is active, belongs to this actor and matches
     * the requested slot. Used when submitting the booking.
     */
    function vpValidateHoldForSlot(mysqli $con, string $token, string $amenity, string $startDate, string $endDate, ?string $startTime, ?string $endTime): array
    {
        $hold = vpFindHoldByToken($con, $token);
        if (!$hold) {
            return [false, 'Your hold has expired. Please pick the time slot again.'];
        }
        if ($hold['status'] !== 'held' || strtotime((string)$hold['expires_at']) <= time()) {
            return [false, 'Your hold has expired. Please pick the time slot again.'];
        }
        if (strcasecmp((string)$hold['amenity'], $amenity) !== 0) {
            return [false, 'Your hold does not match the selected amenity. Please pick the time slot again.'];
        }
        $stN = vpNormalizeTime($startTime);
        $etN = vpNormalizeTime($endTime);
        if ((string)$hold['start_date'] !== $startDate || (string)$hold['end_date'] !== $endDate
            || vpNormalizeTime($hold['start_time']) !== $stN || vpNormalizeTime($hold['end_time']) !== $etN) {
            return [false, 'Your hold does not match the selected time. Please pick the time slot again.'];
        }
        return [true, '', $hold];
    }
}

if (!function_exists('vpRecordWaitlist')) {
    /**
     * Add (or return an existing) waitlist entry for a slot.
     *
     * @return array{ok:bool,error?:string,entry?:array,duplicate?:bool}
     */
    function vpRecordWaitlist(mysqli $con, array $in): array
    {
        vpAmenityHoldsEnsureSchema($con);

        [$userId, $accountType, $ownerKey] = vpResolveIdentity();
        if (array_key_exists('user_id', $in) && $in['user_id'] !== null && $in['user_id'] !== '') {
            $userId = (int)$in['user_id'];
            $ownerKey = vpOwnerKeyForUser($userId);
        }
        if (!empty($in['account_type'])) {
            $accountType = ($in['account_type'] === 'resident') ? 'resident' : 'visitor';
        }
        $entryPassId = !empty($in['entry_pass_id']) ? (int)$in['entry_pass_id'] : null;

        [$ok, $err, $n] = vpValidateSlot(
            (string)($in['amenity'] ?? ''),
            (string)($in['start_date'] ?? ''),
            (string)($in['end_date'] ?? ''),
            $in['start_time'] ?? null,
            $in['end_time'] ?? null
        );
        if (!$ok) {
            return ['ok' => false, 'error' => $err];
        }
        $amenity = $n['amenity'];
        $startDate = $n['start_date'];
        $endDate = $n['end_date'];
        $stN = $n['start_time'];
        $etN = $n['end_time'];

        // Serialize joins per slot so two residents cannot receive the same
        // queue position, and deduplicate against the latest committed data.
        vpExpireHolds($con);
        vpExpireWaitlistOffers($con);
        vpEnsureSlotLockRows($con, $amenity, $startDate, $endDate);

        @$con->query('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $con->begin_transaction();
        try {
            vpLockSlotRows($con, $amenity, $startDate, $endDate);

            $sql = "SELECT * FROM amenity_waitlist
                    WHERE status IN ('waiting','offered')
                      AND amenity=? AND start_date=? AND end_date=?
                      AND ((start_time IS NULL AND ? IS NULL) OR start_time=?)
                      AND ((end_time IS NULL AND ? IS NULL) OR end_time=?)
                      AND (owner_key=?" . ($userId ? " OR user_id=?" : "") . ")
                    ORDER BY id DESC LIMIT 1 FOR UPDATE";
            $stmt = $con->prepare($sql);
            if ($stmt) {
                if ($userId) {
                    $stmt->bind_param('sssssssss', $amenity, $startDate, $endDate, $stN, $stN, $etN, $etN, $ownerKey, $userId);
                } else {
                    $stmt->bind_param('ssssssss', $amenity, $startDate, $endDate, $stN, $stN, $etN, $etN, $ownerKey);
                }
                $stmt->execute();
                $existing = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($existing) {
                    $con->commit();
                    return ['ok' => true, 'entry' => $existing, 'duplicate' => true];
                }
            }

            $stmt = $con->prepare("SELECT COALESCE(MAX(position),0)+1 AS next_pos FROM amenity_waitlist
                    WHERE status IN ('waiting','offered') AND amenity=? AND start_date=? AND end_date=?
                      AND ((start_time IS NULL AND ? IS NULL) OR start_time=?)
                      AND ((end_time IS NULL AND ? IS NULL) OR end_time=?)");
            $nextPos = 1;
            if ($stmt) {
                $stmt->bind_param('sssssss', $amenity, $startDate, $endDate, $stN, $stN, $etN, $etN);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $nextPos = (int)($row['next_pos'] ?? 1);
            }

            $stmt = $con->prepare("INSERT INTO amenity_waitlist
                    (amenity, start_date, end_date, start_time, end_time, user_id, entry_pass_id, account_type, owner_key, position, status, offer_token, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,'waiting',?,NOW())");
            if (!$stmt) {
                $con->rollback();
                return ['ok' => false, 'error' => 'Could not join the queue.'];
            }
            $wlToken = bin2hex(random_bytes(16));
            $stmt->bind_param('sssssiissis', $amenity, $startDate, $endDate, $stN, $etN, $userId, $entryPassId, $accountType, $ownerKey, $nextPos, $wlToken);
            $stmt->execute();
            $id = $stmt->insert_id;
            $stmt->close();
            $con->commit();
        } catch (Throwable $e) {
            @$con->rollback();
            return ['ok' => false, 'error' => 'Could not join the queue: ' . $e->getMessage()];
        }

        $stmt = $con->prepare("SELECT * FROM amenity_waitlist WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['ok' => true, 'entry' => $entry, 'duplicate' => false];
    }
}

if (!function_exists('vpWaitlistOfferStatus')) {
    /**
     * Poll a waitlist entry. If the entry is the head of the queue AND the slot
     * is currently free, promote it to 'offered' with a short claim window.
     *
     * @return array{ok:bool,error?:string,entry?:array,slot_free?:bool,is_head?:bool,claim?:array}
     */
    function vpWaitlistOfferStatus(mysqli $con, string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Missing waitlist token.'];
        }
        $stmt = $con->prepare("SELECT * FROM amenity_waitlist WHERE offer_token = ? LIMIT 1");
        if (!$stmt) {
            return ['ok' => false, 'error' => 'Lookup failed.'];
        }
        $stmt->bind_param('s', $token);
        $stmt->execute();
        $entry = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$entry) {
            return ['ok' => false, 'error' => 'Waitlist entry not found.'];
        }

        // Terminal states -> reflect the final status back to the caller.
        if (in_array($entry['status'], ['converted', 'declined', 'cancelled', 'expired', 'unavailable'], true)) {
            return ['ok' => true, 'entry' => $entry, 'terminal' => true,
                    'position' => 0, 'total' => 0, 'message' => vpWaitlistMessage($entry)];
        }

        // Advance any stale offers first (this entry may be the next head).
        vpExpireWaitlistOffers($con);

        // Re-read in case vpExpireWaitlistOffers() changed this row.
        $stmt = $con->prepare("SELECT * FROM amenity_waitlist WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $entry['id']);
        $stmt->execute();
        $fresh = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($fresh) { $entry = $fresh; }
        if (in_array($entry['status'], ['converted', 'declined', 'cancelled', 'expired', 'unavailable'], true)) {
            return ['ok' => true, 'entry' => $entry, 'terminal' => true,
                    'position' => 0, 'total' => 0, 'message' => vpWaitlistMessage($entry)];
        }

        // Queue position + total among active entries for the same slot.
        [$position, $total] = vpWaitlistRank($con, $entry);
        $isHead = ($position === 1);

        // Inspect the slot: free, held by someone else, or permanently booked.
        vpExpireHolds($con);
        $conflicts = vpFindConflicts($con, (string)$entry['amenity'], (string)$entry['start_date'], (string)$entry['end_date'], $entry['start_time'], $entry['end_time']);

        // A verified payment or confirmed booking can never be won by the
        // queue. A merely submitted, pending booking still leaves the slot in
        // review, so the resident keeps waiting.
        $bookedBy = null;
        foreach ($conflicts as $c) {
            if ($c['source'] === 'hold') { continue; }
            $ap = strtolower((string)($c['approval_status'] ?? ''));
            $status = strtolower((string)($c['status'] ?? ''));
            $payment = strtolower((string)($c['payment_status'] ?? ''));
            if ($ap === 'approved' || $ap === 'permission_granted' || $status === 'approved' ||
                in_array($payment, ['submitted', 'verified', 'paid'], true) ||
                ($payment === 'pending' && !empty($c['has_receipt']))) {
                $bookedBy = $c;
                break;
            }
        }

        if ($bookedBy !== null) {
            $message = 'The slot you queued for has been booked by another user. Please choose another time.';
            $con->begin_transaction();
            try {
                vpNotifyQueuedUsersOfBookedSlot($con, $bookedBy);
                $con->commit();
            } catch (Throwable $e) {
                $con->rollback();
                throw $e;
            }
            $entry['status'] = 'booked';
            $entry['last_reason'] = $message;
            return ['ok' => true, 'entry' => $entry, 'terminal' => true, 'slot_free' => false,
                    'position' => 0, 'total' => 0, 'message' => $message];
        }

        $slotFree = empty($conflicts);

        // Offer the slot to the head when it is genuinely free: create a fresh
        // short temporary hold so the slot is reserved for the claimant while
        // they decide (spec: give a fresh 5-minute claim window).
        if ($isHead && $slotFree && $entry['status'] === 'waiting') {
            $secs = vpWaitlistOfferSeconds($con);
            $hold = vpAcquireHold($con, [
                'amenity'       => (string)$entry['amenity'],
                'start_date'    => (string)$entry['start_date'],
                'end_date'      => (string)$entry['end_date'],
                'start_time'    => $entry['start_time'],
                'end_time'      => $entry['end_time'],
                'user_id'       => $entry['user_id'],
                'owner_key'     => (string)$entry['owner_key'],
                'account_type'  => (string)$entry['account_type'],
                'entry_pass_id' => $entry['entry_pass_id'],
                'ref_code'      => null,
                'seconds'       => $secs,
            ]);
            if (!empty($hold['ok'])) {
                $htoken = (string)$hold['hold']['hold_token'];
                $hexp = (string)$hold['hold']['expires_at'];
                $stmt = $con->prepare("UPDATE amenity_waitlist
                        SET status='offered', offer_hold_token=?, offer_expires_at=?, updated_at=NOW()
                        WHERE id=? AND status='waiting'");
                $stmt->bind_param('ssi', $htoken, $hexp, $entry['id']);
                $stmt->execute();
                if ($stmt->affected_rows > 0) {
                    $entry['status'] = 'offered';
                    $entry['offer_hold_token'] = $htoken;
                    $entry['offer_expires_at'] = $hexp;
                    $slotFree = true;
                    if (!empty($entry['user_id'])) {
                        vpNotifyUser($con, (int)$entry['user_id'], 'A slot you were waiting for is now available',
                            'Your waitlisted ' . $entry['amenity'] . ' slot on ' . $entry['start_date'] . ' is now free. You have ' . intdiv($secs, 60) . ' minute(s) to claim it.', 'success');
                    }
                }
                $stmt->close();
            }
        }

        return ['ok' => true, 'entry' => $entry, 'slot_free' => $slotFree, 'is_head' => $isHead,
                'position' => $position, 'total' => $total,
                'offer_hold_token' => $entry['offer_hold_token'] ?? null,
                'message' => vpWaitlistMessage($entry, $position, $total)];
    }
}

if (!function_exists('vpNotifyQueuedUsersOfBookedSlot')) {
    /**
     * Delete active waitlist entries overlapping a newly booked slot and
     * persist one notification per affected user. Call inside the booking
     * transaction so the reservation, queue cleanup, and notifications commit
     * or roll back together.
     */
    function vpNotifyQueuedUsersOfBookedSlot(mysqli $con, array $booking): int
    {
        $amenity = trim((string)($booking['amenity'] ?? ''));
        $startDate = (string)($booking['start_date'] ?? '');
        $endDate = (string)($booking['end_date'] ?? $startDate);
        $startTime = vpNormalizeTime($booking['start_time'] ?? null);
        $endTime = vpNormalizeTime($booking['end_time'] ?? null);
        $bookerId = (int)($booking['user_id'] ?? 0);
        if ($amenity === '' || $startDate === '' || $endDate === '') {
            return 0;
        }

        $stmt = $con->prepare("SELECT id, amenity, start_date, end_date, start_time, end_time, user_id, offer_hold_token
                               FROM amenity_waitlist
                               WHERE status IN ('waiting','offered')
                                 AND amenity = ?
                                 AND start_date <= ? AND end_date >= ?
                                 AND (start_time IS NULL OR end_time IS NULL OR ? IS NULL OR ? IS NULL
                                      OR (start_time < ? AND end_time > ?))
                               FOR UPDATE");
        if (!$stmt) {
            throw new RuntimeException('Could not find queued users for the booked slot: ' . $con->error);
        }
        $stmt->bind_param('sssssss', $amenity, $endDate, $startDate, $startTime, $endTime, $endTime, $startTime);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not find queued users for the booked slot: ' . $error);
        }
        $result = $stmt->get_result();
        $entries = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $entries[] = $row;
        }
        $stmt->close();
        if (!$entries) {
            return 0;
        }

        $delete = $con->prepare("DELETE FROM amenity_waitlist WHERE id = ? AND status IN ('waiting','offered')");
        $release = $con->prepare("UPDATE amenity_slot_holds SET status='released', updated_at=NOW()
                                  WHERE hold_token = ? AND status='held'");
        $notify = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at)
                                 VALUES (?, ?, ?, 'warning', NOW())");
        if (!$delete || !$release || !$notify) {
            $error = $con->error;
            if ($delete) { $delete->close(); }
            if ($release) { $release->close(); }
            if ($notify) { $notify->close(); }
            throw new RuntimeException('Could not prepare queued-user booking notifications: ' . $error);
        }

        $message = 'The slot you queued for has been booked by another user. Please choose another time.';
        $removed = 0;
        foreach ($entries as $entry) {
            $entryId = (int)$entry['id'];
            if (!empty($entry['offer_hold_token'])) {
                $holdToken = (string)$entry['offer_hold_token'];
                $release->bind_param('s', $holdToken);
                if (!$release->execute()) {
                    throw new RuntimeException('Could not release an expired queue offer hold: ' . $release->error);
                }
            }

            $delete->bind_param('i', $entryId);
            if (!$delete->execute()) {
                throw new RuntimeException('Could not remove a booked waitlist entry: ' . $delete->error);
            }
            if ($delete->affected_rows < 1) {
                continue;
            }
            $removed++;

            $userId = (int)($entry['user_id'] ?? 0);
            if ($userId <= 0 || $userId === $bookerId) {
                continue;
            }
            $bookedStart = $startTime === null ? '*' : $startTime;
            $bookedEnd = $endTime === null ? '*' : $endTime;
            $title = 'Queued slot booked | ' . $amenity . ' | ' . $startDate . ' | ' .
                     $endDate . ' | ' . $bookedStart . ' | ' . $bookedEnd;
            $notify->bind_param('iss', $userId, $title, $message);
            if (!$notify->execute()) {
                throw new RuntimeException('Could not create a queued-user booking notification: ' . $notify->error);
            }
        }
        $delete->close();
        $release->close();
        $notify->close();
        return $removed;
    }
}

if (!function_exists('vpNotifyQueuedUsersForReservation')) {
    function vpNotifyQueuedUsersForReservation(mysqli $con, int $reservationId): int
    {
        $stmt = $con->prepare("SELECT amenity, user_id, start_date, end_date, start_time, end_time
                               FROM reservations WHERE id = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Could not load the booked reservation: ' . $con->error);
        }
        $stmt->bind_param('i', $reservationId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not load the booked reservation: ' . $error);
        }
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) {
            throw new RuntimeException('The booked reservation was not found.');
        }
        return vpNotifyQueuedUsersOfBookedSlot($con, $booking);
    }
}

if (!function_exists('vpNotifyQueuedUsersForReservationReference')) {
    function vpNotifyQueuedUsersForReservationReference(mysqli $con, string $refCode): int
    {
        $stmt = $con->prepare("SELECT amenity, user_id, start_date, end_date, start_time, end_time
                               FROM reservations WHERE ref_code = ? LIMIT 1");
        if (!$stmt) {
            throw new RuntimeException('Could not load the booked reservation: ' . $con->error);
        }
        $stmt->bind_param('s', $refCode);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            throw new RuntimeException('Could not load the booked reservation: ' . $error);
        }
        $booking = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$booking) {
            throw new RuntimeException('The booked reservation was not found.');
        }
        return vpNotifyQueuedUsersOfBookedSlot($con, $booking);
    }
}

if (!function_exists('vpNotifyUser')) {
    function vpNotifyUser(mysqli $con, int $userId, string $title, string $message, string $type = 'info'): void
    {
        $stmt = $con->prepare("INSERT INTO notifications (user_id, title, message, type, created_at) VALUES (?, ?, ?, ?, NOW())");
        if ($stmt) {
            $stmt->bind_param('isss', $userId, $title, $message, $type);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('vpWaitlistMarkStatus')) {
    function vpWaitlistMarkStatus(mysqli $con, string $offerToken, string $status): bool
    {
        if (!in_array($status, ['converted', 'declined', 'cancelled', 'expired'], true)) {
            return false;
        }
        // When a resident leaves (or their offer dies), free the claim hold so
        // the next person in line can be offered the slot.
        if (in_array($status, ['declined', 'cancelled', 'expired'], true)) {
            $stmt = $con->prepare("SELECT offer_hold_token FROM amenity_waitlist WHERE offer_token = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $offerToken);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row && !empty($row['offer_hold_token'])) {
                    vpReleaseHold($con, (string)$row['offer_hold_token']);
                }
            }
        }
        $stmt = $con->prepare("UPDATE amenity_waitlist SET status=?, updated_at=NOW() WHERE offer_token=?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('ss', $status, $offerToken);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }
}

if (!function_exists('vpWaitlistRank')) {
    /**
     * [position, total] of an entry among the active (waiting/offered) entries
     * for the same slot, ordered consistently by position then id.
     */
    function vpWaitlistRank(mysqli $con, array $entry): array
    {
        $stmt = $con->prepare("SELECT id FROM amenity_waitlist
                WHERE status IN ('waiting','offered') AND amenity=? AND start_date=? AND end_date=?
                  AND ((start_time IS NULL AND ? IS NULL) OR start_time=?)
                  AND ((end_time IS NULL AND ? IS NULL) OR end_time=?)
                ORDER BY position ASC, id ASC");
        if (!$stmt) {
            return [0, 0];
        }
        $st = $entry['start_time'];
        $et = $entry['end_time'];
        $stmt->bind_param('sssssss', $entry['amenity'], $entry['start_date'], $entry['end_date'], $st, $st, $et, $et);
        $stmt->execute();
        $res = $stmt->get_result();
        $pos = 0; $i = 0; $selfId = (int)$entry['id'];
        while ($row = $res->fetch_assoc()) {
            $i++;
            if ((int)$row['id'] === $selfId) { $pos = $i; }
        }
        $stmt->close();
        return [$pos, $i];
    }
}

if (!function_exists('vpWaitlistMessage')) {
    function vpWaitlistMessage(array $entry, int $position = 0, int $total = 0): string
    {
        switch ((string)($entry['status'] ?? '')) {
            case 'waiting':
                $m = 'This time slot is currently being reserved by another resident. You have been added to the queue. Please wait while we check if the slot becomes available.';
                if ($position > 0) {
                    $m .= ' You are #' . $position . ' in line' . ($total > 1 ? ' of ' . $total : '') . '.';
                }
                return $m;
            case 'offered':
                return 'This slot is now available to you. Claim it before the countdown ends.';
            case 'converted':
                return 'Your request for this slot has been submitted.';
            case 'declined':
                return 'You left the waiting line for this slot.';
            case 'cancelled':
                return 'This waiting request was cancelled.';
            case 'expired':
                return 'Your claim window for this slot expired. You can pick another time.';
            case 'unavailable':
                return !empty($entry['last_reason']) ? (string)$entry['last_reason'] : 'This slot is no longer available. Please choose another time.';
        }
        return 'Waiting for slot.';
    }
}

if (!function_exists('vpExpireWaitlistOffers')) {
    /**
     * Server-side sweep for offers whose claim window elapsed. Deactivates the
     * entry (so the queue advances to the next person) and frees its claim hold.
     * Safe to call on every request; the browser never has to be open.
     */
    function vpExpireWaitlistOffers(mysqli $con): void
    {
        $stmt = $con->prepare("SELECT id, user_id, offer_hold_token, amenity, start_date, start_time, end_time
                FROM amenity_waitlist
                WHERE status='offered' AND offer_expires_at IS NOT NULL AND offer_expires_at <= NOW()");
        if (!$stmt) {
            return;
        }
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) { $rows[] = $row; }
        $stmt->close();
        foreach ($rows as $row) {
            if (!empty($row['offer_hold_token'])) {
                vpReleaseHold($con, (string)$row['offer_hold_token']);
            }
            $upd = $con->prepare("UPDATE amenity_waitlist SET status='expired', offer_expires_at=NULL, offer_hold_token=NULL, last_reason=?, updated_at=NOW()
                    WHERE id=? AND status='offered'");
            $reason = 'Your claim window expired before you claimed the slot.';
            $upd->bind_param('si', $reason, $row['id']);
            $upd->execute();
            $upd->close();
            if (!empty($row['user_id'])) {
                vpNotifyUser($con, (int)$row['user_id'], 'Waitlist offer expired',
                    'Your claim window for the ' . $row['amenity'] . ' slot on ' . $row['start_date'] . ' expired.', 'warning');
            }
        }
    }
}

if (!function_exists('vpFindAnyActiveWaitlistForOwner')) {
    /** Most recent active queue entry for an owner (reload recovery). */
    function vpFindAnyActiveWaitlistForOwner(mysqli $con, string $ownerKey, ?int $userId): ?array
    {
        $sql = "SELECT * FROM amenity_waitlist
                WHERE status IN ('waiting','offered')
                  AND (owner_key=?" . ($userId ? " OR user_id=?" : "") . ")
                ORDER BY id DESC LIMIT 1";
        $stmt = $con->prepare($sql);
        if (!$stmt) {
            return null;
        }
        if ($userId) {
            $stmt->bind_param('si', $ownerKey, $userId);
        } else {
            $stmt->bind_param('s', $ownerKey);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}

if (!function_exists('vpSuggestAlternatives')) {
    /**
     * Suggest up to $limit free windows on the same day for the same amenity,
     * using the requested duration. No personal data is involved.
     */
    function vpSuggestAlternatives(mysqli $con, string $amenity, string $date, ?string $startTime, ?string $endTime, int $limit = 3): array
    {
        $durMin = 60;
        if ($startTime !== null && $endTime !== null) {
            $s = strtotime('1970-01-01 ' . $startTime);
            $e = strtotime('1970-01-01 ' . $endTime);
            if ($s !== false && $e !== false && $e > $s) {
                $durMin = max(30, (int)(($e - $s) / 60));
            }
        }
        $hourStart = 9;
        $hourEnd = in_array($amenity, ['Clubhouse', 'Multi-Purpose Building'], true) ? 21 : 18;
        $out = [];
        for ($h = $hourStart; ($h * 60 + $durMin) <= ($hourEnd * 60) && count($out) < $limit; $h++) {
            $ws = sprintf('%02d:00:00', $h);
            $we = date('H:i:s', mktime($h, 0, 0) + $durMin * 60);
            $conflicts = vpFindConflicts($con, $amenity, $date, $date, $ws, $we);
            if (empty($conflicts)) {
                $out[] = ['start_time' => $ws, 'end_time' => $we];
            }
        }
        return $out;
    }
}

if (!function_exists('vpHoldListForSlot')) {
    /**
     * Active holds for an amenity across a date window, used to enrich the
     * availability payload. Returns rows keyed for JSON output.
     */
    function vpHoldListForSlot(mysqli $con, string $amenity, string $windowStart, string $windowEnd, ?string $ownerKey, ?int $userId): array
    {
        vpExpireHolds($con);
        $stmt = $con->prepare("SELECT hold_token, start_date, end_date, start_time, end_time, owner_key, user_id
                FROM amenity_slot_holds
                WHERE status='held' AND expires_at > NOW() AND amenity=?
                  AND start_date <= ? AND end_date >= ?
                ORDER BY start_date, start_time");
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sss', $amenity, $windowEnd, $windowStart);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($row = $res->fetch_assoc()) {
            $mine = false;
            if ($ownerKey !== null && (string)$row['owner_key'] === $ownerKey) {
                $mine = true;
            } elseif ($userId !== null && $row['user_id'] !== null && (int)$row['user_id'] === (int)$userId) {
                $mine = true;
            }
            $out[] = [
                'start_date' => (string)$row['start_date'],
                'end_date'   => (string)$row['end_date'],
                'start'      => vpNormalizeTime($row['start_time']),
                'end'        => vpNormalizeTime($row['end_time']),
                'has_time'   => $row['start_time'] !== null,
                'hold'       => true,
                'mine'       => $mine,
                'status'     => 'HELD',
            ];
        }
        $stmt->close();
        return $out;
    }
}
