import os
import re
import subprocess
import threading
import time
import traceback
import uuid
from collections import defaultdict, deque
from datetime import datetime, timedelta, timezone
from typing import List, Optional

import cv2
import requests
import torch
from fastapi import FastAPI, HTTPException
from pydantic import BaseModel

# PyTorch >= 2.6 mengubah default torch.load menjadi weights_only=True, yang
# bisa membuat ultralytics gagal memuat checkpoint YOLO (.pt berisi objek
# Python yang di-pickle, bukan cuma tensor) dan meng-crash service ini saat
# startup. Patch ini menyamakan perilaku dengan yang sudah dipakai di
# Dockerfile saat build (lihat RUN unduh bobot model), tapi diterapkan juga
# di runtime supaya konsisten di semua versi torch yang ter-install.
_orig_torch_load = torch.load
torch.load = lambda *a, **k: _orig_torch_load(*a, **{**k, "weights_only": False})

from ultralytics import YOLO

from .config import (
    CALLBACK_SECRET,
    CONF_THRESHOLD,
    DEFAULT_DANGER_DWELL_SECONDS,
    DETECT_IMGSZ,
    MODEL_NAME,
    RIDER_HOST_CLASSES,
    RIDER_X_MARGIN_RATIO,
    TARGET_CLASSES,
    TRAIL_LENGTH,
)
from .tracker import Zone, ZoneTracker, build_polygon, hex_to_bgr, track_color

# Header User-Agent yang menyamar sebagai browser Chrome biasa -- dipakai
# untuk SEMUA pembacaan stream CCTV (snapshot maupun live terus-menerus),
# karena banyak proxy CCTV instansi (mis. ATCS pemda) menolak/membatasi
# koneksi yang tidak "terlihat" seperti request browser biasa.
BROWSER_USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36"
)


# PENTING (redesign 27 Agu 2026 -- lihat doc proyek
# "fix-live-tracking-loncat-loncat.md" & docstring _run_live_detection di
# bawah): fungsi _open_stream_capture() dan class _LiveStreamReader yang
# tadinya ada di sini SUDAH DIPENSIUNKAN. Keduanya dulu dipakai supaya
# _run_live_detection bisa membaca stream CCTV frame-demi-frame secara
# real-time sambil menjalankan YOLO di loop yang sama, lalu memacing
# penulisan video hasil mengikuti waktu nyata (dengan duplikasi frame kalau
# YOLO lebih lambat dari CCTV aslinya). Pendekatan itu tetap bisa terlihat
# patah-patah kalau CPU host sedang berat, karena rasio duplikasi frame
# naik-turun tidak bisa dijamin selalu rata betapa pun kalibrasinya
# ditingkatkan.
#
# Sesi live sekarang direkam DULU ke file (lihat _record_raw_stream), baru
# diproses lewat pipeline dua-tahap yang SAMA PERSIS dengan mode unggah
# file (_detect_and_render) -- video hasil jadi otomatis 100% sinkron
# dengan frame rate rekaman asli (sama seperti mode unggah, yang tidak
# pernah punya masalah "loncat-loncat" ini), tanpa perlu kalibrasi/pacing
# real-time sama sekali. Konsekuensinya: hasil (video + hitungan) baru siap
# beberapa saat SETELAH sesi live selesai (durasi ekstra kira-kira = waktu
# YOLO memproses rekaman tsb), bukan lagi "mengalir" selama sesi berjalan --
# tapi UI tetap hanya menampilkan status "completed" setelah selesai baik
# di desain lama maupun baru, jadi tidak ada perubahan perilaku yang
# terlihat pengguna selain jeda tambahan ini.

app = FastAPI(title="SIGAP-JPL Detector Service")

# PENTING: pastikan PyTorch benar-benar memakai semua core CPU yang
# tersedia untuk inferensi (bukan cuma 1 core) -- di dalam container,
# jumlah thread default PyTorch kadang tidak terdeteksi dengan benar dari
# limit cgroup, jadi disetel eksplisit ke jumlah core yang terlihat oleh
# proses ini. Ini aman dipasang meski defaultnya sudah benar.
try:
    _cpu_count = os.cpu_count() or 1
    torch.set_num_threads(_cpu_count)
    print(f"torch.set_num_threads({_cpu_count})")
except Exception:  # noqa: BLE001
    traceback.print_exc()

print(f"Loading YOLO model: {MODEL_NAME}")
model = YOLO(MODEL_NAME)

# PENTING (redesign 27 Agu 2026): dulu ada `model_live` terpisah (varian
# yolov8n lebih ringan) khusus dipakai _run_live_detection karena sesi live
# diproses real-time (tekanan kecepatan). Sejak sesi live direkam dulu lalu
# diproses lewat pipeline yang sama dengan mode unggah file (lihat
# _detect_and_render), tidak ada lagi tekanan real-time itu -- SATU model
# (`model`, full akurasi) sekarang dipakai untuk KEDUA mode, sekaligus jadi
# bagian dari perbaikan akurasi hitungan (lihat juga CONF_THRESHOLD &
# bytetrack_sigap.yaml di config.py/tracker config).
TRACKER_CONFIG_PATH = os.path.join(os.path.dirname(__file__), "bytetrack_sigap.yaml")

# PENTING (fix tracking "loncat-loncat"/tidak stabil saat >1 sesi berjalan
# bersamaan): `model` di atas adalah OBJEK GLOBAL TUNGGAL yang dipakai
# bersama oleh SEMUA thread pemrosesan (_process_video & _process_live_video
# masing-masing dijalankan di background thread-nya sendiri per request --
# lihat endpoint /process & /process-live). Kalau ada 2+ video diunggah
# bersamaan, ATAU 2+ lokasi JPL menjalankan sesi live yang jadwal
# pemrosesannya tumpang tindih, beberapa thread akan memanggil
# model.track() PADA OBJEK YANG SAMA secara bersamaan.
#
# ultralytics menyimpan STATE tracker ByteTrack (mis. daftar track aktif
# beserta ID-nya) menempel pada objek model itu sendiri (self.predictor),
# BUKAN per-panggilan -- ini TIDAK aman dipakai dari banyak thread
# sekaligus. Kalau dua thread memanggilnya bersamaan, state tracker milik
# sesi A bisa tertimpa/tercampur dengan sesi B di tengah jalan: box
# berpindah tempat, track ID meloncat/berganti mendadak, atau deteksi
# muncul-hilang tidak wajar -- persis gejala "loncat-loncat"/tidak mulus
# yang terlihat di video hasil, TERLEPAS dari seberapa cepat inferensinya.
#
# Perbaikan: satu lock global, membungkus SETIAP pemanggilan .track()-nya
# (lihat pemakaian di _detect_and_render). Konsekuensinya sesi-sesi yang
# tumpang tindih jadi diproses BERGANTIAN (serial) alih-alih benar-benar
# paralel saat berebut model yang sama -- throughput sedikit menurun saat
# banyak sesi bersamaan, tapi jauh lebih baik daripada tracking yang diam-
# diam korup tanpa pernah muncul sebagai error apa pun.
_model_lock = threading.Lock()


