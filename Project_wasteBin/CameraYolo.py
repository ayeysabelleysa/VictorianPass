from ultralytics import YOLO
import cv2
import os
import threading
import time
from flask import Flask, Response, jsonify

import aluminum_detection as ALUMINUM

CAMERA_DEVICE = "/dev/video0"

# USB webcam capture mode. The webcam advertises MJPEG 640x480@30,
# MJPEG 640x360@30 and YUYV 640x480@30. The service asks for MJPEG
# 640x480@30 with a one-frame buffer to avoid a stale-frame backlog.
# V4L2 may refuse any request, so the applied values are read back and
# logged instead of being assumed.
CAMERA_FOURCC = "MJPG"
CAMERA_WIDTH = 640
CAMERA_HEIGHT = 480
CAMERA_FPS = 30
CAMERA_BUFFERSIZE = 1

# Resolve the trained model relative to THIS file so the camera
# service finds it no matter which working directory Main.py used.
# The older yolo11n.pt and best.pt files are left untouched on disk.
BASE_DIR = os.path.dirname(os.path.abspath(__file__))
MODEL = os.path.join(BASE_DIR, "vhecopoint_yolo11n_v1_best.pt")

# Raw model confidence floor. Per-material floors are applied on top
# of this in detect().
CONFIDENCE = 0.20

# Inference size for the Raspberry Pi deployment. 640 was tried as a
# temporary diagnostic (it matched the laptop) but cost ~1.15 s per
# inference on a Pi-class CPU, so deployment is back at 256. All
# filters, confirmation state, stale clearing and diagnostics are
# unchanged by this value.
YOLO_SIZE = 256

MAX_DET = 10
AGNOSTIC_NMS = False

# Detection region of interest, as fractions of the full camera frame.
# Boxes are produced against this crop, then translated back to the
# full frame before being published.
ROI_X_MIN = 0.10
ROI_X_MAX = 0.90
ROI_Y_MIN = 0.05
ROI_Y_MAX = 0.95

# Per-material minimum confidence, applied after the raw model
# confidence (CONFIDENCE). Aluminum is restored to the laptop reference
# threshold (0.35); paper and plastic must be more certain.
MIN_CONFIDENCE_BY_MATERIAL = {
    "Aluminum": 0.35,
    "Paper": 0.60,
    "Plastic": 0.50,
}

# Aluminum-over-plastic suppression. A plastic box is dropped only
# when an aluminum box covers at least ALUMINUM_COVERAGE_RATIO of the
# plastic box AND the plastic box is at most PLASTIC_MAX_AREA_RATIO
# of the aluminum box's area.
ALUMINUM_COVERAGE_RATIO = 0.80
PLASTIC_MAX_AREA_RATIO = 0.65

# Temporal confirmation for Aluminum. A single inference can produce a
# spurious aluminum box on a background/paper scene, so an aluminum
# detection is only accepted after it has appeared on this many
# consecutive fresh inferences. A real can stays in view for the
# STABLE_TIME window, so this delays but never discards a genuine can.
# It is applied BEFORE the aluminum-over-plastic suppression so an
# unconfirmed aluminum box cannot hide a real plastic box.
ALUMINUM_CONFIRM_FRAMES = 2

# Diagnostic raw-prediction logger for the imgsz=640 can-detect test.
# Throttles terminal output so a short physical test stays readable:
# aluminum-bearing lines max out at one per RAW_ALUMINUM_LOG_INTERVAL,
# and "aluminum=none" lines at 3x that rate.
RAW_ALUMINUM_LOG_INTERVAL = 2.0

# Overlay colors per material (OpenCV uses BGR). Aluminum/Paper/Plastic
# get distinct saturated colors so a detection is readable at a glance.
MATERIAL_COLORS = {
    "Aluminum": (0, 140, 255),   # orange
    "Paper": (255, 80, 0),       # blue
    "Plastic": (200, 0, 180),    # purple / magenta
}
DEFAULT_BOX_COLOR = (0, 255, 0)  # green fallback for unmapped classes

# JPEG quality for the MJPEG stream. Left at the existing 70: the latency
# investigation points at duplicate-frame encoding / CPU contention, not
# the quality setting, so it is not changed without a measured comparison
# on the Pi.
JPEG_QUALITY = 70

STABLE_TIME = 3.0
DETECTION_INTERVAL = 0.6
MAX_MIXED_PERCENT = 20

# Period (seconds) for the lightweight performance log line.
STATS_INTERVAL = 10.0

app = Flask(__name__)

frame = None
camera_available = False
current_material = None
confirmed_material = None
stable_start = None

# Confidence of the current dominant material's best box. Published in
# /status so Main.py's aluminum policy can enforce a confidence floor.
material_confidence = 0.0

