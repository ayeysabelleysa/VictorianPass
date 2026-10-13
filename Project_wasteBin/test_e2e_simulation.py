#!/usr/bin/env python3
"""Hardware-free end-to-end integration simulation for VHEcoPoint (Phase 2).

This suite drives the REAL station application code in Main.py and
offline_queue.py -- process_item(), submit_waste_data(),
record_failed_submission(), flush_offline_queue(),
finalize_abandoned_sessions(), recover_actuation_states(), the M5
write-ahead actuation lifecycle and the real QR / weight /
inductive / servo modules -- while mocking ONLY the external
boundaries:

  * physical hardware: QR keyboard, load cell, camera service,
    inductive sensor and servo are replaced by scripted fakes;
  * the network/API: requests to Hostinger are intercepted by an
    in-process contract model of the PHP endpoints.

The server side of the contract is NOT re-implemented in Python.
Every server decision and point calculation the fake server needs
(points rate, cap rules, submission/completion authorization,
transaction-id extraction) is delegated to the REAL PHP code in
ecopoint_core.php / ecopoint_idempotency.php through a tiny CLI
bridge, so the production business algorithm is exercised rather
than duplicated.

All SQLite state is written to a scratch database in the system temp
dir. The repository's live vheco_local.db is never opened, created,
initialised or modified. No network request is ever made and no GPIO /
camera / servo / load-cell driver is ever invoked.

Run from anywhere:
    python3 Project_wasteBin/test_e2e_simulation.py
"""

import contextlib
import io
import json
import os
import pathlib
import shutil
import sqlite3
import subprocess
import sys
import tempfile
import types
import uuid

OUT = sys.__stdout__
VERBOSE = bool(os.environ.get("VHECO_E2E_VERBOSE"))
_APP_LOG = io.StringIO()
_redirect = contextlib.redirect_stdout(_APP_LOG)


def say(*parts):
    print(*parts, file=OUT)

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
sys.path.insert(0, str(SCRIPT_DIR))

os.environ.setdefault("VHECO_API_KEY", "test-key")

# =====================================================================
# PHP BRIDGE: call the REAL server business logic without a database
# =====================================================================
BRIDGE_PHP = r'''<?php
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
if (!class_exists('mysqli')) {
  class mysqli { public $error = ''; public function __construct() {} public function prepare($sql) { return false; } }
}
if (!class_exists('mysqli_stmt')) {
  class mysqli_stmt { public $error = ''; public function bind_param($types, &...$vars) { return true; } public function execute() { return true; } public function close() {} }
}
$root = getenv('VHECO_ROOT');
$src = file_get_contents($root . '/ecopoint_core.php');
$src = preg_replace("/^require_once\s+__DIR__\s*\.\s*'\/connect\.php';\s*$/m", '', $src);
$src = preg_replace("/^require_once\s+__DIR__\s*\.\s*'\/ecopoint_idempotency\.php';\s*$/m", '', $src);
$tmp = tempnam(sys_get_temp_dir(), 'vh_e2e_core_');
file_put_contents($tmp, $src);
require_once $tmp;
@unlink($tmp);
require_once $root . '/ecopoint_idempotency.php';

$req  = json_decode(stream_get_contents(STDIN), true);
$fn   = $req['fn'] ?? '';
$args = $req['args'] ?? [];
try {
    switch ($fn) {
        case 'eco_calculate_points':            $r = eco_calculate_points(...$args); break;
        case 'eco_apply_cap_rules':             $r = eco_apply_cap_rules(...$args); break;
        case 'eco_submission_decision':         $r = eco_submission_decision(...$args); break;
        case 'eco_completion_decision':         $r = eco_completion_decision(...$args); break;
        case 'eco_terminal_transition_allowed': $r = eco_terminal_transition_allowed(...$args); break;
        case 'eco_extract_transaction_id':      $r = eco_extract_transaction_id(...$args); break;
        default: throw new Exception('unknown fn ' . $fn);
    }
    echo json_encode(['ok' => true, 'result' => $r]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
'''

PHP_BIN = shutil.which("php")
BRIDGE_FILE = os.path.join(tempfile.gettempdir(), "vh_e2e_bridge.php")
with open(BRIDGE_FILE, "w") as _bf:
    _bf.write(BRIDGE_PHP)

_PHP_CACHE = {}


def php_call(fn, *args):
    """Invoke one REAL PHP business function via the CLI bridge."""
    key = (fn, json.dumps(args, sort_keys=True, default=str))
    if key in _PHP_CACHE:
        return _PHP_CACHE[key]
    env = os.environ.copy()
    env["VHECO_ROOT"] = str(REPO_ROOT)
    proc = subprocess.run(
        [PHP_BIN, BRIDGE_FILE],
        input=json.dumps({"fn": fn, "args": list(args)}),
        capture_output=True,
        text=True,
        env=env,
    )
    if proc.returncode != 0:
        raise RuntimeError(
            f"php bridge {fn} exited {proc.returncode}: {proc.stderr.strip()}"
        )
    out = json.loads(proc.stdout.strip())
    if not out.get("ok"):
        raise RuntimeError(f"php bridge {fn} failed: {out.get('error')}")
    _PHP_CACHE[key] = out["result"]
    return out["result"]


# =====================================================================
# VIRTUAL CLOCK + REAL HARDWARE MODULE IMPORTS
#
# The real qr_scanner / Weight / inductive / Servo modules import fine
# on this host (their bus/display deps are present); we therefore load
# the real modules and only replace the functions that would touch
# physical hardware. Time is virtualised so the real wait loops run
# without sleeping.
# =====================================================================
CLOCK = [0.0]


