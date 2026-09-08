import time
import requests
import os
import subprocess

from offline_queue import init_db, save_transaction

from qr_scanner import (
    start_scanner,
    read_qr,
    is_victorianpass,
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


# =========================================================
# SETTINGS
# =========================================================

CAMERA_URL = "http://127.0.0.1:5000/status"

camera_process = subprocess.Popen(
    ["python3", "CameraYolo.py"],
    stdout=subprocess.DEVNULL,
    stderr=subprocess.DEVNULL
)

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


MIN_WEIGHT = 20.0

WEIGHT_STABLE_TIME = 1
METAL_STABLE_TIME = 0.5

MAX_SESSIONS = 3
DAILY_POINT_CAP = 100

CAMERA_TIMEOUT = 0.5

# Resident session timeout.
# If no item is placed for 2 minutes,
# only the resident session ends.
IDLE_TIMEOUT = 90

REMOVAL_CHECK_INTERVAL = 0.15


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
    print("QR VALUE SENT TO API:", repr(qr_code.split("CODE=")[-1].strip()))

    result = api_post(
        QR_API,
        {
            "qr_code": qr_code.split("CODE=")[-1].strip()
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
        "resident": resident
    }
# =========================================================
# SUBMIT WASTE DATA
# =========================================================
def submit_waste_data(session_token, material, weight):
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
        print("No response from waste submission API.")
        return None

    if not result.get("success"):
        print(
            "Waste submission failed:",
            result.get("message", "Unknown error")
        )
        return None

    print("Waste data submitted successfully.")

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
        print("No response from session completion API.")
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

    print("Hostinger session completed.")
    print(
        "Points awarded:",
        result.get("points_awarded", 0)
    )
    print(
        "New balance:",
        result.get("new_balance", 0)
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

        print("Hostinger session cancelled.")

    elif result:

        print(
            "Hostinger cancellation failed:",
            result.get(
                "message",
                "Unknown error"
            )
        )


## =========================================================
# WAIT FOR ITEM
# =========================================================

def wait_for_item():

    start_time = time.monotonic()

    while True:

        weight = get_weight(1)
        metal = metal_detected()

        status = "METAL DETECTED" if metal else "Waiting for item..."
        print(f"\r[ WEIGHT ] {weight:.1f} g | {status}", end="", flush=True)

        # Weight must be present before an item can start
        if weight >= MIN_WEIGHT:
            print()
            return weight

        if time.monotonic() - start_time >= IDLE_TIMEOUT:
            print()
            return None

        time.sleep(REMOVAL_CHECK_INTERVAL)
# =========================================================
# WEIGHT STABILITY
# =========================================================

def wait_for_weight_stable():

    stable_start = None
    last_weight = 0

    while True:

        weight = get_weight(1)

        if weight < MIN_WEIGHT:

            stable_start = None

            time.sleep(0.1)

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

        time.sleep(0.1)


# =========================================================
# CAMERA MATERIAL
# =========================================================

def get_camera_material():

    while True:

        data = camera_status()

        if not data:

            time.sleep(0.1)

            continue

        # -------------------------------------------------
        # MIXED MATERIAL
        # -------------------------------------------------

        if data.get("mixed"):

            return "Mixed"

        # -------------------------------------------------
        # CAMERA CONFIRMED
        # -------------------------------------------------

        if data.get("confirmed"):

            material = data.get(
                "material"
            )

            if material in (
                "Plastic",
                "Paper"
            ):

                return material

        time.sleep(0.1)


# =========================================================
# WAIT FOR REMOVAL
# =========================================================

def wait_for_removal():

    print("Remove the item.")

    while True:

        weight = get_weight(1)

        metal = metal_detected()

        # Both sensors clear

        if (
            weight < MIN_WEIGHT
            and not metal
        ):

            print("Item removed.")

            return

        time.sleep(
            REMOVAL_CHECK_INTERVAL
        )


# =========================================================
# PROCESS ONE ITEM
# =========================================================
def process_item():
    print()
    print("Ready for next item.")
    print(
        "Place item on the "
        "weight platform..."
    )

    # =====================================================
    # WAIT FOR WEIGHT FIRST
    # =====================================================

    weight = wait_for_item()

    if weight is None:
        return "IDLE"

    print("Item detected. Checking material...")

    metal = metal_detected()
    print("DEBUG - Metal sensor:", metal)

    if metal:
        print("Metal detected. Verifying...")

        if not wait_for_metal_stable(METAL_STABLE_TIME):
            print("Metal verification failed.")
            wait_for_removal()
            return "REJECTED"

        material = "aluminum"

    else:
        # =================================================
        # CAMERA FOR PLASTIC / PAPER
        # =================================================

        print("Checking camera...")

        material = get_camera_material()

        if material == "Mixed":
            print("Mixed material. Item rejected.")
            wait_for_removal()
            return "REJECTED"

        print("Camera confirmed.")

    # =====================================================
    # STABILIZE WEIGHT
    # =====================================================

    print("Measuring weight...")

    weight = wait_for_weight_stable()

    if weight is None:
        print("Item removed. Please try again.")
        return "REJECTED"

    # =====================================================
    # FINAL METAL CHECK
    # =====================================================

    if material == "aluminum":
        if not metal_detected():
            print(
                "Metal verification lost."
            )
            wait_for_removal()
            return "REJECTED"

    else:
        if metal_detected():
            print(
                "Metal detected. "
                "Item rejected."
            )
            wait_for_removal()
            return "REJECTED"

    # =====================================================
    # CALCULATE INCENTIVE
    # =====================================================

    print("Verifying item...")

    weight, points = measure_incentive(
        material
    )

    return weight, points, material

# =========================================================
# MAIN
# =========================================================

scanner = None

init_db()

try:
    # =====================================================
    # START HARDWARE
    # =====================================================

    start_weight()

    start_inductive()

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
    # OF A RESIDENT'S 2-MINUTE TIMEOUT.
    # =====================================================

    while True:

        # =================================================
        # WAIT FOR QR
        # =================================================

        qr = read_qr(scanner)

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

        sessions = 0
        total_points = 0

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
        print("================================")

        # =================================================
        # RESIDENT ITEM LOOP
        # =================================================

        while sessions < MAX_SESSIONS:

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
            # 2-MINUTE IDLE
            # =================================================

            if result == "IDLE":

                print()
                print("================================")
                print("       RESIDENT SESSION ENDED")
                print("================================")
                print(
                    "No item detected for "
                    "2 minutes."
                )
                print(
                    "Your session has expired."
                )

                # -------------------------------------------------
                # CANCEL ONLY THIS HOSTINGER SESSION
                # -------------------------------------------------

                cancel_api_session(
                    session_token,
                    "No item detected for 2 minutes"
                )

                print()
                print(
                    "Please use your QR to access."
                )
                print("================================")
                print()

                # -------------------------------------------------
                # IMPORTANT
                #
                # This BREAK only exits the
                # resident item loop.
                #
                # The outer while True
                # continues.
                # -------------------------------------------------

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
                print("Failed to submit waste data.")
                print("Item will not be counted.")
                wait_for_removal()
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

            transaction_id = f"{session_token}-{sessions + 1}"

            sessions += 1

            # =================================================
            # DISPLAY ACCEPTED ITEM
            # =================================================

            print()
            print("--------------------------------")
            print("ITEM ACCEPTED")
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
                f"Sessions : "
                f"{sessions}/{MAX_SESSIONS}"
            )
            print(
                f"Total    : "
                f"{total_points:.2f}/"
                f"{DAILY_POINT_CAP}"
            )

            # =================================================
            # WAIT FOR ITEM REMOVAL
            # =================================================

            wait_for_removal()

        # =================================================
        # COMPLETE HOSTINGER SESSION
        # =================================================
        if sessions > 0:
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
            f"Items  : {sessions}"
        )
        print(
            f"Points : {total_points:.2f}"
        )
        print()
        print(
            "Please use your QR to access."
        )
        print("================================")
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

    print("System released.")
