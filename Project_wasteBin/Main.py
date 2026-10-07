import time
import requests
import os
import subprocess
import sys

from offline_queue import init_db, save_transaction

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


# =========================================================
# API POST
# =========================================================

def api_post(url, data):
    try:
        response = requests.post(
            url,
            headers=API_HEADERS,
            json=data,
            timeout=10
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
            result = response.json()

        except Exception:
            print("Invalid JSON response from server.")
            return None

        return result

    except requests.exceptions.Timeout:
        print("Hostinger API timeout.")
        return None

    except requests.exceptions.ConnectionError:
        print("Cannot connect to Hostinger.")
        return None

    except Exception as e:
        print(f"API error: {e}")
        return None


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
        "cap_state": result.get("cap_state", {})
    }


# =========================================================
# SUBMIT WASTE DATA
# =========================================================

def submit_waste_data(session_token, material, weight):
    if (
        weight is None
        or weight < MIN_WEIGHT
        or weight > MAX_WEIGHT
    ):
        print(
            "Refusing to submit invalid weight."
        )

        return None

    result = api_post(
        SUBMIT_WASTE_API,
        {
            "session_token": session_token,
            "material": material.capitalize(),
            "weight_kg": weight / 1000,
            "raw_hardware_data": {
                "station_id": STATION_ID
            }
        }
    )

    if not result:
        print(
            "No response from waste submission API."
        )

        return None

    if not result.get("success"):
        print(
            "Waste submission failed:",
            result.get(
                "message",
                "Unknown error"
            )
        )

        return None

    print(
        "Waste data submitted successfully."
    )

    return result


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

            if data.get("camera_available", False):
                saw_available = True

                # -------------------------------------------------
                # MIXED MATERIAL
                # -------------------------------------------------

                if data.get("mixed"):
                    return "Mixed"

                # -------------------------------------------------
                # CAMERA CONFIRMED PLASTIC / PAPER
                # -------------------------------------------------

                if data.get("confirmed"):
                    material = data.get("material")

                    if material in (
                        "Plastic",
                        "Paper"
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

    else:

        cancel_api_session(
            session_token,
            f"Item not removed within "
            f"{REMOVAL_TIMEOUT:.0f} seconds"
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

def process_item():
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
    # CAMERA SUCCESSFULLY IDENTIFIED PLASTIC / PAPER
    # -----------------------------------------------------

    if camera_material in (
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

        metal = metal_detected()

        if metal:
            print(
                "Metal detected. "
                "Confirming aluminum..."
            )

            verify_start = time.monotonic()

            while (
                time.monotonic()
                - verify_start
                < METAL_STABLE_TIME
            ):
                if not metal_detected():
                    print(
                        "Metal verification failed."
                    )

                    if not wait_for_removal():
                        return "REMOVAL_TIMEOUT"

                    return "REJECTED"

                time.sleep(0.05)

            material = "aluminum"

            print(
                "Aluminum confirmed."
            )

        else:
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

        if not metal_detected():
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
    # 6. SORT MATERIAL INTO THE CORRECT BIN
    # =====================================================

    try:
        Servo.sort_material(material)

    except Exception as e:
        print(
            f"[SERVO] Sort failed: {e}"
        )

    return weight, points, material


# =========================================================
# MAIN
# =========================================================


def main():
    scanner = None

    init_db()

    try:

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

            # =================================================
            # LOCAL SESSION COUNTERS
            # =================================================

            items = 0
            total_points = float(session.get("cap_state", {}).get("daily_points_used", 0))

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

                result = process_item()

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

                    else:

                        cancel_api_session(
                            session_token,
                            f"No item detected for "
                            f"{IDLE_TIMEOUT} seconds"
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
                # =================================================

                weight, points, material = result

                # =================================================
                # SUBMIT WASTE DATA TO HOSTINGER
                # =================================================

                submitted = submit_waste_data(
                    session_token,
                    material,
                    weight
                )

                if submitted is None:

                    print(
                        "Failed to submit waste data."
                    )

                    print(
                        "Item will not be counted."
                    )

                    if not wait_for_removal():

                        end_session_on_removal_timeout(
                            session_token,
                            items
                        )

                        break

                    continue

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

                transaction_id = (
                    f"{session_token}-{items + 1}"
                )

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
            # =================================================

            completed = None
            if items > 0:

                completed = complete_api_session(
                    session_token
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