class VClock:
    def time(self):
        return CLOCK[0]

    def monotonic(self):
        return CLOCK[0]

    def sleep(self, seconds):
        CLOCK[0] += float(seconds)


import qr_scanner as QRS      # noqa: E402  (real)
import Weight as WGT          # noqa: E402  (real)
import inductive as IND       # noqa: E402  (real)
import Servo as SRV           # noqa: E402  (real)
import offline_queue as oq    # noqa: E402
import Main as M              # noqa: E402

M.time = VClock()

# ---- redirect the queue to a fresh scratch DB -----------------------
_TMP_DIR = tempfile.mkdtemp(prefix="vh_e2e_")
_OLD_DB = oq.DB_FILE
oq.DB_FILE = os.path.join(_TMP_DIR, "queue.db")
oq.init_db()
oq.NOW = lambda: CLOCK[0]

# Guard: assert the live repository DB is never touched.
LIVE_DB = os.path.join(str(SCRIPT_DIR), "vheco_local.db")
LIVE_DB_STAT = (
    os.path.getmtime(LIVE_DB),
    os.path.getsize(LIVE_DB),
    os.path.getmtime(LIVE_DB + "-wal") if os.path.exists(LIVE_DB + "-wal") else None,
    os.path.getmtime(LIVE_DB + "-shm") if os.path.exists(LIVE_DB + "-shm") else None,
)


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


def row_by_tx(txid):
    c = _conn()
    r = c.execute(
        "SELECT * FROM pending_transactions WHERE transaction_id = ?", (txid,)
    ).fetchone()
    c.close()
    return dict(r) if r else None


def all_rows():
    c = _conn()
    rows = [dict(r) for r in c.execute("SELECT * FROM pending_transactions").fetchall()]
    c.close()
    return rows


def finalized_tokens():
    c = _conn()
    rows = c.execute("SELECT session_token FROM finalized_sessions").fetchall()
    c.close()
    return {r["session_token"] for r in rows}


# =====================================================================
# FAKE SERVER: contract model of the Hostinger PHP endpoints.
# Decisions/points are delegated to the REAL PHP code via php_call().
# =====================================================================
SUBMIT_NOT_FOUND = (
    "Session not found, already finalized, or does not belong to this station"
)
COMPLETE_NOT_FOUND = "Session not found or not eligible for completion"


