#!/usr/bin/env python3
"""Regression tests for the M1 HTTP error-classification fix.

These tests exercise the REAL classify/complete/queue code in
Main.py and offline_queue.py against a mocked API. The device
hardware modules (Servo / camera / load cell / inductive / QR
keyboard) are stubbed out so the module can be imported on a
non-Raspberry-Pi host.

All queue state is written to a scratch SQLite database under the
system temp dir. The repository's vheco_local.db is never opened.

Run from anywhere:
    python3 Project_wasteBin/test_m1_error_classification.py
"""

import json
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
_TMP = tempfile.mkdtemp(prefix="m1_errclass_")
_OLD_DB = oq.DB_FILE
oq.DB_FILE = os.path.join(_TMP, "queue.db")
oq.init_db()


def _conn():
    c = sqlite3.connect(oq.DB_FILE)
    c.row_factory = sqlite3.Row
    return c


def set_api(resp_spec):
    """Point the module-level api_post_ex at a canned response."""
    M.api_post_ex = lambda url, data, timeout=None: resp_spec


def seed(session, n_synced=0, n_pending=0):
    c = _conn()
    now = time.time()
    for i in range(n_synced):
        c.execute(
            "INSERT INTO pending_transactions (transaction_id, session_token,"
            " qr_code, material, weight_kg, status, created_at, synced_at)"
            " VALUES (?,?,?,?,?, 'SYNCED', ?, ?)",
            (f"{session}-S{i}", session, "VH-1003", "Plastic", 0.2, now, now),
        )
    for i in range(n_pending):
        c.execute(
            "INSERT INTO pending_transactions (transaction_id, session_token,"
            " qr_code, material, weight_kg, status, created_at)"
            " VALUES (?,?,?,?,?, 'PENDING', ?)",
            (f"{session}-P{i}", session, "VH-1003", "Plastic", 0.2, now),
        )
    c.commit()
    c.close()


def finalized_tokens():
    c = _conn()
    rows = c.execute(
        "SELECT session_token FROM finalized_sessions ORDER BY session_token"
    ).fetchall()
    c.close()
    return {r["session_token"] for r in rows}


def row_by_tx(txid):
    c = _conn()
    r = c.execute(
        "SELECT status, retry_count, last_error, session_token, transaction_id"
        " FROM pending_transactions WHERE transaction_id = ?",
        (txid,),
    ).fetchone()
    c.close()
    return dict(r) if r else None


PASS = 0
FAIL = 0
CHECKS = []


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        CHECKS.append(("PASS", name))
    else:
        FAIL += 1
        CHECKS.append(("FAIL", name, detail))
        print(f"  FAIL  {name}  {detail}")


# =====================================================================
# Contract guard: client constants must match the real PHP 404 bodies.
# =====================================================================
print("== 0. endpoint contract drift guard ==")
_php_complete = (SCRIPT_DIR.parent / "api" / "complete_session.php").read_text(
    encoding="utf-8"
)
_php_submit = (SCRIPT_DIR.parent / "api" / "submit_waste_data.php").read_text(
    encoding="utf-8"
)
check(
    "COMPLETE_NOT_FOUND_MESSAGE matches complete_session.php",
    M.COMPLETE_NOT_FOUND_MESSAGE in _php_complete,
    M.COMPLETE_NOT_FOUND_MESSAGE,
)
check(
    "SUBMIT_NOT_FOUND_MESSAGE matches submit_waste_data.php",
    M.SUBMIT_NOT_FOUND_MESSAGE in _php_submit,
    M.SUBMIT_NOT_FOUND_MESSAGE,
)

# =====================================================================
# 1. complete_api_session_ex classification matrix
# =====================================================================
print("== 1. complete_api_session_ex ==")

set_api({"http_status": 200, "json": {"success": True, "points_awarded": 5},
         "transport_error": None})
o = M.complete_api_session_ex("tok-OK")
check("1a success -> ok True, terminal False",
      o["ok"] is True and o["terminal"] is False, o)

set_api({"http_status": 404, "json": {"success": False,
         "message": M.COMPLETE_NOT_FOUND_MESSAGE}, "transport_error": None})
o = M.complete_api_session_ex("tok-DONE")
check("1b recognized already-finalized 404 -> terminal True",
      o["ok"] is False and o["terminal"] is True, o)

set_api({"http_status": 400, "json": {"success": False,
         "message": "Invalid or missing material"}, "transport_error": None})
o = M.complete_api_session_ex("tok-BAD")
check("1c recognized invalid/ineligible (app 400) -> terminal True",
      o["ok"] is False and o["terminal"] is True, o)

