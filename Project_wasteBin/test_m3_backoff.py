#!/usr/bin/env python3
"""Regression tests for the M3 persistent retry backoff.

These tests exercise the REAL code in offline_queue.py and Main.py
against a mocked API. The device hardware modules (Servo / camera /
load cell / inductive / QR keyboard) are stubbed so the module can be
imported on a non-Raspberry-Pi host.

What is covered:
  * additive SQLite migration (legacy DB without next_retry_at)
  * a retryable failure persists a now + delay deadline
  * a flush before the deadline does NOT resubmit
  * a flush at/after the deadline resubmits
  * the delay grows 5,10,20,40,80,160 then caps at 300 forever
  * the deadline survives a simulated Pi restart (it is in SQLite)
  * the same transaction_id is reused on every retry
  * becoming SYNCED clears the deadline
  * the retry-limit guard still FAILs a row even while it is in
    backoff (deadline never postpones the permanent-failure check)
  * a delayed PENDING row still blocks session finalization

Time is virtualised by patching offline_queue.NOW, so no test sleeps
for real. All queue state is written to a scratch SQLite database under
the system temp dir; the repository's vheco_local.db is never opened.

Run from anywhere:
    python3 Project_wasteBin/test_m3_backoff.py
"""

import os
import pathlib
import sqlite3
import sys
import tempfile
import time
import types
import uuid

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

os.environ.setdefault("VHECO_API_KEY", "test-key")

# ---- stub hardware modules so `import Main` works off-device ----
_HW_STUBS = {
    "qr_scanner": {
        "start_scanner": lambda: None,
        "read_qr": lambda s: (None, s),
        "is_victorianpass": lambda q: True,
        "extract_resident_code": lambda q: "VH-1003",
        "close_scanner": lambda s: None,
    },
    "Weight": {
        "start_weight": lambda: None,
        "get_weight": lambda t: 0.0,
        "measure_incentive": lambda *a: 0,
        "close_weight": lambda: None,
    },
    "inductive": {
        "start_inductive": lambda: None,
        "metal_detected": lambda: False,
        "wait_for_metal_stable": lambda: None,
        "close_inductive": lambda: None,
    },
    "Servo": {
        "start": lambda: None,
        "sort_material": lambda m: None,
        "close": lambda: None,
    },
}
for _name, _attrs in _HW_STUBS.items():
    _mod = types.ModuleType(_name)
    for _k, _v in _attrs.items():
        setattr(_mod, _k, _v)
    sys.modules[_name] = _mod

import offline_queue as oq  # noqa: E402
import Main as M           # noqa: E402

# ---- redirect the queue to a fresh scratch DB ---------------------
_TMP = tempfile.mkdtemp(prefix="m3_backoff_")
_OLD_DB = oq.DB_FILE
oq.DB_FILE = os.path.join(_TMP, "queue.db")
oq.init_db()

# ---- virtual clock -------------------------------------------------
# offline_queue._now() reads the module global NOW() at call time, so
# patching oq.NOW advances every deadline computation deterministically.
FAKE_NOW = [1000.0]
oq.NOW = lambda: FAKE_NOW[0]

# ---- API call capture ----------------------------------------------
CALLS = []


def set_api(resp_spec):
    """Answer every API call with one canned response."""
    CALLS.clear()

    def _f(url, data, timeout=None):
        CALLS.append((url, data))
        return resp_spec

    M.api_post_ex = _f


def queue_api(specs):
    """Answer successive API calls from a list (last one repeats)."""
    CALLS.clear()

    def _f(url, data, timeout=None):
        spec = specs[min(len(CALLS), len(specs) - 1)]
        CALLS.append((url, data))
        return spec

    M.api_post_ex = _f


def _conn():
    c = sqlite3.connect(oq.DB_FILE)
    c.row_factory = sqlite3.Row
    return c


def reset_queue():
    c = _conn()
    c.execute("DELETE FROM pending_transactions")
    c.execute("DELETE FROM finalized_sessions")
    c.commit()
    c.close()


def insert_pending(txid, session="tok-S", retry=0, next_retry_at=None,
                   status="PENDING", material="Plastic", weight_kg=0.2):
    c = _conn()
    c.execute(
        "INSERT INTO pending_transactions (transaction_id, session_token,"
        " qr_code, material, weight_kg, status, retry_count, created_at,"
        " next_retry_at)"
        " VALUES (?,?,?,?,?,?,?,?,?)",
        (txid, session, "VH-1003", material, weight_kg, status, retry,
         FAKE_NOW[0], next_retry_at),
    )
    c.commit()
    c.close()


def insert_synced(txid, session="tok-S"):
    c = _conn()
    c.execute(
        "INSERT INTO pending_transactions (transaction_id, session_token,"
        " qr_code, material, weight_kg, status, created_at, synced_at)"
        " VALUES (?,?,?,?,?, 'SYNCED', ?, ?)",
        (txid, session, "VH-1003", "Plastic", 0.2, FAKE_NOW[0], FAKE_NOW[0]),
    )
    c.commit()
    c.close()


