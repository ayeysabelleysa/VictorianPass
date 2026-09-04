import time
import requests
import os

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

API_BASE_URL = "https://deeppink-wren-292489.hostingersite.com/api"

QR_API = (
    f"{API_BASE_URL}/qr_verify_and_create_session.php"
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

WEIGHT_STABLE_TIME = 3.0
METAL_STABLE_TIME = 3.0

MAX_SESSIONS = 3
DAILY_POINT_CAP = 250

CAMERA_TIMEOUT = 0.5

# Resident session timeout.
# If no item is placed for 2 minutes,
# only the resident session ends.
IDLE_TIMEOUT = 120

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


# =========================================================
# WAIT FOR ITEM
# =========================================================

def wait_for_item():

    start_time = time.monotonic()

    while True:

        # -------------------------------------------------
        # Read HX711
        # -------------------------------------------------

        weight = get_weight(1)

        if weight >= MIN_WEIGHT:

            return weight

        # -------------------------------------------------
        # EXACT 2-MINUTE IDLE CHECK
        # -------------------------------------------------

        elapsed = (
            time.monotonic()
            - start_time
        )

        if elapsed >= IDLE_TIMEOUT:

            return None

        time.sleep(
            REMOVAL_CHECK_INTERVAL
        )


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

        # Item must still be on HX711

        weight = get_weight(1)

        if weight < MIN_WEIGHT:

            return None

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
    # HX711 FIRST
    # =====================================================

    weight = wait_for_item()

    if weight is None:

        return "IDLE"

    print(
        "Item detected. "
        "Hold still for 3 seconds..."
    )

    # =====================================================
    # WEIGHT STABILITY
    # =====================================================

    weight = wait_for_weight_stable()

    print(
        "Weight stable. "
        "Checking material..."
    )

    # =====================================================
    # METAL
    # =====================================================

    if metal_detected():

        print(
            "Metal detected. "
            "Verifying..."
        )

        if not wait_for_metal_stable(
            METAL_STABLE_TIME
        ):

            print(
                "Metal verification failed."
            )

            wait_for_removal()

            return "REJECTED"

        material = "aluminum"

    # =====================================================
    # PLASTIC / PAPER
    # =====================================================

    else:

        print(
            "Identifying material..."
        )

        material = get_camera_material()

        if material is None:

            print(
                "Item removed. "
                "Please try again."
            )

            return "REJECTED"

        if material == "Mixed":

            print(
                "Mixed material. "
                "Item rejected."
            )

            wait_for_removal()

            return "REJECTED"

    # =====================================================
    # FINAL HX711 CHECK
    # =====================================================

    weight = get_weight(1)

    if weight < MIN_WEIGHT:

        print(
            "Item removed. "
            "Please try again."
        )

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