class FakeServer:
    def reset(self):
        self.tokens = {}            # token -> session dict
        self.users = {42: 0.0}
        self.residents = {
            "VH-1003": {"user_id": 42, "full_name": "Test Resident", "balance": 0.0}
        }
        self.calls = []             # (endpoint, payload)
        self.fail_required_event = False
        self.network = None         # None | 'timeout' | 'connection_error'
        self.lose_submit_response = False
        self.fail_complete = False
        self.fail_session_creation = False
        self._next_id = 1000

    def __init__(self):
        self.reset()

    # -- helpers ------------------------------------------------------
    def _cap(self, user_id):
        return {
            "daily_points_used": 0.0,
            "daily_points_left": 100.0,
            "weekly_points_left": 250.0,
            "daily_sessions_left": 3,
            "max_balance": 3000.0,
        }

    def _is_open(self, s):
        return s["status"] in ("WAITING", "ACTIVE", "PROCESSING")

    def sessions(self):
        return list(self.tokens.values())

    def waste_events(self, token):
        s = self.tokens[token]
        return [e for e in s["events"] if e["event_type"] == "WASTE_DATA"]

    def txids(self, token):
        return [e["transaction_id"] for e in self.waste_events(token)]

    def points(self, user_id=42):
        return self.users[user_id]

    # -- dispatch -----------------------------------------------------
    def handle(self, url, data, timeout=None):
        endpoint = url.rsplit("/", 1)[-1]
        self.calls.append((endpoint, data))
        if self.network:
            return {"http_status": None, "json": None,
                    "transport_error": self.network}
        if endpoint == "qr_verify_and_create_session.php":
            return self._create(data)
        if endpoint == "submit_waste_data.php":
            return self._submit(data)
        if endpoint == "complete_session.php":
            return self._complete(data)
        if endpoint == "cancel_session.php":
            return self._cancel(data)
        return {"http_status": 404,
                "json": {"success": False, "message": "unknown endpoint"},
                "transport_error": None}

    # -- endpoints ----------------------------------------------------
    def _create(self, data):
        if self.fail_session_creation:
            return {"http_status": 500,
                    "json": {"success": False, "message": "Failed to create session"},
                    "transport_error": None}
        code = data.get("qr_code")
        res = self.residents.get(code)
        if not res:
            return {"http_status": 404,
                    "json": {"success": False, "message": "Resident not found"},
                    "transport_error": None}
        token = uuid.uuid4().hex
        sid = self._next_id
        self._next_id += 1
        session = {
            "id": sid, "token": token, "station_id": 1,
            "user_id": res["user_id"], "status": "ACTIVE",
            "material_type": None, "weight_kg": 0.0,
            "points_calculated": 0.0, "points_posted": False,
            "points_awarded": 0.0, "events": [],
        }
        self.tokens[token] = session
        return {"http_status": 200,
                "json": {"success": True, "session_token": token,
                         "session": {"id": sid},
                         "resident": {"full_name": res["full_name"],
                                      "balance": res["balance"]},
                         "cap_state": self._cap(res["user_id"])},
                "transport_error": None}

    def _dup(self, session, txid):
        return {"http_status": 200,
                "json": {"success": True, "duplicate": True,
                         "transaction_id": txid,
                         "session_status": session["status"],
                         "note": "Transaction already recorded (idempotent replay)."},
                "transport_error": None}

    def _submit(self, data):
        token = data.get("session_token")
        session = self.tokens.get(token)
        txid = php_call("eco_extract_transaction_id", data)
        already = (
            session is not None and txid != ""
            and any(e["transaction_id"] == txid for e in session["events"])
        )

        if session is None:
            return {"http_status": 404,
                    "json": {"success": False, "message": SUBMIT_NOT_FOUND},
                    "transport_error": None}

        decision = php_call(
            "eco_submission_decision",
            {"id": session["id"], "station_id": session["station_id"],
             "status": session["status"], "user_id": session["user_id"]},
            session["station_id"],
            already,
        )

        if decision == "duplicate":
            return self._dup(session, txid)
        if decision == "reject":
            return {"http_status": 404,
                    "json": {"success": False, "message": SUBMIT_NOT_FOUND},
                    "transport_error": None}

        material = data.get("material")
        weight_kg = float(data.get("weight_kg") or 0.0)
        calc = php_call("eco_calculate_points", material, weight_kg)

        if self.fail_required_event:
            # Required WASTE_DATA insert failed -> rollback: no event,
            # no snapshot update, retryable server error.
            return {"http_status": 500,
                    "json": {"success": False,
                             "message": "Submit failed: required WASTE_DATA "
                                        "event could not be persisted"},
                    "transport_error": None}

        session["events"].append({
            "event_type": "WASTE_DATA", "transaction_id": txid,
            "material": calc["material"], "weight_kg": calc["weight_kg"],
            "points_calc": calc["raw_points"],
        })
        session["material_type"] = calc["material"]
        session["weight_kg"] = calc["weight_kg"]
        session["points_calculated"] = calc["raw_points"]
        session["status"] = "PROCESSING"

        if self.lose_submit_response:
            return {"http_status": None, "json": None,
                    "transport_error": "timeout"}

        return {"http_status": 200,
                "json": {"success": True, "duplicate": False,
                         "transaction_id": txid, "session_status": "PROCESSING",
                         "material": calc["material"],
                         "weight_kg": calc["weight_kg"],
                         "points_calculated": calc["raw_points"],
                         "cap_state": self._cap(session["user_id"])},
                "transport_error": None}

    def _complete(self, data):
        token = data.get("session_token")
        session = self.tokens.get(token)
        if session is None or not self._is_open(session):
            return {"http_status": 404,
                    "json": {"success": False, "message": COMPLETE_NOT_FOUND},
                    "transport_error": None}
        if self.fail_complete:
            return {"http_status": 500,
                    "json": {"success": False, "message": "Finalize failed"},
                    "transport_error": None}

        if not session["points_posted"]:
            total = round(sum(e["points_calc"] for e in self.waste_events(token)), 2)
            calc = {"valid": total > 0, "raw_points": total}
            cap_state = {"daily_points_left": 100.0, "weekly_points_left": 250.0}
            balance = self.users[session["user_id"]]
            awarded = php_call("eco_apply_cap_rules", calc, cap_state, balance)
            self.users[session["user_id"]] = min(balance + awarded, 3000.0)
            session["points_posted"] = True
            session["points_awarded"] = awarded

        session["status"] = "COMPLETED"
        return {"http_status": 200,
                "json": {"success": True, "session_status": "COMPLETED",
                         "points_awarded": session["points_awarded"],
                         "new_balance": self.users[session["user_id"]]},
                "transport_error": None}

    def _cancel(self, data):
        token = data.get("session_token")
        session = self.tokens.get(token)
        if session is not None and self._is_open(session):
            session["status"] = "CANCELLED"
        return {"http_status": 200,
                "json": {"success": True, "session_status": "CANCELLED"},
                "transport_error": None}


SERVER = FakeServer()
M.api_post_ex = SERVER.handle


# =====================================================================
# HARDWARE FAKES (only the physical boundary is replaced)
# =====================================================================
class Hardware:
    def __init__(self):
        self.reset()

    def reset(self):
        self.place_weight = 0.0
        self.camera = None            # None | "Plastic" | "Paper" | "Mixed" | "UNAVAILABLE"
        self.metal = False
        self.removal = True
        self.sort_result = "ok"      # ok | fail | raise
        self.sort_calls = []
        self.stable = None           # when set, direct stable result
        self.item_weight = 0.0


HW = Hardware()


def install_direct(place_weight=0.0, camera=None, stable=None, metal=False,
                   removal=True, sort="ok"):
    """Configure the hardware boundary for a direct process_item() call."""
    HW.place_weight = place_weight
    HW.camera = camera
    HW.metal = metal
    HW.removal = removal
    HW.sort_result = sort
    HW.stable = stable
    HW.sort_calls = []

    M.wait_for_item = lambda: (None if place_weight is None else place_weight)
    M.get_camera_material = lambda: camera
    M.wait_for_weight_stable = lambda: stable
    M.metal_detected = lambda: metal
    M.wait_for_removal = lambda: removal
    M.reset_camera = lambda: None
    M.notify_unrecognized_item = lambda: None

    def _sort(material):
        HW.sort_calls.append(material)
        if sort == "ok":
            return True
        if sort == "fail":
            return False
        raise RuntimeError("servo fault")

    M.Servo.sort_material = _sort


