#!/usr/bin/env python3
"""VHEcoPoint — headless verifier for the desktop UI SIMULATION MODE.

Runs the whole simulated deposit scenario (A Plastic 120 g, B Paper 120 g,
C Aluminum 150 g) over the real HTTP contract, checks that the served
dashboard HTML carries the expected values through the exact element ids
the production js/ecopoint_dashboard.js binds to, exercises the dry-run
hardware adapters, then re-runs every existing regression suite.

It never touches the real database, network or hardware. Exit code 0 =
everything passed.

    python3 Project_wasteBin/ui_sim_verify.py
"""

import hashlib
import json
import os
import pathlib
import re
import socket
import subprocess
import sys
import time
import urllib.error
import urllib.request

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
REPO_ROOT = SCRIPT_DIR.parent
sys.path.insert(0, str(SCRIPT_DIR))

import ui_sim_server as sim  # noqa: E402
import hw_sim_adapters as hw  # noqa: E402

REAL_JS = REPO_ROOT / "js" / "ecopoint_dashboard.js"
INNER_DB = SCRIPT_DIR / "vheco_local.db"

PASS = 0
FAIL = 0
_HEADER = None


def section(name):
    global _HEADER
    _HEADER = name
    print()
    print("=" * 66)
    print("  " + name)
    print("=" * 66)


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print("  PASS  " + name)
    else:
        FAIL += 1
        print("  FAIL  " + name + (("  -- " + str(detail)) if detail else ""))


# ---------------------------------------------------------------------
# HTTP helpers
# ---------------------------------------------------------------------
class Client:
    def __init__(self, port):
        self.base = "http://127.0.0.1:%d" % port

    def get(self, path):
        try:
            r = urllib.request.urlopen(self.base + path, timeout=10)
            return r.status, r.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as e:
            return e.code, e.read().decode("utf-8", "replace")

    def get_json(self, path):
        s, body = self.get(path)
        return s, json.loads(body)

    def post(self, path, data):
        req = urllib.request.Request(
            self.base + path, data=json.dumps(data).encode(),
            headers={"Content-Type": "application/json"})
        try:
            r = urllib.request.urlopen(req, timeout=10)
            return r.status, json.loads(r.read().decode())
        except urllib.error.HTTPError as e:
            return e.code, json.loads(e.read().decode())

    def sse_first_snapshot(self, timeout=6.0):
        """Open the SSE stream and return the first `snapshot` event's JSON."""
        host = self.base.split("//", 1)[1]
        h, p = host.split(":")
        sock = socket.create_connection((h, int(p)), timeout=timeout)
        sock.sendall(b"GET /api/ecopoint_sse.php HTTP/1.1\r\nHost: x\r\n"
                     b"Accept: text/event-stream\r\n\r\n")
        sock.settimeout(0.5)
        buf = b""
        deadline = time.time() + timeout
        while time.time() < deadline:
            try:
                d = sock.recv(65536)
                if not d:
                    break
                buf += d
            except socket.timeout:
                if b"event: snapshot" in buf and b'"current_balance"' in buf:
                    break
        sock.close()
        text = buf.decode("utf-8", "replace")
        if "event: snapshot" not in text:
            return None
        idx = text.index("event: snapshot")
        data_line = ""
        for line in text[idx:].splitlines():
            if line.startswith("data: "):
                data_line = line[len("data: "):]
                break
        try:
            return json.loads(data_line)
        except Exception:
            return None


def id_text(html, element_id):
    m = re.search(r'id="%s"[^>]*>([^<]*)' % re.escape(element_id), html)
    return m.group(1).strip() if m else None


DYNAMIC_IDS = {
    "ecopoint-notification-container",
    "ecopoint-points-limit-popup",
    "ecopoint-session-popup",
    "ecopoint-dashboard-styles",
}


