import lgpio
import time

from smbus2 import SMBus

from qr_scanner import (
    start_scanner,
    read_qr,
    is_victorianpass,
    close_scanner
)

from Weight import (
    start_weight,
    measure_incentive,
    close_weight
)


SENSOR_GPIO = 17

LCD_ADDRESS = 0x27
LCD_BUS = 1
LCD_WIDTH = 16

IDLE_TIMEOUT = 120

MAX_SESSIONS = 3
DAILY_POINT_CAP = 250

METAL_STABLE_TIME = 3


LCD_CHR = 1
LCD_CMD = 0

LCD_LINE_1 = 0x80
LCD_LINE_2 = 0xC0

ENABLE = 0b00000100

E_PULSE = 0.0005
E_DELAY = 0.0005


bus = SMBus(LCD_BUS)

sensor = None
scanner = None


def lcd_toggle_enable(bits):

    time.sleep(E_DELAY)

    bus.write_byte(
        LCD_ADDRESS,
        bits | ENABLE
    )

    time.sleep(E_PULSE)

    bus.write_byte(
        LCD_ADDRESS,
        bits & ~ENABLE
    )

    time.sleep(E_DELAY)


def lcd_byte(bits, mode):

    high_bits = (
        mode |
        (bits & 0xF0) |
        0x08
    )

    low_bits = (
        mode |
        ((bits << 4) & 0xF0) |
        0x08
    )

    bus.write_byte(
        LCD_ADDRESS,
        high_bits
    )

    lcd_toggle_enable(high_bits)

    bus.write_byte(
        LCD_ADDRESS,
        low_bits
    )

    lcd_toggle_enable(low_bits)


def lcd_init():

    lcd_byte(0x33, LCD_CMD)
    lcd_byte(0x32, LCD_CMD)
    lcd_byte(0x06, LCD_CMD)
    lcd_byte(0x0C, LCD_CMD)
    lcd_byte(0x28, LCD_CMD)
    lcd_byte(0x01, LCD_CMD)

    time.sleep(0.005)


def lcd_display(message, line):

    message = str(message).ljust(LCD_WIDTH)

    lcd_byte(
        line,
        LCD_CMD
    )

    for character in message[:LCD_WIDTH]:

        lcd_byte(
            ord(character),
            LCD_CHR
        )


def show_scan():

    lcd_display(
        "Scan Victorian",
        LCD_LINE_1
    )

    lcd_display(
        "Pass QR",
        LCD_LINE_2
    )


def show_user(vp_number):

    lcd_display(
        vp_number,
        LCD_LINE_1
    )

    lcd_display(
        "Points:",
        LCD_LINE_2
    )


def show_points(vp_number, points):

    lcd_display(
        vp_number,
        LCD_LINE_1
    )

    lcd_display(
        "Points:" + str(round(points, 1)),
        LCD_LINE_2
    )


def metal_detected():

    return lgpio.gpio_read(
        sensor,
        SENSOR_GPIO
    ) == 0


def wait_for_metal_stable():

    stable_start = None

    while True:

        if metal_detected():

            if stable_start is None:
                stable_start = time.time()

            elapsed = (
                time.time() -
                stable_start
            )

            if elapsed >= METAL_STABLE_TIME:

                return True

        else:

            stable_start = None

        time.sleep(0.05)


def process_item(vp_number, material, current_points):

    print()
    print("Metal stable for 3 seconds.")
    print("Starting weight measurement.")

    weight, points = measure_incentive(
        material
    )

    remaining = (
        DAILY_POINT_CAP -
        current_points
    )

    if points > remaining:
        points = max(0, remaining)

    current_points += points

    print("--------------------------------")
    print("ITEM PROCESSED")
    print("--------------------------------")
    print("User:", vp_number)
    print("Material:", material)
    print("Weight:", round(weight, 2), "g")
    print("Incentive:", round(points, 2), "points")
    print("Daily total:", round(current_points, 2), "points")
    print("--------------------------------")
    print()

    show_points(
        vp_number,
        current_points
    )

    return current_points


try:

    lcd_init()

    sensor = lgpio.gpiochip_open(0)

    lgpio.gpio_claim_input(
        sensor,
        SENSOR_GPIO
    )

    scanner = start_scanner()

    start_weight()

    print()
    print("================================")
    print("VICTORIANPASS WASTE BIN")
    print("================================")
    print("System LOCKED")
    print("Waiting for QR code...")
    print()

    show_scan()

    while True:

        qr_data = read_qr(scanner)

        print("QR scanned:", qr_data)

        if not is_victorianpass(qr_data):

            print("Invalid QR.")
            print("VictorianPass code required.")
            print()

            lcd_display(
                "Invalid QR",
                LCD_LINE_1
            )

            lcd_display(
                "Scan VP QR",
                LCD_LINE_2
            )

            time.sleep(2)

            show_scan()

            continue

        vp_number = qr_data

        sessions = 0
        current_points = 0

        last_activity = time.time()

        print()
        print("--------------------------------")
        print("USER SESSION STARTED")
        print("--------------------------------")
        print("User:", vp_number)
        print("Sessions: 0 / 3")
        print("Points: 0 / 250")
        print("--------------------------------")
        print()

        show_user(vp_number)

        while True:

            if time.time() - last_activity >= IDLE_TIMEOUT:

                print()
                print("2-minute idle timeout.")
                print("Session locked.")
                print()

                show_scan()

                break

            if sessions >= MAX_SESSIONS:

                print()
                print("--------------------------------")
                print("DAILY SESSION LIMIT REACHED")
                print("--------------------------------")
                print("User:", vp_number)
                print("Sessions:", sessions, "/ 3")
                print("Points:", round(current_points, 2), "/ 250")
                print("--------------------------------")
                print()

                lcd_display(
                    "3 Sessions Used",
                    LCD_LINE_1
                )

                lcd_display(
                    "Scan Next Day",
                    LCD_LINE_2
                )

                time.sleep(3)

                show_scan()

                break

            if current_points >= DAILY_POINT_CAP:

                print()
                print("--------------------------------")
                print("DAILY POINT CAP REACHED")
                print("--------------------------------")
                print("User:", vp_number)
                print("Points:", round(current_points, 2))
                print("--------------------------------")
                print()

                lcd_display(
                    "250 Point Limit",
                    LCD_LINE_1
                )

                lcd_display(
                    "Reached",
                    LCD_LINE_2
                )

                time.sleep(3)

                show_scan()

                break

            if metal_detected():

                last_activity = time.time()

                print()
                print("Metal detected.")
                print("Checking stability...")

                if wait_for_metal_stable():

                    last_activity = time.time()

                    current_points = process_item(
                        vp_number,
                        "aluminum",
                        current_points
                    )

                    sessions += 1

                    print("Session:", sessions, "/", MAX_SESSIONS)
                    print("Daily points:",
                          round(current_points, 2),
                          "/",
                          DAILY_POINT_CAP)
                    print()

                    show_points(
                        vp_number,
                        current_points
                    )

                    time.sleep(1)

            else:

                time.sleep(0.1)


except KeyboardInterrupt:

    print()
    print("System stopped.")


finally:

    try:
        close_scanner(scanner)
    except:
        pass

    try:
        close_weight()
    except:
        pass

    try:

        if sensor is not None:
            lgpio.gpiochip_close(sensor)

    except:
        pass

    try:
        bus.close()
    except:
        pass

    print("System released.")