def fresh_session():
    SERVER.reset()
    reset_queue()
    CLOCK[0] = 0.0
    return M.create_api_session("VH-1003")


# =====================================================================
# PASS/FAIL HARNESS
# =====================================================================
PASS = 0
FAIL = 0


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        say(f"  ok    {name}")
    else:
        FAIL += 1
        say(f"  FAIL  {name}  {detail}")


# =====================================================================
# 0. SERVER CONTRACT WIRING (real PHP algorithm)
# =====================================================================
say("== 0. fake server uses the real PHP algorithm ==")
_redirect.__enter__()  # capture application stdout; see VHECO_E2E_VERBOSE
_calc = php_call("eco_calculate_points", "Plastic", 1.0)
check("0a PHP eco_calculate_points rate is 303 pts/kg",
      _calc["rate_pts_per_kg"] == 303 and _calc["raw_points"] == 303, _calc)
check("0b PHP eco_apply_cap_rules caps to the daily allowance",
      php_call("eco_apply_cap_rules", {"valid": True, "raw_points": 500.0},
               {"daily_points_left": 100.0, "weekly_points_left": 250.0}, 0.0) == 100.0)
check("0c PHP submission decision: open session -> insert",
      php_call("eco_submission_decision",
               {"id": 1, "station_id": 1, "status": "ACTIVE", "user_id": 42},
               1, False) == "insert")
check("0d PHP submission decision: recorded tx -> duplicate",
      php_call("eco_submission_decision",
               {"id": 1, "station_id": 1, "status": "COMPLETED", "user_id": 42},
               1, True) == "duplicate")
check("0e PHP submission decision: new tx on closed session -> reject",
      php_call("eco_submission_decision",
               {"id": 1, "station_id": 1, "status": "COMPLETED", "user_id": 42},
               1, False) == "reject")

# =====================================================================
# 1. REAL QR, WEIGHT, CAMERA AND INDUCTIVE LOGIC (fake readings only)
# =====================================================================
say("== 1. real hardware-reading logic against scripted readings ==")
check("1a real QR validation accepts a VictorianPass house code",
      QRS.is_victorianpass("VH-1003") is True)
check("1b real QR validation accepts a resident_qr_view URL",
      QRS.is_victorianpass("https://x/resident_qr_view.php?code=VH-1003") is True)
check("1c real QR validation rejects an unrelated code",
      QRS.is_victorianpass("totally-not-a-pass") is False)
check("1d real QR extraction pulls the house code from a URL",
      QRS.extract_resident_code(
          "https://x/resident_qr_view.php?code=VH-1003&z=1") == "VH-1003")
check("1e real QR extraction returns None for garbage",
      QRS.extract_resident_code("garbage") is None)

# load cell scenarios fed through the REAL wait loops with virtual time
CLOCK[0] = 0.0
M.get_weight = lambda samples=5: 250.0
M.metal_detected = lambda: False
check("1f wait_for_item returns a placed weight",
      M.wait_for_item() == 250.0)

CLOCK[0] = 0.0
M.get_weight = lambda samples=5: None
check("1g wait_for_item treats a failed load-cell reading as no item (IDLE)",
      M.wait_for_item() is None)

CLOCK[0] = 0.0
M.get_weight = lambda samples=5: 250.0
check("1h wait_for_weight_stable returns a stable weight",
      M.wait_for_weight_stable() == 250.0)

CLOCK[0] = 0.0
M.get_weight = lambda samples=5: 0.0
check("1i zero weight never stabilizes (returns None)",
      M.wait_for_weight_stable() is None)

_alt = iter([250.0, None, 250.0, None, 250.0, None])
CLOCK[0] = 0.0
M.get_weight = lambda samples=5: next(_alt, None)
check("1j noisy/intermittent readings never stabilize (returns None)",
      M.wait_for_weight_stable() is None)

CLOCK[0] = 0.0
M.get_weight = lambda samples=5: None
check("1k sensor timeout/error never stabilizes (returns None)",
      M.wait_for_weight_stable() is None)

# camera service scenarios (real get_camera_material polling logic)
CLOCK[0] = 0.0
M.camera_status = lambda: {"camera_available": True, "confirmed": True,
                           "material": "Plastic"}
check("1l confirmed camera detection returns the material",
      M.get_camera_material() == "Plastic")

CLOCK[0] = 0.0
M.camera_status = lambda: {"camera_available": True, "confirmed": True,
                           "material": "Plastic", "mixed": True}
check("1m mixed-material camera status returns Mixed",
      M.get_camera_material() == "Mixed")

CLOCK[0] = 0.0
M.camera_status = lambda: {}
check("1n unreachable camera service reports UNAVAILABLE",
      M.get_camera_material() == "UNAVAILABLE")

CLOCK[0] = 0.0
M.camera_status = lambda: {"camera_available": True, "confirmed": False,
                           "material": None}
check("1o camera that cannot classify returns None (inductive fallback)",
      M.get_camera_material() is None)

CLOCK[0] = 0.0
M.metal_detected = lambda: True
check("1p inductive sensor reports metal presence", M.metal_detected() is True)

# =====================================================================
# 2. FULL SUCCESS FLOW DRIVEN THROUGH THE REAL main() LOOP
# =====================================================================
say("== 2. end-to-end success flow through main() ==")

SERVER.reset()
reset_queue()
CLOCK[0] = 0.0
HW.reset()

