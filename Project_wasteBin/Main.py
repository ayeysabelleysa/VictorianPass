import json
import time
import uuid
import requests
import os
import subprocess
import sys

from offline_queue import (
    init_db,
    save_transaction,
    get_pending_transactions,
    has_pending_transactions,
    get_session_sync_summary,
    is_session_finalized,
    mark_session_finalized,
    mark_synced,
    mark_failed,
    mark_failed_permanent,
    MAX_RETRY_COUNT,
    is_retry_due,
    begin_actuation,
    mark_actuation_started,
    mark_actuation_completed,
    mark_actuation_abandoned,
    mark_actuation_uncertain,
    recover_actuation_states,
    get_uncertain_transactions,
    is_submittable,
    transaction_exists
)

from qr_scanner import (
    start_scanner,
    read_qr,
    is_victorianpass,
    extract_resident_code,
    close_scanner
)

from Weight import (
    start_weight,
    get_weight,
    measure_incentive,
    close_weight
)

from inductive import (
    start_inductive,
    metal_detected,
    wait_for_metal_stable,
    close_inductive
)

import Servo

import aluminum_detection as ALUMINUM


# =========================================================
# SETTINGS
# =========================================================

CAMERA_URL = "http://127.0.0.1:5000/status"
CAMERA_RESET_URL = "http://127.0.0.1:5000/reset"
CAMERA_UNRECOGNIZED_URL = "http://127.0.0.1:5000/unrecognized"

# CameraYolo.py and its relative resources (yolo11n.pt) must
# resolve no matter which directory Main.py was started from.
SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
CAMERA_SCRIPT = os.path.join(SCRIPT_DIR, "CameraYolo.py")

camera_process = None


def start_camera_process():
    """
    Launch CameraYolo.py with the SAME interpreter that is
    running Main.py (sys.executable), so the project .venv is
    always used instead of whatever "python3" happens to be
    first on PATH.
    """
    global camera_process

    camera_process = subprocess.Popen(
        [sys.executable, CAMERA_SCRIPT],
        cwd=SCRIPT_DIR
    )

    print(
        f"Camera service started "
        f"(pid {camera_process.pid})."
    )

    return camera_process


def stop_camera_process():
    """
    Terminate the CameraYolo child and make sure it cannot
    survive as an orphan holding port 5000.
    """
    global camera_process

    if camera_process is None:
        return

    try:
        camera_process.terminate()

        try:
            camera_process.wait(timeout=5)

        except subprocess.TimeoutExpired:
            print(
                "Camera service did not stop in time. "
                "Killing it."
            )

            camera_process.kill()
            camera_process.wait(timeout=5)

    except Exception as e:
        print(f"Camera service cleanup error: {e}")

    finally:
        camera_process = None

API_BASE_URL = "https://deeppink-wren-292489.hostingersite.com/api"

QR_API = (
    f"{API_BASE_URL}/qr_verify_and_create_session.php"
)

SUBMIT_WASTE_API = (
    f"{API_BASE_URL}/submit_waste_data.php"
)

COMPLETE_API = (
    f"{API_BASE_URL}/complete_session.php"
)

CANCEL_API = (
    f"{API_BASE_URL}/cancel_session.php"
)

# =============================================================
# RECOGNIZED ENDPOINT ERROR CONTRACT
#
# The PHP endpoints answer every rejection of their own as JSON with
# {"success": false, "message": "..."} and never as a web-server
# error page. A session-level 404 is only trusted as "already
# finalized / ineligible / not owned" when the message matches the
# endpoint's own contract below; any other 404 body is treated as
# ambiguous and retried. Constants must stay in sync with the PHP.
# =============================================================
COMPLETE_NOT_FOUND_MESSAGE = (
    "Session not found or not eligible for completion"
)
SUBMIT_NOT_FOUND_MESSAGE = (
    "Session not found, already finalized, or does not belong "
    "to this station"
)

API_KEY = os.getenv("VHECO_API_KEY")

STATION_ID = "VH-ECO-001"

API_HEADERS = {
    "Content-Type": "application/json",
    "X-VHECO-Station": STATION_ID,
    "X-VHECO-Api-Key": API_KEY
}


MIN_WEIGHT = 6.0
MAX_WEIGHT = 2000.0
WEIGHT_STABLE_TIME = 0.5
METAL_STABLE_TIME = 0.5
MAX_SESSIONS = 3
DAILY_POINT_CAP = 100
CAMERA_TIMEOUT = 0.5

# CameraYolo needs roughly STABLE_TIME (3.0s) of continuous
# dominant detection PLUS at least one inference cycle
# (DETECTION_INTERVAL 0.6s) before it reports
# confirmed = true. A 3.0s poll therefore always timed out
# and every plastic/paper item fell through to the inductive
# sensor, which then rejected it. 8.0s gives it room to settle.
CAMERA_CLASSIFICATION_TIMEOUT = 8.0

# Resident session timeout.
# If no item is placed for this many seconds,
# only the resident session ends.
IDLE_TIMEOUT = 90

REMOVAL_CHECK_INTERVAL = 0.15

# Maximum time to wait for the user to take an item off the
# platform. The station must NEVER block forever here.
REMOVAL_TIMEOUT = 45.0

# A platform is only considered clear after this many
# consecutive clear samples (3 x 0.15 s ~= 0.45 s) so a
# single noisy/failed reading cannot fake a removal.
REMOVAL_CLEAR_SAMPLES = 3

# Live REMOVE status is printed at most once per second.
REMOVAL_STATUS_INTERVAL = 1.0

# Maximum time to wait for a stable weight before giving up.
WEIGHT_STABLE_TIMEOUT = 10.0


# =========================================================
# ALUMINUM DETECTION POLICY
# =========================================================
#
# How the station decides an item is an aluminum can:
#
#   "inductive"  (default) - aluminum ONLY when the inductive
#                 sensor reports stable metal. This is the
#                 station's original behavior, so an unset or
#                 invalid configuration changes nothing.
#   "camera"     - aluminum from a confirmed, sufficiently
#                 confident camera detection of a verified
#                 aluminum-can class. The inductive sensor need
#                 NOT activate.
#   "hybrid"     - either a confident camera detection OR a
#                 stable inductive detection is enough.
#
# Plastic, Paper and Cardboard handling is never affected by the
# mode; only the aluminum edge is governed by this policy.
ALUMINUM_DETECTION_MODE = ALUMINUM.normalize_mode(
    os.getenv("VHECO_ALUMINUM_MODE", ALUMINUM.DEFAULT_MODE)
)

# Optional camera-confidence floor for accepting an aluminum
# detection. The default equals the camera service's own global
# confidence threshold, so no global camera threshold changes.
try:
    _ALUMINUM_MIN_CAMERA_CONFIDENCE = float(
        os.getenv(
            "VHECO_ALUMINUM_MIN_CONFIDENCE",
            ALUMINUM.DEFAULT_MIN_CAMERA_CONFIDENCE,
        )
    )
except (TypeError, ValueError):
    _ALUMINUM_MIN_CAMERA_CONFIDENCE = (
        ALUMINUM.DEFAULT_MIN_CAMERA_CONFIDENCE
    )

ALUMINUM_CONFIG = {
    "min_camera_confidence": _ALUMINUM_MIN_CAMERA_CONFIDENCE,
}


# =========================================================
# CAMERA
# =========================================================

def camera_status():
    try:
        r = requests.get(
            CAMERA_URL,
            timeout=CAMERA_TIMEOUT
        )

        if r.status_code == 200:
            return r.json()

    except Exception:
        pass

    return {}


def notify_unrecognized_item():
    try:
        requests.post(
            CAMERA_UNRECOGNIZED_URL,
            timeout=CAMERA_TIMEOUT
        )
    except Exception:
        pass


def reset_camera():
    """
    Clear the camera's per-item state (confirmation, mixed
    flag, latched "Item Not Recognized" popup).

    Called at item boundaries only, never while a
    classification is in progress.
    """
    try:
        requests.post(
            CAMERA_RESET_URL,
            timeout=CAMERA_TIMEOUT
        )
    except Exception:
        pass


# Full /status dict from the most recent camera poll, used by the
# aluminum policy to read the model's aluminum capability and the
# detection confidence. Kept as a module global so tests (and the
# process_item flow) can inspect it without a second HTTP request.
_last_camera_status = {}


def _remember_camera_status(data):
    global _last_camera_status

    if isinstance(data, dict):
        _last_camera_status = dict(data)


def camera_evidence():
    """Last camera /status dict (empty when the camera never answered)."""
    return dict(_last_camera_status)


