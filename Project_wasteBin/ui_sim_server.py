#!/usr/bin/env python3
"""VHEcoPoint — opt-in desktop UI SIMULATION MODE.

A dependency-free local server that reproduces the VHEcoPoint resident
dashboard data-flow with REAL application code behind it:

  * the REAL js/ecopoint_dashboard.js bundle is served unchanged and is
    the exact same file the production profileresident.php loads;
  * the KPI card markup (ecopoint-current-balance, ecopoint-weekly-points,
    ecopoint-daily-pts-today, ecopoint-sessions-remaining, live panel,
    Weekly Material Cap Tracker, Points History) mirrors profileresident.php
    verbatim, so the browser-side data-binding runs through the same ids;
  * every POINT / CAP calculation is delegated to the REAL PHP business
    logic in ecopoint_core.php / ecopoint_idempotency.php through a tiny
    CLI bridge (eco_calculate_points, eco_apply_cap_rules,
    eco_week_bounds, eco_submission_decision, eco_completion_decision,
    eco_terminal_transition_allowed, eco_extract_transaction_id);
  * the aggregation loops used to render the dashboard (cap state,
    weekly material tracker, points history, current balance) mirror
    ecopoint_core.php eco_resident_cap_state() and profileresident.php
    exactly, including point_transactions.amount being INT (each awarded
    fraction is rounded the same way MySQL rounds INT insertion).

ISOLATION / SAFETY
  * Everything lives in-memory in SIMStore.  Nothing is ever written to
    disk (no vheco_local.db, no offline_queue.db, no SQLite file).
  * The mock API listens only on 127.0.0.1.  No outbound network calls
    are ever made; the real Hostinger API is never contacted.
  * The dedicated test code SIM-VHECO-TEST-001 maps to a synthetic
    simulation resident (id 9001).  No real VictorianPass resident QR is
    used or accepted.
  * Not loaded by Main.py / offline_queue.py.  SIMULATION MODE is only
    active while this script is explicitly run; production behaviour is
    unchanged when it is not running.

The intended use is a supervisor-opening-the-desktop demo:

    python3 Project_wasteBin/ui_sim_server.py [--port N] [--host H]

    open http://127.0.0.1:<port>/              station console
    open http://127.0.0.1:<port>/dashboard     resident dashboard
    open http://127.0.0.1:<port>/admin         admin dashboard (simulated)

The /admin view is an ADMIN-SIMULATION harness that mirrors
admin_ecopoint.php (KPIs, station grid, live sessions, recent-sessions
history, session-detail modal with waste breakdown + event audit). It is
served from the SAME in-memory SIMStore as the resident dashboard, so the
admin side sees the same simulated transactions, and it live-polls
/admin/api (no full browser reload). It is clearly labelled as simulated;
the real admin_ecopoint.php (PHP + mysqli + staff auth) is never executed.

Because this sandbox's browsers cannot reach loopback HTTP, the
accompanying ui_sim_verify.py runs the scenario headlessly over HTTP and
validates the exact JS/DOM contract the bundle consumes.
"""

import hashlib
import json
import os
import pathlib
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import urllib.parse
import uuid
from datetime import datetime, timedelta
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
REAL_JS_PATH = REPO_ROOT / "js" / "ecopoint_dashboard.js"

TEST_QR = "SIM-VHECO-TEST-001"
SIM_USER_ID = 9001
SIM_RESIDENT_NAME = "SIMULATION USER"
SIM_HOUSE = "SIM-9001"
SIM_RESIDENT_EMAIL = "simulation.user@victorianpass.local"
SIM_API_KEY_LAST4 = "e_me"  # matches the VH-ECO-001 schema seed
STATION_ID = 1
STATION_CODE = "VH-ECO-001"
STATION_NAME = "VHEcoPoint Station"
SIM_TIMEZONE_NOTE = "Simulated local time (server clock)"

# Mirrors ECO_SESSION_STATUSES_OPEN / terminal sets used by the admin page.
OPEN_STATUSES = ("WAITING", "ACTIVE", "PROCESSING")
TERMINAL_STATUSES = ("COMPLETED", "CANCELLED", "ERROR")


# =====================================================================
# PHP CLI bridge — call the REAL production business functions
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
$tmp = tempnam(sys_get_temp_dir(), 'vh_uisim_core_');
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
        case 'eco_week_bounds':                 $r = eco_week_bounds(...$args); break;
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
BRIDGE_FILE = os.path.join(tempfile.gettempdir(), "vh_uisim_bridge.php")
if not os.path.exists(BRIDGE_FILE):
    with open(BRIDGE_FILE, "w") as _bf:
        _bf.write(BRIDGE_PHP)

_PHP_CACHE = {}


def php_call(fn, *args):
    """Invoke one REAL PHP business function via the CLI bridge."""
    if PHP_BIN is None:
        raise RuntimeError("php CLI not found; the simulation cannot run the real point logic")
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
        timeout=20,
    )
    if proc.returncode != 0:
        raise RuntimeError("php bridge %s exited %d: %s" % (fn, proc.returncode, (proc.stderr or "").strip()))
    out = json.loads(proc.stdout.strip())
    if not out.get("ok"):
        raise RuntimeError("php bridge %s failed: %s" % (fn, out.get("error")))
    _PHP_CACHE[key] = out["result"]
    return _PHP_CACHE[key]


# =====================================================================
# Python mirrors of the production render/aggregation helpers
# (kept byte-for-byte faithful to the PHP sources referenced above)
# =====================================================================
def material_label(material_type: str) -> str:
    """Mirrors residentEcoPointMaterialLabel() in profileresident.php."""
    s = (material_type or "").strip().lower()
    if "plastic" in s or "pet" in s:
        return "Plastic Bottles"
    if "aluminum" in s or "aluminium" in s or s.split() and ("can" in s):
        return "Aluminum Cans"
    if "cardboard" in s or "paper" in s:
        return "Paper (Old Documents)"
    return "Other"


def sse_material_label(raw: str) -> str:
    """Mirrors eco_material_display_label() in api/ecopoint_sse.php."""
    hay = raw.strip().lower()
    if "plastic" in hay or "pet" in hay:
        return "Plastic (PET)"
    if "aluminum" in hay or "aluminium" in hay or "can" in hay:
        return "Aluminum Cans"
    if "cardboard" in hay or "paper" in hay:
        return "Paper & Cardboard"
    return raw if raw and raw != "-" else "-"


def normalize_sql_datetime(dt: datetime) -> str:
    return dt.strftime("%Y-%m-%d %H:%M:%S")


def int_mysql(value) -> int:
    """Mirror MySQL INT column coercion of a DECIMAL double:
    cast rounds to nearest integer (36.36 -> 36, 27.28 -> 27)."""
    from decimal import Decimal, ROUND_HALF_UP
    return int(Decimal(str(value)).quantize(Decimal("1"), rounding=ROUND_HALF_UP))


