@extends('layouts.app')

@section('title', 'SIGAP-JPL — Unggah Video')

@section('content')
<div class="grid gap-8 lg:grid-cols-2">
    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-xl font-semibold mb-1">Unggah Video CCTV JPL</h1>
        <p class="text-sm text-slate-500 mb-4">Gambar satu atau lebih zona pada video: zona arah untuk menghitung objek yang lewat, dan/atau zona bahaya untuk mendeteksi objek yang berhenti/diam di area rel.</p>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('videos.store') }}" method="POST" enctype="multipart/form-data" id="upload-form">
            @csrf

            <label class="block text-sm font-medium mb-1">Lokasi JPL</label>
            <select id="location-select" class="block w-full text-sm border rounded-lg p-2 mb-2">
                <option value="">— Pilih lokasi —</option>
                @foreach ($locations as $location)
                    <option value="{{ $location->id }}">{{ $location->code }} — {{ $location->name }}</option>
                @endforeach
                <option value="__new__">+ Tambah lokasi baru</option>
            </select>
            <input type="hidden" name="jpl_location_id" id="jpl_location_id">

            <div id="new-location-fields" class="hidden grid grid-cols-2 gap-2 mb-4">
                <input type="text" name="new_location_code" placeholder="Kode (mis. JPL-013)" class="text-sm border rounded-lg p-2">
                <input type="text" name="new_location_name" placeholder="Nama lokasi" class="text-sm border rounded-lg p-2">
            </div>
            <div id="location-spacer" class="mb-4"></div>

            <label class="block text-sm font-medium mb-1">Waktu rekaman</label>
            <input type="datetime-local" name="recorded_at" id="recorded_at" class="block w-full text-sm border rounded-lg p-2 mb-4">

            <label class="block text-sm font-medium mb-1">File video (.mp4)</label>
            <input type="file" name="video" id="video-input" accept="video/mp4" required
                   class="block w-full text-sm border rounded-lg p-2 mb-4">

            <div class="relative w-full bg-black rounded-lg overflow-hidden mb-3" style="max-width: 480px;">
                <video id="preview" class="w-full block" muted playsinline></video>
                <canvas id="zone-canvas" class="absolute top-0 left-0 w-full h-full cursor-crosshair"></canvas>
            </div>

            <div class="border rounded-lg p-3 mb-3 bg-slate-50">
                <p class="text-xs text-slate-500 mb-2">Klik pada video untuk menambah titik poligon (minimal 3 titik), lalu isi nama &amp; tipe zona di bawah dan klik "Simpan Zona".</p>
                <div class="grid grid-cols-2 gap-2 mb-2">
                    <input type="text" id="zone-name" placeholder="Nama zona (mis. Rel Kiri)" class="text-sm border rounded-lg p-2">
                    <select id="zone-type" class="text-sm border rounded-lg p-2">
                        <option value="direction">Zona Arah (hitung objek lewat)</option>
                        <option value="danger">Zona Bahaya (area rel/perlintasan)</option>
                    </select>
                </div>
                <div class="flex items-center gap-2 mb-2">
                    <label class="text-xs text-slate-500">Warna:</label>
                    <input type="color" id="zone-color" value="#22c55e" class="h-8 w-14 border rounded">
                    <button type="button" id="undo-point" class="text-xs text-slate-600 underline ml-auto">Batalkan titik terakhir</button>
                </div>
                <button type="button" id="save-zone" disabled
                        class="w-full bg-slate-700 text-white rounded-lg py-2 text-sm font-medium disabled:opacity-40">
                    Simpan Zona (min. 3 titik)
                </button>
            </div>

            <div id="zone-list" class="space-y-1 mb-4 text-sm"></div>

            <input type="hidden" name="zones_json" id="zones_json">
            <input type="hidden" name="video_width" id="video_width">
            <input type="hidden" name="video_height" id="video_height">

            <button type="submit" id="submit-btn" disabled
                    class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium disabled:opacity-40">
                Unggah &amp; Proses
            </button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Riwayat Video</h2>
        <div class="space-y-3">
            @forelse ($videos as $video)
                <a href="{{ route('videos.show', $video) }}"
                   class="block rounded-lg border p-3 hover:bg-slate-50">
                    <div class="flex items-center justify-between">
                        <span class="font-medium truncate">{{ $video->original_name }}</span>
                        <span @class([
                            'text-xs px-2 py-0.5 rounded-full',
                            'bg-yellow-100 text-yellow-800' => $video->status === 'pending',
                            'bg-blue-100 text-blue-800' => $video->status === 'processing',
                            'bg-green-100 text-green-800' => $video->status === 'completed',
                            'bg-red-100 text-red-800' => $video->status === 'failed',
                        ])>{{ $video->status }}</span>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">
                        {{ $video->jplLocation?->name ?? 'Tanpa lokasi' }} · {{ $video->created_at->diffForHumans() }}
                    </div>
                </a>
            @empty
                <p class="text-sm text-slate-500">Belum ada video yang diunggah.</p>
            @endforelse
        </div>
        {{ $videos->links() }}
    </div>
</div>

<script>
const locationSelect = document.getElementById('location-select');
const newLocationFields = document.getElementById('new-location-fields');
const jplLocationIdInput = document.getElementById('jpl_location_id');

locationSelect.addEventListener('change', () => {
    if (locationSelect.value === '__new__') {
        newLocationFields.classList.remove('hidden');
        jplLocationIdInput.value = '';
    } else {
        newLocationFields.classList.add('hidden');
        jplLocationIdInput.value = locationSelect.value;
    }
});

