from ultralytics import YOLO
import cv2
import threading
import time
from flask import Flask, Response, jsonify

RTSP_URL = "rtsp://192.168.0.150:554/live"

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
camera_available = False
current_material = None
confirmed_material = None
stable_start = None

mixed_material = False
material_percent = {}
unrecognized_item = False

# Latest boxes from detect(), in 256x192 detection space.
# (material, confidence, x1, y1, x2, y2)
# Published by detect() so the video stream can draw them
# without running a second YOLO inference.
detected_boxes = []

lock = threading.Lock()
yolo_lock = threading.Lock()

print("Loading YOLO...")
model = YOLO(MODEL)
print("YOLO ready.")

model.fuse = lambda *args, **kwargs: model.model
# =========================
# CAMERA
# =========================

def camera():
    global frame
    global camera_available

    cap = None

    while True:

        if cap is None or not cap.isOpened():

            if cap is not None:
                cap.release()

            with lock:
                frame = None
                camera_available = False

            cap = cv2.VideoCapture(RTSP_URL)
            cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)

            if not cap.isOpened():
                print("V380 unavailable. Retrying...")
                time.sleep(2)
                continue

            print("V380 connected.")

        ok, image = cap.read()

        if not ok:

            with lock:
                frame = None
                camera_available = False

            cap.release()
            cap = None

            print("V380 frame unavailable. Reconnecting...")
            time.sleep(2)
            continue

        with lock:
            frame = image
            camera_available = True


# =========================
# YOLO
# =========================

def detect():
    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent
    global unrecognized_item
    global detected_boxes

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

        with yolo_lock:
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
                unrecognized_item = False
                detected_boxes = []

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
            unrecognized_item = False

            # Publish the boxes we just computed so the video
            # stream can draw them without inferring again.
            detected_boxes = boxes

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
            boxes = detected_boxes

        if image is None:
            time.sleep(0.05)
            continue

        # Draw YOLO boxes
        #
        # These boxes come from detect(), which already ran the
        # model on the same 256x192 frame. The previous code ran a
        # SECOND, unlocked inference here for every streamed
        # frame. That doubled the model's CPU load, starving
        # detect(), and it raced with detect() over the shared
        # predictor state. Reusing the published boxes keeps the
        # identical overlay while leaving the model free to
        # actually detect.
        if boxes:

            scale_x = image.shape[1] / 256
            scale_y = image.shape[0] / 192

            for box in boxes:

                x1, y1, x2, y2 = box[2], box[3], box[4], box[5]

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
        <head>
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
                :root { color-scheme: light; }
                * { box-sizing: border-box; }
                body { margin: 0; background: #07150d; font-family: Arial, sans-serif; }
                .camera { width: 100vw; height: 100vh; object-fit: contain; display: block; }
                .popup-overlay { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; padding: 16px; background: rgba(6, 23, 12, .62); z-index: 2; }
                .popup-overlay.visible { display: flex; }
                .popup-card { width: min(390px, 100%); max-height: calc(100vh - 32px); overflow: auto; padding: 26px 22px 22px; border-radius: 16px; background: #fff; color: #1d2b21; text-align: center; box-shadow: 0 18px 48px rgba(0, 0, 0, .28); }
                .popup-icon { width: 48px; height: 48px; margin: 0 auto 12px; display: grid; place-items: center; border-radius: 50%; background: #fff4cc; color: #9a6700; font-size: 26px; }
                .popup-title { margin: 0 0 8px; color: #23412e; font-size: 21px; }
                .popup-message { margin: 0 0 20px; color: #4b5563; font-size: 15px; line-height: 1.5; }
                .popup-button { width: 100%; border: 0; border-radius: 9px; padding: 12px 18px; background: #23412e; color: #fff; font-size: 15px; font-weight: 700; cursor: pointer; }
                .popup-button:hover { background: #2f6042; }
                .popup-button:focus-visible { outline: 3px solid #9bd5ad; outline-offset: 3px; }
            </style>
        </head>
        <body>
    <img src="/video"
                 class="camera" alt="VHEcoPoint station camera">
        <div class="popup-overlay" id="unrecognizedPopup" role="alertdialog" aria-modal="true" aria-labelledby="unrecognizedTitle" aria-describedby="unrecognizedMessage">
            <div class="popup-card">
                <div class="popup-icon" aria-hidden="true">⚠</div>
                <h1 class="popup-title" id="unrecognizedTitle">Item Not Recognized</h1>
                <p class="popup-message" id="unrecognizedMessage">The system could not identify this item. Please remove the item and try again.</p>
                <button class="popup-button" id="removeItemButton" type="button">Remove Item</button>
            </div>
        </div>
        <script>
            const popup = document.getElementById('unrecognizedPopup');
            const removeButton = document.getElementById('removeItemButton');
            let popupVisible = false;

            async function updateStationUI() {
                try {
                    const response = await fetch('/status', { cache: 'no-store' });
                    const status = await response.json();
                    const shouldShow = status.unrecognized === true;
                    if (shouldShow !== popupVisible) {
                        popupVisible = shouldShow;
                        popup.classList.toggle('visible', shouldShow);
                        if (shouldShow) removeButton.focus();
                    }
                } catch (error) {
                    // The camera stream remains usable if status polling is unavailable.
                }
            }

            removeButton.addEventListener('click', async function() {
                removeButton.disabled = true;
                try {
                    await fetch('/reset', { method: 'POST' });
                } finally {
                    popupVisible = false;
                    popup.classList.remove('visible');
                    removeButton.disabled = false;
                    updateStationUI();
                }
            });

            updateStationUI();
            setInterval(updateStationUI, 300);
        </script>
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
            "camera_available": camera_available,
            "detected": current_material is not None,
            "material": current_material,
            "confirmed": confirmed_material is not None,
                "unrecognized": unrecognized_item,
            "stable_seconds": round(stable, 1),
            "mixed": mixed_material,
            "percentages": material_percent
        })


# =========================
# RESET CAMERA
# =========================

@app.route("/reset", methods=["GET", "POST"])
def reset():

    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent
    global unrecognized_item

    with lock:

        current_material = None
        confirmed_material = None
        stable_start = None
        mixed_material = False
        material_percent = {}
        unrecognized_item = False

    return jsonify({"reset": True})


@app.route("/unrecognized", methods=["POST"])
def unrecognized():

    global unrecognized_item

    with lock:
        unrecognized_item = True

    return jsonify({"unrecognized": True})


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
