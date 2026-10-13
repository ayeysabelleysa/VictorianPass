# Focused headless smoke tests for the false-Aluminum fix in CameraYolo.py.
#
# Importing CameraYolo loads the trained YOLO model but starts NO camera
# thread, detector thread, Flask server or OSV sensor: every tested
# function is a pure helper. No hardware (camera / RTSP / GPIO) is used.

import os

os.environ.setdefault("CUDA_VISIBLE_DEVICES", "")

import CameraYolo as C

passed = 0
failed = 0
failures = []


def check(name, condition, detail=""):
    global passed, failed
    if condition:
        passed += 1
    else:
        failed += 1
        failures.append(f"{name} {detail}".strip())


def nearly(a, b, tol=1e-6):
    return abs(a - b) <= tol


# Full-frame size and ROI origin used below must mirror the constants.
W, H = 640, 480
XMIN, YMIN = int(W * C.ROI_X_MIN), int(H * C.ROI_Y_MIN)


def roi_box(material, conf, bx1, by1, bx2, by2):
    """A raw_boxes tuple (ROI pixels) as produced by detect()."""
    return (material, conf, bx1, by1, bx2, by2)


def full_box(material, conf, fx1, fy1, fx2, fy2):
    """A translated, post-_accept_boxes tuple in full-frame fractions."""
    return (material, conf, fx1, fy1, fx2, fy2)


def reset_globals():
    with C.lock:
        C._clear_detection_locked()


# ----------------------------------------------------------------------
# 1. _accept_boxes: per-material confidence thresholds are enforced.
# ----------------------------------------------------------------------

boxes = C._accept_boxes(
    [
        roi_box("Aluminum", 0.34, 192, 168, 320, 264),
        roi_box("Aluminum", 0.35, 192, 168, 320, 264),
        roi_box("Paper", 0.59, 400, 100, 500, 200),
        roi_box("Paper", 0.60, 400, 100, 500, 200),
        roi_box("Plastic", 0.49, 100, 100, 200, 200),
        roi_box("Plastic", 0.50, 100, 100, 200, 200),
    ],
    XMIN,
    YMIN,
    W,
    H,
)

materials = [b[0] for b in boxes]

check(
    "aluminum 0.34 dropped, 0.35 kept (laptop reference floor)",
    materials.count("Aluminum") == 1,
    f"got {materials}",
)
check(
    "paper 0.59 dropped, 0.60 kept",
    materials.count("Paper") == 1,
    f"got {materials}",
)
check(
    "plastic 0.49 dropped, 0.50 kept",
    materials.count("Plastic") == 1,
    f"got {materials}",
)
check("three boxes survive thresholds", len(boxes) == 3, f"got {len(boxes)}")

# ----------------------------------------------------------------------
# 2. _accept_boxes: ROI pixels are translated to full-frame fractions.
# ----------------------------------------------------------------------

accepted = C._accept_boxes(
    [roi_box("Aluminum", 0.90, 192, 168, 320, 264)],
    XMIN,
    YMIN,
    W,
    H,
)

check("translation keeps one box", len(accepted) == 1, f"got {len(accepted)}")

if accepted:
    m, conf, fx1, fy1, fx2, fy2 = accepted[0]
    check("translated x1", nearly(fx1, (XMIN + 192) / W), str(fx1))
    check("translated y1", nearly(fy1, (YMIN + 168) / H), str(fy1))
    check("translated x2", nearly(fx2, (XMIN + 320) / W), str(fx2))
    check("translated y2", nearly(fy2, (YMIN + 264) / H), str(fy2))

# ----------------------------------------------------------------------
# 3. _accept_boxes: aluminum-over-plastic suppression happens INSIDE the
#    acceptance path, i.e. before the caller computes areas.
# ----------------------------------------------------------------------

aluminum_roi = roi_box("Aluminum", 0.90, 192, 168, 320, 264)
plastic_inside_roi = roi_box("Plastic", 0.90, 198.4, 172.8, 300.8, 249.6)
plastic_outside_roi = roi_box("Plastic", 0.90, 256, 216, 512, 408)

