#!/usr/bin/env python3
"""Regression tests for M5 durable pre-actuation (write-ahead) recovery.

This closes the power-loss window between physical servo sorting and the
local queue record. The transaction row is committed BEFORE the servo is
commanded and advanced through a small state machine, so a crash at any
point is either provably harmless (nothing moved) or flagged UNCERTAIN
(may have moved) -- never silently lost and never awarded while unsorted.

These tests exercise the REAL code in offline_queue.py and Main.py
against a mocked API. The device hardware modules (Servo / camera /
load cell / inductive / QR keyboard) are stubbed so the module can be
imported on a non-Raspberry-Pi host.

What is covered:
  * SQLite migration adds actuation_state/started/completed columns and
    leaves legacy rows NULL (= already actuated, still submittable)
  * begin_actuation commits a PREPARED row before the servo can move
  * the actuation state machine (started -> completed / uncertain /
    abandoned) and is_submittable()
  * startup recovery: PREPARED -> ABANDONED (FAILED, no points) and
    ACTUATING -> UNCERTAIN (still PENDING, never submitted)
  * flush_offline_queue NEVER submits PREPARED/ACTUATING/UNCERTAIN and
    never converts an unresolved row to FAILED via the retry counter
  * legacy NULL rows still synchronize exactly as before
  * an unresolved row blocks session finalization
  * process_item commits the record BEFORE Servo.sort_material and
    refuses to actuate when the record cannot be committed
  * a servo failure/exception leaves the row UNCERTAIN, not ACTUATED
  * the same transaction_id is reused for the failed-submission retry

Time is virtualised by patching offline_queue.NOW, so no test sleeps for
real. All queue state is written to a scratch SQLite database under the
system temp dir; the repository's vheco_local.db is never opened.

Run from anywhere:
    python3 Project_wasteBin/test_m5_power_loss.py
"""

import os
import pathlib
import sqlite3
import sys
import tempfile
import types

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
        "sort_material": lambda m: True,
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
_TMP = tempfile.mkdtemp(prefix="m5_power_loss_")
_OLD_DB = oq.DB_FILE
oq.DB_FILE = os.path.join(_TMP, "queue.db")
oq.init_db()

# ---- virtual clock -------------------------------------------------
FAKE_NOW = [1000.0]
oq.NOW = lambda: FAKE_NOW[0]

# ---- API call capture ----------------------------------------------
CALLS = []


def set_api(resp_spec):
    CALLS.clear()

    def _f(url, data, timeout=None):
        CALLS.append((url, data))
        return resp_spec

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


def insert_row(txid, session="tok-S", status="PENDING",
               actuation_state="__unset__", material="Plastic",
               weight_kg=0.2, retry=0, next_retry_at=None):
    """Insert a row. actuation_state='__unset__' omits the column so it
    lands as NULL, exactly like a genuine pre-M5 legacy row."""
    c = _conn()
    if actuation_state == "__unset__":
        c.execute(
            "INSERT INTO pending_transactions (transaction_id, session_token,"
            " qr_code, material, weight_kg, status, retry_count, created_at,"
            " next_retry_at)"
            " VALUES (?,?,?,?,?,?,?,?,?)",
            (txid, session, "VH-1003", material, weight_kg, status, retry,
             FAKE_NOW[0], next_retry_at),
        )
    else:
        c.execute(
            "INSERT INTO pending_transactions (transaction_id, session_token,"
            " qr_code, material, weight_kg, status, retry_count, created_at,"
            " next_retry_at, actuation_state)"
            " VALUES (?,?,?,?,?,?,?,?,?,?)",
            (txid, session, "VH-1003", material, weight_kg, status, retry,
             FAKE_NOW[0], next_retry_at, actuation_state),
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
        "SELECT transaction_id, session_token, material, weight_kg, status,"
        " retry_count, last_error, next_retry_at, actuation_state,"
        " actuation_started_at, actuation_completed_at"
        " FROM pending_transactions WHERE transaction_id = ?",
        (txid,),
    ).fetchone()
    c.close()
    return dict(r) if r else None


def cols_of(table="pending_transactions"):
    c = _conn()
    rows = c.execute(f"PRAGMA table_info({table})").fetchall()
    c.close()
    return {r[1] for r in rows}


def finalized_tokens():
    c = _conn()
    rows = c.execute("SELECT session_token FROM finalized_sessions").fetchall()
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
# 0. schema migration
# =====================================================================
print("== 0. additive migration ==")
_c = cols_of()
check("0a actuation_state column added", "actuation_state" in _c, _c)
check("0b actuation_started_at column added", "actuation_started_at" in _c)
check("0c actuation_completed_at column added", "actuation_completed_at" in _c)