# =====================================================================
# Isolated simulation store
# =====================================================================
class SIMStore:
    """In-memory stand-in for MySQL ecopoint_* tables. Never on disk."""

    def __init__(self, clock=None):
        self.clock = clock or datetime.now
        self.reset()

    def reset(self):
        self.transactions = []      # point_transactions rows
        self.sessions = {}          # token -> session dict
        self.next_session_id = 1000
        self.next_tx_id = 1
        self.next_event_id = 1
        # users.points equivalent (LEAST(users.points + amount, 3000))
        self.user_points = {}
        # station registry equivalent (ecopoint_stations row for VH-ECO-001)
        self.station_created_at = normalize_sql_datetime(self.clock())
        self.station_heartbeat_at = normalize_sql_datetime(self.clock())

    def now(self) -> datetime:
        return self.clock()

    def _heartbeat(self):
        """Station activity implies a fresh heartbeat (mirrors the hardware
        heartbeat endpoint updating ecopoint_stations.last_heartbeat_at)."""
        self.station_heartbeat_at = normalize_sql_datetime(self.now())

    # -- PHP week window -------------------------------------------------
    def _week_bounds(self, dt: datetime):
        w = php_call("eco_week_bounds", normalize_sql_datetime(dt))
        return w

    # -- cap state (mirror eco_resident_cap_state) ----------------------
    def cap_state(self, user_id: int, dt=None):
        dt = dt or self.now()
        today = dt.strftime("%Y-%m-%d")
        week = self._week_bounds(dt)
        w_start = week["week_start_mysql"]
        w_end = week["week_end_mysql"]

        daily_pts = 0.0
        daily_sess = 0
        weekly_pts = 0.0
        for tx in self.transactions:
            if tx["user_id"] != user_id:
                continue
            if not (w_start <= tx["created_at"] <= w_end):
                continue
            if tx["transaction_type"] != "earn":
                continue
            amt = float(tx["amount"])
            weekly_pts += amt
            if tx["created_at"][:10] == today:
                daily_pts += amt
                daily_sess += 1

        completed_today = 0
        for s in self.sessions.values():
            if (
                s["user_id"] == user_id
                and s["status"] == "COMPLETED"
                and s["completed_at"]
                and s["completed_at"][:10] == today
            ):
                completed_today += 1
        daily_sess = max(daily_sess, completed_today)

        return {
            "today": today,
            "week_start": week["week_start_date"],
            "week_end": week["week_end_date"],
            "daily_points_used": daily_pts,
            "daily_points_left": max(0, 100 - daily_pts),
            "daily_sessions_used": daily_sess,
            "daily_sessions_left": max(0, 3 - daily_sess),
            "weekly_points_used": weekly_pts,
            "weekly_points_left": max(0, 250 - weekly_pts),
        }

    # -- ledger balance (mirror eco_ledger_balance) ----------------------
    def ledger_balance(self, user_id: int) -> float:
        bal = 0.0
        for tx in self.transactions:
            if tx["user_id"] != user_id:
                continue
            t = tx["transaction_type"]
            if t == "earn":
                bal += float(tx["amount"])
            elif t == "redeem":
                bal -= float(tx["amount"])
            elif t == "adjustment":
                bal += float(tx["amount"])
        return max(0.0, bal)

    # -- dashboard current points (mirror profileresident intval sums) --
    def current_points(self, user_id: int) -> int:
        pts = 0
        for tx in self.transactions:
            if tx["user_id"] != user_id:
                continue
            t = tx["transaction_type"]
            amt = int(tx["amount"])  # PHP intval($tx['amount'])
            if t == "earn":
                pts += amt
            elif t == "redeem":
                pts -= amt
            elif t == "adjustment":
                pts += amt
        return max(0, pts)

    # -- dashboard aggregation (mirror profileresident.php loops) -------
    def dashboard_aggregates(self, user_id: int):
        dt = self.now()
        today = dt.strftime("%Y-%m-%d")
        week = self._week_bounds(dt)
        w_start = week["week_start_date"]
        w_end = week["week_end_date"]

        weekly_stats = {
            "Plastic Bottles": {"points": 0, "weight": 0.0},
            "Aluminum Cans": {"points": 0, "weight": 0.0},
            "Paper (Old Documents)": {"points": 0, "weight": 0.0},
        }
        weekly_points = 0
        today_points = 0
        all_history = []

        for tx in self.transactions:
            if tx["user_id"] != user_id or tx["transaction_type"] != "earn":
                continue
            created_at = tx["created_at"]
            created_ts = datetime.strptime(created_at, "%Y-%m-%d %H:%M:%S")
            created_date = created_at[:10]
            label = material_label(tx.get("material_type", ""))
            weight_kg = float(tx.get("weight_kg") or 0)
            points_earned = int(tx["amount"])

            all_history.append({
                "type": "earn",
                "amount": points_earned,
                "date_ts": int(created_ts.timestamp()),
                "date_label": created_ts.strftime("%b %d, %Y"),
                "time_label": created_ts.strftime("%-I:%M %p"),
                "description": tx.get("description", ""),
                "material_label": label,
                "weight_kg": weight_kg,
            })

            if w_start <= created_date <= w_end:
                weekly_points += points_earned
                if label in weekly_stats:
                    weekly_stats[label]["points"] += points_earned
                    weekly_stats[label]["weight"] += weight_kg
            if created_date == today:
                today_points += points_earned

        today_sessions_used = 0
        for s in self.sessions.values():
            if (
                s["user_id"] == user_id
                and s["status"] == "COMPLETED"
                and s["completed_at"]
                and s["completed_at"][:10] == today
            ):
                today_sessions_used += 1

        all_history.sort(key=lambda h: h["date_ts"], reverse=True)

        return {
            "current_points": self.current_points(user_id),
            "weekly_points": weekly_points,
            "weekly_values": weekly_stats,
            "today_points": today_points,
            "today_sessions_used": today_sessions_used,
            "history": all_history,
            "weekly_remaining": max(0, 250 - weekly_points),
            "weekly_progress": min(100, round((weekly_points / 250.0) * 100)) if weekly_points else 0,
            "daily_remaining": max(0, 100 - today_points),
            "sessions_remaining": max(0, 3 - today_sessions_used),
        }

    # -- session helpers -------------------------------------------------
    def _session_row(self, token):
        s = self.sessions.get(token)
        return dict(s) if s else None

    def _add_event(self, session, etype, payload, source):
        ev = {
            "id": self.next_event_id,
            "event_type": etype,
            "event_payload": payload,
            "source": source,
            "created_at": normalize_sql_datetime(self.now()),
        }
        self.next_event_id += 1
        session["events"].append(ev)
        return ev

    # -- QR verify (mirror api/qr_verify_and_create_session.php) --------
    def verify_qr(self, qr_code: str):
        qr = (qr_code or "").strip()
        if qr != TEST_QR:
            return {"http_status": 404, "json": {
                "success": False, "message": "Resident not found"}, }
        self._heartbeat()
        cap = self.cap_state(SIM_USER_ID)
        if cap["daily_sessions_left"] <= 0:
            return {"http_status": 403, "json": {
                "success": False,
                "message": "Daily session limit reached (max %d per day)" % 3,
                "cap": cap}, }
        active = self._active_for_user(SIM_USER_ID)
        if active is not None:
            return {"http_status": 200, "json": {
                "success": True,
                "session_token": active["session_token"],
                "session": {"id": active["id"]},
                "resident": {"full_name": SIM_RESIDENT_NAME,
                             "balance": self.ledger_balance(SIM_USER_ID)},
                "cap_state": cap}, }

        now = self.now()
        token = uuid.uuid4().hex
        session = {
            "id": self.next_session_id,
            "session_token": token,
            "station_id": STATION_ID,
            "user_id": SIM_USER_ID,
            "qr_ref_code": qr,
            "status": "ACTIVE",
            "material_type": "",
            "weight_kg": 0.0,
            "points_calculated": 0.0,
            "points_awarded": 0,
            "points_posted": False,
            "created_at": normalize_sql_datetime(now),
            "updated_at": normalize_sql_datetime(now),
            "waiting_at": normalize_sql_datetime(now),
            "started_at": normalize_sql_datetime(now),
            "completed_at": None,
            "cancelled_at": None,
            "error_at": None,
            "error_message": None,
            "total_weight_kg": 0.0,
            "total_points": 0,
            "posted_transaction_id": None,
            "events": [],
            "items": [],
        }
        self.next_session_id += 1
        self.sessions[token] = session
        self._add_event(session, "QR_VERIFIED", {
            "qr_ref_code": qr, "user_id": SIM_USER_ID,
            "resident_name": SIM_RESIDENT_NAME}, "BACKEND")
        self._add_event(session, "SESSION_CREATED", {
            "station_code": STATION_CODE, "cap_state": cap}, "BACKEND")
        return {"http_status": 200, "json": {
            "success": True,
            "session_token": token,
            "session": {"id": session["id"]},
            "resident": {"full_name": SIM_RESIDENT_NAME,
                         "balance": self.ledger_balance(SIM_USER_ID)},
            "cap_state": cap}, }

    # -- submit (mirror api/submit_waste_data.php + idempotency) --------
    def submit_item(self, data: dict):
        token = (data.get("session_token") or "").strip()
        material = (data.get("material") or "").strip()
        cap_material = {"plastic": "Plastic", "paper": "Paper",
                        "aluminum": "Aluminum", "cardboard": "Cardboard"}
        material = cap_material.get(material.lower(), material)
        try:
            weight_kg = float(data.get("weight_kg") or 0)
        except (TypeError, ValueError):
            weight_kg = 0.0

        session = self.sessions.get(token)
        if session is None:
            return {"http_status": 404, "json": {"success": False,
                    "message": "Session not found or no longer active"}}
        self._heartbeat()
        txid = php_call("eco_extract_transaction_id", data)
        already = txid != "" and any(
            self._event_txid(e) == txid
            for e in session["events"] if e["event_type"] == "WASTE_DATA"
        )

        decision = php_call(
            "eco_submission_decision",
            {"id": session["id"], "station_id": session["station_id"],
             "status": session["status"], "user_id": session["user_id"]},
            STATION_ID, already,
        )
        if decision == "duplicate":
            return {"http_status": 200, "json": {
                "success": True, "duplicate": True, "transaction_id": txid,
                "session_status": session["status"],
                "note": "Transaction already recorded (idempotent replay)."}}
        if decision == "reject":
            return {"http_status": 404, "json": {"success": False,
                    "message": "Session not found or no longer active"}}

        calc = php_call("eco_calculate_points", material, weight_kg)
        if not calc["valid"]:
            return {"http_status": 400, "json": {"success": False,
                    "message": "Unknown material. Allowed: Plastic, Aluminum, Paper, Cardboard"}}

        now = self.now()
        event = self._add_event(session, "WASTE_DATA", {
            "material": calc["material"],
            "weight_kg": calc["weight_kg"],
            "points_calc": calc["raw_points"],
            "rate_pts_per_kg": calc["rate_pts_per_kg"],
            "raw": {"transaction_id": txid},
            "transaction_id": txid,
        }, "HARDWARE")

        session["items"].append({
            "id": event["id"],
            "waste_type": calc["material"],
            "material_label": material_label(calc["material"]),
            "weight_kg": calc["weight_kg"],
            "rate_pts_per_kg": calc["rate_pts_per_kg"],
            "points_calculated": calc["raw_points"],
            "points_awarded": 0,
            "created_at": normalize_sql_datetime(now),
        })
        session["material_type"] = calc["material"]
        session["weight_kg"] = calc["weight_kg"]
        session["points_calculated"] = calc["raw_points"]
        session["status"] = "PROCESSING"
        session["updated_at"] = normalize_sql_datetime(now)

        return {"http_status": 200, "json": {
            "success": True, "duplicate": False,
            "transaction_id": txid, "session_status": "PROCESSING",
            "material": calc["material"], "weight_kg": calc["weight_kg"],
            "points_calculated": calc["raw_points"],
            "event_id": event["id"],
            "cap_state": self.cap_state(session["user_id"])}, }

    # -- shared finalize (mirror eco_award_points_and_finalize) ----------
    def _finalize_session(self, session):
        """Award the session's recorded items ONCE and mark it COMPLETED.

        Mirrors ecopoint_core.php eco_award_points_and_finalize():
          * points are recomputed from the recorded WASTE_DATA items (the
            single source of truth), never trusted from the client;
          * the raw total is passed through the REAL PHP cap rules
            (eco_apply_cap_rules) and coerced like the INT ledger column;
          * exactly one point_transactions row is posted (guarded by
            points_posted, so a duplicate/replay cannot double-award);
          * the session ends COMPLETED with completed_at set, so it is
            counted as one consumed visit exactly once.

        Shared by normal completion and resident early-end so BOTH paths
        produce identical ledger + counter state.
        """
        now = self.now()
        tx_id = None
        if not session["points_posted"]:
            total = sum(i["points_calculated"] for i in session["items"])
            total_w = sum(i["weight_kg"] for i in session["items"])
            calc = {"raw_points": round(total, 2), "valid": total > 0,
                    "material": session["material_type"]}
            cap = self.cap_state(SIM_USER_ID)
            bal = self.ledger_balance(SIM_USER_ID)
            raw_awarded = php_call("eco_apply_cap_rules", calc, cap, bal)
            awarded = int_mysql(raw_awarded)          # INT column coercion
            tx_id = None
            if awarded > 0:
                self.transactions.append({
                    "id": self.next_tx_id,
                    "user_id": SIM_USER_ID,
                    "transaction_type": "earn",
                    "amount": awarded,
                    "description": "VHEcoPoint recycling: %s (%.2f kg) @ %d pts/kg"
                                   % (calc["material"], total_w,
                                      php_call("eco_calculate_points",
                                               calc["material"] or "Plastic",
                                               total_w or 0.0).get("rate_pts_per_kg", 0)),
                    "material_type": calc["material"],
                    "weight_kg": total_w,
                    "ecopoint_session_id": session["id"],
                    "created_at": normalize_sql_datetime(now),
                })
                tx_id = self.next_tx_id
                self.next_tx_id += 1
                self.user_points[SIM_USER_ID] = min(
                    (self.user_points.get(SIM_USER_ID, 0) or 0) + awarded, 3000)
                session["posted_transaction_id"] = tx_id
            self._add_event(session, "POINTS_POSTED", {
                "calculated": round(total, 2),
                "awarded": int(awarded),
                "cap_state": cap,
                "transaction_id": tx_id}, "BACKEND")
            session["points_posted"] = True
            session["points_awarded"] = awarded
            session["points_calculated"] = round(total, 2)
            session["weight_kg"] = round(total_w, 3)
            session["total_weight_kg"] = round(total_w, 3)
            session["total_points"] = int(awarded)

        session["status"] = "COMPLETED"
        session["completed_at"] = normalize_sql_datetime(now)
        session["updated_at"] = normalize_sql_datetime(now)
        self._add_event(session, "SESSION_COMPLETED", {
            "points_awarded": int(session["points_awarded"] or 0)}, "BACKEND")
        return int(session["points_awarded"] or 0)

    # -- complete (mirror api/complete_session.php + award path) ---------
    def complete_session(self, token: str):
        session = self.sessions.get(token)
        if session is None:
            return {"http_status": 404, "json": {"success": False,
                    "message": "Session not found or already ended"}}
        decision = php_call(
            "eco_completion_decision",
            {"id": session["id"], "station_id": session["station_id"],
             "status": session["status"], "user_id": session["user_id"]},
            STATION_ID,
        )
        if decision != "complete":
            return {"http_status": 404, "json": {"success": False,
                    "message": "Session not found or already ended"}}

        self._heartbeat()
        awarded = self._finalize_session(session)
        return {"http_status": 200, "json": {
            "success": True, "session_status": "COMPLETED",
            "points_awarded": awarded,
            "new_balance": self.ledger_balance(SIM_USER_ID),
            "cap_state_after": self.cap_state(SIM_USER_ID)}, }

    # -- resident early end (mirror api/ecopoint_end_session.php) ---------
    def end_session(self, user_id: int = SIM_USER_ID):
        """Resident 'End Session Early'.

        Production finalizes whatever waste was recorded up to now and marks
        the session COMPLETED (api/ecopoint_end_session.php ->
        eco_award_points_and_finalize), so a valid visit that is ended early
        consumes exactly one daily session. The same finalize path is used
        here, so the ledger, material tracker, points history and session
        counter all agree with a normal completion.
        """
        active = self._active_for_user(user_id)
        if active is None:
            return {"http_status": 400, "json": {
                "success": False, "message": "No active session to end"}}
        if active["status"] not in ("WAITING", "ACTIVE", "PROCESSING"):
            return {"http_status": 409, "json": {
                "success": False,
                "message": "Session is not in a running state (%s)." % active["status"]}}

        self._heartbeat()
        awarded = self._finalize_session(active)
        return {"http_status": 200, "json": {
            "success": True,
            "message": "Session ended successfully",
            "session_status": "COMPLETED",
            "awarded_points": float(awarded),
            "new_balance": self.ledger_balance(SIM_USER_ID),
            "cap_state_after": self.cap_state(SIM_USER_ID),
            "max_balance": 3000.0,
            "points_limit_reached": False,
            "points_limit_message": ""}, }

    # -- active session for a user ---------------------------------------
    def _active_for_user(self, user_id):
        for s in self.sessions.values():
            if s["user_id"] == user_id and s["status"] in ("WAITING", "ACTIVE", "PROCESSING"):
                return s
        return None

    def _event_txid(self, event):
        p = event.get("event_payload") or {}
        p_raw = p.get("raw") or {}
        return p.get("transaction_id") or p_raw.get("transaction_id") or ""

    # -- snapshot for SSE / poll (mirror eco_build_snapshot) -------------
    def snapshot(self, user_id: int):
        now = self.now()
        active = self._active_for_user(user_id)
        active_out = None
        if active:
            total_points = 0.0
            total_weight = 0.0
            for e in active["events"]:
                if e["event_type"] == "WASTE_DATA":
                    total_points += float((e["event_payload"] or {}).get("points_calc", 0))
                    total_weight += float((e["event_payload"] or {}).get("weight_kg", 0))
            # Projected eligible points: the raw accumulated total run through
            # the REAL PHP cap rules (same as _finalize_session), so the live
            # panel mirrors exactly what finalization would award.
            estimated_points = 0.0
            if total_points > 0:
                projected = php_call("eco_apply_cap_rules", {
                    "raw_points": round(total_points, 2),
                    "valid": True,
                    "material": active["material_type"] or "Plastic",
                }, self.cap_state(active["user_id"]),
                   self.ledger_balance(active["user_id"]))
                estimated_points = float(projected)
            events = []
            for e in reversed(active["events"][-12:]):
                events.append({
                    "event_type": e["event_type"],
                    "payload": e["event_payload"],
                    "source": e["source"],
                    "created_at": e["created_at"],
                })
            items = []
            for i in active["items"]:
                items.append(dict(i))
            active_out = {
                "id": active["id"],
                "session_token_safe": active["session_token"][:8] + "\u2026",
                "status": active["status"],
                "material": sse_material_label(active["material_type"]),
                "weight_kg": float(active["weight_kg"] or 0),
                "points_calculated": float(active["points_calculated"] or 0),
                "points_awarded": float(active["points_awarded"] or 0),
                "points_raw": round(total_points, 2),
                "estimated_points": round(estimated_points, 2),
                "total_weight_kg": round(total_weight, 3),
                "total_points": round(total_points, 2),
                "created_at": active["created_at"],
                "started_at": active.get("started_at") or active["created_at"],
                "completed_at": active["completed_at"],
                "cancelled_at": active["cancelled_at"],
                "error_at": active["error_at"],
                "error_message": active["error_message"],
                "station": {
                    "code": STATION_CODE, "name": STATION_NAME,
                    "location": "SIMULATION MODE (local)",
                },
                "recent_events": events,
                "waste_items": items,
            }

        history = []
        rows = [s for s in self.sessions.values()
                if s["user_id"] == user_id and s["status"] in ("COMPLETED", "CANCELLED", "ERROR")]
        rows.sort(key=lambda s: s["id"], reverse=True)
        for r in rows[:5]:
            history.append({
                "id": r["id"],
                "status": r["status"],
                "material": sse_material_label(r["material_type"]),
                "weight_kg": float(r["weight_kg"] or 0),
                "points_awarded": float(r["points_awarded"] or 0),
                "created_at": r["created_at"],
                "completed_at": r["completed_at"],
                "station_name": STATION_NAME,
            })

        bal = self.ledger_balance(SIM_USER_ID)
        return {
            "success": True,
            "user_id": user_id,
            "ts": time.time(),
            "polled_at": normalize_sql_datetime(now),
            "current_balance": bal,
            "cap_state": self.cap_state(user_id),
            # Consolidated dashboard aggregate block. This is the single
            # source both the KPI cards, the weekly tracker and the points
            # history render from, so the three can never disagree.
            "dashboard": self.dashboard_aggregates(user_id),
            "active_session": active_out,
            "recent_sessions": history,
            "points_limit_reached": bal >= 3000,
            "points_limit_message": "Balance already at / above the 3,000-point cap." if bal >= 3000 else "",
            "points_max_balance": 3000.0,
            "simulation": True,
        }

    # -- admin helpers (mirror admin_ecopoint.php queries) ---------------
    @staticmethod
    def _waste_type_db(material_type: str) -> str:
        """Map the human material name to the ecopoint_waste_items.waste_type
        ENUM ('plastic_pet' | 'aluminum_can' | 'paper_cardboard' | 'other')."""
        m = (material_type or "").strip().lower()
        if "plastic" in m or "pet" in m:
            return "plastic_pet"
        if "aluminum" in m or "aluminium" in m or m == "can":
            return "aluminum_can"
        if "cardboard" in m or "paper" in m:
            return "paper_cardboard"
        return "other"

    def _admin_session_out(self, s: dict) -> dict:
        """One ecopoint_waste_sessions row (ws.*) joined with the resident row
        (users.*) and station row (ecopoint_stations.*) exactly like the
        admin_ecopoint.php SELECTs."""
        first = SIM_RESIDENT_NAME.split(" ", 1)[0] if s["user_id"] == SIM_USER_ID else "SIM"
        last = (SIM_RESIDENT_NAME[len(first):].strip()
                if s["user_id"] == SIM_USER_ID else "RESIDENT")
        return {
            "id": s["id"],
            "session_token": s["session_token"],
            "station_id": s["station_id"],
            "station_code": STATION_CODE,
            "station_name": STATION_NAME,
            "station_location": "SIMULATION MODE (local)",
            "user_id": s["user_id"],
            "qr_ref_code": s["qr_ref_code"],
            "status": s["status"],
            "error_message": s["error_message"],
            "material_type": s["material_type"],
            "weight_kg": float(s["weight_kg"] or 0),
            "points_calculated": float(s["points_calculated"] or 0),
            "points_awarded": int(s["points_awarded"] or 0),
            "points_posted": bool(s["points_posted"]),
            "posted_transaction_id": s.get("posted_transaction_id"),
            "total_weight_kg": float(s.get("total_weight_kg") or s["weight_kg"] or 0),
            "total_points": int(s.get("total_points") or (s["points_awarded"] or 0)),
            "created_at": s["created_at"],
            "updated_at": s["updated_at"],
            "waiting_at": s.get("waiting_at"),
            "started_at": s.get("started_at"),
            "completed_at": s["completed_at"],
            "cancelled_at": s["cancelled_at"],
            "error_at": s["error_at"],
            "first_name": first,
            "last_name": last,
            "house_number": SIM_HOUSE,
            "email": SIM_RESIDENT_EMAIL,
            "ref_code": TEST_QR,
        }

    def _admin_items_out(self, s: dict) -> list:
        """ecopoint_waste_items rows for a session (mirror the admin SELECT *)."""
        items = []
        for i in s["items"]:
            items.append({
                "id": i.get("id"),
                "session_id": s["id"],
                "waste_type": self._waste_type_db(i["waste_type"]),
                "material_label": i["material_label"],
                "weight_kg": float(i["weight_kg"]),
                "rate_pts_per_kg": int(i.get("rate_pts_per_kg") or 0),
                "points_calculated": int(i["points_calculated"]),
                "points_awarded": int(i.get("points_awarded") or 0),
                "created_at": i.get("created_at") or s["created_at"],
            })
        return items

    def _admin_events_out(self, s: dict) -> list:
        """ecopoint_session_events rows with payload_decoded, mirroring the
        admin ajax handler (event_payload is the JSON string, payload_decoded
        the parsed object)."""
        return [{
            "id": e["id"],
            "session_id": s["id"],
            "station_id": s["station_id"],
            "event_type": e["event_type"],
            "event_payload": json.dumps(e["event_payload"]),
            "source": e["source"],
            "created_at": e["created_at"],
            "payload_decoded": e["event_payload"],
        } for e in s["events"]]

    def admin_panel(self, q: str = ""):
        """Mirror the admin_ecopoint.php KPI / stations / live / recent queries."""
        now = self.now()
        today = now.strftime("%Y-%m-%d")
        week = self._week_bounds(now)
        w_start, w_end = week["week_start_mysql"], week["week_end_mysql"]

        pts_today = 0
        sess_today = 0
        pts_week = 0
        sess_week = 0
        for s in self.sessions.values():
            if s["status"] != "COMPLETED" or not s["completed_at"]:
                continue
            pts = int(s["points_awarded"] or 0)
            if s["completed_at"][:10] == today:
                pts_today += pts
                sess_today += 1
            if w_start <= s["completed_at"] <= w_end:
                pts_week += pts
                sess_week += 1

        sessions_active = sum(
            1 for s in self.sessions.values() if s["status"] in OPEN_STATUSES)

        hb = self.station_heartbeat_at
        try:
            hb_dt = datetime.strptime(hb, "%Y-%m-%d %H:%M:%S") if hb else None
            online = hb_dt is not None and (now - hb_dt).total_seconds() <= 120
        except ValueError:
            online = False

        active_for_station = next(
            (s for s in self.sessions.values()
             if s["station_id"] == STATION_ID and s["status"] in OPEN_STATUSES),
            None)
        station = {
            "id": STATION_ID,
            "station_code": STATION_CODE,
            "station_name": STATION_NAME,
            "location": "SIMULATION MODE (local)",
            "status": "ACTIVE",
            "api_key_last4": SIM_API_KEY_LAST4,
            "last_heartbeat_at": hb,
            "created_at": self.station_created_at,
            "online": online,
            "active": ({
                "has": True,
                "status": active_for_station["status"],
                "id": active_for_station["id"],
                "resident": SIM_RESIDENT_NAME,
                "house": SIM_HOUSE,
            } if active_for_station else {
                "has": False, "status": "-", "id": None,
                "resident": "-", "house": "-",
            }),
        }

        active_sessions = sorted(
            (self._admin_session_out(s)
             for s in self.sessions.values() if s["status"] in OPEN_STATUSES),
            key=lambda r: r["id"], reverse=True)

        q_l = (q or "").strip().lower()
        rows = [self._admin_session_out(s) for s in self.sessions.values()
                if s["status"] in TERMINAL_STATUSES]
        rows.sort(key=lambda r: r["id"], reverse=True)
        if q_l:
            def _match(r):
                hay = " ".join([
                    str(r["id"]), r["station_code"], r["qr_ref_code"],
                    r["first_name"], r["last_name"],
                    r["first_name"] + " " + r["last_name"],
                    r["house_number"]]).lower()
                return q_l in hay
            rows = [r for r in rows if _match(r)]
        recent = rows[:50]

        return {
            "simulation": True,
            "ts": time.time(),
            "polled_at": normalize_sql_datetime(now),
            "kpi": {
                "stations_total": 1,
                "stations_online": 1 if online else 0,
                "sessions_active": sessions_active,
                "pts_today": pts_today,
                "sessions_today": sess_today,
                "pts_week": pts_week,
                "sessions_week": sess_week,
            },
            "stations": [station],
            "active_sessions": active_sessions,
            "recent": recent,
        }

    def admin_session_detail(self, session_id: int):
        """Mirror admin_ecopoint.php?ajax=session_detail: session + items +
        events (with payload_decoded)."""
        for s in self.sessions.values():
            if s["id"] == session_id:
                session = self._admin_session_out(s)
                return {
                    "success": True,
                    "session": session,
                    "items": self._admin_items_out(s),
                    "events": self._admin_events_out(s),
                }
        return {"success": False}