class ZoneSchema(BaseModel):
    name: str
    type: str = "direction"  # "direction" | "danger"
    points: List[List[float]]
    color: Optional[str] = None


class ProcessRequest(BaseModel):
    job_id: int
    video_path: str
    location_name: str = "JPL"
    recorded_at: Optional[str] = None
    zones: List[ZoneSchema]
    classes: List[str] = ["person", "bicycle", "car", "motorcycle", "bus", "truck"]
    danger_dwell_seconds: float = DEFAULT_DANGER_DWELL_SECONDS
    callback_url: str
    progress_url: Optional[str] = None
    callback_secret: Optional[str] = None


class SnapshotRequest(BaseModel):
    url: str


class ProcessLiveRequest(BaseModel):
    job_id: int
    stream_url: str
    location_name: str = "JPL"
    start_at: Optional[str] = None
    finish_at: str
    zones: List[ZoneSchema]
    classes: List[str] = ["person", "bicycle", "car", "motorcycle", "bus", "truck"]
    danger_dwell_seconds: float = DEFAULT_DANGER_DWELL_SECONDS
    callback_url: str
    progress_url: Optional[str] = None
    callback_secret: Optional[str] = None


@app.get("/health")
def health():
    return {"status": "ok", "model": MODEL_NAME}


@app.post("/process")
def process(req: ProcessRequest):
    if not os.path.exists(req.video_path):
        raise HTTPException(status_code=404, detail=f"Video not found: {req.video_path}")

    if not req.zones:
        raise HTTPException(status_code=422, detail="Minimal satu zona diperlukan.")

    # Proses di background thread supaya endpoint langsung merespons dan Laravel
    # job tidak perlu menunggu proses video yang bisa memakan waktu lama.
    thread = threading.Thread(target=_process_video, args=(req,), daemon=True)
    thread.start()

    return {"accepted": True, "job_id": req.job_id}


@app.post("/snapshot")
def snapshot(req: SnapshotRequest):
    """Ambil satu frame dari stream CCTV (dipanggil admin saat menjadwalkan
    deteksi live, supaya admin bisa menggambar ulang zona di atas kondisi
    kamera terkini -- lihat _capture_snapshot)."""
    try:
        rel_path, width, height = _capture_snapshot(req.url)
    except Exception as exc:  # noqa: BLE001
        traceback.print_exc()
        raise HTTPException(status_code=422, detail=f"Gagal mengambil snapshot: {exc}")

    return {"path": rel_path, "width": width, "height": height}


@app.post("/process-live")
def process_live(req: ProcessLiveRequest):
    """Proses stream CCTV secara LANGSUNG (real-time) lewat YOLOv8 + ByteTrack,
    tanpa merekam ke file terlebih dahulu -- lihat _run_live_detection."""
    if not req.zones:
        raise HTTPException(status_code=422, detail="Minimal satu zona diperlukan.")

    try:
        finish_at = datetime.fromisoformat(req.finish_at)
    except ValueError:
        raise HTTPException(status_code=422, detail="Format finish_at tidak valid.")

    # PENTING: Laravel mengirim finish_at/start_at sebagai ISO8601 DENGAN
    # offset zona waktu (mis. "...+07:00"), jadi datetime.fromisoformat()
    # di atas menghasilkan datetime timezone-AWARE. Membandingkannya
    # langsung dengan datetime.now() (yang NAIVE/tanpa zona waktu) membuat
    # Python melempar "TypeError: can't compare offset-naive and
    # offset-aware datetimes" -- exception ini TIDAK ketangkap oleh
    # `except ValueError` di atas, jadi lolos sebagai 500 Internal Server
    # Error mentah ke Laravel. Ini penyebab sebenarnya endpoint ini selalu
    # gagal dengan 500 setiap kali dicoba. Perbaikannya: samakan dulu
    # menjadi timezone-aware sebelum dibandingkan.
    if finish_at.tzinfo is None:
        finish_at = finish_at.replace(tzinfo=timezone.utc)

    if finish_at <= datetime.now(timezone.utc):
        raise HTTPException(status_code=422, detail="finish_at sudah lewat.")

    thread = threading.Thread(target=_process_live_video, args=(req, finish_at), daemon=True)
    thread.start()

    return {"accepted": True, "job_id": req.job_id}


def _capture_snapshot(url: str):
    """Ambil satu frame dari URL stream (HLS/MP4/RTSP) memakai ffmpeg dan
    simpan sebagai JPEG di volume bersama /data/videos/live-snapshots, supaya
    bisa dibaca balik oleh container Laravel (storage/app/videos) lewat
    volume Docker yang sama. Mengembalikan (path_relatif, width, height)."""
    snapshots_dir = "/data/videos/live-snapshots"
    os.makedirs(snapshots_dir, exist_ok=True)

    filename = f"{uuid.uuid4().hex}.jpg"
    out_path = os.path.join(snapshots_dir, filename)

    cmd = ["ffmpeg", "-y", "-loglevel", "error"]

    # "-rtsp_transport" adalah opsi khusus demuxer RTSP -- kalau dipaksakan
    # ke URL HLS/HTTP (.m3u8/.mp4), ffmpeg gagal karena opsi itu tidak
    # dikenali oleh demuxer yang dipakai. Hanya sertakan untuk URL rtsp://.
    if url.lower().startswith(("rtsp://", "rtsps://")):
        cmd += ["-rtsp_transport", "tcp"]
    else:
        # Banyak proxy CCTV instansi (mis. ATCS pemda) menolak request tanpa
        # User-Agent yang terlihat seperti browser biasa (dianggap bot).
        cmd += ["-user_agent", BROWSER_USER_AGENT]

    cmd += ["-i", url, "-frames:v", "1", "-q:v", "2", out_path]

    result = subprocess.run(cmd, capture_output=True, text=True, timeout=25)

    if result.returncode != 0:
        # Sertakan pesan error ASLI dari ffmpeg (bukan cuma kode exit) supaya
        # penyebabnya (mis. 403 Forbidden, protokol tidak didukung, URL
        # kedaluwarsa) langsung terlihat oleh admin di UI, bukan cuma
        # "returned non-zero exit status".
        stderr_tail = (result.stderr or "").strip().splitlines()
        detail = " | ".join(stderr_tail[-3:]) if stderr_tail else f"exit code {result.returncode}"
        raise RuntimeError(detail)

    frame = cv2.imread(out_path)
    if frame is None:
        raise RuntimeError("File snapshot tidak terbaca (stream mungkin tidak valid).")

    height, width = frame.shape[:2]

    return f"live-snapshots/{filename}", width, height

