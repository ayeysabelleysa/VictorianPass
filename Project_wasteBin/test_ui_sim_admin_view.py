#!/usr/bin/env python3
"""Regression test: VHEcoPoint ADMIN dashboard simulation view.

Guards the simulated admin harness served at /admin. The real
admin_ecopoint.php needs PHP + mysqli + staff auth and is NEVER executed
here; this harness mirrors its structure (KPIs, station grid, live active
sessions, recent-sessions history, session-detail modal with waste
breakdown + event audit) against the SAME in-memory SIMStore the resident
dashboard reads, and live-polls /admin/api (no full browser reload).

Assertions, against isolated test data:
  1. /admin is served, clearly labelled SIMULATION, with the real page's
     section structure and field names.
  2. Three deposits (Plastic 120 g, Paper 120 g, Aluminum 150 g) appear on
     the admin side with material / weight / transaction / session / point
     values that EXACTLY match the simulator's underlying records.
  3. Session status changes PROCESSING -> COMPLETED when finalized.
  4. New activity becomes visible through the same /admin/api JSON the page
     polls, without a browser reload.
  5. No stale or duplicated records: duplicate transaction replays stay a
     single deposit, reads never mutate, session ids are unique.
  6. ERROR sessions render with their error_message / error_at, and the
     event audit (incl. transaction ids) is present.
  7. /sim/reset clears the admin view AND leaves the resident store, the
     (staged-deleted) vheco_local.db and the live SQLite queue untouched.

No production API, database, hardware or network is touched.
"""

import hashlib
import json
import pathlib
import re
import sys
import urllib.error
import urllib.parse
import urllib.request

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

import ui_sim_server as sim  # noqa: E402

PASS = 0
FAIL = 0

INNER_DB = SCRIPT_DIR / "vheco_local.db"
ADMIN_IDS = (
    "admin-stations-online", "admin-stations-total", "admin-sessions-active",
    "admin-pts-today", "admin-sessions-today", "admin-pts-week",
    "admin-sessions-week", "admin-stations-grid", "admin-active-tbody",
    "admin-recent-tbody", "admin-search", "admin-last-updated",
)


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


def admin_api(base, q=""):
    s, body = http_get(base, "/admin/api" + ("?q=" + urllib.parse.quote(q) if q else ""))
    return s, json.loads(body)


def deposit(base, material, kg, txid, complete=True):
    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    s, sub = http_post(base, "/sim/submit",
                       {"session_token": token, "material": material,
                        "weight_kg": kg, "transaction_id": txid})
    if complete:
        _, comp = http_post(base, "/sim/complete", {"session_token": token})
        return token, s, sub, comp
    return token, s, sub, None


def store_db_hash():
    if INNER_DB.exists():
        return hashlib.sha256(INNER_DB.read_bytes()).hexdigest()
    return "absent"


def scenario_served_contract(base):
    print("\n-- served /admin contract (structure + live poll wiring) --")
    s, admin = http_get(base, "/admin")
    check("GET /admin serves the harness (200)", s == 200)
    check("page is clearly labelled SIMULATION MODE (no production claim)",
          "SIMULATION MODE" in admin and "SIMULATED ADMIN VIEW" in admin)
    check("page states it is NOT the real admin_ecopoint.php",
          "admin_ecopoint.php" in admin and "never executed" in admin)
    check("topbar mirrors the real admin title",
          "VictorianPass Admin" in admin and "VHEcoPoint Dashboard" in admin)
    check("KPI/pill/table sections mirror admin_ecopoint.php",
          "Stations Online" in admin and "This Week (Mon" in admin
          and "Recent Sessions (last 50)" in admin
          and "Live Active Sessions" in admin)
    missing = [i for i in ADMIN_IDS if 'id="%s"' % i not in admin]
    check("admin live/data-binding elements present", not missing, missing)
    check("session-detail modal present (detailBg/detailBody)",
          'id="detailBg"' in admin and 'id="detailBody"' in admin)
    check("auto-update wired via /admin/api polling (no page reload)",
          "setInterval" in admin and "loadAdmin" in admin
          and "/admin/api" in admin and "location.reload" not in admin)

    s, panel = admin_api(base)
    need = {"simulation", "kpi", "stations", "active_sessions", "recent", "polled_at"}
    check("GET /admin/api returns the full admin panel schema",
          s == 200 and need.issubset(panel.keys()),
          "missing %s" % (need - set(panel.keys())))
    check("admin panel is flagged simulation", panel.get("simulation") is True)
    check("KPI schema mirrors admin_ecopoint.php",
          set(panel["kpi"].keys()) == {
              "stations_total", "stations_online", "sessions_active",
              "pts_today", "sessions_today", "pts_week", "sessions_week"})
    s, bad = http_get(base, "/admin/api?session=9999")
    check("unknown session detail returns success:false",
          json.loads(bad).get("success") is False)


