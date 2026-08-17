import requests
import time

ESP32_IP = "192.168.100.138"
CAPTURE_URL = f"http://{ESP32_IP}/capture"

print("================================")
print("ESP32-CAM TEST")
print("================================")

frame = 0

while True:

    try:
        response = requests.get(
            CAPTURE_URL,
            timeout=5
        )

        if response.status_code == 200:

            frame += 1

            filename = f"frame_{frame}.jpg"

            with open(filename, "wb") as file:
                file.write(response.content)

            print(
                f"Frame {frame}: "
                f"{len(response.content)} bytes"
            )

        else:

            print(
                "Camera returned:",
                response.status_code
            )

    except Exception as error:

        print("Camera error:", error)

    time.sleep(0.5)
