"""Configurable aluminum-can detection policy for VHEcoPoint.

This module is intentionally dependency-free: it uses only the Python
standard library (no hardware, no network, no YOLO), so the decision
rule can be unit-tested on any host. It only decides whether the
available evidence justifies classifying an item as aluminum. The
caller (``Main.process_item``) remains responsible for measuring
weight, committing the durable pre-actuation record, and driving the
servo.

Detection modes
---------------
``inductive`` (default)
    Aluminum is recognized ONLY when the inductive sensor reports
    stable metal. This is exactly the station's original behavior, so
    leaving the mode unset changes nothing.

``camera``
    Aluminum is recognized from a confirmed, sufficiently confident
    camera detection of a *verified aluminum-can class*. The inductive
    sensor does NOT have to activate. If the loaded model has no
    aluminum-can class this mode reports ``unsupported`` and never
    actuates on camera aluminum.

``hybrid``
    EITHER a confident camera detection OR a stable inductive
    detection is enough. A confident camera detection is preferred.
    Contradictory confident evidence (camera confident on a different
    material while the inductive sensor holds stable metal) is treated
    conservatively (``reject``), never silently reclassified.

Only the aluminum edge is governed here. Plastic, Paper and Cardboard
handling is unchanged and stays in ``Main.process_item``.
"""

MODE_INDUCTIVE = "inductive"
MODE_CAMERA = "camera"
MODE_HYBRID = "hybrid"

VALID_MODES = (MODE_INDUCTIVE, MODE_CAMERA, MODE_HYBRID)
DEFAULT_MODE = MODE_INDUCTIVE

# Aluminum is accepted from the camera only when the detection reached
# the camera service's own stability confirmation AND its confidence
# meets this floor. The floor is configurable per deployment. The
# default matches the camera service's global confidence threshold
# (CameraYolo.CONFIDENCE) so no global threshold is changed here.
DEFAULT_MIN_CAMERA_CONFIDENCE = 0.20

# Class names that count as a *verified aluminum can*. Generic names
# such as "metal" or "cup" are deliberately excluded: the inductive
# sensor already detects any conductor, and an aluminum classification
# must not be guessed from an unrelated class.
ALUMINUM_CLASS_NAMES = frozenset({
    "aluminum",
    "aluminium",
    "aluminum_can",
    "aluminium_can",
    "can",
})

# Class name -> canonical material. Used both to build the camera's
# class map and to keep the two modules in sync. Only names that
# describe the material actually recognized by the station appear here.
CLASS_MATERIALS = {
    "bottle": "Plastic",
    "plastic": "Plastic",
    "book": "Paper",
    "paper": "Paper",
    "aluminum": "Aluminum",
    "aluminium": "Aluminum",
    "aluminum_can": "Aluminum",
    "aluminium_can": "Aluminum",
    "can": "Aluminum",
}

CANONICAL_MATERIALS = ("Plastic", "Paper", "Aluminum")


def normalize_mode(mode):
    """Return a valid mode, falling back to the conservative default."""
    if isinstance(mode, str):
        candidate = mode.strip().lower()
        if candidate in VALID_MODES:
            return candidate
    return DEFAULT_MODE


def material_for_class(class_name):
    """Map a model class name to a canonical material, or None."""
    if not isinstance(class_name, str):
        return None
    return CLASS_MATERIALS.get(class_name.strip().lower())


def aluminum_supported(class_names):
    """True when the model exposes at least one verified aluminum class.

    ``class_names`` may be any iterable of class-name strings (for
    example ``model.names.values()``).
    """
    for name in class_names:
        if (
            isinstance(name, str)
            and name.strip().lower() in ALUMINUM_CLASS_NAMES
        ):
            return True
    return False


def _as_confidence(value):
    try:
        return float(value)
    except (TypeError, ValueError):
        return 0.0


class Decision:
    """Outcome of :func:`resolve_aluminum`.

    ``status`` is one of:
        ``accept``       aluminum confirmed, actuation may proceed
        ``reject``       positive contradictory evidence, do not actuate
        ``unsupported``  camera mode requested but model cannot do it
        ``inconclusive`` no valid aluminum evidence (caller treats the
                        item as unrecognized)
    """

    __slots__ = ("status", "material", "source", "reason")

    def __init__(self, status, material, source, reason):
        self.status = status
        self.material = material
        self.source = source
        self.reason = reason

    @property
    def accepted(self):
        return self.status == "accept"

    def __repr__(self):
        return (
            "Decision(status=%r, material=%r, source=%r, reason=%r)"
            % (self.status, self.material, self.source, self.reason)
        )


def resolve_aluminum(mode, camera, inductive, config=None):
    """Decide the aluminum outcome from camera and inductive evidence.

    ``camera`` evidence keys (all optional):
        ``supported``   bool  -- model has a verified aluminum class
        ``confirmed``   bool  -- detection reached stability confirmation
        ``material``    str   -- confirmed material, e.g. "Aluminum"
        ``confidence``  float -- detection confidence of that material

    ``inductive`` evidence keys:
        ``active``      bool  -- metal currently detected
        ``stable``      bool  -- metal stayed present for the stable window

    ``config`` may contain ``min_camera_confidence``.
    """
    mode = normalize_mode(mode)

    cfg_confidence = DEFAULT_MIN_CAMERA_CONFIDENCE
    if config and config.get("min_camera_confidence") is not None:
        cfg_confidence = _as_confidence(config["min_camera_confidence"])

    camera = camera or {}
    inductive = inductive or {}

    cam_supported = bool(camera.get("supported"))
    cam_confirmed = bool(camera.get("confirmed"))
    cam_material = camera.get("material")
    cam_confidence = _as_confidence(camera.get("confidence"))

    cam_material_key = (
        cam_material.strip().lower()
        if isinstance(cam_material, str)
        else None
    )

    cam_aluminum_ok = (
        cam_supported
        and cam_confirmed
        and cam_material_key == "aluminum"
        and cam_confidence >= cfg_confidence
    )

    ind_active = bool(inductive.get("active"))
    ind_stable = bool(inductive.get("stable"))
    ind_aluminum_ok = ind_active and ind_stable

    if mode == MODE_INDUCTIVE:
        if ind_aluminum_ok:
            return Decision(
                "accept", "aluminum", "inductive", "inductive_stable"
            )
        return Decision(
            "inconclusive", None, None, "inductive_not_stable"
        )

    if mode == MODE_CAMERA:
        if not cam_supported:
            return Decision(
                "unsupported", None, None, "model_has_no_aluminum_class"
            )
        if cam_aluminum_ok:
            return Decision(
                "accept", "aluminum", "camera", "camera_confirmed"
            )
        return Decision(
            "inconclusive", None, None, "camera_not_confident"
        )

    # MODE_HYBRID
    if cam_aluminum_ok:
        return Decision("accept", "aluminum", "camera", "camera_confirmed")

    if ind_aluminum_ok:
        # A confident camera classification of some other material while
        # the inductive sensor holds stable metal is contradictory.
        if (
            cam_supported
            and cam_confirmed
            and cam_material_key not in (None, "aluminum")
        ):
            return Decision(
                "reject", None, None, "camera_inductive_conflict"
            )
        return Decision(
            "accept", "aluminum", "inductive", "inductive_stable"
        )

    return Decision("inconclusive", None, None, "no_aluminum_evidence")
