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

# Deteksi "orang ini menumpang kendaraan itu" memakai dua syarat geometris,
# BUKAN rasio luas irisan (rasio luas terlalu ketat -- box orang biasanya
# jauh lebih tinggi daripada box motor karena mencakup kepala & badan atas,
# jadi irisannya kecil walau orangnya jelas-jelas duduk di atas motor):
#
# 1. Pusat horizontal (center-x) box orang harus berada di dalam rentang-x
#    box kendaraan, dengan sedikit toleransi (RIDER_X_MARGIN_RATIO x lebar
#    kendaraan) -- pengendara selalu duduk/berdiri tepat di atas
#    kendaraannya secara horizontal, terlepas dari seberapa besar box-nya.
# 2. Harus ada irisan vertikal (sekecil apa pun) antara kedua box -- badan
#    orang menumpuk di atas/bersinggungan dengan body kendaraan, bukan
#    berdiri terpisah di tanah.
RIDER_X_MARGIN_RATIO = float(os.getenv("RIDER_X_MARGIN_RATIO", "0.25"))