mixed_material = False
material_percent = {}
unrecognized_item = False

# Wall-clock time when unrecognized_item was last raised.
# Used so the popup can never stay latched forever if Main.py
# crashes or never calls /reset.
unrecognized_at = None

# Automatic safety reset for the unrecognized popup.
UNRECOGNIZED_AUTO_RESET_SEC = 60.0

# Latest boxes from detect(), expressed as fractions of the FULL
# camera frame (x1, y1, x2, y2 in 0..1) after being translated out of
# the ROI. (material, confidence, x1, y1, x2, y2)
# Published by detect() so the video stream can draw them
# without running a second YOLO inference.
detected_boxes = []

# Camera frame id the current detected_boxes were computed from.
# None means no detector result has been published (or the last fresh
# result was empty), so the overlay must not draw any boxes.
detected_frame_id = None

# Consecutive fresh inferences that contained an accepted aluminum box.
# Reset to 0 whenever a fresh inference has no aluminum, and used by
# ALUMINUM_CONFIRM_FRAMES. Only ever touched from the detector thread.
_aluminum_streak = 0

# Time the [RAW] diagnostic logger last printed. Only the detector
# thread touches it; no lock needed beyond the GIL.
_last_raw_aluminum_log = 0.0

# Per-material throttle timestamps for the generic Paper/Plastic
# diagnostic trace. Each material keeps its own counter so a busy
# Aluminum trace cannot starve a Paper/Plastic trace (and vice versa).
_last_generic_diag_log = {
    "Paper": 0.0,
    "Plastic": 0.0,
}

# Monotonic id bumped by camera() on every new frame. The detector and the
# video generator use it to work on the NEWEST frame only and to avoid
# re-encoding duplicate frames.
frame_counter = 0

# Lightweight instrumentation (guarded by _stats_lock). Read/reset by
# stats_loop(); never fed into detection logic.
_stats_lock = threading.Lock()
_stats = {
    "capture": 0,
    "video": 0,
    "infer_ms": 0.0,
    "infer_n": 0,
    "encode_ms": 0.0,
    "encode_n": 0,
}

lock = threading.Lock()
yolo_lock = threading.Lock()

print("Loading YOLO...")
model = YOLO(MODEL)
print("YOLO ready.")

model.fuse = lambda *args, **kwargs: model.model

# Build the class-name -> material map from the classes the model
# actually exposes, so a model without a given class simply never maps
# it. This preserves the original bottle->Plastic / book->Paper mapping
# (yolo11n.pt has no other relevant classes) while enabling a model
# trained on plastic/paper/aluminum (best.pt) to work unchanged.
MATERIALS = {}
for _class_name in model.names.values():
    _material = ALUMINUM.material_for_class(_class_name)
    if _material:
        MATERIALS[_class_name.strip().lower()] = _material

# Whether the loaded model can recognize a verified aluminum can. When
# False the camera can never return "Aluminum" and Main.py's aluminum
# handling degrades to the inductive sensor exactly as before.
ALUMINUM_SUPPORTED = ALUMINUM.aluminum_supported(model.names.values())

print(
    "YOLO classes:",
    dict(model.names),
)
print(
    "Aluminum-can detection supported:",
    ALUMINUM_SUPPORTED,
)
# =========================
# CAMERA
# =========================

def _fourcc_value(code):
    """Integer FourCC value for a 4-character code such as 'MJPG'."""
    try:
        return int(cv2.VideoWriter_fourcc(*code))
    except (AttributeError, TypeError, ValueError):
        return int(cv2.VideoWriter.fourcc(*code))


def _fourcc_text(value):
    """Decode an integer FourCC property value back to text, or None."""
    try:
        value = int(value)
    except (TypeError, ValueError):
        return None

    if value <= 0:
        return None

    text = "".join(
        chr((value >> (8 * index)) & 0xFF) for index in range(4)
    ).strip("\x00")

    return text or None


def _read_camera_number(cap, prop, cast):
    """Read a camera property, returning None if unsupported/invalid."""
    try:
        value = cap.get(prop)
    except cv2.error as error:
        print(f"Camera property read failed: {error}")
        return None

    try:
        return cast(value)
    except (TypeError, ValueError):
        return None


