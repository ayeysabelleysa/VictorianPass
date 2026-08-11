<?php
/**
 * VictorianPass EcoPoint Waste Session — Core Business Logic
 * -----------------------------------------------------------
 * All business rules (rates, caps, authentication, duplicate
 * prevention, point awards, etc.) live HERE so endpoints stay thin.
 *
 * Required tables (see DATABASE/ecopoint_waste_system.sql):
 *   - ecopoint_stations
 *   - ecopoint_waste_sessions
 *   - ecopoint_session_events
 *   - point_transactions (users table)
 */

declare(strict_types=1);

// ------------------------------------------------------------------
// Program constants (business rules, centralised!)
// ------------------------------------------------------------------
define('ECO_MATERIAL_RATES', [
    'Plastic'              => 55,   // pts/kg (PET ≤1000ml)
    'Aluminum'             => 140,  // pts/kg (cans)
    'Paper'                => 30,   // pts/kg (documents, newspaper)
    'Cardboard'            => 30,   // pts/kg (boxes)
]);
define('ECO_DAILY_POINT_CAP',       100);  // pts/day max per resident
define('ECO_DAILY_SESSION_CAP',     3);    // sessions/day max
define('ECO_WEEKLY_POINT_CAP',      250);  // pts/week max (Mon reset)
define('ECO_MAX_BALANCE',           3000); // max resident balance
define('ECO_SESSION_TIMEOUT_SEC',   600);  // 10 min default session timeout
define('ECO_ALLOWED_MATERIALS',     array_keys(ECO_MATERIAL_RATES));
define('ECO_SESSION_STATUSES',      ['WAITING','ACTIVE','PROCESSING','COMPLETED','CANCELLED','ERROR']);
define('ECO_SESSION_STATUSES_OPEN', ['WAITING','ACTIVE','PROCESSING']); // non-final, user can have only one

require_once __DIR__ . '/connect.php';

// ------------------------------------------------------------------
// HTTP helpers
// ------------------------------------------------------------------
function eco_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function eco_read_json_input(): array {
    $raw = @file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function eco_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== strtoupper($method)) {
        eco_json_response(['success' => false, 'message' => 'Method not allowed'], 405);
    }
}

