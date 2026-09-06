import sqlite3
import time
import shutil

DB_FILE = "vheco_local.db"

# Keep synced records for 7 days for troubleshooting/auditing.
SYNCED_RETENTION_DAYS = 7

# Stop writing if the filesystem has less than 500 MB free.
MIN_FREE_SPACE_MB = 500


def init_db():
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA journal_mode=WAL")

    conn.execute("""
        CREATE TABLE IF NOT EXISTS pending_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            transaction_id TEXT UNIQUE NOT NULL,
            session_token TEXT,
            qr_code TEXT,
            material TEXT,
            weight_kg REAL,
            raw_hardware_data TEXT,
            status TEXT NOT NULL DEFAULT 'PENDING',
            retry_count INTEGER NOT NULL DEFAULT 0,
            last_error TEXT,
            created_at REAL NOT NULL,
            synced_at REAL
        )
    """)

    conn.commit()
    conn.close()


def has_enough_storage():
    total, used, free = shutil.disk_usage(".")
    free_mb = free / (1024 * 1024)

    return free_mb >= MIN_FREE_SPACE_MB


def save_transaction(
    transaction_id,
    session_token,
    qr_code,
    material,
    weight_kg,
    raw_hardware_data=None
):
    if not has_enough_storage():
        raise RuntimeError(
            "Raspberry Pi storage is critically low. "
            "Transaction was NOT saved."
        )

    conn = sqlite3.connect(DB_FILE)

    conn.execute("""
        INSERT OR IGNORE INTO pending_transactions (
            transaction_id,
            session_token,
            qr_code,
            material,
            weight_kg,
            raw_hardware_data,
            status,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)
    """, (
        transaction_id,
        session_token,
        qr_code,
        material,
        weight_kg,
        raw_hardware_data,
        time.time()
    ))

    conn.commit()
    conn.close()


def get_pending_transactions():
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row

    rows = conn.execute("""
        SELECT *
        FROM pending_transactions
        WHERE status = 'PENDING'
        ORDER BY id ASC
    """).fetchall()

    conn.close()

    return rows


def mark_synced(transaction_id):
    conn = sqlite3.connect(DB_FILE)

    conn.execute("""
        UPDATE pending_transactions
        SET status = 'SYNCED',
            synced_at = ?
        WHERE transaction_id = ?
    """, (time.time(), transaction_id))

    conn.commit()
    conn.close()


def mark_failed(transaction_id, error):
    conn = sqlite3.connect(DB_FILE)

    conn.execute("""
        UPDATE pending_transactions
        SET retry_count = retry_count + 1,
            last_error = ?
        WHERE transaction_id = ?
    """, (str(error), transaction_id))

    conn.commit()
    conn.close()


def cleanup_synced_transactions():
    cutoff = time.time() - (SYNCED_RETENTION_DAYS * 24 * 60 * 60)

    conn = sqlite3.connect(DB_FILE)

    conn.execute("""
        DELETE FROM pending_transactions
        WHERE status = 'SYNCED'
        AND synced_at IS NOT NULL
        AND synced_at < ?
    """, (cutoff,))

    conn.commit()
    conn.close()


def storage_status():
    total, used, free = shutil.disk_usage(".")

    return {
        "total_gb": round(total / (1024 ** 3), 2),
        "used_gb": round(used / (1024 ** 3), 2),
        "free_gb": round(free / (1024 ** 3), 2),
        "free_mb": round(free / (1024 ** 2), 1)
    }


if __name__ == "__main__":
    init_db()
    cleanup_synced_transactions()

    print("VHEcoPoint offline queue ready.")
    print("Storage:", storage_status())