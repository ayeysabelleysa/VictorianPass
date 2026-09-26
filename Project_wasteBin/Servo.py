import time
import board
import busio
from adafruit_pca9685 import PCA9685 as AdafruitPCA9685


# ============================================================
# PCA9685 CONFIGURATION
# ============================================================

I2C_BUS = 1
PCA9685_ADDRESS = 0x40

# PCA9685 channels
MG995_CHANNEL = 12
MG996R_CHANNEL = 14

PWM_FREQUENCY = 50      # Standard servo frequency


# ============================================================
# SERVO CALIBRATION
# ============================================================
# These are INITIAL values.
# We will physically calibrate them on your actual mechanism.
#
# Do NOT assume these positions are mechanically correct yet.

# MG996R horizontal diverter
MG996R_LEFT = 45
MG996R_CENTER = 90
MG996R_RIGHT = 135

# MG995 downward/release flap
MG995_HOME = 90
MG995_RELEASE = 150


# Servo pulse limits
# Most MG995/MG996R servos work around this range,
# but the actual safe range depends on your servo.
SERVO_MIN_US = 500
SERVO_MAX_US = 2500


# ============================================================
# PCA9685 REGISTERS
# ============================================================

MODE1 = 0x00
PRESCALE = 0xFE

LED0_ON_L = 0x06


# ============================================================
# PCA9685 CLASS
# ============================================================

class PCA9685:

    def __init__(self, bus=I2C_BUS, address=PCA9685_ADDRESS):
        self.i2c = busio.I2C(board.SCL, board.SDA)
        self.pca = AdafruitPCA9685(self.i2c, address=address)
        self.pca.frequency = PWM_FREQUENCY

    def set_servo_pulse(self, channel, pulse_us):
        period_us = 1_000_000.0 / self.pca.frequency
        duty_cycle = int((pulse_us / period_us) * 65535)
        duty_cycle = max(0, min(65535, duty_cycle))
        self.pca.channels[channel].duty_cycle = duty_cycle

    def close(self):
        self.pca.deinit()


# ============================================================
# SERVO FUNCTIONS
# ============================================================

pca = None


def start():
    """
    Initialize the PCA9685.
    """

    global pca

    if pca is not None:
        return

    pca = PCA9685()

    # Put both servos into their safe starting positions.
    mg996r_center()
    mg995_home()

    time.sleep(0.5)

    print("[SERVO] PCA9685 initialized")
    print("[SERVO] MG995  -> Channel 12")
    print("[SERVO] MG996R -> Channel 14")


def angle_to_pulse(angle):
    """
    Convert servo angle (0-180 degrees)
    to pulse width.
    """

    angle = max(
        0,
        min(180, float(angle))
    )

    pulse = (
        SERVO_MIN_US
        + (angle / 180.0)
        * (SERVO_MAX_US - SERVO_MIN_US)
    )

    return pulse


def move_servo(channel, angle, move_time=0.5):
    """
    Move a servo to an angle.

    A small delay is used after movement so the
    mechanical system has time to settle.
    """

    if pca is None:
        raise RuntimeError(
            "Servo system has not been started. "
            "Call start() first."
        )

    angle = max(
        0,
        min(180, float(angle))
    )

    pulse = angle_to_pulse(angle)

    print(
        f"[SERVO] Channel {channel} "
        f"-> {angle:.1f} degrees "
        f"({pulse:.0f} us)"
    )

    pca.set_servo_pulse(
        channel,
        pulse
    )

    time.sleep(move_time)


# ============================================================
# MG996R - HORIZONTAL DIVERTER
# ============================================================

def mg996r_left():
    move_servo(
        MG996R_CHANNEL,
        MG996R_LEFT
    )


def mg996r_center():
    move_servo(
        MG996R_CHANNEL,
        MG996R_CENTER
    )


def mg996r_right():
    move_servo(
        MG996R_CHANNEL,
        MG996R_RIGHT
    )


# ============================================================
# MG995 - DOWNWARD / RELEASE FLAP
# ============================================================

def mg995_home():
    move_servo(
        MG995_CHANNEL,
        MG995_HOME
    )


def mg995_release():
    move_servo(
        MG995_CHANNEL,
        MG995_RELEASE
    )


# ============================================================
# MATERIAL SORTING
# ============================================================

def sort_material(material):
    """
    Redirect one accepted item into its correct bin.

    Material mapping:

        Paper/Cardboard -> LEFT
        Plastic/PET     -> CENTER
        Aluminum/Metal  -> RIGHT
    """

    if pca is None:
        raise RuntimeError(
            "Servo system has not been started. "
            "Call start() first."
        )

    material = str(material).strip().lower()

    print(
        f"[SERVO] Sorting material: {material}"
    )

    # --------------------------------------------------------
    # Select horizontal chute
    # --------------------------------------------------------

    if material in (
        "paper",
        "cardboard",
        "paper_cardboard"
    ):
        mg996r_left()

    elif material in (
        "plastic",
        "pet",
        "bottle"
    ):
        mg996r_center()

    elif material in (
        "aluminum",
        "metal",
        "can"
    ):
        mg996r_right()

    else:
        print(
            f"[SERVO] Unknown material: {material}"
        )

        # Safety: return diverter to center.
        mg996r_center()

        return False

    # --------------------------------------------------------
    # Release item
    # --------------------------------------------------------

    mg995_release()

    # Give the item time to slide/fall.
    time.sleep(0.7)

    # Return release flap to home.
    mg995_home()

    # Small settling delay.
    time.sleep(0.3)

    return True


# ============================================================
# SHUTDOWN
# ============================================================

def close():
    """
    Return both servos to safe positions
    and close I2C.
    """

    global pca

    if pca is None:
        return

    try:
        mg995_home()
        mg996r_center()

    finally:
        pca.close()
        pca = None

    print("[SERVO] Closed")


# ============================================================
# DIRECT TEST
# ============================================================

if __name__ == "__main__":

    print("================================")
    print(" VHEcoPoint Servo Test")
    print("================================")
    print()
    print("MG995  = Channel 12")
    print("MG996R = Channel 15")
    print()

    try:

        start()

        print()
        print("Testing MG996R...")
        print("LEFT")
        mg996r_left()

        time.sleep(1)

        print("CENTER")
        mg996r_center()

        time.sleep(1)

        print("RIGHT")
        mg996r_right()

        time.sleep(1)

        print("CENTER")
        mg996r_center()

        time.sleep(1)

        print()
        print("Testing MG995...")
        print("HOME")
        mg995_home()

        time.sleep(1)

        print("RELEASE")
        mg995_release()

        time.sleep(1)

        print("HOME")
        mg995_home()

        print()
        print("Servo test completed.")

    except KeyboardInterrupt:
        print()
        print("Test stopped by user.")

    except Exception as e:
        print()
        print("[SERVO ERROR]")
        print(e)

    finally:
        close()