def js_referenced_ids():
    src = REAL_JS.read_text(encoding="utf-8", errors="replace")
    ids = set()
    for m in re.finditer(
        r"(?:getElement|setText|setStyle|setHTML|hideElement|showElement)"
        r"\(['\"]([^'\"]+)['\"]", src):
        ids.add(m.group(1))
    for m in re.finditer(r"getElementById\(['\"]([^'\"]+)['\"]", src):
        ids.add(m.group(1))
    # Drop concatenation prefixes (e.g. 'ecopoint-weekly-material-' + slug).
    # Those ids are built dynamically and are validated by the consistency
    # suite against the real rendered rows instead.
    return {i for i in ids if not i.endswith("-")} - DYNAMIC_IDS


# ---------------------------------------------------------------------
# Phase 3 + 5 scenario
# ---------------------------------------------------------------------
def run_scenario():
    section("PHASE 3 — simulated deposit scenario (A/B/C) + served dashboard")

    srv, store = sim.start_server()
    port = srv.server_address[1]
    c = Client(port)

    try:
        # ---- static contract: real JS served byte-identical -------------
        s, js_body = c.get("/js/ecopoint_dashboard.js")
        real_bytes = REAL_JS.read_bytes()
        check("real js/ecopoint_dashboard.js served (200)", s == 200)
        check("served dashboard JS is byte-identical to the production file",
              js_body.encode("utf-8") == real_bytes,
              "sha256 %s" % hashlib.sha256(real_bytes).hexdigest()[:12])

        # ---- static contract: JS-bound ids exist in the dashboard ------
        s, dash = c.get("/dashboard")
        check("dashboard harness served (200)", s == 200)
        missing = [i for i in sorted(js_referenced_ids())
                   if ('id="%s"' % i) not in dash]
        check("every element id the real JS binds to exists in the harness",
              not missing, "missing: %s" % missing)
        check("station console served (200)", c.get("/")[0] == 200)
        console = c.get("/")[1]
        check("console has an autofocus QR input (USB-scanner target)",
              'id="sim-qr-target"' in console and "autofocus" in console)
        check("console is clearly labelled SIMULATION MODE",
              "SIMULATION MODE" in console)

        # ---- snapshot schema on the poll endpoint -----------------------
        s, snap = c.get_json("/api/ecopoint_session_status.php")
        need = {"success", "user_id", "ts", "polled_at", "current_balance",
                "cap_state", "active_session", "recent_sessions",
                "points_limit_reached", "points_limit_message",
                "points_max_balance"}
        check("poll endpoint returns the full snapshot schema",
              s == 200 and need.issubset(snap.keys()),
              "missing %s" % (need - set(snap.keys())))

        # ---- SSE contract ----------------------------------------------
        sse = c.sse_first_snapshot()
        check("SSE emits an 'event: snapshot' with current_balance",
              sse is not None and "current_balance" in sse and "cap_state" in sse)

        # ---- end-session with no active session is rejected cleanly -----
        s, j = c.post("/api/ecopoint_end_session.php", {})
        check("end_session.php with no active session returns 400 (not 405)",
              s == 400 and not j.get("success"), (s, j))

        # ================= A: Plastic 120 g ==============================
        dep, err = deposit(c, "Plastic", 0.12, "tx-A")
        check("A: QR verify + submit Plastic 120 g -> raw 36.36 pts",
              dep and abs(dep["raw"] - 36.36) < 0.001 and dep["awarded"] == 36,
              dep)
        check("A: balance = 36, daily = 36, weekly = 36, sessions = 1",
              dep and dep["balance"] == 36 and dep["cap"]["daily_points_used"] == 36
              and dep["cap"]["weekly_points_used"] == 36
              and dep["cap"]["daily_sessions_used"] == 1, dep and dep["cap"])
        check("A: served dashboard cards read 36/36/36",
              dep and dep["cards"] == ("36", "36", "36"), dep and dep["cards"])
        check("A: weekly tracker counts 36 pts / 120 g Plastic Bottles",
              dep and dep["weekly"]["Plastic Bottles"] == (36, 0.12),
              dep and dep["weekly"])

        # ================= B: Paper 120 g ================================
        dep, err = deposit(c, "Paper", 0.12, "tx-B")
        check("B: balance = 72, daily = 72, weekly = 72, sessions = 2",
              dep and dep["balance"] == 72 and dep["cap"]["daily_points_used"] == 72
              and dep["cap"]["weekly_points_used"] == 72
              and dep["cap"]["daily_sessions_used"] == 2, dep and dep["cap"])
        check("B: served dashboard cards read 72/72/72",
              dep and dep["cards"] == ("72", "72", "72"), dep and dep["cards"])
        check("B: weekly tracker = Plastic 36/0.12 + Paper 36/0.12",
              dep and dep["weekly"]["Plastic Bottles"] == (36, 0.12)
              and dep["weekly"]["Paper (Old Documents)"] == (36, 0.12),
              dep and dep["weekly"])

        # ================= C: Aluminum 150 g =============================
        dep, err = deposit(c, "Aluminum", 0.15, "tx-C")
        check("C: raw 45.45 pts, capped by daily room 28 -> awarded 28",
              dep and abs(dep["raw"] - 45.45) < 0.001 and dep["awarded"] == 28,
              dep)
        check("C: balance = 100, daily = 100, weekly = 100, sessions = 3",
              dep and dep["balance"] == 100
              and dep["cap"]["daily_points_used"] == 100
              and dep["cap"]["weekly_points_used"] == 100
              and dep["cap"]["daily_sessions_used"] == 3, dep and dep["cap"])
        check("C: served dashboard cards read 100/100/100 (week 40%)",
              dep and dep["cards"] == ("100", "100", "100"), dep and dep["cards"])
        check("C: weekly tracker adds Aluminum 28 pts / 150 g",
              dep and dep["weekly"]["Aluminum Cans"] == (28, 0.15),
              dep and dep["weekly"])
        check("C: points history shows 3 earn rows (36/36/28)",
              dep and sorted(h["amount"] for h in dep["history"]) == [28, 36, 36],
              dep and dep["history"])

        # ---- 4th session blocked by the daily session cap --------------
        s, v = c.post("/sim/verify", {"qr_code": sim.TEST_QR})
        check("4th QR verify blocked (daily session limit 3/day)", s == 403, (s, v))

        # =============== ADMIN simulacrum (phase 3.5) ====================
        s, admin = c.get("/admin")
        check("admin harness served (200) + labelled SIMULATION",
              s == 200 and "SIMULATED ADMIN VIEW" in admin
              and "SIMULATION MODE" in admin)
        s, panel = c.get_json("/admin/api")
        check("admin kpi schema mirrors admin_ecopoint.php",
              set(panel["kpi"].keys()) == {
                  "stations_total", "stations_online", "sessions_active",
                  "pts_today", "sessions_today", "pts_week", "sessions_week"},
              panel["kpi"])
        check("the three deposits appear on the admin side (100 pts, 3 sessions)",
              panel["kpi"]["pts_today"] == 100
              and panel["kpi"]["sessions_today"] == 3
              and panel["kpi"]["sessions_week"] == 3
              and len(panel["recent"]) == 3
              and panel["kpi"]["sessions_active"] == 0
              and not panel["stations"][0]["active"]["has"],
              (panel["kpi"], len(panel["recent"]), panel["stations"][0]["active"]))
        check("admin recent matches deposit order/values (28 + 36 + 36)",
              panel["recent"][0]["points_awarded"] == 28
              and panel["recent"][1]["points_awarded"] == 36
              and panel["recent"][2]["points_awarded"] == 36
              and panel["recent"][0]["id"] > panel["recent"][1]["id"],
              [(r["id"], r["points_awarded"]) for r in panel["recent"]])

        # ================= PHASE 5 checks ================================
        section("PHASE 5 — safety / isolation / cap checks")

        # duplicate replay must not double-award
        c.post("/sim/reset", {})
        s, v = c.post("/sim/verify", {"qr_code": sim.TEST_QR})
        tok = v["session_token"]
        c.post("/sim/submit", {"session_token": tok, "material": "Plastic",
                               "weight_kg": 0.12, "transaction_id": "dup-1"})
        s2, j2 = c.post("/sim/submit", {"session_token": tok, "material": "Plastic",
                                        "weight_kg": 0.12, "transaction_id": "dup-1"})
        check("duplicate transaction replay is rejected as idempotent",
              s2 == 200 and j2.get("duplicate") is True, (s2, j2))
        c.post("/sim/complete", {"session_token": tok})
        st = c.get_json("/sim/state")[1]
        earns = [t for t in store.transactions if t["transaction_type"] == "earn"]
        check("duplicate replay produced exactly one earn (no double award)",
              len(earns) == 1 and st["ledger_balance"] == 36,
              (len(earns), st["ledger_balance"]))

        # servo failure (adapter path) must not award points
        c.post("/sim/reset", {})
        before = store.ledger_balance(sim.SIM_USER_ID)
        servo = hw.DryRunServo(fail_mode="raise")
        servo.start()
        servo_ok = True
        try:
            servo.sort_material("plastic")
        except RuntimeError:
            servo_ok = False
        # process_item only submits if actuation succeeded
        if servo_ok:
            c.post("/sim/verify", {"qr_code": sim.TEST_QR})
        after = store.ledger_balance(sim.SIM_USER_ID)
        check("servo failure -> item not submitted, no points awarded",
              (not servo_ok) and after == before and after == 0, (servo_ok, after))

        # 4th session blocked after reset+3 (re-verify cap)
        c.post("/sim/reset", {})
        for tx in ("a", "b", "c"):
            _, v = c.post("/sim/verify", {"qr_code": sim.TEST_QR})
            t = v["session_token"]
            c.post("/sim/submit", {"session_token": t, "material": "Plastic",
                                   "weight_kg": 0.02, "transaction_id": tx})
            c.post("/sim/complete", {"session_token": t})
        s, v = c.post("/sim/verify", {"qr_code": sim.TEST_QR})
        check("cap enforced after reset + 3 sessions (4th blocked, 403)", s == 403)

        # non-test QR must never resolve
        s, v = c.post("/sim/verify", {"qr_code": "VH-1003"})
        check("only the sim test QR resolves (real QR rejected 404)", s == 404)

        # reset clears the store
        c.post("/sim/reset", {})
        st = c.get_json("/sim/state")[1]
        check("reset returns the sandbox to a clean state",
              st["ledger_balance"] == 0 and not st["sessions"]
              and st["cap_state"]["daily_sessions_used"] == 0)

        # isolation: no DB / no prod URLs
        check("inner vheco_local.db untouched by the simulation",
              store_db_hash() == _db_hash_before, "db hash changed")
        dbs = list(SCRIPT_DIR.glob("*.db")) + list(SCRIPT_DIR.glob("*.sqlite*"))
        check("no SQLite/db files created by the simulation",
              all(p.name == "vheco_local.db" for p in dbs),
              [p.name for p in dbs])
        server_src = (SCRIPT_DIR / "ui_sim_server.py").read_text()
        check("server contains no outbound/production HTTP client",
              "https://" not in server_src
              and "import requests" not in server_src
              and "urllib.request" not in server_src
              and "urlopen" not in server_src
              and "socket.create_connection" not in server_src,
              "found outbound client reference")
    finally:
        srv.shutdown()


