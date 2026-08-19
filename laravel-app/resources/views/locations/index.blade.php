@extends('layouts.app')

@section('title', 'SIGAP-JPL — Lokasi JPL')

@section('content')
<div class="grid gap-8 md:grid-cols-2">
    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-xl font-semibold mb-4">Tambah Lokasi JPL</h1>

        @if ($errors->any())
            <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                <ul class="list-disc list-inside">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('locations.store') }}" method="POST" class="space-y-3">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1">Kode Lokasi</label>
                <input type="text" name="code" placeholder="mis. JPL-013" class="w-full text-sm border rounded-lg p-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Nama Lokasi</label>
                <input type="text" name="name" placeholder="mis. Putri Hijau - Perintis, Medan" class="w-full text-sm border rounded-lg p-2" required>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Posisi KM (opsional)</label>
                <input type="text" name="km_position" placeholder="mis. KM 12+500" class="w-full text-sm border rounded-lg p-2">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Deskripsi (opsional)</label>
                <textarea name="description" rows="3" class="w-full text-sm border rounded-lg p-2"></textarea>
            </div>
            <button type="submit" class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium">Simpan Lokasi</button>
        </form>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <h2 class="text-lg font-semibold mb-4">Daftar Lokasi JPL</h2>
        <p class="text-xs text-slate-500 mb-4">Isi URL CCTV untuk mengaktifkan Dashboard Online lokasi tersebut. Dashboard Offline aktif otomatis begitu ada video yang diunggah.</p>

        @if ($errors->liveJob->any())
            <div class="mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm">
                <p class="font-medium mb-1">Gagal menjadwalkan deteksi live:</p>
                <ul class="list-disc list-inside">
                    @foreach ($errors->liveJob->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
                <p class="mt-1 text-xs">Silakan klik "+ Jadwalkan Deteksi Live" pada lokasi yang dituju dan coba lagi.</p>
            </div>
        @endif
        <div class="space-y-3">
            @forelse ($locations as $location)
                <div class="rounded-lg border p-3">
                    <div class="flex items-center justify-between flex-wrap gap-2">
                        <span class="font-medium">{{ $location->code }} — {{ $location->name }}</span>
                        <div class="flex items-center gap-2">
                            @if ($location->safety_event_count > 0)
                                <span class="text-xs px-2 py-0.5 rounded-full bg-red-100 text-red-800">{{ $location->safety_event_count }} safety event</span>
                            @endif
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $location->cctv_url ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $location->cctv_url ? 'Online aktif' : 'Online belum diatur' }}
                            </span>
                            <span class="text-xs px-2 py-0.5 rounded-full {{ $location->videos_count > 0 ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' }}">
                                {{ $location->videos_count > 0 ? 'Offline aktif ('.$location->videos_count.' video)' : 'Offline belum ada video' }}
                            </span>
                        </div>
                    </div>
                    @if ($location->km_position)
                        <div class="text-xs text-slate-400 mt-1">{{ $location->km_position }}</div>
                    @endif

                    <form action="{{ route('locations.cctv', $location) }}" method="POST" class="flex items-center gap-2 mt-3">
                        @csrf
                        <input type="text" name="cctv_url" value="{{ $location->cctv_url }}"
                               placeholder="https://... (HLS/MP4/embed) atau rtsp://... setelah di-relay"
                               class="flex-1 text-sm border rounded-lg p-2">
                        <button type="submit" class="text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-3 py-2 font-medium whitespace-nowrap">Simpan URL</button>
                    </form>

                    @if ($location->cctv_url)
                        <div class="mt-3 pt-3 border-t">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <span class="text-xs font-medium text-slate-600">Deteksi Live Terjadwal</span>
                                <button type="button"
                                        class="open-scheduler text-xs bg-slate-900 text-white rounded-lg px-3 py-1.5 font-medium whitespace-nowrap"
                                        data-location-id="{{ $location->id }}"
                                        data-location-name="{{ $location->name }}"
                                        data-snapshot-url="{{ route('locations.live-jobs.snapshot', $location) }}"
                                        data-store-url="{{ route('locations.live-jobs.store', $location) }}">
                                    + Jadwalkan Deteksi Live
                                </button>
                            </div>

                            @forelse ($location->liveCaptureJobs as $job)
                                <div class="flex items-center justify-between text-xs mt-2 border rounded-lg px-2.5 py-1.5">
                                    <div>
                                        <span @class([
                                            'px-1.5 py-0.5 rounded-full mr-1',
                                            'bg-yellow-100 text-yellow-800' => $job->status === 'scheduled',
                                            'bg-blue-100 text-blue-800' => $job->status === 'running',
                                        ])>{{ $job->statusLabel() }}</span>
                                        {{ $job->start_at->format('d M Y H:i') }} — {{ $job->finish_at->format('d M Y H:i') }}
                                    </div>
                                    @if ($job->isCancelable())
                                        <form action="{{ route('live-jobs.cancel', $job) }}" method="POST"
                                              onsubmit="return confirm('Batalkan jadwal deteksi live ini?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="text-red-600 underline">Batalkan</button>
                                        </form>
                                    @endif
                                </div>
                            @empty
                                <p class="text-xs text-slate-400 mt-2">Belum ada jadwal deteksi live untuk lokasi ini.</p>
                            @endforelse
                        </div>
                    @endif

                    <form action="{{ route('locations.destroy', $location) }}" method="POST"
                          class="mt-3"
                          onsubmit="return confirm('Hapus lokasi {{ $location->name }}? Video yang sudah diunggah tidak ikut terhapus, hanya kehilangan tautan lokasinya.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="text-xs text-red-600 underline">Hapus Lokasi</button>
                    </form>
                </div>
            @empty
                <p class="text-sm text-slate-500">Belum ada lokasi JPL terdaftar.</p>
            @endforelse
        </div>
    </div>