# legacy DB without the M5 columns must gain them and keep working
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
_lcols = {
    r[1] for r in sqlite3.connect(_legacy).execute(
        "PRAGMA table_info(pending_transactions)"
    )
}
check("0d legacy DB gains actuation_state", "actuation_state" in _lcols)
_lc = sqlite3.connect(_legacy)
_lc.row_factory = sqlite3.Row
_lrow = dict(_lc.execute(
    "SELECT status, actuation_state FROM pending_transactions"
    " WHERE transaction_id='LEGACY-1'"
).fetchone())
_lc.close()
check(
    "0e legacy row kept PENDING with NULL actuation_state",
    _lrow["status"] == "PENDING" and _lrow["actuation_state"] is None,
    _lrow,
)
check(
    "0f legacy NULL row is submittable (never seen as unsorted)",
    oq.is_submittable(_lrow) is True,
)
oq.DB_FILE = _saved

# =====================================================================
# 1. is_submittable() truth table
# =====================================================================
print("== 1. is_submittable ==")
check("1a NULL -> submittable", oq.is_submittable({"actuation_state": None}) is True)
check("1b ACTUATED -> submittable",
      oq.is_submittable({"actuation_state": oq.ACTUATION_COMPLETED}) is True)
for _s in (
    oq.ACTUATION_PREPARED,
    oq.ACTUATION_STARTED,
    oq.ACTUATION_UNCERTAIN,
    oq.ACTUATION_ABANDONED,
):
    check(f"1c {_s} -> NOT submittable",
          oq.is_submittable({"actuation_state": _s}) is False)
check("1d missing column falls back to submittable",
      oq.is_submittable({}) is True)

# =====================================================================
# 2. begin_actuation commits a PREPARED row
# =====================================================================
print("== 2. begin_actuation ==")
reset_queue()
FAKE_NOW[0] = 1000.0
oq.begin_actuation("TX-BEGIN", "tok-A", "VH-1003", "Plastic", 0.5, "{}")
r = row_by_tx("TX-BEGIN")
check(
    "2a row exists, PENDING, PREPARED, unstarted",
    r is not None and r["status"] == "PENDING"
    and r["actuation_state"] == oq.ACTUATION_PREPARED
    and r["actuation_started_at"] is None
    and r["actuation_completed_at"] is None,
    r,
)
check(
    "2b row is NOT submittable while PREPARED",
    oq.is_submittable(r) is False,
)
check(
    "2c session/material/weight persisted",
    r["session_token"] == "tok-A" and r["material"] == "Plastic"
    and abs(r["weight_kg"] - 0.5) < 1e-9,
    r,
)
check("2d transaction_exists sees it", oq.transaction_exists("TX-BEGIN") is True)
check("2e transaction_exists false for unknown",
      oq.transaction_exists("NOPE") is False)

# =====================================================================
# 3. actuation state machine
# =====================================================================
print("== 3. state machine ==")
FAKE_NOW[0] = 1001.0
oq.mark_actuation_started("TX-BEGIN")
r = row_by_tx("TX-BEGIN")
check(
    "3a started: ACTUATING, start timestamp set, still not submittable",
    r["actuation_state"] == oq.ACTUATION_STARTED
    and r["actuation_started_at"] == 1001.0
    and oq.is_submittable(r) is False,
    r,
)
FAKE_NOW[0] = 1002.0
oq.mark_actuation_completed("TX-BEGIN")
r = row_by_tx("TX-BEGIN")
check(
    "3b completed: ACTUATED, completion timestamp set, submittable",
    r["actuation_state"] == oq.ACTUATION_COMPLETED
    and r["actuation_completed_at"] == 1002.0
    and oq.is_submittable(r) is True,
    r,
)

reset_queue()
oq.begin_actuation("TX-UNC", "tok-A", "VH-1003", "Paper", 0.2, "{}")
oq.mark_actuation_started("TX-UNC")
oq.mark_actuation_uncertain("TX-UNC", "servo blew up")
r = row_by_tx("TX-UNC")
check(
    "3c uncertain: UNCERTAIN, still PENDING, not submittable",
    r["actuation_state"] == oq.ACTUATION_UNCERTAIN
    and r["status"] == "PENDING"
    and oq.is_submittable(r) is False
    and "servo blew up" in (r["last_error"] or ""),
    r,
)