def _configure_camera(cap):
    """Request the preferred webcam mode and report what was negotiated.

    V4L2 silently ignores unsupported or inaccurate requests, so each
    ``set`` result is logged and every value is read back afterwards.
    The negotiated mode is never overwritten with the requested one.
    """
    requests = (
        (cv2.CAP_PROP_FOURCC, _fourcc_value(CAMERA_FOURCC),
         "FourCC", CAMERA_FOURCC),
        (cv2.CAP_PROP_FRAME_WIDTH, CAMERA_WIDTH, "width", CAMERA_WIDTH),
        (cv2.CAP_PROP_FRAME_HEIGHT, CAMERA_HEIGHT, "height", CAMERA_HEIGHT),
        (cv2.CAP_PROP_FPS, CAMERA_FPS, "FPS", CAMERA_FPS),
        (cv2.CAP_PROP_BUFFERSIZE, CAMERA_BUFFERSIZE,
         "buffer size", CAMERA_BUFFERSIZE),
    )

    for prop, value, label, display in requests:
        try:
            accepted = bool(cap.set(prop, value))
        except cv2.error as error:
            print(f"Camera {label} request failed: {error}")
            continue

        print(
            f"Camera {label} request: {display} -> "
            f"{'accepted' if accepted else 'rejected'}"
        )

    width = _read_camera_number(cap, cv2.CAP_PROP_FRAME_WIDTH, int)
    height = _read_camera_number(cap, cv2.CAP_PROP_FRAME_HEIGHT, int)
    fps = _read_camera_number(cap, cv2.CAP_PROP_FPS, float)
    fourcc = _fourcc_text(
        _read_camera_number(cap, cv2.CAP_PROP_FOURCC, int)
    )

    width_text = width if width is not None else "unknown"
    height_text = height if height is not None else "unknown"
    fps_text = f"{fps:.1f}" if fps is not None else "unknown"

    print(
        "Camera negotiated: "
        f"{width_text}x{height_text} @ {fps_text}fps "
        f"FourCC={fourcc or 'unknown'}"
    )

    if (
        width != CAMERA_WIDTH
        or height != CAMERA_HEIGHT
        or fps is None
        or fps < CAMERA_FPS
        or (fourcc is not None and fourcc.upper() != CAMERA_FOURCC)
    ):
        print(
            "Camera did not accept the requested "
            f"{CAMERA_WIDTH}x{CAMERA_HEIGHT}@{CAMERA_FPS} "
            f"{CAMERA_FOURCC} mode; using the negotiated mode above."
        )


def camera():
    global frame
    global camera_available
    global frame_counter

    cap = None

    while True:

        if cap is None or not cap.isOpened():

            if cap is not None:
                cap.release()

            with lock:
                frame = None
                camera_available = False

            cap = cv2.VideoCapture(CAMERA_DEVICE, cv2.CAP_V4L2)

            if not cap.isOpened():
                # A build without the V4L2 backend returns an unopened
                # capture; fall back to the default backend rather than
                # dropping the camera entirely.
                cap.release()
                cap = cv2.VideoCapture(CAMERA_DEVICE)

            if not cap.isOpened():
                print("V380 unavailable. Retrying...")
                time.sleep(2)
                continue

            _configure_camera(cap)

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
            frame_counter += 1

        with _stats_lock:
            _stats["capture"] += 1


# =========================
# YOLO
# =========================

def _box_area(box):
    x1, y1, x2, y2 = box[2], box[3], box[4], box[5]
    return max(0.0, x2 - x1) * max(0.0, y2 - y1)


def _intersection_area(a, b):
    ax1, ay1, ax2, ay2 = a[2], a[3], a[4], a[5]
    bx1, by1, bx2, by2 = b[2], b[3], b[4], b[5]

    ix1 = max(ax1, bx1)
    iy1 = max(ay1, by1)
    ix2 = min(ax2, bx2)
    iy2 = min(ay2, by2)

    if ix2 <= ix1 or iy2 <= iy1:
        return 0.0

    return (ix2 - ix1) * (iy2 - iy1)


def _suppress_plastic_by_aluminum(boxes):
    """Drop plastic boxes that a larger aluminum box explains.

    Ported from the laptop filter: a plastic box is suppressed only
    when an aluminum box covers at least ALUMINUM_COVERAGE_RATIO of
    it AND the plastic box's area is at most PLASTIC_MAX_AREA_RATIO
    of the aluminum box's area. Both boxes must already share the same
    coordinate space.
    """
    aluminum_boxes = [b for b in boxes if b[0] == "Aluminum"]

    if not aluminum_boxes:
        return boxes

    kept = []

    for box in boxes:

        if box[0] != "Plastic":
            kept.append(box)
            continue

        plastic_area = _box_area(box)

        if plastic_area <= 0:
            continue

        suppressed = False

        for aluminum_box in aluminum_boxes:

            aluminum_area = _box_area(aluminum_box)

            if aluminum_area <= 0:
                continue

            overlap = _intersection_area(box, aluminum_box)

            if (
                overlap / plastic_area >= ALUMINUM_COVERAGE_RATIO
                and plastic_area
                    <= PLASTIC_MAX_AREA_RATIO * aluminum_area
            ):
                suppressed = True
                break

        if not suppressed:
            kept.append(box)

    return kept