set_api({"http_status": 401, "json": {"success": False,
         "message": "Invalid API key"}, "transport_error": None})
o = M.complete_api_session_ex("tok-AUTH")
check("1d recognized auth 401 -> terminal True",
      o["ok"] is False and o["terminal"] is True, o)

set_api({"http_status": 404, "json": None, "transport_error": None})
o = M.complete_api_session_ex("tok-HTML404")
check("1e generic non-JSON 404 -> terminal False, status+msg preserved",
      o["terminal"] is False and o["http_status"] == 404
      and o["message"] == "http_404_invalid_json", o)

set_api({"http_status": 404, "json": {"success": False,
         "message": "Not Found"}, "transport_error": None})
o = M.complete_api_session_ex("tok-WEB404")
check("1f unexpected JSON 404 -> terminal False",
      o["terminal"] is False and o["message"] == "Not Found", o)

set_api({"http_status": 404, "json": {"error": "not found"},
         "transport_error": None})
o = M.complete_api_session_ex("tok-JSON404NOENV")
check("1g JSON 404 without success/message -> terminal False",
      o["terminal"] is False, o)

set_api({"http_status": 500, "json": None, "transport_error": None})
o = M.complete_api_session_ex("tok-HTML500")
check("1h non-JSON 500 -> terminal False", o["terminal"] is False, o)

set_api({"http_status": None, "json": None, "transport_error": "timeout"})
o = M.complete_api_session_ex("tok-TIMEOUT")
check("1i timeout -> terminal False, http_status None",
      o["terminal"] is False and o["http_status"] is None, o)

set_api({"http_status": None, "json": None, "transport_error": "connection_error"})
o = M.complete_api_session_ex("tok-CONN")
check("1j connection failure -> terminal False",
      o["terminal"] is False, o)

set_api({"http_status": 200, "json": None, "transport_error": None})
o = M.complete_api_session_ex("tok-JUNK200")
check("1k non-JSON 200 -> terminal False", o["terminal"] is False, o)

o = M.complete_api_session_ex("")
check("1l empty token -> terminal True without network",
      o["terminal"] is True and o["http_status"] is None, o)

# =====================================================================
# 2. _classify_submit_failure / offline queue status transitions
# =====================================================================
print("== 2. submit classification / queue ==")


def submit_outcome(resp_spec, session="tok-S", material="plastic",
                   weight=200.0, txid=None):
    set_api(resp_spec)
    return M.submit_waste_data(session, material, weight,
                               transaction_id=txid or uuid.uuid4().hex)


o = submit_outcome({"http_status": 400, "json": None,
                    "transport_error": None})
check("2a generic non-JSON 400 -> retryable True",
      o["ok"] is False and o["retryable"] is True, o)

o = submit_outcome({"http_status": 403, "json": None,
                    "transport_error": None})
check("2b generic non-JSON 403 -> retryable True",
      o["ok"] is False and o["retryable"] is True, o)

o = submit_outcome({"http_status": 404, "json": {"success": False,
                    "message": "Not Found"}, "transport_error": None})
check("2c unexpected JSON 404 (not endpoint contract) -> retryable True",
      o["ok"] is False and o["retryable"] is True, o)

o = submit_outcome({"http_status": 400, "json": {"success": False,
                    "message": "Unknown material. Allowed: x"},
                    "transport_error": None})
check("2d recognized app 400 (invalid payload) -> retryable False",
      o["ok"] is False and o["retryable"] is False, o)

o = submit_outcome({"http_status": 404, "json": {"success": False,
                    "message": M.SUBMIT_NOT_FOUND_MESSAGE},
                    "transport_error": None})
check("2e recognized submit 404 (session gone) -> retryable False",
      o["ok"] is False and o["retryable"] is False, o)

o = submit_outcome({"http_status": 404, "json": {"success": False,
                    "message": ""}, "transport_error": None})
check("2f JSON 404 with empty message is not a rejection -> retryable True",
      o["ok"] is False and o["retryable"] is True, o)

o = submit_outcome({"http_status": None, "json": None,
                    "transport_error": "timeout"})
check("2g timeout -> retryable True", o["retryable"] is True, o)

# ---- queue transitions through flush_offline_queue ----------------
seed("tok-FLUSH", 0, 0)
c = _conn()
now = time.time()
for txid, retry in (("TX-GENERIC4XX", 0), ("TX-BAD400", 0), ("TX-MAX", 8)):
    c.execute(
        "INSERT INTO pending_transactions (transaction_id, session_token,"
        " qr_code, material, weight_kg, status, retry_count, created_at)"
        " VALUES (?,?,?,?,?, 'PENDING', ?, ?)",
        (txid, "tok-FLUSH", "VH-1003", "Plastic", 0.2, retry, now),
    )
