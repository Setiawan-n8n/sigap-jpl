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


def _open_stream_capture(url: str):
    """Buka cv2.VideoCapture ke stream live (HLS/RTSP/dll) dengan header
    User-Agent browser terpasang.

    PENTING: sebelumnya, pembacaan stream live di _run_live_detection
    memakai cv2.VideoCapture(url) POLOS tanpa User-Agent sama sekali --
    beda dengan _capture_snapshot() di bawah yang sudah lebih dulu memakai
    ffmpeg dengan User-Agent Chrome, justru karena proxy CCTV instansi
    (mis. ATCS pemda) diketahui menolak/membatasi koneksi tanpa User-Agent
    browser (lihat komentar di _capture_snapshot). Ini kemungkinan besar
    penyebab pembacaan stream live sering gagal/gagal terus-menerus di
    tengah sesi (perlu sambung ulang berkali-kali) walau kamera & jaringan
    baik-baik saja: proxy-nya menganggap koneksi dari OpenCV/FFmpeg polos
    sebagai bot dan memblokir/membatasinya. OPENCV_FFMPEG_CAPTURE_OPTIONS
    adalah cara OpenCV (backend FFmpeg) menerima opsi tambahan seperti
    User-Agent -- dibaca ulang setiap kali VideoCapture dibuka, jadi aman
    dipakai di sini maupun saat sambung ulang.
    """
    os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = f"user_agent;{BROWSER_USER_AGENT}"
    return cv2.VideoCapture(url)


app = FastAPI(title="SIGAP-JPL Detector Service")

print(f"Loading YOLO model: {MODEL_NAME}")
model = YOLO(MODEL_NAME)


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