# =====================================================================
# Station console + resident dashboard HTML
# =====================================================================
BANNER = (
    '<div style="position:sticky;top:0;z-index:9999;background:#7f1d1d;color:#fff;'
    'padding:8px 14px;font:700 13px/1.4 -apple-system,Segoe UI,sans-serif;'
    'border-bottom:3px solid #fca5a5;">'
    '&#9888;&#65039; SIMULATION MODE &#8212; sandboxed local VHEcoPoint demo. '
    'Test QR: <code>SIM-VHECO-TEST-001</code>. No real resident data, database, '
    'network or hardware is touched. Live deposits are NOT possible here.</div>'
)


def render_dashboard(store: SIMStore) -> str:
    agg = store.dashboard_aggregates(SIM_USER_ID)
    snap = store.snapshot(SIM_USER_ID)

    def nf(x):
        return f"{int(round(float(x))):,}"

    material_rows = ""
    material_slugs = {"Plastic Bottles": "plastic", "Aluminum Cans": "aluminum",
                      "Paper (Old Documents)": "paper"}
    for label in ("Plastic Bottles", "Aluminum Cans", "Paper (Old Documents)"):
        slug = material_slugs[label]
        st = agg["weekly_values"].get(label, {"points": 0, "weight": 0.0})
        progress = min(100, round((st["points"] / 250.0) * 100)) if st["points"] else 0
        material_rows += (
            '<div class="sim-material-row">'
            '<span class="sim-material-title">' + label + "</span>"
            '<span class="sim-material-sub" id="ecopoint-weekly-material-' + slug + '-weight">'
            + nf(st["weight"] * 1000) + " g recycled this week</span>"
            '<span class="sim-material-value" id="ecopoint-weekly-material-' + slug + '-points">'
            + nf(st["points"]) + " pts</span>"
            '<div class="sim-progress"><div class="sim-progress-bar" id="ecopoint-weekly-material-'
            + slug + '-bar" style="width:' + str(progress) + '%;"></div></div>'
            "</div>"
        )

    if agg["history"]:
        hist_rows = ""
        for h in agg["history"]:
            label = h["material_label"]
            grams = nf(h["weight_kg"] * 1000)
            title = label + (" - " + grams + " g" if h["weight_kg"] > 0 else "")
            entitled = (title + " &nbsp;|&nbsp; " + h["time_label"] +
                        " &nbsp;|&nbsp; <b>+" + nf(h["amount"]) + " pts</b>")
            hist_rows += (
                '<div class="vp-tx-row"><span class="sim-tx-icon">&#11015;</span>'
                '<span style="flex:1;">' + entitled + "</span></div>"
            )
    else:
        hist_rows = '<div class="ecopoint-empty">No VHEcoPoint activity yet.</div>'

    live_js_html = ('<script src="js/ecopoint_dashboard.js"></script>' if REAL_JS_PATH.exists()
                    else '<div style="color:#7f1d1d">WARNING: real dashboard JS file not found at '
                         + str(REAL_JS_PATH) + "</div>")

    html = """<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VHEcoPoint Resident Dashboard &mdash; SIMULATION MODE</title>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:0 0 60px;}
 .wrap{max-width:980px;margin:0 auto;padding:16px;}
 .sim-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-top:16px;}
 .sim-kpi-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.05);}
 .sim-kpi-label{font-size:12px;color:#6b7280;font-weight:600;margin-bottom:6px;text-transform:uppercase;letter-spacing:.03em;}
 .sim-kpi-value{font-size:1.9rem;font-weight:800;color:#0f172a;}
 .sim-kpi-subtext{font-size:12px;color:#6b7280;margin-top:6px;line-height:1.45;}
 .sim-kpi-subtext .balance-ok{color:#15803d;}
 .sim-card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:16px 18px;margin-top:16px;box-shadow:0 1px 2px rgba(15,23,42,.05);}
 .sim-card-title{font-size:15px;font-weight:800;margin:0 0 6px;}
 .sim-note{font-size:12px;color:#6b7280;margin-bottom:12px;}
 .sim-live-status{font-weight:700;}
 .sim-live-status.active{color:#15803d;}
 .sim-meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-top:10px;}
 .sim-metric{}.sim-metric span{font-size:11px;color:#6b7280;display:block;}
 .sim-metric strong{font-size:13px;}
 .sim-ready{color:#166534;background:#dcfce7;border-radius:8px;padding:8px 10px;font-weight:700;display:none;}
 .sim-actions{display:none;margin-top:10px;}
 .sim-material-list{margin-top:8px;}
 .sim-material-row{display:flex;align-items:center;gap:10px;border-bottom:1px solid #f1f5f9;padding:9px 0;font-size:13px;}
 .sim-material-title{font-weight:700;flex:1;}
 .sim-material-sub{color:#6b7280;font-size:12px;}
 .sim-material-value{font-weight:800;color:#14532d;}
 .sim-progress{height:6px;background:#e5e7eb;border-radius:4px;flex-basis:120px;}
 .sim-progress-bar{height:100%;background:#22c55e;border-radius:4px;}
 .vp-tx-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f1f5f9;font-size:13px;}
 .sim-tx-icon{width:30px;height:30px;border-radius:50%;background:#dcfce7;color:#166534;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
 .ecopoint-empty{color:#6b7280;font-size:13px;}
 .sim-banner2{background:#fef3c7;border:1px solid #fde047;border-radius:10px;color:#92400e;padding:10px 14px;font-size:13px;font-weight:600;margin-top:14px;}
</style></head>
<body>""" + BANNER + """
<div class="wrap">
<p style="color:#6b7280;font-size:12px;">Resident: <b>"""+SIM_RESIDENT_NAME+"""</b> &nbsp;&middot;&nbsp; """+SIM_TIMEZONE_NOTE+""" &nbsp;&middot;&nbsp;
<a href="/">Open the Station Console</a></p>
<div class="sim-banner2">This resident view reuses the production card markup (profileresident.php) and the
real <code>js/ecopoint_dashboard.js</code> live data-binding. Values feed through the same
SSE / poll snapshot JSON the production dashboard consumes.</div>

<div class="sim-kpi-grid">
  <div class="sim-kpi-card">
    <div class="sim-kpi-label"><i class="fa-solid fa-coins"></i> Current Point Balance</div>
    <div class="sim-kpi-value sim-balance-value"><i class="fa-solid fa-star"></i>
      <span class="sim-balance-number" id="ecopoint-current-balance"> """+nf(agg["current_points"])+"""</span>
      <span class="sim-limit">/ 3,000 pts</span></div>
    <div class="sim-kpi-subtext">Net balance from VHEcoPoint recycling ledger (earn &minus; redeem &plusmn; adjustments). Maximum balance is 3,000 pts.</div>
  </div>
  <div class="sim-kpi-card">
    <div class="sim-kpi-label">Weekly Points Earned</div>
    <div class="sim-kpi-value"><span id="ecopoint-weekly-points">"""+nf(agg["weekly_points"])+"""</span> / 250 pts</div>
    <div class="sim-kpi-subtext"><span id="ecopoint-weekly-remaining">"""+nf(agg["weekly_remaining"])+""" pts remain before this week's program cap resets.</span></div>
  </div>
  <div class="sim-kpi-card">
    <div class="sim-kpi-label">Daily Points Earned</div>
    <div class="sim-kpi-value"><span id="ecopoint-daily-pts-today">"""+nf(agg["today_points"])+"""</span> / 100 pts</div>
    <div class="sim-kpi-subtext"><span id="ecopoint-daily-remaining">"""+nf(agg["daily_remaining"])+""" pts</span> remain before today's 100-pt cap resets.</div>
  </div>
  <div class="sim-kpi-card">
    <div class="sim-kpi-label">Daily Sessions Used</div>
    <div class="sim-kpi-value"><span id="ecopoint-sessions-used">"""+nf(agg["today_sessions_used"])+"""</span> / 3 Used Today</div>
    <div class="sim-kpi-subtext"><span id="ecopoint-sessions-remaining">"""+("session" if agg["sessions_remaining"]==1 else "sessions")+""" """+nf(agg["sessions_remaining"])+""" remaining. Maximum of 3 VHEcoPoint station visits per day.</span></div>
  </div>
  <div class="sim-kpi-card">
    <div class="sim-kpi-label">Points Expiry Countdown</div>
    <div class="sim-kpi-value" style="font-size:1.2rem;">12 months from each earn</div>
    <div class="sim-kpi-subtext">Points expire 12 months after they are earned.</div>
  </div>
</div>

<div class="sim-card" id="ecopoint-live-panel">
  <div class="sim-card-title">Live Station Session</div>
  <div class="sim-note">Live status from the SSE / poll snapshot; updated in real time.</div>
  <div class="sim-live-status" id="ecopoint-live-status">No Active Session</div>
  <div class="sim-live-message" id="ecopoint-live-message">Scan your VictorianPass QR at the VHEcoPoint Station to begin.</div>
  <div class="sim-meta" id="ecopoint-live-meta">
    <div class="sim-metric"><span>Session start time</span><strong id="ecopoint-live-start-time">&mdash;</strong></div>
    <div class="sim-metric"><span>Current material</span><strong id="ecopoint-live-material">&mdash;</strong></div>
    <div class="sim-metric"><span>Current weight</span><strong id="ecopoint-live-weight">0 g</strong></div>
    <div class="sim-metric"><span>Current points</span><strong id="ecopoint-live-points">0 pts</strong></div>
    <div class="sim-metric"><span>Station</span><strong id="ecopoint-live-station-name">"""+STATION_NAME+"""</strong></div>
  </div>
  <div class="sim-cap-note" id="ecopoint-live-cap-note" style="display:none;margin-top:10px;padding:9px 12px;border-radius:10px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:12px;font-weight:600;align-items:center;gap:8px;"></div>
  <div class="sim-ready" id="ecopoint-live-ready">&#10003; Ready for Next Item</div>
  <div id="ecopoint-live-details"></div>
  <div class="sim-actions" id="ecopoint-live-actions">
    <button type="button" id="ecopointEndSessionBtn" title="End the active VHEcoPoint session early. Recorded items are still awarded and the visit counts once.">End Session</button>
  </div>
</div>

<div class="sim-card">
  <h3 class="sim-card-title">Weekly Material Cap Tracker</h3>
  <div class="sim-note">Weekly contributions count toward the current 250-point program cap.</div>
  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin:8px 0;">
    <span style="font-weight:700;color:#374151;font-size:13px;">Total Weekly Progress</span>
    <span style="font-weight:800;color:#14532d;font-size:15px;"><span id="ecopoint-weekly-progress">"""+nf(agg["weekly_points"])+""" / 250 pts &middot; """+str(agg["weekly_progress"])+"""%</span></span>
  </div>
  <div class="sim-progress" style="margin-bottom:4px;"><div class="sim-progress-bar" id="ecopoint-weekly-progress-bar" style="width:"""+str(agg["weekly_progress"])+"""%;"></div></div>
  <div class="sim-material-list">""" + material_rows + """</div>
</div>

<div class="sim-card">
  <h3 class="sim-card-title"><i class="fa-solid fa-receipt"></i> Points History</h3>
  <div class="sim-note">Your VHEcoPoint earn and redeem transactions, most recent first.</div><div id="ecopoint-history-container">""" + hist_rows + """</div>
</div>

<div>
  <div id="endSessionEarlyModal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(15,23,42,0.6);">
    <div style="background:#fff;padding:20px;border-radius:14px;max-width:420px;">
      <b>End session early?</b>
      <div style="font-size:12px;color:#6b7280;margin:6px 0;">Materials and points recorded so far are saved and the visit counts once. This mirrors the production resident early-end.</div>
      <button type="button" id="endSessionEarlyCancel">Close</button>
      <button type="button" id="endSessionEarlyConfirm">End Session</button>
      <button type="button" id="endSessionEarlyClose" style="display:none;"></button>
    </div>
  </div>
</div>

""" + live_js_html + """
</div></body></html>
"""
    return html


