import os

MODEL_NAME = os.getenv("MODEL_NAME", "yolov8n.pt")
CALLBACK_SECRET = os.getenv("CALLBACK_SECRET", "change-me-secret")
CONF_THRESHOLD = float(os.getenv("CONF_THRESHOLD", "0.35"))
DEFAULT_DANGER_DWELL_SECONDS = float(os.getenv("DANGER_DWELL_SECONDS", "5"))
TRAIL_LENGTH = int(os.getenv("TRAIL_LENGTH", "30"))  # jumlah titik jejak lintasan yang disimpan per objek

# Mapping id kelas COCO -> nama kelas yang dihitung aplikasi ini.
# 0=person, 1=bicycle, 2=car, 3=motorcycle, 5=bus, 7=truck
TARGET_CLASSES = {
    0: "person",
    1: "bicycle",
    2: "car",
    3: "motorcycle",
    5: "bus",
    7: "truck",
}