post = C._accept_boxes(
    [aluminum_roi, plastic_inside_roi],
    XMIN,
    YMIN,
    W,
    H,
)
post_materials = [b[0] for b in post]

check(
    "plastic fully inside the aluminum box is suppressed in _accept_boxes",
    "Plastic" not in post_materials and "Aluminum" in post_materials,
    f"got {post_materials}",
)

post2 = C._accept_boxes(
    [aluminum_roi, plastic_outside_roi],
    XMIN,
    YMIN,
    W,
    H,
)
post2_materials = [b[0] for b in post2]

check(
    "plastic mostly outside the aluminum box is kept",
    "Plastic" in post2_materials and "Aluminum" in post2_materials,
    f"got {post2_materials}",
)

# ----------------------------------------------------------------------
# 4. _suppress_plastic_by_aluminum: direct edge-case checks (full-frame
#    fractions, the coordinate space it is called in).
# ----------------------------------------------------------------------

alu = full_box("Aluminum", 0.90, 0.40, 0.40, 0.60, 0.60)     # area 0.04
tiny_inside = full_box("Plastic", 0.90, 0.41, 0.41, 0.57, 0.57)  # 80%+ overlap
too_big = full_box("Plastic", 0.90, 0.30, 0.30, 0.70, 0.70)   # area 0.16
partly_out = full_box("Plastic", 0.90, 0.50, 0.50, 0.90, 0.90)

no_alu = C._suppress_plastic_by_aluminum([tiny_inside, partly_out])
check(
    "no aluminum box -> nothing suppressed",
    [b[0] for b in no_alu] == ["Plastic", "Plastic"],
    str([b[0] for b in no_alu]),
)

suppressed = C._suppress_plastic_by_aluminum([alu, tiny_inside])
check(
    ">=80% overlap and <=65% area -> plastic suppressed",
    [b[0] for b in suppressed] == ["Aluminum"],
    str([b[0] for b in suppressed]),
)

kept_big = C._suppress_plastic_by_aluminum([alu, too_big])
check(
    "plastic area > 65% of aluminum -> kept",
    "Plastic" in [b[0] for b in kept_big],
    str([b[0] for b in kept_big]),
)

kept_partial = C._suppress_plastic_by_aluminum([alu, partly_out])
check(
    "overlap < 80% -> kept",
    "Plastic" in [b[0] for b in kept_partial],
    str([b[0] for b in kept_partial]),
)

# ----------------------------------------------------------------------
# 5. _material_areas: sums only the boxes handed to it (post-suppression),
#    with a complete canonical-material key set.
# ----------------------------------------------------------------------

empty_areas = C._material_areas([])
check(
    "empty boxes -> all-zero areas",
    all(v == 0.0 for v in empty_areas.values())
    and set(empty_areas) == set(C.ALUMINUM.CANONICAL_MATERIALS),
    str(empty_areas),
)

# After suppression inside _accept_boxes, plastic must not contribute.
accept_out = C._accept_boxes(
    [aluminum_roi, plastic_inside_roi],
    XMIN,
    YMIN,
    W,
    H,
)
areas = C._material_areas(accept_out)
check(
    "suppressed plastic contributes zero area",
    areas["Plastic"] == 0.0 and areas["Aluminum"] > 0.0,
    f"aluminum={areas['Aluminum']} plastic={areas['Plastic']}",
)

# ----------------------------------------------------------------------
# 5b. Aluminum confirmation must survive the empty-result clear path.
#
# A steady can produces a raw Aluminum box on each fresh inference. Frame
# 1 is confirmation-pending, so the filtered output is EMPTY and the
# detect() loop calls _clear_detection_locked(reset_streak=False). That
# must NOT wipe the streak, or confirmation could never reach frame 2.
# This reproduces the exact detect() sequence for a steady can.
# ----------------------------------------------------------------------

reset_globals()

reset_globals()

alu_roi = roi_box("Aluminum", 0.81, 192, 168, 320, 264)