def scenario_three_deposits(base, store):
    print("\n-- admin side mirrors the three simulated deposits --")
    http_post(base, "/sim/reset", {})
    deposit(base, "Plastic", 0.12, "adm-A", complete=True)
    deposit(base, "Paper", 0.12, "adm-B", complete=True)
    deposit(base, "Aluminum", 0.15, "adm-C", complete=True)

    s, panel = admin_api(base)
    kpi = panel["kpi"]
    check("KPI after A/B/C: 100 pts today / 3 sessions today / 3 this week",
          kpi["pts_today"] == 100 and kpi["sessions_today"] == 3
          and kpi["pts_week"] == 100 and kpi["sessions_week"] == 3, kpi)
    check("KPI: 0 active, station online (1/1)",
          kpi["sessions_active"] == 0 and kpi["stations_online"] == 1
          and kpi["stations_total"] == 1, kpi)
    check("all three deposits appear in recent (COMPLETED, newest first)",
          len(panel["recent"]) == 3
          and all(r["status"] == "COMPLETED" for r in panel["recent"]),
          [(r["id"], r["status"]) for r in panel["recent"]])
    check("no duplicated records: recent session ids are unique",
          len({r["id"] for r in panel["recent"]}) == 3)

    by_id = {r["id"]: r for r in panel["recent"]}
    store_rows = {ss["id"]: ss for ss in store.sessions.values()}
    weights = {1000: 0.12, 1001: 0.12, 1002: 0.15}
    awarded = {1000: 36, 1001: 36, 1002: 28}
    materials = {1000: "Plastic", 1001: "Paper", 1002: "Aluminum"}
    ok = True
    detail = ""
    for sid, row in by_id.items():
        ss = store_rows.get(sid)
        if not ss or ss["status"] != "COMPLETED":
            ok = False
            detail += "missing session %s;" % sid
            continue
        if abs(float(row["weight_kg"]) - weights[sid]) > 1e-9:
            ok = False
            detail += "weight %s;" % row["weight_kg"]
        if row["points_awarded"] != awarded[sid]:
            ok = False
            detail += "awarded %s;" % row["points_awarded"]
        if (row["material_type"] or "").lower() != materials[sid].lower():
            ok = False
            detail += "material %s;" % row["material_type"]
        if row["id"] != ss["id"]:
            ok = False
            detail += "id mismatch;"
    check("admin material/weight/points/session values match the store",
          ok, detail)

    s, panel2 = admin_api(base)
    check("re-reading the panel is stable (no stale/duplicated records)",
          panel2["recent"] == panel["recent"]
          and panel2["active_sessions"] == panel["active_sessions"])

    earns = [t for t in store.transactions if t["transaction_type"] == "earn"]
    tx_ok = (len(earns) == 3
             and {t["amount"] for t in earns} == {28, 36}
             and all(t.get("ecopoint_session_id") in by_id for t in earns))
    check("each earn transaction ties to a session and matches awarded pts",
          tx_ok, [(t["amount"], t.get("ecopoint_session_id")) for t in earns])

    check("admin identity is the synthetic sim resident (no real data)",
          all(r["house_number"] == sim.SIM_HOUSE
              and r["qr_ref_code"] == sim.TEST_QR
              and "SIMULATION" in r["first_name"] + " " + r["last_name"]
              for r in panel["recent"]))

    s, admin = http_get(base, "/admin")
    tbody = re.search(r'id="admin-recent-tbody">(.*?)</tbody>', admin, re.S)
    body = tbody.group(1) if tbody else ""
    served_ok = (body.count("#1000") == 1 and body.count("#1001") == 1
                 and body.count("#1002") == 1
                 and "0.12 kg" in body and "0.15 kg" in body
                 and ">36</b>" in body and ">28</b>" in body)
    check("served /admin HTML renders the same recent rows (ids/weights/pts)",
          served_ok, body[:400])

    s, filtered = admin_api(base, "VH-ECO")
    check("admin search by station code filters recent set",
          s == 200 and len(filtered["recent"]) == 3, len(filtered["recent"]))
    s, filtered = admin_api(base, "zzz-no-match")
    check("admin search with no match returns an empty set",
          len(filtered["recent"]) == 0)


