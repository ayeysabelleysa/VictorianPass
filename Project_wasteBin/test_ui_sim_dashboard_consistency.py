#!/usr/bin/env python3
"""Regression test: VHEcoPoint resident dashboard CONSISTENCY.

Guards the reported dashboard inconsistency where, after a recycling visit,
the KPI cards advanced (balance / weekly / daily points) but the
"Daily Sessions Used" number, the Weekly Material Cap Tracker and the Points
History stayed at 0, and the open-session item line showed "@ 0 pts".

The fix has three parts, all asserted here:
  1. The real dashboard JS falls back to `points_calculated` for an item while
     the session is still open (points are only awarded at finalize).
  2. The "X / 3 Used Today" number is refreshed live from the same cap_state
     the cards use, and the weekly tracker + history are refreshed from a
     consolidated `dashboard` block in the SSE/poll snapshot.
  3. A resident early-end goes through the SAME finalize path as a normal
     completion, so the visit is counted once and the ledger, tracker and
     history all advance together. A visit is never counted twice.
  4. The dashboard JS defines the `escapeHtml` helper used by the
     mixed-material render path (inside the IIFE, before its call site, and
     mapping every HTML-significant character). When no JS runtime is
     available this is a SOURCE-LEVEL STRUCTURAL CHECK, not a runtime test.

Drives the real simulation server over HTTP. No production API, database,
hardware or network is touched.
"""

import json
import pathlib
import shutil
import subprocess
import sys
import urllib.error
import urllib.request

SCRIPT_DIR = pathlib.Path(__file__).resolve().parent
sys.path.insert(0, str(SCRIPT_DIR))

import ui_sim_server as sim  # noqa: E402

PASS = 0
FAIL = 0

REAL_JS = SCRIPT_DIR.parent / "js" / "ecopoint_dashboard.js"


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


def snapshot(base):
    _, body = http_get(base, "/api/ecopoint_session_status.php")
    return json.loads(body)


def consistency_errors(snap):
    """Every dashboard section must agree with the snapshot's source of truth."""
    errs = []
    cap = snap.get("cap_state") or {}
    dash = snap.get("dashboard")
    if dash is None:
        return ["snapshot.dashboard block is missing"]
    if int(cap.get("daily_sessions_used", -1)) != int(dash.get("today_sessions_used", -2)):
        errs.append("cap.daily_sessions_used=%s != dashboard.today_sessions_used=%s"
                    % (cap.get("daily_sessions_used"), dash.get("today_sessions_used")))
    if int(round(float(cap.get("weekly_points_used", -1)))) != int(dash.get("weekly_points", -2)):
        errs.append("cap.weekly_points_used=%s != dashboard.weekly_points=%s"
                    % (cap.get("weekly_points_used"), dash.get("weekly_points")))
    if int(round(float(cap.get("daily_sessions_left", -1)))) != max(
            0, 3 - int(dash.get("today_sessions_used", 0))):
        errs.append("cap.daily_sessions_left=%s != 3 - dashboard.today_sessions_used"
                    % cap.get("daily_sessions_left"))
    vals = dash.get("weekly_values") or {}
    got = sum(int(v.get("points", 0)) for v in vals.values())
    if got != int(dash.get("weekly_points", -3)):
        errs.append("sum(weekly_values.points)=%s != dashboard.weekly_points=%s"
                    % (got, dash.get("weekly_points")))
    if int(dash.get("sessions_remaining", -1)) != max(0, 3 - int(dash.get("today_sessions_used", 0))):
        errs.append("dashboard.sessions_remaining inconsistent")
    if int(round(float(snap.get("current_balance", -1)))) < 0:
        errs.append("negative balance")
    return errs


def weekly_value(dash, label):
    return (dash.get("weekly_values") or {}).get(label, {"points": 0, "weight": 0})