# =====================================================================
# Admin dashboard harness (simulated admin_ecopoint.php)
# =====================================================================
def _esc(s):
    return (str(s or "").replace("&", "&amp;").replace("<", "&lt;")
            .replace(">", "&gt;").replace('"', "&quot;").replace("'", "&#39;"))


def _admin_time(ts, style="long"):
    """Format a 'YYYY-MM-DD HH:MM:SS' timestamp like the real admin page."""
    if not ts:
        return "&mdash;"
    try:
        dt = datetime.strptime(str(ts)[:19], "%Y-%m-%d %H:%M:%S")
    except ValueError:
        return _esc(ts)
    hour = dt.strftime("%I")
    if hour.startswith("0"):
        hour = hour[1:]
    ampm = dt.strftime("%p")
    if style == "short":
        return "%s:%s %s" % (hour, dt.strftime("%M"), ampm)
    return "%s %d, %s:%s %s" % (dt.strftime("%b"), dt.day, hour,
                                dt.strftime("%M"), ampm)


def _admin_pill(status):
    cls = str(status or "").lower() or "offline"
    if cls not in ("waiting", "active", "processing", "completed",
                   "cancelled", "error", "online", "offline", "maintenance"):
        cls = "offline"
    return '<span class="pill %s">%s</span>' % (cls, _esc(status or "&mdash;"))


