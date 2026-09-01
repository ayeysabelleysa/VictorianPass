import cv2
import time

RTSP_URL = "rtsp://192.168.100.153:554/live"

print("Connecting to V380...")
print(RTSP_URL)

cap = cv2.VideoCapture(RTSP_URL)

if not cap.isOpened():
    print("ERROR: Could not open V380 stream")
    exit()

print("V380 stream connected!")

frame_count = 0
start_time = time.time()

while True:
    ret, frame = cap.read()

    if not ret:
        print("ERROR: Could not read frame")
        break

    frame_count += 1

    # Print status every 30 frames
    if frame_count % 30 == 0:
        elapsed = time.time() - start_time

        if elapsed > 0:
            fps = frame_count / elapsed
            print(
                f"Receiving video: "
                f"{frame_count} frames | "
                f"{fps:.1f} FPS | "
                f"Resolution: {frame.shape[1]}x{frame.shape[0]}"
            )

cap.release()