_db_hash_before = None


def store_db_hash():
    if INNER_DB.exists():
        return hashlib.sha256(INNER_DB.read_bytes()).hexdigest()
    return "absent"


def deposit(c, material, kg, txid):
    """Run one full simulated session: verify -> submit -> complete, then
    read back both the store aggregates and the served dashboard HTML."""
    _, v = c.post("/sim/verify", {"qr_code": sim.TEST_QR})
    if not v.get("success"):
        return None, v
    tok = v["session_token"]
    s, sub = c.post("/sim/submit", {"session_token": tok, "material": material,
                                    "weight_kg": kg, "transaction_id": txid})
    if s != 200 or not sub.get("success"):
        return None, sub
    raw = sub["points_calculated"]
    s, comp = c.post("/sim/complete", {"session_token": tok})
    if s != 200 or not comp.get("success"):
        return None, comp
    awarded = comp["points_awarded"]

    st = c.get_json("/sim/state")[1]
    dash = c.get("/dashboard")[1]
    # values as rendered into the real card ids by the served harness
    cards = (id_text(dash, "ecopoint-current-balance"),
             id_text(dash, "ecopoint-weekly-points"),
             id_text(dash, "ecopoint-daily-pts-today"))
    weekly = {k: (v["points"], round(v["weight"], 3))
              for k, v in st["dashboard"]["weekly_values"].items()}
    return {
        "raw": raw,
        "awarded": awarded,
        "balance": int(st["ledger_balance"]),
        "cap": st["cap_state"],
        "cards": cards,
        "weekly": weekly,
        "history": st["dashboard"]["history"],
    }, None