def scenario_served_contract(base):
    print("\n-- served dashboard contract (ids + JS live bindings) --")
    _, dash = http_get(base, "/dashboard")
    for eid in ("ecopoint-sessions-used", "ecopoint-weekly-progress",
                "ecopoint-weekly-progress-bar",
                "ecopoint-weekly-material-plastic-points",
                "ecopoint-weekly-material-plastic-weight",
                "ecopoint-weekly-material-plastic-bar",
                "ecopoint-weekly-material-aluminum-points",
                "ecopoint-weekly-material-paper-points",
                "ecopoint-history-container",
                "ecopoint-live-cap-note"):
        check("dashboard markup exposes id=%s" % eid, ('id="%s"' % eid) in dash)

    js = REAL_JS.read_text(encoding="utf-8", errors="replace")
    check("real JS falls back to item.points_calculated while a session is open",
          "item.points_awarded || item.points_calculated" in js.replace(" ", "")
          or "item.points_calculated" in js)
    check("real JS refreshes the sessions-used number live",
          "ecopoint-sessions-used" in js)
    check("real JS refreshes the weekly tracker from snapshot.dashboard",
          "updateWeeklyMaterialTracker" in js and "snapshot.dashboard" in js)
    check("real JS refreshes the points history from snapshot.dashboard",
          "updatePointsHistory" in js and "ecopoint-history-container" in js)
    check("real JS renders the projected (capped) points, not the raw total",
          "estimated_points" in js and "updateLiveCapNote" in js
          and "ecopoint-live-cap-note" in js)


def scenario_live_points_capped(base):
    print("\n-- live 'Current points' shows the eligible (capped) award, 480 g --")
    http_post(base, "/sim/reset", {})

    # Fresh resident: 480 g Plastic = 145.44 raw > 100 daily allowance.
    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    http_post(base, "/sim/submit", {"session_token": token, "material": "Plastic",
                                    "weight_kg": 0.48, "transaction_id": "cons-480-a"})
    snap = snapshot(base)
    act = snap["active_session"]
    check("raw live total is 145.44 (uncapped), as today",
          abs(float(act["total_points"]) - 145.44) < 0.01
          and abs(float(act["points_raw"]) - 145.44) < 0.01, act)
    check("live estimated_points caps 145.44 -> 100 (daily allowance)",
          abs(float(act["estimated_points"]) - 100.0) < 0.01, act)
    check("cap_state reports the authoritative remaining allowance (100 left)",
          snap["cap_state"]["daily_points_left"] == 100
          and snap["cap_state"]["daily_points_used"] == 0, snap["cap_state"])
    check("snapshot is internally consistent", not consistency_errors(snap),
          consistency_errors(snap))

    _, c = http_post(base, "/sim/complete", {"session_token": token})
    check("finalization awards exactly the capped 100 (145.44 raw)",
          c.get("points_awarded") == 100, c)
    snap = snapshot(base)
    check("balance after finalize is 100 and history shows one capped row",
          snap["current_balance"] == 100
          and len(snap["dashboard"]["history"]) == 1
          and snap["dashboard"]["history"][0]["amount"] == 100)

    print("\n-- resident who already earned points today sees a smaller allowance --")
    # A prior 120 g Plastic session (36 pts) consumed part of today's allowance.
    http_post(base, "/sim/reset", {})
    _, v_prior = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token_prior = v_prior["session_token"]
    http_post(base, "/sim/submit", {"session_token": token_prior, "material": "Plastic",
                                    "weight_kg": 0.12, "transaction_id": "cons-480-prior"})
    http_post(base, "/sim/complete", {"session_token": token_prior})
    snap = snapshot(base)
    check("after earning 36 today: balance 36, allowance 64",
          snap["current_balance"] == 36
          and snap["cap_state"]["daily_points_used"] == 36
          and snap["cap_state"]["daily_points_left"] == 64, snap["cap_state"])

    _, v2 = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token2 = v2["session_token"]
    http_post(base, "/sim/submit", {"session_token": token2, "material": "Plastic",
                                    "weight_kg": 0.48, "transaction_id": "cons-480-b"})
    snap = snapshot(base)
    act = snap["active_session"]
    check("raw total 145.44 is projected to only 64 (100 - 36 earned today)",
          abs(float(act["total_points"]) - 145.44) < 0.01
          and abs(float(act["estimated_points"]) - 64.0) < 0.01, act)
    _, c2 = http_post(base, "/sim/complete", {"session_token": token2})
    check("finalize awards exactly 64 for the 480 g deposit",
          c2.get("points_awarded") == 64, c2)
    snap = snapshot(base)
    check("balance becomes 100 and history shows two capped rows",
          snap["current_balance"] == 100
          and len(snap["dashboard"]["history"]) == 2)


