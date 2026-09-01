import time
import requests

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


# =========================
# SETTINGS
# =========================

CAMERA_URL = "http://127.0.0.1:5000/status"

MIN_WEIGHT = 20.0
WEIGHT_STABLE_TIME = 3.0
METAL_STABLE_TIME = 3.0

MAX_SESSIONS = 3
DAILY_POINT_CAP = 250

CAMERA_TIMEOUT = 0.5

# System stops after 2 minutes
# without a new item being placed.
IDLE_TIMEOUT = 120

# Fast removal detection
REMOVAL_CHECK_INTERVAL = 0.15


# =========================
# CAMERA
# =========================

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


# =========================
# WAIT FOR ITEM
# =========================

def wait_for_item():
    start = time.monotonic()

    while True:

        weight = get_weight(1)

        if weight >= MIN_WEIGHT:
            return weight

        # 2-minute idle timeout
        if time.monotonic() - start >= IDLE_TIMEOUT:
            return None

        time.sleep(REMOVAL_CHECK_INTERVAL)


# =========================
# WEIGHT STABILITY
# =========================

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

        if time.monotonic() - stable_start >= WEIGHT_STABLE_TIME:
            return last_weight

        time.sleep(0.1)


# =========================
# CAMERA MATERIAL
# =========================

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

        # Mixed material
        if data.get("mixed"):
            return "Mixed"

        # Camera confirmed
        if data.get("confirmed"):

            material = data.get("material")

            if material in ("Plastic", "Paper"):
                return material

        time.sleep(0.1)


# =========================
# WAIT FOR REMOVAL
# =========================

def wait_for_removal():

    print("Remove the item.")

    while True:

        weight = get_weight(1)
        metal = metal_detected()

        # Both sensors clear
        if weight < MIN_WEIGHT and not metal:
            print("Item removed.")
            return

        time.sleep(REMOVAL_CHECK_INTERVAL)


# =========================
# PROCESS ONE ITEM
# =========================

def process_item():

    print()
    print("Ready for next item.")
    print("Place item on the weight platform...")

    # ---------------------
    # HX711 FIRST
    # ---------------------

    weight = wait_for_item()

    if weight is None:
        return "IDLE"

    print("Item detected. Hold still for 3 seconds...")

    # ---------------------
    # WEIGHT STABILITY
    # ---------------------

    weight = wait_for_weight_stable()

    print("Weight stable. Checking material...")

    # ---------------------
    # METAL
    # ---------------------

    if metal_detected():

        print("Metal detected. Verifying...")

        if not wait_for_metal_stable(METAL_STABLE_TIME):
            print("Metal verification failed.")
            wait_for_removal()
            return "REJECTED"

        material = "aluminum"

    # ---------------------
    # PLASTIC / PAPER
    # ---------------------

    else:

        print("Identifying material...")

        material = get_camera_material()

        if material is None:
            print("Item removed. Please try again.")
            return "REJECTED"

        if material == "Mixed":
            print("Mixed material. Item rejected.")
            wait_for_removal()
            return "REJECTED"

    # ---------------------
    # FINAL HX711 CHECK
    # ---------------------

    weight = get_weight(1)

    if weight < MIN_WEIGHT:
        print("Item removed. Please try again.")
        return "REJECTED"

    # ---------------------
    # FINAL METAL CHECK
    # ---------------------

    if material == "aluminum":

        if not metal_detected():
            print("Metal verification lost.")
            wait_for_removal()
            return "REJECTED"

    else:

        if metal_detected():
            print("Metal detected. Item rejected.")
            wait_for_removal()
            return "REJECTED"

    # ---------------------
    # CALCULATE INCENTIVE
    # ---------------------

    print("Verifying item...")

    weight, points = measure_incentive(material)

    return weight, points, material


# =========================
# MAIN
# =========================

scanner = None

try:

    start_weight()
    start_inductive()
    scanner = start_scanner()

    print()
    print("================================")
    print("VHEcoPoint")
    print("================================")
    print("System ready.")
    print("Please scan your VictorianPass.")
    print()

    while True:

        # =====================
        # QR
        # =====================

        qr = read_qr(scanner)

        if not is_victorianpass(qr):
            print("Invalid QR. Please try again.")
            continue

        user = qr
        sessions = 0
        total_points = 0

        print()
        print("================================")
        print("USER SESSION STARTED")
        print(f"User: {user}")
        print("================================")

        # =====================
        # ITEM LOOP
        # =====================

        while sessions < MAX_SESSIONS:

            if total_points >= DAILY_POINT_CAP:
                break

            result = process_item()

            # -----------------
            # 2 MINUTE IDLE
            # -----------------

            if result == "IDLE":

                print()
                print("================================")
                print("SYSTEM IDLE")
                print("No item detected for 2 minutes.")
                print("System stopped.")
                print("================================")

                raise SystemExit

            # -----------------
            # REJECTED
            # -----------------

            if result == "REJECTED":
                continue

            # -----------------
            # VALID ITEM
            # -----------------

            weight, points, material = result

            # Daily cap
            remaining = DAILY_POINT_CAP - total_points

            points = min(
                points,
                max(0, remaining)
            )

            total_points += points
            sessions += 1

            print()
            print("--------------------------------")
            print("ITEM ACCEPTED")
            print(f"Material : {material.capitalize()}")
            print(f"Weight   : {weight:.2f} g")
            print(f"Points   : {points:.2f}")
            print("--------------------------------")
            print(f"Sessions : {sessions}/{MAX_SESSIONS}")
            print(f"Total    : {total_points:.2f}/{DAILY_POINT_CAP}")

            # -----------------
            # REMOVE ITEM
            # -----------------

            wait_for_removal()

        # =====================
        # SESSION FINISHED
        # =====================

        print()
        print("================================")
        print("SESSION FINISHED")
        print(f"Items: {sessions}")
        print(f"Points: {total_points:.2f}")
        print("Please scan again.")
        print("================================")
        print()


# =========================
# STOP
# =========================

except KeyboardInterrupt:

    print()
    print("System stopped.")


# =========================
# CLEANUP
# =========================

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