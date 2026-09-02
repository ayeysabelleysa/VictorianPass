# VictorianPass
SYSTEM - VictorianPass: An Online Amenity Reservation System with QR-based Entry pass Security for Victorian Heights Subdivision

## Hardware integration (VHEcoPoint Station)

All server API endpoints live in the centralized `api/` folder:

| Endpoint | Purpose |
|---|---|
| `api/qr_verify_and_create_session.php` | Scan resident QR → create waste session |
| `api/submit_waste_data.php` | Submit weight + material for active session |
| `api/complete_session.php` | Finalize session, award points |
| `api/cancel_session.php` | Cancel an active session |
| `api/error_session.php` | Report sensor/error fault |
| `api/ecopoint_sse.php` | SSE stream for live resident dashboard updates |
| `api/ecopoint_session_status.php` | One-shot session status snapshot |

**Authentication** — hardware must send these HTTP headers on every request: `X-VHECO-Station` and `X-VHECO-Api-Key` (see `ecopoint_core.php` for validation logic and seed key defaults).

**Resident UI** — the resident dashboard (`profileresident.php`) uses Server-Sent Events (SSE) to receive live session updates — no station display is required.

Quick curl example (replace host, station code & key):

```bash
curl -X POST "http://localhost/VictorianPass/api/qr_verify_and_create_session.php" \
	-H "Content-Type: application/json" \
	-H "X-VHECO-Station: VH-ECO-001" \
	-H "X-VHECO-Api-Key: vheco-station-VH-ECO-001-default-api-key-secret-change-me" \
	-d '{"qr_code":"VP-RESIDENT-00012"}'
```