def _admin_active_rows(panel):
    rows = []
    for s in panel["active_sessions"]:
        resident = (s["first_name"] or "") + " " + (s["last_name"] or "")
        rows.append(
            "<tr>"
            "<td><b>#" + str(s["id"]) + "</b></td>"
            "<td>" + _esc(s["station_code"]) + "</td>"
            "<td>" + _esc(resident.strip()) + "</td>"
            "<td>" + _esc(s["house_number"]) + "</td>"
            "<td>" + _admin_pill(s["status"]) + "</td>"
            "<td>" + _esc(s["material_type"] or "&mdash;") + "</td>"
            "<td>%.2f kg</td>" % float(s["weight_kg"] or 0)
            + "<td>" + str(int(s["points_calculated"] or 0)) + " / "
            + str(int(s["points_awarded"] or 0)) + "</td>"
            "<td>" + _admin_time(s["started_at"] or s["created_at"], "short")
            + "</td>"
            '<td><button class="btn-link" onclick="adminOpenDetail('
            + str(s["id"]) + ')">Details</button></td>'
            "</tr>")
    if not rows:
        rows.append('<tr><td colspan="10" style="padding:40px;text-align:center;color:var(--muted)">'
                    "No active sessions right now.</td></tr>")
    return "".join(rows)


def _admin_recent_rows(panel):
    rows = []
    for s in panel["recent"]:
        resident = (s["first_name"] or "") + " " + (s["last_name"] or "")
        rows.append(
            "<tr>"
            "<td><b>#" + str(s["id"]) + "</b></td>"
            "<td>" + _admin_time(s["created_at"]) + "</td>"
            "<td>" + _esc(s["station_code"]) + "</td>"
            "<td>" + _esc(resident.strip()) + "</td>"
            "<td>" + _esc(s["house_number"]) + "</td>"
            "<td>" + _admin_pill(s["status"]) + "</td>"
            "<td>" + _esc(s["material_type"] or "&mdash;") + "</td>"
            "<td>%.2f kg</td>" % float(s["weight_kg"] or 0)
            + '<td><b style="color:#166534">' + str(int(s["points_awarded"] or 0))
            + "</b></td>"
            '<td><button class="btn-link" onclick="adminOpenDetail('
            + str(s["id"]) + ')">View</button></td>'
            "</tr>")
    if not rows:
        rows.append('<tr><td colspan="10" style="padding:40px;text-align:center;color:var(--muted)">'
                    "No sessions yet.</td></tr>")
    return "".join(rows)


def _admin_station_cards(panel):
    cards = []
    for s in panel["stations"]:
        online = bool(s["online"])
        status = s["status"] or "ACTIVE"
        pill_cls = "online" if online else (str(status).lower()
                                            if status.lower() != "active" else "offline")
        label = "ONLINE" if online else (status if status.lower() != "active" else "OFFLINE")
        active_html = ""
        if s["active"]["has"]:
            active_html = (
                '<div class="active-block">'
                '<div style="font-weight:600;margin-bottom:2px">'
                '<i class="fa-solid fa-user"></i> ' + _esc(s["active"]["resident"]) + "</div>"
                '<div style="font-size:0.82rem;color:var(--muted)">'
                '<i class="fa-solid fa-house"></i> ' + _esc(s["active"]["house"]) + " &middot; "
                '<button class="btn-link" onclick="adminOpenDetail('
                + str(s["active"]["id"]) + ')">View session &rarr;</button></div></div>')
        else:
            active_html = '<div class="active-block none">&mdash; No active session &mdash;</div>'
        cards.append(
            '<div class="station-card">'
            "<h3><span class=\"pill " + pill_cls + "\"><i class=\"fa-solid "
            + ("fa-wifi\"></i> " if online else "fa-plug-circle-xmark\"></i> ")
            + label + "</span>"
            "<span style=\"margin-left:6px\">" + _esc(s["station_code"]) + "</span></h3>"
            '<div class="loc"><i class="fa-solid fa-location-dot"></i> '
            + _esc(s["station_name"] + (" &mdash; " + s["location"]
                                        if s.get("location") else "")) + "</div>"
            '<div class="meta">'
            "<div><span>Last heartbeat</span><br><b>"
            + (_admin_time(s["last_heartbeat_at"]) if s["last_heartbeat_at"] else "&mdash;")
            + "</b></div>"
            "<div><span>Created</span><br><b>"
            + _admin_time(s["created_at"]) + "</b></div>"
            "<div><span>Key last 4</span><br><b>" + _esc(s.get("api_key_last4") or "&mdash;")
            + "</b></div>"
            "<div><span>Active sess</span><br><b>"
            + ('<span class="pill ' + str(s["active"]["status"]).lower() + '">'
               + _esc(s["active"]["status"]) + " #" + str(s["active"]["id"]) + "</span>"
               if s["active"]["has"] else "none")
            + "</b></div></div>" + active_html + "</div>")
    if not cards:
        cards.append('<div style="padding:40px;text-align:center;color:var(--muted);'
                     'grid-column:1/-1;background:#fff;border-radius:12px;'
                     'border:1px dashed var(--border)">No stations in the simulated store.</div>')
    return "".join(cards)