def _accept_boxes(raw_boxes, x_min, y_min, width, height):
    """Threshold-filter, translate and suppress one inference's boxes.

    ``raw_boxes`` holds ``(material, conf, bx1, by1, bx2, by2)`` in ROI
    pixels. Returns accepted ``(material, conf, x1, y1, x2, y2)`` tuples
    as fractions of the full frame. The aluminum-over-plastic
    suppression is applied LAST and BEFORE the caller computes any
    areas, percentages or confidence.
    """
    boxes = []

    for material, conf, bx1, by1, bx2, by2 in raw_boxes:

        min_conf = MIN_CONFIDENCE_BY_MATERIAL.get(material, CONFIDENCE)

        if conf < min_conf:
            continue

        # ROI pixel coords -> full-frame pixels -> fractions of the
        # full frame (so the overlay survives a resolution change).
        px1 = x_min + bx1
        px2 = x_min + bx2
        py1 = y_min + by1
        py2 = y_min + by2

        boxes.append(
            (
                material,
                conf,
                px1 / width,
                py1 / height,
                px2 / width,
                py2 / height
            )
        )

    return _suppress_plastic_by_aluminum(boxes)


def _material_areas(boxes):
    """Sum box areas per material. Callers pass post-suppression boxes."""
    areas = {
        material: 0
        for material in ALUMINUM.CANONICAL_MATERIALS
    }

    for material, _conf, bx1, by1, bx2, by2 in boxes:
        areas[material] += max(0.0, bx2 - bx1) * max(0.0, by2 - by1)

    return areas


def _update_aluminum_streak(aluminum_seen):
    """Advance the consecutive-aluminum counter, report confirmation.

    A fresh inference with an accepted aluminum box extends the streak;
    one without resets it. Confirmed once the streak reaches
    ALUMINUM_CONFIRM_FRAMES.
    """
    global _aluminum_streak

    _aluminum_streak = _aluminum_streak + 1 if aluminum_seen else 0

    return _aluminum_streak >= ALUMINUM_CONFIRM_FRAMES


def _filter_unconfirmed_aluminum(raw_boxes):
    """Drop aluminum boxes until they are temporally confirmed.

    Real cans persist across the STABLE_TIME window, so this delays but
    never discards one. It runs before suppression so an unconfirmed
    aluminum box cannot hide a genuine plastic box.
    """
    aluminum_floor = MIN_CONFIDENCE_BY_MATERIAL.get(
        "Aluminum", CONFIDENCE
    )

    aluminum_seen = any(
        material == "Aluminum" and conf >= aluminum_floor
        for material, conf, *_rest in raw_boxes
    )

    if _update_aluminum_streak(aluminum_seen):
        return raw_boxes

    return [box for box in raw_boxes if box[0] != "Aluminum"]


def _diagnose_aluminum(fid, raw_boxes, boxes):
    """Throttled per-stage trace for Aluminum candidates.

    Labels what happens to each Aluminum box at every relevant stage:
    [RAW] as the model emits it, [THRESH] after the per-class floor,
    [SUPPRESS] after aluminum-over-plastic suppression (which only ever
    drops Plastic, so Aluminum box counts here are informational,
    [CONFIRM] the two-frame state, and [ACCEPT]/[REJECT] the final
    filter result. Confidence and box coordinates are on every line.
    Throttled to RAW_ALUMINUM_LOG_INTERVAL so the terminal stays
    readable.
    """
    global _last_raw_aluminum_log

    log_now = time.time()

    if (
        log_now - _last_raw_aluminum_log
        < RAW_ALUMINUM_LOG_INTERVAL
    ):
        return

    _last_raw_aluminum_log = log_now

    floor = MIN_CONFIDENCE_BY_MATERIAL.get("Aluminum", CONFIDENCE)

    raw_aluminum = [
        (
            material,
            round(conf, 3),
            int(bx1), int(by1),
            int(bx2), int(by2),
        )
        for material, conf, bx1, by1, bx2, by2 in raw_boxes
        if material == "Aluminum"
    ]

    passed = [
        (conf, b) for (_, conf, *b) in raw_aluminum
        if conf >= floor
    ]

    rejected = len(raw_aluminum) - len(passed)

    accepted = [
        (
            material,
            round(conf, 3),
            round(x1, 3), round(y1, 3),
            round(x2, 3), round(y2, 3),
        )
        for material, conf, x1, y1, x2, y2 in boxes
        if material == "Aluminum"
    ]

    confirmed = _aluminum_streak >= ALUMINUM_CONFIRM_FRAMES

    print("[RAW]     fid=%d aluminum=%s" % (fid, raw_aluminum))
    print(
        "[THRESH]  fid=%d floor=%.2f rejected=%d passed=%s"
        % (fid, floor, rejected, passed[0] if len(passed) == 1 else passed)
    )
    print(
        "[SUPPRESS] fid=%d aluminum_before=%d aluminum_after=%d"
        " (suppression only drops Plastic)"
        % (fid, len(passed), len(accepted))
    )
    print(
        "[CONFIRM] fid=%d streak=%d/%d confirmed=%s"
        % (
            fid,
            _aluminum_streak,
            ALUMINUM_CONFIRM_FRAMES,
            confirmed,
        )
    )

    if accepted:
        print("[ACCEPT]  fid=%d aluminum_published=%s" % (fid, accepted))
    else:
        print(
            "[REJECT]  fid=%d aluminum_published=0 reason=%s"
            % (
                fid,
                (
                    "confirmation-pending streak=%d/%d"
                    % (_aluminum_streak, ALUMINUM_CONFIRM_FRAMES)
                    if raw_aluminum and not confirmed
                    else "none-passed-threshold"
                ),
            )
        )


