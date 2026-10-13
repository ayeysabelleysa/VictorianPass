import os
import shutil
import sqlite3
import time

# =========================================================
# PINNED DATABASE PATH
#
# The queue DB must live next to this script, never in the
# process CWD: the station service may launch Main.py from
# any directory and a relative path would silently scatter
# queue files (or lose them) across reboots.
# =========================================================
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
DB_FILE = os.path.join(SCRIPT_DIR, "vheco_local.db")

# Keep synced records for 7 days for troubleshooting/auditing.
SYNCED_RETENTION_DAYS = 7

# Stop writing if the filesystem has less than 500 MB free.
MIN_FREE_SPACE_MB = 500

# Give up on a PENDING row after this many failed flush
# attempts; it is then moved to FAILED with the last error
# kept for the audit trail.
MAX_RETRY_COUNT = 8

# M3: persistent retry backoff.
#
# After a retryable failure the row's retry_count is incremented and a
# next-retry deadline is persisted in SQLite (Unix epoch, NULL = due
# now). Delays grow geometrically, then stay capped at 300 s, so
# repeated flushes during an internet outage stop hammering the API.
RETRY_DELAYS = (5, 10, 20, 40, 80, 160, 300)
MAX_RETRY_DELAY = RETRY_DELAYS[-1]  # 300 s hard cap

# Injectable clock. Everything deadline-related reads NOW() so tests
# can advance time deterministically without real sleeps.
NOW = time.time


def _now():
    return float(NOW())


def retry_delay_for_attempt(retry_count):
    """Seconds to wait after a failure that brings a row's
    retry_count up to `retry_count` (1-based attempt number).

    Delays: 5, 10, 20, 40, 80, 160 seconds, then 300 caps forever.
    """
    index = max(0, int(retry_count) - 1)
    if index >= len(RETRY_DELAYS):
        return MAX_RETRY_DELAY
    return RETRY_DELAYS[index]


def is_retry_due(row, now=None):
    """True when a PENDING row may be attempted now.

    A NULL next_retry_at (freshly saved or legacy row) is always due.
    Delayed rows are still returned by get_pending_transactions() and
    still count as PENDING for all safety checks -- this function only
    decides whether a flush may actually send them.
    """
    if now is None:
        now = _now()

    try:
        deadline = row["next_retry_at"]
    except (KeyError, IndexError):
        # Column missing/unreadable: behave conservatively and let
        # the normal retry policy run (fall back to old behaviour).
        return True

    if deadline is None:
        return True

    return float(deadline) <= float(now)

# Status values used by the queue:
#   PENDING -> not yet accepted by the server (retryable)
#   SYNCED  -> server confirmed; kept for SYNCED_RETENTION_DAYS
#   FAILED  -> terminal (expired session, bad payload, gave up);
#              never retried automatically
VALID_STATUSES = ("PENDING", "SYNCED", "FAILED")

# M5: durable pre-actuation (write-ahead) lifecycle.
#
# Physical sorting happens on the servo BEFORE the item is submitted.
# If power fails after the servo moves but before the local queue row
# is committed, the item would be physically sorted yet unrecoverable.
# To close that window the station writes the transaction row *before*
# commanding the servo and advances it through these states:
#
#   PREPARED   -> record committed; no servo command issued yet.
#                 A crash here PROVES nothing moved -> safe to abandon.
#   ACTUATING  -> the servo command is being issued; a crash here
#                 means the physical outcome is UNKNOWN.
#   ACTUATED   -> the servo command returned successfully (command
#                 completion only: the current hardware provides no
#                 independent placement sensor, so this is NOT proof
#                 the item landed in a bin).
#   ABANDONED  -> recovered PREPARED row; no points, never submitted.
#   UNCERTAIN  -> recovered ACTUATING row; outcome unknown, never
#                 auto-submitted/awarded, blocks session finalization.
#
# Legacy rows created before M5 have actuation_state = NULL: they were
# only ever written AFTER the servo succeeded, so NULL means ACTUATED.
ACTUATION_PREPARED  = "PREPARED"
ACTUATION_STARTED   = "ACTUATING"
ACTUATION_COMPLETED = "ACTUATED"
ACTUATION_ABANDONED = "ABANDONED"
ACTUATION_UNCERTAIN = "UNCERTAIN"