reset_queue()
oq.begin_actuation("TX-ABN", "tok-A", "VH-1003", "Paper", 0.2, "{}")
oq.mark_actuation_abandoned("TX-ABN", "never started")
r = row_by_tx("TX-ABN")
check(
    "3d abandoned: ABANDONED + FAILED, not pending, not submittable",
    r["actuation_state"] == oq.ACTUATION_ABANDONED
    and r["status"] == "FAILED"
    and r["next_retry_at"] is None
    and oq.is_submittable(r) is False,
    r,
)

# state transitions never touch an already-SYNCED row
reset_queue()
insert_synced("TX-SYNC", session="tok-A")
oq.mark_actuation_uncertain("TX-SYNC", "should be ignored")
r = row_by_tx("TX-SYNC")
check(
    "3e already-SYNCED row is not mutated by actuation transitions",
    r["status"] == "SYNCED" and r["actuation_state"] is None,
    r,
)

# =====================================================================
# 4. startup recovery
# =====================================================================
print("== 4. recover_actuation_states ==")
reset_queue()
FAKE_NOW[0] = 2000.0
insert_row("TX-PREP", session="tok-R", actuation_state=oq.ACTUATION_PREPARED)
insert_row("TX-ACT", session="tok-R", actuation_state=oq.ACTUATION_STARTED)
insert_row("TX-DONE", session="tok-R", actuation_state=oq.ACTUATION_COMPLETED)
insert_synced("TX-SY", session="tok-R")
recovered = oq.recover_actuation_states()
check(
    "4a exactly the PREPARED and ACTUATING rows were recovered",
    sorted(t[0] for t in recovered) == ["TX-ACT", "TX-PREP"],
    recovered,
)
r_prep = row_by_tx("TX-PREP")
r_act = row_by_tx("TX-ACT")
r_done = row_by_tx("TX-DONE")
r_sy = row_by_tx("TX-SY")
check(
    "4b PREPARED -> ABANDONED/FAILED (nothing moved, no points)",
    r_prep["actuation_state"] == oq.ACTUATION_ABANDONED
    and r_prep["status"] == "FAILED",
    r_prep,
)
check(
    "4c ACTUATING -> UNCERTAIN, still PENDING (may have moved)",
    r_act["actuation_state"] == oq.ACTUATION_UNCERTAIN
    and r_act["status"] == "PENDING",
    r_act,
)
check(
    "4d ACTUATED row is untouched (it already completed)",
    r_done["actuation_state"] == oq.ACTUATION_COMPLETED
    and r_done["status"] == "PENDING",
    r_done,
)
check(
    "4e SYNCED row is untouched",
    r_sy["status"] == "SYNCED",
    r_sy,
)
unc = {row["transaction_id"] for row in oq.get_uncertain_transactions()}
check("4f get_uncertain_transactions lists only the uncertain row",
      unc == {"TX-ACT"}, unc)

# =====================================================================
# 5. flush gating: unresolved rows are never submitted
# =====================================================================
print("== 5. flush never submits unresolved rows ==")
reset_queue()
FAKE_NOW[0] = 3000.0
insert_row("TX-U", session="tok-U", actuation_state=oq.ACTUATION_UNCERTAIN)
insert_row("TX-P", session="tok-U", actuation_state=oq.ACTUATION_PREPARED)
insert_row("TX-A1", session="tok-U", actuation_state=oq.ACTUATION_STARTED)
insert_row("TX-LEGACY", session="tok-U")  # NULL = pre-M5, submittable
insert_row("TX-OK2", session="tok-U", actuation_state=oq.ACTUATION_COMPLETED)
set_api(OK)
n = M.flush_offline_queue()
sent = {call[1].get("transaction_id") for call in CALLS}
check(
    "5a only ACTUATED + legacy rows were submitted",
    sent == {"TX-LEGACY", "TX-OK2"},
    sent,
)
check("5b flush synced exactly those two", n == 2, n)
check("5c UNCERTAIN row still PENDING/UNCERTAIN",
      row_by_tx("TX-U")["status"] == "PENDING"
      and row_by_tx("TX-U")["actuation_state"] == oq.ACTUATION_UNCERTAIN)
check("5d PREPARED row still PENDING/PREPARED",
      row_by_tx("TX-P")["status"] == "PENDING")
check("5e ACTUATING row still PENDING/ACTUATING",
      row_by_tx("TX-A1")["status"] == "PENDING")
