import time
from evdev import InputDevice, ecodes

# =========================================================
# SETTINGS
# =========================================================

QR_DEVICE = "/dev/input/event4"

# VictorianPass resident house-number prefix
VH_PREFIX = "VH-"

# Old/other VictorianPass prefix, if still used
VP_PREFIX = "VP-"


# =========================================================
# KEYBOARD MAP
# =========================================================

KEY_MAP = {
    # Numbers
    "KEY_0": "0",
    "KEY_1": "1",
    "KEY_2": "2",
    "KEY_3": "3",
    "KEY_4": "4",
    "KEY_5": "5",
    "KEY_6": "6",
    "KEY_7": "7",
    "KEY_8": "8",
    "KEY_9": "9",

    # Letters
    "KEY_A": "A",
    "KEY_B": "B",
    "KEY_C": "C",
    "KEY_D": "D",
    "KEY_E": "E",
    "KEY_F": "F",
    "KEY_G": "G",
    "KEY_H": "H",
    "KEY_I": "I",
    "KEY_J": "J",
    "KEY_K": "K",
    "KEY_L": "L",
    "KEY_M": "M",
    "KEY_N": "N",
    "KEY_O": "O",
    "KEY_P": "P",
    "KEY_Q": "Q",
    "KEY_R": "R",
    "KEY_S": "S",
    "KEY_T": "T",
    "KEY_U": "U",
    "KEY_V": "V",
    "KEY_W": "W",
    "KEY_X": "X",
    "KEY_Y": "Y",
    "KEY_Z": "Z",

    # Normal punctuation
    "KEY_MINUS": "-",
    "KEY_EQUAL": "=",
    "KEY_SLASH": "/",
    "KEY_DOT": ".",
    "KEY_SEMICOLON": ";",

    # Other characters that may appear in URLs
    "KEY_COMMA": ",",
    "KEY_APOSTROPHE": "'",
    "KEY_LEFTBRACE": "[",
    "KEY_RIGHTBRACE": "]",
    "KEY_BACKSLASH": "\\",
    "KEY_GRAVE": "`",
}


# =========================================================
# SHIFTED KEY MAP
# =========================================================
#
# QR URLs can contain characters that require SHIFT:
#
# ? = SHIFT + /
# _ = SHIFT + -
# : = SHIFT + ;
#
# =========================================================

SHIFT_KEY_MAP = {
    "KEY_1": "!",
    "KEY_2": "@",
    "KEY_3": "#",
    "KEY_4": "$",
    "KEY_5": "%",
    "KEY_6": "^",
    "KEY_7": "&",
    "KEY_8": "*",
    "KEY_9": "(",
    "KEY_0": ")",

    "KEY_MINUS": "_",
    "KEY_EQUAL": "+",

    "KEY_SEMICOLON": ":",
    "KEY_SLASH": "?",

    "KEY_COMMA": "<",
    "KEY_DOT": ">",
    "KEY_APOSTROPHE": "\"",

    "KEY_LEFTBRACE": "{",
    "KEY_RIGHTBRACE": "}",

    "KEY_BACKSLASH": "|",
    "KEY_GRAVE": "~",
}


# =========================================================
# START SCANNER
# =========================================================

def start_scanner():
    scanner = InputDevice(QR_DEVICE)

    print("GM65 QR scanner started.")
    print("Device:", scanner.name)
    print("Device:", QR_DEVICE)

    return scanner


# =========================================================
# READ QR
# =========================================================

def read_qr(scanner):
    qr_buffer = ""

    shift_pressed = False

    while True:
        try:
            event = scanner.read_one()

        except BlockingIOError:
            time.sleep(0.01)
            continue

        if event is None:
            time.sleep(0.01)
            continue

        if event.type != ecodes.EV_KEY:
            continue

        key_name = ecodes.KEY.get(event.code)

        if isinstance(key_name, list):
            key_name = key_name[0]

        # -------------------------------------------------
        # SHIFT
        # -------------------------------------------------

        if key_name in ("KEY_LEFTSHIFT", "KEY_RIGHTSHIFT"):

            if event.value == 1:
                shift_pressed = True

            elif event.value == 0:
                shift_pressed = False

            continue

        # -------------------------------------------------
        # Only process key-down events
        # -------------------------------------------------

        if event.value != 1:
            continue

        # -------------------------------------------------
        # CAPS LOCK
        # -------------------------------------------------

        if key_name == "KEY_CAPSLOCK":
            continue

        # -------------------------------------------------
        # ENTER = END OF QR
        # -------------------------------------------------

        if key_name == "KEY_ENTER":

            if qr_buffer:

                print()
                print("--------------------------------")
                print("QR RAW DATA")
                print("--------------------------------")
                print(qr_buffer)
                print("--------------------------------")

                return qr_buffer

            qr_buffer = ""
            continue

        # -------------------------------------------------
        # SHIFTED CHARACTER
        # -------------------------------------------------

        if shift_pressed and key_name in SHIFT_KEY_MAP:
            qr_buffer += SHIFT_KEY_MAP[key_name]
            continue

        # -------------------------------------------------
        # NORMAL CHARACTER
        # -------------------------------------------------

        if key_name in KEY_MAP:
            qr_buffer += KEY_MAP[key_name]


# =========================================================
# VICTORIANPASS VALIDATION
# =========================================================

def is_victorianpass(qr_data):

    if not qr_data:
        return False

    qr_data = qr_data.strip()

    # -----------------------------------------------------
    # Direct resident house number
    #
    # Example:
    # VH-2000
    # -----------------------------------------------------

    if qr_data.upper().startswith(VH_PREFIX):
        return True

    # -----------------------------------------------------
    # Old VP format
    #
    # Example:
    # VP-123456
    # -----------------------------------------------------

    if qr_data.upper().startswith(VP_PREFIX):
        return True

    # -----------------------------------------------------
    # Resident QR URL
    #
    # Example:
    # https://.../resident_qr_view.php?code=VH-2000
    # -----------------------------------------------------

    lower_qr = qr_data.lower()

    if "resident_qr_view.php?code=" in lower_qr:
        return True

    return False


# =========================================================
# EXTRACT RESIDENT HOUSE NUMBER
# =========================================================

def extract_resident_code(qr_data):

    if not qr_data:
        return None

    qr_data = qr_data.strip()

    # -----------------------------------------------------
    # Already a house number
    # -----------------------------------------------------

    if qr_data.upper().startswith(VH_PREFIX):
        return qr_data.upper()

    # -----------------------------------------------------
    # Find code= in the URL
    # -----------------------------------------------------

    marker = "code="

    lower_qr = qr_data.lower()

    position = lower_qr.find(marker)

    if position != -1:

        code = qr_data[position + len(marker):]

        # Remove anything after the code if necessary
        code = code.split("&")[0]
        code = code.strip()

        if code.upper().startswith(VH_PREFIX):
            return code.upper()

    return None


# =========================================================
# CLOSE SCANNER
# =========================================================

def close_scanner(scanner):

    if scanner:

        try:
            scanner.close()

        except Exception:
            pass

        print("GM65 scanner closed.")