def render_admin(store: SIMStore) -> str:
    panel = store.admin_panel()
    kpi = panel["kpi"]
    active_rows = _admin_active_rows(panel)
    recent_rows = _admin_recent_rows(panel)
    station_cards = _admin_station_cards(panel)
    polled = panel["polled_at"]

    html = ("""<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>VictorianPass Admin | VHEcoPoint Dashboard &mdash; SIMULATION MODE</title>
<style>
 :root{--accent:#23412e;--accent-2:#2f573d;--muted:#6b7280;--border:#e5e7eb;--bg:#f7f5f0;}
 *{box-sizing:border-box;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;}
 body{margin:0;background:var(--bg);color:#1f2937;}
 .topbar{background:var(--accent);color:#fff;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;}
 .topbar h1{margin:0;font-size:1.2rem;}
 .topbar a{color:#fff;text-decoration:none;padding:6px 10px;border-radius:6px;font-size:0.9rem;background:rgba(255,255,255,.12);}
 main{max-width:1400px;margin:0 auto;padding:24px;}
 .sim-note-box{background:#fff;border:1px solid #fca5a5;border-left:5px solid #b91c1c;border-radius:10px;padding:12px 16px;font:600 13px/1.5 -apple-system,Segoe UI,sans-serif;color:#7f1d1d;margin-bottom:18px;}
 .kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:24px;}
 .kpi{background:#fff;border-radius:12px;padding:16px 18px;border:1px solid var(--border);box-shadow:0 2px 6px rgba(0,0,0,.04);}
 .kpi .label{font-size:0.8rem;color:var(--muted);font-weight:500;}
 .kpi .value{font-size:1.8rem;font-weight:700;margin-top:6px;color:var(--accent);}
 .kpi .sub{font-size:0.75rem;color:var(--muted);margin-top:2px;}
 h2.section{margin:28px 0 12px;font-size:1.1rem;color:#111;display:flex;align-items:center;gap:10px;}
 h2.section i{color:var(--accent);}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:12px;overflow:hidden;border:1px solid var(--border);box-shadow:0 2px 6px rgba(0,0,0,.04);}
 th,td{text-align:left;padding:12px 14px;border-bottom:1px solid var(--border);font-size:0.9rem;vertical-align:middle;}
 th{background:#faf9f6;font-weight:600;color:#374151;font-size:0.8rem;text-transform:uppercase;letter-spacing:.03em;}
 .pill{display:inline-block;padding:3px 10px;border-radius:999px;font-size:0.75rem;font-weight:600;}
 .pill.waiting{background:#fef3c7;color:#92400e;} .pill.active{background:#dbeafe;color:#1e40af;}
 .pill.processing{background:#cffafe;color:#0e7490;} .pill.completed{background:#dcfce7;color:#166534;}
 .pill.cancelled{background:#fee2e2;color:#991b1b;} .pill.error{background:#fecaca;color:#7f1d1d;}
 .pill.online{background:#dcfce7;color:#166534;} .pill.offline{background:#e5e7eb;color:#4b5563;}
 .stations-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;margin-bottom:8px;}
 .station-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:16px 18px;box-shadow:0 2px 6px rgba(0,0,0,.04);}
 .station-card h3{margin:0;display:flex;align-items:center;gap:10px;font-size:1rem;}
 .station-card .loc{color:var(--muted);font-size:0.82rem;margin:4px 0 12px;}
 .station-card .meta{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;font-size:0.85rem;}
 .station-card .meta b{color:#111;font-weight:600;}
 .station-card .active-block{margin-top:12px;padding:10px 12px;border-radius:8px;background:#f7faf8;border:1px dashed #cfd9d0;}
 .station-card .active-block.none{color:var(--muted);text-align:center;font-size:0.85rem;}
 .search-row{display:flex;gap:10px;align-items:center;margin:6px 0 14px;}
 .search-row input{flex:1;padding:10px 12px;border-radius:8px;border:1px solid var(--border);font-size:0.9rem;}
 .search-row button{padding:10px 16px;background:var(--accent);color:#fff;border:0;border-radius:8px;font-weight:600;cursor:pointer;}
 .live-strip{display:flex;align-items:center;gap:10px;font-size:0.78rem;color:var(--muted);margin:4px 0 2px;}
 .live-dot{width:9px;height:9px;border-radius:50%;background:#22c55e;display:inline-block;}
 .modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);align-items:center;justify-content:center;z-index:1000;padding:20px;}
 .modal-bg.open{display:flex;}
 .modal{background:#fff;border-radius:14px;max-width:820px;width:100%;max-height:90vh;overflow-y:auto;padding:22px;}
 .modal header{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;}
 .modal header h2{margin:0;font-size:1.1rem;}
 .modal header button{background:transparent;border:0;font-size:1.3rem;cursor:pointer;color:#6b7280;}
 .detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px 22px;font-size:0.9rem;margin:8px 0 18px;}
 .detail-grid b{color:#111;display:block;font-size:0.78rem;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.03em;margin-bottom:2px;}
 .breakdown{border:1px solid var(--border);border-radius:10px;overflow:hidden;margin:10px 0 18px;}
 .event-log{background:#fafafa;border-radius:10px;padding:10px 14px;max-height:280px;overflow-y:auto;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:0.8rem;}
 .event-log .evt + .evt{margin-top:8px;padding-top:8px;border-top:1px dashed #e5e7eb;}
 .event-log .ts{color:var(--muted);}
 .event-log .src{display:inline-block;padding:1px 6px;border-radius:4px;font-size:0.72rem;margin-right:6px;background:#eef2ff;color:#3730a3;}
 .btn-link{background:none;border:0;color:var(--accent);cursor:pointer;text-decoration:underline;font-weight:500;padding:0;}
</style></head><body>
""" + BANNER + """
<div class="topbar">
  <h1><i class="fa-solid fa-leaf" style="margin-right:8px;color:#a7f3d0"></i> VictorianPass Admin — VHEcoPoint Dashboard</h1>
  <a href="/"><i class="fa-solid fa-arrow-left"></i> Back to Station Console</a>
</div>
<main>
<div class="sim-note-box">
&#9888;&#65039; SIMULATED ADMIN VIEW &mdash; this is <b>not</b> the real <code>admin_ecopoint.php</code>
(that page needs PHP + mysqli + staff authentication and is never executed here). It mirrors its
KPIs, station status, live sessions, recent-sessions history and session-detail modal against the
<b>same in-memory simulation store</b> the resident dashboard reads. Local only &mdash; no real
resident information, databases or networks are touched.
</div>
<div class="live-strip"><span class="live-dot"></span> Live (auto-refresh every 2 s, no page reload) &middot; last update: <b id="admin-last-updated">""" + _esc(polled) + """</b></div>

<div class="kpis">
  <div class="kpi"><div class="label">Stations Online</div>
    <div class="value"><i class="fa-solid fa-tower-broadcast" style="font-size:1rem;margin-right:4px"></i><span id="admin-stations-online">""" + str(kpi["stations_online"]) + """</span><span style="font-size:1rem;color:var(--muted);font-weight:500"> / <span id="admin-stations-total">""" + str(kpi["stations_total"]) + """</span></span></div>
    <div class="sub">Online = heartbeat in last 2 minutes</div></div>
  <div class="kpi"><div class="label">Active Sessions Now</div>
    <div class="value"><i class="fa-solid fa-spinner fa-spin" style="font-size:1rem;margin-right:4px"></i><span id="admin-sessions-active">""" + str(kpi["sessions_active"]) + """</span></div>
    <div class="sub">WAITING / ACTIVE / PROCESSING</div></div>
  <div class="kpi"><div class="label">Points Awarded Today</div>
    <div class="value" style="color:#166534"><span id="admin-pts-today">""" + str(kpi["pts_today"]) + """</span><span style="font-size:1rem;color:var(--muted);font-weight:500"> / 100 cap per resident</span></div>
    <div class="sub">Sessions completed today: <b id="admin-sessions-today">""" + str(kpi["sessions_today"]) + """</b></div></div>
  <div class="kpi"><div class="label">This Week (Mon &rarr; Sun)</div>
    <div class="value" style="color:#9a3412"><span id="admin-pts-week">""" + str(kpi["pts_week"]) + """</span><span style="font-size:1rem;color:var(--muted);font-weight:500"> pts</span></div>
    <div class="sub">Sessions: <b id="admin-sessions-week">""" + str(kpi["sessions_week"]) + """</b> &middot; Resets Monday 12:00 AM</div></div>
</div>

<h2 class="section"><i class="fa-solid fa-satellite-dish"></i> VHEcoPoint Stations (LAN)</h2>
<div class="stations-grid" id="admin-stations-grid">""" + station_cards + """</div>

<h2 class="section"><i class="fa-solid fa-bolt"></i> Live Active Sessions</h2>
<table><thead><tr><th>ID</th><th>Station</th><th>Resident</th><th>House</th><th>Status</th><th>Material</th><th>Weight</th><th>Points (calc / award)</th><th>Started</th><th></th></tr></thead>
<tbody id="admin-active-tbody">""" + active_rows + """</tbody></table>

<h2 class="section" style="margin-top:30px"><i class="fa-solid fa-clock-rotate-left"></i> Recent Sessions (last 50)</h2>
<div class="search-row">
  <input id="admin-search" placeholder="Search by resident name, house #, station, QR ref, or session ID…">
  <button type="button" onclick="adminSearch()"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  <button type="button" style="background:#334155;" onclick="document.getElementById('admin-search').value='';adminSearch();">Clear</button>
</div>
<table><thead><tr><th>ID</th><th>Date</th><th>Station</th><th>Resident</th><th>House</th><th>Status</th><th>Material</th><th>Weight</th><th>Pts Awarded</th><th></th></tr></thead>
<tbody id="admin-recent-tbody">""" + recent_rows + """</tbody></table>
</main>

<div class="modal-bg" id="detailBg" onclick="if(event.target===this)adminCloseDetail()">
  <div class="modal" role="dialog" aria-modal="true">
    <header><h2><i class="fa-solid fa-receipt" style="color:var(--accent);margin-right:8px"></i> VHEcoPoint Session Detail (SIMULATED)</h2>
    <button onclick="adminCloseDetail()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button></header>
    <div id="detailBody" style="min-height:80px;display:flex;align-items:center;justify-content:center;color:var(--muted)">Loading&hellip;</div>
  </div>
</div>

<script>
var detailBody=document.getElementById('detailBody'), detailBg=document.getElementById('detailBg');
function escH(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function fmtDT(s){if(!s)return '&mdash;';var d=new Date(s.replace(' ','T'));if(isNaN(d.getTime()))return escH(s);
 var mo=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
 var hh=d.getHours()%12; if(hh===0)hh=12; var mm=String(d.getMinutes()).padStart(2,'0');
 return mo+' '+d.getDate()+', '+d.getFullYear()+', '+hh+':'+mm+' '+(d.getHours()>=12?'PM':'AM');}
function fmtDTshort(s){if(!s)return '&mdash;';var d=new Date(s.replace(' ','T'));if(isNaN(d.getTime()))return escH(s);
 var hh=d.getHours()%12; if(hh===0)hh=12; return hh+':'+String(d.getMinutes()).padStart(2,'0')+' '+(d.getHours()>=12?'PM':'AM');}
function fmtKg(v){v=parseFloat(v||0);if(isNaN(v))v=0;return v.toFixed(2)+' kg';}
function pill(s){var c=String(s||'').toLowerCase();if(['waiting','active','processing','completed','cancelled','error','online','offline'].indexOf(c)<0)c='offline';
 return '<span class="pill '+c+'">'+escH(s||'&mdash;')+'</span>';}
async function adminFetch(url){var r=await fetch(url);return await r.json();}
function adminRenderActive(p){var t=document.getElementById('admin-active-tbody');
 if(!p.active_sessions||!p.active_sessions.length){t.innerHTML='<tr><td colspan="10" style="padding:40px;text-align:center;color:var(--muted)">No active sessions right now.</td></tr>';return;}
 t.innerHTML=p.active_sessions.map(function(s){return '<tr><td><b>#'+s.id+'</b></td><td>'+escH(s.station_code)+
  '</td><td>'+escH((s.first_name||'')+' '+(s.last_name||'')).trim()+'</td><td>'+escH(s.house_number)+'</td><td>'+pill(s.status)+
  '</td><td>'+escH(s.material_type||'&mdash;')+'</td><td>'+fmtKg(s.weight_kg)+'</td><td>'+(+s.points_calculated||0)+' / '+(+s.points_awarded||0)+
  '</td><td>'+fmtDTshort(s.started_at||s.created_at)+'</td><td><button class="btn-link" onclick="adminOpenDetail('+s.id+')">Details</button></td></tr>';}).join('');}
function adminRenderRecent(p){var t=document.getElementById('admin-recent-tbody');
 if(!p.recent||!p.recent.length){t.innerHTML='<tr><td colspan="10" style="padding:40px;text-align:center;color:var(--muted)">No sessions yet.</td></tr>';return;}
 t.innerHTML=p.recent.map(function(s){return '<tr><td><b>#'+s.id+'</b></td><td>'+fmtDT(s.created_at)+'</td><td>'+escH(s.station_code)+
  '</td><td>'+escH((s.first_name||'')+' '+(s.last_name||'')).trim()+'</td><td>'+escH(s.house_number)+'</td><td>'+pill(s.status)+
  '</td><td>'+escH(s.material_type||'&mdash;')+'</td><td>'+fmtKg(s.weight_kg)+'</td><td><b style="color:#166534">'+(+s.points_awarded||0)+
  '</b></td><td><button class="btn-link" onclick="adminOpenDetail('+s.id+')">View</button></td></tr>';}).join('');}
function adminRenderStations(p){var g=document.getElementById('admin-stations-grid');
 if(!p.stations||!p.stations.length){g.innerHTML='<div style="padding:40px;text-align:center;color:var(--muted);grid-column:1/-1;background:#fff;border-radius:12px;border:1px dashed var(--border)">No stations in the simulated store.</div>';return;}
 g.innerHTML=p.stations.map(function(s){var on=!!s.online,status=s.status||'ACTIVE';
  var cls=on?'online':(status.toLowerCase()!=='active'?status.toLowerCase():'offline');
  var lbl=on?'ONLINE':(status.toLowerCase()!=='active'?status:'OFFLINE');
  var act=s.active&&s.active.has?'<div class="active-block"><div style="font-weight:600;margin-bottom:2px"><i class="fa-solid fa-user"></i> '+escH(s.active.resident)+
   '</div><div style="font-size:0.82rem;color:var(--muted)"><i class="fa-solid fa-house"></i> '+escH(s.active.house)+' &middot; <button class="btn-link" onclick="adminOpenDetail('+s.active.id+')">View session &rarr;</button></div></div>'
   :'<div class="active-block none">&mdash; No active session &mdash;</div>';
  var apill=s.active&&s.active.has?'<span class="pill '+String(s.active.status).toLowerCase()+'">'+escH(s.active.status)+' #'+s.active.id+'</span>':'none';
  return '<div class="station-card"><h3><span class="pill '+cls+'"><i class="fa-solid '+(on?'fa-wifi':'fa-plug-circle-xmark')+'" style="margin-right:4px"></i> '+lbl+'</span><span style="margin-left:6px">'+escH(s.station_code)+'</span></h3>'+
   '<div class="loc"><i class="fa-solid fa-location-dot"></i> '+escH(s.station_name+(s.location?' &mdash; '+s.location:''))+'</div>'+
   '<div class="meta"><div><span>Last heartbeat</span><br><b>'+fmtDT(s.last_heartbeat_at)+'</b></div>'+
   '<div><span>Created</span><br><b>'+fmtDT(s.created_at)+'</b></div>'+
   '<div><span>Key last 4</span><br><b>'+escH(s.api_key_last4||'&mdash;')+'</b></div>'+
   '<div><span>Active sess</span><br><b>'+apill+'</b></div></div>'+act+'</div>';}).join('');}
function adminRenderKpis(p){var k=p.kpi||{};
 set('admin-stations-online',k.stations_online||0);set('admin-stations-total',k.stations_total||0);
 set('admin-sessions-active',k.sessions_active||0);set('admin-pts-today',k.pts_today||0);
 set('admin-sessions-today',k.sessions_today||0);set('admin-pts-week',k.pts_week||0);set('admin-sessions-week',k.sessions_week||0);}
function set(id,v){var e=document.getElementById(id);if(e)e.textContent=v;}
async function loadAdmin(q){try{var p=await adminFetch('/admin/api'+(q!==undefined&&q!==''?'?q='+encodeURIComponent(q):''));
  adminRenderKpis(p);adminRenderStations(p);adminRenderActive(p);adminRenderRecent(p);
  set('admin-last-updated',p.polled_at?p.polled_at:new Date().toISOString());}
 catch(e){set('admin-last-updated','poll error - retrying');}}
function adminSearch(){loadAdmin(document.getElementById('admin-search').value.trim());}
var adminTimeout=null;
document.getElementById('admin-search').addEventListener('input',function(){clearTimeout(adminTimeout);adminTimeout=setTimeout(adminSearch,400);});
setInterval(function(){loadAdmin(document.getElementById('admin-search').value.trim());},2000);
document.addEventListener('keydown',function(e){if(e.key==='Escape')adminCloseDetail();});
function adminOpenDetail(id){detailBody.innerHTML='Loading session #'+id+'&hellip;';detailBg.classList.add('open');
 adminFetch('/admin/api?session='+encodeURIComponent(id)).then(function(d){
  if(!d||!d.success){detailBody.innerHTML='Session not found';return;}
  var s=d.session,items=d.items||[],events=d.events||[];
  var statusClass=String(s.status||'').toLowerCase();
  var h=''; h+='<div style="margin-bottom:8px">'+pill(s.status)+' <span style="margin-left:8px;font-weight:600">#'+s.id+'</span> at <b>'+escH(s.station_code)+'</b> ('+escH(s.station_name)+')'+(s.station_location?' &mdash; '+escH(s.station_location):'')+'</div>';
  h+='<div class="detail-grid">';
  h+='<div><b>Resident</b>'+escH(((s.first_name||'')+' '+(s.last_name||'')).trim()||'-')+'</div>';
  h+='<div><b>House</b>'+escH(s.house_number||'-')+'</div>';
  h+='<div><b>Email</b>'+escH(s.email||'-')+'</div>';
  h+='<div><b>QR ref (scan)</b><code>'+escH(s.qr_ref_code||'')+'</code></div>';
  h+='<div><b>Scanned at</b>'+fmtDT(s.created_at)+'</div>';
  h+='<div><b>Started at</b>'+fmtDT(s.started_at||s.waiting_at||s.created_at)+'</div>';
  h+='<div><b>Finalized at</b>'+((s.completed_at||s.cancelled_at||s.error_at)?fmtDT(s.completed_at||s.cancelled_at||s.error_at):'&mdash;')+'</div>';
  h+='<div><b>User ref_code</b>'+escH(s.ref_code||'')+'</div>';
  h+='<div><b>Total weight</b>'+fmtKg(s.total_weight_kg||s.weight_kg||0)+'</div>';
  h+='<div><b>Points (calculated / awarded)</b><span style="color:#166534;font-weight:600">'+(+s.points_calculated||0)+' / '+(+s.points_awarded||0)+'</span></div>';
  if(s.error_message){h+='<div style="grid-column:1/-1"><b>Error message</b><div style="color:#991b1b">'+escH(s.error_message)+'</div></div>';}
  h+='</div>';
  h+='<h3 style="font-size:0.95rem;margin:10px 0 6px;color:#111">Waste Breakdown</h3>';
  h+='<div class="breakdown"><table style="margin:0;border:0;box-shadow:none;border-radius:0"><thead><tr><th>Category</th><th>Label</th><th>Rate (pts/kg)</th><th>Weight (kg)</th><th>Points calculated</th><th>Points awarded</th></tr></thead><tbody>';
  if(!items.length){h+='<tr><td colspan="6" style="padding:16px;text-align:center;color:var(--muted)">'+(s.material_type?'Session-level data only ('+escH(s.material_type)+' &middot; '+fmtKg(s.weight_kg)+')':'No waste-item rows for this session.')+'</td></tr>';}
  else{var totals={};items.forEach(function(it){var k=it.waste_type;totals[k]=totals[k]||{weight:0,calc:0,award:0};
    totals[k].weight+=parseFloat(it.weight_kg||0);totals[k].calc+=parseInt(it.points_calculated||0,10);totals[k].award+=parseInt(it.points_awarded||0,10);});
   items.forEach(function(it){h+='<tr><td><code>'+escH(it.waste_type)+'</code></td><td>'+escH(it.material_label)+'</td><td>'+(+it.rate_pts_per_kg||0)+'</td><td>'+fmtKg(it.weight_kg)+'</td><td>'+(+it.points_calculated||0)+'</td><td><b style="color:#166534">'+(+it.points_awarded||0)+'</b></td></tr>';});
   var tw=0,tc=0,ta=0;Object.keys(totals).forEach(function(k){tw+=totals[k].weight;tc+=totals[k].calc;ta+=totals[k].award;});
   h+='<tr style="background:#f9fafb;font-weight:600"><td colspan="3" style="text-align:right">Totals</td><td>'+fmtKg(tw)+'</td><td>'+tc+'</td><td style="color:#166534">'+ta+'</td></tr>';}
  h+='</tbody></table></div>';
  h+='<h3 style="font-size:0.95rem;margin:18px 0 6px;color:#111">Immutable Audit Event Log</h3><div class="event-log">';
  if(!events.length){h+='<div style="color:var(--muted)">No events recorded.</div>';}
  events.forEach(function(e){h+='<div class="evt"><span class="ts">'+fmtDT(e.created_at)+'</span> <span class="src '+escH(e.source||'HARDWARE')+'">'+escH(e.source||'HARDWARE')+'</span><b style="margin-right:6px">'+escH(e.event_type||'')+'</b>';
   if(e.payload_decoded){try{h+='<pre style="margin:4px 0 0;padding:6px 8px;background:#fff;border:1px solid var(--border);border-radius:6px;white-space:pre-wrap">'+escH(JSON.stringify(e.payload_decoded,null,2))+'</pre>';}catch(err){h+='<span> - '+escH(String(e.event_payload||''))+'</span>';}}
   h+='</div>';});
  h+='</div>';
  detailBody.innerHTML=h;}).catch(function(){detailBody.innerHTML='Failed to load session detail';});}
function adminCloseDetail(){detailBg.classList.remove('open');}
loadAdmin('');
</script>
</body></html>""")

    return html


