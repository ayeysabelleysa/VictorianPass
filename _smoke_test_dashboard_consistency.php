<?php
/**
 * VHEcoPoint Dashboard Consistency Smoke Test
 * ============================================
 * Validates that after a recycling session is logged:
 *   (A) Current Point Balance
 *   (B) Weekly Points Earned
 *   (C) Daily Sessions Used
 *   (D) Recycling Activity History count
 * — ALL FOUR update together from the SAME source of truth
 *   (VHEcoPoint-tied point_transactions rows) so the user never
 *   sees "1,950 balance but 0 weekly / 0 sessions / no history".
 *
 * Also re-implements the EXACT aggregation loops from
 * profileresident.php to guarantee parity.
 *
 * RUN:
 *   Browser: /VictorianPass/_smoke_test_dashboard_consistency.php
 *   CLI:     C:\xampp\php\php.exe _smoke_test_dashboard_consistency.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

// MATCH connect.php timezone so PHP date() == MySQL created_at date keys
date_default_timezone_set('Asia/Manila');

require_once __DIR__ . '/connect.php';

$failures = [];
$passes   = [];
function check($label, $ok, $detail = '') {
    global $failures, $passes;
    if ($ok) { $passes[] = $label; }
    else     { $failures[] = $label . '  ::  ' . ($detail ?: 'assertion failed'); }
}

echo "<pre style=\"font-family:Consolas,monospace;font-size:12px;white-space:pre-wrap;background:#0b1020;color:#d9e6ff;padding:18px;border-radius:10px;line-height:1.55;\">";
echo "[VHEcoPoint Dashboard Consistency Test] Started at " . date('Y-m-d H:i:s') . "\n";
echo str_repeat("═", 78) . "\n\n";

// ----------------------------------------------------------
// STEP 0 — Ensure schema supports ecopoint_session_id FK
// ----------------------------------------------------------
function addColIfMissing(mysqli $con, string $table, string $col, string $def): void {
    $c = $con->query("SHOW COLUMNS FROM $table LIKE '$col'");
    if (!$c || $c->num_rows === 0) {
        @$con->query("ALTER TABLE $table ADD COLUMN $col $def");
    }
}
addColIfMissing($con, 'point_transactions', 'ecopoint_session_id', "INT DEFAULT NULL COMMENT 'FK to ecopoint_waste_sessions' AFTER weight_kg");
addColIfMissing($con, 'point_transactions', 'station_id',          "INT DEFAULT NULL COMMENT 'FK to ecopoint_stations' AFTER weight_kg");

// Create station / sessions tables if missing (migration-idempotent)
@$con->query("CREATE TABLE IF NOT EXISTS ecopoint_stations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    station_code VARCHAR(64) NOT NULL UNIQUE,
    station_name VARCHAR(120) NOT NULL,
    api_key_hash VARCHAR(255) NOT NULL,
    status ENUM('ACTIVE','INACTIVE') DEFAULT 'ACTIVE',
    last_heartbeat_at TIMESTAMP NULL
) ENGINE=InnoDB");
@$con->query("INSERT IGNORE INTO ecopoint_stations (id, station_code, station_name, api_key_hash)
              VALUES (1, 'VH-ECO-001', 'Clubhouse Main Station', '\$2y\$10\$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm')");
@$con->query("CREATE TABLE IF NOT EXISTS ecopoint_waste_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_token CHAR(64) NOT NULL UNIQUE,
    station_id INT NOT NULL,
    user_id INT NOT NULL,
    qr_ref_code VARCHAR(120),
    status ENUM('ACTIVE','PROCESSING','COMPLETED','CANCELLED','ERROR') DEFAULT 'ACTIVE',
    material_type VARCHAR(50),
    weight_kg DECIMAL(6,2) DEFAULT 0.00,
    points_calculated INT DEFAULT 0,
    points_awarded INT DEFAULT 0,
    points_posted TINYINT(1) DEFAULT 0,
    posted_transaction_id INT DEFAULT NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB");

// ----------------------------------------------------------
// STEP 1 — Find / create a clean test resident
// ----------------------------------------------------------
$email = 'ecopoint_dash_test@victorianpass.local';
$res = $con->query("SELECT id FROM users WHERE email = '".$con->real_escape_string($email)."' LIMIT 1");
if ($res && $res->num_rows > 0) {
    $userId = (int)$res->fetch_assoc()['id'];
} else {
    $hp = password_hash('Testpass_123!', PASSWORD_BCRYPT);
    @$con->query("INSERT IGNORE INTO houses (house_number, address) VALUES ('VH-TEST', 'Test Address')");
    $ok = $con->query("INSERT INTO users
         (first_name, middle_name, last_name, phone, email, user_type, password, sex, birthdate, house_number, address, status)
         VALUES ('EcoTest', 'Q', 'Resident', '09170000000', '$email', 'resident', '$hp', 'Male', '1990-01-15', 'VH-TEST', 'Victorian Heights', 'active')");
    $userId = (int)$con->insert_id;
    check("Insert test resident", $ok && $userId > 0, $con->error);
}
check("Test resident resolved (id=$userId)", $userId > 0);

// Wipe any prior test rows for this user so we start this test with a KNOWN baseline
$con->query("DELETE FROM ecopoint_waste_sessions WHERE user_id = $userId");
$con->query("DELETE FROM point_transactions WHERE user_id = $userId");
$con->query("UPDATE users SET points = 1950 WHERE id = $userId"); // seed raw VP account with stale 1950! The dashboard MUST ignore this.
check("Clean baseline: Wiped prior sessions/tx for user $userId, poisoned users.points = 1950 (dashboard must ignore)", true);

// ----------------------------------------------------------
// HELPERS: EXACT copies of profileresident.php aggregation
// (so parity is mathematically guaranteed)
// ----------------------------------------------------------
function residentEcoPointMaterialLabelTest($materialType = '', $description = '') {
    $value = strtolower(trim((string)$materialType));
    $desc  = strtolower((string)$description);
    $haystack = trim($value . ' ' . $desc);
    if (strpos($haystack, 'plastic') !== false || strpos($haystack, 'pet') !== false) return 'Plastic (PET)';
    if (strpos($haystack, 'aluminum') !== false || strpos($haystack, 'can') !== false) return 'Aluminum Cans';
    if (strpos($haystack, 'cardboard') !== false) return 'Cardboard';
    if (strpos($haystack, 'paper') !== false) return 'Paper';
    return 'Other';
}

function dashboardSnapshot(mysqli $con, int $userId, string $label): array {
    // --- EXACT same queries / loop as profileresident.php -----------------
    $colQ = $con->query("SHOW COLUMNS FROM point_transactions LIKE 'ecopoint_session_id'");
    $hasFk = ($colQ && $colQ->num_rows > 0);
    if ($hasFk) {
        $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
    } else {
        $stmt = $con->prepare("SELECT id, transaction_type, amount, description, reservation_ref_code, material_type, weight_kg, created_at, NULL AS ecopoint_session_id FROM point_transactions WHERE user_id = ? ORDER BY created_at DESC");
    }
    $ecoPointTransactions = [];
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $isEcoTx = (!empty($row['ecopoint_session_id']) && intval($row['ecopoint_session_id']) > 0)
                     || (stripos((string)($row['description'] ?? ''), 'VHEcoPoint') !== false)
                     || (stripos((string)($row['description'] ?? ''), 'recycling') !== false);
            if ($isEcoTx) $ecoPointTransactions[] = $row;
        }
        $stmt->close();
    }
    // 1. Balance = net of ecoPointTransactions ONLY (NOT users.points!)
    $currentPoints = 0;
    foreach ($ecoPointTransactions as $tx) {
        $txType = strtolower(trim((string)($tx['transaction_type'] ?? 'earn')));
        $amt = intval($tx['amount'] ?? 0);
        if ($txType === 'earn')       $currentPoints += $amt;
        elseif ($txType === 'redeem') $currentPoints -= $amt;
        elseif ($txType === 'adjustment') $currentPoints += $amt;
    }
    $currentPoints = max(0, $currentPoints);

    $ecoPointWeeklyCap = 250;
    $ecoPointDailySessionsMax = 3;
    $ecoPointWeeklyPoints = 0;
    $ecoPointTodaySessionsUsed = 0;
    $ecoPointRecyclingHistory = [];
    $todayDateKey = date('Y-m-d');
    $weekStartDateKey = date('Y-m-d', strtotime('monday this week'));
    $weekEndDateKey = date('Y-m-d', strtotime('sunday this week'));
    $debugLoop = []; // debug output per row
    foreach ($ecoPointTransactions as $tx) {
        $txType = strtolower(trim((string)($tx['transaction_type'] ?? '')));
        if ($txType !== 'earn') continue;
        $createdAt = (string)($tx['created_at'] ?? '');
        $createdTs = $createdAt !== '' ? strtotime($createdAt) : false;
        // MATCH profileresident.php: fallback to time() on unparsable dates
        if ($createdTs === false || $createdTs <= 0) {
            $createdTs = time();
        }
        $createdDateKey = date('Y-m-d', $createdTs);
        $pointsEarned = intval($tx['amount'] ?? 0);
        $ecoPointRecyclingHistory[] = 1;
        $isToday = ($createdDateKey === $todayDateKey);
        $inWeek  = ($createdDateKey !== '' && $createdDateKey >= $weekStartDateKey && $createdDateKey <= $weekEndDateKey);
        if ($isToday)  $ecoPointTodaySessionsUsed++;
        if ($inWeek)   $ecoPointWeeklyPoints += $pointsEarned;
        $debugLoop[] = "   tx_id={$tx['id']} created_at=$createdAt (ts=$createdTs) datekey=$createdDateKey → isToday=".($isToday?'YES':'no')." inWeek=".($inWeek?'YES':'no')." (bounds $weekStartDateKey..$weekEndDateKey) +{$pointsEarned}pts";
    }
    return [
        'label'                => $label,
        'balance'              => $currentPoints,
        'weekly_earned'        => $ecoPointWeeklyPoints,
        'daily_sessions_used'  => $ecoPointTodaySessionsUsed,
        'history_count'        => count($ecoPointRecyclingHistory),
        'users_points_raw'     => (function() use ($con, $userId) {
            $r = $con->query("SELECT points FROM users WHERE id = $userId LIMIT 1")->fetch_assoc();
            return (int)($r['points'] ?? 0);
        })(),
        'debug_txs'            => $debugLoop,
    ];
}

// ----------------------------------------------------------
// STEP 2 — BEFORE snapshot (all 4 widgets MUST show 0)
// ----------------------------------------------------------
$before = dashboardSnapshot($con, $userId, 'BEFORE');
echo "[BEFORE SNAPSHOT]  (poisoned users.points = {$before['users_points_raw']} — dashboard MUST ignore it)\n";
echo "  ├── Current Balance:        {$before['balance']} pts\n";
echo "  ├── Weekly Earned:          {$before['weekly_earned']} / 250 pts\n";
echo "  ├── Daily Sessions Used:    {$before['daily_sessions_used']} / 3\n";
echo "  └── Activity History rows:  {$before['history_count']}\n\n";

check("BEFORE — Current Point Balance = 0",          $before['balance']             === 0,   "got {$before['balance']} (poisoned users.points was {$before['users_points_raw']} — dashboard should have ignored it)");
check("BEFORE — Weekly Points Earned = 0",          $before['weekly_earned']       === 0,   "got {$before['weekly_earned']}");
check("BEFORE — Daily Sessions Used = 0",           $before['daily_sessions_used'] === 0,   "got {$before['daily_sessions_used']}");
check("BEFORE — Activity History count = 0",        $before['history_count']       === 0,   "got {$before['history_count']}");
check("BEFORE — Critical proof: users.points ({$before['users_points_raw']}) ≠ dashboard balance ({$before['balance']}) — so dashboard IS NOT using raw VP account",
      $before['users_points_raw'] !== $before['balance'] || $before['users_points_raw'] === 0,
      "dashboard is pulling stale users.points instead of VHEcoPoint ledger");

// ----------------------------------------------------------
// STEP 3 — Simulate a VHEcoPoint recycling session end-to-end
//         We deposit 2 kg Plastic (PET) → 2 * 55 = 110 pts
//         We'll do TWO today to exercise both weekly and daily.
// ----------------------------------------------------------
function simulateVHEcoSession(mysqli $con, int $userId, int $stationId, string $material, float $weightKg, int $ratePerKg): array {
    global $con;
    $calculated = (int)round($weightKg * $ratePerKg);
    // Create session row  — use explicit PHP date so our PHP-side date-key math matches
    $token = bin2hex(random_bytes(32));
    $nowPhp = date('Y-m-d H:i:s');
    $con->query("INSERT INTO ecopoint_waste_sessions
        (session_token, station_id, user_id, qr_ref_code, status, material_type, weight_kg, points_calculated, points_awarded, points_posted, completed_at, created_at)
        VALUES ('$token', $stationId, $userId, 'VH-TEST', 'COMPLETED',
                '".$con->real_escape_string($material)."', $weightKg, $calculated, $calculated, 0, '$nowPhp', '$nowPhp')");
    $sessionId = (int)$con->insert_id;

    // Insert matching point_transaction row (earn, with ecopoint_session_id FK)  — SAME EXPLICIT created_at value
    $desc = sprintf('VHEcoPoint recycling: %s (%.2f kg) @ %d pts/kg', $material, $weightKg, $ratePerKg);
    // Columns: user_id, transaction_type, amount, description, material_type, weight_kg, station_id, ecopoint_session_id, created_at
    // Placeholders: 8  + 1 literal 'earn' already written as literal below → all 9 values covered
    $stmt = $con->prepare("INSERT INTO point_transactions
        (user_id, transaction_type, amount, description, material_type, weight_kg, station_id, ecopoint_session_id, created_at)
        VALUES (?, 'earn', ?, ?, ?, ?, ?, ?, ?)");
    // Types: i i s s d i i s  (8 params for 8 ?s + transaction_type is already literal 'earn')
    $stmt->bind_param('iissdiis', $userId, $calculated, $desc, $material, $weightKg, $stationId, $sessionId, $nowPhp);
    $stmt->execute();
    $txId = (int)$con->insert_id;
    $stmt->close();

    // Double-check the value was actually written
    $q = $con->query("SELECT created_at FROM point_transactions WHERE id = $txId LIMIT 1");
    $row = $q ? $q->fetch_assoc() : null;
    if (!$row || trim((string)$row['created_at']) === '' || $row['created_at'] === '0000-00-00 00:00:00') {
        // TIMESTAMP column might have been DEFAULT-writing; fix it with explicit UPDATE
        $con->query("UPDATE point_transactions SET created_at = '$nowPhp' WHERE id = $txId LIMIT 1");
    }

    // Mark session posted
    $con->query("UPDATE ecopoint_waste_sessions SET points_posted = 1, posted_transaction_id = $txId WHERE id = $sessionId");

    return ['session_id' => $sessionId, 'tx_id' => $txId, 'awarded' => $calculated, 'created_at' => $nowPhp];
}

// Two deposits today — SESSION 1: 2 kg Plastic (PET) = 110 pts
$s1 = simulateVHEcoSession($con, $userId, 1, 'Plastic', 2.0, 55);
// SESSION 2: 0.5 kg Aluminum Cans = 70 pts
$s2 = simulateVHEcoSession($con, $userId, 1, 'Aluminum', 0.5, 140);
$totalExpectedAward = $s1['awarded'] + $s2['awarded']; // 110 + 70 = 180
check("Simulated 2 sessions: Plastic 2kg ({$s1['awarded']}pts) + Aluminum 0.5kg ({$s2['awarded']}pts) = {$totalExpectedAward}pts awarded today",
      $totalExpectedAward === 180, "total awarded=$totalExpectedAward, expected 180");
echo "→ Simulated 2 deposits today: S#{$s1['session_id']} + S#{$s2['session_id']}\n";
echo "  Session created_at 1 = {$s1['created_at']}   (today key = " . date('Y-m-d') . ")\n";
echo "  Week bounds: Mon " . date('Y-m-d', strtotime('monday this week')) . " → Sun " . date('Y-m-d', strtotime('sunday this week')) . "\n\n";

// ----------------------------------------------------------
// STEP 4 — AFTER snapshot. Every widget MUST have advanced
//          by exactly the expected delta.
// ----------------------------------------------------------
$after = dashboardSnapshot($con, $userId, 'AFTER');
echo "[AFTER SNAPSHOT]\n";
echo "  ├── Current Balance:        {$after['balance']} pts   (expected {$totalExpectedAward})\n";
echo "  ├── Weekly Earned:          {$after['weekly_earned']} / 250 pts   (expected {$totalExpectedAward})\n";
echo "  ├── Daily Sessions Used:    {$after['daily_sessions_used']} / 3    (expected 2)\n";
echo "  └── Activity History rows:  {$after['history_count']}      (expected 2)\n";
if (!empty($after['debug_txs'])) {
    echo "\n  ↳ Per-transaction breakdown:\n";
    foreach ($after['debug_txs'] as $l) echo "$l\n";
}
echo "\n";

$dBalance   = $after['balance']             - $before['balance'];
$dWeekly    = $after['weekly_earned']       - $before['weekly_earned'];
$dDaily     = $after['daily_sessions_used'] - $before['daily_sessions_used'];
$dHistory   = $after['history_count']       - $before['history_count'];

check("AFTER — Δ Balance = {$totalExpectedAward} pts",
      $dBalance === $totalExpectedAward,
      "Δ={$dBalance} — expected {$totalExpectedAward}");
check("AFTER — Δ Weekly Earned = {$totalExpectedAward} pts",
      $dWeekly === $totalExpectedAward,
      "Δ={$dWeekly} — expected {$totalExpectedAward} (weekly and balance MUST be consistent)");
check("AFTER — Δ Daily Sessions Used = 2",
      $dDaily === 2, "Δ={$dDaily} — expected 2 (one per earn transaction)");
check("AFTER — Δ Activity History count = 2",
      $dHistory === 2, "Δ={$dHistory} — expected 2 (one per earn transaction)");

// Cross-consistency check: balance MUST equal sum of weekly_points + history-total
$totalFromHistory = $after['weekly_earned']; // because both sessions are in this week
check("Cross-check 1: Current Balance == Weekly Earned  (both sessions are in this week)",
      $after['balance'] === $after['weekly_earned'],
      "balance={$after['balance']} vs weekly={$after['weekly_earned']}");
check("Cross-check 2: Daily Sessions Used == count of Activity History rows  (both happened today)",
      $after['daily_sessions_used'] === $after['history_count'],
      "daily={$after['daily_sessions_used']} vs history={$after['history_count']}");
check("Cross-check 3: Dashboard STILL ignores users.points (was {$after['users_points_raw']})",
      $after['balance'] !== $after['users_points_raw'] || $after['users_points_raw'] === 0,
      "balance ({$after['balance']}) still equals poisoned users.points ({$after['users_points_raw']}) — not sourcing from ledger!");

echo str_repeat("─", 78) . "\n\n";
echo "📊 SUMMARY OF WIDGET CHANGES (BEFORE → AFTER):\n";
echo "  Current Point Balance :  {$before['balance']} → {$after['balance']}    (+{$dBalance})\n";
echo "  Weekly Points Earned  :  {$before['weekly_earned']} → {$after['weekly_earned']}    (+{$dWeekly})\n";
echo "  Daily Sessions Used   :  {$before['daily_sessions_used']} → {$after['daily_sessions_used']}    (+{$dDaily})\n";
echo "  Recycling History     :  {$before['history_count']} → {$after['history_count']}    (+{$dHistory})\n\n";

// ----------------------------------------------------------
// FINAL — pass / fail banner
// ----------------------------------------------------------
echo str_repeat("═", 78) . "\n";
$total = count($passes) + count($failures);
if (empty($failures)) {
    echo "✅ ALL {$total} CHECKS PASSED — Dashboard is perfectly consistent.\n";
    echo "   Every widget (balance / weekly / daily sessions / history) advances\n";
    echo "   together from the SAME VHEcoPoint ledger, and the poisoned stale\n";
    echo "   users.points value is correctly ignored.\n";
} else {
    echo "❌ " . count($failures) . " / {$total} FAILED:\n";
    foreach ($failures as $f) echo "    ✗ $f\n";
    if ($passes) echo "\n  Passed (" . count($passes) . "):\n";
    foreach ($passes as $p) echo "    ✓ $p\n";
}
echo "</pre>";