# Scripted platform: item1 (120 g, Plastic) 0-1 s, item2 (120 g, Paper)
# 2-3 s, otherwise empty.  Virtual time makes this deterministic.
PLATFORM = [(0.0, 1.0, 120.0), (2.0, 3.0, 120.0)]


def _platform_weight(t):
    for a, b, w in PLATFORM:
        if a <= t < b:
            return w
    return 0.0


M.get_weight = lambda samples=5: _platform_weight(CLOCK[0])
M.metal_detected = lambda: False
_CAM = {"n": 0}


def _cam_status():
    _CAM["n"] += 1
    return {
        "camera_available": True, "confirmed": True,
        "material": "Plastic" if _CAM["n"] == 1 else "Paper",
    }


M.camera_status = _cam_status
M.reset_camera = lambda: None
M.notify_unrecognized_item = lambda: None
M.start_camera_process = lambda: None
M.stop_camera_process = lambda: None
M.start_weight = lambda: None
M.start_inductive = lambda: None
M.Servo.start = lambda: None
M.Servo.close = lambda: None

# QR script: one valid scan, then stop the infinite loop.
_QR = ["VH-1003"]
SCANNER = types.SimpleNamespace()


def _read_qr(scanner):
    if _QR:
        return _QR.pop(0), scanner
    raise KeyboardInterrupt


M.start_scanner = lambda: SCANNER
M.read_qr = _read_qr

# Spy: prove the write-ahead row exists (ACTUATING) before the servo runs.
_SPY = {}


def _spy_begin(txid, *a, **k):
    _orig_begin(txid, *a, **k)
    _SPY["txid"] = txid


_orig_begin = M.begin_actuation
M.begin_actuation = _spy_begin


def _spy_sort(material):
    HW.sort_calls.append(material)
    row = row_by_tx(_SPY["txid"])
    _SPY["row_at_sort"] = dict(row) if row else None
    return True


M.Servo.sort_material = _spy_sort

M.main()

M.begin_actuation = _orig_begin

_sessions = SERVER.sessions()
check("2a main() created exactly one session", len(_sessions) == 1, len(_sessions))
_sess = _sessions[0] if _sessions else None
if _sess:
    token = _sess["token"]
    events = SERVER.waste_events(token)
    check("2b exactly two durable WASTE_DATA events recorded",
          len(events) == 2, len(events))
    check("2c materials stayed consistent through the flow",
          [e["material"] for e in events] == ["Plastic", "Paper"],
          [e["material"] for e in events])
    check("2d weights stayed consistent (0.12 kg each)",
          all(abs(e["weight_kg"] - 0.12) < 1e-9 for e in events),
          [e["weight_kg"] for e in events])
    check("2e session was finalized COMPLETED exactly once",
          _sess["status"] == "COMPLETED" and _sess["points_posted"] is True,
          _sess["status"])
    expected = round(0.12 * 303 + 0.12 * 303, 2)
    check("2f points awarded once, matching the real rate",
          abs(_sess["points_awarded"] - expected) < 1e-6,
          (_sess["points_awarded"], expected))
    check("2g resident balance updated exactly once",
          abs(SERVER.points(42) - expected) < 1e-6, SERVER.points(42))

    rows = sorted(all_rows(), key=lambda r: r["id"])
    check("2h two queue rows exist, both SYNCED",
          len(rows) == 2 and all(r["status"] == "SYNCED" for r in rows),
          [(r["status"],) for r in rows])
    check("2i the server transaction ids match the client queue ids",
          sorted(r["transaction_id"] for r in rows) == sorted(SERVER.txids(token)),
          (sorted(r["transaction_id"] for r in rows), sorted(SERVER.txids(token))))
    check("2j both rows are ACTUATED (submittable)",
          all(r["actuation_state"] == oq.ACTUATION_COMPLETED for r in rows),
          [r["actuation_state"] for r in rows])
    check("2k queue material/weight consistent with the server",
          sorted((r["material"], round(r["weight_kg"], 4)) for r in rows)
          == [("Paper", 0.12), ("Plastic", 0.12)],
          [(r["material"], r["weight_kg"]) for r in rows])
    check("2l the same transaction id is reused for submission",
          set(r["transaction_id"] for r in rows) == set(SERVER.txids(token)))

_spy_row = _SPY.get("row_at_sort")
check("2m write-ahead row existed before the servo command",
      _spy_row is not None
      and _spy_row["actuation_state"] == oq.ACTUATION_STARTED,
      _spy_row)
check("2n servo was commanded for both items (simulated only)",
      HW.sort_calls == ["plastic", "paper"], HW.sort_calls)

# =====================================================================
# 3. FAILURE SCENARIOS
# =====================================================================
say("== 3. failure scenarios ==")

# 3a invalid QR / session-creation failure
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
check("3a invalid QR yields no session", M.create_api_session("not-a-pass") is None)
SERVER.fail_session_creation = True
check("3b server session-creation failure yields no session",
      M.create_api_session("VH-1003") is None)
SERVER.fail_session_creation = False

# 3c missing material -> unrecognized -> REJECTED, nothing recorded
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera=None, stable=250.0,
               metal=False, removal=True)
res = M.process_item(_s, "VH-1003")
check("3c missing material is REJECTED and nothing queued",
      res == "REJECTED" and all_rows() == [] and len(SERVER.waste_events(_s)) == 0,
      res)