def scenario_completed(base, store):
    print("\n-- normal completion advances every section together --")
    http_post(base, "/sim/reset", {})
    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    http_post(base, "/sim/submit", {"session_token": token, "material": "Plastic",
                                    "weight_kg": 0.12, "transaction_id": "cons-A"})
    snap = snapshot(base)
    item = snap["active_session"]["waste_items"][0]
    check("open-session item exposes points_calculated (points_awarded still 0)",
          abs(float(item["points_calculated"]) - 36.36) < 0.001
          and int(item["points_awarded"]) == 0, item)

    _, c = http_post(base, "/sim/complete", {"session_token": token})
    snap = snapshot(base)
    check("complete awards 36 (capped from raw 36.36)", c.get("points_awarded") == 36, c)
    check("snapshot is internally consistent", not consistency_errors(snap),
          consistency_errors(snap))
    check("completed session counts once",
          snap["cap_state"]["daily_sessions_used"] == 1
          and snap["dashboard"]["today_sessions_used"] == 1)
    check("tracker rows reflect the recorded material",
          weekly_value(snap["dashboard"], "Plastic Bottles")["points"] == 36
          and abs(weekly_value(snap["dashboard"], "Plastic Bottles")["weight"] - 0.12) < 0.001)
    check("points history has exactly one earn row",
          len(snap["dashboard"]["history"]) == 1
          and snap["dashboard"]["history"][0]["amount"] == 36)


def scenario_early_end(base, store):
    print("\n-- resident early-end counts once and awards recorded items --")
    http_post(base, "/sim/reset", {})
    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    http_post(base, "/sim/submit", {"session_token": token, "material": "Aluminum",
                                    "weight_kg": 0.15, "transaction_id": "cons-B"})
    s, e = http_post(base, "/api/ecopoint_end_session.php", {})
    check("early end succeeds and finalizes the session",
          s == 200 and e.get("success") and e.get("session_status") == "COMPLETED", (s, e))
    check("early end awards the recorded 150 g Aluminum (45 pts)",
          e.get("awarded_points") in (45, 45.0), e)
    snap = snapshot(base)
    check("early-ended visit is counted exactly once",
          snap["cap_state"]["daily_sessions_used"] == 1
          and snap["dashboard"]["today_sessions_used"] == 1, snap["cap_state"])
    check("early-ended visit shows in tracker + history",
          weekly_value(snap["dashboard"], "Aluminum Cans")["points"] == 45
          and len(snap["dashboard"]["history"]) == 1)
    check("snapshot is internally consistent after early end",
          not consistency_errors(snap), consistency_errors(snap))

    s2, e2 = http_post(base, "/api/ecopoint_end_session.php", {})
    check("ending again (no active session) is rejected, no double count",
          s2 == 400 and not e2.get("success"), (s2, e2))
    snap = snapshot(base)
    check("session count unchanged after the rejected second end",
          snap["cap_state"]["daily_sessions_used"] == 1)


def scenario_zero_deposit_early_end(base):
    print("\n-- zero-deposit early-end still consumes exactly one visit --")
    http_post(base, "/sim/reset", {})
    http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    s, e = http_post(base, "/api/ecopoint_end_session.php", {})
    snap = snapshot(base)
    check("zero-deposit end finalizes with 0 awarded",
          s == 200 and e.get("awarded_points") == 0, (s, e))
    check("zero-deposit visit counts once, no history row, no balance",
          snap["cap_state"]["daily_sessions_used"] == 1
          and snap["dashboard"]["today_sessions_used"] == 1
          and len(snap["dashboard"]["history"]) == 0
          and snap["current_balance"] == 0)
    check("snapshot is internally consistent", not consistency_errors(snap),
          consistency_errors(snap))


