import os
import re
import subprocess
import threading
import traceback
from collections import defaultdict, deque
from datetime import datetime, timedelta
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
    RIDER_OVERLAP_THRESHOLD,
    TARGET_CLASSES,
    TRAIL_LENGTH,
)
from .tracker import Zone, ZoneTracker, build_polygon, hex_to_bgr, track_color

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


def _sanitize(name: str) -> str:
    return re.sub(r"[^A-Za-z0-9]+", "_", name).strip("_").lower() or "zona"


def _overlap_ratio(box_a, box_b) -> float:
    """Rasio luas irisan terhadap luas box_a (0..1). Dipakai untuk menebak
    apakah sebuah deteksi "person" kemungkinan besar pengendara/penumpang
    kendaraan (box orang-nya tumpang tindih signifikan dengan box kendaraan),
    bukan pejalan kaki lepas."""
    ax1, ay1, ax2, ay2 = box_a
    bx1, by1, bx2, by2 = box_b
    ix1, iy1 = max(ax1, bx1), max(ay1, by1)
    ix2, iy2 = min(ax2, bx2), min(ay2, by2)
    iw, ih = max(0.0, ix2 - ix1), max(0.0, iy2 - iy1)
    inter = iw * ih
    area_a = max(1e-6, (ax2 - ax1) * (ay2 - ay1))
    return inter / area_a


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
    writer = cv2.VideoWriter(output_path, cv2.VideoWriter_fourcc(*"mp4v"), fps, (width, height))

    base_dt = _parse_recorded_at(req.recorded_at)
    trails: dict = defaultdict(lambda: deque(maxlen=TRAIL_LENGTH))
    safety_events_out = []
    # Track ID "person" yang pernah terdeteksi tumpang tindih signifikan
    # dengan kendaraan -- sekali ditandai sebagai pengendara/penumpang, tetap
    # dikecualikan dari hitungan "Orang" seterusnya (supaya tidak
    # berubah-ubah antar frame hanya karena sudut kamera/oklusi sesaat).
    rider_track_ids: set = set()

    results = model.track(
        source=req.video_path,
        classes=wanted_ids,
        conf=CONF_THRESHOLD,
        tracker="bytetrack.yaml",
        persist=True,
        stream=True,
        verbose=False,
    )

    frame_idx = 0
    for result in results:
        frame = result.orig_img.copy()
        overlay = frame.copy()

        # --- Gambar zona (poligon) sebagai layer transparan ---
        for zone, zs in zip(zones, req.zones):
            color = zone_colors[zone.name]
            pts = zone.polygon.astype(int).reshape((-1, 1, 2))
            if zone.type == "danger":
                cv2.fillPoly(overlay, [pts], color)
            cv2.polylines(frame, [pts], isClosed=True, color=color, thickness=2)
        frame = cv2.addWeighted(overlay, 0.18, frame, 0.82, 0)

        detections = []
        boxes = result.boxes
        if boxes is not None and boxes.id is not None:
            xyxy = boxes.xyxy.cpu().numpy()
            ids = boxes.id.cpu().numpy()
            cls_ids = boxes.cls.cpu().numpy()

            frame_boxes = []  # (track_id, class_name, box)
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
                    if any(_overlap_ratio(box, vbox) >= RIDER_OVERLAP_THRESHOLD for vbox in vehicle_boxes):
                        rider_track_ids.add(tid)

            for track_id, class_name, box in frame_boxes:
                if class_name == "person" and track_id in rider_track_ids:
                    continue  # pengendara/penumpang -- jangan dihitung sebagai "Orang"

                x1, y1, x2, y2 = box
                cx, cy = (x1 + x2) / 2, (y1 + y2) / 2
                detections.append((track_id, class_name, cx, cy))

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

        new_events = zone_tracker.update(frame_idx, detections)

        # --- Overlay info lokasi & waktu (gaya OSD CCTV) ---
        timestamp = base_dt + timedelta(seconds=frame_idx / fps)
        cv2.rectangle(frame, (0, 0), (width, 28), (0, 0, 0), -1)
        cv2.putText(frame, req.location_name[:60], (8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (255, 255, 255), 1)
        ts_text = timestamp.strftime("%Y-%m-%d %H:%M:%S")
        (tw, _), _ = cv2.getTextSize(ts_text, cv2.FONT_HERSHEY_SIMPLEX, 0.55, 1)
        cv2.putText(frame, ts_text, (width - tw - 8, 20), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 255, 255), 1)

        # Simpan snapshot bukti untuk safety event baru pada frame ini
        for ev in new_events:
            snap_name = f"{req.job_id}_{ev['track_id']}_{_sanitize(ev['zone_name'])}_{frame_idx}.jpg"
            snap_path = os.path.join(snapshots_dir, snap_name)
            cv2.imwrite(snap_path, frame)
            ev["snapshot_path"] = f"results/snapshots/{snap_name}"
            del ev["frame_idx"]
            safety_events_out.append(ev)

        writer.write(frame)
        frame_idx += 1

        if progress_interval and frame_idx % progress_interval == 0:
            pct = min(99, int(frame_idx / total_frames * 100))
            if pct != last_progress_sent:
                _send_progress(req, pct)
                last_progress_sent = pct

    writer.release()

    _transcode_to_h264(output_path)

    return zone_tracker.direction_rows(), f"results/{output_filename}", safety_events_out


def _transcode_to_h264(path: str) -> None:
    """Re-encode video ke H.264 (codec "mp4v" hasil cv2.VideoWriter bisa
    ditulis, tapi TIDAK bisa diputar langsung di browser -- Chrome/Firefox/
    Safari hanya mendukung H.264/VP9/AV1 pada tag <video>). ffmpeg sudah
    ter-install di image ini (lihat Dockerfile), jadi transcode dilakukan di
    tempat, menimpa file mp4v dengan versi H.264 yang bisa diputar di browser.
    Kalau ffmpeg gagal/tidak ada, file mp4v asli tetap dibiarkan (annotated
    video tetap tersimpan, hanya tidak bisa diputar langsung di browser).
    """
    tmp_path = f"{path}.h264.tmp.mp4"
    try:
        subprocess.run(
            [
                "ffmpeg", "-y", "-loglevel", "error",
                "-i", path,
                "-c:v", "libx264", "-pix_fmt", "yuv420p", "-movflags", "+faststart",
                tmp_path,
            ],
            check=True,
            timeout=600,
        )
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
