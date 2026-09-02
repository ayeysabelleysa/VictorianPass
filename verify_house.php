<?php
include("connect.php");

if (!function_exists('vpSchemaDone')) {
  function vpSchemaDone($con, $key) {
    $dir = __DIR__ . '/schema_flags';
    if (!is_dir($dir)) return false;
    $flag = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.done';
    return @file_exists($flag);
  }
}
if (!function_exists('vpMarkSchemaDone')) {
  function vpMarkSchemaDone($con, $key) {
    $dir = __DIR__ . '/schema_flags';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $flag = $dir . '/' . preg_replace('/[^a-z0-9_]/i', '_', $key) . '.done';
    @file_put_contents($flag, '1');
  }
}
if (!vpSchemaDone($con, 'houses_v1') && ($con instanceof mysqli)) {
  $countRow = $con->query("SELECT COUNT(*) AS c FROM houses");
  $houseCount = $countRow ? intval($countRow->fetch_assoc()['c']) : 0;
  if ($houseCount < 2220) {
    @$con->begin_transaction();
    $batch = [];
    for ($i = 1; $i <= 2220; $i++) {
      $hn = 'VH-' . str_pad((string)$i, 4, '0', STR_PAD_LEFT);
      $batch[] = "('$hn','Victorian Heights Subdivision')";
    }
    $chunks = array_chunk($batch, 500);
    foreach ($chunks as $chunk) {
      $vals = implode(',', $chunk);
      @$con->query("INSERT IGNORE INTO houses (house_number, address) VALUES $vals");
    }
    @$con->commit();
  }
  vpMarkSchemaDone($con, 'houses_v1');
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $house_number = trim($_POST['house_number']);
  $isAjax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

  if (empty($house_number)) {
    if ($isAjax) {
      header('Content-Type: application/json');
      echo json_encode(['success' => false, 'message' => 'Please enter your House Number.']);
      exit;
    }
    echo "<script>alert('Please enter your House Number.');</script>";
  } else {
    $check = $con->prepare("SELECT id FROM houses WHERE house_number = ?");
    $check->bind_param("s", $house_number);
    $check->execute();
    $result = $check->get_result();

    if ($result->num_rows > 0) {
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'house_number' => $house_number]);
        exit;
      }
      // ✅ Redirect silently without alert; final notification will occur after full signup submission
      header("Location: signup.php?house_number=" . urlencode($house_number));
      exit;
    } else {
      if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid or unregistered House Number!']);
        exit;
      }
      echo "<script>alert('Invalid or unregistered House Number!');</script>";
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Verify House Number</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    body {
      font-family: 'Poppins', sans-serif;
      background: url('images/signuppage/bgsignup.png') no-repeat center/cover;
      display: flex; justify-content: center; align-items: center;
      height: 100vh; margin: 0;
    }
    .verify-box {
      background: rgba(255,255,255,0.95);
      padding: 30px; border-radius: 12px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.2);
      width: 320px; text-align: center;
    }
    input {
      width: 90%; padding: 10px; margin: 10px 0;
      border: 1px solid #ccc; border-radius: 8px;
      font-family: 'Poppins', sans-serif;
    }
    button {
      padding: 10px 20px; background: #23412e; color: #fff;
      border: none; border-radius: 8px; cursor: pointer;
      font-family: 'Poppins', sans-serif;
    }
    button:hover { background: #2e5d3b; }
    a {
      display: inline-block; margin-top: 15px;
      color: #23412e; text-decoration: none;
    }
    a:hover { text-decoration: underline; }
    .back-link{display:inline-flex;align-items:center;gap:8px}
    .back-link i{color:#f2c24f}
  </style>
</head>
<body>
  <div class="verify-box">
    <h2>Resident Verification</h2>
    <p>Enter your registered House Number</p>
    <form method="POST" action="">
      <input type="text" name="house_number" placeholder="e.g., VH-1023" required>
      <button type="submit">Verify</button>
    </form>
    <a href="signup.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Sign Up</a>
  </div>
</body>
</html>