// default waktu rekaman = sekarang
const recordedAt = document.getElementById('recorded_at');
const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
recordedAt.value = now.toISOString().slice(0, 16);

const videoInput = document.getElementById('video-input');
const preview = document.getElementById('preview');
const canvas = document.getElementById('zone-canvas');
const ctx = canvas.getContext('2d');
const zoneNameInput = document.getElementById('zone-name');
const zoneTypeSelect = document.getElementById('zone-type');
const zoneColorInput = document.getElementById('zone-color');
const saveZoneBtn = document.getElementById('save-zone');
const undoPointBtn = document.getElementById('undo-point');
const zoneListEl = document.getElementById('zone-list');
const submitBtn = document.getElementById('submit-btn');

const palette = ['#22c55e', '#3b82f6', '#a855f7', '#f97316', '#ef4444', '#eab308'];
let paletteIndex = 0;

let currentPoints = [];
let zones = [];

videoInput.addEventListener('change', () => {
    const file = videoInput.files[0];
    if (!file) return;
    preview.src = URL.createObjectURL(file);
    currentPoints = [];
    zones = [];
    renderZoneList();
    updateSubmitState();
});

preview.addEventListener('loadedmetadata', () => {
    document.getElementById('video_width').value = preview.videoWidth;
    document.getElementById('video_height').value = preview.videoHeight;
    resizeCanvas();
    preview.currentTime = Math.min(1, preview.duration / 2);
});

function resizeCanvas() {
    canvas.width = preview.clientWidth;
    canvas.height = preview.clientHeight;
    draw();
}
window.addEventListener('resize', resizeCanvas);

canvas.addEventListener('click', (e) => {
    const rect = canvas.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = (e.clientY - rect.top) / rect.height;
    currentPoints.push({ x, y });
    saveZoneBtn.disabled = currentPoints.length < 3;
    draw();
});

undoPointBtn.addEventListener('click', () => {
    currentPoints.pop();
    saveZoneBtn.disabled = currentPoints.length < 3;
    draw();
});

saveZoneBtn.addEventListener('click', () => {
    if (currentPoints.length < 3) return;

    const name = zoneNameInput.value.trim() || `Zona ${zones.length + 1}`;
    zones.push({
        name,
        type: zoneTypeSelect.value,
        color: zoneColorInput.value,
        points: currentPoints.map(p => [p.x, p.y]),
    });

    currentPoints = [];
    zoneNameInput.value = '';
    saveZoneBtn.disabled = true;
    paletteIndex = (paletteIndex + 1) % palette.length;
    zoneColorInput.value = palette[paletteIndex];

    renderZoneList();
    updateSubmitState();
    draw();
});

function renderZoneList() {
    zoneListEl.innerHTML = '';
    zones.forEach((zone, idx) => {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 border rounded-lg px-3 py-1.5';
        row.innerHTML = `
            <span class="inline-block w-3 h-3 rounded-full" style="background:${zone.color}"></span>
            <span class="flex-1 truncate">${zone.name}</span>
            <span class="text-xs text-slate-400">${zone.type === 'danger' ? 'Bahaya' : 'Arah'}</span>
            <button type="button" data-idx="${idx}" class="delete-zone text-xs text-red-600 underline">Hapus</button>
        `;
        zoneListEl.appendChild(row);
    });

    document.querySelectorAll('.delete-zone').forEach(btn => {
        btn.addEventListener('click', () => {
            zones.splice(Number(btn.dataset.idx), 1);
            renderZoneList();
            updateSubmitState();
            draw();
        });
    });
}

function updateSubmitState() {
    document.getElementById('zones_json').value = JSON.stringify(zones);
    submitBtn.disabled = zones.length < 1 || !videoInput.files[0];
}

function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);

    zones.forEach(zone => {
        ctx.beginPath();
        zone.points.forEach((p, i) => {
            const px = p[0] * canvas.width, py = p[1] * canvas.height;
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        });
        ctx.closePath();
        ctx.strokeStyle = zone.color;
        ctx.lineWidth = 2;
        ctx.stroke();
        if (zone.type === 'danger') {
            ctx.fillStyle = zone.color + '33';
            ctx.fill();
        }
        const first = zone.points[0];
        ctx.fillStyle = zone.color;
        ctx.font = '12px sans-serif';
        ctx.fillText(zone.name, first[0] * canvas.width + 4, first[1] * canvas.height - 4);
    });

    if (currentPoints.length) {
        ctx.beginPath();
        currentPoints.forEach((p, i) => {
            const px = p.x * canvas.width, py = p.y * canvas.height;
            if (i === 0) ctx.moveTo(px, py); else ctx.lineTo(px, py);
        });
        ctx.strokeStyle = zoneColorInput.value;
        ctx.lineWidth = 2;
        ctx.setLineDash([4, 4]);
        ctx.stroke();
        ctx.setLineDash([]);

        currentPoints.forEach(p => {
            ctx.beginPath();
            ctx.arc(p.x * canvas.width, p.y * canvas.height, 4, 0, Math.PI * 2);
            ctx.fillStyle = zoneColorInput.value;
            ctx.fill();
        });
    }
}

document.getElementById('upload-form').addEventListener('submit', (e) => {
    updateSubmitState();
    if (zones.length < 1) {
        e.preventDefault();
        alert('Silakan gambar dan simpan minimal satu zona terlebih dahulu.');
    }
});
</script>
@endsection