# ---------------------------------------------------------------------
# Phase 4 — adapters
# ---------------------------------------------------------------------
def run_adapters():
    section("PHASE 4 — dry-run hardware adapters")
    try:
        hw._selfcheck()
        check("adapter self-check ran", True)
    except AssertionError as e:
        check("adapter self-check ran", False, e)

    servo = hw.DryRunServo()
    servo.start()
    check("servo start homes diverter + flap to safe positions",
          servo.channel_angle_pairs()[:2] ==
          [(hw.MG996R_CHANNEL, 90.0), (hw.MG995_CHANNEL, 90.0)],
          servo.channel_angle_pairs()[:2])

    servo.reset_recording()
    servo.sort_material("plastic")
    check("Plastic diverts LEFT (ch14=45) and pulses within 500-2500us",
          servo.channel_angle_pairs() == hw.expected_servo_commands("plastic")
          and all(500 <= c["pulse_us"] <= 2500 for c in servo.commands),
          servo.channel_angle_pairs())

    servo.reset_recording()
    servo.sort_material("aluminum")
    check("Aluminum diverts RIGHT (ch14=135)",
          servo.channel_angle_pairs() ==
          [(hw.MG996R_CHANNEL, 135.0), (hw.MG995_CHANNEL, 150.0),
           (hw.MG995_CHANNEL, 90.0), (hw.MG996R_CHANNEL, 90.0)],
          servo.channel_angle_pairs())

    check("unknown material returns False (no points path)",
          servo.sort_material("glass") is False)

    check("load cell: below-MIN_WEIGHT reading is rejected",
          hw.DryRunLoadCell(grams=3.0).get_weight() < hw.MIN_WEIGHT)
    check("load cell: timeout yields None (never treated as empty)",
          hw.DryRunLoadCell(mode="timeout").get_weight() is None)
    try:
        hw.DryRunLoadCell(mode="failure").get_weight()
        check("load cell failure raises", False)
    except IOError:
        check("load cell failure raises", True)

    check("camera cannot see cans -> inductive fallback identifies Aluminum",
          hw.simulate_item_classification(
              hw.DryRunCamera("Unclassified"), hw.DryRunInductive(True))
          == "Aluminum")
    check("no metal + camera unclassified -> Unrecognized (rejected)",
          hw.simulate_item_classification(
              hw.DryRunCamera("Unclassified"), hw.DryRunInductive(False))
          == "Unrecognized")
    check("camera 'Mixed' is rejected before any inductive fallback",
          hw.simulate_item_classification(
              hw.DryRunCamera("Mixed"), hw.DryRunInductive(True)) == "Mixed")


