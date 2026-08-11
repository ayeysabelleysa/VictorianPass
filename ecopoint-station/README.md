# VHEcoPoint Station Hardware Integration Guide
VictorianPass — QR-based real-time waste tracking (NO station screen — pure hardware API!)

> ✅ **Critical hardware constraint you already know:** The VHEcoPoint station has **NO screen/display/touchscreen** —
> only a **QR scanner**, **weight sensors**, a **120L bin**, and **LAN connection**.
> The resident's *VictorianPass mobile/web account* is the ONLY display surface.
> This document has **zero station UI** requirements.

---

## 0. Architecture Summary

```
┌──────────────────────────────────────────┐        LAN / HTTPS          ┌──────────────────────────┐
│ VHEcoPoint Station (Hardware)            │ ─────────────────────────▶ │ VictorianPass Backend    │
│  • QR scanner (reads user.ref_code)      │  POST JSON + AUTH HEADERS  │  • ecopoint_core.php     │
│  • Load cells / weight sensor(s)         │                            │  • api_hardware/*.php    │
│  • 120L bin (Plastic/Aluminum/Paper)     │ ◀───────────────────────── │  • Admin Dashboard       │
│  • Ethernet LAN                          │   200 OK JSON responses    │  • MySQL (source of tr.) │
└──────────────────────────────────────────┘                            └──────────────────────────┘
         │                                                                      ▲
         │                                                                      │
         ▼                                                                      │ SSE push
┌─────────────────────────────────────────────────────────────────────────────────────────────────┐
│ Resident's own VictorianPass account (phone / browser / webview)                               │
│  • Poll → Server-Sent Events (EventSource) → live session card + real-time updates → no refresh │
│  • EventSource AUTOMATICALLY reconnects if Wi‑Fi / cellular drops (state is always server-side) │
└─────────────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 1. Setup Steps (before hardware integration)

### 1.1 Run the Database Migration (phpMyAdmin)
Import this file **once** into your `victorianpass_db` database — it is *idempotent* (safe to run multiple times):
```
../DATABASE/ecopoint_waste_system.sql
```

Creates these tables / columns if they don't exist:
- `ecopoint_stations` — station registry + hashed API key auth
- `ecopoint_waste_sessions` — state machine (WAITING/ACTIVE/PROCESSING/COMPLETED/CANCELLED/ERROR)
- `ecopoint_waste_items` — **WasteTransactionItem** per user spec (category breakdown w/ weight + pts)
- `ecopoint_session_events` — immutable audit log
- Adds missing `material_type / weight_kg / station_id / ecopoint_session_id` columns to your existing `point_transactions` table (so the resident dashboard works automatically)
- Seeds a default station `VH-ECO-001` (see auth section below)

### 1.2 Station Authentication
Every hardware API call **must** send these two HTTP headers:

| Header                  | Value                                                       |
|-------------------------|-------------------------------------------------------------|
| `X-VHECO-Station`       | Station code, e.g. `VH-ECO-001`                             |
| `X-VHECO-Api-Key`       | Plaintext secret (the station is a *trusted* LAN box!)      |

Station rows store **only a `password_hash()` of the API key** (never plaintext anywhere in DB!)

#### Default Seeded Station (for testing / bootstrap)
The migration seeds `VH-ECO-001`. The plaintext test key is:
```
vheco-station-VH-ECO-001-default-api-key-secret-change-me
```
**For production** — generate your own 64+char random key, `password_hash()` it in PHP, insert/update the row in `ecopoint_stations`, give the plaintext to the hardware team only.

---

## 2. Hardware Backend API Endpoints (Production Ready)
All endpoints live under `/VictorianPass/api_hardware/`. All return JSON. All require the two auth headers above.

### 2.1. `POST /api_hardware/qr_verify_and_create_session.php`
Called immediately **after** the station scans a resident's QR code.

**Request:**
```json
{ "qr_code": "<scanned value - this is users.ref_code>" }
```

**Response (200):**
```jsonc
{
  "success": true,
  "created_new": true,
  "session_token": "a1b2c3d4e5f6…",   // <-- USE THIS TOKEN FOR *ALL* SUBSEQUENT CALLS
  "session": {
    "id": 12345,
    "status": "ACTIVE",                // or WAITING if it was an explicit WAITING call
    "created_at": "2026-01-01 09:15:00"
  },
  "resident": {
    "user_id": 42,
    "full_name": "Juan Dela Cruz",
    "balance": 2180                    // current total balance (informational only)
  },
  "cap_state": {
    "daily_sessions_left":    2,       // < 1 = reject new sessions today
    "daily_points_left":      45,
    "weekly_points_left":     205,     // < 1 = reject new sessions this week
    "weekly_reset_note":      "Resets next Monday 12:00 AM (Asia/Manila)"
  },
  "rates":  { "Plastic":55, "Aluminum":140, "Paper":30, "Cardboard":30 },
  "rules":  { "daily_point_cap":100, "daily_session_cap":3, "weekly_point_cap":250,
              "max_balance":3000, "session_timeout_sec":600 }
}
```

**Important failure responses** (you MUST handle these in hardware code):

| HTTP | When it happens                                                                  | Hardware action |
|------|----------------------------------------------------------------------------------|-----------------|
| 400  | `qr_code` missing                                                                | Retry scan      |
| 401  | Auth headers missing / unknown station / wrong API key                           | Stop; station cannot operate |
| 403  | Station is `INACTIVE/MAINTENANCE/REVOKED`                                        | Stop; display on resident's dashboard via admin |
| 403  | Daily session limit reached (resident has 3 sessions today)                      | Stop & tell resident to come back tomorrow |
| 403  | Weekly + daily point cap reached (0 capacity left)                               | Stop & tell resident to come back next week |
| 409-like 200  | `created_new=false` — resident already had an ACTIVE/WAITING/PROCESSING session. Same session returned (duplicate guard!). | **Use the returned existing session — don't try to create a second one!** |

---

### 2.2. `POST /api_hardware/submit_waste_data.php`
Called **during processing**, every time you read a new weight from the scale (or material sensor triggers).

> 💡 You can call this **multiple times per session** — the backend stores the latest values, writes a WASTE_DATA event each time, and pushes live updates to the resident's dashboard via SSE.

**Request:**
```jsonc
{
  "session_token": "a1b2c3d4e5f6…",       // from endpoint (2.1)
  "material":      "Aluminum",            // one of: Plastic / Aluminum / Paper / Cardboard
  "weight_kg":      1.50,                 // positive number (kg)
  "waste_type":     "aluminum_can",       // optional — one of plastic_pet, aluminum_can, paper_cardboard, other (WasteTransactionItem key)
  "raw_hardware_data": {                  // optional — any sensor payload you want audited
    "scale_raw_adc": 45231,
    "stabilized": true,
    "bin_full_percent": 42
  }
}
```
**Response (200):**
```jsonc
{
  "success": true,
  "session_status": "PROCESSING",
  "material": "Aluminum",
  "weight_kg": 1.50,
  "rate_pts_per_kg": 140,
  "points_calculated": 210,               // backend math ONLY — use this to update LEDs or beeps if you have them
  "cap_state": { "weekly_points_left": 165, "daily_points_left": 10, "daily_sessions_left": 2 },
  "note": "Final points capped at session completion (daily/weekly/max-balance rules)"
}
```

---

### 2.3. `POST /api_hardware/complete_session.php`
Call this **ONCE** when everything is done (bin closed, weight is final, waste is deposited).

- Backend **finalizes** points (applies all three caps: daily 100 / weekly 250 / max balance 3000)
- Inserts exactly 1 row into `point_transactions`
- Updates `users.points` (+ legacy `residents.ecopoint_balance` if applicable)
- Marks session `COMPLETED` + `points_posted=1`
- Writes an FK `posted_transaction_id` back to the session row
- **Re-running this endpoint is IDENTICAL to the first run** (double-award guard: no extra points, ever)

**Request:**
```jsonc
{
  "session_token": "a1b2c3d4e5f6…",
  "final_material":  "Aluminum",         // optional, defaults to last submitted snapshot
  "final_weight_kg": 1.50,               // optional, defaults to last submitted snapshot
  "raw_hardware_data": {}                // optional final telemetry
}
```

**Response (200):**
```jsonc
{
  "success": true,
  "session_status": "COMPLETED",
  "material": "Aluminum",
  "weight_kg": 1.50,
  "points_calculated": 210,              // raw
  "points_awarded":     45,              // <-- ACTUAL POINTS CREDITED (capped!)
  "new_balance":       2225,
  "cap_state_after":   { "weekly_points_used": 45, "weekly_points_left": 205, "daily_points_used": 55, "daily_points_left": 45 }
}
```

> 💡 If hardware tries to "complete" with 0 kg → backend returns HTTP 400 and cancels the session for you.

---

### 2.4. `POST /api_hardware/cancel_session.php`
Call when: user walks away, invalid/unacceptable waste, timeout, bin jam, operator cancel, etc.
- No points awarded. Session marked `CANCELLED` + audit event written.

**Request:**
```json
{ "session_token": "a1b2c3d4e5f6…", "reason": "resident_cancelled_timeout" }
```

---

### 2.5. `POST /api_hardware/error_session.php`
Call whenever: load cell failure, sensor fault, bin 100% full (needs emptying), scale calibration error, etc.
- Session moves to `ERROR`. Points never posted. Everything fully auditable.

**Request:**
```json
{
  "session_token": "a1b2c3d4e5f6…",
  "error_code": "E_BIN_FULL",
  "error_message": "Bin 1 (Plastic) reached 100% — please empty before continuing",
  "payload": { "bin_id": 1, "fill_percent": 100, "sensor_crc_ok": true }
}
```
If `session_token` is omitted → the error is treated as a station-level error (still heartbeat's the station, just no session link).

---

## 3. Resident Realtime Display (No Station UI Needed!)
Resident's VictorianPass dashboard opens one persistent Server-Sent Event stream (SSE):

**JS (used automatically by `profileresident.php`):**
```js
const es = new EventSource('/VictorianPass/api_resident/ecopoint_sse.php', { withCredentials: true });
es.addEventListener('snapshot', (ev) => {
  const snap = JSON.parse(ev.data);
  // render(snap.active_session, snap.cap_state, snap.current_balance, snap.recent_sessions)
});
es.onerror = () => { /* browser's EventSource AUTOMATICALLY reconnects — do nothing! */ };
```

### SSE behaviour guarantees (required for mid-session drops)
1. If the resident's phone drops Wi-Fi → moves to 5G → drops again, **`EventSource` reconnects on its own** (per SSE spec) and the next emitted `snapshot` contains the **full DB state** (no lost intermediate messages).
2. State is **always server-side** — the resident's app just displays what it receives.
3. SSE stream ends after ~4:40 (stays under PHP's default `max_execution_time` = 300s) → browser reconnects automatically.

---

## 4. Admin Dashboard
Go to:
```
https://<your-host>/VictorianPass/admin_ecopoint.php
```
(Uses the *exact same admin auth* as `admin.php` — you must be logged in as `admin`. Redirects to `admin_login.php` otherwise.)

**Shows:**
- KPI cards: Stations online/offline, active sessions now, points awarded today, weekly totals (Mon → Sun reset)
- **Stations grid** with live heartbeat status, last 2 min = ONLINE, shows current active session (resident + house #) for each
- **Live sessions table** (WAITING/ACTIVE/PROCESSING) with station, resident, material, weight, points preview
- **Recent sessions history** (last 50, searchable by resident/house/station/QR/session ID)
- **Click "Details" on any session → modal with:**
  - Session metadata (resident, QR scanned, timestamps, status)
  - **WasteTransactionItem** breakdown table (per category weight + rate + calc + awarded)
  - Full **immutable audit event log** (QR_VERIFIED → WASTE_DATA → STATE_CHANGE → POINTS_POSTED → COMPLETED)

Admin page auto-refreshes every 20 seconds.

---

## 5. Full Example Hardware Flow (curl / Postman)
Use these commands to smoke-test the complete flow from the hardware side. Replace the base URL as needed:

```powershell
# --- Step 0: Your hardware AUTH headers ---
$HEADERS = @(
  "X-VHECO-Station: VH-ECO-001",
  "X-VHECO-Api-Key: vheco-station-VH-ECO-001-default-api-key-secret-change-me",
  "Content-Type: application/json"
)
$BASE = "http://localhost/VictorianPass"