// ------------------------------------------------------------------
// Hardware station authentication (API key header)
// Expected header: X-VHECO-Station: <station_code>
// Expected header: X-VHECO-Api-Key: <plaintext-key>
// ------------------------------------------------------------------
function eco_authenticate_station(mysqli $con): array {
    $code    = trim((string)($_SERVER['HTTP_X_VHECO_STATION']     ?? ''));
    $apiKey  = (string)($_SERVER['HTTP_X_VHECO_API_KEY']          ?? '');

    if ($code === '' || $apiKey === '') {
        eco_json_response([
            'success' => false,
            'message' => 'Missing station authentication headers (X-VHECO-Station, X-VHECO-Api-Key)',
        ], 401);
    }

    $stmt = $con->prepare("
        SELECT id, station_code, station_name, api_key_hash, status, location
        FROM   ecopoint_stations
        WHERE  station_code = ?
        LIMIT  1
    ");
    if (!$stmt) {
        eco_json_response(['success' => false, 'message' => 'Auth query failure'], 500);
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        eco_json_response(['success' => false, 'message' => 'Unknown station'], 401);
    }

    $status = strtoupper((string)($row['status'] ?? 'ACTIVE'));
    if ($status !== 'ACTIVE') {
        eco_json_response([
            'success' => false,
            'message' => 'Station is not active (status: ' . $status . ')',
        ], 403);
    }

    // Verify hashed API key (never trust plaintext stored anywhere!)
    $hashOk = password_verify($apiKey, (string)($row['api_key_hash'] ?? ''));
    if (!$hashOk) {
        eco_json_response(['success' => false, 'message' => 'Invalid API key'], 401);
    }

    // Touch heartbeat
    $upd = $con->prepare("UPDATE ecopoint_stations SET last_heartbeat_at = NOW() WHERE id = ?");
    if ($upd) {
        $upd->bind_param('i', $row['id']);
        @$upd->execute();
        $upd->close();
    }

    return [
        'station_id'   => (int)$row['id'],
        'station_code' => (string)$row['station_code'],
        'station_name' => (string)$row['station_name'],
        'location'     => (string)($row['location'] ?? ''),
    ];
}

// ------------------------------------------------------------------
// Resident QR code → user lookup (secure: only trust DB rows, never
// trust client-supplied balances!)
// ------------------------------------------------------------------
function eco_find_resident_by_qr(mysqli $con, string $qrCode): ?array {
    $qrCode = trim($qrCode);
    if ($qrCode === '') return null;

    // ----------------------------------------------------------
    // 1) Old migration path: residents.qr_code (kept for back-compat)
    // ----------------------------------------------------------
    if (class_exists('mysqli')) {
        $colQ = $con->query("SHOW COLUMNS FROM residents LIKE 'qr_code'");
        if ($colQ && $colQ->num_rows > 0) {
            $stmt = $con->prepare("SELECT id, full_name, ecopoint_balance, qr_code, status FROM residents WHERE qr_code = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param('s', $qrCode);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if ($row) {
                    $balance = (int)($row['ecopoint_balance'] ?? 0);
                    // Mirror to users.points if that's the main table the dashboard uses
                    $uid = (int)$row['id'];
                    $syncStmt = $con->prepare("UPDATE users SET points = GREATEST(points, ?) WHERE id = ?");
                    if ($syncStmt) {
                        $syncStmt->bind_param('ii', $balance, $uid);
                        @$syncStmt->execute();
                        $syncStmt->close();
                    }
                    return [
                        'user_id'    => $uid,
                        'ref_code'   => (string)($row['qr_code'] ?? ''),
                        'full_name'  => (string)($row['full_name'] ?? 'Resident'),
                        'balance'    => $balance,
                        'status'     => (string)($row['status'] ?? 'active'),
                    ];
                }
            }
        }
    }

    // ----------------------------------------------------------
    // 2) Main path: users.ref_code (the existing QR system used by
    //    qr_view.php / profileresident.php)
    // ----------------------------------------------------------
    $stmt = $con->prepare("
        SELECT id, ref_code, first_name, last_name, points, status
        FROM   users
        WHERE  ref_code = ?
        LIMIT  1
    ");
    if (!$stmt) return null;
    $stmt->bind_param('s', $qrCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;

    $status = strtolower((string)($row['status'] ?? 'pending'));
    $isActive = (strpos($status, 'active') !== false || strpos($status, 'approv') !== false);

    return [
        'user_id'   => (int)$row['id'],
        'ref_code'  => (string)($row['ref_code'] ?? ''),
        'full_name' => trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? '')),
        'balance'   => (int)($row['points'] ?? 0),
        'status'    => $isActive ? 'active' : $status,
    ];
}

// ------------------------------------------------------------------
// Explicit weekly window: Monday 00:00 (reset) to the following Monday 00:00
// This guarantees the weekly cap resets EVERY MONDAY 12:00 AM as required.
// Weekday = 0 means Monday in PHP date("N").
// ------------------------------------------------------------------
function eco_week_bounds(string $forDate = 'now'): array {
    $ts = is_string($forDate) ? strtotime($forDate) : (int)$forDate;
    // The most recent Monday (or today if today IS Monday) at 00:00:00
    $weekday1 = (int)date('N', $ts); // 1=Mon, 7=Sun
    $mondayTs = mktime(0, 0, 0, (int)date('m', $ts), (int)date('d', $ts) - ($weekday1 - 1), (int)date('Y', $ts));
    $nextMonTs = $mondayTs + (7 * 86400);
    return [
        'week_start_ts'    => $mondayTs,
        'week_end_ts'      => $nextMonTs - 1, // last second of Sunday
        'week_start_date'  => date('Y-m-d', $mondayTs),
        'week_end_date'    => date('Y-m-d', $nextMonTs - 1),
        'week_start_mysql' => date('Y-m-d 00:00:00', $mondayTs),
        'week_end_mysql'   => date('Y-m-d 23:59:59', $nextMonTs - 1),
    ];
}

