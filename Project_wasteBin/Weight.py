import lgpio
import time

DOUT_PIN = 5
SCK_PIN = 6
CALIBRATION_FACTOR = 28.95
ZERO_OFFSET = -98306

MAX_WEIGHT_G = 2000.0
MAX_RAW_DELTA = MAX_WEIGHT_G * CALIBRATION_FACTOR
MAX_RAW_SPREAD = 2.0 * CALIBRATION_FACTOR
HX711_INVALID_RAW = (-1, 0, 8388607, -8388608)

h = None


def start_weight():
    global h
    h = lgpio.gpiochip_open(0)
    lgpio.gpio_claim_input(h, DOUT_PIN)
    lgpio.gpio_claim_output(h, SCK_PIN, 0)
    print("HX711 ready.")


def wait_ready(timeout=2):
    start = time.monotonic()

    while lgpio.gpio_read(h, DOUT_PIN) == 1:
        if time.monotonic() - start >= timeout:
            return False
        time.sleep(0.001)

    return True


def read_hx711():
    if not wait_ready():
        return None

    value = 0

    for _ in range(24):
        lgpio.gpio_write(h, SCK_PIN, 1)
        value = (value << 1) | lgpio.gpio_read(h, DOUT_PIN)
        lgpio.gpio_write(h, SCK_PIN, 0)

    lgpio.gpio_write(h, SCK_PIN, 1)
    lgpio.gpio_write(h, SCK_PIN, 0)

    if value & 0x800000:
        value -= 0x1000000

    return value


def is_valid_raw(value):
    if value is None:
        return False

    if value in HX711_INVALID_RAW:
        return False

    if abs(value - ZERO_OFFSET) > MAX_RAW_DELTA:
        return False

    return True


def average_reading(samples=10):
    readings = []

    for _ in range(samples):
        value = read_hx711()

        if value is None:
            return None

        if not is_valid_raw(value):
            return None

        readings.append(value)

    if not readings:
        return None

    if (max(readings) - min(readings)) > MAX_RAW_SPREAD:
        return None

    return sum(readings) / len(readings)


def get_weight(samples=5):
    """Read the platform weight in grams.

    Return values:

        0.0 or more  -> a genuine, valid reading.
                        0.0 means the platform really is
                        empty / near zero.

        None         -> the reading FAILED and must NOT be
                        trusted: HX711 not responding,
                        timeout, invalid raw data, noise
                        spread too wide, or overload.

    Callers must never treat None as "0 g". A missing or
    broken load cell has to be distinguishable from an
    empty platform, otherwise the station can detect
    phantom items or declare phantom removals.
    """
    raw = average_reading(samples)

    if raw is None:
        return None

    if not is_valid_raw(raw):
        return None

    weight = (raw - ZERO_OFFSET) / CALIBRATION_FACTOR

    if weight < 0:
        # Valid reading, platform simply not loaded (or
        # light tare drift). Genuine near-zero.
        weight = 0

    if weight > MAX_WEIGHT_G:
        # Valid raw value but out of the calibrated range:
        # treat as an unusable/overloaded reading, NOT as 0 g.
        return None

    return weight


def calculate_incentive(material, weight_grams):
    weight_kg = weight_grams / 1000
    material = material.lower()

    if material == "plastic":
        rate = 55
    elif material == "aluminum":
        rate = 140
    elif material in ("paper", "cardboard"):
        rate = 30
    else:
        return 0

    return weight_kg * rate


def measure_incentive(material):
    weight = get_weight(10)

    # Weight sensor failed: no incentive can be calculated.
    if weight is None:
        return None, 0

    points = calculate_incentive(material, weight)
    return weight, points


def close_weight():
    global h

    if h is not None:
        try:
            lgpio.gpiochip_close(h)
        except:
            pass

        h = None