def _process_video(req: ProcessRequest):
    try:
        counts, annotated_relpath, safety_events = _run_detection(req)
        _send_callback(req, {
            "status": "completed",
            "annotated_path": annotated_relpath,
            "counts": counts,
            "safety_events": safety_events,
        })
    except Exception as exc:  # noqa: BLE001
        traceback.print_exc()
        _send_callback(req, {
            "status": "failed",
            "error_message": str(exc),
        })


def _process_live_video(req: ProcessLiveRequest, finish_at: datetime):
    try:
        counts, annotated_relpath, safety_events = _run_live_detection(req, finish_at)
        _send_callback(req, {
            "status": "completed",
            "annotated_path": annotated_relpath,
            "counts": counts,
            "safety_events": safety_events,
        })
    except Exception as exc:  # noqa: BLE001
        traceback.print_exc()
        _send_callback(req, {
            "status": "failed",
            "error_message": str(exc),
        })


def _sanitize(name: str) -> str:
    return re.sub(r"[^A-Za-z0-9]+", "_", name).strip("_").lower() or "zona"


def _is_riding(person_box, vehicle_box) -> bool:
    """Cek apakah box "person" kemungkinan besar pengendara/penumpang dari
    box kendaraan tsb (lihat penjelasan RIDER_X_MARGIN_RATIO di config.py).
    Sengaja tidak memakai rasio luas irisan -- box orang biasanya jauh lebih
    tinggi dari box motor (mencakup kepala & badan atas), jadi irisan
    areanya kecil walau orangnya jelas sedang menaiki motor tsb."""
    px1, _py1, px2, py2 = person_box
    vx1, vy1, vx2, vy2 = vehicle_box

    person_cx = (px1 + px2) / 2.0
    v_width = max(1e-6, vx2 - vx1)
    x_margin = v_width * RIDER_X_MARGIN_RATIO
    if not (vx1 - x_margin <= person_cx <= vx2 + x_margin):
        return False

    # Harus ada irisan vertikal (badan orang menumpuk di atas kendaraan),
    # bukan sekadar sejajar secara horizontal padahal beda posisi jauh.
    return py2 >= vy1 and _py1 <= vy2


def _purge_riders_from_tally(zone_tracker: ZoneTracker, rider_track_ids: set) -> None:
    """Buang track pengendara/penumpang (rider_track_ids) dari hasil hitungan
    zona (ZoneTracker.direction_counted), BUKAN cuma dari video anotasi.

    PENTING -- ini bug terpisah dari yang sudah ada: kode sebelumnya HANYA
    mengecualikan rider_track_ids saat MENGGAMBAR kotak/label di video
    (lihat loop penggambaran di bawah, ada `if class_name == "person" and
    track_id in rider_track_ids: continue`), tapi TIDAK pernah mengecualikan
    track yang sama dari zone_dets yang dikirim ke ZoneTracker.update().
    Akibatnya, orang yang menaiki motor/mobil tetap ikut ter-tally sebagai
    "Orang" di ZoneTracker.direction_counted & muncul di tabel "Rincian per
    Zona" / grafik "Total per Kategori" -- walau di video hasil deteksinya
    sendiri kotaknya sudah tidak digambar (makanya bug ini tidak kelihatan
    hanya dengan menonton videonya, harus dicek angka tabelnya).

    Dipanggil sekali di akhir (setelah rider_track_ids final -- tidak akan
    bertambah lagi), supaya track yang baru ketahuan sebagai
    pengendara/penumpang belakangan (mis. motornya baru masuk frame
    belakangan) tetap ikut terkoreksi, bukan cuma yang ketahuan sejak awal.
    """
    if not rider_track_ids:
        return
    zone_tracker.direction_counted = {
        (track_id, zone_name)
        for track_id, zone_name in zone_tracker.direction_counted
        if track_id not in rider_track_ids
    }


def _parse_recorded_at(value: Optional[str]) -> datetime:
    if not value:
        return datetime.now()
    try:
        return datetime.fromisoformat(value)
    except ValueError:
        return datetime.now()