def scenario_status_transition(base, store):
    print("\n-- session status PROCESSING -> COMPLETED with audit (incl. tx ids) --")
    http_post(base, "/sim/reset", {})
    token, sub_status, sub, _ = deposit(base, "Plastic", 0.12, "adm-tx-1",
                                        complete=False)
    check("submit returns PROCESSING with raw 36.36",
          sub_status == 200 and sub.get("session_status") == "PROCESSING"
          and abs(float(sub.get("points_calculated")) - 36.36) < 0.01, sub)

    s, panel = admin_api(base)
    check("active session appears on the admin side (PROCESSING)",
          len(panel["active_sessions"]) == 1
          and panel["active_sessions"][0]["status"] == "PROCESSING"
          and panel["active_sessions"][0]["id"] == panel["stations"][0]["active"]["id"],
          [(r["id"], r["status"]) for r in panel["active_sessions"]])
    act = panel["active_sessions"][0]
    check("admin live row carries material/weight/calc points",
          (act["material_type"] or "").lower() == "plastic"
          and abs(float(act["weight_kg"]) - 0.12) < 1e-9
          and abs(float(act["points_calculated"]) - 36.36) < 0.01, act)
    check("station card active block names the resident/house",
          panel["stations"][0]["active"]["resident"] == sim.SIM_RESIDENT_NAME
          and panel["stations"][0]["active"]["house"] == sim.SIM_HOUSE)

    sid = act["id"]
    s, detail = http_get(base, "/admin/api?session=%d" % sid)
    d = json.loads(detail)
    check("detail returns session + items + events",
          d["success"] and len(d["items"]) == 1 and len(d["events"]) >= 3)
    it = d["items"][0]
    check("item row mirrors ecopoint_waste_items (enum/rate/weight/calc)",
          it["waste_type"] == "plastic_pet" and it["rate_pts_per_kg"] == 303
          and abs(float(it["weight_kg"]) - 0.12) < 1e-9
          and int(it["points_calculated"]) == 36, it)
    waste = [e for e in d["events"] if e["event_type"] == "WASTE_DATA"]
    check("transaction id appears in the WASTE_DATA audit payload",
          len(waste) == 1
          and waste[0]["payload_decoded"].get("transaction_id") == "adm-tx-1"
          and (waste[0]["payload_decoded"].get("raw") or {}).get("transaction_id") == "adm-tx-1"
          and "adm-tx-1" in waste[0]["event_payload"],
          waste and waste[0]["payload_decoded"])
    check("source of the deposit event is HARDWARE",
          waste[0]["source"] == "HARDWARE")

    _, comp = http_post(base, "/sim/complete", {"session_token": token})
    check("completion awards 36 (INT)", comp.get("points_awarded") == 36, comp)

    s, panel = admin_api(base)
    check("status changed to COMPLETED: active empty, recent top is the session",
          len(panel["active_sessions"]) == 0
          and panel["recent"][0]["id"] == sid
          and panel["recent"][0]["status"] == "COMPLETED")
    s, detail = http_get(base, "/admin/api?session=%d" % sid)
    d = json.loads(detail)
    sess = d["session"]
    check("detail reflects COMPLETED with completed_at + posted tx",
          sess["status"] == "COMPLETED" and sess["completed_at"]
          and sess["points_awarded"] == 36
          and sess["posted_transaction_id"] is not None, sess)
    ev_types = [e["event_type"] for e in d["events"]]
    check("finalize audit events present (POINTS_POSTED, SESSION_COMPLETED)",
          "POINTS_POSTED" in ev_types and "SESSION_COMPLETED" in ev_types, ev_types)


def scenario_duplicates_and_auto_update(base):
    print("\n-- no duplicates; new activity appears via the same polled JSON --")
    http_post(base, "/sim/reset", {})
    s, admin0 = http_get(base, "/admin")
    check("clean admin page starts with no sessions",
          "No sessions yet." in admin0 and "No active sessions right now." in admin0)

    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    payload = {"session_token": token, "material": "Plastic",
               "weight_kg": 0.12, "transaction_id": "dup-admin"}
    http_post(base, "/sim/submit", payload)
    s, again = http_post(base, "/sim/submit", payload)
    check("replaying the same transaction_id is idempotent (duplicate flag)",
          s == 200 and again.get("duplicate") is True, (s, again))

    s, panel = admin_api(base)
    act = panel["active_sessions"][0]
    s, detail = http_get(base, "/admin/api?session=%d" % act["id"])
    d = json.loads(detail)
    check("duplicate replay is NOT a second deposit (one item, one event)",
          len(d["items"]) == 1
          and len([e for e in d["events"] if e["event_type"] == "WASTE_DATA"]) == 1
          and int(d["session"]["weight_kg"] * 1000) == 120,
          (len(d["items"]), len(d["events"])))

    _, comp = http_post(base, "/sim/complete", {"session_token": token})
    check("completion after duplicate replay still awards once (36)",
          comp.get("points_awarded") == 36, comp)

    # New activity becomes visible through the polling endpoint (the exact
    # request the page makes every 2 s) without any browser reload.
    _, panel_before = admin_api(base)
    check("before the new deposit recent holds just the prior session",
          len(panel_before["recent"]) == 1
          and len(panel_before["active_sessions"]) == 0)
    _, v2 = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token2 = v2["session_token"]
    http_post(base, "/sim/submit", {"session_token": token2, "material": "Aluminum",
                                    "weight_kg": 0.15, "transaction_id": "new-act"})
    _, panel_after = admin_api(base)
    check("next poll shows the new active session (auto-visible, no reload)",
          len(panel_after["active_sessions"]) == 1
          and panel_after["active_sessions"][0]["id"] != panel_before["recent"][0]["id"]
          and panel_after["active_sessions"][0]["status"] == "PROCESSING"
          and abs(float(panel_after["active_sessions"][0]["weight_kg"]) - 0.15) < 1e-9)