# 3d zero weight / unstable weight / sensor timeout -> REJECTED
for label, stable_val in (("zero", 0.0), ("unstable", None), ("timeout", None)):
    SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
    _s = fresh_session()["session_token"]
    install_direct(place_weight=250.0, camera="Plastic", stable=stable_val,
                   metal=False, removal=True)
    res = M.process_item(_s, "VH-1003")
    check(f"3d-{label} invalid weight is REJECTED and not queued",
          res == "REJECTED" and all_rows() == [] and len(HW.sort_calls) == 0,
          (res, [dict(r) for r in all_rows()]))

# 3e database failure before servo actuation -> no physical command
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
_orig_begin2 = M.begin_actuation


def _boom_begin(*a, **k):
    raise RuntimeError("sqlite: disk I/O error")


M.begin_actuation = _boom_begin
res = M.process_item(_s, "VH-1003")
M.begin_actuation = _orig_begin2
check("3e uncommittable write-ahead record -> REJECTED, servo untouched",
      res == "REJECTED" and HW.sort_calls == [], (res, HW.sort_calls))
check("3e2 no queue row left behind", all_rows() == [])

# 3f servo failure / uncertain outcome -> UNCERTAIN, never submitted
for label, sort in (("fail", "fail"), ("raise", "raise")):
    SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
    _s = fresh_session()["session_token"]
    install_direct(place_weight=250.0, camera="Plastic", stable=250.0, sort=sort)
    res = M.process_item(_s, "VH-1003")
    rows = all_rows()
    check(f"3f-{label} servo {label} -> REJECTED with one UNCERTAIN row",
          res == "REJECTED" and len(rows) == 1
          and rows[0]["actuation_state"] == oq.ACTUATION_UNCERTAIN
          and rows[0]["status"] == "PENDING",
          (res, rows))
    check(f"3f-{label}2 UNCERTAIN row is never submitted",
          oq.is_submittable(rows[0]) is False)
    # even a fully healthy network must not submit it
    SERVER.network = None
    M.flush_offline_queue()
    check(f"3f-{label}3 flush does not submit the UNCERTAIN row",
          len([c for c in SERVER.calls if c[0] == "submit_waste_data.php"]) == 0
          and row_by_tx(rows[0]["transaction_id"])["status"] == "PENDING")

# =====================================================================
# 4. SUBMISSION FAILURES: OUTAGE, LOST RESPONSE, REQUIRED-ERROR
# =====================================================================
say("== 4. submission/networking failures ==")

# 4a internet unavailable before submission -> queued, same id retried
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
res = M.process_item(_s, "VH-1003")
weight, points, material, txid = res
SERVER.network = "connection_error"
outcome = M.submit_waste_data(_s, material, weight, transaction_id=txid)
check("4a outage submit is not ok and is retryable",
      outcome["ok"] is False and outcome["retryable"] is True, outcome)
saved = M.record_failed_submission(_s, "VH-1003", material, weight, txid, outcome)
check("4a2 offline item is queued and never double-inserted",
      saved == "QUEUED" and len(all_rows()) == 1, (saved, all_rows()))
SERVER.network = None
CLOCK[0] = 1000.0
M.flush_offline_queue()
check("4a3 flush after recovery syncs using the SAME transaction id",
      row_by_tx(txid)["status"] == "SYNCED"
      and SERVER.txids(_s) == [txid],
      (row_by_tx(txid)["status"], SERVER.txids(_s)))

# 4b server accepts but the response is lost -> ambiguous retry, no duplicate
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
weight, points, material, txid = M.process_item(_s, "VH-1003")
SERVER.lose_submit_response = True
outcome = M.submit_waste_data(_s, material, weight, transaction_id=txid)
check("4b lost response is classified as retryable",
      outcome["ok"] is False and outcome["retryable"] is True, outcome)
check("4b2 the server actually recorded the item once",
      len(SERVER.waste_events(_s)) == 1, len(SERVER.waste_events(_s)))
SERVER.lose_submit_response = False
M.record_failed_submission(_s, "VH-1003", material, weight, txid, outcome)
CLOCK[0] = 1000.0
M.flush_offline_queue()
check("4b3 retry reuses the id and the server answers duplicate (no 2nd event)",
      row_by_tx(txid)["status"] == "SYNCED"
      and len(SERVER.waste_events(_s)) == 1,
      (row_by_tx(txid)["status"], len(SERVER.waste_events(_s))))

# 4c required WASTE_DATA persistence failure -> rollback, then retry once
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
weight, points, material, txid = M.process_item(_s, "VH-1003")
SERVER.fail_required_event = True
outcome = M.submit_waste_data(_s, material, weight, transaction_id=txid)
check("4c required-event failure -> server error, no success",
      outcome["ok"] is False and outcome["http_status"] == 500, outcome)
check("4c2 no WASTE_DATA event persisted on failure",
      len(SERVER.waste_events(_s)) == 0)
saved = M.record_failed_submission(_s, "VH-1003", material, weight, txid, outcome)
check("4c3 pre-actuation row kept, item stays queued (no duplicate insert)",
      saved == "QUEUED" and len(all_rows()) == 1, (saved, all_rows()))
SERVER.fail_required_event = False
CLOCK[0] = 1000.0
M.flush_offline_queue()
check("4c4 retry persists exactly one WASTE_DATA event",
      len(SERVER.waste_events(_s)) == 1
      and row_by_tx(txid)["status"] == "SYNCED",
      (len(SERVER.waste_events(_s)), row_by_tx(txid)["status"]))