# --- Step 1: Get a user's QR (ref_code) from users table in phpMyAdmin ---
# --- Copy any ref_code, e.g. "VP-RESIDENT-00012" ---
$QR = "VP-RESIDENT-00012"

# --- Step 2: Create / verify session ---
$body1 = @{ qr_code = $QR } | ConvertTo-Json
$r1 = Invoke-RestMethod -Method POST -Uri "$BASE/api_hardware/qr_verify_and_create_session.php" -Headers $HEADERS -Body $body1
$TOKEN = $r1.session_token
Write-Host "SESSION TOKEN: $TOKEN  (status: $($r1.session.status))"

# --- Step 3: Submit waste data during weighing (can repeat multiple times!) ---
$body2 = @{ session_token = $TOKEN; material = "Aluminum"; weight_kg = 1.50; waste_type = "aluminum_can"; raw_hardware_data = @{ scale = "stabilized" } } | ConvertTo-Json -Depth 5
$r2 = Invoke-RestMethod -Method POST -Uri "$BASE/api_hardware/submit_waste_data.php" -Headers $HEADERS -Body $body2
Write-Host "Points calculated so far: $($r2.points_calculated)  (cap weekly left=$($r2.cap_state.weekly_points_left))"

# --- Step 4: Finalize session (award capped points!) ---
$body3 = @{ session_token = $TOKEN } | ConvertTo-Json
$r3 = Invoke-RestMethod -Method POST -Uri "$BASE/api_hardware/complete_session.php" -Headers $HEADERS -Body $body3
Write-Host "DONE. Session = $($r3.session_status)  Awarded = $($r3.points_awarded)  New Balance = $($r3.new_balance)"

