# VHEcoPoint: Software Development Documentation

---

## 1. Project Overview

### Project Title

**VHEcoPoint: A Smart Waste Segregation Station with QR-Based Incentive System for Victorian Heights Subdivision**

### Project Description

VHEcoPoint is a hardware-integrated smart waste segregation and rewards system deployed at Victorian Heights Subdivision. Physical EcoPoint Stations — equipped with QR scanners, digital weight sensors, and a local network bridge — authenticate residents by scanning the resident's unique VictorianPass QR code, weigh the deposited recyclable material, and automatically award EcoPoints based on material type and kilogram weight. The system integrates with the existing **VictorianPass web platform** as the user-facing UI host: residents, guards, and administrators monitor sessions, point balances, and station telemetry through VictorianPass dashboards, while the core incentive engine, session state machine, and points ledger are VHEcoPoint-owned modules. The awarded points are redeemable against VictorianPass amenity reservations (Clubhouse, Basketball Court, Tennis Court, Multi-Purpose Building) to create a closed-loop sustainability incentive.

### Current Situation and Problem Addressed

Victorian Heights Subdivision — a 2,220-household residential community — currently lacks a structured waste segregation and recycling program. The specific problems are:

1. **No Waste Segregation Incentive**: Households have no motivation to separate recyclables from general waste. Mixed waste goes directly to landfill, increasing the subdivision's hauling cost and environmental footprint.
2. **Manual Weighing & Tracking**: Pilot manual recycling programs relied on handwritten logbooks and self-reported weights; records were inaccurate, lost, or impossible to audit, and rewards (if any) were delayed or inconsistent.
3. **No Resident-Level Accountability**: Without individual QR-linked sessions, the barangay cannot attribute recycling behavior to specific households nor identify top contributors or non-participants.
4. **Redeemable Rewards Are Unstructured**: Any "community perks" for recycling were arbitrary (cash handouts, paper coupons) with no standardized point-to-value conversion, expiry policy, or budget cap.
5. **Zero Hardware Telemetry**: There are no physical stations in place. Management has no real-time visibility into station availability (online/offline), material throughput by type, or points liability at any given moment.

### How the System Addresses the Identified Problem

VHEcoPoint solves these problems through a closed, backend-truthful incentive loop combining hardware and software:

- **QR-Authenticated Sessions**: Each resident scans their unique VictorianPass QR at the station. The hardware forwards the QR to the backend via a secured API call. The backend verifies the resident, generates a cryptographically unique session token, and opens exactly one ACTIVE session per resident (enforced by a MySQL unique index on `(user_id, status)` for non-final states) to prevent double-counting.
- **Hardware-Based Weighing & Material Detection**: The station's digital scale and sensor package (Plastic PET, Aluminum Cans, Paper, Cardboard) transmit material type and weight kilograms to the backend. The backend **recalculates points from first principles using business-rule constants** and never trusts hardware-provided point values.
- **Automatic, Capped Points Awarding**: Points are awarded per kilogram at rates defined in [ecopoint_core.php](file:///C:/xampp/htdocs/VictorianPass/ecopoint_core.php#L20-L25): Plastic (PET) 55 pts/kg, Aluminum 140 pts/kg, Paper & Cardboard 30 pts/kg. Three caps prevent runaway liability: 100 pts/day, 250 pts/week (Monday reset), and 3,000 pts maximum balance per resident [ecopoint_core.php](file:///C:/xampp/htdocs/VictorianPass/ecopoint_core.php#L26-L29).
- **Atomic Award & Double-Spend Prevention**: Points are posted atomically using a `points_posted` TINYINT flag and a unique FK in `point_transactions.ecopoint_session_id`. If the process crashes mid-write, no points are awarded until the transaction retries successfully [ecopoint_waste_system.sql](file:///C:/xampp/htdocs/VictorianPass/DATABASE/ecopoint_waste_system.sql#L143-L145).
- **State-Machine Audit Trail**: Every session transits through `WAITING → ACTIVE → PROCESSING → COMPLETED | CANCELLED | ERROR`. Every state change and hardware telemetry payload is logged to `ecopoint_session_events` with JSON payloads for reconstructability [ecopoint_core.php](file:///C:/xampp/htdocs/VictorianPass/ecopoint_core.php#L32-L33).
- **VictorianPass as Host UI**: Resident balance + transaction history, Guard quick-lookups, and Admin station KPI dashboards (online count, active sessions, points awarded today/week, session-level material breakdowns) are rendered inside the VictorianPass web platform so users have a single community portal rather than a separate VHEcoPoint login.

### Key Functions (Feature Summary)

1. **Hardware Station Authentication & Registry** — API-key (hashed, `password_hash`/`password_verify`) authentication for stations with heartbeat-based online/offline status.
2. **QR Scanning & Resident Verification** — Scan resident VictorianPass QR → backend lookup → open one unique ACTIVE waste session.
3. **Real-Time Material Weighing & Points Calculation** — Accept weight/material from scale → backend recalculates points → apply daily/weekly/balance caps.
4. **Session State Machine & Event Logging** — Enforce `WAITING → ACTIVE → PROCESSING → COMPLETED | CANCELLED | ERROR` lifecycle; log every payload.
5. **Atomic Points Ledger & Double-Award Prevention** — Post points once via `points_posted` flag + unique session FK in `point_transactions`.
6. **Resident EcoPoint Dashboard (via VictorianPass)** — Display current balance, transaction history (earn/redeem, material, weight), and active session status via SSE/polling.
7. **Admin EcoPoint Control Panel (via VictorianPass)** — Station KPIs (online, active sessions, pts today/week), per-session drilldown (waste breakdown, event audit), resident point ledger, and station management.
8. **Station Error, Timeout, & Cancel Handlers** — Automatic 10-minute session timeout [ecopoint_core.php](file:///C:/xampp/htdocs/VictorianPass/ecopoint_core.php#L30), explicit CANCEL/ERROR endpoints, and hardware-disconnect recovery.
9. **Points Redemption Against Amenities (via VictorianPass)** — Use EcoPoint balance to offset/discount amenity reservation fees within VictorianPass booking flow.

---

## 2. Functional Requirements Table

| ID       | Requirement Name                                  | Description (The system shall...)                                                                                                                                                                                                 | Priority |
|----------|---------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|----------|
| **FR-01** | Hardware Station Registry & API Authentication     | Maintain a registry of EcoPoint stations in `ecopoint_stations` with unique station_code, hashed API key (`password_hash`), status flag, and location; authenticate every hardware call by verifying `X-VHECO-Station` and `X-VHECO-Api-Key` headers via `password_verify` against the stored hash, and reject unknown/inactive/invalid-key stations with 401/403 responses; update `last_heartbeat_at` on every successful auth. | High     |
| **FR-02** | Resident QR Scan & Session Creation                | Upon hardware submitting a scanned QR code, look up the resident in the users/residents table, confirm the resident status is active, ensure no other open (non-final) session exists for that user (via unique index on `(user_id, status)`), create a new `ecopoint_waste_sessions` row with status `ACTIVE`, and return a server-generated 64-char opaque session_token to the hardware for this session. | High     |
| **FR-03** | Waste Material & Weight Data Submission            | Accept hardware POSTs of material type (Plastic/Aluminum/Paper/Cardboard) and weight in kg tied to a valid session_token; validate the material is in `ECO_ALLOWED_MATERIALS`, weight > 0, and the referenced session is ACTIVE; persist `raw_hardware_data` JSON for audit. | High     |
| **FR-04** | Backend Points Calculation (Source of Truth)       | Recalculate point values server-side using the rate table `ECO_MATERIAL_RATES` (Plastic 55, Aluminum 140, Paper 30, Cardboard 30 pts/kg), and **never** trust any point value supplied by the hardware; store `points_calculated` before cap application. | High     |
| **FR-05** | Daily/Weekly/Balance Cap Enforcement               | Before awarding, compute and enforce (a) daily point cap of 100 pts, (b) weekly point cap of 250 pts (Monday 12:00 AM reset), (c) maximum resident balance of 3,000 pts; cap the award to the lowest remaining headroom, record applied cap values on the session row, and award only `points_awarded` after capping. | High     |
| **FR-06** | Atomic Points Posting & Double-Award Prevention    | On session COMPLETED, atomically (a) write exactly one `point_transactions` row tied via unique FK `ecopoint_session_id`, (b) increment `users.points` / `residents.ecopoint_balance`, (c) set `points_posted = 1` and `posted_transaction_id` on the session row; if any step fails, roll back so points are never awarded twice. | High     |
| **FR-07** | Session State Machine & Audit Event Logging        | Enforce the lifecycle `WAITING → ACTIVE → PROCESSING → COMPLETED | CANCELLED | ERROR`; reject out-of-order transitions; log every state change and hardware payload to `ecopoint_session_events` as a JSON `event_payload` with timestamps, enabling full post-hoc session reconstruction. | High     |
| **FR-08** | Resident EcoPoint Dashboard (via VictorianPass)    | Within the VictorianPass resident dashboard, display current EcoPoint balance, a paginated transaction history (transaction_type, material, weight_kg, amount, created_at, station/location), and near-real-time active-session status via polling `api_resident/ecopoint_session_status.php` or SSE; allow residents to redeem points during the VictorianPass amenity checkout flow. | High     |
| **FR-09** | Admin EcoPoint KPIs & Session Management          | Within the VictorianPass admin panel, render KPIs (stations total, stations online via 2-min heartbeat, active sessions now, points today, sessions today, points this week, sessions this week); display a stations grid with online/offline coloring, a live open-sessions list, searchable/paginated session history, and a session-detail modal with waste-items breakdown + full event log; allow admin to register/revoke station API keys. | Medium   |
| **FR-10** | Session Timeout, Cancel, & Error Recovery          | Automatically timeout an ACTIVE/PROCESSING session to ERROR after `ECO_SESSION_TIMEOUT_SEC` (600 s = 10 min); expose explicit hardware endpoints to CANCEL or ERROR a session with a message; prevent any session in a final state (COMPLETED/CANCELLED/ERROR) from accepting new weight data or being re-opened. | High     |

---

## 3. Non-Functional Requirements

### Performance
- The system shall process each hardware session-creation request (QR verify + open session) within 500 ms at the 95th percentile, excluding physical network latency between the station and the server.
- The backend points-calculation pipeline (material + weight → cap checks → points awarded) shall complete in under 200 ms per session.
- The VictorianPass-hosted resident EcoPoint dashboard page shall fully render (balance, recent transactions) within three (3) seconds on a 5 Mbps residential connection.
- The admin EcoPoint KPI dashboard shall compute and display all 8 summary metrics (stations online, active sessions, pts today, sessions today, pts week, sessions week, etc.) in under one (1) second against a database of 100,000 historical sessions.
- The `api_resident/ecopoint_session_status.php` polling endpoint shall respond in under 100 ms so the resident UI can refresh active-session progress smoothly.

### Security
- The system shall store all EcoPoint station API keys using PHP's `password_hash(PASSWORD_BCRYPT)` only; plaintext keys shall never appear in any database, log file, configuration dump, or email. Verification shall use `password_verify` exclusively.
- Every hardware API call shall authenticate via mandatory headers `X-VHECO-Station` and `X-VHECO-Api-Key`; missing headers, unknown station codes, inactive stations, or invalid keys shall return 401/403 **before** any business logic runs.
- The points award path shall be transactionally atomic. A `points_posted` TINYINT(1) flag on `ecopoint_waste_sessions` combined with a unique foreign-key constraint on `point_transactions.ecopoint_session_id` shall mathematically prevent double-awarding, even on retries, crashes, or duplicate POSTs from the hardware.
- Resident sessions shall be isolated: no hardware call shall be able to read or write another resident's session without possession of the 64-character per-session opaque token.
- VictorianPass sessions used to view the EcoPoint dashboards shall use the platform's standard 45-minute inactivity timeout and `session_regenerate_id(true)` on login.

### Usability
- The VictorianPass-hosted EcoPoint dashboards shall use the Poppins font family and the same responsive breakpoints as the rest of the platform so residents and admins experience a single consistent UI.
- Resident dashboard shall show the current EcoPoint balance as a large, prominent card at the top; the last 10 transactions shall be visible without additional clicks or pagination.
- Admin KPIs shall be displayed as color-coded metric cards (green = online, red = offline) so station-health status is understandable at a glance.
- Hardware bridge error codes (AUTH_FAIL, SESSION_NOT_FOUND, SESSION_NOT_ACTIVE, WEIGHT_INVALID, CAP_EXCEEDED) shall be returned as human-readable `message` strings the kiosk UI can surface directly to residents.
- Material selection on the station side (kiosk UI at [ecopoint-station/index_hardware.php](file:///C:/xampp/htdocs/VictorianPass/ecopoint-station/index_hardware.php)) shall require at most two taps to select the recyclable category.

### Reliability
- The system shall achieve at least 99% monthly uptime for the EcoPoint API endpoints (excluding scheduled maintenance windows of ≤2 hours announced at least 48 hours in advance).
- The session state machine shall be fully recoverable: any interrupted session shall persist in its last known state and have a complete `ecopoint_session_events` log, so admins can manually reconcile and refund/award points if hardware loses power mid-session.
- If a hardware POST to complete a session fails due to network blip, the backend shall accept the same replayed completion request idempotently (no double award) thanks to `points_posted` + unique FK.
- Station heartbeat-based offline detection (< 2 minutes since last_heartbeat_at = ONLINE else OFFLINE) shall be monotonic and self-healing when the station reconnects.

### Compatibility
- The EcoPoint hardware bridge ([station_bridge.py](file:///C:/xampp/htdocs/VictorianPass/ecopoint-station/hardware/station_bridge.py)) shall be compatible with Python 3.8+ and run on both Windows (kiosk PC) and Linux single-board computers (Raspberry Pi 3+/4) over a local HTTP server on port 8080.
- The VictorianPass-hosted EcoPoint UI shall be compatible with the latest two stable releases of Chrome, Firefox, Edge, and Safari on desktop, plus Chrome Mobile and Safari Mobile on phones/tablets used by residents and guards.
- All API payloads shall use JSON with UTF-8 encoding. Endpoints shall set `Content-Type: application/json` and accept standard HTTP methods (GET/POST) with CORS relaxed only for the station LAN origin.
- The MySQL backend shall run on MySQL 5.7+ or MariaDB 10.3+ using InnoDB and utf8mb4_unicode_ci, consistent with the hosting environment.

### Scalability
- The backend shall support at least 8 simultaneous EcoPoint stations (deployed at different subdivision gates/clubhouse areas) hitting the API concurrently, with no database deadlocks on points writes (transaction ordering enforced by user-level row-locking).
- The points ledger `point_transactions` shall be index-optimized for per-resident lookups (`idx_point_transactions_user_id`) and per-session FK uniqueness so it grows comfortably to 1,000,000+ transaction rows without dashboard query degradation.
- New stations shall be provisionable solely by inserting a new row in `ecopoint_stations`; no schema changes or redeploys shall be required.

### Auditability & Data Integrity
- Every EcoPoint session shall have a complete, immutable-style event log in `ecopoint_session_events` capturing (at minimum) auth, QR scanned, weight submitted, cap applied, points posted, and final state transitions. Payloads shall be stored as JSON for forensics.
- No `UPDATE` or `DELETE` shall be permitted against `point_transactions` rows from application code; point adjustments shall be written as new `transaction_type = 'adjustment'` rows with a signed amount and descriptive note.
- The system shall automatically back up `ecopoint_*` tables plus `point_transactions` as part of the nightly 24-hour VictorianPass database backup, retaining 14 daily + 4 weekly copies.

