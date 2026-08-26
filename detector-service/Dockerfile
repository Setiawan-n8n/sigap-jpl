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
# lambat (VPS ini CPU-only, tidak ada GPU). Model "s" ini dipakai untuk
# mode UNGGAH FILE (_run_detection), yang tidak punya tekanan real-time.
RUN python -c "import torch; _orig = torch.load; torch.load = lambda *a, **k: _orig(*a, **{**k, 'weights_only': False}); from ultralytics import YOLO; YOLO('yolov8s.pt')"

# Unduh JUGA bobot model YOLOv8n (nano) saat build -- dipakai KHUSUS untuk
# sesi live (lihat LIVE_MODEL_NAME di config.py & model_live di main.py).
# Sesi live memproses stream dalam SATU pass real-time yang dipacing ke
# 15fps output; model "s" pada CPU 4 vCPU VPS ini terlalu lambat untuk
# mengejar target itu, membuat video hasil terlihat patah-patah/steppy
# (frame sama diduplikasi berkali-kali menunggu inferensi berikutnya
# selesai). Model "n" ~3x lebih cepat dari "s", trade-off-nya akurasi
# deteksi objek kecil/jauh sedikit lebih rendah -- dianggap sepadan untuk
# tayangan live, sementara mode unggah file tetap presisi penuh via "s".
RUN python -c "import torch; _orig = torch.load; torch.load = lambda *a, **k: _orig(*a, **{**k, 'weights_only': False}); from ultralytics import YOLO; YOLO('yolov8n.pt')"

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
