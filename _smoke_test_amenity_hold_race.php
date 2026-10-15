<?php
/**
 * Amenity Hold — Concurrency (race) Test
 * ======================================
 * Spawns N concurrent PHP processes that all try to hold the SAME slot at the
 * same instant. Exactly ONE must win; the rest must get a conflict. This
 * exercises the resource-row FOR UPDATE serialization in amenity_holds.php.
 *
 * RUN (CLI only):
 *   C:\xampp\php\php.exe _smoke_test_amenity_hold_race.php
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$PHP = PHP_BINARY;

// ----- Worker mode ----------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    require_once __DIR__ . '/connect.php';
    require_once __DIR__ . '/amenity_holds.php';
    $uid = (int)($argv[2] ?? 1);
    $amenity = (string)($argv[3] ?? 'Tennis Court');
    $day = (string)($argv[4] ?? date('Y-m-d', strtotime('+45 days')));
    // Small random jitter so workers collide near-simultaneously.
    usleep(random_int(0, 30000));
    $res = vpAcquireHold($con, [
        'amenity'    => $amenity,
        'start_date' => $day,
        'end_date'   => $day,
        'start_time' => '08:00',
        'end_time'   => '09:00',
        'user_id'    => $uid,
        'account_type' => 'resident',
    ]);
    echo json_encode([
        'uid'      => $uid,
        'ok'       => !empty($res['ok']),
        'conflict' => !empty($res['conflict']),
        'token'    => $res['hold']['hold_token'] ?? null,
    ]);
    exit(0);
}

require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/amenity_holds.php';

echo "<pre style=\"font-family:monospace;font-size:12px;\">";
echo "[Amenity Hold Race Test] " . date('Y-m-d H:i:s') . "\n\n";

$AMENITY = 'Tennis Court';
$DAY = date('Y-m-d', strtotime('+45 days'));
$N = 8;
$uids = [];
for ($i = 0; $i < $N; $i++) {
    $uids[] = 991000 + $i;
}
$inList = implode(',', $uids);
$con->query("DELETE FROM amenity_slot_holds WHERE user_id IN ($inList)");

$procs = [];
$pipes = [];
for ($i = 0; $i < $N; $i++) {
    $cmd = escapeshellarg($PHP) . ' ' . escapeshellarg(__FILE__) . ' worker ' . $uids[$i] . ' ' . escapeshellarg($AMENITY) . ' ' . escapeshellarg($DAY);
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $p = proc_open($cmd, $descriptors, $pipes[$i]);
    if (is_resource($p)) {
        stream_set_blocking($pipes[$i][1], false);
        $procs[$i] = $p;
    }
}

// Collect outputs.
$results = [];
foreach ($procs as $i => $p) {
    $out = '';
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        $chunk = stream_get_contents($pipes[$i][1]);
        if ($chunk !== false && $chunk !== '') {
            $out .= $chunk;
        }
        $status = proc_get_status($p);
        if (!$status['running']) {
            $chunk = stream_get_contents($pipes[$i][1]);
            if ($chunk) { $out .= $chunk; }
            break;
        }
        usleep(20000);
    }
    fclose($pipes[$i][1]);
    fclose($pipes[$i][2]);
    proc_close($p);
    $decoded = json_decode(trim($out), true);
    $results[] = $decoded ?: ['raw' => $out];
}

$wins = 0;
$conflicts = 0;
$other = 0;
foreach ($results as $r) {
    if (!empty($r['ok'])) { $wins++; }
    elseif (!empty($r['conflict'])) { $conflicts++; }
    else { $other++; }
}

echo "Workers: " . count($results) . " (N=$N)\n";
foreach ($results as $r) {
    echo "  uid=" . ($r['uid'] ?? '?') . " ok=" . (!empty($r['ok']) ? 'yes' : 'no')
        . " conflict=" . (!empty($r['conflict']) ? 'yes' : 'no')
        . (!empty($r['raw']) ? ' raw=' . substr($r['raw'], 0, 120) : '') . "\n";
}

$held = $con->query("SELECT COUNT(*) c FROM amenity_slot_holds
        WHERE status='held' AND expires_at > NOW() AND amenity='" . $con->real_escape_string($AMENITY) . "'
          AND start_date='" . $con->real_escape_string($DAY) . "' AND start_time='08:00:00'")->fetch_assoc();
$heldCount = (int)$held['c'];

$con->query("DELETE FROM amenity_slot_holds WHERE user_id IN ($inList)");

echo "\nWinners: $wins   Conflicts: $conflicts   Other: $other\n";
echo "DB active holds for slot: $heldCount\n\n";

$ok = ($wins === 1 && $conflicts === $N - 1 && $heldCount === 1);
echo "RESULT: " . ($ok ? "ALL PASSED — exactly one winner" : "FAILED") . "\n";
echo "</pre>";

exit($ok ? 0 : 1);