def scenario_replay_no_double_count(base, store):
    print("\n-- replay / race cannot double count a finalized visit --")
    http_post(base, "/sim/reset", {})
    _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    token = v["session_token"]
    http_post(base, "/sim/submit", {"session_token": token, "material": "Plastic",
                                    "weight_kg": 0.12, "transaction_id": "cons-C"})
    http_post(base, "/api/ecopoint_end_session.php", {})
    # replay the completion endpoints against the now-terminal session
    s_complete, _ = http_post(base, "/sim/complete", {"session_token": token})
    s_end, _ = http_post(base, "/api/ecopoint_end_session.php", {})
    snap = snapshot(base)
    check("replayed complete/end are rejected",
          s_complete == 404 and s_end == 400, (s_complete, s_end))
    check("no double award, no double count",
          snap["cap_state"]["daily_sessions_used"] == 1
          and snap["current_balance"] == 36
          and len(snap["dashboard"]["history"]) == 1, snap["cap_state"])


def scenario_three_sessions_then_blocked(base):
    print("\n-- three visits fill the daily counter, a fourth is blocked --")
    http_post(base, "/sim/reset", {})
    erred = []
    for i, grams in enumerate((120, 110, 100)):
        _, v = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
        token = v["session_token"]
        http_post(base, "/sim/submit", {"session_token": token, "material": "Plastic",
                                        "weight_kg": grams / 1000.0,
                                        "transaction_id": "cons-3-%d" % i})
        http_post(base, "/api/ecopoint_end_session.php", {})
        snap = snapshot(base)
        if consistency_errors(snap):
            erred.append((i, consistency_errors(snap)))
    snap = snapshot(base)
    check("after 3 visits: sessions-used=3 and remaining=0",
          snap["cap_state"]["daily_sessions_used"] == 3
          and snap["dashboard"]["today_sessions_used"] == 3
          and snap["cap_state"]["daily_sessions_left"] == 0
          and snap["dashboard"]["sessions_remaining"] == 0)
    check("every intermediate snapshot was consistent", not erred, erred)
    s4, v4 = http_post(base, "/sim/verify", {"qr_code": sim.TEST_QR})
    check("a 4th visit is blocked (403 daily session cap)",
          s4 == 403 and not v4.get("success"), (s4, v4))


def scenario_ineligible_not_counted(base):
    print("\n-- an ineligible scan never touches the counters --")
    http_post(base, "/sim/reset", {})
    s, bad = http_post(base, "/sim/verify", {"qr_code": "VH-9999"})
    snap = snapshot(base)
    check("invalid QR rejected (404)", s == 404 and not bad.get("success"), (s, bad))
    check("no session counted, empty history, zero balance",
          snap["cap_state"]["daily_sessions_used"] == 0
          and snap["dashboard"]["today_sessions_used"] == 0
          and len(snap["dashboard"]["history"]) == 0
          and snap["current_balance"] == 0)
    check("snapshot is internally consistent", not consistency_errors(snap),
          consistency_errors(snap))


def _extract_js_function(js, name):
    """Return the text of a top-level `function <name>(...) { ... }`.

    A plain balanced-brace scan is exact here: the escapeHtml helper contains
    no braces inside string literals or its character-class regex.
    """
    start = js.find("function " + name + "(")
    if start == -1:
        return None
    open_brace = js.find("{", start)
    if open_brace == -1:
        return None
    depth = 0
    for i in range(open_brace, len(js)):
        if js[i] == "{":
            depth += 1
        elif js[i] == "}":
            depth -= 1
            if depth == 0:
                return js[start:i + 1]
    return None


