from ultralytics import YOLO
import cv2
import requests
import threading
import time
import numpy as np
from flask import Flask, Response, jsonify

ESP32_IP = "192.168.100.138"
CAPTURE_URL = f"http://{ESP32_IP}/capture"

MODEL = "yolo11n.pt"
CONFIDENCE = 0.30
YOLO_SIZE = 320

CAPTURE_INTERVAL = 0.4
STABLE_TIME = 3.0

MATERIALS = {
    "bottle": "Plastic",
    "book": "Paper"
}

app = Flask(__name__)

frame = None
current_detection = None
current_material = None
detection_start = None
confirmed_material = None

lock = threading.Lock()

print("Loading YOLO...")
model = YOLO(MODEL)
print("YOLO ready.")


def camera():
    global frame

    print("Connecting to:")
    print(CAPTURE_URL)

    session = requests.Session()

    while True:
        start_time = time.time()

        try:
            response = session.get(
                CAPTURE_URL,
                timeout=3
            )

            if response.status_code == 200:
                image = cv2.imdecode(
                    np.frombuffer(
                        response.content,
                        dtype=np.uint8
                    ),
                    cv2.IMREAD_COLOR
                )

                if image is not None:
                    with lock:
                        frame = image

            else:
                print(
                    "Camera HTTP error:",
                    response.status_code
                )

        except Exception as error:
            print("Camera error:", error)

        elapsed = time.time() - start_time
        remaining = CAPTURE_INTERVAL - elapsed

        if remaining > 0:
            time.sleep(remaining)


def detect():
    global current_detection
    global current_material
    global detection_start
    global confirmed_material

    while True:

        with lock:
            if frame is None:
                image = None
            else:
                image = frame.copy()

        if image is None:
            time.sleep(0.05)
            continue

        small = cv2.resize(
            image,
            (320, 240)
        )

        results = model(
            small,
            imgsz=YOLO_SIZE,
            conf=CONFIDENCE,
            verbose=False
        )

        best_object = None
        best_area = 0

        for box in results[0].boxes:

            class_id = int(box.cls[0])
            confidence = float(box.conf[0])

            class_name = model.names[class_id].lower()

            if class_name not in MATERIALS:
                continue

            x1, y1, x2, y2 = map(
                int,
                box.xyxy[0]
            )

            width = max(0, x2 - x1)
            height = max(0, y2 - y1)

            area = width * height

            if area > best_area:
                best_area = area

                best_object = (
                    MATERIALS[class_name],
                    confidence,
                    x1,
                    y1,
                    x2,
                    y2
                )

        if best_object is None:

            with lock:
                current_detection = None
                current_material = None
                detection_start = None
                confirmed_material = None

            time.sleep(0.05)
            continue

        (
            material,
            confidence,
            x1,
            y1,
            x2,
            y2
        ) = best_object

        with lock:

            current_detection = best_object

            if current_material is None:

                current_material = material
                detection_start = time.time()
                confirmed_material = None

            elif current_material != material:

                current_material = material
                detection_start = time.time()
                confirmed_material = None

            else:

                elapsed = time.time() - detection_start

                if elapsed >= STABLE_TIME:
                    confirmed_material = material

        time.sleep(0.05)


def video():

    while True:

        with lock:

            if frame is None:
                image = None
            else:
                image = frame.copy()

            detection = current_detection
            start = detection_start
            confirmed = confirmed_material

        if image is None:
            time.sleep(0.05)
            continue

        if detection is not None:

            (
                material,
                confidence,
                x1,
                y1,
                x2,
                y2
            ) = detection

            height, width = image.shape[:2]

            x1 = int(x1 * width / 320)
            x2 = int(x2 * width / 320)
            y1 = int(y1 * height / 240)
            y2 = int(y2 * height / 240)

            cv2.rectangle(
                image,
                (x1, y1),
                (x2, y2),
                (0, 255, 0),
                2
            )

            if start is not None:
                elapsed = time.time() - start
                elapsed = min(
                    elapsed,
                    STABLE_TIME
                )
            else:
                elapsed = 0

            if confirmed == material:

                text = (
                    f"{material} "
                    f"{confidence:.0%} "
                    f"CONFIRMED"
                )

            else:

                text = (
                    f"{material} "
                    f"{confidence:.0%} "
                    f"{elapsed:.1f}/{STABLE_TIME:.0f}s"
                )

            cv2.putText(
                image,
                text,
                (
                    x1,
                    max(y1 - 10, 25)
                ),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.6,
                (0, 255, 0),
                2
            )

            if confirmed == material:

                cv2.putText(
                    image,
                    "MATERIAL CONFIRMED",
                    (10, 30),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.8,
                    (0, 255, 0),
                    2
                )

        success, encoded = cv2.imencode(
            ".jpg",
            image,
            [
                cv2.IMWRITE_JPEG_QUALITY,
                85
            ]
        )

        if not success:
            continue

        yield (
            b"--frame\r\n"
            b"Content-Type: image/jpeg\r\n\r\n"
            + encoded.tobytes()
            + b"\r\n"
        )


@app.route("/")
def home():

    return """
    <html>
    <head>
        <title>VHEcoPoint ESP32-CAM</title>
    </head>

    <body style="
        margin: 0;
        background: black;
        overflow: hidden;
    ">

        <img
            src="/video"
            style="
                width: 100vw;
                height: 100vh;
                object-fit: contain;
            "
        >

    </body>
    </html>
    """


@app.route("/video")
def stream():

    return Response(
        video(),
        mimetype="multipart/x-mixed-replace; boundary=frame"
    )


@app.route("/status")
def status():

    with lock:

        if current_material is None:

            return jsonify({
                "detected": False,
                "material": None,
                "confirmed": False,
                "stable_seconds": 0,
                "required_seconds": STABLE_TIME
            })

        if detection_start is not None:
            elapsed = time.time() - detection_start
        else:
            elapsed = 0

        return jsonify({
            "detected": True,
            "material": current_material,
            "confirmed": (
                confirmed_material == current_material
            ),
            "stable_seconds": round(
                elapsed,
                2
            ),
            "required_seconds": STABLE_TIME
        })


if __name__ == "__main__":

    print()
    print("================================")
    print("VHEcoPoint ESP32-CAM + YOLO")
    print("================================")
    print()

    print("Camera:")
    print(CAPTURE_URL)

    print()
    print("Capture rate: approximately 2.5 FPS")
    print("Material stability: 3 seconds")
    print()
    print("bottle -> Plastic")
    print("book   -> Paper")
    print()

    threading.Thread(
        target=camera,
        daemon=True
    ).start()

    threading.Thread(
        target=detect,
        daemon=True
    ).start()

    app.run(
        host="0.0.0.0",
        port=5000,
        threaded=True
    )