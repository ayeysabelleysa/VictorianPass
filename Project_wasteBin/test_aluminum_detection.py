#!/usr/bin/env python3
"""Tests for configurable aluminum-can detection.

Two layers are covered:

  A. The pure decision policy in ``aluminum_detection.py`` (no hardware,
     no YOLO, no network). This is where the mode semantics live:
     inductive (default), camera, and hybrid, plus the "verified
     aluminum-can class" rule.

  B. The real ``Main.process_item`` integration, with the hardware
     boundary stubbed (Servo / camera / inductive / load cell / QR) so
     the module imports on a non-Raspberry-Pi host. These prove that:
       * the default (inductive) mode is byte-for-byte unchanged:
         a camera aluminum reading is ignored and aluminum is only
         accepted from a stable inductive reading;
       * camera mode accepts a confirmed, confident aluminum detection
         even though the inductive sensor never activates;
       * a low-confidence or unstable camera detection is rejected and
         never actuates or earns points;
       * unknown/uncertain items still do not actuate;
       * the M5 durable pre-actuation record is still committed BEFORE
         the servo is commanded.

Time is virtualised through offline_queue.NOW and the queue is written
to a scratch SQLite database under the system temp dir; the repository
vheco_local.db is never opened.

Run from anywhere:
    python3 Project_wasteBin/test_aluminum_detection.py
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

import aluminum_detection as AD  # noqa: E402

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


# =====================================================================
# A. PURE POLICY
# =====================================================================
print("== A. aluminum_detection policy ==")

# A1 mode normalization
check("A1a unset mode falls back to inductive",
      AD.normalize_mode(None) == AD.MODE_INDUCTIVE)
check("A1b unknown mode falls back to inductive",
      AD.normalize_mode("bogus") == AD.MODE_INDUCTIVE)
check("A1c mode parsing is case/space insensitive",
      AD.normalize_mode("  CAMERA ") == AD.MODE_CAMERA)
check("A1d valid hybrid accepted",
      AD.normalize_mode("hybrid") == AD.MODE_HYBRID)

# A2 verified aluminum-can classes only
check("A2a 'aluminum' is a verified class",
      AD.aluminum_supported(["aluminum"]) is True)
check("A2b 'can' is a verified class",
      AD.aluminum_supported(["bottle", "can"]) is True)
check("A2c generic 'metal' is NOT a verified class",
      AD.aluminum_supported(["metal"]) is False)
check("A2d generic 'cup' is NOT a verified class",
      AD.aluminum_supported(["cup"]) is False)
check("A2e COCO-class model (no aluminum) is unsupported",
      AD.aluminum_supported(["bottle", "book", "cup", "wine glass"]) is False)

# A3 inductive (default) mode
_d = AD.resolve_aluminum(
    AD.MODE_INDUCTIVE,
    {"supported": True, "confirmed": True,
     "material": "Aluminum", "confidence": 0.99},
    {"active": True, "stable": True},
)
check("A3a default mode accepts stable inductive metal",
      _d.accepted and _d.source == "inductive", _d)

_d = AD.resolve_aluminum(
    AD.MODE_INDUCTIVE,
    {"supported": True, "confirmed": True,
     "material": "Aluminum", "confidence": 0.99},
    {"active": False, "stable": False},
)
check("A3b default mode ignores a camera-only aluminum reading",
      not _d.accepted, _d)

_d = AD.resolve_aluminum(
    AD.MODE_INDUCTIVE,
    {"supported": False, "confirmed": False, "material": None, "confidence": 0.0},
    {"active": True, "stable": False},
)
check("A3c unstable inductive metal is not accepted",
      not _d.accepted, _d)

# A4 camera mode
_d = AD.resolve_aluminum(
    AD.MODE_CAMERA,
    {"supported": True, "confirmed": True,
     "material": "Aluminum", "confidence": 0.9},
    {"active": False, "stable": False},
)
check("A4a camera mode accepts confident confirmed aluminum "
      "with inductive inactive",
      _d.accepted and _d.source == "camera", _d)

_d = AD.resolve_aluminum(
    AD.MODE_CAMERA,
    {"supported": True, "confirmed": True,
     "material": "Aluminum", "confidence": 0.05},
    {"active": False, "stable": False},
    {"min_camera_confidence": 0.5},
)
check("A4b camera mode rejects a low-confidence detection",
      not _d.accepted, _d)

_d = AD.resolve_aluminum(
    AD.MODE_CAMERA,
    {"supported": True, "confirmed": False,
     "material": "Aluminum", "confidence": 0.9},
    {"active": False, "stable": False},
)
check("A4c camera mode rejects an unstable detection",
      not _d.accepted, _d)

_d = AD.resolve_aluminum(
    AD.MODE_CAMERA,
    {"supported": False, "confirmed": True,
     "material": "Aluminum", "confidence": 0.9},
    {"active": True, "stable": True},
)
check("A4d camera mode is unsupported when the model lacks the class",
      _d.status == "unsupported", _d)

# A5 hybrid mode
_d = AD.resolve_aluminum(
    AD.MODE_HYBRID,
    {"supported": True, "confirmed": True,
     "material": "Aluminum", "confidence": 0.9},
    {"active": False, "stable": False},
)
check("A5a hybrid accepts a confident camera detection alone",
      _d.accepted and _d.source == "camera", _d)

_d = AD.resolve_aluminum(
    AD.MODE_HYBRID,
    {"supported": False, "confirmed": False, "material": None, "confidence": 0.0},
    {"active": True, "stable": True},
)
check("A5b hybrid accepts a stable inductive detection alone",
      _d.accepted and _d.source == "inductive", _d)

_d = AD.resolve_aluminum(
    AD.MODE_HYBRID,
    {"supported": True, "confirmed": True,
     "material": "Paper", "confidence": 0.9},
    {"active": True, "stable": True},
)
check("A5c hybrid rejects contradictory confident camera/metal evidence",
      _d.status == "reject", _d)

_d = AD.resolve_aluminum(
    AD.MODE_HYBRID,
    {"supported": True, "confirmed": False, "material": None, "confidence": 0.0},
    {"active": False, "stable": False},
)
check("A5d hybrid with no evidence is inconclusive",
      not _d.accepted and _d.status == "inconclusive", _d)


# =====================================================================
# B. process_item INTEGRATION (hardware stubbed)
# =====================================================================
print("== B. process_item integration ==")

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

_TMP = tempfile.mkdtemp(prefix="aluminum_detection_")
_OLD_DB = oq.DB_FILE
oq.DB_FILE = os.path.join(_TMP, "queue.db")
oq.init_db()

FAKE_NOW = [1000.0]
oq.NOW = lambda: FAKE_NOW[0]


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
        "SELECT transaction_id, material, status, actuation_state"
        " FROM pending_transactions WHERE transaction_id = ?",
        (txid,),
    ).fetchone()
    c.close()
    return dict(r) if r else None


# Spy: capture the transaction id as it is committed so the servo stub
# can prove the durable row already existed (ACTUATING) before sorting.
STATE = {}
_orig_begin = oq.begin_actuation


def _capture_begin(txid, *args, **kwargs):
    _orig_begin(txid, *args, **kwargs)
    STATE["txid"] = txid


oq.begin_actuation = _capture_begin
M.begin_actuation = _capture_begin


def install(camera_material=None, evidence=None, metal=False):
    STATE["notified"] = False
    STATE["sort_calls"] = []
    STATE.pop("row_at_sort", None)
    STATE.pop("txid", None)

    M.reset_camera = lambda: None
    M.notify_unrecognized_item = lambda: STATE.__setitem__("notified", True)
    M.wait_for_item = lambda: 500.0
    M.get_camera_material = lambda: camera_material
    M.camera_evidence = lambda: dict(evidence or {})
    M.wait_for_weight_stable = lambda: 500.0
    M.wait_for_removal = lambda: True
    M.metal_detected = lambda: metal

    def _sort(material):
        STATE["sort_calls"].append(material)
        STATE["row_at_sort"] = row_by_tx(STATE.get("txid", "?"))
        return True

    M.Servo.sort_material = _sort


def set_mode(mode, min_confidence=None):
    M.ALUMINUM_DETECTION_MODE = mode
    cfg = {"min_camera_confidence": AD.DEFAULT_MIN_CAMERA_CONFIDENCE}
    if min_confidence is not None:
        cfg["min_camera_confidence"] = min_confidence
    M.ALUMINUM_CONFIG = cfg


_ALUM_EVIDENCE = {
    "aluminum_supported": True,
    "confirmed": True,
    "material": "Aluminum",
    "material_confidence": 0.9,
}

# B1: camera mode, confident aluminum, inductive inactive -> sorted.
reset_queue()
FAKE_NOW[0] = 2000.0
install(camera_material="Aluminum", evidence=_ALUM_EVIDENCE, metal=False)
set_mode(AD.MODE_CAMERA)
res = M.process_item("tok-A", "VH-1003")
check("B1a camera aluminum is accepted and returns a 4-tuple",
      isinstance(res, tuple) and len(res) == 4, res)
if isinstance(res, tuple) and len(res) == 4:
    weight, points, material, txid = res
    check("B1b material is aluminum", material == "aluminum", material)
    check("B1c points are weight * 0.303",
          abs(points - weight * 0.303) < 1e-6, (weight, points))
    check("B1d the servo was commanded with aluminum",
          STATE["sort_calls"] == ["aluminum"], STATE["sort_calls"])
    row = row_by_tx(txid)
    check("B1e the row is PENDING and ACTUATED",
          row is not None and row["status"] == "PENDING"
          and row["actuation_state"] == oq.ACTUATION_COMPLETED, row)
    seen = STATE.get("row_at_sort")
    check("B1f the durable row existed (ACTUATING) before the servo ran",
          seen is not None and seen["actuation_state"] == oq.ACTUATION_STARTED,
          seen)

# B2: camera mode, low confidence -> rejected, no actuation, no points.
reset_queue()
FAKE_NOW[0] = 2100.0
_low = dict(_ALUM_EVIDENCE, material_confidence=0.05)
install(camera_material="Aluminum", evidence=_low, metal=False)
set_mode(AD.MODE_CAMERA, min_confidence=0.5)
res = M.process_item("tok-A", "VH-1003")
check("B2a low-confidence camera aluminum -> REJECTED", res == "REJECTED", res)
check("B2b servo was NOT commanded", STATE["sort_calls"] == [])
check("B2c the popup was raised", STATE["notified"] is True)
check("B2d no queue row was left behind",
      len(oq.get_pending_transactions()) == 0)

# B3a: DEFAULT mode, camera supports aluminum but inductive is inactive.
reset_queue()
FAKE_NOW[0] = 2200.0
install(camera_material="Aluminum", evidence=_ALUM_EVIDENCE, metal=False)
set_mode(AD.MODE_INDUCTIVE)
res = M.process_item("tok-A", "VH-1003")
check("B3a default mode ignores camera aluminum -> REJECTED",
      res == "REJECTED", res)
check("B3b servo was NOT commanded", STATE["sort_calls"] == [])
check("B3c no queue row was left behind",
      len(oq.get_pending_transactions()) == 0)

# B3b: DEFAULT mode, inductive metal present -> still accepted.
reset_queue()
FAKE_NOW[0] = 2300.0
install(camera_material="Aluminum", evidence=_ALUM_EVIDENCE, metal=True)
set_mode(AD.MODE_INDUCTIVE)
res = M.process_item("tok-A", "VH-1003")
check("B3d default mode still accepts stable inductive aluminum",
      isinstance(res, tuple) and res[2] == "aluminum", res)
check("B3e servo was commanded with aluminum",
      STATE["sort_calls"] == ["aluminum"], STATE["sort_calls"])

# B4: camera mode, inductive metal but NO camera confirmation -> rejected.
reset_queue()
FAKE_NOW[0] = 2400.0
install(camera_material=None, evidence={}, metal=True)
set_mode(AD.MODE_CAMERA)
res = M.process_item("tok-A", "VH-1003")
check("B4a camera mode does not let the inductive sensor classify alone",
      res == "REJECTED", res)
check("B4b servo was NOT commanded", STATE["sort_calls"] == [])

# B5: hybrid mode, camera unavailable but inductive stable -> accepted.
reset_queue()
FAKE_NOW[0] = 2500.0
install(camera_material=None, evidence={}, metal=True)
set_mode(AD.MODE_HYBRID)
res = M.process_item("tok-A", "VH-1003")
check("B5a hybrid accepts stable inductive aluminum",
      isinstance(res, tuple) and res[2] == "aluminum", res)
check("B5b servo was commanded with aluminum",
      STATE["sort_calls"] == ["aluminum"], STATE["sort_calls"])

# B6: unknown item (no camera, no metal) -> rejected, never actuates.
reset_queue()
FAKE_NOW[0] = 2600.0
install(camera_material=None, evidence={}, metal=False)
set_mode(AD.MODE_HYBRID)
res = M.process_item("tok-A", "VH-1003")
check("B6a unknown item -> REJECTED", res == "REJECTED", res)
check("B6b the popup was raised", STATE["notified"] is True)
check("B6c servo was NOT commanded", STATE["sort_calls"] == [])
check("B6d no queue row was left behind",
      len(oq.get_pending_transactions()) == 0)

# B7: Plastic/Paper handling is unchanged in every mode.
for _mode in (AD.MODE_INDUCTIVE, AD.MODE_CAMERA, AD.MODE_HYBRID):
    reset_queue()
    FAKE_NOW[0] = 2700.0
    install(camera_material="Plastic", evidence={}, metal=False)
    set_mode(_mode)
    res = M.process_item("tok-A", "VH-1003")
    check(f"B7 plastic still sorts in {_mode} mode",
          isinstance(res, tuple) and res[2] == "plastic"
          and STATE["sort_calls"] == ["plastic"], (res, STATE["sort_calls"]))

# =====================================================================
print("=" * 60)
print(f"RESULT: {PASS} passed, {FAIL} failed")
print("=" * 60)

oq.DB_FILE = _OLD_DB
sys.exit(0 if FAIL == 0 else 1)