def _run_detection(req: ProcessRequest):
    cap = cv2.VideoCapture(req.video_path)
    if not cap.isOpened():
        raise RuntimeError("Tidak dapat membuka file video.")

    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH))
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT))
    fps = cap.get(cv2.CAP_PROP_FPS) or 25
    # CAP_PROP_FRAME_COUNT bisa tidak akurat untuk beberapa codec/VFR, tapi
    # cukup baik untuk estimasi progress kasar. Kalau tidak tersedia (<= 0),
    # progress reporting berbasis persentase cukup dilewati saja.
    total_frames = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    cap.release()

    # Kirim update progress kira-kira tiap 5% supaya tidak membanjiri Laravel
    # dengan request tapi UI tetap terasa "hidup" selama proses berjalan.
    progress_interval = max(1, total_frames // 20) if total_frames > 0 else 0
    last_progress_sent = -1

    zones = []
    for z in req.zones:
        zones.append(Zone(name=z.name, type=z.type, polygon=build_polygon(z.points, width, height)))
    zone_colors = {z.name: hex_to_bgr(zs.color) for z, zs in zip(zones, req.zones)}

    zone_tracker = ZoneTracker(zones=zones, fps=fps, danger_dwell_seconds=req.danger_dwell_seconds)

    wanted_ids = [cid for cid, name in TARGET_CLASSES.items() if name in req.classes]

    base_dir = os.path.dirname(req.video_path)
    results_dir = os.path.join(base_dir, "results")
    snapshots_dir = os.path.join(results_dir, "snapshots")
    os.makedirs(snapshots_dir, exist_ok=True)

    output_filename = f"{req.job_id}_annotated.mp4"
    output_path = os.path.join(results_dir, output_filename)

    base_dt = _parse_recorded_at(req.recorded_at)
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
    # Kalau video langsung digambar & ditulis sambil jalan (satu-pass, seperti
    # sebelumnya), label yang tampil di video ikut kedip mengikuti kesalahan
    # itu. Makanya deteksi dipisah jadi dua tahap: tahap ini HANYA mengoleksi
    # semua deteksi mentah per frame (tanpa menggambar apa pun), supaya
    # ZoneTracker sempat mengumpulkan "suara" tiap track dari SELURUH video
    # dan tahu kelas final (mayoritas) tiap track sebelum video digambar.
    # =====================================================================
    results = model.track(
        source=req.video_path,
        classes=wanted_ids,
        conf=CONF_THRESHOLD,
        tracker="bytetrack.yaml",
        persist=True,
        stream=True,
        verbose=False,
    )

    all_frame_detections: list = []  # index = frame_idx -> list[(track_id, class_name, box)]

    frame_idx = 0
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
            # cy = y2/sisi bawah), BUKAN titik tengah geometris kotak
            # (dulu (y1+y2)/2). Kamera CCTV di sini mengambil gambar dari
            # sudut miring/tinggi (bukan tegak lurus dari atas), jadi posisi
            # sebenarnya sebuah kendaraan di jalan diwakili oleh titik roda
            # menyentuh aspal (dekat sisi bawah kotak) -- bukan titik tengah
            # kotaknya. Untuk kendaraan tinggi (bus/truk) atau kotak yang
            # memanjang ke atas (mis. mencakup kepala pengendara motor),
            # titik tengah kotak bisa "kepeleset" masuk ke garis zona
            # padahal posisi kendaraan yang sebenarnya (di jalan) masih di
            # luar garis itu -- inilah penyebab kendaraan yang terlihat di
            # luar area pantauan tetap ikut ter-tally.
            zone_dets = []
            for track_id, class_name, box in frame_boxes:
                x1, y1, x2, y2 = box
                cx, cy = (x1 + x2) / 2, y2
                zone_dets.append((track_id, class_name, cx, cy))
            new_events = zone_tracker.update(frame_idx, zone_dets)
            safety_events_out.extend(new_events)

        all_frame_detections.append(frame_boxes)
        frame_idx += 1

        # Tahap 1 dianggap porsi 0-70% dari progress total (paling berat,
        # karena inferensi YOLO ada di sini). Tahap 2 (gambar & tulis video)
        # jauh lebih ringan (tanpa inferensi model), diberi porsi 70-99%.
        if progress_interval and frame_idx % progress_interval == 0:
            pct = min(69, int(frame_idx / total_frames * 70)) if total_frames > 0 else 0
            if pct != last_progress_sent:
                _send_progress(req, pct)
                last_progress_sent = pct

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
    cap2 = cv2.VideoCapture(req.video_path)
    writer = cv2.VideoWriter(output_path, cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))

    progress_interval_2 = max(1, total_frames_actual // 20) if total_frames_actual > 0 else 0

    frame_idx = 0
    while True:
        ret, frame = cap2.read()
        if not ret:
            break

        overlay = frame.copy()

        # --- Gambar zona (poligon) sebagai layer transparan ---
        for zone, zs in zip(zones, req.zones):
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
        cv2.putText(frame, req.location_name[:60], (8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (255, 255, 255), 1)
        ts_text = timestamp.strftime("%Y-%m-%d %H:%M:%S")
        (tw, _), _ = cv2.getTextSize(ts_text, cv2.FONT_HERSHEY_SIMPLEX, 0.55, 1)
        cv2.putText(frame, ts_text, (width - tw - 8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 255), 1)

        # Simpan snapshot bukti untuk safety event yang terjadi pada frame ini
        for ev in events_by_frame.get(frame_idx, []):
            snap_name = f"{req.job_id}_{ev['track_id']}_{_sanitize(ev['zone_name'])}_{frame_idx}.jpg"
            snap_path = os.path.join(snapshots_dir, snap_name)
            cv2.imwrite(snap_path, frame)
            ev["snapshot_path"] = f"results/snapshots/{snap_name}"
            del ev["frame_idx"]

        writer.write(frame)
        frame_idx += 1

        if progress_interval_2 and frame_idx % progress_interval_2 == 0:
            pct = 70 + min(29, int(frame_idx / total_frames_actual * 29))
            if pct != last_progress_sent:
                _send_progress(req, pct)
                last_progress_sent = pct

    cap2.release()
    writer.release()

    _transcode_to_h264(output_path)

    return zone_tracker.direction_rows(), f"results/{output_filename}", safety_events_out


def _run_live_detection(req: ProcessLiveRequest, finish_at: datetime):
    """Proses stream CCTV LANGSUNG lewat YOLOv8 + ByteTrack selama rentang
    waktu terjadwal, tanpa merekam ke file dulu (beda dengan _run_detection
    yang bekerja dua tahap atas file lokal yang bisa dibaca ulang).

    Karena sumbernya stream live yang TIDAK bisa dibaca ulang/di-seek, di
    sini deteksi & penggambaran dilakukan dalam SATU pass: setiap frame
    langsung digambar begitu diproses, memakai kelas "mayoritas sejauh ini"
    untuk track tsb (ZoneTracker._majority_class, sama seperti mode
    unggah file, hanya saja votingnya baru final di akhir stream, bukan di
    akhir seluruh video sejak awal). Konsekuensinya: label sebuah track di
    detik-detik awal kemunculannya bisa saja masih berubah sesaat sebelum
    stabil -- ini trade-off yang tidak terhindarkan untuk pemrosesan
    real-time (tidak seperti file, di sini "masa depan" video belum ada saat
    frame sekarang digambar).
    """
    base_dir = os.path.join("/data/videos", "live", str(req.job_id))
    results_dir = os.path.join(base_dir, "results")
    snapshots_dir = os.path.join(results_dir, "snapshots")
    os.makedirs(snapshots_dir, exist_ok=True)

    output_filename = f"{req.job_id}_annotated.mp4"
    output_path = os.path.join(results_dir, output_filename)

    base_dt = _parse_recorded_at(req.start_at)
    wanted_ids = [cid for cid, name in TARGET_CLASSES.items() if name in req.classes]

    cap = _open_stream_capture(req.stream_url)
    if not cap.isOpened():
        raise RuntimeError("Tidak dapat membuka stream CCTV. Periksa kembali URL-nya.")

    width = int(cap.get(cv2.CAP_PROP_FRAME_WIDTH)) or 1280
    height = int(cap.get(cv2.CAP_PROP_FRAME_HEIGHT)) or 720
    # PENTING: SENGAJA tidak lagi memakai cap.get(cv2.CAP_PROP_FPS) sama
    # sekali untuk sesi live. Banyak stream live (HLS/RTSP relay, termasuk
    # proxy ATCS) melaporkan angka FPS yang "valid" secara teknis (lolos
    # pengecekan wajar) tapi jauh lebih rendah dari kenyataan (mis. 5-8
    # fps) -- padahal angka inilah yang menentukan frame rate video hasil
    # akhir (lihat pacing di bawah). Video yang dideklarasikan di frame
    # rate serendah itu terlihat "patah-patah"/lambat ke mata manusia,
    # BUKAN karena durasinya salah (itu sudah diperbaiki lewat pacing di
    # bawah), tapi karena frame rate videonya sendiri terlalu rendah untuk
    # terlihat mulus dibanding tayangan CCTV aslinya. Karena penulisan
    # video sekarang sudah dipacing mengikuti waktu nyata (bukan lagi
    # bergantung pada frame rate asli sumbernya untuk akurasi durasi),
    # aman & lebih baik untuk selalu pakai angka frame rate output yang
    # wajar & konsisten, terlepas dari apa pun yang dilaporkan stream-nya.
    fps = 15.0

    zones = []
    for z in req.zones:
        zones.append(Zone(name=z.name, type=z.type, polygon=build_polygon(z.points, width, height)))
    zone_colors = {z.name: hex_to_bgr(zs.color) for z, zs in zip(zones, req.zones)}

    zone_tracker = ZoneTracker(zones=zones, fps=fps, danger_dwell_seconds=req.danger_dwell_seconds)

    writer = cv2.VideoWriter(output_path, cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))

    trails: dict = defaultdict(lambda: deque(maxlen=TRAIL_LENGTH))
    rider_track_ids: set = set()
    safety_events_out = []

    # PENTING: finish_at yang diterima di sini SUDAH timezone-aware (lihat
    # normalisasi di endpoint /process-live). started_at & "now" di bawah
    # HARUS ikut dibuat timezone-aware juga (bukan datetime.now() biasa),
    # supaya perbandingan/pengurangan dengan finish_at tidak melempar
    # TypeError "can't compare offset-naive and offset-aware datetimes".
    started_at = datetime.now(timezone.utc)
    planned_seconds = max(1.0, (finish_at - started_at).total_seconds())
    last_progress_sent = -1
    frame_idx = 0
    # PENTING: penghitung frame yang SUDAH ditulis ke file video output --
    # SENGAJA dipisah dari frame_idx (lihat pemakaiannya di titik penulisan
    # `writer.write` di bawah). frame_idx menghitung berapa kali deteksi/
    # YOLO berhasil dijalankan (dipakai ZoneTracker & penandaan safety
    # event); output_frames_written menghitung berapa frame yang SUDAH
    # benar-benar ditulis ke video, DIPAKSA mengikuti waktu NYATA yang
    # berlalu supaya video akhir tidak "slow motion".
    output_frames_written = 0
    consecutive_failures = 0
    # PENTING: sebelumnya, begitu pembacaan gagal terus-menerus selama
    # ~20 detik, kode langsung MENYERAH (break) dan melaporkan sesi sebagai
    # "completed" seolah berjalan normal -- padahal videonya jadi terpotong
    # jauh lebih pendek dari rentang waktu yang dijadwalkan (mis. dijadwalkan
    # 10 menit, tapi cuma dapat ~10 detik rekaman). Penyebabnya: stream live
    # (HLS/.m3u8) punya "jendela" segmen yang terus bergeser seiring waktu;
    # cv2.VideoCapture yang dibuka SEKALI di awal tidak selalu ikut
    # menyegarkan playlist-nya, jadi pembacaan bisa mulai gagal terus di
    # tengah sesi walau kamera & jaringannya baik-baik saja. Perbaikannya:
    # coba SAMBUNG ULANG (buka ulang koneksi ke stream_url, yang otomatis
    # mengambil playlist live terbaru) begitu pembacaan mulai gagal
    # berturut-turut, bukan langsung menyerah. Baru benar-benar berhenti
    # kalau sambung ulang berkali-kali TETAP gagal selama lebih dari
    # MAX_RECONNECT_SECONDS -- dan itu pun dilaporkan sebagai GAGAL (lihat
    # pengecekan setelah loop di bawah), bukan "completed" diam-diam.
    reconnect_after_failures = max(1, int(fps * 5))  # ~5 detik gagal berturut -> coba sambung ulang
    reconnect_deadline = None  # None = belum dalam mode "sedang mencoba sambung ulang"
    MAX_RECONNECT_SECONDS = 60.0

    try:
        while True:
            now = datetime.now(timezone.utc)
            if now >= finish_at:
                break

            ok, frame = cap.read()
            if not ok or frame is None:
                consecutive_failures += 1

                if consecutive_failures >= reconnect_after_failures:
                    if reconnect_deadline is None:
                        reconnect_deadline = now + timedelta(seconds=MAX_RECONNECT_SECONDS)
                        print(
                            f"[live:{req.job_id}] Pembacaan stream gagal berturut-turut, "
                            f"mencoba sambung ulang...",
                            flush=True,
                        )
                    elif now >= reconnect_deadline:
                        # Sudah mencoba sambung ulang berulang kali selama lebih dari
                        # MAX_RECONNECT_SECONDS tanpa hasil -- anggap stream benar-benar
                        # tidak terjangkau, jangan coba lagi.
                        break

                    cap.release()
                    cap = _open_stream_capture(req.stream_url)
                    consecutive_failures = 0
                    time.sleep(1.0)
                    continue

                # Beri jeda singkat lalu coba lagi -- stream live kadang
                # sesaat tersendat (buffering jaringan), bukan berarti putus.
                time.sleep(0.2)
                continue
            consecutive_failures = 0
            reconnect_deadline = None

            # Deteksi & tracking satu frame. persist=True menjaga ID track
            # tetap konsisten antar pemanggilan berturut-turut (sama seperti
            # stream=True pada mode unggah file, tapi di sini dipanggil
            # manual per-frame karena sumbernya bukan path file).
            results = model.track(
                source=frame,
                classes=wanted_ids,
                conf=CONF_THRESHOLD,
                tracker="bytetrack.yaml",
                persist=True,
                verbose=False,
            )
            result = results[0]

            frame_boxes = []
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

                vehicle_boxes = [b for _, cn, b in frame_boxes if cn in RIDER_HOST_CLASSES]
                if vehicle_boxes:
                    for tid, cn, box in frame_boxes:
                        if cn != "person" or tid in rider_track_ids:
                            continue
                        if any(_is_riding(box, vbox) for vbox in vehicle_boxes):
                            rider_track_ids.add(tid)

                # Sama seperti di _run_detection: pakai titik tengah-BAWAH
                # kotak (bottom-center) untuk uji zona, bukan titik tengah
                # geometris kotak -- lihat penjelasan lengkap di sana.
                zone_dets = []
                for track_id, class_name, box in frame_boxes:
                    x1, y1, x2, y2 = box
                    cx, cy = (x1 + x2) / 2, y2
                    zone_dets.append((track_id, class_name, cx, cy))
                new_events = zone_tracker.update(frame_idx, zone_dets)
                for ev in new_events:
                    safety_events_out.append(ev)

            overlay = frame.copy()
            for zone, zs in zip(zones, req.zones):
                color = zone_colors[zone.name]
                pts = zone.polygon.astype(int).reshape((-1, 1, 2))
                if zone.type == "danger":
                    cv2.fillPoly(overlay, [pts], color)
                cv2.polylines(frame, [pts], isClosed=True, color=color, thickness=2)
            frame = cv2.addWeighted(overlay, 0.18, frame, 0.82, 0)

            for track_id, raw_class_name, box in frame_boxes:
                class_name = zone_tracker._majority_class(track_id, raw_class_name)
                if class_name == "person" and track_id in rider_track_ids:
                    continue

                x1, y1, x2, y2 = box
                cx, cy = (x1 + x2) / 2, (y1 + y2) / 2

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

            timestamp = base_dt + timedelta(seconds=(now - started_at).total_seconds())
            cv2.rectangle(frame, (0, 0), (width, 28), (0, 0, 0), -1)
            cv2.putText(frame, f"{req.location_name[:50]} (LIVE)", (8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (255, 255, 255), 1)
            ts_text = timestamp.strftime("%Y-%m-%d %H:%M:%S")
            (tw, _), _ = cv2.getTextSize(ts_text, cv2.FONT_HERSHEY_SIMPLEX, 0.55, 1)
            cv2.putText(frame, ts_text, (width - tw - 8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 255), 1)

            for ev in [e for e in safety_events_out if e.get("frame_idx") == frame_idx and "snapshot_path" not in e]:
                ev["class_name"] = zone_tracker._majority_class(ev["track_id"], ev["class_name"])
                snap_name = f"{req.job_id}_{ev['track_id']}_{_sanitize(ev['zone_name'])}_{frame_idx}.jpg"
                snap_path = os.path.join(snapshots_dir, snap_name)
                cv2.imwrite(snap_path, frame)
                ev["snapshot_path"] = f"live/{req.job_id}/results/snapshots/{snap_name}"

            # PENTING: sebelumnya, satu frame yang berhasil diproses = satu
            # frame ditulis ke video, TANPA memperhitungkan berapa lama
            # waktu NYATA yang benar-benar berlalu untuk memproses frame
            # itu (mis. karena inferensi YOLO/jaringan naik-turun
            # kecepatannya). Video akhir sempat "diperbaiki" durasinya
            # dengan melabeli ulang SATU frame rate rata-rata untuk
            # seluruh video (lihat riwayat perbaikan sebelumnya) -- itu
            # membuat TOTAL durasi cocok, tapi gerakan di dalam videonya
            # jadi terlihat "slow motion" (frame rate rata-rata itu
            # dipaksakan rata ke seluruh video, padahal kecepatan
            # pemrosesan nyata naik-turun sepanjang sesi). Perbaikan yang
            # benar: TULIS frame mengikuti waktu NYATA yang berlalu, bukan
            # sekadar sekali per frame yang berhasil diproses -- kalau
            # pemrosesan frame ini memakan waktu lebih dari 1/fps detik,
            # ulangi (duplikasi) frame yang sama untuk mengisi "jeda" itu
            # sampai video mengejar waktu nyata, persis seperti cara kerja
            # perekam CCTV biasa saat sesaat tersendat (menahan frame
            # terakhir, bukan mempercepat/memperlambat seluruh rekaman).
            now_after_processing = datetime.now(timezone.utc)
            elapsed = (now_after_processing - started_at).total_seconds()
            target_output_frames = int(elapsed * fps)
            while output_frames_written < target_output_frames:
                writer.write(frame)
                output_frames_written += 1
            frame_idx += 1

            pct = min(99, int(elapsed / planned_seconds * 100))
            if pct != last_progress_sent and pct % 2 == 0:
                _send_progress(req, pct)
                last_progress_sent = pct

        if output_frames_written == 0 and frame_idx > 0:
            # Jaga-jaga: kalau sesi sangat singkat sehingga belum sempat
            # menulis satu frame pun ke output lewat pacing di atas, tetap
            # tulis minimal frame terakhir yang berhasil diproses supaya
            # file video tidak kosong/rusak.
            writer.write(frame)
            output_frames_written += 1
    finally:
        cap.release()
        writer.release()

    if frame_idx == 0:
        raise RuntimeError("Tidak ada frame yang berhasil dibaca dari stream CCTV selama sesi ini.")

    if datetime.now(timezone.utc) < finish_at:
        # Loop berhenti BUKAN karena waktu terjadwal sudah habis, tapi karena
        # sambung ulang stream (lihat di atas) tetap gagal setelah dicoba
        # berkali-kali -- jangan laporkan sebagai "completed" seolah sesi
        # berjalan penuh sampai waktu selesai, padahal terpotong di tengah
        # jalan. Admin perlu tahu ini supaya bisa menjadwalkan ulang, bukan
        # mengira hasil yang terpotong itu representasi 10 menit penuh.
        raise RuntimeError(
            f"Koneksi ke stream CCTV terputus & gagal disambungkan ulang setelah "
            f"{frame_idx} frame berhasil diproses (~{frame_idx / fps:.0f} detik rekaman). "
            f"Sesi dihentikan lebih awal, tidak sampai waktu selesai yang dijadwalkan."
        )

    # rider_track_ids sudah final sekarang (sesi live sudah selesai) --
    # koreksi hitungan zona supaya pengendara/penumpang tidak ikut ter-tally
    # sebagai "Orang" (lihat docstring _purge_riders_from_tally).
    _purge_riders_from_tally(zone_tracker, rider_track_ids)

    for ev in safety_events_out:
        ev.pop("frame_idx", None)

    # PENTING: video di atas SUDAH ditulis dengan pacing waktu-nyata (lihat
    # penjelasan lengkap di titik `writer.write` di dalam loop) -- frame
    # rate yang dipakai VideoWriter (variabel `fps`) sudah konsisten dipakai
    # untuk menentukan KAPAN setiap frame ditulis, jadi durasi & kecepatan
    # video akhir SUDAH otomatis cocok dengan waktu nyata TANPA perlu
    # dikoreksi ulang di sini (percobaan sebelumnya melabeli ulang dengan
    # satu frame rate rata-rata malah membuat videonya terlihat "slow
    # motion" -- lihat riwayat perbaikan).
    _transcode_to_h264(output_path)

    return zone_tracker.direction_rows(), f"live/{req.job_id}/results/{output_filename}", safety_events_out


def _transcode_to_h264(path: str, input_fps: Optional[float] = None) -> None:
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
    """
    tmp_path = f"{path}.h264.tmp.mp4"
    try:
        cmd = ["ffmpeg", "-y", "-loglevel", "error"]
        if input_fps and input_fps > 0:
            cmd += ["-r", f"{input_fps:.3f}"]
        cmd += [
            "-i", path,
            "-c:v", "libx264", "-pix_fmt", "yuv420p", "-movflags", "+faststart",
            tmp_path,
        ]
        subprocess.run(cmd, check=True, timeout=600)
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
