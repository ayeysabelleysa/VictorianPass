<?php
include('connect.php');

$houses = [
  ['VH-3002', 'Blk 7 Lot 16, Victorian Heights Subdivision'],
  ['VH-3003', 'Blk 7 Lot 17, Victorian Heights Subdivision'],
  ['VH-3004', 'Blk 7 Lot 18, Victorian Heights Subdivision'],
  ['VH-3005', 'Blk 7 Lot 19, Victorian Heights Subdivision'],
  ['VH-3006', 'Blk 7 Lot 20, Victorian Heights Subdivision'],
  ['VH-3007', 'Blk 8 Lot 1, Victorian Heights Subdivision'],
  ['VH-3008', 'Blk 8 Lot 2, Victorian Heights Subdivision'],
  ['VH-4001', 'Blk 9 Lot 4, Victorian Heights Subdivision'],
  ['VH-4002', 'Blk 9 Lot 5, Victorian Heights Subdivision'],
  ['VH-5001', 'Blk 11 Lot 2, Victorian Heights Subdivision'],
];

$stmt = $con->prepare("INSERT IGNORE INTO houses (house_number, address) VALUES (?, ?)");
$inserted = 0;
foreach ($houses as $h) {
  $stmt->bind_param('ss', $h[0], $h[1]);
  if ($stmt->execute()) {
    $inserted += ($stmt->affected_rows > 0) ? 1 : 0;
  }
}
$stmt->close();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Seed Houses</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet" />
  <style>
    body { font-family: 'Poppins', sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; background:#f7f7f7; }
    .card { background:#fff; padding:24px; border-radius:12px; box-shadow:0 6px 20px rgba(0,0,0,0.12); width:420px; }
    h1 { font-size:1.25rem; margin:0 0 12px; }
    p { margin:8px 0; }
    a { color:#23412e; text-decoration:none; font-weight:600; }
    a:hover { text-decoration:underline; }
    .count { color:#135f2a; font-weight:600; }
  </style>
  </head>
  <body>
    <div class="card">
      <h1>House Seed Complete</h1>
      <p>Inserted <span class="count"><?php echo $inserted; ?></span> new house records (duplicates were ignored).</p>
      <p><a href="signup.php">Go to Sign Up</a></p>
    </div>
  </body>
</html>