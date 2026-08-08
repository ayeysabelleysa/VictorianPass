# VHEcoPoint Station Hardware Integration Guide

This guide explains how to connect the VHEcoPoint Station UI to physical hardware (QR scanner, weight scale, etc.)

## Overview

The system has 3 main components:
1. **Station UI**: `index_hardware.php` - the main touchscreen interface
2. **Backend API**: `api/` folder - PHP endpoints to verify residents and save sessions
3. **Hardware Bridge**: `hardware/station_bridge.py` - Python script that connects physical hardware to the UI

## Quick Start (Local Testing)

### 1. Set Up Database
First, run the database migration to add EcoPoint tables:
- Open phpMyAdmin (http://localhost/phpmyadmin)
- Select your `victorianpass_db` database
- Import `../DATABASE/ecopoint_migration.sql`

Make sure your `residents` table has:
- `ecopoint_balance` (INT, default 0)
- `qr_code` (VARCHAR, unique)

### 2. Test the Hardware Bridge (Mock Mode)
The bridge comes with mock hardware for testing!
```bash
cd c:\xampp\htdocs\VictorianPass\ecopoint-station\hardware
python station_bridge.py
```

This will:
- Start a local web server on port 8080
- Open the station UI in your default browser
- Let you test by typing QR codes in the terminal (try "demo")

### 3. View the Station UI
Open `http://localhost/VictorianPass/ecopoint-station/index_hardware.php`

---

## Connecting Real Hardware

### A. QR Code Scanner
Most USB QR scanners act like a keyboard (HID device). When you scan a QR code, it types the data and presses Enter.

To integrate:
1. Modify `station_bridge.py`'s `MockQRScanner` class to read from your scanner
2. Common options:
   - Use `pyserial` if scanner uses serial port
   - Use `evdev` (Linux) to read keyboard input events
   - Use OpenCV + camera for visual QR scanning (see optional dependencies)

### B. Weight Scale
Connect your digital scale via USB or serial.

To integrate:
1. Find your scale's COM port (Windows) or /dev/tty* (Linux)
2. Modify `MockWeightScale` to read from the scale
3. Use `pyserial` to communicate with serial scales

### C. Material Sensors (Optional)
Add sensors to detect plastic/aluminum/paper! Update the bridge to send material detection events to the UI.

---

## API Endpoints

### Verify Resident
```
POST /VictorianPass/ecopoint-station/api/verify_resident.php
Content-Type: application/json

{
  "qr_code": "RESIDENT_QR_CODE"
}

Response:
{
  "success": true,
  "data": {
    "resident_id": 123,
    "name": "Juan Dela Cruz",
    "balance": 420,
    "weeklyRemaining": 180,
    "dailyRemaining": 2
  }
}
```

### Complete Session
```
POST /VictorianPass/ecopoint-station/api/complete_session.php
Content-Type: application/json

{
  "resident_id": 123,
  "material": "Plastic",
  "weight_kg": 1.5,
  "points_earned": 83
}

Response:
{
  "success": true,
  "data": {
    "session_id": 456,
    "new_balance": 503
  }
}
```

---

## Hardware Bridge API (Local)

The Python bridge runs on http://localhost:8080 and provides these endpoints:

### Get Events (Long Polling)
```
GET /events
Response: {"type": "qr_scan", "data": {"qr_code": "..."}}
OR: {"type": "weight_update", "data": {"weight_kg": 1.5}}
```

### Session Control
```
POST /session/start
Body: {"resident": {...}}

POST /session/stop
```

---

## Deployment on Raspberry Pi

1. Install Raspberry Pi OS (Desktop version recommended)
2. Install Apache + PHP + MySQL (or use XAMPP for ARM)
3. Copy the project to `/var/www/html/VictorianPass`
4. Set up MySQL database
5. Run the bridge script on startup (using systemd)
6. Configure Chromium to open the station UI in kiosk mode on boot

---

## Troubleshooting

### Database Connection Error
Check that:
- MySQL is running in XAMPP
- `connect.php` has correct credentials
- The migration script was run successfully

### Bridge Not Connecting
Check port 8080 is not in use, or change the port in `station_bridge.py`

### QR Scanner Not Working
Make sure the scanner is in "USB HID" mode and sends a newline after each scan