def _diagnose_material(mat, fid, raw_boxes, boxes):
    """Throttled per-stage trace for a non-confirmed material (Paper /
    Plastic), mirroring the Aluminum trace but without the temporal
    confirmation stage.

    Lines: [RAW] the model's boxes in ROI-relative pixels, [THRESH] the
    per-class floor decision, [SUPPRESS] whether aluminum-overlap
    removed boxes (only ever applies to Plastic), and [ACCEPT]/[REJECT]
    the final filter result in published (full-frame fraction) coords.
    Throttled per material to RAW_ALUMINUM_LOG_INTERVAL.
    """
    log_now = time.time()

    if (
        log_now - _last_generic_diag_log[mat]
        < RAW_ALUMINUM_LOG_INTERVAL
    ):
        return

    _last_generic_diag_log[mat] = log_now

    floor = MIN_CONFIDENCE_BY_MATERIAL.get(mat, CONFIDENCE)

    raw_items = [
        (round(conf, 3), int(bx1), int(by1), int(bx2), int(by2))
        for material, conf, bx1, by1, bx2, by2 in raw_boxes
        if material == mat
    ]

    passed = [conf for conf, *_coords in raw_items if conf >= floor]
    threshold_rejected = len(raw_items) - len(passed)

    after_suppress = [
        (
            round(conf, 3),
            round(x1, 3), round(y1, 3),
            round(x2, 3), round(y2, 3),
        )
        for material, conf, x1, y1, x2, y2 in boxes
        if material == mat
    ]

    suppression_removed = len(passed) - len(after_suppress)

    print("[RAW]     fid=%d %s_roi=%s" % (fid, mat, raw_items))
    print(
        "[THRESH]  fid=%d %s floor=%.2f rejected=%d passed=%d"
        % (fid, mat, floor, threshold_rejected, len(passed))
    )
    print(
        "[SUPPRESS] fid=%d %s removed=%d (aluminum-overlap only affects Plastic)"
        % (fid, mat, suppression_removed)
    )

    if after_suppress:
        print(
            "[ACCEPT]  fid=%d %s_published=%s" % (fid, mat, after_suppress)
        )
    else:
        print(
            "[REJECT]  fid=%d %s_published=0 reason=%s"
            % (
                fid,
                mat,
                (
                    "suppression-removed"
                    if suppression_removed > 0
                    else "none-passed-threshold"
                ),
            )
        )


def _clear_detection_locked(reset_streak=True):
    """Clear all published detection state. Caller holds ``lock``.

    ``reset_streak`` additionally resets the two-consecutive-frame
    Aluminum confirmation counter. That is normally True (session reset /
    detector exception), but MUST be False in the detect() empty-result
    path: a frame whose Aluminum is still confirmation-pending produces no
    accepted boxes, and wiping the streak there would make confirmation
    impossible to ever complete for a steady can.
    """
    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent
    global material_confidence
    global unrecognized_item
    global unrecognized_at
    global detected_boxes
    global detected_frame_id
    global _aluminum_streak

    current_material = None
    confirmed_material = None
    stable_start = None
    mixed_material = False
    material_percent = {}
    material_confidence = 0.0
    unrecognized_item = False
    unrecognized_at = None
    detected_boxes = []
    detected_frame_id = None

    if reset_streak:
        _aluminum_streak = 0