# --- Optional: Cancel instead of complete ---
# $body4 = @{ session_token = $TOKEN; reason = "timeout_user_left" } | ConvertTo-Json
# Invoke-RestMethod -Method POST -Uri "$BASE/api_hardware/cancel_session.php" -Headers $HEADERS -Body $body4
```

---

## 6. Smoke Test (PHP Built-In)
Want to validate the *entire stack* (DB schema → auth → session create → duplicate guard → finalize → double-award guard) **without hardware?**
Just visit (logged in as resident or not, doesn't matter):
```
http://localhost/VictorianPass/_smoke_test_ecopoint.php
```

Prints a full PASS/FAIL summary at the end.

---

## 7. Business Rules Implemented (All Server-Side, Immutable!)
| Rule | Value | How enforced |
|------|-------|--------------|
| Daily session cap | **3** / resident / day | `SELECT COUNT(*) FROM ecopoint_waste_sessions WHERE status=COMPLETED AND DATE(completed_at)=today` |
| Daily point cap | **100** pts | `eco_apply_cap_rules()` in core (points never exceed remaining daily room) |
| Weekly point cap | **250** pts | Reset **EVERY MONDAY 12:00 AM (Asia/Manila)** via `eco_week_bounds()` helper (weekday=1=Mon → explicit calculation, no PHP ambiguity) |
| Max total balance | **3000** pts | `LEAST(points + ?, 3000)` on every award |
| Material rates | Plastic 55, Aluminum 140, Paper/Cardboard 30 pts/kg | `ECO_MATERIAL_RATES` constant in core |
| Session timeout | **600s / 10 min** | Constant in core (cancel logic can be wired later for stale OPEN sessions) |
| Duplicate active session per resident | Blocked (1 WAITING/ACTIVE/PROCESSING max) | `eco_get_active_session_for_user()` + MySQL UNIQUE KEY `uniq_active_user(user_id, status)` |
| Double-point awards | Blocked (idempotent!) | `points_posted` flag + `posted_transaction_id` FK + `SELECT … FOR UPDATE` row locking |
| Hardware identity | Verified on every call | Headers + `password_verify()` on DB hash |
| Resident identity via QR | Never trusts QR data for points/balance | QR = lookup only. Balance/points/caps always re-read from DB. |

---

## 8. Data Model (Matches your spec + existing schema)

| New table | Equivalent in spec | Purpose |
|-----------|-------------------|---------|
| `ecopoint_stations` | — | Station registry + hashed API keys |
| `ecopoint_waste_sessions` | `WasteSession` | Session state machine + totals (id, resident_id, station_id, status ENUM, timestamps, total_weight_kg, total_points) |
| `ecopoint_waste_items` | `WasteTransactionItem` | Category-level breakdown: waste_type (plastic_pet/aluminum_can/paper_cardboard), weight_kg, rate, points_calculated + points_awarded |
| `point_transactions` (augmented existing) | `ResidentPointLedger` equivalent | Earn entries (already used by dashboard!) — now has material_type, weight_kg, station_id FK, ecopoint_session_id FK |
| `ecopoint_session_events` | — | Immutable audit log of every event (JSON payload + source) |

---

## 9. Troubleshooting
| Symptom | Fix |
|---------|-----|
| Hardware gets 401 "Missing authentication headers" | Ensure both `X-VHECO-Station` and `X-VHECO-Api-Key` are set (not one!) |
| 401 "Invalid API key" even for VH-ECO-001 | Run the `_smoke_test_ecopoint.php` — its migration block re-seeds the correct hash, or run the last UPDATE statement in the SQL migration again |
| Session never shows on resident dashboard | Resident must be logged into VictorianPass in the same browser session. Dashboard uses SSE on the same `$_SESSION`. |
| 403 "daily session limit reached" | Resident has 3 COMPLETED sessions already today — next day new slots available |
| Weekly cap shows 0 at start of Monday | Check timezone in `connect.php` → must be `Asia/Manila`. `eco_week_bounds()` uses PHP server's `date()`. |
| SSE dashboard updates stutter | Make sure `zlib.output_compression = Off` and Nginx/Apache aren't buffering SSE (script sends `X-Accel-Buffering: no` + disables OB) |

Happy building! 🌱