def row_by_tx(txid):
    c = _conn()
    r = c.execute(
        "SELECT status, retry_count, last_error, next_retry_at,"
        " transaction_id FROM pending_transactions WHERE transaction_id = ?",
        (txid,),
    ).fetchone()
    c.close()
    return dict(r) if r else None


def finalized_tokens():
    c = _conn()
    rows = c.execute(
        "SELECT session_token FROM finalized_sessions"
    ).fetchall()
    c.close()
    return {r["session_token"] for r in rows}


PASS = 0
FAIL = 0


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print(f"  ok    {name}")
    else:
        FAIL += 1
        print(f"  FAIL  {name}  {detail}")


RETRYABLE = {"http_status": 500, "json": None, "transport_error": None}
OK = {"http_status": 200, "json": {"success": True}, "transport_error": None}

# =====================================================================
# 0. delay helper / constant table
# =====================================================================
print("== 0. retry_delay_for_attempt ==")
_expected = {1: 5, 2: 10, 3: 20, 4: 40, 5: 80, 6: 160, 7: 300, 8: 300}
check(
    "0a delay table is 5,10,20,40,80,160 then capped at 300",
    {k: oq.retry_delay_for_attempt(k) for k in _expected} == _expected,
    {k: oq.retry_delay_for_attempt(k) for k in _expected},
)
check(
    "0b MAX_RETRY_DELAY is the 300 s cap",
    oq.MAX_RETRY_DELAY == 300,
    oq.MAX_RETRY_DELAY,
)
check(
    "0c large attempt counts stay capped",
    oq.retry_delay_for_attempt(20) == 300 and oq.retry_delay_for_attempt(999) == 300,
)
check(
    "0d non-positive attempt count is clamped to the first delay",
    oq.retry_delay_for_attempt(0) == 5 and oq.retry_delay_for_attempt(-3) == 5,
)

