import lgpio
import time

DOUT_PIN = 5
SCK_PIN = 6

CALIBRATION_FACTOR = -8.47685
ZERO_OFFSET = -100039.65

h = None


def start_weight():
    global h

    h = lgpio.gpiochip_open(0)

    lgpio.gpio_claim_input(h, DOUT_PIN)
    lgpio.gpio_claim_output(h, SCK_PIN, 0)

    print("HX711 ready.")


def wait_ready():
    while lgpio.gpio_read(h, DOUT_PIN) == 1:
        time.sleep(0.001)


def read_hx711():
    wait_ready()

    value = 0

    for _ in range(24):
        lgpio.gpio_write(h, SCK_PIN, 1)

        value = (
            value << 1
        ) | lgpio.gpio_read(h, DOUT_PIN)

        lgpio.gpio_write(h, SCK_PIN, 0)

    lgpio.gpio_write(h, SCK_PIN, 1)
    lgpio.gpio_write(h, SCK_PIN, 0)

    if value & 0x800000:
        value -= 0x1000000

    return value


def average_reading(samples=10):
    total = 0

    for _ in range(samples):
        total += read_hx711()

    return total / samples


def get_weight(samples=5):
    raw = average_reading(samples)

    weight = (
        raw - ZERO_OFFSET
    ) / CALIBRATION_FACTOR

    if weight < 0:
        weight = 0

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