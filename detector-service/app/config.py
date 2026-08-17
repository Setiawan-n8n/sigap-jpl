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

# Kelas kendaraan yang mungkin "ditumpangi" seseorang. Dipakai untuk
# membedakan pejalan kaki asli dari pengendara/penumpang kendaraan supaya
# angka "Orang" tidak dobel-hitung dengan "Motor"/"Mobil"/dst.
RIDER_HOST_CLASSES = {"bicycle", "motorcycle", "car", "bus", "truck"}

# Seberapa besar porsi bounding box "person" yang harus tertutup oleh
# bounding box kendaraan (0..1) supaya orang tsb dianggap pengendara/
# penumpang, bukan pejalan kaki. Makin kecil nilainya, makin agresif
# mengecualikan orang di dekat kendaraan (risiko pejalan kaki yang lewat
# tepat di samping motor ikut ter-exclude); makin besar, makin longgar
# (risiko pengendara tetap kehitung sebagai pejalan kaki).
RIDER_OVERLAP_THRESHOLD = float(os.getenv("RIDER_OVERLAP_THRESHOLD", "0.3"))