// ------------------------------------------------------------------
// Calculate resident's remaining daily/weekly caps (DB is source of truth)
// ------------------------------------------------------------------
function eco_resident_cap_state(mysqli $con, int $userId): array {
    $today     = date('Y-m-d');
    $week      = eco_week_bounds();
    $monday    = $week['week_start_date'];
    $sunday    = $week['week_end_date'];

    $dailyPts = 0;
    $dailySess = 0;
    $weeklyPts = 0;

    // Use point_transactions (earn entries) for caps, since that's what
    // the dashboard displays.
    $stmt = $con->prepare("
        SELECT
            transaction_type,
            amount,
            DATE(created_at) AS day_date,
            created_at
        FROM   point_transactions
        WHERE  user_id = ?
        AND    created_at BETWEEN ? AND ?
    ");
    if ($stmt) {
        $stmt->bind_param('iss', $userId, $week['week_start_mysql'], $week['week_end_mysql']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $type = strtolower((string)($row['transaction_type'] ?? ''));
            if ($type !== 'earn') continue;
            $amt  = (int)($row['amount'] ?? 0);
            $weeklyPts += $amt;
            if ((string)($row['day_date'] ?? '') === $today) {
                $dailyPts += $amt;
                $dailySess += 1;
            }
        }
        $stmt->close();
    }

    // Daily sessions = completed sessions today as well (more accurate than tx count).
    $stmt = $con->prepare("
        SELECT COUNT(*) AS c
        FROM   ecopoint_waste_sessions
        WHERE  user_id = ? AND status = 'COMPLETED' AND DATE(completed_at) = ?
    ");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($r && (int)$r['c'] > $dailySess) $dailySess = (int)$r['c'];
    }

    return [
        'today'                => $today,
        'week_start'           => $monday,
        'week_end'             => $sunday,
        'weekly_reset_note'    => 'Resets next Monday 12:00 AM (Asia/Manila)',
        'daily_points_used'    => $dailyPts,
        'daily_points_left'    => max(0, ECO_DAILY_POINT_CAP    - $dailyPts),
        'daily_sessions_used'  => $dailySess,
        'daily_sessions_left'  => max(0, ECO_DAILY_SESSION_CAP  - $dailySess),
        'weekly_points_used'   => $weeklyPts,
        'weekly_points_left'   => max(0, ECO_WEEKLY_POINT_CAP   - $weeklyPts),
    ];
}

// ------------------------------------------------------------------
// Duplicate-active-session guard
// A resident may have at most ONE session that's WAITING/ACTIVE/PROCESSING.
// (OPEN status set — duplicates blocked by DB UNIQUE KEY + this check.)
// ------------------------------------------------------------------
function eco_get_active_session_for_user(mysqli $con, int $userId): ?array {
    $inList = implode(',', array_fill(0, count(ECO_SESSION_STATUSES_OPEN), '?'));
    $stmt = $con->prepare("
        SELECT *
        FROM   ecopoint_waste_sessions
        WHERE  user_id = ?
        AND    status IN ($inList)
        LIMIT  1
    ");
    if (!$stmt) return null;
    $params = array_merge([$userId], ECO_SESSION_STATUSES_OPEN);
    $types  = 'i' . str_repeat('s', count(ECO_SESSION_STATUSES_OPEN));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ------------------------------------------------------------------
// Insert immutable session event row (audit log)
// ------------------------------------------------------------------
function eco_log_event(mysqli $con, int $sessionId, int $stationId, string $type, $payload, string $source = 'HARDWARE'): void {
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $con->prepare("
            INSERT INTO ecopoint_session_events
            (session_id, station_id, event_type, event_payload, source)
            VALUES (?, ?, ?, ?, ?)
        ");
    }
    if (!$stmt) return;
    $json = ($payload === null) ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);
    $stmt->bind_param('iisss', $sessionId, $stationId, $type, $json, $source);
    @$stmt->execute();
}

// ------------------------------------------------------------------
// Generate a cryptographically-strong opaque session token (64 hex)
// ------------------------------------------------------------------
function eco_generate_session_token(): string {
    return bin2hex(random_bytes(32));
}

// ------------------------------------------------------------------
// Rate / points math (always recalculated on backend from material+weight)
// Never trust client/harware-provided points values.
// ------------------------------------------------------------------
function eco_calculate_points(string $material, float $weightKg): array {
    $material = trim($material);
    $weightKg = max(0.0, $weightKg);
    $rate     = (int)(ECO_MATERIAL_RATES[$material] ?? 0);
    $raw      = (int)round($weightKg * $rate);
    $valid    = ($rate > 0 && $weightKg > 0 && in_array($material, ECO_ALLOWED_MATERIALS, true));
    return [
        'material'        => $material,
        'weight_kg'       => $weightKg,
        'rate_pts_per_kg' => $rate,
        'raw_points'      => $raw,
        'valid'           => $valid,
    ];
}

function eco_apply_cap_rules(array $calc, array $capState, int $currentBalance): int {
    if (empty($calc['valid'])) return 0;
    $award = (int)$calc['raw_points'];
    $award = min($award, (int)$capState['daily_points_left']);
    $award = min($award, (int)$capState['weekly_points_left']);
    $room  = max(0, ECO_MAX_BALANCE - $currentBalance);
    $award = min($award, $room);
    return max(0, $award);
}

// ------------------------------------------------------------------
// Create a new waste session (after QR verification)
// Returns the full session row including the session_token the
// hardware must use for subsequent calls.
// ------------------------------------------------------------------
function eco_create_session(mysqli $con, array $station, array $resident, string $qrRefCode): array {
    $userId = (int)$resident['user_id'];

    // 1) Duplicate-session guard
    $active = eco_get_active_session_for_user($con, $userId);
    if ($active) {
        // Don't create a second one; just return existing (prevents doubles)
        return [
            'created'    => false,
            'session'    => $active,
            'cap_state'  => eco_resident_cap_state($con, $userId),
        ];
    }

    // 2) Session/day limit guard
    $capState = eco_resident_cap_state($con, $userId);
    if ($capState['daily_sessions_left'] <= 0) {
        eco_json_response([
            'success' => false,
            'message' => 'Daily session limit reached (max ' . ECO_DAILY_SESSION_CAP . ' per day)',
            'cap'     => $capState,
        ], 403);
    }

    if (strtolower((string)($resident['status'] ?? '')) !== 'active') {
        eco_json_response([
            'success' => false,
            'message' => 'Resident account is not active (status: ' . ($resident['status'] ?? 'unknown') . ')',
        ], 403);
    }

    $token     = eco_generate_session_token();
    $stationId = (int)$station['station_id'];

    $con->begin_transaction();
    try {
        $stmt = $con->prepare("
            INSERT INTO ecopoint_waste_sessions
            (session_token, station_id, user_id, qr_ref_code, status, applied_daily_cap, applied_weekly_cap, applied_max_balance)
            VALUES (?, ?, ?, ?, 'ACTIVE', ?, ?, ?)
        ");
        $stmt->bind_param(
            'siisiii',
            $token,
            $stationId,
            $userId,
            $qrRefCode,
            ECO_DAILY_POINT_CAP,
            ECO_WEEKLY_POINT_CAP,
            ECO_MAX_BALANCE
        );
        $stmt->execute();
        $sessionId = (int)$con->insert_id;
        $stmt->close();

        eco_log_event($con, $sessionId, $stationId, 'QR_VERIFIED', [
            'qr_ref_code'  => $qrRefCode,
            'user_id'      => $userId,
            'resident_name'=> $resident['full_name'],
        ], 'BACKEND');
        eco_log_event($con, $sessionId, $stationId, 'SESSION_CREATED', [
            'station_code' => $station['station_code'],
            'cap_state'    => $capState,
        ], 'BACKEND');

        $con->commit();

        // Re-read full session row to return
        $sRow = $con->query("SELECT * FROM ecopoint_waste_sessions WHERE id = $sessionId LIMIT 1")->fetch_assoc();
        return [
            'created'   => true,
            'session'   => $sRow,
            'cap_state' => $capState,
        ];
    } catch (Throwable $e) {
        try { $con->rollback(); } catch (Throwable $_) {}
        eco_json_response([
            'success' => false,
            'message' => 'Failed to create session: ' . $e->getMessage(),
        ], 500);
    }
}

// ------------------------------------------------------------------
// Lookup session via token (used for all subsequent hardware calls)
// Ensures station owns the session + session is still allowed to mutate.
// (OPEN statuses: WAITING / ACTIVE / PROCESSING.)
// ------------------------------------------------------------------
function eco_load_session_for_station(mysqli $con, array $station, string $token, bool $allowProcessing = true): ?array {
    $token      = trim($token);
    $stationId  = (int)$station['station_id'];
    if ($token === '') return null;

    // Build list of allowed statuses (OPEN status set from constant, subset by $allowProcessing)
    $allowed = ECO_SESSION_STATUSES_OPEN;
    if (!$allowProcessing) {
        $allowed = array_values(array_filter($allowed, function($s){ return $s !== 'PROCESSING'; }));
    }
    $inList = implode(',', array_fill(0, count($allowed), '?'));
    $stmt = $con->prepare("
        SELECT *
        FROM   ecopoint_waste_sessions
        WHERE  session_token = ?
        AND    station_id    = ?
        AND    status IN ($inList)
        LIMIT  1
    ");
    if (!$stmt) return null;
    $params = array_merge([$token, $stationId], $allowed);
    $types  = 'si' . str_repeat('s', count($allowed));
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ------------------------------------------------------------------
// Atomically transition session status (state machine)
// ------------------------------------------------------------------
function eco_transition_status(mysqli $con, int $sessionId, int $stationId, string $newStatus, ?string $errorMsg = null): void {
    $newStatus = strtoupper($newStatus);
    $allowed   = ECO_SESSION_STATUSES;
    if (!in_array($newStatus, $allowed, true)) return;

    // Single status update (safe & simple)
    $stmt = $con->prepare("UPDATE ecopoint_waste_sessions SET status = ? WHERE id = ? AND station_id = ?");
    if (!$stmt) return;
    $stmt->bind_param('sii', $newStatus, $sessionId, $stationId);
    $stmt->execute();
    $stmt->close();

    // Per-status timestamp auditing
    if ($newStatus === 'WAITING') {
        $s = $con->prepare("UPDATE ecopoint_waste_sessions SET waiting_at = NOW() WHERE id = ? AND waiting_at IS NULL");
        $s && ($s->bind_param('i', $sessionId) && @$s->execute());
    }
    if ($newStatus === 'ACTIVE') {
        $s = $con->prepare("UPDATE ecopoint_waste_sessions SET started_at = COALESCE(started_at, NOW()), waiting_at = COALESCE(waiting_at, NOW()) WHERE id = ?");
        $s && ($s->bind_param('i', $sessionId) && @$s->execute());
    }
    if ($newStatus === 'COMPLETED') {
        $s = $con->prepare("UPDATE ecopoint_waste_sessions SET completed_at = NOW(), total_weight_kg = COALESCE(total_weight_kg, weight_kg), total_points = COALESCE(total_points, points_awarded) WHERE id = ?");
        $s && ($s->bind_param('i', $sessionId) && @$s->execute());
    }
    if ($newStatus === 'CANCELLED') {
        $s = $con->prepare("UPDATE ecopoint_waste_sessions SET cancelled_at = NOW() WHERE id = ?");
        $s && ($s->bind_param('i', $sessionId) && @$s->execute());
    }
    if ($newStatus === 'ERROR' && $errorMsg !== null) {
        $s = $con->prepare("UPDATE ecopoint_waste_sessions SET error_at = NOW(), error_message = ? WHERE id = ?");
        if ($s) {
            $s->bind_param('si', $errorMsg, $sessionId);
            @$s->execute();
        }
    }
}

// ------------------------------------------------------------------
// Finalize: Award points (idempotent — points_posted flag prevents doubles)
// Must be called inside an already-begun transaction or it will start one.
// ------------------------------------------------------------------
function eco_award_points_and_finalize(mysqli $con, int $sessionId, int $stationId): array {
    // Reload session with FOR UPDATE row lock
    $stmt = $con->prepare("SELECT * FROM ecopoint_waste_sessions WHERE id = ? FOR UPDATE");
    if (!$stmt) eco_json_response(['success' => false, 'message' => 'Session lock failed'], 500);
    $stmt->bind_param('i', $sessionId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) eco_json_response(['success' => false, 'message' => 'Session not found'], 404);

    // Idempotency guard: if already posted, don't double-award!
    $alreadyPosted = ((int)($session['points_posted'] ?? 0) === 1);

    $material = (string)($session['material_type'] ?? '');
    $weight   = (float)($session['weight_kg']    ?? 0);
    $userId   = (int)$session['user_id'];
    $capState = eco_resident_cap_state($con, $userId);
    $bal      = (int)($session['points_awarded'] ?? 0); // pre-existing award if any
    $curBal   = eco_user_balance($con, $userId);

    if (!$alreadyPosted) {
        $calc       = eco_calculate_points($material, $weight);
        $calculated = (int)$calc['raw_points'];
        $awarded    = eco_apply_cap_rules($calc, $capState, $curBal);

        // Update session with calculated values
        $upd = $con->prepare("
            UPDATE ecopoint_waste_sessions
            SET    material_type = ?,
                   weight_kg     = ?,
                   points_calculated = ?,
                   points_awarded    = ?
            WHERE  id = ?
        ");
        $upd->bind_param('sdiii', $calc['material'], $calc['weight_kg'], $calculated, $awarded, $sessionId);
        $upd->execute();
        $upd->close();

        // Post transaction + update user balance (single write)
        $txId = null;
        if ($awarded > 0) {
            $desc = sprintf(
                'VHEcoPoint recycling: %s (%.2f kg) @ %d pts/kg',
                $calc['material'],
                $calc['weight_kg'],
                $calc['rate_pts_per_kg']
            );
            $ins = $con->prepare("
                INSERT INTO point_transactions
                (user_id, transaction_type, amount, description, material_type, weight_kg, station_id, ecopoint_session_id)
                VALUES (?, 'earn', ?, ?, ?, ?, ?, ?, ?)
            ");
            $ins->bind_param(
                'iisdidii',
                $userId,
                $awarded,
                $desc,
                $calc['material'],
                $calc['weight_kg'],
                $stationId,
                $sessionId
            );
            $ins->execute();
            $txId = (int)$con->insert_id;
            $ins->close();

            $updBal = $con->prepare("UPDATE users SET points = LEAST(points + ?, ?) WHERE id = ?");
            $maxBal = ECO_MAX_BALANCE;
            $updBal->bind_param('iii', $awarded, $maxBal, $userId);
            $updBal->execute();
            $updBal->close();

            // Back-sync to residents.ecopoint_balance if it exists
            $colQ = $con->query("SHOW COLUMNS FROM residents LIKE 'ecopoint_balance'");
            if ($colQ && $colQ->num_rows > 0) {
                $sync = $con->prepare("UPDATE residents SET ecopoint_balance = LEAST(ecopoint_balance + ?, ?) WHERE id = ?");
                if ($sync) {
                    $sync->bind_param('iii', $awarded, $maxBal, $userId);
                    @$sync->execute();
                    $sync->close();
                }
            }
        }

        // Mark session posted (flag + FK to tx row)
        $mark = $con->prepare("
            UPDATE ecopoint_waste_sessions
            SET    points_posted = 1,
                   posted_transaction_id = ?
            WHERE  id = ?
        ");
        $mark->bind_param('ii', $txId, $sessionId);
        $mark->execute();
        $mark->close();

        eco_log_event($con, $sessionId, $stationId, 'POINTS_POSTED', [
            'calculated'    => $calculated,
            'awarded'       => $awarded,
            'cap_state'     => $capState,
            'transaction_id'=> $txId,
        ], 'BACKEND');

        $bal = $awarded;
    }

    eco_transition_status($con, $sessionId, $stationId, 'COMPLETED');
    eco_log_event($con, $sessionId, $stationId, 'COMPLETED', [
        'awarded_points' => $bal,
        'final_material' => $material,
        'final_weight'   => $weight,
    ], 'BACKEND');

    return [
        'awarded_points'       => $bal,
        'new_balance'          => eco_user_balance($con, $userId),
        'cap_state_after'      => eco_resident_cap_state($con, $userId),
    ];
}

function eco_user_balance(mysqli $con, int $userId): int {
    $stmt = $con->prepare("SELECT points FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['points'] ?? 0);
}
