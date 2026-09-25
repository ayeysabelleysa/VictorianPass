<?php
require_once __DIR__ . '/connect.php';
require_once __DIR__ . '/test_account_seed.php';

$result = vpEnsureTestAccount($con);

if ($result !== '') {
    echo $result . "\n";
}
echo "Email: test@victorianpass.com\n";
echo "Password: Test123!\n";
echo "Points: 3000\n";

$q = $con->query("SELECT id FROM users WHERE email = 'test@victorianpass.com' LIMIT 1");
if ($q && $row = $q->fetch_assoc()) {
    echo "ID: " . $row['id'] . "\n";
}

$con->close();
?>