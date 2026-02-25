<?php
// Include the database connection file
require_once 'connect.php';

// Display current PHP time
echo "<h2>Timezone Verification</h2>";
echo "<strong>PHP Timezone:</strong> " . date_default_timezone_get() . "<br>";
echo "<strong>PHP Time (Manila):</strong> " . date('Y-m-d H:i:s') . "<br><br>";

// Verify MySQL Timezone
if ($con) {
    // Check current session timezone
    $res = $con->query("SELECT @@session.time_zone as tz");
    if ($res) {
        $row = $res->fetch_assoc();
        echo "<strong>MySQL Session Timezone:</strong> " . $row['tz'] . " (Should be +08:00)<br>";
    } else {
        echo "<strong>MySQL Session Timezone:</strong> Error retrieving<br>";
    }

    // Check current database time
    $res = $con->query("SELECT NOW() as db_time");
    if ($res) {
        $row = $res->fetch_assoc();
        echo "<strong>MySQL DB Time (NOW):</strong> " . $row['db_time'] . "<br>";
        
        // Check if DB time matches PHP time (approx)
        $phpTime = strtotime(date('Y-m-d H:i:s'));
        $dbTime = strtotime($row['db_time']);
        $diff = abs($phpTime - $dbTime);
        
        if ($diff < 60) {
            echo "<span style='color:green; font-weight:bold;'>SUCCESS: PHP and DB times are synchronized!</span>";
        } else {
            echo "<span style='color:red; font-weight:bold;'>ERROR: PHP and DB times are NOT synchronized! Difference: $diff seconds.</span>";
            if ($dbTime < $phpTime - 3600) {
                 echo "<br>It seems DB is lagging behind (possibly UTC).";
            }
        }
    } else {
        echo "Error querying DB time: " . $con->error;
    }
} else {
    echo "Database connection failed.";
}
?>