def detect():
    global current_material
    global confirmed_material
    global stable_start
    global mixed_material
    global material_percent
    global material_confidence
    global unrecognized_item
    global unrecognized_at
    global detected_boxes
    global detected_frame_id
    global _last_raw_aluminum_log

    last_frame_id = -1

    while True:

        try:

            # Grab the newest frame reference under the lock, then copy
            # it OUTSIDE the lock. camera() only ever rebinds `frame` to
            # a fresh array, so the reference stays valid and consistent.
            # Holding the lock through the copy would briefly block
            # camera() and video().
            with lock:
                image = frame
                fid = frame_counter

            if image is None:
                time.sleep(0.1)
                continue

            # Only infer on a frame we have not already processed. If
            # capture stalls, wait instead of burning CPU re-running the
            # model on the same image (no backlog: newest frame only).
            if fid == last_frame_id:
                time.sleep(0.05)
                continue

            last_frame_id = fid
            image = image.copy()

            # Crop the detection region of interest out of the full frame.
            height, width = image.shape[:2]

            x_min = int(width * ROI_X_MIN)
            x_max = int(width * ROI_X_MAX)
            y_min = int(height * ROI_Y_MIN)
            y_max = int(height * ROI_Y_MAX)

            roi = image[y_min:y_max, x_min:x_max]

            if roi.size == 0:
                time.sleep(0.1)
                continue

            # Feed the ROI to YOLO as-is and let its own letterbox
            # preprocessing scale it to YOLO_SIZE while preserving the
            # ROI's aspect ratio. A forced resize to a fixed WxH here
            # would stretch objects (e.g. a 512x432 ROI squashed into
            # 256x192 on a 640x480 frame). Ultralytics returns boxes in
            # the coordinate space of the image it was given, i.e. ROI
            # pixels.
            infer_start = time.perf_counter()
            with yolo_lock:
                results = model(
                    roi,
                    imgsz=YOLO_SIZE,
                    conf=CONFIDENCE,
                    max_det=MAX_DET,
                    agnostic_nms=AGNOSTIC_NMS,
                    verbose=False
                )
            infer_ms = (time.perf_counter() - infer_start) * 1000.0
            with _stats_lock:
                _stats["infer_ms"] += infer_ms
                _stats["infer_n"] += 1

            # Collect this inference's mapped boxes in ROI pixels.
            # Class thresholds and the ROI->full-frame translation are
            # applied by _accept_boxes below.
            raw_boxes = []

            for box in results[0].boxes:

                class_id = int(box.cls[0])
                name = model.names[class_id].lower()

                if name not in MATERIALS:
                    continue

                material = MATERIALS[name]
                conf = float(box.conf[0])
                bx1, by1, bx2, by2 = map(float, box.xyxy[0])

                raw_boxes.append(
                    (material, conf, bx1, by1, bx2, by2)
                )

            # Keep the raw inference for the diagnostic trace: the
            # confirmation step below rebinds raw_boxes to the filtered
            # list, so the pre-filter boxes must be snapshotted here.
            original_raw_boxes = raw_boxes
            had_raw_aluminum = any(
                box[0] == "Aluminum" for box in raw_boxes
            )
            had_raw_paper = any(
                box[0] == "Paper" for box in raw_boxes
            )
            had_raw_plastic = any(
                box[0] == "Plastic" for box in raw_boxes
            )

            # Temporal aluminum confirmation, then per-class thresholds,
            # translation and aluminum-over-plastic suppression. The
            # suppression must be complete BEFORE any area, percentage
            # or confidence is computed below.
            raw_boxes = _filter_unconfirmed_aluminum(raw_boxes)
            boxes = _accept_boxes(
                raw_boxes, x_min, y_min, width, height
            )

            # Diagnostic stage trace for Aluminum candidates, throttled.
            # The "none" arm stays much quieter by design so terminal
            # output does not flood during an empty-scene stretch.
            if had_raw_aluminum:
                _diagnose_aluminum(fid, original_raw_boxes, boxes)
            elif (
                time.time() - _last_raw_aluminum_log
                >= RAW_ALUMINUM_LOG_INTERVAL * 3
            ):
                _last_raw_aluminum_log = time.time()
                print("[RAW] fid=%d aluminum=none" % fid)

            # Same treatment for Paper and Plastic, each with its own
            # throttle so a can test cannot hide a paper test's trace.
            if had_raw_paper:
                _diagnose_material("Paper", fid, original_raw_boxes, boxes)
            elif (
                time.time() - _last_generic_diag_log["Paper"]
                >= RAW_ALUMINUM_LOG_INTERVAL * 3
            ):
                _last_generic_diag_log["Paper"] = time.time()
                print("[RAW] fid=%d paper=none" % fid)

            if had_raw_plastic:
                _diagnose_material("Plastic", fid, original_raw_boxes, boxes)
            elif (
                time.time() - _last_generic_diag_log["Plastic"]
                >= RAW_ALUMINUM_LOG_INTERVAL * 3
            ):
                _last_generic_diag_log["Plastic"] = time.time()
                print("[RAW] fid=%d plastic=none" % fid)

            areas = _material_areas(boxes)
            total_area = sum(areas.values())

            if total_area == 0:

                # A fresh inference produced no accepted boxes: clear
                # the published result so the overlay can never keep
                # showing the previous Aluminum/Plastic box. The streak
                # is deliberately preserved (reset_streak=False): an
                # unconfirmed-Aluminum frame reaches here empty and must
                # not erase its own confirmation progress.
                with lock:
                    _clear_detection_locked(reset_streak=False)

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
                unrecognized_at = None

                # Publish the boxes we just computed, together with the
                # camera frame they came from, so the video stream draws
                # only the latest completed detector result.
                detected_boxes = boxes
                detected_frame_id = fid

                # Best-box confidence of the dominant material, used by
                # Main.py to enforce a configurable aluminum floor.
                material_confidence = max(
                    (
                        box_conf
                        for (box_material, box_conf, *_rest) in boxes
                        if box_material == dominant
                    ),
                    default=0.0,
                )

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

            # [PUBLISHED] diagnostic: the boxes that passed the full
            # filtering chain (thresholds, suppression, confirmation).
            # Logged only when an Aluminum box survives, so it can be
            # told apart from [RAW] above.
            if any(box[0] == "Aluminum" for box in boxes):
                print(
                    "[PUBLISHED] fid=%d material=%s boxes=%s"
                    % (
                        fid,
                        current_material,
                        [
                            (
                                material,
                                round(conf, 3),
                                round(x1, 3), round(y1, 3),
                                round(x2, 3), round(y2, 3),
                            )
                            for material, conf, x1, y1, x2, y2 in boxes
                        ],
                    )
                )

            time.sleep(DETECTION_INTERVAL)

        except Exception as error:

            # Never let a detector failure leave the last boxes
            # published forever: clear them and keep the thread alive.
            print(f"Detector error: {error!r}. Clearing detections.")
            with lock:
                _clear_detection_locked()
            time.sleep(1.0)