check("5f legacy NULL row synced", row_by_tx("TX-LEGACY")["status"] == "SYNCED")
check("5g ACTUATED row synced", row_by_tx("TX-OK2")["status"] == "SYNCED")

# unresolved rows must not be failed by the retry-limit guard either
reset_queue()
FAKE_NOW[0] = 3010.0
insert_row("TX-UMAX", session="tok-U", actuation_state=oq.ACTUATION_UNCERTAIN,
           retry=oq.MAX_RETRY_COUNT, next_retry_at=99999.0)
set_api(RETRYABLE)
M.flush_offline_queue()
r = row_by_tx("TX-UMAX")
check(
    "5h UNCERTAIN row at the retry limit is NOT moved to FAILED",
    r["status"] == "PENDING" and r["actuation_state"] == oq.ACTUATION_UNCERTAIN,
    r,
)
check("5i no API call for the unresolved row", len(CALLS) == 0, len(CALLS))

# =====================================================================
# 6. unresolved row blocks session finalization
# =====================================================================
print("== 6. unresolved blocks finalization ==")
reset_queue()
FAKE_NOW[0] = 4000.0
insert_synced("TX-DONE", session="tok-B")
insert_row("TX-HOLD", session="tok-B", actuation_state=oq.ACTUATION_UNCERTAIN)
set_api({"http_status": 200, "json": {"success": True, "points_awarded": 3},
         "transport_error": None})
n = M.finalize_abandoned_sessions()
check(
    "6a session with an UNCERTAIN row is NOT finalized",
    n == 0 and "tok-B" not in finalized_tokens(),
    (n, finalized_tokens()),
)
check("6b has_pending_transactions counts the UNCERTAIN row",
      oq.has_pending_transactions("tok-B") is True)

# =====================================================================
# 7. process_item: record BEFORE actuation; refuse when it can't commit
# =====================================================================
print("== 7. process_item ordering ==")


def _install_good_hardware():
    M.reset_camera = lambda: None
    M.notify_unrecognized_item = lambda: None
    M.wait_for_item = lambda: 500.0
    M.get_camera_material = lambda: "Plastic"
    M.wait_for_weight_stable = lambda: 500.0
    M.wait_for_removal = lambda: True
    M.metal_detected = lambda: False
    M.Servo.sort_material = lambda m: True


# 7a: the durable row exists (and is ACTUATING) before the servo runs
reset_queue()
FAKE_NOW[0] = 5000.0
_install_good_hardware()
_seen = {}


def _spy_sort(material):
    _seen["row_at_actuation"] = row_by_tx(_seen.get("txid", "?"))
    return True


M.Servo.sort_material = _spy_sort
_orig_begin = oq.begin_actuation


def _begin_capture(*args, **kwargs):
    _orig_begin(*args, **kwargs)
    _seen["txid"] = args[0]


oq.begin_actuation = _begin_capture
M.begin_actuation = _begin_capture
res = M.process_item("tok-P", "VH-1003")
oq.begin_actuation = _orig_begin
M.begin_actuation = _orig_begin

check(
    "7a process_item returns a 4-tuple ending in transaction_id",
    isinstance(res, tuple) and len(res) == 4 and res[0] == 500.0,
    res,
)
if isinstance(res, tuple) and len(res) == 4:
    txid = res[3]
    row = row_by_tx(txid)
    check(
        "7b after success the row is PENDING and ACTUATED",
        row is not None and row["status"] == "PENDING"
        and row["actuation_state"] == oq.ACTUATION_COMPLETED,
        row,
    )
    seen = _seen.get("row_at_actuation")
    check(
        "7c the durable row already existed (ACTUATING) before sort_material",
        seen is not None and seen["actuation_state"] == oq.ACTUATION_STARTED
        and seen["transaction_id"] == txid,
        seen,
    )
    check(
        "7d all queued rows from this item are submittable",
        oq.is_submittable(row) is True,
    )

# 7b: if the pre-actuation record cannot be committed, do NOT actuate
reset_queue()
FAKE_NOW[0] = 5100.0
_install_good_hardware()
_called = {"sort": False}


def _spy_sort2(material):
    _called["sort"] = True
    return True


M.Servo.sort_material = _spy_sort2
_orig_storage = oq.has_enough_storage
oq.has_enough_storage = lambda: False
res = M.process_item("tok-P", "VH-1003")
oq.has_enough_storage = _orig_storage
check("7e uncommittable record -> REJECTED", res == "REJECTED", res)
check("7f servo was NOT commanded", _called["sort"] is False)
check("7g no queue row was left behind", row_by_tx("x") is None
      and len(oq.get_pending_transactions()) == 0)