def scenario_escapeHtml_helper(base):
    print("\n-- escapeHtml helper contract (mixed-material render path) --")
    js = REAL_JS.read_text(encoding="utf-8", errors="replace")

    iife_open = js.find("(function()")
    iife_close = js.rfind("})();")
    def_pos = js.find("function escapeHtml(")
    use_pos = js.find("escapeHtml(material.split")
    helper = _extract_js_function(js, "escapeHtml")

    check("real JS defines an escapeHtml helper", def_pos != -1)
    check("escapeHtml is declared inside the IIFE",
          iife_open != -1 and iife_close != -1 and iife_open < def_pos < iife_close)
    check("escapeHtml is declared before its mixed-material call site",
          def_pos != -1 and use_pos != -1 and def_pos < use_pos)

    # node/bun support `-e`; that is the runtime behaviour check below.
    runtime = shutil.which("node") or shutil.which("bun")
    if helper is None or runtime is None:
        # No JS runtime on this host, so this is a SOURCE-LEVEL STRUCTURAL
        # CHECK, not a runtime behavior test. It proves the extracted helper
        # text maps every HTML-significant character the mixed-material path
        # can emit (material names arrive from the server, not the DOM).
        print("  NOTE  no JS runtime (node/bun) available -- source-level "
              "structural check only, NOT a runtime behavior test")
        check("escapeHtml source maps &, <, >, \" and ' to HTML entities",
              helper is not None
              and "[&<>\"']" in helper
              and helper.count("&amp;") >= 1
              and helper.count("&lt;") >= 1
              and helper.count("&gt;") >= 1
              and helper.count("&quot;") >= 1
              and helper.count("&#39;") >= 1)
        return

    print("  NOTE  JS runtime %s found -- executing the extracted helper" % runtime)
    cases = [("&", "&amp;"), ("<", "&lt;"), (">", "&gt;"),
             ("\"", "&quot;"), ("'", "&#39;"),
             ("a&b<c>d\"e'f", "a&amp;b&lt;c&gt;d&quot;e&#39;f")]
    script = helper + "\n"
    script += "const cases = " + json.dumps(cases) + ";\n"
    script += ("let ok = true;\n"
               "for (const c of cases) {\n"
               "  const got = escapeHtml(c[0]);\n"
               "  if (got !== c[1]) { ok = false; console.error("
               "JSON.stringify(c[0]) + ' -> ' + JSON.stringify(got) + "
               "' expected ' + JSON.stringify(c[1])); }\n"
               "}\n"
               "process.exit(ok ? 0 : 1);\n")
    try:
        proc = subprocess.run([runtime, "-e", script],
                              capture_output=True, text=True, timeout=30)
        check("escapeHtml correctly escapes &, <, >, \" and ' at runtime",
              proc.returncode == 0, proc.stderr.strip())
    except (OSError, subprocess.SubprocessError) as exc:
        print("  NOTE  could not execute %s (%s) -- source-level structural "
              "check only" % (runtime, exc))
        check("escapeHtml source maps &, <, >, \" and ' to HTML entities",
              "[&<>\"']" in helper
              and all(tok in helper
                      for tok in ("&amp;", "&lt;", "&gt;", "&quot;", "&#39;")))


def main():
    print("=" * 70)
    print("  UI SIM dashboard-consistency regression")
    print("  (sessions-used / material tracker / points history / per-item pts)")
    print("=" * 70)

    srv, store = sim.start_server()
    base = "http://127.0.0.1:%d" % srv.server_address[1]
    try:
        scenario_served_contract(base)
        scenario_escapeHtml_helper(base)
        scenario_completed(base, store)
        scenario_early_end(base, store)
        scenario_zero_deposit_early_end(base)
        scenario_replay_no_double_count(base, store)
        scenario_three_sessions_then_blocked(base)
        scenario_ineligible_not_counted(base)
        scenario_live_points_capped(base)
    finally:
        srv.shutdown()

    print("\n" + "=" * 70)
    print("RESULT: %d passed, %d failed" % (PASS, FAIL))
    print("=" * 70)
    sys.exit(0 if FAIL == 0 else 1)


if __name__ == "__main__":
    main()
