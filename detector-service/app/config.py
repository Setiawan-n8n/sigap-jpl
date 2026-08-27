import os

MODEL_NAME = os.getenv("MODEL_NAME", "yolov8s.pt")
CALLBACK_SECRET = os.getenv("CALLBACK_SECRET", "change-me-secret")

# PENTING (redesign 27 Agu 2026, lihat docstring _record_raw_stream &
# _run_live_detection di main.py): SEBELUMNYA nilai default ini 0.35, dan
# dipakai LANGSUNG sebagai filter `conf=` pada pemanggilan model.track() --
# artinya deteksi dengan confidence di bawah 0.35 dibuang TOTAL sebelum
# sempat sampai ke tracker ByteTrack sama sekali.
#
# Ini bertentangan dengan cara kerja ByteTrack sendiri: algoritma ini SENGAJA
# dirancang dua tahap -- deteksi confidence TINGGI (>= track_high_thresh)
# dipakai untuk asosiasi utama, deteksi confidence RENDAH (di antara
# track_low_thresh & track_high_thresh, lihat bytetrack_sigap.yaml) tetap
# dipakai untuk MELANJUTKAN track yang sudah ada (mis. motor yang sesaat
# konfiden modelnya turun karena oklusi sebagian/sudut kamera), TAPI tidak
# pernah dipakai untuk memulai track BARU (itu hak track_high_thresh/
# new_track_thresh saja). Kalau conf global sudah membuang semua deteksi di
# bawah 0.35 sebelum tracker sempat melihatnya, separuh mekanisme ByteTrack
# ini otomatis mati -- track yang objeknya sempat sedikit tertutup akan
# HILANG (bukan dilanjutkan), lalu saat objeknya terlihat jelas lagi
# beberapa frame kemudian, ByteTrack menganggapnya track BARU dengan ID
# baru. Akibatnya: kendaraan yang sama bisa ter-tally lebih dari sekali
# (dobel hitung) ATAU malah tidak ter-tally sama sekali (kalau ID barunya
# muncul saat sudah lewat dari poligon zona) -- ini kemungkinan besar
# kontributor utama laporan "jumlah kendaraan masih terlalu sedikit",
# terpisah dari (dan bisa terjadi bersamaan dengan) soal pilihan model.
#
# Perbaikan: turunkan filter conf GLOBAL ini mendekati track_low_thresh di
# bytetrack_sigap.yaml (0.1), supaya deteksi confidence rendah tetap sampai
# ke tracker untuk keperluan KELANJUTAN track -- track BARU tetap tidak
# akan pernah dibuat dari deteksi selemah itu (dijaga oleh new_track_thresh
# di tracker, bukan oleh nilai ini), jadi ini TIDAK menambah false-positive
# baru ke hitungan, hanya mengurangi track yang hilang akibat oklusi
# sesaat.
CONF_THRESHOLD = float(os.getenv("CONF_THRESHOLD", "0.1"))

DEFAULT_DANGER_DWELL_SECONDS = float(os.getenv("DANGER_DWELL_SECONDS", "5"))
TRAIL_LENGTH = int(os.getenv("TRAIL_LENGTH", "30"))  # jumlah titik jejak lintasan yang disimpan per objek

# Ukuran input (imgsz) inferensi YOLO -- DIPAKAI BERSAMA oleh mode unggah
# file MAUPUN sesi live sejak redesign 27 Agu 2026 (lihat docstring
# _run_live_detection soal kenapa sesi live sekarang tidak lagi punya
# tekanan real-time, jadi tidak lagi butuh model/imgsz terpisah yang lebih
# ringan seperti LIVE_MODEL_NAME/LIVE_IMGSZ versi sebelumnya -- keduanya
# sudah dipensiunkan). Dibuat lewat env var (bukan konstanta di kode)
# supaya bisa dinaikkan (mis. ke 960) untuk kendaraan kecil/jauh di CCTV
# tanpa build ulang image, kalau hasil lapangan menunjukkan itu masih
# kurang -- trade-off-nya waktu proses lebih lama per video/sesi.
DETECT_IMGSZ = int(os.getenv("DETECT_IMGSZ", "640"))

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
