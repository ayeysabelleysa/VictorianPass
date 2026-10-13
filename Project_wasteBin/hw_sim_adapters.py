#!/usr/bin/env python3
"""VHEcoPoint — dry-run hardware adapters for SIMULATION MODE.

These classes let the *real* station decision logic run end-to-end with NO
hardware attached and NO database, while recording exactly what the servos
would have done and letting the load cell / camera / inductive sensor be
scripted through the same situations the physical machine hits.

They deliberately do NOT import board/busio/adafruit/gpiozero/RPi or the
production offline queue, so this file is safe to import on any machine.

Fidelity notes (constants mirrored from the real modules):

  Servo.py
      MG995_CHANNEL   = 12   (downward / release flap)
      MG996R_CHANNEL  = 14   (horizontal diverter)
      MG996R_LEFT     = 45
      MG996R_CENTER   = 90
      MG996R_RIGHT    = 135
      MG995_HOME      = 90
      MG995_RELEASE   = 150
      SERVO_MIN_US    = 500
      SERVO_MAX_US    = 2500
      pulse = SERVO_MIN_US + angle/180 * (SERVO_MAX_US - SERVO_MIN_US)

  Main.py
      MIN_WEIGHT = 6.0        MAX_WEIGHT = 2000.0
      METAL_STABLE_TIME = 0.5 CAMERA_TIMEOUT = 0.5
      CAMERA_CLASSIFICATION_TIMEOUT = 8.0

DryRunServo.sort_material() reproduces Servo.sort_material() command order
exactly, so the verifier can assert the full actuation sequence:

      Plastic  -> ch14 LEFT(45)  ; ch12 RELEASE(150) ; ch12 HOME(90) ; ch14 CENTER(90)
      Paper    -> ch14 CENTER(90); ch12 RELEASE(150) ; ch12 HOME(90) ; ch14 CENTER(90)
      Aluminum -> ch14 RIGHT(135); ch12 RELEASE(150) ; ch12 HOME(90) ; ch14 CENTER(90)
"""

import time


# ---------------------------------------------------------------------
# Servo constants (mirrored from Servo.py)
# ---------------------------------------------------------------------
I2C_BUS = 1
PCA9685_ADDRESS = 0x40
MG995_CHANNEL = 12
MG996R_CHANNEL = 14
PWM_FREQUENCY = 50
MG996R_LEFT = 45
MG996R_CENTER = 90
MG996R_RIGHT = 135
MG995_HOME = 90
MG995_RELEASE = 150
SERVO_MIN_US = 500
SERVO_MAX_US = 2500

# ---------------------------------------------------------------------
# Station constants (mirrored from Main.py)
# ---------------------------------------------------------------------
MIN_WEIGHT = 6.0
MAX_WEIGHT = 2000.0
METAL_STABLE_TIME = 0.5
CAMERA_TIMEOUT = 0.5
CAMERA_CLASSIFICATION_TIMEOUT = 8.0


def angle_to_pulse(angle, min_us=SERVO_MIN_US, max_us=SERVO_MAX_US):
    angle = max(0.0, min(180.0, float(angle)))
    return min_us + (angle / 180.0) * (max_us - min_us)


class DryRunServo:
    """Records the exact servo commands the real machine would emit."""

    def __init__(self, fail_mode=None, speed=0.0):
        # fail_mode: None            -> normal
        #            "raise"         -> raise on release (simulates a dead servo)
        #            "return_false"  -> unknown material, no sort
        self.fail_mode = fail_mode
        self.speed = speed               # time.sleep multiplier (0 = instant)
        self.started = False
        self.commands = []               # list of dicts
        self.actuated = None             # last sort_material return value
        self._sorting = False            # only fail while actually sorting

    # -- PCA9685 lifecycle -------------------------------------------
    def start(self):
        if self.started:
            return
        self.started = True
        self.mg996r_center()
        self.mg995_home()
        self._sleep(0.5)

    def close(self):
        if not self.started:
            return
        self.mg995_home()
        self.mg996r_center()
        self.started = False

    # -- low level ---------------------------------------------------
    def _sleep(self, seconds):
        time.sleep(seconds * self.speed)

    def _record(self, channel, angle):
        angle = max(0.0, min(180.0, float(angle)))
        pulse = angle_to_pulse(angle)
        self.commands.append({
            "channel": channel,
            "angle": round(angle, 3),
            "pulse_us": round(pulse, 3),
            "move_time": 0.5,
        })

    def move_servo(self, channel, angle, move_time=0.5):
        if not self.started:
            raise RuntimeError(
                "Servo system has not been started. Call start() first.")
        self._record(channel, angle)
        if self.fail_mode == "raise" and self._sorting and channel == MG995_CHANNEL:
            raise RuntimeError("simulated servo failure on release flap")
        self._sleep(move_time)

    # -- named helpers (mirror Servo.py) -----------------------------
    def mg996r_left(self):
        self.move_servo(MG996R_CHANNEL, MG996R_LEFT)

    def mg996r_center(self):
        self.move_servo(MG996R_CHANNEL, MG996R_CENTER)

    def mg996r_right(self):
        self.move_servo(MG996R_CHANNEL, MG996R_RIGHT)

    def mg995_home(self):
        self.move_servo(MG995_CHANNEL, MG995_HOME)

    def mg995_release(self):
        self.move_servo(MG995_CHANNEL, MG995_RELEASE)

    # -- material sorting (mirror Servo.sort_material) ---------------
    def sort_material(self, material):
        if not self.started:
            raise RuntimeError(
                "Servo system has not been started. Call start() first.")
        if self.fail_mode == "return_false":
            self.actuated = False
            return False

        self._sorting = True
        try:
            return self._do_sort(material)
        finally:
            self._sorting = False

    def _do_sort(self, material):
        material = str(material).strip().lower()
        if material in ("plastic", "pet", "bottle"):
            self.mg996r_left()
        elif material in ("paper", "cardboard", "paper_cardboard"):
            self.mg996r_center()
        elif material in ("aluminum", "metal", "can"):
            self.mg996r_right()
        else:
            self.mg996r_center()
            self.actuated = False
            return False

        self.mg995_release()
        self._sleep(0.7)
        self.mg995_home()
        self._sleep(0.3)
        self.mg996r_center()
        self.actuated = True
        return True

    # -- introspection ----------------------------------------------
    def channel_angle_pairs(self):
        return [(c["channel"], c["angle"]) for c in self.commands]

    def reset_recording(self):
        self.commands = []
        self.actuated = None