def _camera_aluminum_evidence(status, material):
    """Shape a camera /status dict into aluminum policy evidence."""
    if not isinstance(status, dict):
        status = {}

    return {
        "supported": bool(status.get("aluminum_supported")),
        "confirmed": bool(status.get("confirmed")),
        "material": material,
        "confidence": status.get("material_confidence"),
    }


# =========================================================
# API POST
# =========================================================

API_TIMEOUT = 10


def api_post_ex(url, data, timeout=None):
    """POST and return a structured outcome so callers can tell a
    transport failure (ambiguous — the server may or may not have
    processed the request) apart from a definite server answer.

    Returns:
        {
            "http_status":    int | None,
            "json":           dict | None,
            "transport_error": str | None,   # None = we got an HTTP response
        }
    """
    if timeout is None:
        timeout = API_TIMEOUT

    try:
        response = requests.post(
            url,
            headers=API_HEADERS,
            json=data,
            timeout=timeout
        )

        print(
            f"API {url.split('/')[-1]} "
            f"-> HTTP {response.status_code}"
        )

        print(
            "RAW SERVER RESPONSE:",
            repr(response.text)
        )

        try:
            payload = response.json()

        except Exception:
            print("Invalid JSON response from server.")
            payload = None

        return {
            "http_status": response.status_code,
            "json": payload if isinstance(payload, dict) else None,
            "transport_error": None
        }

    except requests.exceptions.Timeout:
        print("Hostinger API timeout.")
        return {
            "http_status": None,
            "json": None,
            "transport_error": "timeout"
        }

    except requests.exceptions.ConnectionError:
        print("Cannot connect to Hostinger.")
        return {
            "http_status": None,
            "json": None,
            "transport_error": "connection_error"
        }

    except Exception as e:
        print(f"API error: {e}")
        return {
            "http_status": None,
            "json": None,
            "transport_error": str(e)
        }


def api_post(url, data):
    info = api_post_ex(url, data)

    if info["http_status"] is None:
        return None

    if info["json"] is None:
        return None

    return info["json"]


# =========================================================
# CREATE HOSTINGER SESSION
# =========================================================

def create_api_session(qr_code):
    print()
    print("Verifying VictorianPass with Hostinger...")

    # =====================================================
    # READ THE RESIDENT HOUSE CODE FROM THE SCANNED QR
    #
    # extract_resident_code() already handles:
    #   - direct house codes            VH-0019
    #   - the website QR URL            ?code=VH-0019
    #   - uppercase query keys          ?CODE=VH-0019
    #   - extra query parameters        ?code=VH-0019&x=1
    #
    # The previous inline "CODE=" split was case sensitive
    # and sent the whole URL to the API, which never matched.
    # =====================================================

    resident_code = extract_resident_code(qr_code)

    print(
        "QR VALUE SENT TO API:",
        repr(resident_code)
    )

    # Fail cleanly instead of sending an unusable value.
    if not resident_code:
        print(
            "Could not read a resident house "
            "code from the QR."
        )

        return None

    result = api_post(
        QR_API,
        {
            "qr_code": resident_code
        }
    )

    if not result:
        print("No response from Hostinger.")
        return None

    if not result.get("success"):
        print(
            "QR verification failed:",
            result.get(
                "message",
                "Unknown error"
            )
        )

        return None

    session_token = result.get(
        "session_token"
    )

    print("EcoPoint Session ID:", result.get("session", {}).get("id"))

    if not session_token:
        print(
            "Hostinger did not return "
            "a session token."
        )

        return None

    resident = result.get(
        "resident",
        {}
    )

    # =====================================================
    # DISPLAY ACTUAL RESIDENT INFORMATION
    # FROM HOSTINGER
    # =====================================================

    print()
    print("--------------------------------")

    print(
        "Resident :",
        resident.get(
            "full_name",
            "Unknown"
        )
    )

    print(
        "Balance  :",
        resident.get(
            "balance",
            0
        )
    )

    print("--------------------------------")

    return {
        "session_token": session_token,
        "resident": resident,
        "qr_code": resident_code,
        "cap_state": result.get("cap_state", {})
    }


# =========================================================
# SUBMIT WASTE DATA
# =========================================================

def _recognized_app_rejection(http_status, result, not_found_message):
    """True only when the server body is a recognized PHP endpoint
    error for this request.

    The endpoints always answer their own rejections as JSON with
        {"success": false, "message": "..."}
    and never as a web-server error page or non-JSON body.

    - HTTP 404 is only trusted when the message matches the
      endpoint's own not-found / not-eligible / not-owned contract.
      A generic HTML 404, malformed JSON, or an unexpected JSON 404
      carries no evidence that the request can never succeed, so it
      is never treated as terminal.
    - Other 4xx statuses (400/401/403/405) are trusted when they
      carry the endpoint's success:false + message envelope, which
      the PHP code sets for every rejection it emits.

    Retryable-on-mismatch is the safe direction: if the endpoint
    contract ever changes, the station retries instead of retiring a
    session or permanently failing an item.
    """
    if not isinstance(result, dict):
        return False

    if result.get("success") is not False:
        return False

    message = result.get("message")
    if not isinstance(message, str) or message.strip() == "":
        return False

    if not (400 <= http_status < 500):
        return False

    if http_status == 404:
        return message.strip() == not_found_message

    return True


def _classify_submit_failure(info):
    """Decide whether a failed submit is worth retrying later.

    retryable = True  -> transport failure, 5xx, or any response that
                         carries no reliable evidence the attempt can
                         never succeed (generic or non-JSON 4xx,
                         malformed JSON): safe to retry because the
                         server de-duplicates on transaction_id.
    retryable = False -> the PHP endpoint answered definitively with
                         its own JSON error contract: invalid payload
                         or an expired/finalized/missing session;
                         sending the identical payload again can
                         never succeed.

    A body is only trusted as definitive when it matches this
    endpoint's recognized error contract (see
    _recognized_app_rejection). An unexpected or non-JSON 4xx must
    not permanently FAIL an item.
    """
    if info["transport_error"] is not None:
        return True

    status = info["http_status"]

    if status is None:
        return True

    if info["json"] is None:
        # Non-JSON body (proxy/gateway error page, etc.).
        return True

    if status >= 500:
        return True

    if not isinstance(info["json"], dict):
        return True

    # Every recognized endpoint rejection (400/401/403/404/405) is
    # definitive for this payload; a generic or unexpected 4xx is not.
    return not _recognized_app_rejection(
        status,
        info["json"],
        SUBMIT_NOT_FOUND_MESSAGE
    )


def submit_waste_data(session_token, material, weight, transaction_id=None):
    """Submit one item to Hostinger.

    weight      -> grams (as measured by the load cell); converted
                   to kilograms for the API.
    transaction_id -> stable client-generated ID, created BEFORE
                   the first attempt and reused on every retry so
                   the server can de-duplicate. Strongly recommended.

    Returns an outcome dict:
        {"ok": bool, "retryable": bool, "result": dict|None,
         "message": str, "http_status": int|None}
    """
    if (
        weight is None
        or weight < MIN_WEIGHT
        or weight > MAX_WEIGHT
    ):
        print(
            "Refusing to submit invalid weight."
        )

        return {
            "ok": False,
            "retryable": False,
            "result": None,
            "message": "invalid_weight",
            "http_status": None
        }

    raw_hardware_data = {
        "station_id": STATION_ID
    }

    # The server checks BOTH the top-level field and this nested
    # copy when looking for a duplicate transaction.
    if transaction_id:
        raw_hardware_data["transaction_id"] = transaction_id

    payload = {
        "session_token": session_token,
        "material": material.capitalize(),
        "weight_kg": weight / 1000,
        "raw_hardware_data": raw_hardware_data
    }

    if transaction_id:
        payload["transaction_id"] = transaction_id

    info = api_post_ex(
        SUBMIT_WASTE_API,
        payload
    )

    result = info["json"]

    if info["transport_error"] is None and result and result.get("success"):
        if result.get("duplicate"):
            print(
                "Waste data already recorded by server "
                "(idempotent replay)."
            )
        else:
            print(
                "Waste data submitted successfully."
            )

        return {
            "ok": True,
            "retryable": False,
            "result": result,
            "message": "ok",
            "http_status": info["http_status"]
        }

    if info["transport_error"]:
        message = f"transport_error:{info['transport_error']}"
    elif result:
        message = result.get("message", "Unknown error")
        print("Waste submission failed:", message)
    else:
        message = f"http_{info['http_status']}_invalid_json"

    return {
        "ok": False,
        "retryable": _classify_submit_failure(info),
        "result": result,
        "message": message,
        "http_status": info["http_status"]
    }