</div>

{{-- Panel penjadwalan deteksi live (dipakai bergantian oleh semua lokasi --
     dibuka lewat tombol "+ Jadwalkan Deteksi Live" di atas). Zona digambar
     ulang di atas snapshot CCTV TERKINI yang diambil saat panel dibuka,
     bukan dipakai ulang dari unggahan video sebelumnya. --}}
<div id="scheduler-backdrop" class="hidden fixed inset-0 bg-black/50 z-40 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-6">
        <div class="flex items-center justify-between mb-1">
            <h2 class="text-lg font-semibold">Jadwalkan Deteksi Live — <span id="scheduler-location-name"></span></h2>
            <button type="button" id="scheduler-close" class="text-slate-400 hover:text-slate-600 text-xl leading-none">&times;</button>
        </div>
        <p class="text-sm text-slate-500 mb-4">
            Stream CCTV akan diproses langsung lewat YOLOv8 (tanpa direkam dulu) selama rentang waktu yang dipilih.
            Gambar zona di atas snapshot terkini di bawah ini.
        </p>

        <div id="scheduler-errors" class="hidden mb-4 rounded-lg bg-red-100 text-red-800 px-4 py-3 text-sm"></div>

        <form id="live-job-form" method="POST">
            @csrf

            <div class="grid grid-cols-2 gap-2 mb-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Waktu Mulai</label>
                    <input type="datetime-local" name="start_at" id="live-start-at" required class="w-full text-sm border rounded-lg p-2">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Waktu Selesai</label>
                    <input type="datetime-local" name="finish_at" id="live-finish-at" required class="w-full text-sm border rounded-lg p-2">
                </div>
            </div>

            <button type="button" id="take-snapshot"
                    class="w-full mb-3 bg-slate-100 hover:bg-slate-200 rounded-lg py-2 text-sm font-medium">
                Ambil Snapshot dari CCTV
            </button>
            <p id="snapshot-status" class="text-xs text-slate-500 mb-3"></p>

            <div id="snapshot-wrap" class="hidden">
                <div class="relative w-full bg-black rounded-lg overflow-hidden mb-3">
                    <canvas id="live-zone-canvas" class="w-full block cursor-crosshair"></canvas>
                </div>

                <div class="border rounded-lg p-3 mb-3 bg-slate-50">
                    <p class="text-xs text-slate-500 mb-2">Klik pada gambar untuk menambah titik poligon (minimal 3 titik), lalu isi nama &amp; tipe zona di bawah dan klik "Simpan Zona".</p>
                    <div class="grid grid-cols-2 gap-2 mb-2">
                        <input type="text" id="live-zone-name" placeholder="Nama zona (mis. Rel Kiri)" class="text-sm border rounded-lg p-2">
                        <select id="live-zone-type" class="text-sm border rounded-lg p-2">
                            <option value="direction">Zona Arah (hitung objek lewat)</option>
                            <option value="danger">Zona Bahaya (area rel/perlintasan)</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 mb-2">
                        <label class="text-xs text-slate-500">Warna:</label>
                        <input type="color" id="live-zone-color" value="#22c55e" class="h-8 w-14 border rounded">
                        <button type="button" id="live-undo-point" class="text-xs text-slate-600 underline ml-auto">Batalkan titik terakhir</button>
                    </div>
                    <button type="button" id="live-save-zone" disabled
                            class="w-full bg-slate-700 text-white rounded-lg py-2 text-sm font-medium disabled:opacity-40">
                        Simpan Zona (min. 3 titik)
                    </button>
                </div>

                <div id="live-zone-list" class="space-y-1 mb-4 text-sm"></div>
            </div>

            <input type="hidden" name="zones_json" id="live-zones-json">

            <button type="submit" id="live-submit-btn" disabled
                    class="w-full bg-slate-900 text-white rounded-lg py-2.5 font-medium disabled:opacity-40">
                Simpan Jadwal
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    const backdrop = document.getElementById('scheduler-backdrop');
    const locationNameEl = document.getElementById('scheduler-location-name');
    const form = document.getElementById('live-job-form');
    const startAtInput = document.getElementById('live-start-at');
    const finishAtInput = document.getElementById('live-finish-at');
    const takeSnapshotBtn = document.getElementById('take-snapshot');
    const snapshotStatus = document.getElementById('snapshot-status');
    const snapshotWrap = document.getElementById('snapshot-wrap');
    const canvas = document.getElementById('live-zone-canvas');
    const ctx = canvas.getContext('2d');
    const zoneNameInput = document.getElementById('live-zone-name');
    const zoneTypeSelect = document.getElementById('live-zone-type');
    const zoneColorInput = document.getElementById('live-zone-color');
    const saveZoneBtn = document.getElementById('live-save-zone');
    const undoPointBtn = document.getElementById('live-undo-point');
    const zoneListEl = document.getElementById('live-zone-list');
    const submitBtn = document.getElementById('live-submit-btn');
    const errorsBox = document.getElementById('scheduler-errors');

    const palette = ['#22c55e', '#3b82f6', '#a855f7', '#f97316', '#ef4444', '#eab308'];
    let paletteIndex = 0;
    let currentPoints = [];
    let zones = [];
    let snapshotImage = null;
    let snapshotUrl = '';

    function resetPanel() {
        zones = [];
        currentPoints = [];
        snapshotImage = null;
        snapshotWrap.classList.add('hidden');
        snapshotStatus.textContent = '';
        errorsBox.classList.add('hidden');
        errorsBox.innerHTML = '';
        zoneNameInput.value = '';
        paletteIndex = 0;
        zoneColorInput.value = palette[0];
        renderZoneList();
        updateSubmitState();

        const now = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
        const soon = new Date(now.getTime() + 5 * 60000);
        startAtInput.value = soon.toISOString().slice(0, 16);
        finishAtInput.value = new Date(soon.getTime() + 60 * 60000).toISOString().slice(0, 16);
    }

    document.querySelectorAll('.open-scheduler').forEach(btn => {
        btn.addEventListener('click', () => {
            locationNameEl.textContent = btn.dataset.locationName;
            form.action = btn.dataset.storeUrl;
            snapshotUrl = btn.dataset.snapshotUrl;
            resetPanel();
            backdrop.classList.remove('hidden');
        });
    });

    document.getElementById('scheduler-close').addEventListener('click', () => {
        backdrop.classList.add('hidden');
    });
    backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) backdrop.classList.add('hidden');
    });

    takeSnapshotBtn.addEventListener('click', async () => {
        takeSnapshotBtn.disabled = true;
        snapshotStatus.textContent = 'Mengambil snapshot dari CCTV...';
        try {
            const res = await fetch(snapshotUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '{{ csrf_token() }}',
                    'Accept': 'application/json',
                },
            });
            const data = await res.json();
            if (!res.ok) {
                snapshotStatus.textContent = data.error || 'Gagal mengambil snapshot.';
                takeSnapshotBtn.disabled = false;
                return;
            }

            snapshotImage = new Image();
            snapshotImage.onload = () => {
                canvas.width = data.width || snapshotImage.naturalWidth;
                canvas.height = data.height || snapshotImage.naturalHeight;
                snapshotWrap.classList.remove('hidden');
                snapshotStatus.textContent = 'Snapshot berhasil diambil. Gambar zona di atasnya.';
                draw();
            };
            snapshotImage.src = data.image_url;
        } catch (e) {
            snapshotStatus.textContent = 'Gagal mengambil snapshot: ' + e.message;
        } finally {
            takeSnapshotBtn.disabled = false;
        }
    });

    canvas.addEventListener('click', (e) => {
        if (!snapshotImage) return;
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
                <button type="button" data-idx="${idx}" class="delete-live-zone text-xs text-red-600 underline">Hapus</button>
            `;
            zoneListEl.appendChild(row);
        });

        document.querySelectorAll('.delete-live-zone').forEach(btn => {
            btn.addEventListener('click', () => {
                zones.splice(Number(btn.dataset.idx), 1);
                renderZoneList();
                updateSubmitState();
                draw();
            });
        });
    }

    function updateSubmitState() {
        document.getElementById('live-zones-json').value = JSON.stringify(zones);
        submitBtn.disabled = zones.length < 1 || !snapshotImage;
    }

    function draw() {
        if (!snapshotImage) return;
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(snapshotImage, 0, 0, canvas.width, canvas.height);

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

    form.addEventListener('submit', (e) => {
        updateSubmitState();
        if (zones.length < 1) {
            e.preventDefault();
            alert('Silakan gambar dan simpan minimal satu zona terlebih dahulu.');
        }
    });
})();
</script>
@endsection
