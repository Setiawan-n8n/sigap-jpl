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

# Unduh bobot model YOLOv8n saat build agar tidak perlu akses internet saat runtime.
RUN python -c "import torch; _orig = torch.load; torch.load = lambda *a, **k: _orig(*a, **{**k, 'weights_only': False}); from ultralytics import YOLO; YOLO('yolov8n.pt')"

EXPOSE 8001

CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8001"]
