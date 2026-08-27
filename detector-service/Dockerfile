FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
        libgl1 libglib2.0-0 ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /srv

COPY requirements.txt .
# Install CPU-only PyTorch dulu (VPS ini tidak punya GPU). Tanpa ini, pip akan
# menarik build CUDA yang jauh lebih besar (ratusan MB paket nvidia-*) yang
# sama sekali tidak terpakai dan bikin build lambat/rawan gagal.
RUN pip install --no-cache-dir torch==2.3.1 torchvision==0.18.1 --index-url https://download.pytorch.org/whl/cpu \
    && pip install --no-cache-dir -r requirements.txt

COPY app ./app

# Unduh bobot model YOLOv8s saat build agar tidak perlu akses internet saat
# runtime. Pakai varian "s" (small), bukan "n" (nano) -- nano sering
# salah-klasifikasi objek kecil/sekilas-muncul/terhalang (mis. motor parkir
# terbaca "person") karena akurasinya memang paling rendah di keluarga
# YOLOv8. "s" jauh lebih akurat dengan konsekuensi inferensi ~3x lebih
# lambat (VPS ini CPU-only, tidak ada GPU).
#
# Redesign 27 Agu 2026: model "s" ini SEKARANG dipakai untuk KEDUA mode
# (unggah file & sesi live) -- sebelumnya ada model "n" (nano) terpisah
# yang diunduh juga di sini khusus untuk sesi live (yang dulu diproses
# real-time, butuh model lebih ringan). Sejak sesi live direkam dulu lalu
# diproses lewat pipeline yang sama dengan mode unggah (lihat
# detector-service/app/main.py, _run_live_detection & _detect_and_render),
# tidak ada lagi tekanan real-time itu, jadi model "n" terpisah sudah
# dipensiunkan (image jadi lebih kecil & build lebih cepat juga).
RUN python -c "import torch; _orig = torch.load; torch.load = lambda *a, **k: _orig(*a, **{**k, 'weights_only': False}); from ultralytics import YOLO; YOLO('yolov8s.pt')"

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