# =========================================================
# COMPLETE HOSTINGER SESSION
# =========================================================

def complete_api_session(
    session_token,
    material=None,
    weight=None
):
    print()
    print("Completing Hostinger session...")

    data = {
        "session_token": session_token
    }

    if material is not None:
        data["final_material"] = material.capitalize()

    if weight is not None:
        data["final_weight_kg"] = weight / 1000

    data["raw_hardware_data"] = {
        "station_id": STATION_ID
    }

    result = api_post(
        COMPLETE_API,
        data
    )

    if not result:
        print(
            "No response from session completion API."
        )

        return None

    if not result.get("success"):
        print(
            "Session completion failed:",
            result.get(
                "message",
                "Unknown error"
            )
        )

        return None

    print(
        "Hostinger session completed."
    )

    print(
        "Points awarded:",
        result.get(
            "points_awarded",
            0
        )
    )

    print(
        "New balance:",
        result.get(
            "new_balance",
            0
        )
    )

    return result


def complete_api_session_ex(
    session_token,
    material=None,
    weight=None
):
    """Complete a session and classify the outcome so the
    abandoned-session finalizer can tell a transient failure apart
    from a definitive one.

    Only a response that matches the endpoint's recognized error
    contract can retire a session (see _recognized_app_rejection).
    In particular, the documented 404 body
        {"success": false, "message": "Session not found or not
         eligible for completion"}
    is the only 404 treated as already-finalized / definitively
    ineligible. A generic HTML 404, non-JSON body, malformed or
    unexpected JSON, 5xx, timeout or connection failure is never
    terminal, so it can never write the local finalization ledger.

    Returns:
        {
            "ok":          bool,   # server confirmed completion
            "terminal":    bool,   # server gave a RECOGNIZED
                                   # definitive answer that the
                                   # session is not completable
                                   # (already finalized/cancelled,
                                   # ineligible, bad payload, auth):
                                   # never retry
            "result":      dict | None,
            "message":     str,
            "http_status": int | None,
        }
    """
    if not session_token:
        return {
            "ok": False,
            "terminal": True,
            "result": None,
            "message": "no_session_token",
            "http_status": None
        }

    data = {
        "session_token": session_token
    }

    if material is not None:
        data["final_material"] = material.capitalize()

    if weight is not None:
        data["final_weight_kg"] = weight / 1000

    data["raw_hardware_data"] = {
        "station_id": STATION_ID
    }

    info = api_post_ex(
        COMPLETE_API,
        data
    )

    result = info["json"]

    if info["transport_error"] is None and result and result.get("success"):
        return {
            "ok": True,
            "terminal": False,
            "result": result,
            "message": "ok",
            "http_status": info["http_status"]
        }

    if info["transport_error"]:
        message = f"transport_error:{info['transport_error']}"
    elif result:
        message = result.get("message", "Unknown error")
    else:
        message = f"http_{info['http_status']}_invalid_json"

    status = info["http_status"]

    # A session is only retired when the PHP endpoint gave a
    # recognized, definitive answer about THIS session. The endpoint
    # documents exactly one 404 body for already-finalized /
    # ineligible sessions and always answers its own rejections as
    # JSON {"success": false, "message": "..."}. Any generic or
    # non-JSON 4xx, malformed/unexpected body, 5xx, timeout or
    # connection failure carries no evidence that the session is
    # done, so it is never written to the local finalization ledger.
    terminal = _recognized_app_rejection(
        status,
        result,
        COMPLETE_NOT_FOUND_MESSAGE
    )

    return {
        "ok": False,
        "terminal": terminal,
        "result": result,
        "message": message,
        "http_status": status
    }


# =========================================================
# CANCEL HOSTINGER SESSION
# =========================================================

def cancel_api_session(
    session_token,
    reason
):
    if not session_token:
        return

    print()
    print("Cancelling Hostinger session...")

    result = api_post(
        CANCEL_API,
        {
            "session_token": session_token,
            "reason": reason
        }
    )

    if result and result.get("success"):
        print(
            "Hostinger session cancelled."
        )

    elif result:
        print(
            "Hostinger cancellation failed:",
            result.get(
                "message",
                "Unknown error"
            )
        )


# =========================================================
# OFFLINE QUEUE: SAFE WRAPPERS
#
# The queue lives on local storage that can fail independently of
# the station (disk I/O error, full disk, locked/corrupt DB). A
# queue fault must NEVER crash the station loop and must NEVER be
# mistaken for "nothing queued". These wrappers isolate callers
# from sqlite3 exceptions.
# =========================================================

def _mark_synced_safe(transaction_id):
    """Mark a row SYNCED; never raises.

    Returns True on success. On failure the row stays PENDING and
    will be re-submitted later; the server de-duplicates by
    transaction_id, so re-sending is safe.
    """
    try:
        mark_synced(transaction_id)
        return True

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not mark transaction "
            f"{transaction_id} as SYNCED: {e}. It stays PENDING "
            f"and will be re-tried (server de-duplicates by id)."
        )
        return False


def _mark_failed_safe(transaction_id, error):
    """Record one retryable failure; never raises."""
    try:
        mark_failed(transaction_id, error)
        return True

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not record retry for "
            f"transaction {transaction_id}: {e}"
        )
        return False


def _mark_failed_permanent_safe(transaction_id, error):
    """Move a row to FAILED; never raises."""
    try:
        mark_failed_permanent(transaction_id, error)
        return True

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not mark transaction "
            f"{transaction_id} as FAILED: {e}"
        )
        return False


# =========================================================
# M5: DURABLE PRE-ACTUATION (WRITE-AHEAD) SAFE WRAPPERS
#
# These never raise: the caller MUST refuse to move the servo
# when one returns False, because an unrecorded physical sort
# cannot be recovered.
# =========================================================

def _begin_actuation_safe(
    transaction_id,
    session_token,
    qr_code,
    material,
    weight,
    points
):
    """Commit the pre-actuation record; never raises.

    Returns True only when the row is durably committed. False means
    the caller must NOT command the servo.
    """
    payload = json.dumps({
        "station_id": STATION_ID,
        "transaction_id": transaction_id,
        "points_preview": points,
        "weight_g": weight,
        "actuation_requested_at": time.time()
    })

    try:
        begin_actuation(
            transaction_id,
            session_token,
            qr_code,
            material.capitalize(),
            weight / 1000.0,
            payload
        )
        return True

    except Exception as e:
        print(
            f"[OFFLINE] FATAL: pre-actuation record for "
            f"{transaction_id} could NOT be committed: {e}"
        )
        return False


def _mark_actuation_started_safe(transaction_id):
    """Persist actuation-started before the servo command; never raises."""
    try:
        mark_actuation_started(transaction_id)
        return True

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not persist actuation-started "
            f"for {transaction_id}: {e}"
        )
        return False


def _mark_actuation_completed_safe(transaction_id):
    """Persist actuation-completed after the servo returns; never raises."""
    try:
        mark_actuation_completed(transaction_id)
        return True

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not persist actuation-complete "
            f"for {transaction_id}: {e}"
        )
        return False


def _mark_actuation_abandoned_safe(transaction_id, reason):
    """Resolve a never-commanded actuation; never raises."""
    try:
        mark_actuation_abandoned(transaction_id, reason)
        return True

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not mark {transaction_id} "
            f"ABANDONED: {e}"
        )
        return False


def _mark_actuation_uncertain_safe(transaction_id, reason):
    """Flag an unknown physical outcome; never raises."""
    try:
        mark_actuation_uncertain(transaction_id, reason)
        return True

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not mark {transaction_id} "
            f"UNCERTAIN: {e}"
        )
        return False


def _report_uncertain_transactions():
    """Print a prominent diagnostic for unresolved physical outcomes.

    These rows are never submitted or awarded automatically and they
    block session finalization. An operator identifies them by their
    transaction_id (also recorded in the SQLite queue at
    pending_transactions.actuation_state = 'UNCERTAIN') and resolves
    each by physically inspecting the affected bin/item and deciding
    manually whether the item was sorted and should be credited; there
    is no automated physical confirmation on this hardware.
    """
    try:
        rows = get_uncertain_transactions()

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not list uncertain "
            f"transactions: {e}"
        )
        return

    if not rows:
        return

    print()
    print("=" * 60)
    print(f"  [ACTUATION] {len(rows)} UNRESOLVED transaction(s)")
    print("=" * 60)
    print("  These items MAY have been physically sorted but the")
    print("  completion was not recorded (power loss during sorting).")
    print("  They will NOT be submitted or awarded automatically and")
    print("  they BLOCK session finalization until resolved.")
    print("  Operator action required. Inspect each item against the")
    print("  bin/receipt log, then resolve the row out-of-band.")
    for row in rows:
        print(
            f"    - id={row['id']} tx={row['transaction_id']} "
            f"session={row['session_token']} material={row['material']} "
            f"weight_kg={row['weight_kg']} error={row['last_error']}"
        )
    print("=" * 60)
    print()


