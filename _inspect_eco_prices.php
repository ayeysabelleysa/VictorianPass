<?php
require __DIR__ . '/connect.php';
if (!($con instanceof mysqli)) { echo "NO CON\n"; exit; }
$tables = ['reservations', 'resident_reservations', 'guest_forms', 'guest_forms_old', 'reservation_history'];
foreach ($tables as $t) {
  $cols = [];
  $q = $con->query("SHOW COLUMNS FROM `$t`");
  if (!$q) { continue; }
  while ($c = $q->fetch_assoc()) { $cols[$c['Field']] = $c['Type']; }
  $use = array_intersect(['use_points', 'points_used'], array_keys($cols));
  if (!$use) { echo "== $t: no points cols ==\n"; continue; }
  echo "== $t ==\n";
  $sel = [];
  foreach (array_intersect(['price','downpayment','use_points','points_used','amenity','start_date','end_date','start_time','end_time','payment_status','approval_status','status'], array_keys($cols)) as $f) { $sel[] = "`$f`"; }
  $q2 = $con->query("SELECT " . implode(',', $sel) . " FROM `$t` WHERE `use_points` = 1 OR `points_used` > 0 ORDER BY id DESC LIMIT 30");
  if (!$q2) { echo "  (query failed: {$con->error})\n"; continue; }
  $n = 0;
  while ($r = $q2->fetch_assoc()) {
    $n++;
    echo "  #$n " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
  }
  if ($n === 0) echo "  (no point rows)\n";
  $q2->free();
}
$q->free();
