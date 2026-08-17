import time
from evdev import InputDevice, ecodes

QR_DEVICE = "/dev/input/event4"
VP_PREFIX = "VP-"

KEY_MAP = {
    "KEY_0": "0", "KEY_1": "1", "KEY_2": "2",
    "KEY_3": "3", "KEY_4": "4", "KEY_5": "5",
    "KEY_6": "6", "KEY_7": "7", "KEY_8": "8",
    "KEY_9": "9",

    "KEY_A": "A", "KEY_B": "B", "KEY_C": "C",
    "KEY_D": "D", "KEY_E": "E", "KEY_F": "F",
    "KEY_G": "G", "KEY_H": "H", "KEY_I": "I",
    "KEY_J": "J", "KEY_K": "K", "KEY_L": "L",
    "KEY_M": "M", "KEY_N": "N", "KEY_O": "O",
    "KEY_P": "P", "KEY_Q": "Q", "KEY_R": "R",
    "KEY_S": "S", "KEY_T": "T", "KEY_U": "U",
    "KEY_V": "V", "KEY_W": "W", "KEY_X": "X",
    "KEY_Y": "Y", "KEY_Z": "Z",

    "KEY_MINUS": "-"
}


def start_scanner():
    scanner = InputDevice(QR_DEVICE)

    print("GM65 QR scanner started.")
    print("Device:", scanner.name)
    print("Device:", QR_DEVICE)

    return scanner


def read_qr(scanner):

    qr_buffer = ""

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

        if event.value != 1:
            continue

        key_name = ecodes.KEY.get(event.code)

        if isinstance(key_name, list):
            key_name = key_name[0]

        if key_name == "KEY_CAPSLOCK":
            continue

        if key_name == "KEY_ENTER":

            if qr_buffer:
                return qr_buffer

            qr_buffer = ""

        elif key_name in KEY_MAP:
            qr_buffer += KEY_MAP[key_name]


def is_victorianpass(qr_data):

    if qr_data is None:
        return False

    return qr_data.startswith(VP_PREFIX)


def close_scanner(scanner):

    if scanner:

        try:
            scanner.close()
        except:
            pass

        print("GM65 scanner closed.")