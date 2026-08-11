# VictorianPass
SYSTEM - VictorianPass: An Online Amenity Reservation System with QR-based Entry pass Security for Victorian Heights Subdivision

## Hardware integration (VHEcoPoint Station)

This repository includes a ready-made hardware integration path for the VHEcoPoint smart waste station. A full, prescriptive hardware integration guide is available at [ecopoint-station/README.md](ecopoint-station/README.md) — the following is a short summary and quick-start.

- Station bridge (hardware agent): `ecopoint-station/hardware/station_bridge.py` — example Python bridge that reads sensors and calls the backend API.
- Server API endpoints for hardware live under: `api_hardware/` (examples: `qr_verify_and_create_session.php`, `submit_waste_data.php`, `complete_session.php`, `cancel_session.php`, `error_session.php`).
- Authentication: hardware must send these HTTP headers on every request: `X-VHECO-Station` and `X-VHECO-Api-Key` (see `ecopoint-station/README.md` for details and seed key).
- Resident UI: the resident dashboard (`profileresident.php`) uses Server-Sent Events (SSE) to receive live session updates — no station display is required.

Quick curl example (replace host, station code & key):

```bash
curl -X POST "http://localhost/VictorianPass/api_hardware/qr_verify_and_create_session.php" \
	-H "Content-Type: application/json" \
	-H "X-VHECO-Station: VH-ECO-001" \
	-H "X-VHECO-Api-Key: vheco-station-VH-ECO-001-default-api-key-secret-change-me" \
	-d '{"qr_code":"VP-RESIDENT-00012"}'
```

For a fully-detailed integration (endpoints, request/response examples, auth, and troubleshooting) see: `ecopoint-station/README.md`.

If you want, I can also:
- Add a short `hardware/` README that documents recommended network setup, firewall rules, and a small diagram for the hardware team's handoff.
- Remove or refactor any remaining UI copy that references "VictorianPass" to instead say "Personal QR Code" across other pages.