def _session_finalized_safe(session_token):
    """True when the session is already recorded as finalized.

    On a read error, reports True (fail safe): a session that cannot
    be checked is treated as done so the station never spins on
    repeated completion attempts for it."""
    try:
        return is_session_finalized(session_token)

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not read finalize ledger for "
            f"session {session_token}: {e}; assuming already handled."
        )
        return True


def _mark_session_finalized_safe(session_token):
    """Record a session as finalized; never raises."""
    try:
        mark_session_finalized(session_token)
        return True

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not record session "
            f"{session_token} as finalized: {e}. It may be attempted "
            f"again later (re-completion is idempotent server-side)."
        )
        return False


# =========================================================
# OFFLINE QUEUE: RECORD FAILED SUBMISSIONS
# =========================================================

def record_failed_submission(
    session_token,
    qr_code,
    material,
    weight,
    transaction_id,
    outcome
):
    """Persist an item that was physically processed/sorted but
    could not be confirmed by the Hostinger API.

    weight -> grams (converted to kilograms for the queue DB).

    Returns:
        "QUEUED"             -> saved, will be retried automatically
        "TERMINAL"           -> saved as FAILED for audit; never retried
                                (server rejected definitively: expired
                                session, bad payload, auth, ...)
        "FAILED_NOT_RECORDED"-> the item was saved, but its terminal
                                FAILED status could NOT be persisted;
                                it stays PENDING (retryable)
        "NOT_SAVED"          -> local write failed (critically low disk);
                                the caller must surface this loudly
    """
    payload = {
        "station_id": STATION_ID,
        "transaction_id": transaction_id,
        "queued_at": time.time(),
        "http_status": outcome.get("http_status"),
        "error": outcome.get("message")
    }

    try:
        # M5: the pre-actuation record was already committed before the
        # servo moved. If it is still present, do not re-insert (and
        # never report the item as lost on a transient write error); a
        # missing row means an older/legacy path, so save it now.
        if transaction_exists(transaction_id):
            print(
                f"[OFFLINE] Transaction {transaction_id} is already "
                f"durably recorded (pre-actuation); keeping it queued."
            )
        else:
            save_transaction(
                transaction_id,
                session_token,
                qr_code,
                material.capitalize(),
                weight / 1000.0,
                json.dumps(payload)
            )

    except RuntimeError as e:
        print(
            f"[OFFLINE] LOW DISK: transaction {transaction_id} "
            f"could NOT be queued: {e}"
        )
        return "NOT_SAVED"

    except Exception as e:
        print(
            f"[OFFLINE] Queue write failed for transaction "
            f"{transaction_id}: {e}"
        )
        return "NOT_SAVED"

    if outcome.get("retryable"):
        print(
            f"[OFFLINE] Item saved to the offline queue "
            f"(transaction {transaction_id}); it will be "
            f"retried automatically."
        )
        return "QUEUED"

    if not _mark_failed_permanent_safe(
        transaction_id,
        outcome.get("message", "terminal failure")
    ):
        print(
            f"[OFFLINE] Transaction {transaction_id} was saved to the "
            f"offline queue, but the FAILED status could NOT be "
            f"persisted. It stays PENDING and will be retried; the "
            f"server still de-duplicates by transaction_id, so this "
            f"cannot double-count."
        )
        return "FAILED_NOT_RECORDED"

    print(
        f"[OFFLINE] Transaction {transaction_id} recorded as "
        f"FAILED (will not retry): "
        f"{outcome.get('message')}"
    )
    return "TERMINAL"


# =========================================================
# OFFLINE QUEUE: FLUSH (RETRY PENDING TRANSACTIONS)
# =========================================================

def flush_offline_queue():
    """Try to sync every PENDING queued transaction that is due.

    M3 persistent backoff: a PENDING row whose retry deadline
    (next_retry_at, persisted in SQLite) has not arrived is skipped, so
    repeated flushes during an outage do not hammer the API. The
    deadline survives a Pi restart because it lives in the queue DB.

      - success                -> SYNCED, deadline cleared
      - retryable failure      -> stays PENDING, retry_count++ and
                                  next_retry_at = now + delay(count)
      - terminal failure       -> FAILED (deadline cleared)
      - retry_count >= MAX     -> FAILED (gave up, logged) -- checked
                                  for EVERY PENDING row on every flush
                                  BEFORE the backoff skip, so a retry
                                  deadline can never postpone this
                                  permanent-failure check.

    Each row keeps its original transaction_id so the server
    can de-duplicate even when an earlier attempt actually
    reached the server (ambiguous timeout).

    Returns the number of rows synced in this pass.
    """
    try:
        pending = get_pending_transactions()

    except Exception as e:
        print(f"[OFFLINE] Queue unreadable: {e}")
        return 0

    if not pending:
        return 0

    due = []
    waiting = 0
    unresolved = 0

    for row in pending:
        txid = row["transaction_id"]

        # M5: never submit a row whose physical actuation is not
        # confirmed. PREPARED/ACTUATING are incomplete; ABANDONED and
        # UNCERTAIN are deliberate unresolved outcomes. These rows are
        # left exactly as they are -- not retried, not failed, not
        # discarded -- so they keep blocking session finalization until
        # an operator resolves them. The gate is checked BEFORE the
        # retry-limit guard so an unresolved row is never converted to
        # FAILED by the retry counter.
        if not is_submittable(row):
            unresolved += 1
            continue

        # Retry-limit guard first: reaching MAX_RETRY_COUNT is terminal
        # and is never delayed by a pending backoff deadline.
        if row["retry_count"] >= MAX_RETRY_COUNT:
            if _mark_failed_permanent_safe(
                txid,
                f"gave up after {row['retry_count']} retries: "
                f"{row['last_error']}"
            ):
                print(
                    f"[OFFLINE] Transaction {txid} moved to FAILED "
                    f"after {row['retry_count']} retries."
                )
            continue

        if is_retry_due(row):
            due.append(row)
        else:
            waiting += 1

    if unresolved:
        print(
            f"[OFFLINE] {unresolved} transaction(s) are UNRESOLVED "
            f"(physical sorting outcome not confirmed) and will NOT "
            f"be submitted automatically. Operator review required."
        )

    if not due:
        if waiting:
            print(
                f"[OFFLINE] {waiting} queued transaction(s) are "
                f"waiting on retry backoff; nothing to send yet."
            )
        return 0

    if waiting:
        print(
            f"[OFFLINE] Flushing {len(due)} due transaction(s) "
            f"({waiting} waiting on backoff)..."
        )
    else:
        print(
            f"[OFFLINE] Flushing {len(due)} queued "
            f"transaction(s)..."
        )

    synced = 0

    for row in due:
        txid = row["transaction_id"]
        weight_kg = row["weight_kg"] or 0.0

        outcome = submit_waste_data(
            row["session_token"],
            row["material"],
            weight_kg * 1000.0,
            transaction_id=txid
        )

        if outcome["ok"]:
            if _mark_synced_safe(txid):
                synced += 1
                print(
                    f"[OFFLINE] Transaction {txid} synced."
                )

        elif outcome["retryable"]:
            _mark_failed_safe(txid, outcome["message"])
            print(
                f"[OFFLINE] Transaction {txid} not yet synced "
                f"(attempt {row['retry_count'] + 1}/"
                f"{MAX_RETRY_COUNT}): {outcome['message']}"
            )

        else:
            if _mark_failed_permanent_safe(txid, outcome["message"]):
                print(
                    f"[OFFLINE] Transaction {txid} terminal, "
                    f"will not retry: {outcome['message']}"
                )

    return synced


# =========================================================
# OFFLINE QUEUE: FINALIZE ABANDONED SESSIONS
#
# A session that was never completed (station moved on, network
# dropped, power loss) leaves its successfully-recorded items
# unawarded until the session is finalized. The server only
# self-heals a stale session when some request touches it, which
# will not happen once the station stops referencing that token,
# so the station drives finalization here.
# =========================================================