# 7c: a servo exception leaves the row UNCERTAIN, never ACTUATED
reset_queue()
FAKE_NOW[0] = 5200.0
_install_good_hardware()


def _boom(material):
    raise RuntimeError("servo power cut")


M.Servo.sort_material = _boom
res = M.process_item("tok-P", "VH-1003")
check("7h servo failure -> REJECTED", res == "REJECTED", res)
_pending = oq.get_pending_transactions()
check("7i exactly one queued row from the failed actuation",
      len(_pending) == 1, len(_pending))
if _pending:
    check(
        "7j failed actuation row is UNCERTAIN (never ACTUATED/submittable)",
        _pending[0]["actuation_state"] == oq.ACTUATION_UNCERTAIN
        and oq.is_submittable(_pending[0]) is False,
        dict(_pending[0]),
    )

# 7d: if 'started' cannot be persisted, abandon and do NOT actuate
reset_queue()
FAKE_NOW[0] = 5300.0
_install_good_hardware()
_called = {"sort": False}


def _spy_sort3(material):
    _called["sort"] = True
    return True


M.Servo.sort_material = _spy_sort3
_orig_started = M.mark_actuation_started


def _fail_started(txid):
    raise RuntimeError("disk full")


M.mark_actuation_started = _fail_started
res = M.process_item("tok-P", "VH-1003")
M.mark_actuation_started = _orig_started
check("7k unpersistable 'started' -> REJECTED", res == "REJECTED", res)
check("7l servo was NOT commanded", _called["sort"] is False)
_pending = oq.get_pending_transactions()
check("7m the never-commanded row was abandoned (no pending left)",
      len(_pending) == 0, [dict(x) for x in _pending])
_abandoned = None
_c = _conn()
_abandoned = _c.execute(
    "SELECT status, actuation_state FROM pending_transactions"
).fetchone()
_c.close()
check(
    "7n abandoned row is FAILED/ABANDONED",
    _abandoned is not None and _abandoned["status"] == "FAILED"
    and _abandoned["actuation_state"] == oq.ACTUATION_ABANDONED,
    dict(_abandoned) if _abandoned else None,
)

# =====================================================================
# 8. failed submit -> same transaction_id reused on retry
# =====================================================================
print("== 8. ambiguous submit reuses the transaction_id ==")
reset_queue()
FAKE_NOW[0] = 6000.0
tx = "TX-AMBIG"
oq.begin_actuation(tx, "tok-Z", "VH-1003", "Plastic", 0.5, "{}")
oq.mark_actuation_started(tx)
oq.mark_actuation_completed(tx)
# The response was lost: record the failure into the SAME row (M5 keeps
# the pre-actuation row instead of inserting a duplicate).
saved = M.record_failed_submission(
    "tok-Z", "VH-1003", "plastic", 500.0, tx,
    {"retryable": True, "http_status": None, "message": "timeout"}
)
check("8a failed submit reuses the existing row (QUEUED)", saved == "QUEUED", saved)
_c = _conn()
_cnt = _c.execute("SELECT COUNT(*) AS n FROM pending_transactions").fetchone()["n"]
_c.close()
check("8b no duplicate row was created", _cnt == 1, _cnt)
check("8c the row stayed submittable (ACTUATED)",
      oq.is_submittable(row_by_tx(tx)) is True)
set_api(OK)
FAKE_NOW[0] = 6005.0
M.flush_offline_queue()
ids = [call[1].get("transaction_id") for call in CALLS]
check("8d retry reused the original transaction_id", ids == [tx], ids)
check("8e row is now SYNCED", row_by_tx(tx)["status"] == "SYNCED")

# ---- control: a genuinely unsorted (PREPARED) row must never sync
reset_queue()
FAKE_NOW[0] = 6100.0
insert_row("tok-UNSORTED-TX", session="tok-Z",
           actuation_state=oq.ACTUATION_PREPARED)
set_api(OK)
M.flush_offline_queue()
check("8f PREPARED (unsorted) row was never sent", len(CALLS) == 0, len(CALLS))
check("8g and it was not modified",
      row_by_tx("tok-UNSORTED-TX")["actuation_state"] == oq.ACTUATION_PREPARED)

# =====================================================================
print("=" * 60)
print(f"RESULT: {PASS} passed, {FAIL} failed")
print("=" * 60)

# Leave no scratch state behind; never touch the repo DB.
oq.DB_FILE = _OLD_DB
sys.exit(0 if FAIL == 0 else 1)