# =====================================================================
# 1. additive migration from a legacy DB without next_retry_at
# =====================================================================
print("== 1. legacy DB migration ==")
_legacy = os.path.join(_TMP, "legacy.db")
_lc = sqlite3.connect(_legacy)
_lc.execute("""
    CREATE TABLE pending_transactions (
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
_lc.execute(
    "INSERT INTO pending_transactions (transaction_id, session_token,"
    " material, weight_kg, status, created_at)"
    " VALUES ('LEGACY-1','tok-L','Plastic',0.2,'PENDING',?)",
    (FAKE_NOW[0],),
)
_lc.commit()
_lc.close()

_saved = oq.DB_FILE
oq.DB_FILE = _legacy
oq.init_db()
_cols = {
    r[1] for r in sqlite3.connect(_legacy).execute(
        "PRAGMA table_info(pending_transactions)"
    )
}
check("1a migration adds the next_retry_at column", "next_retry_at" in _cols)
_lc = sqlite3.connect(_legacy)
_lc.row_factory = sqlite3.Row
_legacy_row = dict(_lc.execute(
    "SELECT status, retry_count, next_retry_at FROM pending_transactions"
    " WHERE transaction_id='LEGACY-1'"
).fetchone())
_lc.close()
check(
    "1b legacy PENDING row is preserved, due immediately",
    _legacy_row["status"] == "PENDING"
    and _legacy_row["next_retry_at"] is None
    and oq.is_retry_due(_legacy_row) is True,
    _legacy_row,
)
oq.DB_FILE = _saved

# =====================================================================
# 2. a retryable failure schedules the next attempt
# =====================================================================
print("== 2. failure schedules backoff ==")
reset_queue()
FAKE_NOW[0] = 1000.0
insert_pending("TX-A", retry=0, next_retry_at=None)
set_api(RETRYABLE)
n = M.flush_offline_queue()
r = row_by_tx("TX-A")
check("2a retryable failure resubmitted once", len(CALLS) == 1, len(CALLS))
check(
    "2b row stays PENDING with retry_count 1",
    r["status"] == "PENDING" and r["retry_count"] == 1,
    r,
)
check(
    "2c deadline persisted as now + first delay (1005)",
    r["next_retry_at"] == 1005.0,
    r["next_retry_at"],
)

# =====================================================================
# 3. a flush before the deadline does not resubmit
# =====================================================================
print("== 3. not due -> skipped ==")
FAKE_NOW[0] = 1004.0
set_api(RETRYABLE)
n = M.flush_offline_queue()
r = row_by_tx("TX-A")
check("3a no API call before the deadline", len(CALLS) == 0, len(CALLS))
check("3b retry_count unchanged while waiting", r["retry_count"] == 1, r)
check("3c flush reports 0 synced", n == 0, n)

# =====================================================================
# 4. at/after the deadline it resubmits and the delay grows
# =====================================================================
print("== 4. due -> retried, delay grows ==")
FAKE_NOW[0] = 1005.0
set_api(RETRYABLE)
M.flush_offline_queue()
r = row_by_tx("TX-A")
check("4a deadline reached -> one API call", len(CALLS) == 1, len(CALLS))
check(
    "4b retry_count now 2 and delay doubled to 10 (->1015)",
    r["retry_count"] == 2 and r["next_retry_at"] == 1015.0,
    r,
)

FAKE_NOW[0] = 1015.0
set_api(RETRYABLE)
M.flush_offline_queue()
r = row_by_tx("TX-A")
check(
    "4c next failure schedules +20 (->1035)",
    r["retry_count"] == 3 and r["next_retry_at"] == 1035.0,
    r,
)

# =====================================================================
# 5. the deadline survives a simulated Pi restart
# =====================================================================
print("== 5. deadline persists across restart ==")
restart_deadline = row_by_tx("TX-A")["next_retry_at"]
# Simulate a reboot: re-open the DB and re-run init_db (idempotent),
# then start the clock at the moment just before the deadline.
oq.init_db()
FAKE_NOW[0] = restart_deadline - 1.0
set_api(RETRYABLE)
M.flush_offline_queue()
r = row_by_tx("TX-A")
check(
    "5a after restart the persisted deadline is still honoured",
    len(CALLS) == 0 and r["retry_count"] == 3,
    (len(CALLS), r),
)
FAKE_NOW[0] = restart_deadline
set_api(RETRYABLE)
M.flush_offline_queue()
check(
    "5b at the persisted deadline it retries again",
    len(CALLS) == 1 and row_by_tx("TX-A")["retry_count"] == 4,
    (len(CALLS), row_by_tx("TX-A")),
)

# =====================================================================
# 6. the same transaction_id is reused on every retry
# =====================================================================
print("== 6. stable transaction_id ==")
reset_queue()
FAKE_NOW[0] = 2000.0
insert_pending("TX-STABLE", retry=0)
queue_api([RETRYABLE, RETRYABLE])
M.flush_offline_queue()
FAKE_NOW[0] = 2005.0
M.flush_offline_queue()
ids = [call[1].get("transaction_id") for call in CALLS]
check(
    "6a transaction_id identical on both attempts",
    ids == ["TX-STABLE", "TX-STABLE"],
    ids,
)

# =====================================================================
# 7. success clears the deadline
# =====================================================================
print("== 7. sync clears backoff ==")
reset_queue()
FAKE_NOW[0] = 3000.0
insert_pending("TX-OK", retry=0)
set_api(RETRYABLE)
M.flush_offline_queue()
check("7a scheduled a deadline first",
      row_by_tx("TX-OK")["next_retry_at"] == 3005.0,
      row_by_tx("TX-OK"))
FAKE_NOW[0] = 3005.0
set_api(OK)
M.flush_offline_queue()
r = row_by_tx("TX-OK")
check(
    "7b SYNCED and next_retry_at cleared",
    r["status"] == "SYNCED" and r["next_retry_at"] is None,
    r,
)

# =====================================================================
# 8. the retry-limit guard beats a future deadline
# =====================================================================
print("== 8. retry limit not postponed by backoff ==")
reset_queue()
FAKE_NOW[0] = 4000.0
# retry_count already at MAX and a deadline far in the future.
insert_pending("TX-MAX", retry=oq.MAX_RETRY_COUNT, next_retry_at=99999.0)
set_api(RETRYABLE)
M.flush_offline_queue()
r = row_by_tx("TX-MAX")
check(
    "8a row at retry limit moves to FAILED despite future deadline",
    r["status"] == "FAILED" and "gave up" in r["last_error"],
    r,
)
check("8b no API call was made for the gave-up row", len(CALLS) == 0, len(CALLS))
check("8c terminal FAILED clears the deadline", r["next_retry_at"] is None, r)

# =====================================================================
# 9. a delayed PENDING row still blocks session finalization
# =====================================================================
print("== 9. delayed PENDING blocks finalization ==")
reset_queue()
FAKE_NOW[0] = 5000.0
insert_synced("TX-DONE", session="tok-LEAD")
insert_pending("TX-WAIT", session="tok-LEAD", retry=1, next_retry_at=99999.0)
set_api({"http_status": 200, "json": {"success": True, "points_awarded": 3},
         "transport_error": None})
n = M.finalize_abandoned_sessions()
check(
    "9a session with a delayed PENDING row is NOT finalized",
    n == 0 and "tok-LEAD" not in finalized_tokens(),
    (n, finalized_tokens()),
)
check(
    "9b has_pending_transactions still counts the delayed row",
    oq.has_pending_transactions("tok-LEAD") is True,
)

# Once it is no longer PENDING, the same session finalizes normally.
reset_queue()
FAKE_NOW[0] = 5000.0
insert_synced("TX-DONE2", session="tok-LEAD2")
set_api({"http_status": 200, "json": {"success": True, "points_awarded": 3},
         "transport_error": None})
n = M.finalize_abandoned_sessions()
check(
    "9c fully-synced session still finalizes (control)",
    n == 1 and "tok-LEAD2" in finalized_tokens(),
    (n, finalized_tokens()),
)

# =====================================================================
print("=" * 60)
print(f"RESULT: {PASS} passed, {FAIL} failed")
print("=" * 60)

# Leave no scratch state behind; never touch the repo DB.
oq.DB_FILE = _OLD_DB
sys.exit(0 if FAIL == 0 else 1)
