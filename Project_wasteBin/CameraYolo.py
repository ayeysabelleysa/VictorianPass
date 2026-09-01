from ultralytics import YOLO
import cv2
import threading
import time
from flask import Flask, Response, jsonify

RTSP_URL = "rtsp://192.168.100.153:554/live"

MODEL = "yolo11n.pt"
CONFIDENCE = 0.20
YOLO_SIZE = 256

STABLE_TIME = 3.0
DETECTION_INTERVAL = 0.6
MAX_MIXED_PERCENT = 20

MATERIALS = {
    "bottle": "Plastic",
    "book": "Paper"
}

app = Flask(__name__)

frame = None
current_material = None
confirmed_material = None
stable_start = None

mixed_material = False
material_percent = {}

lock = threading.Lock()

print("Loading YOLO...")
model = YOLO(MODEL)
print("YOLO ready.")


# =========================
# CAMERA
# =========================

def camera():
    global frame

    cap = cv2.VideoCapture(RTSP_URL)
    cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

    if not cap.isOpened():
        print("ERROR: V380 connection failed.")
        return

    print("V380 connected.")

    while True:
        ok, image = cap.read()

        if not ok:
            cap.release()
            time.sleep(2)

            cap = cv2.VideoCapture(RTSP_URL)
            cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
            continue

        with lock:
            frame = image


# =========================
# YOLO
# =========================

def detect():
    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent

    while True:

        with lock:
            image = None if frame is None else frame.copy()

        if image is None:
            time.sleep(0.1)
            continue

        small = cv2.resize(
            image,
            (256, 192),
            interpolation=cv2.INTER_AREA
        )

        results = model(
            small,
            imgsz=YOLO_SIZE,
            conf=CONFIDENCE,
            verbose=False
        )

        areas = {
            "Plastic": 0,
            "Paper": 0
        }

        boxes = []

        for box in results[0].boxes:

            class_id = int(box.cls[0])
            name = model.names[class_id].lower()

            if name not in MATERIALS:
                continue

            material = MATERIALS[name]

            x1, y1, x2, y2 = map(
                int,
                box.xyxy[0]
            )

            area = max(0, x2 - x1) * max(0, y2 - y1)

            areas[material] += area

            boxes.append(
                (
                    material,
                    float(box.conf[0]),
                    x1,
                    y1,
                    x2,
                    y2
                )
            )

        total_area = sum(areas.values())

        if total_area == 0:

            with lock:
                current_material = None
                confirmed_material = None
                stable_start = None
                mixed_material = False
                material_percent = {}

            time.sleep(DETECTION_INTERVAL)
            continue

        percentages = {
            material: areas[material] / total_area * 100
            for material in areas
            if areas[material] > 0
        }

        dominant = max(
            areas,
            key=areas.get
        )

        minority = [
            p
            for material, p in percentages.items()
            if material != dominant
        ]

        is_mixed = (
            len(minority) > 0
            and max(minority) >= MAX_MIXED_PERCENT
        )

        with lock:

            mixed_material = is_mixed
            material_percent = percentages

            if is_mixed:

                current_material = "Mixed"
                confirmed_material = None
                stable_start = None

            else:

                if current_material != dominant:

                    current_material = dominant
                    stable_start = time.time()
                    confirmed_material = None

                elif stable_start is not None:

                    if time.time() - stable_start >= STABLE_TIME:
                        confirmed_material = dominant

        time.sleep(DETECTION_INTERVAL)


# =========================
# VIDEO
# =========================

def video():

    while True:

        with lock:
            image = None if frame is None else frame.copy()

            material = current_material
            confirmed = confirmed_material
            mixed = mixed_material
            percentages = material_percent.copy()

        if image is None:
            time.sleep(0.05)
            continue

        # Draw YOLO boxes
        if frame is not None:

            results = model(
                cv2.resize(
                    image,
                    (256, 192),
                    interpolation=cv2.INTER_AREA
                ),
                imgsz=YOLO_SIZE,
                conf=CONFIDENCE,
                verbose=False
            )

            scale_x = image.shape[1] / 256
            scale_y = image.shape[0] / 192

            for box in results[0].boxes:

                class_id = int(box.cls[0])
                name = model.names[class_id].lower()

                if name not in MATERIALS:
                    continue

                x1, y1, x2, y2 = map(
                    int,
                    box.xyxy[0]
                )

                x1 = int(x1 * scale_x)
                x2 = int(x2 * scale_x)
                y1 = int(y1 * scale_y)
                y2 = int(y2 * scale_y)

                cv2.rectangle(
                    image,
                    (x1, y1),
                    (x2, y2),
                    (0, 255, 0),
                    2
                )

        if material is not None:

            text = material

            if percentages:
                text += " " + " ".join(
                    f"{m}:{p:.0f}%"
                    for m, p in percentages.items()
                )

            if confirmed == material:
                text += " - CONFIRMED"

            if mixed:
                text = "MIXED MATERIAL - REJECT"

            cv2.putText(
                image,
                text,
                (10, 30),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.7,
                (0, 255, 0),
                2
            )

        ok, encoded = cv2.imencode(
            ".jpg",
            image,
            [cv2.IMWRITE_JPEG_QUALITY, 70]
        )

        if not ok:
            continue

        yield (
            b"--frame\r\n"
            b"Content-Type: image/jpeg\r\n\r\n"
            + encoded.tobytes()
            + b"\r\n"
        )


# =========================
# WEB
# =========================

@app.route("/")
def home():

    return """
    <html>
    <body style="margin:0;background:black">
    <img src="/video"
         style="width:100vw;height:100vh;object-fit:contain">
    </body>
    </html>
    """


@app.route("/video")
def stream():

    return Response(
        video(),
        mimetype="multipart/x-mixed-replace; boundary=frame"
    )


# =========================
# STATUS
# =========================

@app.route("/status")
def status():

    with lock:

        stable = 0

        if stable_start is not None:
            stable = time.time() - stable_start

        return jsonify({
            "detected": current_material is not None,
            "material": current_material,
            "confirmed": confirmed_material is not None,
            "stable_seconds": round(stable, 1),
            "mixed": mixed_material,
            "percentages": material_percent
        })


# =========================
# RESET CAMERA
# =========================

@app.route("/reset")
def reset():

    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent

    with lock:

        current_material = None
        confirmed_material = None
        stable_start = None
        mixed_material = False
        material_percent = {}

    return jsonify({"reset": True})


# =========================
# START
# =========================

if __name__ == "__main__":

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
        threaded=True,
        use_reloader=False
    )