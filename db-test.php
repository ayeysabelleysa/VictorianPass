<?php
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $db = new mysqli(
        "localhost",
        "u785375633_VHEcoPoint_",
        "YOUR_DATABASE_PASSWORD",
        "u785375633_FLS_VHEcoPoint"
    );

    $db->set_charset("utf8mb4");
    echo "Database connection successful.";
} catch (Throwable $error) {
    echo "Database connection failed.";
}