# =========================
# OVERLAY
# =========================

def color_for_material(material):
    """Bounding-box / label color (BGR) for a material name."""
    return MATERIAL_COLORS.get(material, DEFAULT_BOX_COLOR)


def label_position(x1, y1, x2, y2, label_w, label_h, frame_width, frame_height):
    """Top-left (x, y) for a label background that stays inside the frame.

    Preference order: above the box; else just below it; else just inside
    its top edge. Horizontally the label is shifted left only as far as
    needed to fit.
    """
    x = x1
    if x + label_w > frame_width:
        x = frame_width - label_w
    if x < 0:
        x = 0

    if y1 - label_h >= 0:
        y = y1 - label_h          # above the box
    elif y2 + label_h <= frame_height:
        y = y2                    # just below the box
    else:
        y = y1                    # inside, at the top edge
    if y < 0:
        y = 0

    return x, y


def detection_label(material, conf):
    """"Aluminum 87%" style label text for a detection."""
    return f"{material} {float(conf) * 100:.0f}%"


def draw_detection(image, material, conf, x1, y1, x2, y2):
    """Draw one bounding box plus a filled "<Material> <conf>%" label.

    Pure OpenCV drawing: no model inference, no shared state. Returns the
    label background rect (x, y, w, h) so tests can assert placement.
    """
    color = color_for_material(material)

    cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)

    text = detection_label(material, conf)
    font = cv2.FONT_HERSHEY_SIMPLEX
    scale = 0.5
    thickness = 1
    (text_w, text_h), baseline = cv2.getTextSize(text, font, scale, thickness)

    pad = 3
    label_w = text_w + 2 * pad
    label_h = text_h + baseline + 2 * pad

    lx, ly = label_position(
        x1, y1, x2, y2, label_w, label_h,
        image.shape[1], image.shape[0]
    )

    # Filled label background in the material color, then contrasting text.
    cv2.rectangle(
        image, (lx, ly), (lx + label_w, ly + label_h), color, -1
    )
    cv2.putText(
        image, text, (lx + pad, ly + pad + text_h),
        font, scale, (255, 255, 255), thickness, cv2.LINE_AA
    )

    return lx, ly, label_w, label_h


# =========================
# STATS
# =========================