def _detect_and_render(
    video_path: str,
    zones_schema: list,
    classes: list,
    danger_dwell_seconds: float,
    location_name: str,
    base_dt: datetime,
    output_path: str,
    snapshots_dir: str,
    snapshot_url_prefix: str,
    file_prefix: str,
    progress_cb=None,
    progress_base: int = 0,
    progress_scale: float = 1.0,
    label_suffix: str = "",
):
    """Inti deteksi + tracking + render dua-tahap atas SATU file video lokal
    yang bisa dibaca ulang (mis. video hasil unggahan, ATAU sejak redesign
    27 Agu 2026, rekaman mentah hasil _record_raw_stream untuk sesi live --
    lihat docstring _run_live_detection).

    Dipakai bersama oleh _run_detection (mode unggah file) MAUPUN
    _run_live_detection (sesi live), supaya keduanya otomatis mendapat
    manfaat yang sama: frame rate video hasil akhir dijamin sinkron dengan
    file sumbernya (karena `fps` diambil langsung dari file, bukan
    ditebak/dipacing), model & tracker yang dipakai SAMA (akurasi penuh,
    tidak ada lagi model/imgsz "versi ringan khusus live"), dan logika
    voting kelas mayoritas + koreksi pengendara/penumpang (lihat
    _purge_riders_from_tally) konsisten di kedua mode -- sebelumnya kedua
    mode punya salinan logika terpisah yang gampang bercabang beda kalau
    salah satunya diperbaiki tapi yang lain lupa ikut diperbaiki.

    Mengembalikan (direction_rows, safety_events, fps_video).
    """
    cap = cv2.VideoCapture(video_path)
    if not cap.isOpened():
        raise RuntimeError("Tidak dapat membuka file video untuk diproses.")

    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = cap.get(cv2.CAP_PROP_FPS) or 25
    # CAP_PROP_FRAME_COUNT bisa tidak akurat untuk beberapa codec/VFR, tapi
    # cukup baik untuk estimasi progress kasar. Kalau tidak tersedia (<= 0),
    # progress reporting berbasis persentase cukup dilewati saja.
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    cap.release()

    last_progress_sent = -1

    def _report(pct_internal: int) -> None:
        nonlocal last_progress_sent
        if progress_cb is None:
            return
        pct = progress_base + int(pct_internal * progress_scale)
        if pct != last_progress_sent:
            progress_cb(pct)
            last_progress_sent = pct

    zones = []
    for z in zones_schema:
        zones.append(Zone(name=z.name, type=z.type, polygon=build_polygon(z.points, width, height)))
    zone_colors = {z.name: hex_to_bgr(zs.color) for z, zs in zip(zones, zones_schema)}

    zone_tracker = ZoneTracker(zones=zones, fps=fps, danger_dwell_seconds=danger_dwell_seconds)
    wanted_ids = [cid for cid, name in TARGET_CLASSES.items() if name in classes]

    os.makedirs(snapshots_dir, exist_ok=True)

    trails: dict = defaultdict(lambda: deque(maxlen=TRAIL_LENGTH))
    safety_events_out = []
    # Track ID "person" yang pernah terdeteksi tumpang tindih signifikan
    # dengan kendaraan -- sekali ditandai sebagai pengendara/penumpang, tetap
    # dikecualikan dari hitungan "Orang" seterusnya (supaya tidak
    # berubah-ubah antar frame hanya karena sudut kamera/oklusi sesaat).
    rider_track_ids: set = set()

    # =====================================================================
    # TAHAP 1 -- Deteksi & tracking penuh atas seluruh video.
    #
    # Kelas hasil YOLO untuk SATU track yang sama kadang "kedip" antar frame
    # (mis. sebuah motor sesaat terbaca "person"/"car" sebelum benar terbaca
    # "motorcycle" beberapa frame kemudian -- padahal track ID-nya tetap).
    # Kalau video langsung digambar & ditulis sambil jalan (satu-pass), label
    # yang tampil di video ikut kedip mengikuti kesalahan itu. Makanya
    # deteksi dipisah jadi dua tahap: tahap ini HANYA mengoleksi semua
    # deteksi mentah per frame (tanpa menggambar apa pun), supaya ZoneTracker
    # sempat mengumpulkan "suara" tiap track dari SELURUH video dan tahu
    # kelas final (mayoritas) tiap track sebelum video digambar.
    # =====================================================================
    all_frame_detections: list = []  # index = frame_idx -> list[(track_id, class_name, box)]

    frame_idx = 0
    progress_interval = max(1, total_frames // 20) if total_frames > 0 else 0
    # PENTING: seluruh iterasi model.track(..., stream=True) dibungkus SATU
    # lock (_model_lock) -- bukan cuma pemanggilan track() itu sendiri --
    # karena `results` di sini adalah GENERATOR yang menjalankan inferensi
    # per-frame secara LAZY tiap kali di-iterasi (bukan sekaligus di awal).
    # Kalau lock hanya membungkus baris pemanggilan model.track() (yang
    # instan, cuma bikin objek generator), thread lain tetap bisa menyelinap
    # memakai `model` yang sama SELAGI generator ini masih diiterasi di loop
    # for di bawah -- lock-nya jadi tidak berguna. Lihat penjelasan lengkap
    # soal kenapa berbagi satu objek model antar-thread berbahaya di komentar
    # dekat deklarasi _model_lock di atas.
    with _model_lock:
        results = model.track(
            source=video_path,
            classes=wanted_ids,
            conf=CONF_THRESHOLD,
            imgsz=DETECT_IMGSZ,
            tracker=TRACKER_CONFIG_PATH,
            persist=True,
            stream=True,
            verbose=False,
        )

        for result in results:
            frame_boxes = []  # (track_id, class_name, box)
            boxes = result.boxes
            if boxes is not None and boxes.id is not None:
                xyxy = boxes.xyxy.cpu().numpy()
                ids = boxes.id.cpu().numpy()
                cls_ids = boxes.cls.cpu().numpy()

                for box, tid, cls_id in zip(xyxy, ids, cls_ids):
                    class_name = TARGET_CLASSES.get(int(cls_id))
                    if class_name is None:
                        continue
                    frame_boxes.append((int(tid), class_name, box))

                # Tandai "person" yang box-nya tumpang tindih signifikan dengan
                # kendaraan sebagai pengendara/penumpang, bukan pejalan kaki.
                vehicle_boxes = [b for _, cn, b in frame_boxes if cn in RIDER_HOST_CLASSES]
                if vehicle_boxes:
                    for tid, cn, box in frame_boxes:
                        if cn != "person" or tid in rider_track_ids:
                            continue
                        if any(_is_riding(box, vbox) for vbox in vehicle_boxes):
                            rider_track_ids.add(tid)

                # Catat "suara" kelas & posisi tiap track untuk keperluan
                # voting mayoritas & hitungan zona (lihat ZoneTracker.update).
                #
                # PENTING: titik yang diuji terhadap poligon zona sengaja pakai
                # tengah-BAWAH kotak (bottom-center: cx = tengah horizontal,
                # cy = y2/sisi bawah), BUKAN titik tengah geometris kotak.
                # Kamera CCTV di sini mengambil gambar dari sudut miring/
                # tinggi (bukan tegak lurus dari atas), jadi posisi sebenarnya
                # sebuah kendaraan di jalan diwakili oleh titik roda menyentuh
                # aspal (dekat sisi bawah kotak) -- bukan titik tengah
                # kotaknya.
                zone_dets = []
                for track_id, class_name, box in frame_boxes:
                    x1, y1, x2, y2 = box
                    cx, cy = (x1 + x2) / 2, y2
                    zone_dets.append((track_id, class_name, cx, cy))
                new_events = zone_tracker.update(frame_idx, zone_dets)
                safety_events_out.extend(new_events)

            all_frame_detections.append(frame_boxes)
            frame_idx += 1

            # Tahap 1 dianggap porsi 0-70% dari progress (internal, sebelum
            # progress_base/progress_scale diterapkan oleh _report) -- paling
            # berat karena inferensi YOLO ada di sini. Tahap 2 (gambar &
            # tulis video) jauh lebih ringan, diberi porsi 70-99%.
            if progress_interval and frame_idx % progress_interval == 0:
                pct = min(69, int(frame_idx / total_frames * 70)) if total_frames > 0 else 0
                _report(pct)

    total_frames_actual = len(all_frame_detections)

    # rider_track_ids sudah final sekarang (seluruh video sudah dibaca) --
    # koreksi hitungan zona supaya pengendara/penumpang tidak ikut ter-tally
    # sebagai "Orang" (lihat docstring _purge_riders_from_tally).
    _purge_riders_from_tally(zone_tracker, rider_track_ids)

    # Sekarang seluruh video sudah "dibaca suaranya" -- putuskan kelas final
    # tiap event bahaya (safety event) memakai voting mayoritas juga, bukan
    # cuma voting-sejauh-ini seperti kalau diputuskan di tengah proses.
    events_by_frame: dict = defaultdict(list)
    for ev in safety_events_out:
        ev["class_name"] = zone_tracker._majority_class(ev["track_id"], ev["class_name"])
        events_by_frame[ev["frame_idx"]].append(ev)

    # =====================================================================
    # TAHAP 2 -- Baca ulang video (tanpa inferensi YOLO lagi, jadi ringan)
    # dan gambar kotak/label/jejak/OSD memakai kelas FINAL (mayoritas) tiap
    # track, supaya label yang tampil di video konsisten dengan angka akhir
    # di "Total per Kategori".
    # =====================================================================
    cap2 = cv2.VideoCapture(video_path)
    writer = cv2.VideoWriter(output_path, cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))

    progress_interval_2 = max(1, total_frames_actual // 20) if total_frames_actual > 0 else 0

    frame_idx = 0
    while True:
        ret, frame = cap2.read()
        if not ret:
            break

        overlay = frame.copy()

        # --- Gambar zona (poligon) sebagai layer transparan ---
        for zone, zs in zip(zones, zones_schema):
            color = zone_colors[zone.name]
            pts = zone.polygon.astype(int).reshape((-1, 1, 2))
            if zone.type == "danger":
                cv2.fillPoly(overlay, [pts], color)
            cv2.polylines(frame, [pts], isClosed=True, color=color, thickness=2)
        frame = cv2.addWeighted(overlay, 0.18, frame, 0.82, 0)

        frame_boxes = all_frame_detections[frame_idx] if frame_idx < len(all_frame_detections) else []
        for track_id, raw_class_name, box in frame_boxes:
            class_name = zone_tracker._majority_class(track_id, raw_class_name)
            if class_name == "person" and track_id in rider_track_ids:
                continue  # pengendara/penumpang -- jangan dihitung sebagai "Orang"

            x1, y1, x2, y2 = box
            cx, cy = (x1 + x2) / 2, (y1 + y2) / 2

            # --- Jejak lintasan (trail) ---
            trails[track_id].append((int(cx), int(cy)))
            color = track_color(track_id)
            pts = list(trails[track_id])
            for i in range(1, len(pts)):
                cv2.line(frame, pts[i - 1], pts[i], color, 2)

            cv2.rectangle(frame, (int(x1), int(y1)), (int(x2), int(y2)), color, 2)
            cv2.putText(
                frame, f"{class_name}#{track_id}", (int(x1), max(0, int(y1) - 6)),
                cv2.FONT_HERSHEY_SIMPLEX, 0.5, color, 1,
            )

        # --- Overlay info lokasi & waktu (gaya OSD CCTV) ---
        timestamp = base_dt + timedelta(seconds=frame_idx / fps)
        cv2.rectangle(frame, (0, 0), (width, 28), (0, 0, 0), -1)
        cv2.putText(frame, f"{location_name[:60]}{label_suffix}", (8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (255, 255, 255), 1)
        ts_text = timestamp.strftime("%Y-%m-%d %H:%M:%S")
        (tw, _), _ = cv2.getTextSize(ts_text, cv2.FONT_HERSHEY_SIMPLEX, 0.55, 1)
        cv2.putText(frame, ts_text, (width - tw - 8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 255), 1)

        # Simpan snapshot bukti untuk safety event yang terjadi pada frame ini
        for ev in events_by_frame.get(frame_idx, []):
            snap_name = f"{file_prefix}_{ev['track_id']}_{_sanitize(ev['zone_name'])}_{frame_idx}.jpg"
            snap_path = os.path.join(snapshots_dir, snap_name)
            cv2.imwrite(snap_path, frame)
            ev["snapshot_path"] = f"{snapshot_url_prefix}{snap_name}"
            del ev["frame_idx"]

        writer.write(frame)
        frame_idx += 1

        if progress_interval_2 and frame_idx % progress_interval_2 == 0:
            pct = 70 + min(29, int(frame_idx / total_frames_actual * 29))
            _report(pct)

    cap2.release()
    writer.release()

    return zone_tracker.direction_rows(), safety_events_out, fps


def _run_detection(req: ProcessRequest):
    base_dt = _parse_recorded_at(req.recorded_at)
    base_dir = os.path.dirname(req.video_path)
    results_dir = os.path.join(base_dir, "results")
    snapshots_dir = os.path.join(results_dir, "snapshots")
    output_filename = f"{req.job_id}_annotated.mp4"
    output_path = os.path.join(results_dir, output_filename)

    direction_rows, safety_events_out, _fps = _detect_and_render(
        video_path=req.video_path,
        zones_schema=req.zones,
        classes=req.classes,
        danger_dwell_seconds=req.danger_dwell_seconds,
        location_name=req.location_name,
        base_dt=base_dt,
        output_path=output_path,
        snapshots_dir=snapshots_dir,
        snapshot_url_prefix="results/snapshots/",
        file_prefix=str(req.job_id),
        progress_cb=lambda pct: _send_progress(req, pct),
        progress_base=0,
        progress_scale=1.0,
    )

    _transcode_to_h264(output_path)

    return direction_rows, f"results/{output_filename}", safety_events_out


def _is_rtsp_url(url: str) -> bool:
    return url.lower().startswith(("rtsp://", "rtsps://"))


def _video_duration_seconds(path: str) -> float:
    """Perkiraan durasi video lewat metadata OpenCV -- cukup untuk keputusan
    "apakah rekaman ini layak dipakai/perlu disambung lagi" di
    _record_raw_stream, tidak perlu presisi frame-exact."""
    cap = cv2.VideoCapture(path)
    if not cap.isOpened():
        cap.release()
        return 0.0
    fps = cap.get(cv2.CAP_PROP_FPS) or 0
    frame_count = cap.get(cv2.CAP_PROP_FRAME_COUNT) or 0
    cap.release()
    if fps <= 0 or frame_count <= 0:
        return 0.0
    return frame_count / fps


def _run_ffmpeg_record(stream_url: str, seconds: float, out_path: str) -> bool:
    """Rekam stream_url selama `seconds` detik (mengikuti waktu nyata) ke
    out_path, di-encode ulang ke H.264/mp4 (BUKAN stream-copy) supaya file
    hasilnya selalu container/codec yang konsisten & bisa langsung dibaca
    ulang oleh cv2.VideoCapture di _detect_and_render, terlepas dari codec/
    container asli stream sumbernya (mis. MPEG-TS di dalam HLS). Video-only
    (`-an`, tanpa audio) karena aplikasi ini tidak memakai/menampilkan audio
    sama sekali.

    Beban re-encode ini (preset veryfast, tanpa YOLO berjalan bersamaan)
    jauh lebih ringan daripada inferensi YOLO real-time yang dulu jadi
    akar masalah "loncat-loncat" -- CPU 4 vCPU VPS ini semestinya tetap bisa
    mengejar real-time untuk sekadar merekam+re-encode video CCTV biasa.

    Mengembalikan True kalau ffmpeg keluar dengan exit code 0 (berarti
    berhasil merekam PENUH selama `seconds` yang diminta, baik karena stream
    lancar sepenuhnya ATAU opsi -reconnect di bawah berhasil menangani
    putus-nyambung SESAAT tanpa proses ffmpeg-nya sendiri pernah keluar).
    False berarti ffmpeg berhenti lebih awal/gagal total -- pemanggil
    (_record_raw_stream) yang memutuskan apakah & bagaimana mencoba sambung
    ulang dengan segmen baru."""
    os.makedirs(os.path.dirname(out_path), exist_ok=True)
    cmd = ["ffmpeg", "-y", "-loglevel", "error"]
    if _is_rtsp_url(stream_url):
        cmd += ["-rtsp_transport", "tcp"]
    else:
        cmd += ["-user_agent", BROWSER_USER_AGENT]
    # PENTING: SENGAJA TIDAK memakai opsi "-reconnect*" bawaan ffmpeg di
    # sini (sempat dicoba saat pengembangan, lalu dibatalkan setelah
    # diuji). Kombinasi -reconnect_streamed/-reconnect_at_eof ternyata bisa
    # membuat ffmpeg berulang kali menyambung-ulang sendiri ke URL yang
    # SAMA tanpa pernah benar-benar berhenti pada -t yang diminta --
    # teramati saat pengujian: proses ffmpeg terus mengirim request baru
    # selama puluhan detik walau setiap percobaan sukses penuh, karena
    # ffmpeg menganggap tiap akhir koneksi (bahkan yang normal) sebagai
    # sinyal "mungkin ada data lebih lanjut, coba sambung lagi" -- cocok
    # untuk protokol stream yang benar-benar tanpa akhir (mis. Icecast),
    # TAPI berbahaya untuk sumber HTTP progresif seperti ini: proses bisa
    # tersandera jauh melebihi durasi yang diminta, menghabiskan waktu
    # (termasuk jatah reconnect_budget di _record_raw_stream) tanpa
    # kontrol yang jelas. Resiliensi terhadap putus-sambung SUDAH
    # ditangani di lapisan yang kita kendalikan penuh (loop segmen+sambung
    # ulang di _record_raw_stream) -- lebih aman & bisa dibatasi tegas
    # daripada bergantung pada perilaku internal ffmpeg yang tidak selalu
    # bisa diprediksi untuk setiap jenis sumber CCTV.
    cmd += [
        "-i", stream_url,
        "-t", f"{seconds:.2f}",
        "-map", "0:v:0", "-an",
        "-c:v", "libx264", "-preset", "veryfast", "-pix_fmt", "yuv420p",
        out_path,
    ]
    # Batas waktu proses ffmpeg ini sendiri SENGAJA dijaga ketat (durasi
    # yang diminta + jatah overhead kecil yang wajar), BUKAN durasi+60
    # detik seperti percobaan awal -- supaya kalau ffmpeg entah kenapa
    # tersandera/menggantung (mis. gara-gara perilaku sumber yang tidak
    # terduga), kendali kembali secepatnya ke _record_raw_stream untuk
    # memutuskan langkah sambung-ulang, bukan diam menunggu lama padahal
    # jatah reconnect_budget yang sesungguhnya sudah terpakai habis oleh
    # SATU percobaan yang macet ini saja.
    timeout_seconds = seconds + 20
    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout_seconds)
        return result.returncode == 0
    except subprocess.TimeoutExpired:
        return False


def _record_raw_stream(stream_url: str, planned_seconds: float, base_dir: str, job_id: int, progress_cb=None) -> str:
    """Rekam stream CCTV live ke file lokal selama kira-kira `planned_seconds`
    detik, dengan toleransi sambung-ulang kalau stream putus di tengah jalan
    (lihat MAX_RECONNECT_BUDGET di bawah) -- baru dipanggil dari
    _run_live_detection, PENGGANTI pendekatan real-time lama (baca+YOLO+tulis
    dalam satu pass yang dipacing ke waktu nyata).

    KENAPA REKAM DULU (bukan proses sambil jalan seperti sebelumnya): video
    hasil deteksi mode unggah file (_run_detection) TIDAK PERNAH punya
    masalah "loncat-loncat", karena frame rate output-nya diambil LANGSUNG
    dari file sumber yang sudah lengkap & bisa dibaca ulang -- tidak ada
    proses real-time yang bisa telat/perlu duplikasi frame untuk mengejar
    waktu. Dengan merekam dulu, sesi live "menjadi" video biasa yang bisa
    diproses lewat pipeline yang sama persis (_detect_and_render) dan
    mewarisi jaminan sinkron yang sama -- alih-alih terus menerus menambal
    kalkulasi fps/pacing di jalur real-time yang secara inheren rapuh
    (kecepatan inferensi CPU tidak pernah benar-benar konstan).

    Mengembalikan path file mp4 hasil rekaman (video-only, H.264). Melempar
    RuntimeError kalau stream sama sekali tidak bisa direkam, atau kalau
    yang berhasil terekam jauh lebih pendek dari yang dijadwalkan (stream
    sumbernya bermasalah parah, bukan cuma putus-nyambung sesaat)."""
    os.makedirs(base_dir, exist_ok=True)

    # Total waktu (wall-clock) yang boleh dihabiskan untuk USAHA sambung
    # ulang (bukan untuk durasi sesi itu sendiri) -- supaya stream yang
    # benar-benar mati tidak membuat sesi menggantung jauh melewati
    # jadwal finish_at-nya. Sama seperti MAX_RECONNECT_SECONDS pada
    # pendekatan real-time sebelumnya.
    MAX_RECONNECT_BUDGET = timedelta(seconds=60.0)

    segments: list = []
    attempt = 0
    session_deadline = datetime.now(timezone.utc) + timedelta(seconds=planned_seconds)
    reconnect_budget_used = timedelta(0)

    while True:
        now = datetime.now(timezone.utc)
        remaining = (session_deadline - now).total_seconds()
        if remaining <= 0.5:
            break

        seg_path = os.path.join(base_dir, f"{job_id}_raw_seg{attempt}.mp4")
        attempt_started = time.monotonic()
        ok = _run_ffmpeg_record(stream_url, remaining, seg_path)
        attempt_elapsed = time.monotonic() - attempt_started

        seg_duration = _video_duration_seconds(seg_path) if os.path.exists(seg_path) else 0.0
        if seg_duration > 0.5:
            segments.append(seg_path)
        elif os.path.exists(seg_path):
            os.remove(seg_path)

        if ok:
            break

        # PENTING: jatah dihitung dari WAKTU NYATA yang benar-benar terpakai
        # oleh percobaan yang baru saja gagal ini (bisa sampai puluhan
        # detik kalau ffmpeg sempat tersandera sebelum akhirnya di-timeout
        # paksa -- lihat timeout_seconds di _run_ffmpeg_record), BUKAN
        # angka tetap. Kalau dipatok tetap (mis. selalu +1 detik per
        # percobaan gagal), SATU percobaan yang macet lama bisa diam-diam
        # menghabiskan waktu jauh lebih banyak daripada yang tercatat,
        # membuat sesi live berpotensi menggantung jauh melewati
        # finish_at-nya tanpa pernah kelihatan melanggar batas
        # MAX_RECONNECT_BUDGET yang seharusnya mencegah itu.
        reconnect_budget_used += timedelta(seconds=attempt_elapsed)
        if reconnect_budget_used >= MAX_RECONNECT_BUDGET:
            break
        attempt += 1
        time.sleep(1.0)
        time.sleep(1.0)

    if not segments:
        raise RuntimeError("Tidak dapat merekam stream CCTV sama sekali. Periksa kembali URL-nya.")

    raw_path = os.path.join(base_dir, f"{job_id}_raw.mp4")
    if len(segments) == 1:
        os.replace(segments[0], raw_path)
    else:
        # Stream sempat putus & disambung ulang lebih dari sekali -- gabung
        # semua segmen yang berhasil terekam jadi satu file utuh (urut
        # sesuai waktu perekaman) memakai concat demuxer ffmpeg (stream-copy,
        # cepat, karena semua segmen sudah H.264/mp4 seragam hasil
        # _run_ffmpeg_record).
        list_path = os.path.join(base_dir, f"{job_id}_raw_concat.txt")
        with open(list_path, "w") as f:
            for seg in segments:
                f.write(f"file '{os.path.abspath(seg)}'\n")
        concat_cmd = [
            "ffmpeg", "-y", "-loglevel", "error",
            "-f", "concat", "-safe", "0", "-i", list_path,
            "-c", "copy", raw_path,
        ]
        subprocess.run(concat_cmd, check=True, timeout=300)
        for seg in segments:
            os.remove(seg)
        os.remove(list_path)

    if progress_cb:
        progress_cb(50)

    total_recorded = _video_duration_seconds(raw_path)
    # Kalau yang berhasil terekam jauh lebih pendek dari yang dijadwalkan
    # (bukan cuma sedikit meleset karena overhead startup ffmpeg), stream
    # sumbernya memang bermasalah cukup parah -- laporkan sebagai gagal
    # (status "failed", bukan "completed" dengan hasil yang menyesatkan
    # seolah mewakili durasi penuh), sama seperti perilaku sebelum redesign
    # ini.
    #
    # PENTING: ambang ini murni RELATIF terhadap planned_seconds (BUKAN
    # ditambah batas mutlak semacam "minimal 10 detik") -- sesi live yang
    # DIJADWALKAN pendek (mis. beberapa detik saat admin sekadar menguji)
    # tapi berhasil terekam PENUH tetap harus lolos sebagai sukses. Batas
    # mutlak yang pernah dicoba di sini justru bikin sesi pendek yang
    # sebetulnya 100% berhasil terekam malah dianggap gagal.
    if total_recorded < planned_seconds * 0.5:
        raise RuntimeError(
            f"Koneksi ke stream CCTV terputus & gagal disambungkan ulang -- hanya "
            f"{total_recorded:.0f} dari {planned_seconds:.0f} detik sesi yang berhasil "
            f"terekam. Sesi dihentikan, silakan jadwalkan ulang."
        )

    return raw_path


def _run_live_detection(req: ProcessLiveRequest, finish_at: datetime):
    """Proses sesi live terjadwal (27 Agu 2026, redesign) dalam DUA langkah:

    1. _record_raw_stream() -- rekam stream CCTV apa adanya ke file lokal
       selama sisa waktu sampai `finish_at` (dengan toleransi sambung ulang
       kalau sempat putus).
    2. _detect_and_render() -- proses file rekaman itu memakai pipeline dua-
       tahap yang SAMA PERSIS dengan mode unggah file (_run_detection).

    Ini MENGGANTIKAN pendekatan sebelumnya (baca stream + YOLO + tulis video
    dalam SATU pass real-time, dipacing ke fps target hasil kalibrasi) --
    lihat komentar di dekat deklarasi TRACKER_CONFIG_PATH & catatan di
    docstring yang dulu ada di _open_stream_capture/_LiveStreamReader (sudah
    dipensiunkan) untuk riwayat lengkap masalah "loncat-loncat" yang coba
    ditangani berkali-kali oleh pendekatan lama itu.

    Konsekuensi yang disadari & diterima: hasil (video + hitungan) baru
    siap beberapa saat SETELAH waktu `finish_at` tercapai (durasi ekstra
    kira-kira = waktu YOLO memproses rekaman sepanjang sesi tsb), bukan
    lagi "mengalir" selama sesi berjalan. Ini dianggap sepadan karena UI
    (Dashboard/riwayat) memang baru menampilkan hasil sesi live setelah
    berstatus "completed" pada kedua desain -- tidak ada tayangan "nonton
    live" yang hilang, hanya jeda pemrosesan tambahan yang sebelumnya tidak
    ada (ditukar dengan video yang dijamin mulus & akurasi deteksi penuh,
    bukan lagi model/imgsz ringan khusus live).
    """
    base_dir = os.path.join("/data/videos", "live", str(req.job_id))
    results_dir = os.path.join(base_dir, "results")
    snapshots_dir = os.path.join(results_dir, "snapshots")
    os.makedirs(snapshots_dir, exist_ok=True)

    output_filename = f"{req.job_id}_annotated.mp4"
    output_path = os.path.join(results_dir, output_filename)

    now = datetime.now(timezone.utc)
    planned_seconds = max(1.0, (finish_at - now).total_seconds())

    raw_path = _record_raw_stream(
        stream_url=req.stream_url,
        planned_seconds=planned_seconds,
        base_dir=base_dir,
        job_id=req.job_id,
        progress_cb=lambda pct: _send_progress(req, pct),
    )

    base_dt = _parse_recorded_at(req.start_at) if req.start_at else now

    try:
        direction_rows, safety_events_out, _fps = _detect_and_render(
            video_path=raw_path,
            zones_schema=req.zones,
            classes=req.classes,
            danger_dwell_seconds=req.danger_dwell_seconds,
            location_name=req.location_name,
            base_dt=base_dt,
            output_path=output_path,
            snapshots_dir=snapshots_dir,
            snapshot_url_prefix=f"live/{req.job_id}/results/snapshots/",
            file_prefix=str(req.job_id),
            progress_cb=lambda pct: _send_progress(req, pct),
            progress_base=50,
            progress_scale=0.49,
            label_suffix=" (LIVE)",
        )
    finally:
        # Rekaman mentah cuma dibutuhkan selama pemrosesan -- hapus setelah
        # selesai (berhasil ataupun gagal) supaya tidak menumpuk memenuhi
        # disk VPS; video hasil akhirnya sudah ada di output_path.
        try:
            os.remove(raw_path)
        except OSError:
            pass

    _transcode_to_h264(output_path, duration_seconds=planned_seconds)

    return direction_rows, f"live/{req.job_id}/results/{output_filename}", safety_events_out


def _transcode_to_h264(
    path: str,
    input_fps: Optional[float] = None,
    duration_seconds: Optional[float] = None,
) -> None:
    """Re-encode video ke H.264 (codec "mp4v" hasil cv2.VideoWriter bisa
    ditulis, tapi TIDAK bisa diputar langsung di browser -- Chrome/Firefox/
    Safari hanya mendukung H.264/VP9/AV1 pada tag <video>). ffmpeg sudah
    ter-install di image ini (lihat Dockerfile), jadi transcode dilakukan di
    tempat, menimpa file mp4v dengan versi H.264 yang bisa diputar di browser.
    Kalau ffmpeg gagal/tidak ada, file mp4v asli tetap dibiarkan (annotated
    video tetap tersimpan, hanya tidak bisa diputar langsung di browser).

    input_fps (opsional): dipakai KHUSUS untuk sesi live (lihat pemanggilan
    di _run_live_detection) -- memaksa ffmpeg membaca ulang file mp4v
    seolah-olah frame rate aslinya adalah input_fps, BUKAN frame rate yang
    (salah) tertulis di metadata file mp4v hasil cv2.VideoWriter. PENTING:
    untuk sesi live, jumlah frame yang benar-benar berhasil ditulis per
    detik NYATA bisa jauh lebih rendah daripada frame rate yang diasumsikan
    saat VideoWriter dibuat di awal (mis. diasumsikan 15 fps, padahal
    kecepatan nyata membaca+memproses stream cuma ~3 fps karena inferensi
    YOLO & jeda jaringan) -- tanpa koreksi ini, video hasil akhir jadi
    "dipercepat" jauh dari kenyataan (mis. sesi 10 menit jadi video 2
    menit), padahal jumlah frame yang tersimpan sebenarnya representatif
    untuk durasi sesungguhnya. Untuk mode unggah file (bukan live), tidak
    perlu koreksi ini karena frame rate yang dipakai VideoWriter sudah
    diambil langsung dari file sumber yang akurat.

    duration_seconds (opsional): perkiraan durasi NYATA video yang akan
    di-transcode (dipakai KHUSUS sesi live, lihat pemanggilan di
    _run_live_detection) -- dipakai untuk memperlebar batas waktu (timeout)
    ffmpeg di bawah. PENTING: sejak fps output sesi live dipatok tetap ke
    15fps (lihat _run_live_detection), sesi live yang PANJANG (mis. lebih
    dari ~1 jam) menghasilkan file mp4v mentah dengan JAUH lebih banyak
    frame untuk di-encode ulang ke H.264 dibanding sebelumnya. Kalau
    encoding-nya lebih lambat dari batas waktu TETAP 600 detik yang lama,
    subprocess.run() di bawah akan MELEMPAR TimeoutExpired -- itu tertangkap
    oleh except Exception di bawah dan HANYA dicatat ke log server (lihat
    traceback.print_exc()), TIDAK pernah sampai ke pemanggilnya. Akibatnya
    file mp4v MENTAH (yang gagal di-encode ke H.264) tetap dibiarkan apa
    adanya di annotated_path, sesi tetap dilaporkan "completed" lengkap
    dengan hasil hitungan zona (yang memang dihitung terpisah, tidak
    bergantung pada video), TAPI videonya sendiri tidak bisa diputar sama
    sekali di browser (thumbnail hitam, durasi 0:00) karena masih berformat
    mp4v mentah, bukan H.264 -- inilah penyebab video kosong padahal hasil
    hitungan tetap muncul. Perbaikannya dua bagian: (1) tambahkan
    "-preset veryfast" di bawah supaya ffmpeg meng-encode jauh lebih cepat
    (trade-off ukuran file sedikit lebih besar, wajar untuk kebutuhan ini),
    dan (2) lebarkan batas waktunya mengikuti durasi videonya sendiri, bukan
    angka tetap 600 detik yang bisa saja tidak cukup untuk sesi live yang
    sangat panjang.
    """
    tmp_path = f"{path}.h264.tmp.mp4"
    # Batas waktu ffmpeg: minimal 600 detik (10 menit, cukup untuk video
    # unggahan biasa & sesi live pendek/menengah seperti sebelumnya), tapi
    # kalau durasi videonya sendiri lebih panjang dari itu (sesi live lama),
    # beri waktu dua kali lipat durasi videonya supaya encoding "-preset
    # veryfast" (yang jauh lebih cepat dari real-time bahkan di CPU biasa)
    # tetap punya ruang gerak yang wajar, bukan keburu dianggap gagal.
    timeout_seconds = 600
    if duration_seconds and duration_seconds > 0:
        timeout_seconds = max(timeout_seconds, int(duration_seconds * 2))
    try:
        cmd = ["ffmpeg", "-y", "-loglevel", "error"]
        if input_fps and input_fps > 0:
            cmd += ["-r", f"{input_fps:.3f}"]
        cmd += [
            "-i", path,
            "-c:v", "libx264", "-preset", "veryfast",
            "-pix_fmt", "yuv420p", "-movflags", "+faststart",
            tmp_path,
        ]
        subprocess.run(cmd, check=True, timeout=timeout_seconds)
        os.replace(tmp_path, path)
    except Exception:
        traceback.print_exc()
        if os.path.exists(tmp_path):
            os.remove(tmp_path)


def _send_callback(req: ProcessRequest, payload: dict):
    headers = {"X-Callback-Secret": req.callback_secret or CALLBACK_SECRET}
    try:
        requests.post(req.callback_url, json=payload, headers=headers, timeout=15)
    except requests.RequestException:
        traceback.print_exc()


def _send_progress(req: ProcessRequest, pct: int):
    if not req.progress_url:
        return
    headers = {"X-Callback-Secret": req.callback_secret or CALLBACK_SECRET}
    try:
        requests.post(req.progress_url, json={"progress": pct}, headers=headers, timeout=5)
    except requests.RequestException:
        # Progress cuma kosmetik -- kalau gagal terkirim, biarkan saja, jangan
        # sampai mengganggu/menghentikan proses deteksi video yang sebenarnya.
        pass