CONSOLE_HTML = """
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<title>VHEcoPoint Station Console &mdash; SIMULATION MODE</title>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:0 0 40px;}
 .wrap{max-width:760px;margin:0 auto;padding:18px;}
 label{font-size:12px;font-weight:700;display:block;margin:12px 0 4px;}
 input,select,button{font-size:14px;padding:9px 10px;border-radius:8px;border:1px solid #cbd5e1;}
 input:focus{outline:2px solid #f59e0b;}
 button{background:#14532d;color:#fff;font-weight:700;border:none;cursor:pointer;}
 button:disabled{opacity:.5;cursor:not-allowed;}
 .log{background:#0f172a;color:#a5f3fc;font:12px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:12px;border-radius:10px;height:200px;overflow:auto;margin-top:14px;}
 .field-line{display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;}
 .field-line>div{display:flex;flex-direction:column;}
</style></head>
<body>
""" + BANNER + """
<div class="wrap">
<h2 style="margin-bottom:4px;">VHEcoPoint Station Console &mdash; Desktop UI Simulation</h2>
<p style="color:#6b7280;font-size:12px;margin-top:0;">Station: <b>"""+STATION_CODE+"""</b>
&middot; Resident under test: <b>SIMULATION USER</b> (id 9001)
&middot; <a href="/dashboard">Open the Resident Dashboard</a><br>
The USB QR scanner types into the focused input below. Scanning the test code
<code>SIM-VHECO-TEST-001</code> (or pressing &ldquo;Scan test QR&rdquo;) starts a simulated session.</p>

<label for="sim-qr-target">QR code input (focused &mdash; USB scanner types here)</label>
<input id="sim-qr-target" autofocus style="width:100%;box-sizing:border-box;text-transform:none;" placeholder="Scan test QR, or type SIM-VHECO-TEST-001 and press Enter">
<button id="sim-scan" style="margin-top:8px;">Scan test QR (SIM-VHECO-TEST-001)</button>

<div class="field-line">
  <div>
    <label for="sim-material">Material</label>
    <select id="sim-material">
      <option value="Plastic">Plastic (camera confirms)</option>
      <option value="Paper">Paper (camera confirms)</option>
      <option value="Aluminum">Aluminum (camera can&rsquo;t see cans &rarr; inductive sensor confirms)</option>
    </select>
  </div>
  <div>
    <label for="sim-grams">Weight (grams)</label>
    <input id="sim-grams" type="number" min="1" max="2000" value="120" style="width:110px;">
  </div>
  <button id="sim-deposit">Simulate Deposit (submit item)</button>
</div>

<div class="field-line">
  <button id="sim-complete">Complete This Session</button>
  <button id="sim-reset" style="background:#7f1d1d;">Reset Simulation</button>
  <button id="sim-refresh" style="background:#334155;">Refresh State</button>
</div>

<div class="sim-banner" id="sim-status" style="margin-top:12px;background:#fef3c7;border:1px solid #fde047;border-radius:10px;color:#92400e;padding:10px 14px;font-size:13px;font-weight:600;">Ready. Scan the test QR to begin.</div>
<div class="log" id="sim-log">-- station console log --</div>
<script>
const logEl=document.getElementById('sim-log');
function log(m){ const t=new Date().toLocaleTimeString(); logEl.insertAdjacentHTML('afterbegin','<div>['+t+'] '+m+'</div>'); }
function status(m){ document.getElementById('sim-status').textContent=m; }
async function posts(ep, data){ const r=await fetch(ep,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data||{})}); let j=null; try{j=await r.json();}catch(e){} return {s:r.status,j}; }
let sessionToken=null, items=[];
async function refresh(){ const r=await fetch('/sim/state'); const st=await r.json(); const ses=st.current_session;
  if(!ses){ sessionToken=null; items=[]; status('No active simulated session. Scan the test QR to begin.'); }
  else { sessionToken=ses.session_token || sessionToken; items=(ses.waste_items||[]); status('Session active for '+(st.resident_name||'SIMULATION USER')+' ('+ses.status+') &middot; items:'+String(items.length)+' &middot; balance:'+st.dashboard.current_points+' pts'); } }
async function scanTestQr(){
  // Simulates the USB scanner typing the code into the focused input.
  const inp=document.getElementById('sim-qr-target'); inp.focus(); inp.value=''; let i=0;
  const code='SIM-VHECO-TEST-001';
  const timer=setInterval(()=>{ inp.value=code.slice(0,++i); if(i>=code.length){ clearInterval(timer); inp.dispatchEvent(new KeyboardEvent('keydown',{key:'Enter'})); } }, 25);
}
async function doVerify(){
  const code=document.getElementById('sim-qr-target').value.trim();
  await refresh(); if(items.length>0){ log('Cannot start: an active simulation already has submitted items.'); return; }
  const {s,j}=await posts('/sim/verify',{qr_code:code});
  log('qr_verify_and_create_session.php <- '+code+'  -> HTTP '+s+' '+(j&&(j.message||JSON.stringify(j))).slice(0,160));
  if(s>=200&&s<300&&j&&j.success){ sessionToken=j.session_token; status('Session verified. Now simulate a deposit.'); }
  else if(s===403){ log('Blocked: daily session limit reached (3/3 used).'); status('Daily session limit reached. This is CORRECT cap behaviour.'); }
  await refresh();
}
async function doDeposit(){
  if(!sessionToken){ log('No active session. Scan the test QR first.'); return; }
  const material=document.getElementById('sim-material').value;
  const grams=parseFloat(document.getElementById('sim-grams').value)||0;
  const {s,j}=await posts('/sim/submit',{session_token:sessionToken,material:material,weight_kg:(grams/1000),transaction_id:crypto.randomUUID().replace(/-/g,'')});
  log('submit_waste_data.php '+material+' '+grams+'g -> HTTP '+s+' '+(j&&(j.message||JSON.stringify(j))).slice(0,180));
  await refresh();
}
async function doComplete(){
  if(!sessionToken){ log('No active session.'); return; }
  const {s,j}=await posts('/sim/complete',{session_token:sessionToken});
  log('complete_session.php -> HTTP '+s+' '+(j&&(j.message||JSON.stringify(j))).slice(0,180));
  sessionToken=null; await refresh();
}
async function doReset(){
  await fetch('/sim/reset',{method:'POST'});
  sessionToken=null; items=[]; log('Simulation reset.');
  document.getElementById('sim-log').innerHTML='<div>-- station console log --</div>';
  await refresh();
}
document.getElementById('sim-scan').addEventListener('click',scanTestQr);
document.getElementById('sim-qr-target').addEventListener('keydown',e=>{ if(e.key==='Enter') doVerify(); });
document.getElementById('sim-deposit').addEventListener('click',doDeposit);
document.getElementById('sim-complete').addEventListener('click',doComplete);
document.getElementById('sim-reset').addEventListener('click',doReset);
document.getElementById('sim-refresh').addEventListener('click',refresh);
refresh();
</script>
</div></body></html>
"""