def finalize_abandoned_sessions(current_session_token=None):
    """Complete any session whose queued work has fully synced.

    A session is finalized only when ALL of these hold:
      - it is NOT the currently active session;
      - it has no PENDING transactions left (nothing unsynced);
      - at least one of its transactions is SYNCED (points are
        actually waiting to be awarded);
      - it has not already been finalized by this station.

    Failures NEVER mark a session locally complete: the ledger entry
    is written only after the server confirms (or definitively
    refuses) completion. Transient/DB failures are simply retried on
    the next flush.

    Returns the number of sessions finalized in this pass.
    """
    try:
        summary = get_session_sync_summary()

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not list sessions to "
            f"finalize: {e}"
        )
        return 0

    finalized = 0

    for row in summary:
        token = row["session_token"]

        if not token:
            continue

        # Never touch the session the resident is using right now.
        if token == current_session_token:
            continue

        # Any unsynced item means finalizing now would strand it.
        if row["pending_count"]:
            continue

        # Nothing was recorded for this session -> nothing to award.
        if not row["synced_count"]:
            continue

        if _session_finalized_safe(token):
            continue

        outcome = complete_api_session_ex(token)

        if outcome["ok"]:
            _mark_session_finalized_safe(token)
            finalized += 1
            print(
                f"[OFFLINE] Abandoned session {token[:8]}... "
                f"finalized; points awarded: "
                f"{outcome['result'].get('points_awarded', 0)}"
            )

        elif outcome["terminal"]:
            # Server already finalized/cancelled it (e.g. by its own
            # stale-session recovery), or the session can never be
            # completed. Record it so we stop re-attempting.
            _mark_session_finalized_safe(token)
            print(
                f"[OFFLINE] Session {token[:8]}... is not "
                f"completable ({outcome['message']}); not retrying."
            )

        else:
            print(
                f"[OFFLINE] Abandoned session {token[:8]}... not "
                f"finalized yet ({outcome['message']}); will retry."
            )

    return finalized


# =========================================================
# SESSION SAFETY GUARDS
# =========================================================

def _queue_pending_safe(session_token=None):
    """Conservative read of the offline queue; never raises.

    Returns True when a PENDING row exists OR when the queue cannot
    be read at all. Treating an unreadable queue as "pending" is the
    only fail-safe choice: it prevents the station from cancelling or
    completing a session that may still hold unsynced transactions.
    """
    try:
        return has_pending_transactions(session_token)

    except Exception as e:
        print(
            f"[OFFLINE] WARNING: could not read the offline queue "
            f"({e}); assuming pending transactions exist to stay safe."
        )
        return True


def should_cancel_session(items, session_token):
    """Cancelling is only safe when nothing was recorded and no
    queued transaction for this session is still waiting to sync.

    An unreadable queue is treated as pending, so the session is
    NOT cancelled (fail safe)."""
    return items == 0 and not _queue_pending_safe(session_token)


def should_complete_session(items, session_token):
    """Completing is only safe when at least one item was accepted
    AND no queued transaction for this session is still unsynced.

    An unreadable queue is treated as pending, so the session is
    NOT completed (fail safe)."""
    return items > 0 and not _queue_pending_safe(session_token)


# =========================================================
# WAIT FOR ITEM
# =========================================================

def wait_for_item():
    start_time = time.monotonic()

    while True:
        weight = get_weight(10)
        metal = metal_detected()

        # get_weight() returns None when the reading FAILED.
        # A broken/disconnected load cell must never look
        # like a placed item (and never like an empty one).
        if weight is None:
            weight_text = "SENSOR ERROR"
            status = "Waiting for weight sensor..."
        else:
            weight_text = f"{weight:.1f} g"
            status = (
                "METAL DETECTED"
                if metal
                else "Waiting for item..."
            )

        print(
            f"\r[ WEIGHT ] {weight_text} | {status}",
            end="",
            flush=True
        )

        # Weight must be present before an item can start
        if weight is not None and weight >= MIN_WEIGHT:
            print()
            return weight

        if (
            time.monotonic() - start_time
            >= IDLE_TIMEOUT
        ):
            print()
            return None

        time.sleep(
            REMOVAL_CHECK_INTERVAL
        )


# =========================================================
# WEIGHT STABILITY
# =========================================================

def wait_for_weight_stable():
    """
    Wait until the weight stays in the valid range for
    WEIGHT_STABLE_TIME seconds.

    Returns the stable weight in grams, or None when:
      - the weight never stabilized within
        WEIGHT_STABLE_TIMEOUT, or
      - every reading failed (HX711 missing/unusable).

    A failed reading is NEVER treated as a stable 0 g.
    """
    stable_start = None
    last_weight = 0
    start_time = time.monotonic()

    while True:
        weight = get_weight(1)

        # None = failed/unknown reading -> stability clock
        # restarts, we keep waiting (bounded by the timeout).
        if weight is None or weight < MIN_WEIGHT:
            stable_start = None
            time.sleep(0.1)

            if (
                time.monotonic() - start_time
                >= WEIGHT_STABLE_TIMEOUT
            ):
                print(
                    "Weight reading did not become "
                    "stable in time."
                )
                return None

            continue

        if stable_start is None:
            stable_start = time.monotonic()

        last_weight = weight

        if (
            time.monotonic()
            - stable_start
            >= WEIGHT_STABLE_TIME
        ):
            return last_weight

        if (
            time.monotonic() - start_time
            >= WEIGHT_STABLE_TIMEOUT
        ):
            print(
                "Weight reading did not become "
                "stable in time."
            )
            return None

        time.sleep(0.1)


# =========================================================
# CAMERA MATERIAL
# =========================================================

def get_camera_material():
    """
    Poll the camera service until it confirms a material or
    until CAMERA_CLASSIFICATION_TIMEOUT expires.

    A single empty/failed status poll, or a short camera
    reconnect, is TEMPORARY: it means "try again", not
    "camera unavailable". Polling stops as soon as a real
    classification is available, so the 8 s window is a
    deadline and never a mandatory delay.
    """
    start_time = time.monotonic()

    saw_status = False
    saw_available = False

    while (
        time.monotonic() - start_time
        < CAMERA_CLASSIFICATION_TIMEOUT
    ):
        data = camera_status()

        if data:
            saw_status = True

            # Keep the full status for the aluminum policy so it can
            # read the model's aluminum capability and the detection
            # confidence without polling the camera again.
            _remember_camera_status(data)

            if data.get("camera_available", False):
                saw_available = True

                # -------------------------------------------------
                # MIXED MATERIAL
                # -------------------------------------------------

                if data.get("mixed"):
                    return "Mixed"

                # -------------------------------------------------
                # CAMERA CONFIRMED PLASTIC / PAPER / ALUMINUM
                #
                # Aluminum is only ever reported by a model that
                # exposes a verified aluminum-can class. Whether it
                # is *used* is decided by the configured mode in
                # process_item().
                # -------------------------------------------------

                if data.get("confirmed"):
                    material = data.get("material")

                    if material in (
                        "Plastic",
                        "Paper",
                        "Aluminum"
                    ):
                        return material

            # Camera answered but has not confirmed yet:
            # keep polling until the deadline.

        # Empty/failed status: retry.

        time.sleep(0.1)

    # -----------------------------------------------------
    # DEADLINE REACHED
    # -----------------------------------------------------

    if not saw_status:
        print("Camera service unavailable.")
        return "UNAVAILABLE"

    if not saw_available:
        print("V380 camera unavailable.")
        return "UNAVAILABLE"

    # -----------------------------------------------------
    # CAMERA COULD NOT CLASSIFY
    # Let the inductive sensor handle the metal case.
    # -----------------------------------------------------

    print(
        "Camera could not classify item."
    )

    return None


# =========================================================
# WAIT FOR REMOVAL
# =========================================================