def expected_servo_commands(material):
    """The exact (channel, angle) sequence Servo.sort_material() emits."""
    material = str(material).strip().lower()
    diverter = {
        "plastic": MG996R_LEFT, "pet": MG996R_LEFT, "bottle": MG996R_LEFT,
        "paper": MG996R_CENTER, "cardboard": MG996R_CENTER,
        "paper_cardboard": MG996R_CENTER,
        "aluminum": MG996R_RIGHT, "metal": MG996R_RIGHT, "can": MG996R_RIGHT,
    }.get(material, MG996R_CENTER)
    sequence = [(MG996R_CHANNEL, float(diverter))]
    sequence.append((MG995_CHANNEL, float(MG995_RELEASE)))
    sequence.append((MG995_CHANNEL, float(MG995_HOME)))
    sequence.append((MG996R_CHANNEL, float(MG996R_CENTER)))
    return sequence


class DryRunLoadCell:
    """Scriptable stand-in for Weight.py.

    mode:
        "stable"    -> get_weight() returns the configured grams
        "zero"      -> get_weight() always returns 0.0 (< MIN_WEIGHT)
        "unstable"  -> readings keep changing, never inside tolerance
        "timeout"   -> get_weight() returns None forever (no HX711)
        "failure"   -> get_weight() raises IOError
    """

    def __init__(self, grams=0.0, mode="stable", script=None, tolerance_g=2.0):
        self.grams = float(grams)
        self.mode = mode
        self.script = list(script) if script else None
        self.tolerance_g = tolerance_g
        self._i = 0
        self.started = False
        self.reads = 0

    def start_weight(self):
        self.started = True

    def close_weight(self):
        self.started = False

    def is_valid_raw(self, value):
        return value is not None

    def read_hx711(self):
        return self.get_weight()

    def get_weight(self, samples=5):
        self.reads += 1
        if self.mode == "failure":
            raise IOError("simulated HX711 read failure")
        if self.mode == "timeout":
            return None
        if self.mode == "zero":
            return 0.0
        if self.mode == "unstable":
            self._i += 1
            return 1000.0 + (self._i % 7) * 250.0
        if self.script:
            value = self.script[min(self._i, len(self.script) - 1)]
            self._i += 1
            return float(value)
        return self.grams

    def average_reading(self, samples=10):
        return self.get_weight(samples=samples)

    def wait_ready(self, timeout=2):
        if self.mode in ("timeout", "failure"):
            return False
        return True

    def wait_for_weight_stable(self, timeout=3.0, required=3, tolerance_g=None):
        """Mirror Main.wait_for_weight_stable(): needs `required` consecutive
        samples within tolerance_g of the previous reading."""
        tol = self.tolerance_g if tolerance_g is None else tolerance_g
        if self.mode in ("timeout", "failure"):
            return None
        if self.mode == "unstable":
            return None
        last = None
        stable = 0
        deadline = time.time() + timeout
        while time.time() < deadline:
            weight = self.get_weight()
            if weight is None:
                return None
            if last is not None and abs(weight - last) <= tol:
                stable += 1
                if stable >= required:
                    return weight
            else:
                stable = 0
                last = weight
            time.sleep(0.02)
        return None