# Frame 1 of a steady can: raw Aluminum present, streak 0 -> 1, still
# pending, so Aluminum is withheld and the filtered set is empty.
frame1 = C._filter_unconfirmed_aluminum([alu_roi])
check(
    "steady can frame 1: aluminum withheld (pending)",
    "Aluminum" not in [b[0] for b in frame1],
    str([b[0] for b in frame1]),
)
check("steady can frame 1: streak advanced to 1",
      C._aluminum_streak == 1, str(C._aluminum_streak))

# detect() then hits total_area == 0 and clears with reset_streak=False.
with C.lock:
    C._clear_detection_locked(reset_streak=False)
check(
    "empty-result clear preserves the confirmation streak",
    C._aluminum_streak == 1,
    f"streak={C._aluminum_streak} (bug: wipe here makes confirmation impossible)",
)

# Frame 2 of the same steady can: streak 1 -> 2, now confirmed.
frame2 = C._filter_unconfirmed_aluminum([alu_roi])
check(
    "steady can frame 2: aluminum confirmed and kept",
    "Aluminum" in [b[0] for b in frame2],
    str([b[0] for b in frame2]),
)
check("steady can frame 2: streak reached 2",
      C._aluminum_streak == 2, str(C._aluminum_streak))

# A frame with no Aluminum resets the streak again (genuine gap).
nothing = C._filter_unconfirmed_aluminum([])
check("gap frame resets the streak",
      C._aluminum_streak == 0 and not nothing,
      f"streak={C._aluminum_streak}")

# /reset path (reset_streak default True) still wipes the streak.
with C.lock:
    C._aluminum_streak = 2
    C._clear_detection_locked()
check("session clear resets the streak", C._aluminum_streak == 0,
      str(C._aluminum_streak))

# ----------------------------------------------------------------------
# 6. Aluminum temporal confirmation.
# ----------------------------------------------------------------------

reset_globals()

check("frame 1 of aluminum streak not confirmed",
      C._update_aluminum_streak(True) is False)
check("frame 2 of aluminum streak confirmed",
      C._update_aluminum_streak(True) is True)
check("aluminum gap resets the streak",
      C._update_aluminum_streak(False) is False)
check("streak reset means frame 1 not confirmed again",
      C._update_aluminum_streak(True) is False)

reset_globals()

f1 = C._filter_unconfirmed_aluminum([aluminum_roi, plastic_outside_roi])
check(
    "first unconfirmed inference: aluminum dropped, plastic kept",
    "Aluminum" not in [b[0] for b in f1]
    and "Plastic" in [b[0] for b in f1],
    str([b[0] for b in f1]),
)

f2 = C._filter_unconfirmed_aluminum([aluminum_roi, plastic_outside_roi])
check(
    "second consecutive inference: aluminum confirmed and kept",
    "Aluminum" in [b[0] for b in f2]
    and "Plastic" in [b[0] for b in f2],
    str([b[0] for b in f2]),
)

# ----------------------------------------------------------------------
# 7. Stale published state is cleared even after an empty result, and an
#    unconfirmed aluminum can never reach the published boxes.
# ----------------------------------------------------------------------

reset_globals()
with C.lock:
    C.detected_boxes = [("Aluminum", 0.90, 0.40, 0.40, 0.60, 0.60)]
    C.detected_frame_id = 123
    C.material_confidence = 0.90
    C.current_material = "Aluminum"
    C.confirmed_material = "Aluminum"

# Mirrors the inference that produces NO accepted boxes (total_area == 0).
empty_inference = C._accept_boxes(
    [
        roi_box("Aluminum", 0.10, 192, 168, 320, 264),
        roi_box("Paper", 0.05, 400, 100, 500, 200),
    ],
    XMIN,
    YMIN,
    W,
    H,
)
check("empty accepted list", C._material_areas(empty_inference)["Aluminum"] == 0.0
      and not empty_inference)

with C.lock:
    C._clear_detection_locked()

check("clear empties stale detected_boxes", C.detected_boxes == [])
check("clear resets detected_frame_id", C.detected_frame_id is None)
check("clear resets material_confidence", C.material_confidence == 0.0)
check("clear resets current_material", C.current_material is None)
check("clear resets confirmed_material", C.confirmed_material is None)
check("clear resets aluminum streak", C._aluminum_streak == 0)