def wait_for_removal():
    """
    Wait for the user to take the item off the platform.

    Returns
    -------
    True  -> the platform was CONFIRMED clear:
             REMOVAL_CLEAR_SAMPLES consecutive samples with
             weight < MIN_WEIGHT and no metal detected.
    False -> REMOVAL_TIMEOUT expired while the item was
             still (or still apparently) on the platform.

    This function can NEVER block forever. On timeout the
    caller decides the safe next state; no points are
    awarded or duplicated here.

    A FAILED weight reading (None) does NOT count as
    "weight < MIN_WEIGHT": an unknown reading is not proof
    that the platform is empty.
    """
    print("Remove the item.")

    start_time = time.monotonic()

    clear_samples = 0
    last_status = 0.0
    confirmed = False

    while True:
        weight = get_weight(1)
        metal = metal_detected()

        weight_clear = (
            weight is not None
            and weight < MIN_WEIGHT
        )

        if weight_clear and not metal:
            clear_samples += 1
        else:
            clear_samples = 0

        now = time.monotonic()

        # ---------------------------------------------
        # CONFIRMED REMOVAL
        # ---------------------------------------------

        if clear_samples >= REMOVAL_CLEAR_SAMPLES:
            confirmed = True
            break

        # ---------------------------------------------
        # TIMEOUT: recover instead of freezing
        # ---------------------------------------------

        if now - start_time >= REMOVAL_TIMEOUT:
            break

        # ---------------------------------------------
        # LIVE STATUS (at most once per second)
        # ---------------------------------------------

        if now - last_status >= REMOVAL_STATUS_INTERVAL:
            last_status = now

            weight_text = (
                "unknown"
                if weight is None
                else f"{weight:.1f} g"
            )

            metal_text = (
                "METAL"
                if metal
                else "no metal"
            )

            print(
                f"\r[ REMOVE ] weight {weight_text} "
                f"| {metal_text}",
                end="",
                flush=True
            )

        time.sleep(
            REMOVAL_CHECK_INTERVAL
        )

    if confirmed:
        print()
        print("Item removed.")

    else:
        print()
        print(
            f"Item still detected after "
            f"{REMOVAL_TIMEOUT:.0f} seconds."
        )
        print(
            "Continuing so the station does not freeze. "
            "Please remove the item."
        )

    # Item boundary: clear any latched camera state/popup.
    reset_camera()

    return confirmed


# =========================================================
# END RESIDENT SESSION AFTER A REMOVAL TIMEOUT
# =========================================================

def end_session_on_removal_timeout(session_token, items):
    """
    Safe recovery when an item could not be removed within
    REMOVAL_TIMEOUT: end the resident session instead of
    letting the station loop or freeze.

    Points are NEVER awarded or duplicated here:
      - items == 0 -> nothing was submitted, so the session
        is cancelled (same rule as the IDLE path).
      - items >  0 -> waste was already submitted, so the
        session is left to the shared post-loop
        complete_api_session() path, which awards the
        already-recorded WASTE_DATA events exactly once.
    """
    print()
    print("================================")
    print("       RESIDENT SESSION ENDED")
    print("================================")

    print(
        f"Item was not removed within "
        f"{REMOVAL_TIMEOUT:.0f} seconds."
    )

    print(
        "Please remove the item before "
        "scanning again."
    )

    if items > 0:

        print(
            f"{items} item(s) already "
            "submitted. Finalizing session "
            "to award points."
        )

    elif should_cancel_session(items, session_token):

        cancel_api_session(
            session_token,
            f"Item not removed within "
            f"{REMOVAL_TIMEOUT:.0f} seconds"
        )

    else:

        print(
            "[OFFLINE] Queued item(s) still pending for this "
            "session; NOT cancelling so they can be retried. "
            "The session will finalize automatically once "
            "sync succeeds or times out server-side."
        )

    print()

    print(
        "Please use your QR to access."
    )

    print("================================")

    print()


# =========================================================
# PROCESS ONE ITEM
# =========================================================

def process_item(session_token, resident_code):
    print()
    print("Ready for next item.")
    print(
        "Place item on the weight platform..."
    )

    # Item boundary: start every item with a clean camera
    # state so a previous confirmation/popup cannot leak in.
    reset_camera()

    # =====================================================
    # 1. WAIT FOR ITEM
    # =====================================================

    weight = wait_for_item()

    if weight is None:
        return "IDLE"

    print(
        f"Item detected: {weight:.1f} g"
    )

    # =====================================================
    # 2. CAMERA FIRST
    # =====================================================

    print("Checking camera...")

    camera_material = get_camera_material()
    camera_info = camera_evidence()

    # Tracks how aluminum was established, so the final material
    # check knows whether the inductive sensor is authoritative for
    # this item. None unless the item was classified as aluminum.
    aluminum_source = None

    # -----------------------------------------------------
    # CAMERA DETECTED MIXED MATERIAL
    # -----------------------------------------------------

    if camera_material == "Mixed":
        print(
            "Mixed material. Item rejected."
        )

        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    # -----------------------------------------------------
    # CAMERA CONFIRMED ALUMINUM
    #
    # Only reachable when the loaded model exposes a verified
    # aluminum-can class. The configured mode decides whether to
    # trust it; in the default "inductive" mode a camera reading
    # is ignored and the inductive sensor (below) decides, which
    # is exactly the original behavior.
    # -----------------------------------------------------

    if (
        camera_material == "Aluminum"
        and ALUMINUM_DETECTION_MODE != ALUMINUM.MODE_INDUCTIVE
    ):
        decision = ALUMINUM.resolve_aluminum(
            ALUMINUM_DETECTION_MODE,
            _camera_aluminum_evidence(camera_info, camera_material),
            {"active": False, "stable": False},
            ALUMINUM_CONFIG
        )

        if decision.accepted:
            material = "aluminum"
            aluminum_source = decision.source

            print(
                "Camera confirmed: Aluminum"
            )

        else:
            print(
                "Camera aluminum detection not accepted: "
                f"{decision.reason}"
            )

            notify_unrecognized_item()

            if not wait_for_removal():
                return "REMOVAL_TIMEOUT"

            return "REJECTED"

    # -----------------------------------------------------
    # CAMERA SUCCESSFULLY IDENTIFIED PLASTIC / PAPER
    # -----------------------------------------------------

    elif camera_material in (
        "Plastic",
        "Paper"
    ):
        material = camera_material.lower()

        print(
            f"Camera confirmed: {camera_material}"
        )

    # -----------------------------------------------------
    # CAMERA COULD NOT IDENTIFY
    # FALL BACK TO INDUCTIVE SENSOR
    # -----------------------------------------------------

    else:
        print(
            "Camera did not classify the item."
        )

        print(
            "Checking inductive sensor..."
        )

        # In "camera" mode the inductive sensor is NOT authoritative:
        # a metal reading alone must not classify the item. In
        # "inductive" and "hybrid" modes it still may.
        inductive_may_classify = (
            ALUMINUM_DETECTION_MODE
            in (ALUMINUM.MODE_INDUCTIVE, ALUMINUM.MODE_HYBRID)
        )

        metal = metal_detected()

        if metal and inductive_may_classify:
            print(
                "Metal detected. "
                "Confirming aluminum..."
            )

            verify_start = time.monotonic()
            metal_stable = True

            while (
                time.monotonic()
                - verify_start
                < METAL_STABLE_TIME
            ):
                if not metal_detected():
                    metal_stable = False
                    break

                time.sleep(0.05)

            if not metal_stable:
                print(
                    "Metal verification failed."
                )

                if not wait_for_removal():
                    return "REMOVAL_TIMEOUT"

                return "REJECTED"

            material = "aluminum"
            aluminum_source = "inductive"

            print(
                "Aluminum confirmed."
            )

        else:
            if metal:
                print(
                    "Metal detected, but the inductive sensor is "
                    f"not authoritative in '{ALUMINUM_DETECTION_MODE}' "
                    "mode and the camera did not confirm aluminum."
                )

            print(
                "Item could not be identified."
            )

            notify_unrecognized_item()

            if not wait_for_removal():
                return "REMOVAL_TIMEOUT"

            return "REJECTED"

    # =====================================================
    # 3. STABILIZE WEIGHT
    # =====================================================

    print("Measuring weight...")

    weight = wait_for_weight_stable()

    if (
        weight is None
        or weight < MIN_WEIGHT
        or weight > MAX_WEIGHT
    ):
        print(
            "Invalid weight reading. "
            "Item will not be counted."
        )

        print(
            "Please remove the item and try again."
        )

        # Never bounce straight back into wait_for_item with
        # the same item still on the platform: that would
        # reprocess (and potentially resubmit) it forever.
        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    print(
        f"Stable weight: {weight:.2f} g"
    )

    # =====================================================
    # 4. FINAL MATERIAL CHECK
    # =====================================================

    if material == "aluminum":

        # Inductive-confirmed aluminum must still be metal at the end.
        # Camera-confirmed aluminum does not require the inductive
        # sensor (camera mode is explicitly camera-based and the item
        # may not trigger the coil reliably).
        if aluminum_source == "inductive" and not metal_detected():
            print(
                "Metal verification lost."
            )

            if not wait_for_removal():
                return "REMOVAL_TIMEOUT"

            return "REJECTED"

    else:

        if metal_detected():
            print(
                "Metal detected. Item rejected."
            )

            if not wait_for_removal():
                return "REMOVAL_TIMEOUT"

            return "REJECTED"

    # =====================================================
    # 5. CALCULATE INCENTIVE
    # =====================================================

    print("Verifying item...")

    if material == "plastic" or material == "paper" or material == "aluminum":

        if (
            weight is None
            or weight < MIN_WEIGHT
            or weight > MAX_WEIGHT
        ):
            return "REJECTED"

        points = weight * 0.303

    else:
        return "REJECTED"

    # =====================================================
    # 6. DURABLE PRE-ACTUATION RECORD (M5 WRITE-AHEAD)
    #
    # Generate the transaction id ONCE and commit a write-ahead
    # record to the offline queue BEFORE the servo can move.
    # If the record cannot be durably committed we must NOT sort:
    # an unsorted item is recoverable (the resident can try again),
    # while a sorted-but-unrecorded item is not.
    # =====================================================

    transaction_id = uuid.uuid4().hex

    if not _begin_actuation_safe(
        transaction_id,
        session_token,
        resident_code,
        material,
        weight,
        points
    ):
        print(
            "[ACTUATION] Refusing to sort: the durable "
            "pre-actuation record could not be committed."
        )
        print(
            "Please remove the item and try again."
        )

        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    # Persist 'actuation started' BEFORE issuing the servo command so a
    # crash from here on is an UNKNOWN physical outcome (never assumed
    # "nothing happened"). If it cannot be persisted we abandon the
    # (still PREPARED) record and refuse to move the servo.
    if not _mark_actuation_started_safe(transaction_id):
        _mark_actuation_abandoned_safe(
            transaction_id,
            "could not persist actuation-started state"
        )
        print(
            "[ACTUATION] Refusing to sort: could not record that "
            "sorting was about to begin."
        )
        print(
            "Please remove the item and try again."
        )

        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    # =====================================================
    # 7. SORT MATERIAL INTO THE CORRECT BIN
    # =====================================================

    actuated = False

    try:
        actuated = Servo.sort_material(material) is not False

    except Exception as e:
        print(
            f"[SERVO] Sort failed: {e}"
        )

    if not actuated:
        # The command may or may not have moved the mechanism. Do not
        # claim a physical placement: keep the record UNCERTAIN so it is
        # never submitted or awarded automatically and blocks session
        # finalization until an operator resolves it.
        _mark_actuation_uncertain_safe(
            transaction_id,
            "servo command did not complete successfully"
        )
        print(
            "[ACTUATION] Physical sort could not be confirmed. The "
            "transaction is marked UNCERTAIN and will NOT be awarded "
            "automatically."
        )
        print(
            "Please remove the item. Staff has been flagged to review."
        )

        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    if not _mark_actuation_completed_safe(transaction_id):
        # The servo returned successfully, but we cannot record it.
        # Treat as UNCERTAIN rather than risk re-sorting or awarding an
        # unconfirmed outcome on the next pass.
        _mark_actuation_uncertain_safe(
            transaction_id,
            "could not persist actuation completion (servo returned OK)"
        )
        print(
            "[ACTUATION] Sort completed but could not be recorded. The "
            "transaction is marked UNCERTAIN and will NOT be awarded "
            "automatically."
        )
        print(
            "Please remove the item. Staff has been flagged to review."
        )

        if not wait_for_removal():
            return "REMOVAL_TIMEOUT"

        return "REJECTED"

    return weight, points, material, transaction_id