c.commit()
c.close()

_calls = []


def sequence(specs):
    def _f(url, data, timeout=None):
        spec = specs[min(len(_calls), len(specs) - 1)]
        _calls.append((url, data))
        return spec
    return _f


M.api_post_ex = sequence([
    {"http_status": 400, "json": None, "transport_error": None},          # TX-GENERIC4XX
    {"http_status": 400, "json": {"success": False,
     "message": "Unknown material"}, "transport_error": None},             # TX-BAD400
    {"http_status": 200, "json": {"success": True}, "transport_error": None},  # TX-MAX (ignored)
])
M.flush_offline_queue()

r = row_by_tx("TX-GENERIC4XX")
check("2h generic 4xx stays PENDING and increments retry",
      r["status"] == "PENDING" and r["retry_count"] == 1, r)

r = row_by_tx("TX-BAD400")
check("2i recognized invalid payload -> FAILED (never retried)",
      r["status"] == "FAILED" and "Unknown material" in r["last_error"], r)

r = row_by_tx("TX-MAX")
check("2j retry limit still enforced -> FAILED 'gave up'",
      r["status"] == "FAILED" and "gave up" in r["last_error"], r)

# ---- idempotent replay after an ambiguous timeout ------------------
o = submit_outcome({"http_status": None, "json": None,
                    "transport_error": "timeout"}, txid="TX-STABLE")
r = M.record_failed_submission("tok-STABLE", "VH-1003", "plastic", 200.0,
                               "TX-STABLE", o)
check("2k ambiguous timeout -> item queued (not failed)",
      r == "QUEUED", r)

set_api({"http_status": 200, "json": {"success": True, "duplicate": True,
         "transaction_id": "TX-STABLE"}, "transport_error": None})
n = M.flush_offline_queue()
r = row_by_tx("TX-STABLE")
# The generic-4xx row from 2h is also still PENDING and synced in the
# same pass, so count >= 1; the key assertions are that TX-STABLE is
# SYNCED and its stable transaction id survived every retry.
check("2l stable tx id reused on retry and marked SYNCED on replay",
      n >= 1 and r["status"] == "SYNCED" and r["transaction_id"] == "TX-STABLE",
      (n, r))

# =====================================================================
# 3. finalize_abandoned_sessions: ledger only on recognized terminal/ok
# =====================================================================
print("== 3. finalization ledger safety ==")

seed("LEAD-TRANSIENT", n_synced=1, n_pending=0)
seed("LEAD-HTML404", n_synced=1, n_pending=0)
seed("LEAD-UNEXPECTED404", n_synced=1, n_pending=0)
seed("LEAD-OK", n_synced=1, n_pending=0)
seed("LEAD-RECOGNIZED404", n_synced=1, n_pending=0)

set_api({"http_status": 500, "json": {"success": False, "message": "boom"},
         "transport_error": None})
n = M.finalize_abandoned_sessions()
check("3a transient 500 completes nothing", n == 0, n)
check("3b transient 500 does NOT write ledger",
      "LEAD-TRANSIENT" not in finalized_tokens())

set_api({"http_status": None, "json": None, "transport_error": "timeout"})
M.finalize_abandoned_sessions()
check("3c timeout does NOT write ledger",
      "LEAD-TRANSIENT" not in finalized_tokens())

set_api({"http_status": 404, "json": None, "transport_error": None})
M.finalize_abandoned_sessions()
check("3d generic non-JSON 404 does NOT write ledger",
      "LEAD-HTML404" not in finalized_tokens())

set_api({"http_status": 404, "json": {"success": False, "message": "Not Found"},
         "transport_error": None})
M.finalize_abandoned_sessions()
check("3e unexpected JSON 404 does NOT write ledger",
      "LEAD-UNEXPECTED404" not in finalized_tokens())

set_api({"http_status": 200, "json": {"success": True, "points_awarded": 7},
         "transport_error": None})
M.finalize_abandoned_sessions()
check("3f confirmed success writes ledger",
      "LEAD-OK" in finalized_tokens())

set_api({"http_status": 404, "json": {"success": False,
         "message": M.COMPLETE_NOT_FOUND_MESSAGE}, "transport_error": None})
M.finalize_abandoned_sessions()
check("3g recognized already-finalized 404 writes ledger (retires session)",
      "LEAD-RECOGNIZED404" in finalized_tokens())