# The video() overlay guard: with no detector result (detected_frame_id is
# None after a clear), no boxes may be drawn.
with C.lock:
    C.detected_boxes = [("Aluminum", 0.90, 0.40, 0.40, 0.60, 0.60)]
    C.detected_frame_id = None
    overlay_boxes = (
        C.detected_boxes if C.detected_frame_id is not None else []
    )
check("stale boxes not drawn when no detector result",
      overlay_boxes == [])

# ----------------------------------------------------------------------
# 8. /reset clears the published detection state too.
# ----------------------------------------------------------------------

reset_globals()
with C.lock:
    C.detected_boxes = [("Aluminum", 0.90, 0.40, 0.40, 0.60, 0.60)]
    C.detected_frame_id = 9
    C.current_material = "Aluminum"

with C.app.app_context():
    C.reset()
check("reset clears detected_boxes", C.detected_boxes == [])
check("reset clears detected_frame_id", C.detected_frame_id is None)
check("reset clears current_material", C.current_material is None)

# ----------------------------------------------------------------------
# 9. Generic Paper/Plastic diagnostic trace runs and reports the stage.
# ----------------------------------------------------------------------

from io import StringIO
from contextlib import redirect_stdout

C._last_generic_diag_log["Paper"] = 0.0
C._last_generic_diag_log["Plastic"] = 0.0

buf = StringIO()
with redirect_stdout(buf):
    C._diagnose_material(
        "Paper",
        fid=1,
        raw_boxes=[roi_box("Paper", 0.81, 192, 168, 320, 264)],
        boxes=[full_box("Paper", 0.81, 0.4, 0.4, 0.6, 0.6)],
    )
    C._diagnose_material(
        "Plastic",
        fid=2,
        raw_boxes=[roi_box("Plastic", 0.62, 100, 100, 200, 200)],
        boxes=[full_box("Plastic", 0.62, 0.25, 0.25, 0.4, 0.4)],
    )

out = buf.getvalue()
check("paper trace has RAW roi coords", "[RAW]     fid=1 Paper_roi=[(0.81, 192, 168, 320, 264)]" in out,
      str(out))
check("paper trace THRESH floor 0.60 accepted", "floor=0.60 rejected=0 passed=1" in out)
check("paper trace ACCEPT published", "Paper_published=[(0.81, 0.4, 0.4, 0.6, 0.6)]" in out)
check("plastic trace has RAW roi coords", "[RAW]     fid=2 Plastic_roi=[(0.62, 100, 100, 200, 200)]" in out)

# A below-floor Paper candidate is reported as rejected (threshold).
C._last_generic_diag_log["Paper"] = 0.0
buf2 = StringIO()
with redirect_stdout(buf2):
    C._diagnose_material(
        "Paper",
        fid=3,
        raw_boxes=[roi_box("Paper", 0.55, 192, 168, 320, 264)],
        boxes=[],
    )
out2 = buf2.getvalue()
check("paper below floor traced as THRESH rejected",
      "floor=0.60 rejected=1 passed=0" in out2, str(out2))
check("paper below floor traced as REJECT",
      "[REJECT]  fid=3 Paper_published=0 reason=none-passed-threshold" in out2,
      str(out2))

# A suppression-removed Plastic candidate is reported as rejected.
C._last_generic_diag_log["Plastic"] = 0.0
buf3 = StringIO()
with redirect_stdout(buf3):
    C._diagnose_material(
        "Plastic",
        fid=4,
        raw_boxes=[roi_box("Plastic", 0.90, 198.4, 172.8, 300.8, 249.6)],
        boxes=[],
    )
out3 = buf3.getvalue()
check("suppression-removed plastic traced as REJECT",
      "[REJECT]  fid=4 Plastic_published=0 reason=suppression-removed" in out3,
      str(out3))

reset_globals()

print(f"{passed} passed, {failed} failed")
if failures:
    print("FAILURES:")
    for failure in failures:
        print(" -", failure)
    raise SystemExit(1)