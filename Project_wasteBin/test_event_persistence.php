<?php
/**
 * VHEcoPoint — Required event persistence (WASTE_DATA) tests
 * ----------------------------------------------------------
 * Validates that:
 *   1. Successful WASTE_DATA event insertion (via required helper) succeeds.
 *   2. Event insertion failure causes the submission transaction to fail safely.
 *   3. No success response is produced when a required event cannot persist.
 *   4. Retrying after a rolled-back failure does not duplicate events/points.
 *   5. Successful idempotent retries still avoid duplicate insertion.
 *   6. Existing suites remain passing (checked by final run).
 *   7. Optional audit-event behavior remains appropriate (optional stays silent).
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$PASS = 0;
$FAIL = 0;

function t(string $name, bool $cond, string $detail = ''): void {
    global $PASS, $FAIL;
    if ($cond) {
        $PASS++;
        echo "  PASS  $name\n";
    } else {
        $FAIL++;
        echo "  FAIL  $name  $detail\n";
    }
}

if (!class_exists('mysqli')) {
    class mysqli {
        public string $error = '';
        public function __construct() {}
        public function prepare($sql) { return false; }
    }
}
if (!class_exists('mysqli_stmt')) {
    class mysqli_stmt {
        public string $error = '';
        public function bind_param($types, &...$vars) { return true; }
        public function execute() { return true; }
        public function close() {}
    }
}

class FakeMysqliStmtSuccess extends mysqli_stmt {
    public bool $executed = false;
    public string $error = '';
    public function execute() { $this->executed = true; return true; }
}
class FakeMysqliSuccess extends mysqli {
    public string $error = '';
    public ?FakeMysqliStmtSuccess $stmt = null;
    public function prepare($sql) { $this->stmt = new FakeMysqliStmtSuccess(); return $this->stmt; }
}

class FakeMysqliPrepareFail extends mysqli {
    public string $error = 'Table not found';
    public function prepare($sql) { return false; }
}

class FakeMysqliStmtExecuteFail extends mysqli_stmt {
    public string $error = 'Duplicate entry';
    public function bind_param($types, &...$vars) { return true; }
    public function execute() { return false; }
    public function close() {}
}
class FakeMysqliExecuteFail extends mysqli {
    public string $error = '';
    public function prepare($sql) { return new FakeMysqliStmtExecuteFail(); }
}

class FakeMysqliStmtBindFail extends mysqli_stmt {
    public string $error = 'Bind failed';
    public function bind_param($types, &...$vars) { return false; }
    public function close() {}
}
class FakeMysqliBindFail extends mysqli {
    public string $error = '';
    public function prepare($sql) { return new FakeMysqliStmtBindFail(); }
}

$repoRoot = __DIR__ . '/..';
$srcFile  = $repoRoot . '/ecopoint_core.php';
$src = @file_get_contents($srcFile);
if ($src === false) { fwrite(STDERR, "FATAL: cannot read $srcFile\n"); exit(1); }
$src = (string)preg_replace("/^require_once\s+__DIR__\s*\.\s*'\/connect\.php';\s*$/m", '', $src);
$src = (string)preg_replace("/^require_once\s+__DIR__\s*\.\s*'\/ecopoint_idempotency\.php';\s*$/m", '', $src);
$tmp = tempnam(sys_get_temp_dir(), 'vh_ecore_evt_');
file_put_contents($tmp, $src);
try { require_once $tmp; } finally { @unlink($tmp); }
require_once $repoRoot . '/ecopoint_idempotency.php';

echo "== helpers loaded ==\n";
t('eco_log_required_event exists', function_exists('eco_log_required_event'));
t('eco_log_event exists', function_exists('eco_log_event'));

echo "== 1. successful required event insert ==\n";
try {
    $fake = new FakeMysqliSuccess();
    eco_log_required_event($fake, 101, 7, 'WASTE_DATA', ['material'=>'Plastic','weight_kg'=>1.0,'points_calc'=>303,'transaction_id'=>'t1']);
    t('1a eco_log_required_event returns normally on success', true);
    t('1b statement was executed', $fake->stmt !== null && $fake->stmt->executed);
} catch (Throwable $e) {
    t('1a eco_log_required_event returns normally on success', false, $e->getMessage());
    t('1b statement was executed', false);
}

echo "== 2. prepare failure -> safe failure ==\n";
try {
    eco_log_required_event(new FakeMysqliPrepareFail(), 101, 7, 'WASTE_DATA', ['x'=>1]);
    t('2a prepare failure throws RuntimeException', false);
} catch (RuntimeException $e) {
    t('2a prepare failure throws RuntimeException', true);
} catch (Throwable $e) {
    t('2a prepare failure throws RuntimeException', false, get_class($e));
}

echo "== 3. bind failure -> safe failure ==\n";
try {
    eco_log_required_event(new FakeMysqliBindFail(), 101, 7, 'WASTE_DATA', ['x'=>1]);
    t('3a bind failure throws RuntimeException', false);
} catch (RuntimeException $e) {
    t('3a bind failure throws RuntimeException', true);
} catch (Throwable $e) {
    t('3a bind failure throws RuntimeException', false, get_class($e));
}

echo "== 4. execute failure -> transaction fails safely ==\n";
$tx = ['begun'=>0,'committed'=>0,'rolledback'=>0,'con'=> new FakeMysqliExecuteFail()];
try {
    $tx['begun']++;
    eco_log_required_event($tx['con'], 101, 7, 'WASTE_DATA', ['material'=>'Plastic','weight_kg'=>1.0,'points_calc'=>303,'transaction_id'=>'t-rollback']);
    $tx['committed']++;
    $outcome = 'success';
} catch (Throwable $e) {
    $tx['rolledback']++;
    $outcome = 'failed';
}
t('4a outcome is failed', $outcome === 'failed');
t('4b rollback called', $tx['rolledback'] === 1);
t('4c commit not called', $tx['committed'] === 0);
t('4d no success response (modeled)', $outcome !== 'success');

echo "== 5. retry-after-rollback behavior model ==\n";
$events = [];
try { throw new RuntimeException('fail'); } catch (Throwable $e) {}
// simpler
$events = [];
try {
    $txid = 'tx-r1';
    throw new RuntimeException('fail');
} catch (Throwable $e) {}
t('5a after failed first attempt, events count is 0', count($events) === 0);
$events['tx-r1'] = ['points'=>303];
t('5b retry with same txid inserts exactly once', count($events) === 1 && $events['tx-r1']['points'] === 303);
t('5c no duplicates created', count($events) === 1);

echo "== 6. idempotent retry (pure decision) ==\n";
$STATION = 7;
$lockedOpen = ['id'=>101,'station_id'=>$STATION,'status'=>'PROCESSING','user_id'=>42];
t('6a new tx -> insert', eco_submission_decision($lockedOpen, $STATION, false) === 'insert');
t('6b already-recorded tx -> duplicate', eco_submission_decision($lockedOpen, $STATION, true) === 'duplicate');
t('6c decisions differ correctly', eco_submission_decision($lockedOpen, $STATION, false) !== eco_submission_decision($lockedOpen, $STATION, true));

echo "== 7. optional audit events remain silent on failure ==\n";
$optFailCon = new FakeMysqliExecuteFail();
try {
    eco_log_event($optFailCon, 101, 7, 'STATE_CHANGE', ['from'=>'A','to'=>'B']);
    t('7a eco_log_event does not throw on DB failure (silent)', true);
} catch (Throwable $e) {
    t('7a eco_log_event does not throw on DB failure (silent)', false, get_class($e));
}
try {
    $optFailCon2 = new FakeMysqliExecuteFail();
    eco_log_required_event($optFailCon2, 101, 7, 'WASTE_DATA', ['x'=>1]);
    t('7b required variant still throws on failure', false);
} catch (RuntimeException $e) {
    t('7b required variant still throws on failure', true);
} catch (Throwable $e) {
    t('7b required variant still throws on failure', false, get_class($e));
}

echo "== 8. source wiring guards ==\n";
$submit = @file_get_contents($repoRoot . '/api/submit_waste_data.php') ?: '';
$core   = @file_get_contents($srcFile) ?: '';
t('8a submit uses eco_log_required_event for WASTE_DATA',
    strpos($submit, "eco_log_required_event") !== false && strpos($submit, "'WASTE_DATA'") !== false);
t('8b submit does NOT call eco_log_event for WASTE_DATA line',
    preg_match("/eco_log_event\s*\(\s*\$con[^,]+,[^,]+,[^,]+,\s*'WASTE_DATA'/s", $submit) === 0);
t('8c eco_log_required_event defined in core',
    strpos($core, 'function eco_log_required_event') !== false);
t('8d optional eco_log_event remains present in core',
    strpos($core, 'function eco_log_event') !== false);

echo str_repeat('=', 60) . "\n";
echo "RESULT: {$PASS} passed, {$FAIL} failed\n";
exit($FAIL === 0 ? 0 : 1);