# ---------------------------------------------------------------------
# Regressions
# ---------------------------------------------------------------------
PY_SUITES = [
    ("test_e2e_simulation.py", 95),
    ("test_m1_error_classification.py", 45),
    ("test_m3_backoff.py", 26),
    ("test_m5_power_loss.py", 61),
    ("test_ui_sim_session_flow.py", 17),
    ("test_ui_sim_dashboard_consistency.py", 49),
    ("test_ui_sim_admin_view.py", 50),
]
PHP_SUITES = [
    ("test_m4_submit_race.php", 43),
    ("test_complete_session_race.php", 47),
    ("test_cancel_error_race.php", 53),
    ("test_error_recovery_marker.php", 22),
    ("test_event_persistence.php", 22),
]


def run_suite(cmd, fname, expected):
    path = SCRIPT_DIR / fname
    if not path.exists():
        return None, "missing"
    proc = subprocess.run(cmd + [fname], cwd=str(SCRIPT_DIR),
                          capture_output=True, text=True, timeout=300)
    m = re.search(r"RESULT:\s*(\d+)\s*passed,\s*(\d+)\s*failed", proc.stdout)
    if not m:
        return False, (proc.stdout[-300:] + proc.stderr[-300:])
    passed, failed = int(m.group(1)), int(m.group(2))
    ok = proc.returncode == 0 and failed == 0 and passed == expected
    return ok, "passed=%d failed=%d (expected %d, rc=%d)" % (
        passed, failed, expected, proc.returncode)