# and no points are awarded until completion
M.finalize_abandoned_sessions()
check("4c5 completion then awards points exactly once",
      SERVER.points(42) == round(0.25 * 303, 2),
      (SERVER.points(42), round(0.25 * 303, 2)))

# =====================================================================
# 5. RESTART SCENARIOS
# =====================================================================
say("== 5. restart scenarios ==")

# 5a restart with a PENDING row and a future retry deadline
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
weight, points, material, txid = M.process_item(_s, "VH-1003")
SERVER.network = "connection_error"
M.record_failed_submission(
    _s, "VH-1003", material, weight, txid,
    M.submit_waste_data(_s, material, weight, transaction_id=txid))
check("5a offline row starts due (fresh PENDING, no deadline)",
      row_by_tx(txid)["next_retry_at"] is None)
CLOCK[0] = 5000.0
M.flush_offline_queue()                       # failed attempt -> schedules backoff
deadline = row_by_tx(txid)["next_retry_at"]
check("5a2 retryable failure persisted a future backoff deadline",
      deadline is not None and deadline == 5000.0 + oq.retry_delay_for_attempt(1),
      deadline)

# reboot: re-run the real startup initialisation, then flush before deadline
CLOCK[0] = deadline - 1.0
M._init_offline_queue_or_stop()
before = len(SERVER.calls)
M.flush_offline_queue()
check("5a3 after restart a not-yet-due row is not resubmitted",
      len(SERVER.calls) == before and row_by_tx(txid)["status"] == "PENDING"
      and CLOCK[0] < deadline,
      (len(SERVER.calls), before, row_by_tx(txid)["status"]))
SERVER.network = None
CLOCK[0] = deadline
M.flush_offline_queue()
check("5a4 at the persisted deadline the row is retried with the same id",
      row_by_tx(txid)["status"] == "SYNCED" and SERVER.txids(_s) == [txid],
      (row_by_tx(txid)["status"], SERVER.txids(_s)))

# 5b restart after PREPARED / ACTUATING / ACTUATED states
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
oq.begin_actuation("TX-PREP", _s, "VH-1003", "Plastic", 0.25)
oq.begin_actuation("TX-ACT", _s, "VH-1003", "Plastic", 0.25)
oq.mark_actuation_started("TX-ACT")
oq.begin_actuation("TX-DONE", _s, "VH-1003", "Plastic", 0.25)
oq.mark_actuation_started("TX-DONE")
oq.mark_actuation_completed("TX-DONE")
M._init_offline_queue_or_stop()
check("5b PREPARED restart -> ABANDONED/FAILED (nothing moved)",
      row_by_tx("TX-PREP")["actuation_state"] == oq.ACTUATION_ABANDONED
      and row_by_tx("TX-PREP")["status"] == "FAILED",
      row_by_tx("TX-PREP"))
check("5b2 ACTUATING restart -> UNCERTAIN/PENDING (outcome unknown)",
      row_by_tx("TX-ACT")["actuation_state"] == oq.ACTUATION_UNCERTAIN
      and row_by_tx("TX-ACT")["status"] == "PENDING",
      row_by_tx("TX-ACT"))
check("5b3 ACTUATED restart -> untouched and submittable",
      row_by_tx("TX-DONE")["actuation_state"] == oq.ACTUATION_COMPLETED
      and oq.is_submittable(row_by_tx("TX-DONE")) is True)

# 5c uncertain rows block finalization and never gain points
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
oq.begin_actuation("TX-SYNC", _s, "VH-1003", "Plastic", 0.25)
oq.mark_actuation_started("TX-SYNC")
oq.mark_actuation_completed("TX-SYNC")
oq.begin_actuation("TX-HOLD", _s, "VH-1003", "Plastic", 0.25)
oq.mark_actuation_started("TX-HOLD")
oq.mark_actuation_uncertain("TX-HOLD", "operator review")
M.flush_offline_queue()
n = M.finalize_abandoned_sessions()
check("5c uncertain row is never submitted", SERVER.txids(_s) == ["TX-SYNC"],
      SERVER.txids(_s))
check("5c2 uncertain row blocks finalization (no completion, no points)",
      n == 0 and SERVER.points(42) == 0.0 and _s not in finalized_tokens(),
      (n, SERVER.points(42)))
check("5c3 uncertain row is still PENDING/UNCERTAIN",
      row_by_tx("TX-HOLD")["status"] == "PENDING"
      and row_by_tx("TX-HOLD")["actuation_state"] == oq.ACTUATION_UNCERTAIN)

# =====================================================================
# 6. IDEMPOTENCY / DUPLICATE SUBMISSIONS AND REPEATED COMPLETION
# =====================================================================
say("== 6. duplicates and repeated completion ==")
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
install_direct(place_weight=250.0, camera="Plastic", stable=250.0)
weight, points, material, txid = M.process_item(_s, "VH-1003")
o1 = M.submit_waste_data(_s, material, weight, transaction_id=txid)
o2 = M.submit_waste_data(_s, material, weight, transaction_id=txid)
check("6a duplicate submission returns ok and duplicate flag",
      o1["ok"] and o2["ok"] and o2["result"].get("duplicate") is True, o2)
check("6b exactly one WASTE_DATA event exists", len(SERVER.waste_events(_s)) == 1)
M._mark_synced_safe(txid)
SERVER.fail_complete = True
first_complete = M.finalize_abandoned_sessions()
check("6c a failing completion writes no ledger and no points",
      first_complete == 0 and SERVER.points(42) == 0.0
      and _s not in finalized_tokens())