# =========================================================
# MAIN
# =========================================================


def _init_offline_queue_or_stop():
    """Initialise the offline queue before any transaction runs.

    The station must NEVER begin normal processing with an unusable
    offline queue: a physically sorted item that cannot be queued
    would be silently unrecoverable. On failure, log clearly and
    signal a deliberate safe-stop (return False).

    Returns True when the queue is ready, False to stop the station.
    """
    try:
        init_db()

    except Exception as e:
        print()
        print("=" * 60)
        print("  [OFFLINE] FATAL: offline queue could not be initialised")
        print("=" * 60)
        print(f"  Reason : {e}")
        print("  Action : STOPPING. The station will not process any")
        print("           items until the queue is usable, so a sorted")
        print("           item can never be lost without being recorded.")
        print("  Fix    : check storage, permissions and disk space,")
        print("           then restart the station.")
        print("=" * 60)
        print()
        return False

    # M5: resolve any actuation that a crash or power loss interrupted.
    # A PREPARED row never commanded the servo (safe to abandon); an
    # ACTUATING row may have (kept UNCERTAIN: never auto-submitted or
    # awarded, and it blocks session finalization until an operator
    # resolves it). This must run before any flush so an uncertain row
    # can never be sent to the server as a normal transaction.
    try:
        recovered = recover_actuation_states()

    except Exception as e:
        print(
            f"[ACTUATION] WARNING: could not recover interrupted "
            f"actuation states: {e}"
        )
        recovered = []

    if recovered:
        print()
        print("=" * 60)
        print("  [ACTUATION] RECOVERED INTERRUPTED SORTING")
        print("=" * 60)
        for transaction_id, from_state, to_state in recovered:
            print(
                f"  {transaction_id}: {from_state} -> {to_state}"
            )
        print("=" * 60)
        print()

    _report_uncertain_transactions()

    return True


