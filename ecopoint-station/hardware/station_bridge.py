#!/usr/bin/env python3
"""
VHEcoPoint Hardware Bridge
Runs on Raspberry Pi or similar single-board computer
Connects weight scale, QR scanner to station web UI
"""

import time
import json
import requests
import threading
import webbrowser
from http.server import HTTPServer, BaseHTTPRequestHandler
from queue import Queue

# --- Configuration ---
API_BASE_URL = "http://localhost/VictorianPass/ecopoint-station/api"
STATION_UI_URL = "http://localhost/VictorianPass/ecopoint-station/"
SCAN_EVENT_QUEUE = Queue()
WEIGHT_UPDATE_QUEUE = Queue()
CURRENT_RESIDENT = None


# --- Mock Hardware (replace with real code) ---
class MockQRScanner:
    """Mock QR scanner (replace with real scanner code)"""
    def scan_loop(self):
        print("[QR Scanner] Ready. Type a QR code (or 'demo' for test)")
        while True:
            try:
                qr_code = input().strip()
                if qr_code:
                    SCAN_EVENT_QUEUE.put(qr_code)
            except Exception as e:
                print(f"[QR Scanner Error] {e}")


class MockWeightScale:
    """Mock weight scale (replace with real scale code via serial/USB)"""
    def weight_loop(self):
        print("[Weight Scale] Ready")
        weight = 0.0
        while True:
            time.sleep(0.5)
            # Simulate adding weight
            if CURRENT_RESIDENT is not None:
                weight += 0.02
                weight = min(weight, 9.99)
                WEIGHT_UPDATE_QUEUE.put(round(weight, 2))


# --- HTTP Server for UI Communication ---
class StationBridgeHandler(BaseHTTPRequestHandler):
    def _set_headers(self, status=200):
        self.send_response(status)
        self.send_header('Content-type', 'application/json')
        self.send_header('Access-Control-Allow-Origin', '*')
        self.send_header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
        self.send_header('Access-Control-Allow-Headers', 'Content-Type')
        self.end_headers()

    def do_OPTIONS(self):
        self._set_headers()

    def do_GET(self):
        if self.path == '/events':
            # Long polling for events
            self._set_headers()
            try:
                # Wait for next event (timeout after 30s)
                event = None
                # Check both queues
                if not SCAN_EVENT_QUEUE.empty():
                    qr = SCAN_EVENT_QUEUE.get()
                    event = {'type': 'qr_scan', 'data': {'qr_code': qr}}
                elif not WEIGHT_UPDATE_QUEUE.empty():
                    w = WEIGHT_UPDATE_QUEUE.get()
                    event = {'type': 'weight_update', 'data': {'weight_kg': w}}
                
                if event:
                    self.wfile.write(json.dumps(event).encode('utf-8'))
                else:
                    self.wfile.write(json.dumps({'type': 'ping'}).encode('utf-8'))
            except Exception as e:
                self.wfile.write(json.dumps({'error': str(e)}).encode('utf-8'))
        elif self.path == '/':
            self._set_headers()
            self.wfile.write(json.dumps({'status': 'ok', 'bridge': 'VHEcoPoint'}).encode('utf-8'))
        else:
            self._set_headers(404)

    def do_POST(self):
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length)
        data = json.loads(post_data)

        if self.path == '/session/start':
            global CURRENT_RESIDENT
            CURRENT_RESIDENT = data.get('resident')
            self._set_headers()
            self.wfile.write(json.dumps({'status': 'ok'}).encode('utf-8'))
        elif self.path == '/session/stop':
            CURRENT_RESIDENT = None
            self._set_headers()
            self.wfile.write(json.dumps({'status': 'ok'}).encode('utf-8'))
        else:
            self._set_headers(404)

    def log_message(self, format, *args):
        # Quiet logging
        pass


def run_http_server():
    server_address = ('', 8080)
    httpd = HTTPServer(server_address, StationBridgeHandler)
    print(f"[Bridge Server] Running on http://0.0.0.0:8080")
    httpd.serve_forever()


# --- Main ---
if __name__ == "__main__":
    print("=" * 50)
    print("VHEcoPoint Hardware Bridge")
    print("=" * 50)

    # Start mock hardware threads
    qr_thread = threading.Thread(target=MockQRScanner().scan_loop, daemon=True)
    weight_thread = threading.Thread(target=MockWeightScale().weight_loop, daemon=True)
    server_thread = threading.Thread(target=run_http_server, daemon=True)

    qr_thread.start()
    weight_thread.start()
    server_thread.start()

    # Open station UI in browser
    time.sleep(1)
    try:
        webbrowser.open(STATION_UI_URL)
    except Exception as e:
        print(f"[Browser] Could not auto-open UI: {e}")
        print(f"Please open manually: {STATION_UI_URL}")

    # Keep main thread alive
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        print("\n[Bridge] Shutting down...")