class DryRunCamera:
    """Scriptable stand-in for the camera service polled by
    Main.get_camera_material().

    mode:
        "Plastic"/"Paper" -> confirmed classification
        "Mixed"           -> mixed-material rejection
        "Unclassified"    -> available but never confirms (-> inductive path)
        "Unavailable"     -> never answers (-> 'UNAVAILABLE')
    """

    def __init__(self, mode="Plastic"):
        self.mode = mode
        self.started = False
        self.polls = 0

    def start(self):
        self.started = True

    def reset(self):
        self.polls = 0

    def camera_status(self):
        self.polls += 1
        if self.mode == "Unavailable":
            return None
        if self.mode == "Mixed":
            return {"camera_available": True, "confirmed": False,
                    "mixed": True, "material": None}
        if self.mode in ("Plastic", "Paper"):
            return {"camera_available": True, "confirmed": True,
                    "mixed": False, "material": self.mode}
        return {"camera_available": True, "confirmed": False,
                "mixed": False, "material": None}

    def get_camera_material(self, timeout=None):
        """Mirror Main.get_camera_material() return values exactly:
        'Plastic'/'Paper'/'Mixed', None (could not classify), or
        'UNAVAILABLE' (service/camera down)."""
        if self.mode in ("Plastic", "Paper", "Mixed"):
            return self.mode
        if self.mode == "Unavailable":
            return "UNAVAILABLE"
        return None


class DryRunInductive:
    """Scriptable stand-in for inductive.py.

    metal_script: list of booleans returned on successive metal_detected()
    calls; the last value repeats.
    """

    def __init__(self, metal_detected=False, metal_script=None):
        self.metal = bool(metal_detected)
        self.metal_script = list(metal_script) if metal_script else None
        self._i = 0
        self.started = False

    def start_inductive(self):
        self.started = True

    def close_inductive(self):
        self.started = False

    def metal_detected(self):
        if self.metal_script:
            value = self.metal_script[min(self._i, len(self.metal_script) - 1)]
            self._i += 1
            return bool(value)
        return self.metal

    def wait_for_metal_stable(self, seconds=METAL_STABLE_TIME):
        """Mirror inductive.wait_for_metal_stable()."""
        deadline = time.time() + seconds
        while time.time() < deadline:
            if not self.metal_detected():
                return False
            time.sleep(0.02)
        return True


def simulate_item_classification(camera, inductive):
    """Mirror the classification branches of Main.process_item().

    Returns one of: "Plastic", "Paper", "Aluminum", "Mixed",
    "Unrecognized", "UNAVAILABLE".
    """
    camera_material = camera.get_camera_material()

    if camera_material == "Mixed":
        return "Mixed"

    if camera_material in ("Plastic", "Paper"):
        return camera_material

    if camera_material == "UNAVAILABLE":
        # Camera service down: the station also falls back to the
        # inductive sensor, but here we surface the camera failure.
        return "UNAVAILABLE"

    # Camera could not classify -> inductive fallback (this is the ONLY
    # path that can ever accept aluminum).
    if inductive.metal_detected():
        if not inductive.wait_for_metal_stable(METAL_STABLE_TIME):
            return "Unrecognized"
        return "Aluminum"

    return "Unrecognized"


# ---------------------------------------------------------------------
# Self-check (no hardware, no DB, no network)
# ---------------------------------------------------------------------
def _selfcheck():
    ok = []

    servo = DryRunServo()
    servo.start()
    servo.reset_recording()
    assert servo.sort_material("plastic") is True
    assert servo.channel_angle_pairs() == expected_servo_commands("plastic"), \
        servo.channel_angle_pairs()
    assert all(500 <= c["pulse_us"] <= 2500 for c in servo.commands)
    ok.append("plastic servo sequence + pulse range")

    servo.reset_recording()
    assert servo.sort_material("aluminum") is True
    assert servo.channel_angle_pairs() == [
        (MG996R_CHANNEL, 135.0), (MG995_CHANNEL, 150.0),
        (MG995_CHANNEL, 90.0), (MG996R_CHANNEL, 90.0)], servo.channel_angle_pairs()
    ok.append("aluminum servo sequence")

    bad = DryRunServo(fail_mode="raise")
    bad.start()
    raised = False
    try:
        bad.sort_material("plastic")
    except RuntimeError:
        raised = True
    assert raised
    ok.append("servo failure raises")

    assert DryRunLoadCell(grams=120.0).get_weight() == 120.0
    assert DryRunLoadCell(mode="zero").get_weight() == 0.0
    assert DryRunLoadCell(mode="timeout").get_weight() is None
    assert DryRunLoadCell(mode="unstable").wait_for_weight_stable() is None
    ok.append("load cell modes")

    assert simulate_item_classification(
        DryRunCamera("Plastic"), DryRunInductive(False)) == "Plastic"
    assert simulate_item_classification(
        DryRunCamera("Paper"), DryRunInductive(False)) == "Paper"
    assert simulate_item_classification(
        DryRunCamera("Mixed"), DryRunInductive(False)) == "Mixed"
    assert simulate_item_classification(
        DryRunCamera("Unclassified"), DryRunInductive(True)) == "Aluminum"
    assert simulate_item_classification(
        DryRunCamera("Unclassified"), DryRunInductive(False)) == "Unrecognized"
    assert simulate_item_classification(
        DryRunCamera("Unavailable"), DryRunInductive(False)) == "UNAVAILABLE"
    ok.append("classification branches")

    print("hw_sim_adapters self-check OK:")
    for line in ok:
        print("  -", line)


if __name__ == "__main__":
    _selfcheck()