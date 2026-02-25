<?php
include('connect.php');

$start = 1;
$end = 2220;
$address = 'Victorian Heights Subdivision';

$con->query("DELETE FROM houses");

$stmt = $con->prepare("INSERT INTO houses (house_number, address) VALUES (?, ?)");
$inserted = 0;
for ($i = $start; $i <= $end; $i++) {
  $house = 'VH-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
  $stmt->bind_param('ss', $house, $address);
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
