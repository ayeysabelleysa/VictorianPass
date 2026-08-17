import requests
import cv2
import numpy as np
import os
import time
import sys
import select
import termios
import tty

# ============================================================
# ESP32-CAM
# ============================================================

ESP32_IP = "192.168.100.138"
CAPTURE_URL = f"http://{ESP32_IP}/capture"

# ============================================================
# DATASET FOLDERS
# ============================================================

PAPER_DIR = "dataset/paper"
PLASTIC_DIR = "dataset/plastic"

os.makedirs(PAPER_DIR, exist_ok=True)
os.makedirs(PLASTIC_DIR, exist_ok=True)

# ============================================================
# FIND NEXT IMAGE NUMBER
# ============================================================

def get_next_number(folder):

    files = os.listdir(folder)
    numbers = []

    for filename in files:

        if filename.startswith("image_") and filename.endswith(".jpg"):

            try:
                number = int(
                    filename.replace("image_", "")
                            .replace(".jpg", "")
                )
                numbers.append(number)

            except ValueError:
                pass

    if not numbers:
        return 1

    return max(numbers) + 1


paper_number = get_next_number(PAPER_DIR)
plastic_number = get_next_number(PLASTIC_DIR)

# ============================================================
# CHECK FOR KEY PRESS WITHOUT WAITING
# ============================================================

def key_pressed():

    readable, _, _ = select.select(
        [sys.stdin],
        [],
        [],
        0
    )

    if readable:
        return sys.stdin.read(1).lower()

    return None


# ============================================================
# START
# ============================================================

print("======================================")
print("ESP32-CAM DATASET COLLECTOR")
print("======================================")
print()
print("P = save PAPER image")
print("L = save PLASTIC image")
print("Q = quit")
print()
print("Camera:", ESP32_IP)
print()
print("Point the ESP32-CAM at an object.")
print("Press P or L in this terminal to capture.")
print("======================================")

# Put terminal into single-key mode
old_settings = termios.tcgetattr(sys.stdin)
tty.setcbreak(sys.stdin)

try:

    while True:

        # ----------------------------------------------------
        # GET IMAGE FROM ESP32-CAM
        # ----------------------------------------------------

        try:

            response = requests.get(
                CAPTURE_URL,
                timeout=5
            )

        except requests.exceptions.RequestException as error:

            print("Camera connection error:", error)
            time.sleep(1)
            continue

        if response.status_code != 200:

            print(
                "Camera returned:",
                response.status_code
            )

            continue

        # ----------------------------------------------------
        # JPEG -> OpenCV IMAGE
        # ----------------------------------------------------

        image_bytes = np.frombuffer(
            response.content,
            dtype=np.uint8
        )

        frame = cv2.imdecode(
            image_bytes,
            cv2.IMREAD_COLOR
        )

        if frame is None:

            print("Could not decode image")
            continue

        # ----------------------------------------------------
        # CHECK KEYBOARD
        # ----------------------------------------------------

        key = key_pressed()

        # ----------------------------------------------------
        # PAPER
        # ----------------------------------------------------

        if key == "p":

            filename = (
                f"{PAPER_DIR}/"
                f"image_{paper_number:04d}.jpg"
            )

            success = cv2.imwrite(
                filename,
                frame
            )

            if success:

                print(
                    f"PAPER saved -> {filename}"
                )

                paper_number += 1

            else:

                print("ERROR: Could not save paper image")

        # ----------------------------------------------------
        # PLASTIC
        # ----------------------------------------------------

        elif key == "l":

            filename = (
                f"{PLASTIC_DIR}/"
                f"image_{plastic_number:04d}.jpg"
            )

            success = cv2.imwrite(
                filename,
                frame
            )

            if success:

                print(
                    f"PLASTIC saved -> {filename}"
                )

                plastic_number += 1

            else:

                print("ERROR: Could not save plastic image")

        # ----------------------------------------------------
        # QUIT
        # ----------------------------------------------------

        elif key == "q":

            break

        time.sleep(0.1)

finally:

    # Restore normal terminal behavior
    termios.tcsetattr(
        sys.stdin,
        termios.TCSADRAIN,
        old_settings
    )

print()
print("======================================")
print("Dataset collection stopped")
print("======================================")
print(
    f"Paper images:   {paper_number - 1}"
)
print(
    f"Plastic images: {plastic_number - 1}"
)