# Actuation states whose items may be submitted to the server. NULL is
# a legacy (already-actuated) row. Every other state is either not yet
# confirmed or deliberately unresolved, and must never be submitted.
SUBMITTABLE_ACTUATION_STATES = (None, ACTUATION_COMPLETED)


def is_submittable(row):
    """True when a queue row's physical actuation is sufficiently
    confirmed to be sent to the server.

    NULL (legacy row) and ACTUATED are submittable. PREPARED,
    ACTUATING, ABANDONED and UNCERTAIN never are: the first two are
    incomplete, the last two are deliberate unresolved/abandoned
    outcomes. A missing column also falls back to True so an
    un-migrated database keeps the old behaviour.
    """
    try:
        state = row["actuation_state"]
    except (KeyError, IndexError):
        return True

    return state in SUBMITTABLE_ACTUATION_STATES


def _open_write_conn():
    """Open a connection for a durability-critical write.

    synchronous=FULL forces SQLite to fsync on COMMIT, so a committed
    pre-actuation record really is on disk before the servo can move
    (WAL's default NORMAL does not guarantee that across power loss).
    """
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")
    conn.execute("PRAGMA synchronous=FULL")
    return conn


def init_db():
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=5000")

    # Core queue schema. New installs get next_retry_at directly;
    # pre-M3 databases are upgraded by the additive migration below.
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
            synced_at REAL,
            next_retry_at REAL,
            actuation_state TEXT,
            actuation_started_at REAL,
            actuation_completed_at REAL
        )
    """)

    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_pending_status
        ON pending_transactions(status)
    """)

    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_pending_session
        ON pending_transactions(session_token)
    """)

    # M3: additive migration -- add next_retry_at (Unix-epoch retry
    # deadline, NULL = due now) to databases created before backoff
    # existed. Existing rows keep their PENDING status and become due
    # immediately (NULL), preserving old behaviour for queued items.
    _columns = {
        row[1]
        for row in conn.execute("PRAGMA table_info(pending_transactions)")
    }
    if "next_retry_at" not in _columns:
        conn.execute(
            "ALTER TABLE pending_transactions ADD COLUMN next_retry_at REAL"
        )

    # M5: additive migration for the write-ahead actuation lifecycle.
    # Pre-M5 rows get actuation_state = NULL, which is_submittable()
    # treats as an already-actuated (legacy) item: they were only ever
    # written after the servo succeeded, so they are never misclassified
    # as unsorted and keep synchronizing exactly as before.
    for _col, _type in (
        ("actuation_state", "TEXT"),
        ("actuation_started_at", "REAL"),
        ("actuation_completed_at", "REAL"),
    ):
        if _col not in _columns:
            conn.execute(
                f"ALTER TABLE pending_transactions ADD COLUMN {_col} {_type}"
            )

    # Ledger of sessions the station has already asked the server to
    # finalize. It prevents the abandoned-session finalizer from
    # re-calling complete against a session that no longer needs it
    # (which the server answers 404), so a token is only attempted
    # once. Additive table: existing databases keep working as-is.
    conn.execute("""
        CREATE TABLE IF NOT EXISTS finalized_sessions (
            session_token TEXT PRIMARY KEY,
            finalized_at REAL NOT NULL
        )
    """)

    conn.commit()
    conn.close()


def has_enough_storage():
    total, used, free = shutil.disk_usage(
        os.path.dirname(DB_FILE) or "."
    )
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
    """Persist one physically-processed item for later sync.

    weight_kg MUST be kilograms (the API unit). Main.py's live
    path measures in grams and converts before calling this.

    Raises RuntimeError when free disk is critically low so the
    caller can decide how to proceed without crashing.
    """
    if not has_enough_storage():
        raise RuntimeError(
            "Raspberry Pi storage is critically low. "
            "Transaction was NOT saved."
        )

    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

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


# =====================================================================
# M5: DURABLE PRE-ACTUATION (WRITE-AHEAD) RECORD
# =====================================================================

def begin_actuation(
    transaction_id,
    session_token,
    qr_code,
    material,
    weight_kg,
    raw_hardware_data=None
):
    """Commit the pre-actuation record BEFORE the servo is commanded.

    This is the M5 write-ahead log: the transaction row (transaction_id,
    session token, QR code, material, weight, hardware data) is committed
    with actuation_state='PREPARED' and status='PENDING'. Only after this
    function returns may the caller issue the physical servo command.

    weight_kg MUST be kilograms (the API unit).

    The connection uses synchronous=FULL so the commit is fsynced: a
    power loss immediately after this returns still leaves the record on
    disk. Raises (and the caller must NOT actuate) on low disk or any
    SQLite error. A UNIQUE transaction_id collision also raises rather
    than silently overwriting an existing record.
    """
    if not has_enough_storage():
        raise RuntimeError(
            "Raspberry Pi storage is critically low. "
            "The pre-actuation record was NOT committed."
        )

    conn = _open_write_conn()
    try:
        conn.execute("""
            INSERT INTO pending_transactions (
                transaction_id,
                session_token,
                qr_code,
                material,
                weight_kg,
                raw_hardware_data,
                status,
                created_at,
                actuation_state
            )
            VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?, ?)
        """, (
            transaction_id,
            session_token,
            qr_code,
            material,
            weight_kg,
            raw_hardware_data,
            _now(),
            ACTUATION_PREPARED
        ))
        conn.commit()
    finally:
        conn.close()


def _set_actuation_state(transaction_id, state, started=False, completed=False):
    """Advance a PENDING row's actuation state (durably).

    Only touches rows still PENDING so a race with a concurrent sync
    (which would set SYNCED) can never resurrect a finished row.
    """
    conn = _open_write_conn()
    try:
        if started and completed:
            conn.execute("""
                UPDATE pending_transactions
                SET actuation_state = ?,
                    actuation_started_at = ?,
                    actuation_completed_at = ?
                WHERE transaction_id = ?
                  AND status = 'PENDING'
            """, (state, _now(), _now(), transaction_id))
        elif started:
            conn.execute("""
                UPDATE pending_transactions
                SET actuation_state = ?,
                    actuation_started_at = ?
                WHERE transaction_id = ?
                  AND status = 'PENDING'
            """, (state, _now(), transaction_id))
        elif completed:
            conn.execute("""
                UPDATE pending_transactions
                SET actuation_state = ?,
                    actuation_completed_at = ?
                WHERE transaction_id = ?
                  AND status = 'PENDING'
            """, (state, _now(), transaction_id))
        else:
            conn.execute("""
                UPDATE pending_transactions
                SET actuation_state = ?
                WHERE transaction_id = ?
                  AND status = 'PENDING'
            """, (state, transaction_id))
        conn.commit()
    finally:
        conn.close()


def mark_actuation_started(transaction_id):
    """Persist 'actuation started' BEFORE the servo command is issued.

    A crash after this commit is treated as an UNKNOWN physical outcome.
    """
    _set_actuation_state(
        transaction_id, ACTUATION_STARTED, started=True
    )


def mark_actuation_completed(transaction_id):
    """Persist that the servo command returned successfully.

    This records command completion only; the current hardware has no
    independent placement sensor, so it is NOT proof the item landed in
    the correct bin.
    """
    _set_actuation_state(
        transaction_id, ACTUATION_COMPLETED, completed=True
    )


def mark_actuation_abandoned(transaction_id, reason):
    """Resolve a PREPARED row that never issued a servo command.

    Terminal: no points, never submitted. Moved to FAILED so it stops
    counting as pending work (nothing physical happened).
    """
    conn = _open_write_conn()
    try:
        conn.execute("""
            UPDATE pending_transactions
            SET actuation_state = ?,
                status = 'FAILED',
                last_error = ?,
                next_retry_at = NULL
            WHERE transaction_id = ?
              AND status = 'PENDING'
        """, (ACTUATION_ABANDONED, str(reason), transaction_id))
        conn.commit()
    finally:
        conn.close()


def mark_actuation_uncertain(transaction_id, reason):
    """Mark a transaction whose physical outcome cannot be determined.

    The row stays PENDING (so it keeps blocking session finalization and
    is never discarded) but is never submitted: is_submittable() rejects
    UNCERTAIN, and flush_offline_queue() skips it. An operator must
    resolve it out-of-band.
    """
    conn = _open_write_conn()
    try:
        conn.execute("""
            UPDATE pending_transactions
            SET actuation_state = ?,
                last_error = ?
            WHERE transaction_id = ?
              AND status = 'PENDING'
        """, (ACTUATION_UNCERTAIN, str(reason), transaction_id))
        conn.commit()
    finally:
        conn.close()


def recover_actuation_states():
    """Resolve crash-interrupted actuation rows at startup.

    PREPARED  -> ABANDONED (durable state proves no servo command was
                 issued, so nothing physical happened).
    ACTUATING -> UNCERTAIN (the command may have been issued; never
                 auto-actuate again or auto-award).

    Only PENDING rows are considered, so a row that already synced is
    untouched. Returns a list of (transaction_id, from_state, to_state)
    so the caller can log each resolution prominently.
    """
    conn = _open_write_conn()
    recovered = []
    try:
        rows = conn.execute("""
            SELECT transaction_id, actuation_state
            FROM pending_transactions
            WHERE status = 'PENDING'
              AND actuation_state IN (?, ?)
            ORDER BY id ASC
        """, (ACTUATION_PREPARED, ACTUATION_STARTED)).fetchall()

        for transaction_id, state in rows:
            if state == ACTUATION_PREPARED:
                conn.execute("""
                    UPDATE pending_transactions
                    SET actuation_state = ?,
                        status = 'FAILED',
                        last_error = ?,
                        next_retry_at = NULL
                    WHERE transaction_id = ?
                      AND status = 'PENDING'
                """, (
                    ACTUATION_ABANDONED,
                    "abandoned_before_actuation: power loss before any "
                    "servo command was issued",
                    transaction_id
                ))
                recovered.append(
                    (transaction_id, state, ACTUATION_ABANDONED)
                )
            else:
                conn.execute("""
                    UPDATE pending_transactions
                    SET actuation_state = ?,
                        last_error = ?
                    WHERE transaction_id = ?
                      AND status = 'PENDING'
                """, (
                    ACTUATION_UNCERTAIN,
                    "actuation_outcome_unknown: power loss after the "
                    "servo command may have been issued",
                    transaction_id
                ))
                recovered.append(
                    (transaction_id, state, ACTUATION_UNCERTAIN)
                )

        conn.commit()
    finally:
        conn.close()

    return recovered


def get_uncertain_transactions():
    """Rows whose physical actuation outcome is unknown.

    These require operator review: they are deliberately never submitted
    and never awarded, and they block session finalization. Returned
    oldest-first so an operator can work the backlog in order.
    """
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=5000")

    rows = conn.execute("""
        SELECT *
        FROM pending_transactions
        WHERE actuation_state = ?
        ORDER BY id ASC
    """, (ACTUATION_UNCERTAIN,)).fetchall()

    conn.close()

    return rows


def transaction_exists(transaction_id):
    """True when a row with this transaction_id is already stored.

    Used after a failed server submit to confirm the M5 pre-actuation
    record is durable, instead of attempting a redundant insert (and
    risk reporting the item as lost on a transient write error).
    """
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")
    try:
        row = conn.execute(
            "SELECT 1 FROM pending_transactions "
            "WHERE transaction_id = ? LIMIT 1",
            (transaction_id,)
        ).fetchone()
    finally:
        conn.close()

    return row is not None


def get_pending_transactions(session_token=None):
    """Return PENDING rows oldest-first, optionally scoped to one
    session token.

    Includes rows whose retry backoff deadline (next_retry_at) has not
    arrived yet: safety checks must still treat delayed rows as
    PENDING. Only flushing consults is_retry_due()."""
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=5000")

    if session_token:
        rows = conn.execute("""
            SELECT *
            FROM pending_transactions
            WHERE status = 'PENDING'
              AND session_token = ?
            ORDER BY id ASC
        """, (session_token,)).fetchall()
    else:
        rows = conn.execute("""
            SELECT *
            FROM pending_transactions
            WHERE status = 'PENDING'
            ORDER BY id ASC
        """).fetchall()

    conn.close()

    return rows


def has_pending_transactions(session_token=None):
    """True when at least one PENDING row exists (optionally for a
    single session). Used to guard unsafe cancel/complete calls."""
    return len(get_pending_transactions(session_token)) > 0


def get_session_sync_summary():
    """Per-session status counts for every session that ever had a
    queued transaction.

    Returns a list of sqlite3.Row with:
        session_token, pending_count, synced_count

    A session is a candidate for the abandoned-session finalizer
    when pending_count == 0 and synced_count > 0 (at least one item
    was actually recorded, so points are waiting to be awarded).
    """
    conn = sqlite3.connect(DB_FILE)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA busy_timeout=5000")

    rows = conn.execute("""
        SELECT session_token,
               SUM(CASE WHEN status = 'PENDING' THEN 1 ELSE 0 END)
                   AS pending_count,
               SUM(CASE WHEN status = 'SYNCED' THEN 1 ELSE 0 END)
                   AS synced_count
        FROM pending_transactions
        WHERE session_token IS NOT NULL
          AND session_token <> ''
        GROUP BY session_token
    """).fetchall()

    conn.close()

    return rows


def is_session_finalized(session_token):
    """True when the station already asked the server to finalize
    this session (regardless of outcome)."""
    if not session_token:
        return False

    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    row = conn.execute("""
        SELECT 1
        FROM finalized_sessions
        WHERE session_token = ?
    """, (session_token,)).fetchone()

    conn.close()

    return row is not None


def mark_session_finalized(session_token):
    """Record that a session no longer needs a finalize attempt.

    Called only after the server confirms completion (or answers
    definitively that the session is not completable), so a token is
    never attempted forever."""
    if not session_token:
        return

    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    conn.execute("""
        INSERT OR IGNORE INTO finalized_sessions
        (session_token, finalized_at)
        VALUES (?, ?)
    """, (session_token, time.time()))

    conn.commit()
    conn.close()


def mark_synced(transaction_id):
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    conn.execute("""
        UPDATE pending_transactions
        SET status = 'SYNCED',
            synced_at = ?,
            next_retry_at = NULL
        WHERE transaction_id = ?
    """, (time.time(), transaction_id))

    conn.commit()
    conn.close()


def mark_failed(transaction_id, error):
    """Record one retryable failure and schedule the next retry.

    The row stays PENDING (until MAX_RETRY_COUNT). next_retry_at is set
    to now + delay(new retry_count) and persisted in SQLite so restarting
    the Pi does not reset the backoff. The deadline is cleared when the
    row later becomes SYNCED or FAILED.
    """
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    current = conn.execute("""
        SELECT retry_count
        FROM pending_transactions
        WHERE transaction_id = ?
          AND status = 'PENDING'
    """, (transaction_id,)).fetchone()

    if current is None:
        conn.close()
        return

    new_count = int(current[0]) + 1
    deadline = _now() + retry_delay_for_attempt(new_count)

    conn.execute("""
        UPDATE pending_transactions
        SET retry_count = ?,
            last_error = ?,
            next_retry_at = ?
        WHERE transaction_id = ?
          AND status = 'PENDING'
    """, (new_count, str(error), deadline, transaction_id))

    conn.commit()
    conn.close()


def mark_failed_permanent(transaction_id, error):
    """Move a row to FAILED: terminal, never retried automatically.
    The error text is kept for the audit trail and the retry deadline
    is cleared."""
    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    conn.execute("""
        UPDATE pending_transactions
        SET status = 'FAILED',
            last_error = ?,
            next_retry_at = NULL
        WHERE transaction_id = ?
          AND status = 'PENDING'
    """, (str(error), transaction_id))

    conn.commit()
    conn.close()


def cleanup_synced_transactions():
    cutoff = time.time() - (SYNCED_RETENTION_DAYS * 24 * 60 * 60)

    conn = sqlite3.connect(DB_FILE)
    conn.execute("PRAGMA busy_timeout=5000")

    conn.execute("""
        DELETE FROM pending_transactions
        WHERE status = 'SYNCED'
        AND synced_at IS NOT NULL
        AND synced_at < ?
    """, (cutoff,))

    conn.commit()
    conn.close()


def storage_status():
    total, used, free = shutil.disk_usage(
        os.path.dirname(DB_FILE) or "."
    )

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
    print("DB file:", DB_FILE)
    print("Storage:", storage_status())