# =====================================================================
# HTTP handlers
# =====================================================================
class SimHandler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *args):
        pass

    server: "SimHTTPServer"

    def _send(self, status, content_type, body, headers=None):
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Cache-Control", "no-store, no-cache, must-revalidate")
        for k, v in (headers or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(body)

    def _json(self, obj, status=200):
        body = json.dumps(obj).encode("utf-8")
        self._send(status, "application/json", body)

    def _read_json(self):
        try:
            n = int(self.headers.get("Content-Length") or 0)
            raw = self.rfile.read(n) if n else b"{}"
            return json.loads(raw.decode("utf-8")) if raw.strip() else {}
        except Exception:
            return {}

    def do_GET(self):
        raw = self.path
        path = raw.split("?", 1)[0]
        query = urllib.parse.parse_qs(raw.split("?", 1)[1]) if "?" in raw else {}
        store = self.server.store
        if path in ("/", "/index.html"):
            self._send(200, "text/html; charset=utf-8", CONSOLE_HTML.encode("utf-8"))
        elif path in ("/dashboard", "/sim/dashboard"):
            self._send(200, "text/html; charset=utf-8", render_dashboard(store).encode("utf-8"))
        elif path in ("/admin", "/admin/"):
            self._send(200, "text/html; charset=utf-8", render_admin(store).encode("utf-8"))
        elif path == "/admin/api":
            if query.get("session"):
                try:
                    sid = int(query["session"][0])
                except (TypeError, ValueError):
                    sid = -1
                detail = store.admin_session_detail(sid)
                self._json(detail, 200)
            else:
                q = (query.get("q") or [""])[0]
                self._json(store.admin_panel(q), 200)
        elif path == "/js/ecopoint_dashboard.js":
            if REAL_JS_PATH.exists():
                data = REAL_JS_PATH.read_bytes()
                self._send(200, "application/javascript", data)
            else:
                self._send(500, "text/plain", b"real dashboard JS not found")
        elif path == "/api/ecopoint_session_status.php":
            self._json(store.snapshot(SIM_USER_ID), 200)
        elif path == "/sim/state":
            snap = store.snapshot(SIM_USER_ID)
            current = snap["active_session"]
            if current is not None:
                # The station console (not the public dashboard) needs the
                # real, usable session token to submit deposits. The SSE /
                # snapshot contract keeps only the redacted
                # `session_token_safe`; this isolated endpoint exposes the
                # full token for this simulation-only resident.
                active = store._active_for_user(SIM_USER_ID)
                current = dict(current)
                current["session_token"] = active["session_token"]
                current["waste_items"] = list(active["items"])
            self._json({
                "simulation": True,
                "station_code": STATION_CODE,
                "test_qr": TEST_QR,
                "user_id": SIM_USER_ID,
                "resident_name": SIM_RESIDENT_NAME,
                "current_session": current,
                "dashboard": store.dashboard_aggregates(SIM_USER_ID),
                "cap_state": snap["cap_state"],
                "ledger_balance": store.ledger_balance(SIM_USER_ID),
                "sessions": [
                    {"id": s["id"], "status": s["status"],
                     "material_type": s["material_type"], "weight_kg": s["weight_kg"],
                     "points_awarded": s["points_awarded"],
                     "items": list(s["items"])}
                    for s in store.sessions.values()
                ],
            }, 200)
        elif path == "/api/ecopoint_sse.php":
            self._serve_sse()
        else:
            self._json({"success": False, "message": "not found"}, 404)

    # ---- SSE endpoint (mirrors api/ecopoint_sse.php contract) ----------
    def _serve_sse(self):
        store = self.server.store
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream; charset=UTF-8")
        self.send_header("Cache-Control", "no-cache, no-store, must-revalidate")
        self.send_header("X-Accel-Buffering", "no")
        self.send_header("Connection", "close")
        self.end_headers()
        try:
            self.wfile.write(b": welcome VHEcoPoint SSE resident=%d\n" % SIM_USER_ID)
            self.wfile.write(b"retry: 2000\n\n")
            self.wfile.flush()
        except OSError:
            return
        last_hash = ""
        deadline = time.time() + 280
        interval = 0.8
        while time.time() < deadline:
            snap = store.snapshot(SIM_USER_ID)
            cmp_snap = dict(snap)
            cmp_snap.pop("ts", None)
            cmp_snap.pop("polled_at", None)
            h = hashlib.md5(json.dumps(cmp_snap, sort_keys=True).encode()).hexdigest()
            try:
                if h != last_hash:
                    last_hash = h
                    payload = json.dumps(snap)
                    self.wfile.write(b"event: snapshot\n")
                    self.wfile.write(b"id: %d\n" % int(1000 * snap["ts"]))
                    self.wfile.write(b"data: " + payload.encode("utf-8") + b"\n\n")
                    self.wfile.flush()
                else:
                    self.wfile.write(b": keepalive\n\n")
                    self.wfile.flush()
            except OSError:
                break
            time.sleep(interval)
        try:
            self.wfile.write(b"event: end_of_stream\ndata: {\"reconnect\": true}\n\n")
            self.wfile.flush()
        except OSError:
            pass

    def do_POST(self):
        path = self.path.split("?", 1)[0]
        store = self.server.store
        data = self._read_json()

        if path == "/sim/verify":
            result = store.verify_qr(data.get("qr_code", ""))
            self._json(result["json"], result["http_status"])
        elif path == "/sim/submit":
            result = store.submit_item(data)
            self._json(result["json"], result["http_status"])
        elif path == "/sim/complete":
            result = store.complete_session(data.get("session_token", ""))
            self._json(result["json"], result["http_status"])
        elif path == "/sim/reset":
            store.reset()
            self._json({"success": True, "reset": True, "simulation": True}, 200)
        elif path in ("/api/ecopoint_end_session.php", "/sim/end"):
            result = store.end_session(SIM_USER_ID)
            self._json(result["json"], result["http_status"])
        else:
            self._json({"success": False, "message": "not found"}, 404)


class SimHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, addr, handler, store):
        self.store = store
        super().__init__(addr, handler)


def start_server(port=0, host="127.0.0.1", store=None):
    store = store or SIMStore()
    srv = SimHTTPServer((host, port), SimHandler, store)
    thread = threading.Thread(target=srv.serve_forever, daemon=True)
    thread.start()
    actual_port = srv.server_address[1]
    return srv, store


def main(argv=None):
    argv = list(sys.argv[1:] if argv is None else argv)
    port = int(argv[argv.index("--port") + 1]) if "--port" in argv else 8199
    host = argv[argv.index("--host") + 1] if "--host" in argv else "127.0.0.1"
    srv, store = start_server(port=port, host=host)
    print("VHEcoPoint SIMULATION MODE running locally.")
    print("  station console    -> http://%s:%d/" % (host, srv.server_address[1]))
    print("  resident dashboard -> http://%s:%d/dashboard" % (host, srv.server_address[1]))
    print("  admin dashboard    -> http://%s:%d/admin" % (host, srv.server_address[1]))
    print("  no DB, no network, no hardware. Press Ctrl-C to stop.")
    try:
        while True:
            time.sleep(3600)
    except KeyboardInterrupt:
        print("stopped")


if __name__ == "__main__":
    main()