def stats_loop():
    """Log capture FPS, inference time, encode time and emitted FPS."""
    prev = None

    while True:

        time.sleep(STATS_INTERVAL)

        with _stats_lock:
            cur = dict(_stats)

        if prev is None:
            prev = cur
            continue

        d_capture = cur["capture"] - prev["capture"]
        d_video = cur["video"] - prev["video"]
        d_infer_n = cur["infer_n"] - prev["infer_n"]
        d_encode_n = cur["encode_n"] - prev["encode_n"]

        infer_avg = (
            (cur["infer_ms"] - prev["infer_ms"]) / d_infer_n
            if d_infer_n else 0.0
        )
        encode_avg = (
            (cur["encode_ms"] - prev["encode_ms"]) / d_encode_n
            if d_encode_n else 0.0
        )

        print(
            f"[stats] capture={d_capture / STATS_INTERVAL:.1f}fps "
            f"video={d_video / STATS_INTERVAL:.1f}fps "
            f"infer={infer_avg:.0f}ms encode={encode_avg:.1f}ms"
        )

        prev = cur


# =========================
# VIDEO
# =========================

def video():

    last_frame_id = -1

    while True:

        # Read shared state under the lock; copy the frame afterwards so
        # camera()/detect() are not blocked during the memcpy.
        with lock:
            image = frame
            fid = frame_counter

            material = current_material
            confirmed = confirmed_material
            mixed = mixed_material
            percentages = material_percent.copy()

            # Only draw boxes that came from a completed detector result.
            # detect() empties both on an empty result and on any detector
            # error, so a stale box can never be redrawn indefinitely.
            boxes = (
                detected_boxes
                if detected_frame_id is not None
                else []
            )

        if image is None:
            time.sleep(0.05)
            continue

        # Emit only genuinely NEW camera frames. Re-encoding the same frame
        # in a tight loop wasted CPU and starved capture/detect on the Pi.
        if fid == last_frame_id:
            time.sleep(0.003)
            continue

        last_frame_id = fid
        image = image.copy()

        # Draw the detection zone outline. This is the ROI that
        # detect() actually runs on. Intentionally no text label.
        frame_height, frame_width = image.shape[:2]

        cv2.rectangle(
            image,
            (
                int(frame_width * ROI_X_MIN),
                int(frame_height * ROI_Y_MIN)
            ),
            (
                int(frame_width * ROI_X_MAX),
                int(frame_height * ROI_Y_MAX)
            ),
            (255, 255, 0),
            2
        )

        # Draw YOLO boxes
        #
        # These boxes come from detect(), which already ran the model on
        # the ROI. They are stored as fractions of the full frame, tied
        # to the newest detector result (detected_frame_id). No inference
        # is run here, and no second inference ever was after the update
        # to reuse the published boxes.
        for box in boxes:

            box_material, box_conf = box[0], box[1]

            x1 = int(box[2] * frame_width)
            x2 = int(box[4] * frame_width)
            y1 = int(box[3] * frame_height)
            y2 = int(box[5] * frame_height)

            draw_detection(
                image, box_material, box_conf, x1, y1, x2, y2
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

        encode_start = time.perf_counter()
        ok, encoded = cv2.imencode(
            ".jpg",
            image,
            [cv2.IMWRITE_JPEG_QUALITY, JPEG_QUALITY]
        )
        encode_ms = (time.perf_counter() - encode_start) * 1000.0

        if not ok:
            continue

        with _stats_lock:
            _stats["encode_ms"] += encode_ms
            _stats["encode_n"] += 1
            _stats["video"] += 1

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

    global unrecognized_item
    global unrecognized_at

    with lock:

        # Safety net: never leave the "Item Not Recognized"
        # popup latched for more than ~60 seconds, even if
        # Main.py crashed or never called /reset.
        #
        # Only the popup flag is cleared here, so an active
        # classification (material/confirmed/stable/mixed)
        # is never interrupted.
        if (
            unrecognized_item
            and unrecognized_at is not None
            and (time.time() - unrecognized_at)
                >= UNRECOGNIZED_AUTO_RESET_SEC
        ):
            unrecognized_item = False
            unrecognized_at = None

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
            "percentages": material_percent,
            "aluminum_supported": ALUMINUM_SUPPORTED,
            "material_confidence": round(material_confidence, 3)
        })


# =========================
# RESET CAMERA
# =========================

@app.route("/reset", methods=["GET", "POST"])
def reset():

    with lock:
        _clear_detection_locked()

    return jsonify({"reset": True})


@app.route("/unrecognized", methods=["POST"])
def unrecognized():

    global unrecognized_item
    global unrecognized_at

    with lock:
        unrecognized_item = True
        unrecognized_at = time.time()

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

    threading.Thread(
        target=stats_loop,
        daemon=True
    ).start()

    app.run(
        host="0.0.0.0",
        port=5000,
        threaded=True,
        use_reloader=False
    )