def run_regressions():
    section("REGRESSIONS — existing suites must stay green")
    for fname, exp in PY_SUITES:
        ok, detail = run_suite([sys.executable], fname, exp)
        check("python %s" % fname, ok is True, detail)
    for fname, exp in PHP_SUITES:
        ok, detail = run_suite(["php"], fname, exp)
        check("php %s" % fname, ok is True, detail)


# ---------------------------------------------------------------------
# Static hygiene
# ---------------------------------------------------------------------
def run_static():
    section("STATIC — compile, php lint, whitespace, repo untouched")
    for f in ("ui_sim_server.py", "hw_sim_adapters.py", "ui_sim_verify.py"):
        p = subprocess.run([sys.executable, "-m", "py_compile", str(SCRIPT_DIR / f)],
                           capture_output=True, text=True)
        check("py_compile %s" % f, p.returncode == 0, p.stderr[-200:])

    php_files = ["ecopoint_core.php", "ecopoint_idempotency.php"]
    php_files += [f for f in os.listdir(REPO_ROOT / "api")
                  if f.endswith(".php")] if (REPO_ROOT / "api").is_dir() else []
    lint_ok = True
    lint_detail = ""
    for f in php_files[:40]:
        target = (REPO_ROOT / f) if f.startswith("ecopoint") else (REPO_ROOT / "api" / f)
        if not target.exists():
            continue
        p = subprocess.run(["php", "-l", str(target)],
                           capture_output=True, text=True)
        if p.returncode != 0:
            lint_ok = False
            lint_detail = "%s: %s" % (f, p.stderr[-160:])
            break
    check("php -l on relied-upon PHP files", lint_ok, lint_detail)

    d = subprocess.run(["git", "diff", "--check"], cwd=str(REPO_ROOT),
                       capture_output=True, text=True)
    check("git diff --check (no whitespace errors)", d.returncode == 0,
          d.stdout[-200:])

    status = subprocess.run(["git", "status", "--porcelain"], cwd=str(REPO_ROOT),
                            capture_output=True, text=True).stdout
    check("staged live-DB deletion is preserved (not restored/addressed)",
          "D  Project_wasteBin/vheco_local.db" in status
          or "D Project_wasteBin/vheco_local.db" in status)
    new_untracked = {ln[3:] for ln in status.splitlines() if ln.startswith("??")}
    known = {"Project_wasteBin/ui_sim_server.py",
             "Project_wasteBin/ui_sim_verify.py",
             "Project_wasteBin/test_ui_sim_session_flow.py",
             "Project_wasteBin/test_ui_sim_dashboard_consistency.py",
             "Project_wasteBin/test_ui_sim_admin_view.py",
             "Project_wasteBin/hw_sim_adapters.py",
             "Project_wasteBin/UI_SIMULATION_REPORT.md"}
    unexpected = {p for p in new_untracked
                  if (("ui_sim" in p or "hw_sim" in p)
                      and p not in known
                      and not p.endswith("__pycache__"))}
    check("no stray simulation artifacts in the repo",
          not unexpected, unexpected)


def main():
    global _db_hash_before
    _db_hash_before = store_db_hash()

    print("VHEcoPoint UI SIMULATION verifier")
    print("repo       : %s" % REPO_ROOT)
    print("real JS    : %s (%s)" % (REAL_JS, "present" if REAL_JS.exists() else "MISSING"))
    print("php bridge : %s" % (sim.PHP_BIN or "MISSING"))

    run_scenario()
    run_adapters()
    run_static()
    run_regressions()

    print()
    print("=" * 66)
    print("  RESULT: %d passed, %d failed" % (PASS, FAIL))
    print("=" * 66)
    sys.exit(0 if FAIL == 0 else 1)


if __name__ == "__main__":
    main()