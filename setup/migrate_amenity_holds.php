<?php
/**
 * One-time Amenity Holds / Waitlist schema setup.
 * Run from the project root with: php setup/migrate_amenity_holds.php
 *
 * Creates app_settings, amenity_slot_locks, amenity_slot_holds and
 * amenity_waitlist. Safe to run repeatedly (CREATE TABLE IF NOT EXISTS).
 * CLI-only; never included by a web page.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/../amenity_holds.php';

if (!($con instanceof mysqli) || $con->connect_errno) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

// Force the schema ensure to run even if the runtime flag already exists.
foreach (['amenity_holds_v1', 'amenity_holds_v2'] as $flagKey) {
    $flag = __DIR__ . '/../schema_flags/' . $flagKey . '.done';
    if (is_file($flag)) {
        @unlink($flag);
    }
}

vpAmenityHoldsEnsureSchema($con);

$tables = ['app_settings', 'amenity_slot_locks', 'amenity_slot_holds', 'amenity_waitlist'];
$ok = true;
foreach ($tables as $t) {
    $stmt = $con->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
    $stmt->bind_param('s', $t);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    echo ($exists ? "[ok]   " : "[MISS] ") . $t . "\n";
    if (!$exists) {
        $ok = false;
    }
}

// v2 columns/enum on amenity_waitlist.
foreach (['offer_hold_token', 'last_reason'] as $col) {
    $stmt = $con->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'amenity_waitlist' AND COLUMN_NAME = ? LIMIT 1");
    $stmt->bind_param('s', $col);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    echo ($exists ? "[ok]   " : "[MISS] ") . "amenity_waitlist." . $col . "\n";
    if (!$exists) { $ok = false; }
}
$enumRow = $con->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='amenity_waitlist' AND COLUMN_NAME='status'")->fetch_assoc();
$hasUnavailable = $enumRow && strpos((string)$enumRow['COLUMN_TYPE'], "'unavailable'") !== false;
echo ($hasUnavailable ? "[ok]   " : "[MISS] ") . "amenity_waitlist.status has 'unavailable'\n";
if (!$hasUnavailable) { $ok = false; }

// Verify schema flags were written so runtime requests skip the DDL.
vpMarkSchemaDone($con, 'amenity_holds_v1');
vpMarkSchemaDone($con, 'amenity_holds_v2');
echo "schema flag: amenity_holds_v1 = " . (vpSchemaDone($con, 'amenity_holds_v1') ? 'set' : 'missing') . "\n";
echo "schema flag: amenity_holds_v2 = " . (vpSchemaDone($con, 'amenity_holds_v2') ? 'set' : 'missing') . "\n";

echo "Amenity holds schema setup " . ($ok ? "complete.\n" : "INCOMPLETE.\n");
exit($ok ? 0 : 1);