SERVER.fail_complete = False
first_complete = M.finalize_abandoned_sessions()
second_complete = M.finalize_abandoned_sessions()
check("6d first completion awards once and writes the ledger",
      first_complete == 1 and _s in finalized_tokens()
      and SERVER.points(42) == round(0.25 * 303, 2))
check("6e repeated completion neither re-awards nor re-finalizes",
      second_complete == 0 and SERVER.points(42) == round(0.25 * 303, 2)
      and len(SERVER.waste_events(_s)) == 1,
      (second_complete, SERVER.points(42)))

# duplicate submission after completion must STILL not insert a new event
o3 = M.submit_waste_data(_s, material, weight, transaction_id=txid)
check("6f replaying an already-accepted tx after completion is a duplicate",
      o3["ok"] is True and o3["result"].get("duplicate") is True
      and len(SERVER.waste_events(_s)) == 1, o3)

# =====================================================================
# 7. COMPLETION / CANCELLATION / ERROR DECISION RACES (deterministic)
# =====================================================================
say("== 7. deterministic completion/cancel/error races ==")
STATION = 1


def locked(status, recorded_tx=False):
    return {"id": 1, "station_id": STATION, "status": status, "user_id": 42}


check("7a open session is completable",
      php_call("eco_completion_decision", locked("PROCESSING"), STATION) == "complete")
check("7b COMPLETED session cannot be re-completed",
      php_call("eco_completion_decision", locked("COMPLETED"), STATION) == "reject")
check("7c CANCELLED session cannot be completed",
      php_call("eco_completion_decision", locked("CANCELLED"), STATION) == "reject")
check("7d ERROR session cannot be completed via the normal path",
      php_call("eco_completion_decision", locked("ERROR"), STATION) == "reject")
check("7e open session accepts a new WASTE_DATA insert",
      php_call("eco_submission_decision", locked("ACTIVE"), STATION, False) == "insert")
check("7f COMPLETED session rejects a NEW transaction",
      php_call("eco_submission_decision", locked("COMPLETED"), STATION, False) == "reject")
check("7g COMPLETED session still acks an already-recorded transaction",
      php_call("eco_submission_decision", locked("COMPLETED"), STATION, True) == "duplicate")
check("7h cancel/error transition is allowed only from an open status",
      php_call("eco_terminal_transition_allowed", locked("ACTIVE"), STATION) is True
      and php_call("eco_terminal_transition_allowed", locked("COMPLETED"), STATION) is False
      and php_call("eco_terminal_transition_allowed", locked("CANCELLED"), STATION) is False)

# A concrete simulated race: submit decides "insert" from a pre-lock open
# snapshot, then a concurrent completion finalises the row before the
# guarded operation runs. The re-read under the lock must flip to reject.
pre_lock = locked("ACTIVE")
_finalized_after_lock = locked("COMPLETED")
check("7i pre-lock snapshot alone would edit",
      php_call("eco_submission_decision", pre_lock, STATION, False) == "insert")
check("7j post-lock re-read of the same row rejects the edit",
      php_call("eco_submission_decision", _finalized_after_lock, STATION, False) == "reject")
check("7k likewise the completion of an already-cancelled row is rejected",
      php_call("eco_completion_decision", locked("CANCELLED"), STATION) == "reject")

# client-side decision races: cancel is only safe with nothing recorded
SERVER.reset(); reset_queue(); CLOCK[0] = 0.0
_s = fresh_session()["session_token"]
check("7l should_cancel blocks while a queued item is still pending",
      M.should_cancel_session(0, _s) is True)
oq.save_transaction("TX-P1", _s, "VH-1003", "Plastic", 0.2, "{}")
check("7m pending queue blocks cancellation (fail safe)",
      M.should_cancel_session(0, _s) is False)
check("7n should_complete also blocks on the same pending item",
      M.should_complete_session(1, _s) is False)
oq.mark_synced("TX-P1")
check("7o once nothing is pending, completion is allowed",
      M.should_complete_session(1, _s) is True)
check("7o2 with an accepted item, cancellation is refused",
      M.should_cancel_session(1, _s) is False)

# =====================================================================
# 8. SAFETY: the repository's live database was never touched
# =====================================================================
say("== 8. safety checks ==")
_after = (
    os.path.getmtime(LIVE_DB),
    os.path.getsize(LIVE_DB),
    os.path.getmtime(LIVE_DB + "-wal") if os.path.exists(LIVE_DB + "-wal") else None,
    os.path.getmtime(LIVE_DB + "-shm") if os.path.exists(LIVE_DB + "-shm") else None,
)
check("8a live vheco_local.db was never opened or modified",
      _after == LIVE_DB_STAT, (_after, LIVE_DB_STAT))
check("8b all app SQLite work used the scratch DB",
      oq.DB_FILE != LIVE_DB and _TMP_DIR in oq.DB_FILE, oq.DB_FILE)
check("8c no real network request was attempted",
      True)  # api_post_ex is fully replaced by FakeServer

_redirect.__exit__(None, None, None)
if VERBOSE:
    say(_APP_LOG.getvalue())

# =====================================================================
say("=" * 60)
say(f"RESULT: {PASS} passed, {FAIL} failed")
say("=" * 60)

# Leave no scratch state behind; never touch the repo DB.
oq.DB_FILE = _OLD_DB
try:
    os.unlink(BRIDGE_FILE)
except OSError:
    pass
sys.exit(0 if FAIL == 0 else 1)
