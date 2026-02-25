<?php
require_once 'connect.php';

$tables = ['reservations', 'incident_reports', 'guest_forms'];

foreach ($tables as $t) {
    echo "Table: $t\n";
    $res = $con->query("SHOW COLUMNS FROM $t");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (in_array($row['Field'], ['created_at', 'updated_at'])) {
                echo "  " . $row['Field'] . ": " . $row['Type'] . "\n";
            }
        }
    } else {
        echo "  Error: " . $con->error . "\n";
    }
}
?>
