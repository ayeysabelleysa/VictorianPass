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

function reserveSetupAddColumn(mysqli $con, string $table, string $column, string $definition): void {
    if (!reserveSetupColumnExists($con, $table, $column)) {
        $safeTable = '`' . str_replace('`', '``', $table) . '`';
        $safeColumn = '`' . str_replace('`', '``', $column) . '`';
        if (!$con->query("ALTER TABLE $safeTable ADD COLUMN $safeColumn $definition")) {
            throw new RuntimeException($con->error);
        }
        echo "Added $table.$column\n";
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

echo "Reserve schema setup complete.\n";
