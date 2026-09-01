import lgpio
import time

SENSOR_GPIO = 17

_sensor = None


def start_inductive():
    global _sensor

    _sensor = lgpio.gpiochip_open(0)
    lgpio.gpio_claim_input(_sensor, SENSOR_GPIO)

    print("Inductive sensor ready.")


def metal_detected():
    if _sensor is None:
        return False

    return lgpio.gpio_read(_sensor, SENSOR_GPIO) == 0


def wait_for_metal_stable(seconds=3):
    start = None

    while True:

        if metal_detected():

            if start is None:
                start = time.time()

            if time.time() - start >= seconds:
                return True

        else:
            start = None

        time.sleep(0.1)


def close_inductive():
    global _sensor

    if _sensor is not None:

        try:
            lgpio.gpiochip_close(_sensor)
        except:
            pass

        _sensor = None