def scenario_error_session(store, base):
    print("\n-- ERROR sessions render status + error_message on the admin side --")
    http_post(base, "/sim/reset", {})
    res = store.verify_qr(sim.TEST_QR)
    token = res["json"]["session_token"]
    ok = store.submit_item({"session_token": token, "material": "Plastic",
                            "weight_kg": 0.12, "transaction_id": "err-1"})
    check("error-fixture deposit submitted", ok["json"]["success"] is True)
    # Simulation-only injection: mark the session ERROR (admin renders these).
    sess = store.sessions[token]
    sess["status"] = "ERROR"
    sess["error_at"] = sim.normalize_sql_datetime(store.now())
    sess["error_message"] = "Simulated hardware fault (test fixture)"

    _, panel = admin_api(base)
    check("ERROR session listed in recent with ERROR status",
          len(panel["recent"]) == 1
          and panel["recent"][0]["status"] == "ERROR"
          and not panel["active_sessions"])
    s, detail = http_get(base, "/admin/api?session=%d" % panel["recent"][0]["id"])
    d = json.loads(detail)
    check("detail carries error_message and error_at",
          d["session"]["error_message"] == "Simulated hardware fault (test fixture)"
          and d["session"]["error_at"])
    s, admin = http_get(base, "/admin")
    check("served admin HTML renders the ERROR pill",
          'class="pill error">ERROR' in admin.replace("\n", ""))


def scenario_reset_isolation(base, hash_before):
    print("\n-- reset clears admin view; resident DB / admin DB / live queue untouched --")
    http_post(base, "/sim/reset", {})
    _, panel = admin_api(base)
    check("after reset: admin shows zero sessions / zero points",
          len(panel["recent"]) == 0 and len(panel["active_sessions"]) == 0
          and panel["kpi"]["pts_today"] == 0 and panel["kpi"]["sessions_active"] == 0)
    s, snap = http_get(base, "/api/ecopoint_session_status.php")
    r = json.loads(snap)
    check("resident view cleared too (balance 0, no history)",
          r["current_balance"] == 0 and not r["recent_sessions"]
          and r["cap_state"]["daily_points_used"] == 0)

    check("no SQLite/db file created or modified by the admin simulation",
          store_db_hash() == hash_before and len(list(SCRIPT_DIR.glob("*.db"))) == 1,
          [p.name for p in SCRIPT_DIR.glob("*.db")])
    server_src = (SCRIPT_DIR / "ui_sim_server.py").read_text()
    check("simulator store is in-memory (no sqlite/mysql module or db writes)",
          "import sqlite3" not in server_src
          and "from sqlite3" not in server_src
          and "sqlite3" not in server_src
          and "import pymysql" not in server_src
          and "import mysql" not in server_src
          and "MySQLdb" not in server_src,
          "found sqlite/mysql access")
    check("admin view flags simulation (synthetic identity only)",
          panel["simulation"] is True)


def main():
    print("=" * 70)
    print("  UI SIM admin-dashboard view regression")
    print("  (admin_ecopoint.php structure over the shared in-memory store)")
    print("=" * 70)

    hash_before = store_db_hash()
    srv, store = sim.start_server()
    base = "http://127.0.0.1:%d" % srv.server_address[1]
    try:
        scenario_served_contract(base)
        scenario_three_deposits(base, store)
        scenario_status_transition(base, store)
        scenario_duplicates_and_auto_update(base)
        scenario_error_session(store, base)
        scenario_reset_isolation(base, hash_before)
    finally:
        srv.shutdown()

    print("\n" + "=" * 70)
    print("RESULT: %d passed, %d failed" % (PASS, FAIL))
    print("=" * 70)
    sys.exit(0 if FAIL == 0 else 1)


if __name__ == "__main__":
    main()