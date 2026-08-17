"""Logic penghitungan berbasis zona poligon (menggantikan mode garis tunggal).

Dua jenis zona didukung:
- "direction": zona arah (mis. "Rel Kiri", "Rel Kanan"). Setiap track dihitung
  satu kali per zona, saat pertama kali titik tengah bounding box-nya masuk
  ke area zona tersebut.
- "danger": zona bahaya (area perlintasan/rel). Bila sebuah objek (orang/
  kendaraan) berada terus-menerus di dalam zona ini melebihi ambang waktu
  (danger_dwell_seconds), dianggap sebagai potensi bahaya/pelanggaran dan
  dilaporkan sebagai safety event satu kali per kemunculan.
"""
from dataclasses import dataclass, field
from typing import Optional

import cv2
import numpy as np


def build_polygon(points_norm: list, width: int, height: int) -> np.ndarray:
    """Ubah titik-titik ternormalisasi (0..1) jadi poligon piksel untuk cv2.pointPolygonTest."""
    pts = [[float(x) * width, float(y) * height] for x, y in points_norm]
    return np.array(pts, dtype=np.float32).reshape((-1, 1, 2))


@dataclass
class Zone:
    name: str
    type: str  # "direction" | "danger"
    polygon: np.ndarray


@dataclass
class ZoneTracker:
    zones: list
    fps: float
    danger_dwell_seconds: float = 5.0

    direction_counted: set = field(default_factory=set)     # {(track_id, zone_name)}
    danger_state: dict = field(default_factory=dict)        # {(track_id, zone_name): {...}}
    danger_flagged: set = field(default_factory=set)        # {(track_id, zone_name)}
    track_class_votes: dict = field(default_factory=dict)   # {track_id: {class_name: jumlah_frame}}

    def _point_in_zone(self, zone: Zone, x: float, y: float) -> bool:
        return cv2.pointPolygonTest(zone.polygon, (float(x), float(y)), False) >= 0

    def _majority_class(self, track_id, fallback: str) -> str:
        """Kelas "final" sebuah track = kelas yang paling sering muncul di
        seluruh kemunculannya (voting mayoritas), BUKAN kelas pada frame
        pertama track itu terlihat.

        Alasan: YOLO kadang salah-klasifikasi objek yang sama secara
        tidak konsisten antar frame walau track ID-nya tetap sama (mis.
        motor yang sesaat terdeteksi sebagai "car"/"person" sebelum benar
        sebagai "motorcycle" beberapa frame kemudian, karena sudut kamera/
        oklusi/model nano yang kurang presisi). Kalau keputusan kelas
        diambil dari frame pertama saja (perilaku lama), track tsb bisa
        "terkunci" ke kelas yang salah dan objeknya tidak pernah terhitung
        di kategori yang benar sama sekali -- ini persis penyebab kasus
        "Motor" hilang total dari hasil deteksi meski motor jelas terlihat
        lewat di video.
        """
        votes = self.track_class_votes.get(track_id)
        if not votes:
            return fallback
        return max(votes.items(), key=lambda kv: kv[1])[0]

    def update(self, frame_idx: int, detections: list) -> list:
        """detections: list of (track_id, class_name, cx, cy).
        Mengembalikan daftar safety event baru yang baru saja melewati ambang waktu pada frame ini."""
        present_danger_keys = set()

        for track_id, class_name, cx, cy in detections:
            # Catat "suara" kelas track ini pada frame sekarang -- dipakai
            # nanti oleh _majority_class() untuk menentukan kelas final.
            votes = self.track_class_votes.setdefault(track_id, {})
            votes[class_name] = votes.get(class_name, 0) + 1

            for zone in self.zones:
                if not self._point_in_zone(zone, cx, cy):
                    continue

                if zone.type == "direction":
                    # Cukup catat bahwa track ini pernah masuk zona ini.
                    # Kelas finalnya baru diputuskan belakangan (lihat
                    # direction_rows()) memakai voting mayoritas, supaya
                    # tidak salah-hitung akibat flicker klasifikasi di
                    # frame pertama masuk zona.
                    self.direction_counted.add((track_id, zone.name))

                elif zone.type == "danger":
                    key = (track_id, zone.name)
                    present_danger_keys.add(key)
                    if key not in self.danger_state:
                        self.danger_state[key] = {
                            "start_frame": frame_idx,
                            "last_frame": frame_idx,
                        }
                    else:
                        self.danger_state[key]["last_frame"] = frame_idx

        new_events = []
        for key, state in self.danger_state.items():
            if key in present_danger_keys and key not in self.danger_flagged:
                duration = (state["last_frame"] - state["start_frame"]) / self.fps
                if duration >= self.danger_dwell_seconds:
                    self.danger_flagged.add(key)
                    new_events.append({
                        "track_id": key[0],
                        "zone_name": key[1],
                        "class_name": self._majority_class(key[0], "unknown"),
                        "video_time_seconds": state["start_frame"] / self.fps,
                        "duration_seconds": duration,
                        "frame_idx": frame_idx,
                    })

        # Bersihkan state track yang sudah keluar dari zona bahaya, dengan toleransi
        # ~1 detik supaya tidak ter-reset hanya karena deteksi kedip sesaat.
        tolerance_frames = max(1, int(round(self.fps)))
        for key in list(self.danger_state.keys()):
            if key not in present_danger_keys and (frame_idx - self.danger_state[key]["last_frame"]) > tolerance_frames:
                del self.danger_state[key]
                self.danger_flagged.discard(key)

        return new_events

    def direction_rows(self) -> list:
        tally: dict = {}
        for track_id, zone_name in self.direction_counted:
            cls = self._majority_class(track_id, "unknown")
            key = (cls, zone_name)
            tally[key] = tally.get(key, 0) + 1
        return [
            {"class_name": cls, "zone_name": zone_name, "count": count}
            for (cls, zone_name), count in tally.items()
        ]


def track_color(track_id: int) -> tuple:
    """Warna BGR unik & konsisten untuk sebuah track ID (dipakai untuk jejak lintasan)."""
    rng = np.random.default_rng(int(track_id) * 9973 + 17)
    b, g, r = [int(c) for c in rng.integers(60, 256, size=3)]
    return (b, g, r)


def hex_to_bgr(hex_color: Optional[str], default=(0, 200, 0)) -> tuple:
    if not hex_color:
        return default
    try:
        hex_color = hex_color.lstrip("#")
        r, g, b = (int(hex_color[i:i + 2], 16) for i in (0, 2, 4))
        return (b, g, r)
    except Exception:
        return default