# An eligible ERROR session (genuine finalize failure): the real server
# marks the session ERROR with the finalize marker on the 500, and the
# retry answers 200 "recovered" instead of a 404. Model that contract so
# the client still writes the ledger once the recovery completes.
seed("LEAD-RECOVERED", n_synced=1, n_pending=0)
set_api({"http_status": 500, "json": {"success": False, "message": "boom"},
         "transport_error": None})
M.finalize_abandoned_sessions()
before = "LEAD-RECOVERED" in finalized_tokens()
set_api({"http_status": 200, "json": {"success": True, "session_status": "COMPLETED",
         "points_awarded": 7}, "transport_error": None})
M.finalize_abandoned_sessions()
check("3h transient finalize failure is recovered on retry (200) -> ledger written",
      before is False and "LEAD-RECOVERED" in finalized_tokens())

# A NON-eligible ERROR session (e.g. deliberate hardware fault -- the
# backend's marker check rejects it) is NOT recovered by the server, so
# the retry still answers the recognized 404. The client writes the
# ledger (retires the session) but is never handed any points for it.
seed("LEAD-NOTELIGIBLE", n_synced=1, n_pending=0)
set_api({"http_status": 500, "json": {"success": False, "message": "boom"},
         "transport_error": None})
M.finalize_abandoned_sessions()
before = "LEAD-NOTELIGIBLE" in finalized_tokens()
set_api({"http_status": 404, "json": {"success": False,
         "message": M.COMPLETE_NOT_FOUND_MESSAGE}, "transport_error": None})
M.finalize_abandoned_sessions()
check("3i non-eligible ERROR retried then retired on recognized 404 (no award)",
      before is False and "LEAD-NOTELIGIBLE" in finalized_tokens())

# Active session must stay excluded (regression guard for core M1 gate).
seed("LEAD-ACTIVE", n_synced=1, n_pending=0)
set_api({"http_status": 200, "json": {"success": True, "points_awarded": 2},
         "transport_error": None})
n = M.finalize_abandoned_sessions("LEAD-ACTIVE")
check("3j active session still excluded from finalization",
      n == 0 and "LEAD-ACTIVE" not in finalized_tokens())

# =====================================================================
# 4. finalize-recovery marker hardening -- server contract drift guards
#
# These are STATIC string guards against accidental drift in the real
# PHP sources (same style as section 0). They can run offline with no
# MySQL. They are NOT a substitute for a MySQL integration test: the
# recovery gate, locking, and concurrency semantics can only be proven
# against a live database (see Project_wasteBin/test_error_recovery_marker.php
# for the pure-PHP unit test of the real normalizer).
# =====================================================================
print("== 4. finalize-recovery marker hardening (static) ==")

_core_src = (SCRIPT_DIR.parent / "ecopoint_core.php").read_text(encoding="utf-8")
_err_src = (SCRIPT_DIR.parent / "api" / "error_session.php").read_text(encoding="utf-8")
_complete_src = (SCRIPT_DIR.parent / "api" / "complete_session.php").read_text(encoding="utf-8")

check(
    "4a core defines the reserved marker constant",
    "define('ECO_FINALIZE_ERROR_MARKER', 'finalize_error: ');" in _core_src,
)
check(
    "4b core defines the hardware-error normalizer",
    "function eco_normalize_hardware_error_message(" in _core_src,
)
check(
    "4c normalizer guards on the reserved marker prefix",
    "strncmp($message, ECO_FINALIZE_ERROR_MARKER, strlen(ECO_FINALIZE_ERROR_MARKER)) === 0"
    in _core_src,
)
check(
    "4d error_session.php normalizes the hardware-reported message at the boundary",
    "$message  = eco_normalize_hardware_error_message(" in _err_src,
)
check(
    "4e hardware path never stamps the reserved marker itself",
    "ECO_FINALIZE_ERROR_MARKER" not in _err_src,
)
check(
    "4f genuine server marker assignment preserved in complete_session.php catch",
    "ECO_FINALIZE_ERROR_MARKER . $e->getMessage()" in _complete_src,
)
check(
    "4g recovery lock still FOR UPDATE and ERROR-only",
    "AND  station_id    = ?" in _core_src
    and "status        = 'ERROR'" in _core_src
    and "FOR UPDATE" in _core_src,
)
check(
    "4h recovery still honors points_posted double-award guard",
    "$alreadyPosted = ((int)($session['points_posted'] ?? 0) === 1);" in _core_src,
)
check(
    "4i recovery still requires awardable WASTE_DATA or already-posted state",
    "sumStmt" in _core_src and "points_calc" in _core_src,
)

# =====================================================================
print("=" * 60)
print(f"RESULT: {PASS} passed, {FAIL} failed")
print("=" * 60)
sys.exit(0 if FAIL == 0 else 1)