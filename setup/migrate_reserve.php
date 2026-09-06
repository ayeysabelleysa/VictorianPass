<?php
/**
 * One-time Reserve schema setup.
 * Run from the project root with: php setup/migrate_reserve.php
 * This file is CLI-only and must not be included by reserve.php.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../connect.php';
if (!($con instanceof mysqli) || $con->connect_errno) {
    fwrite(STDERR, "Database connection failed.\n");
    exit(1);
}

function reserveSetupColumnExists(mysqli $con, string $table, string $column): bool {
    $stmt = $con->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($con->error);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

function reserveSetupTableExists(mysqli $con, string $table): bool {
    $stmt = $con->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($con->error);
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    return $exists;
}

function reserveSetupAddColumn(mysqli $con, string $table, string $column, string $definition): void {
    if (!reserveSetupTableExists($con, $table)) {
        echo "Skipped $table.$column (table $table does not exist; import DATABASE/victorianpass_schema.sql first)\n";
        return;
    }
    if (!reserveSetupColumnExists($con, $table, $column)) {
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $safeColumn = '`' . str_replace('`', '``', $column) . '`';
        if (!$con->query("ALTER TABLE $safeTable ADD COLUMN $safeColumn $definition")) {
            throw new RuntimeException($con->error);
        }
        echo "Added $table.$column\n";
    }
}

function reserveSetupSetNullable(mysqli $con, string $table, string $column, string $definition): void {
    if (!reserveSetupTableExists($con, $table)) {
        echo "Skipped $table.$column (table $table does not exist; import DATABASE/victorianpass_schema.sql first)\n";
        return;
    }
    $stmt = $con->prepare(
        'SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($con->error);
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $stmt->close();
    if ($row && strtoupper($row['IS_NULLABLE']) !== 'YES') {
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $safeColumn = '`' . str_replace('`', '``', $column) . '`';
        if (!$con->query("ALTER TABLE $safeTable MODIFY COLUMN $safeColumn $definition")) {
            throw new RuntimeException($con->error);
        }
        echo "Made $table.$column nullable\n";
    }
}

function reserveSetupEnsureIndex(mysqli $con, string $table, string $index, string $columnList): void {
    if (!reserveSetupTableExists($con, $table)) {
        echo "Skipped index $table.$index (table $table does not exist; import DATABASE/victorianpass_schema.sql first)\n";
        return;
    }
    $stmt = $con->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException($con->error);
    }
    $stmt->bind_param('ss', $table, $index);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result && $result->num_rows > 0;
    $stmt->close();
    if (!$exists) {
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $safeIndex = '`' . str_replace('`', '``', $index) . '`';
        if (!$con->query("ALTER TABLE $safeTable ADD INDEX $safeIndex ($columnList)")) {
            throw new RuntimeException($con->error);
        }
        echo "Added index $table.$index ($columnList)\n";
    }
}

reserveSetupAddColumn($con, 'reservations', 'entry_pass_id', 'INT NULL');
reserveSetupAddColumn($con, 'reservations', 'start_time', 'TIME NULL');
reserveSetupAddColumn($con, 'reservations', 'end_time', 'TIME NULL');
reserveSetupAddColumn($con, 'reservations', 'downpayment', 'DECIMAL(10,2) NULL');
reserveSetupAddColumn($con, 'reservations', 'payment_status', "ENUM('pending','submitted','verified') NULL");
reserveSetupAddColumn($con, 'reservations', 'account_type', "ENUM('visitor','resident') NULL");
reserveSetupAddColumn($con, 'reservations', 'receipt_path', 'VARCHAR(255) NULL');
reserveSetupAddColumn($con, 'reservations', 'booking_for', "ENUM('resident','guest') NULL");
reserveSetupAddColumn($con, 'reservations', 'use_points', 'TINYINT(1) DEFAULT 0');
reserveSetupAddColumn($con, 'reservations', 'points_used', 'INT DEFAULT 0');
reserveSetupAddColumn($con, 'users', 'points', 'INT DEFAULT 0');

// The reservation flow stores placeholder rows before amenity/date/price are
// chosen, so these columns must be nullable.
reserveSetupSetNullable($con, 'reservations', 'amenity', 'VARCHAR(100) NULL');
reserveSetupSetNullable($con, 'reservations', 'start_date', 'DATE NULL');
reserveSetupSetNullable($con, 'reservations', 'end_date', 'DATE NULL');
reserveSetupSetNullable($con, 'reservations', 'persons', 'INT NULL');
reserveSetupSetNullable($con, 'reservations', 'price', 'DECIMAL(10,2) NULL');

// guest_forms: availability queries select amenity/start_date/end_date and the
// single-day overlap check uses start_time/end_time, so all four must exist.
reserveSetupAddColumn($con, 'guest_forms', 'amenity', 'VARCHAR(100) NULL');
reserveSetupAddColumn($con, 'guest_forms', 'start_date', 'DATE NULL');
reserveSetupAddColumn($con, 'guest_forms', 'end_date', 'DATE NULL');
reserveSetupAddColumn($con, 'guest_forms', 'start_time', 'TIME NULL');
reserveSetupAddColumn($con, 'guest_forms', 'end_time', 'TIME NULL');

// resident_reservations: the multi-day hour-marking and single-day checks use
// start_time/end_time once present; availability selects start_date/end_date.
reserveSetupAddColumn($con, 'resident_reservations', 'start_time', 'TIME NULL');
reserveSetupAddColumn($con, 'resident_reservations', 'end_time', 'TIME NULL');

// point_transactions: reserveCalcVHEcoBalance filters provenance via
// ecopoint_session_id, so the column must exist for the primary query.
reserveSetupAddColumn($con, 'point_transactions', 'ecopoint_session_id', 'INT NULL');

if (!$con->query("CREATE TABLE IF NOT EXISTS point_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    transaction_type ENUM('earn','redeem','adjustment') NOT NULL DEFAULT 'earn',
    amount INT NOT NULL DEFAULT 0,
    description VARCHAR(255) NULL,
    reservation_ref_code VARCHAR(20) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_point_transactions_user_id (user_id),
    INDEX idx_point_transactions_type (transaction_type),
    INDEX idx_point_transactions_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4")) {
    throw new RuntimeException($con->error);
}

// Recommended indexes for the availability queries (range overlap on
// amenity + approval status + dates). Each is checked before creation and is
// additive — none duplicate existing single-column indexes.
reserveSetupEnsureIndex($con, 'reservations', 'idx_reserve_amenity_status_dates', 'amenity, approval_status, start_date, end_date');
reserveSetupEnsureIndex($con, 'resident_reservations', 'idx_rr_reserve_amenity_status_dates', 'amenity, approval_status, start_date, end_date');
reserveSetupEnsureIndex($con, 'guest_forms', 'idx_gf_reserve_amenity_status_dates', 'amenity, approval_status, start_date, end_date');

echo "Reserve schema setup complete.\n";