def main():
    scanner = None

    if not _init_offline_queue_or_stop():
        return

    try:

        # =====================================================
        # STARTUP: SYNC ANY TRANSACTIONS LEFT OVER FROM A
        # PREVIOUS RUN (POWER LOSS, REBOOT, NETWORK OUTAGE)
        # =====================================================

        flush_offline_queue()

        # Any session left behind by the previous run is abandoned
        # (there is no active session yet): finalize the ones whose
        # queued work has fully synced so their points are awarded.
        finalize_abandoned_sessions()

        # =====================================================
        # START CAMERA SERVICE
        # =====================================================

        start_camera_process()

        # =====================================================
        # START HARDWARE
        # =====================================================

        start_weight()
        start_inductive()

        try:
            Servo.start()

        except Exception as e:
            print(
                f"[SERVO] Unavailable: {e}"
            )

        scanner = start_scanner()

        print()
        print("================================")
        print("          VHEcoPoint")
        print("================================")

        print("System ready.")

        print(
            "Please use your QR to access."
        )

        print()

        # =====================================================
        # MAIN STATION LOOP
        #
        # THIS LOOP NEVER ENDS BECAUSE
        # OF A RESIDENT'S IDLE_TIMEOUT.
        # =====================================================

        while True:

            # =================================================
            # WAIT FOR QR
            # =================================================

            qr, scanner = read_qr(scanner)

            if not is_victorianpass(qr):

                print(
                    "Invalid QR. Please try again."
                )

                continue

            # =================================================
            # QR ACCEPTED
            # =================================================

            print()
            print("================================")
            print("       VICTORIANPASS VERIFIED")
            print("================================")

            # =================================================
            # CREATE HOSTINGER SESSION
            # =================================================

            session = create_api_session(qr)

            if session is None:

                print()

                print(
                    "Unable to create resident "
                    "session."
                )

                print(
                    "Please use your QR to access."
                )

                print()

                continue

            session_token = session[
                "session_token"
            ]

            resident = session[
                "resident"
            ]

            resident_code = session.get("qr_code")

            # =================================================
            # LOCAL SESSION COUNTERS
            # =================================================

            items = 0
            total_points = float(session.get("cap_state", {}).get("daily_points_used", 0))

            # =================================================
            # NETWORK JUST WORKED FOR QR VERIFICATION:
            # TRY TO SYNC ANY QUEUED OFFLINE TRANSACTIONS NOW
            # (they may belong to earlier sessions of any
            # resident; each row carries its own token)
            # =================================================

            flush_offline_queue()

            # The resident's NEW session is now the active one; any
            # other session whose queued work has fully synced is
            # abandoned and can be finalized to release its points.
            finalize_abandoned_sessions(session_token)

            # =================================================
            # USER SESSION STARTED
            # =================================================

            print()
            print("================================")
            print("       USER SESSION STARTED")
            print("================================")

            print(
                "Resident :",
                resident.get(
                    "full_name",
                    "Unknown"
                )
            )

            print(
                "Balance  :",
                resident.get(
                    "balance",
                    0
                )
            )

            print(
                "================================"
            )

            # =================================================
            # RESIDENT ITEM LOOP
            # =================================================

            while True:

                # -------------------------------------------------
                # LOCAL DAILY POINT CAP
                # -------------------------------------------------

                if total_points >= DAILY_POINT_CAP:

                    print()

                    print(
                        "Daily point limit reached."
                    )

                    break

                # -------------------------------------------------
                # PROCESS ITEM
                # -------------------------------------------------

                result = process_item(
                    session_token,
                    resident_code
                )

                # =================================================
                # IDLE (no item placed before IDLE_TIMEOUT)
                # =================================================

                if result == "IDLE":

                    print()
                    print("================================")
                    print("       RESIDENT SESSION ENDED")
                    print("================================")

                    print(
                        f"No item detected for "
                        f"{IDLE_TIMEOUT} seconds."
                    )

                    print(
                        "Your session has expired."
                    )

                    # -------------------------------------------------
                    # DECIDE: CANCEL OR COMPLETE
                    #
                    # items counts the items that were ALREADY
                    # physically sorted AND already accepted by
                    # submit_waste_data.php.
                    #
                    # - 0 items  -> nothing was recorded, so the
                    #   session is cancelled (no points are lost,
                    #   because nothing was ever submitted).
                    #
                    # - 1+ items -> waste WAS recorded. Cancelling
                    #   here would leave those WASTE_DATA events
                    #   unawarded forever, so we must NOT cancel.
                    #   complete_api_session() is called by the
                    #   existing post-loop code below, which is
                    #   the same path the daily-cap break already
                    #   uses, so points are still awarded exactly
                    #   once.
                    # -------------------------------------------------

                    if items > 0:

                        print(
                            f"{items} item(s) already "
                            "submitted. Finalizing session "
                            "to award points."
                        )

                    elif should_cancel_session(
                        items,
                        session_token
                    ):

                        cancel_api_session(
                            session_token,
                            f"No item detected for "
                            f"{IDLE_TIMEOUT} seconds"
                        )

                    else:

                        print(
                            "[OFFLINE] Queued item(s) still "
                            "pending for this session; NOT "
                            "cancelling so they can be "
                            "retried. The session will "
                            "finalize automatically once "
                            "sync succeeds or times out "
                            "server-side."
                        )

                    print()

                    print(
                        "Please use your QR to access."
                    )

                    print(
                        "================================"
                    )

                    print()

                    # -------------------------------------------------
                    # IMPORTANT
                    #
                    # This BREAK only exits the
                    # resident item loop.
                    #
                    # The outer while True
                    # continues.
                    #
                    # Completion of the session is handled by the
                    # shared post-loop code below, so the daily-cap
                    # path and this path cannot double award.
                    # -------------------------------------------------

                    break

                # =================================================
                # REMOVAL TIMEOUT
                #
                # An item could not be taken off the platform in
                # time. End the resident session (see
                # end_session_on_removal_timeout) so the station
                # returns to the QR prompt instead of reprocessing
                # the same item and duplicating points.
                # =================================================

                if result == "REMOVAL_TIMEOUT":

                    end_session_on_removal_timeout(
                        session_token,
                        items
                    )

                    break

                # =================================================
                # REJECTED ITEM
                # =================================================

                if result == "REJECTED":
                    continue

                # =================================================
                # VALID ITEM
                #
                # process_item() generated transaction_id and already
                # committed the durable pre-actuation record before the
                # servo moved; that SAME id is reused for every submit
                # and retry so the server can de-duplicate.
                # =================================================

                weight, points, material, transaction_id = result

                # =================================================
                # SUBMIT WASTE DATA TO HOSTINGER
                #
                # The transaction_id is the stable id created before
                # actuation and reused on every retry, so the server
                # can de-duplicate even when a request reached it but
                # the response was lost.
                # =================================================

                submitted = submit_waste_data(
                    session_token,
                    material,
                    weight,
                    transaction_id=transaction_id
                )

                if not submitted["ok"]:

                    print(
                        "Failed to submit waste data."
                    )

                    saved = record_failed_submission(
                        session_token,
                        resident_code,
                        material,
                        weight,
                        transaction_id,
                        submitted
                    )

                    if saved == "QUEUED":

                        print(
                            "Item stored for automatic "
                            "retry; it will be counted "
                            "when the connection recovers."
                        )

                    elif saved == "TERMINAL":

                        print(
                            "Item rejected by the server "
                            "permanently; it will not be "
                            "retried. Please try again with "
                            "a new item."
                        )

                    elif saved == "FAILED_NOT_RECORDED":

                        print(
                            "Item was saved to the offline "
                            "queue but its permanent status "
                            "could not be recorded; it stays "
                            "queued for retry and will not be "
                            "double-counted."
                        )

                    else:

                        print(
                            "WARNING: item could NOT be "
                            "stored locally (low disk). "
                            "This item is LOST."
                        )

                    if not wait_for_removal():

                        end_session_on_removal_timeout(
                            session_token,
                            items
                        )

                        break

                    continue

                # The item reached the server. Mark the durable
                # pre-actuation record SYNCED so it stops counting as
                # pending work and is never re-sent by a later flush.
                _mark_synced_safe(transaction_id)

                # =================================================
                # LOCAL DAILY POINT CAP
                # =================================================

                remaining = (
                    DAILY_POINT_CAP
                    - total_points
                )

                points = min(
                    points,
                    max(0, remaining)
                )

                total_points += points

                items += 1

                # =================================================
                # DISPLAY ACCEPTED ITEM
                # =================================================

                print()

                print("--------------------------------")

                print(
                    f"Material : "
                    f"{material.capitalize()}"
                )

                print(
                    f"Weight   : "
                    f"{weight:.2f} g"
                )

                print(
                    f"Points   : "
                    f"{points:.2f}"
                )

                print("--------------------------------")

                print(
                    f"Items    : "
                    f"{items}"
                )

                print(
                    f"Total    : "
                    f"{total_points:.2f}/"
                    f"{DAILY_POINT_CAP}"
                )

                # =================================================
                # WAIT FOR ITEM REMOVAL
                #
                # The item was already submitted, so a timeout
                # must NOT restart detection with the same item
                # still on the platform (that would submit it a
                # second time). End the session instead.
                # =================================================

                if not wait_for_removal():

                    end_session_on_removal_timeout(
                        session_token,
                        items
                    )

                    break

            # =================================================
            # COMPLETE HOSTINGER SESSION
            #
            # Never finalize while queued transactions for this
            # session are still unsynced: completion would make
            # them unreplayable (the session stops accepting
            # submissions), silently losing the points.
            # =================================================

            completed = None
            if items > 0:

                if _queue_pending_safe(session_token):

                    print(
                        "[OFFLINE] Attempting one final "
                        "sync before completing the "
                        "session..."
                    )
                    flush_offline_queue()

                    # Sync may have resolved other abandoned
                    # sessions too; finalize any that are now done
                    # (the current session is excluded).
                    finalize_abandoned_sessions(session_token)

                if should_complete_session(items, session_token):

                    completed = complete_api_session(
                        session_token
                    )

                else:

                    print(
                        "[OFFLINE] Some queued item(s) for "
                        "this session are still unsynced; "
                        "NOT completing now. The session "
                        "will finalize automatically once "
                        "sync succeeds or times out "
                        "server-side."
                    )

            if completed:

                print(
                    "Points awarded:",
                    completed.get(
                        "points_awarded",
                        0
                    )
                )

                print(
                    "New balance:",
                    completed.get(
                        "new_balance",
                        0
                    )
                )

            # =================================================
            # RESIDENT SESSION FINISHED
            # =================================================

            print()
            print("================================")
            print("       RESIDENT SESSION FINISHED")
            print("================================")

            print(
                f"Items  : {items}"
            )

            # Display the actual points awarded by Hostinger
            final_points = (
                completed.get("points_awarded", 0)
                if completed
                else total_points
            )

            print(
                f"Points : {float(final_points):.2f}"
            )

            print()

            print(
                "Please use your QR to access."
            )

            print(
                "================================"
            )

            print()

            # =================================================
            # IMPORTANT:
            #
            # We DO NOT close the scanner.
            # We DO NOT stop the program.
            #
            # The outer while True goes back
            # to read_qr(scanner).
            # =================================================


    # =========================================================
    # STOP
    # =========================================================

    except KeyboardInterrupt:

        print()
        print("System stopped.")


    # =========================================================
    # CLEANUP
    # =========================================================

    finally:

        # -------------------------------------------------
        # Stop the CameraYolo child FIRST so it can never
        # survive as an orphan holding port 5000.
        # -------------------------------------------------

        try:
            stop_camera_process()

        except Exception:
            pass

        try:
            close_scanner(scanner)

        except Exception:
            pass

        try:
            close_weight()

        except Exception:
            pass

        try:
            close_inductive()

        except Exception:
            pass

        try:
            Servo.close()

        except Exception:
            pass

        print("System released.")


if __name__ == "__main__":
    main()
