#!/usr/bin/env python3
"""Regression test: VHEcoPoint SIMULATION MODE QR -> session -> deposit flow.

Guards the bug where a successful scan of SIM-VHECO-TEST-001 returned HTTP
200 with a session token, but the station console's `refresh()` read the
session token from the wrong place (/sim/state exposed only the redacted
`session_token_safe`) and threw on the wrong item field (`ses.items` vs
`ses.waste_items`). The net effect was that the token was clobbered to
`undefined` right after a successful scan, so the deposit controls reported
"No active session. Scan the test QR first."

This test drives the same front-end state machine (refresh / verify /
deposit) against the real simulation server over HTTP, so a regression in
the served console JS or the /sim/state payload is caught.

No production API, database, hardware or network is touched.
"""

import hashlib
import json
import pathlib
import sys
import urllib.error
import urllib.request

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

import ui_sim_server as sim  # noqa: E402

PASS = 0
FAIL = 0


def check(name, cond, detail=""):
    global PASS, FAIL
    if cond:
        PASS += 1
        print("  PASS  " + name)
    else:
        FAIL += 1
        print("  FAIL  " + name + (("  -- " + str(detail)) if detail else ""))


def http_get(base, path):
    with urllib.request.urlopen(base + path, timeout=10) as r:
        return r.status, r.read().decode("utf-8", "replace")


def http_post(base, path, data):
    req = urllib.request.Request(
        base + path, data=json.dumps(data).encode(),
        headers={"Content-Type": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=10) as r:
            return r.status, json.loads(r.read().decode())
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read().decode())


class Console:
    """Mirrors the station console's front-end state machine exactly."""

    def __init__(self, base):
        self.base = base
        self.session_token = None
        self.items = []
        self.resident_name = None

    def refresh(self):
        _, body = http_get(self.base, "/sim/state")
        st = json.loads(body)
        ses = st.get("current_session")
        if not ses:
            self.session_token = None
            self.items = []
            return st
        self.session_token = ses.get("session_token") or self.session_token
        self.items = ses.get("waste_items") or []
        self.resident_name = st.get("resident_name")
        return st

    def scan(self, code):
        status, j = http_post(self.base, "/sim/verify", {"qr_code": code})
        if 200 <= status < 300 and j.get("success"):
            self.session_token = j.get("session_token")
        self.refresh()
        return status, j

    def deposit(self, material, grams):
        if not self.session_token:
            return None, {"message": "No active session. Scan the test QR first."}
        status, j = http_post(self.base, "/sim/submit", {
            "session_token": self.session_token,
            "material": material,
            "weight_kg": grams / 1000.0,
            "transaction_id": "reg-%s-%d" % (material, grams),
        })
        self.refresh()
        return status, j


def db_hash():
    db = SCRIPT_DIR / "vheco_local.db"
    return hashlib.sha256(db.read_bytes()).hexdigest() if db.exists() else "absent"


def main():
    print("=" * 66)
    print("  UI SIM session-flow regression (QR -> active session -> deposit)")
    print("=" * 66)

    db_before = db_hash()
    srv, store = sim.start_server()
    base = "http://127.0.0.1:%d" % srv.server_address[1]
    try:
        console = Console(base)
        console.refresh()

        # --- the served console JS must read the state token correctly -----
        _, console_html = http_get(base, "/")
        check("console JS reads the session token from /sim/state",
              "ses.session_token" in console_html)
        check("console JS no longer reads the nonexistent ses.items field",
              "ses.items" not in console_html)

        # --- 1. successful scan establishes the active session -------------
        status, j = console.scan(sim.TEST_QR)
        check("test QR scan succeeds (HTTP 200, success true)",
              status == 200 and j.get("success") is True, (status, j))
        check("scan response carries a usable session token",
              bool(j.get("session_token")) and len(j["session_token"]) >= 16)
        st = console.refresh()
        check("/sim/state exposes that same token to the console",
              st["current_session"].get("session_token") == j["session_token"],
              st["current_session"].get("session_token_safe"))
        check("/sim/state reports the simulated resident",
              st.get("resident_name") == sim.SIM_RESIDENT_NAME)
        check("console records the active session token (not undefined)",
              console.session_token == j["session_token"], console.session_token)

        # --- 2. deposit controls use that token + resident -----------------
        status, dep = console.deposit("Plastic", 120)
        check("deposit succeeds using the active session token",
              status == 200 and dep.get("points_calculated") == 36.36,
              (status, dep))
        check("deposit is recorded against the session's resident",
              console.resident_name == sim.SIM_RESIDENT_NAME
              and console.session_token == j["session_token"])
        _, state = http_get(base, "/sim/state")
        state = json.loads(state)
        check("deposit appears on the active session's item list",
              len(state["current_session"]["waste_items"]) == 1,
              state["current_session"]["waste_items"])

        # --- 4. scanning again cannot duplicate/corrupt the session --------
        sessions_before = len(store.sessions)
        token_before = console.session_token
        status, j2 = console.scan(sim.TEST_QR)
        check("rescanning reuses the same session (no duplicate created)",
              console.session_token == token_before
              and len(store.sessions) == sessions_before,
              (token_before, console.session_token, len(store.sessions)))
        check("rescanning does not corrupt submitted session state",
              len(console.items) == 1, console.items)

        # --- 3. invalid QR does not create an active session ---------------
        http_post(base, "/sim/reset", {})
        console.refresh()
        status, bad = http_post(base, "/sim/verify", {"qr_code": "VH-1003"})
        console.refresh()
        check("invalid QR is rejected (HTTP 404, no success)",
              status == 404 and not bad.get("success"), (status, bad))
        check("invalid QR creates no active session",
              console.session_token is None and len(store.sessions) == 0)
        check("invalid QR leaves the console with no token to deposit",
              console.deposit("Plastic", 120)[0] is None)

        # --- 5. only the isolated in-memory store changed ------------------
        check("real vheco_local.db is untouched",
              db_hash() == db_before)
        check("store has no outbound/production references",
              "https://" not in (SCRIPT_DIR / "ui_sim_server.py").read_text())
    finally:
        srv.shutdown()

    print("=" * 66)
    print("RESULT: %d passed, %d failed" % (PASS, FAIL))
    print("=" * 66)
    sys.exit(0 if FAIL == 0 else 1)


if __name__ == "__main__":
    main()