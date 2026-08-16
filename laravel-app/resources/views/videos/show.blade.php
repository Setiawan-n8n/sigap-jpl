@extends('layouts.app')

@section('title', $video->original_name)

@section('content')
<div class="bg-white rounded-xl shadow p-6">
    <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
        <h1 class="text-xl font-semibold">{{ $video->original_name }}</h1>
        <span id="status-badge" class="text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $video->status }}</span>
    </div>
    <p class="text-sm text-slate-500 mb-4">
        {{ $video->jplLocation?->name ?? 'Tanpa lokasi' }}
        @if ($video->recorded_at) · {{ $video->recorded_at->format('d M Y H:i') }} @endif
    </p>

    <div id="processing-msg" class="text-sm text-slate-500 mb-4 {{ in_array($video->status, ['completed', 'failed']) ? 'hidden' : '' }}">
        Video sedang diproses oleh model deteksi (YOLOv8)... halaman ini akan diperbarui otomatis.
    </div>

    <div id="error-msg" class="text-sm text-red-600 mb-4 {{ $video->status === 'failed' ? '' : 'hidden' }}">
        {{ $video->error_message }}
    </div>

    <div id="result-section" class="{{ $video->status === 'completed' ? '' : 'hidden' }}">
        <div class="grid gap-6 md:grid-cols-2 mb-6">
            <div>
                <h2 class="font-medium mb-2">Video Hasil Deteksi &amp; Tracking</h2>
                <video id="annotated-video" controls class="w-full rounded-lg bg-black"
                       src="{{ $video->annotated_path ? route('videos.annotated', $video) : '' }}"></video>
            </div>
            <div>
                <h2 class="font-medium mb-2">Total per Kategori</h2>
                <canvas id="chart"></canvas>
            </div>
        </div>

        <h2 class="font-medium mb-2">Rincian per Zona</h2>
        <table class="w-full text-sm border-collapse mb-8" id="results-table">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-2">Zona</th>
                    <th class="py-2">Kategori</th>
                    <th class="py-2">Jumlah</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>

        <div class="flex items-center justify-between mb-2">
            <h2 class="font-medium">Safety Event (Objek di Zona Bahaya)</h2>
            <span id="safety-count-badge" class="text-xs px-2 py-0.5 rounded-full bg-slate-100">0 kejadian</span>
        </div>
        <div id="safety-events" class="space-y-2"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const statusUrl = "{{ route('videos.status', $video) }}";
let chart = null;
let polling = {{ in_array($video->status, ['completed', 'failed']) ? 'false' : 'true' }};

const labelMap = { car: 'Mobil', motorcycle: 'Motor', bicycle: 'Sepeda', person: 'Orang', bus: 'Bus', truck: 'Truk' };

function render(data) {
    document.getElementById('status-badge').textContent = data.status;

    if (data.status === 'failed') {
        document.getElementById('processing-msg').classList.add('hidden');
        document.getElementById('error-msg').classList.remove('hidden');
        document.getElementById('error-msg').textContent = data.error_message || 'Terjadi kesalahan.';
        polling = false;
        return;
    }

    if (data.status !== 'completed') return;

    polling = false;
    document.getElementById('processing-msg').classList.add('hidden');
    document.getElementById('result-section').classList.remove('hidden');

    if (data.annotated_url) {
        document.getElementById('annotated-video').src = data.annotated_url;
    }

    renderTotals(data.totals || {});
    renderSafetyEvents(data.safety_events || []);
}

function renderTotals(totals) {
    const tbody = document.querySelector('#results-table tbody');
    tbody.innerHTML = '';

    const classTotals = {};
    const rows = [];

    Object.keys(totals).forEach(className => {
        const zones = totals[className];
        Object.keys(zones).forEach(zoneName => {
            const count = zones[zoneName];
            rows.push({ className, zoneName, count });
            classTotals[className] = (classTotals[className] || 0) + count;
        });
    });

    rows.sort((a, b) => a.zoneName.localeCompare(b.zoneName));
    rows.forEach(r => {
        const tr = document.createElement('tr');
        tr.className = 'border-b';
        tr.innerHTML = `<td class="py-2">${r.zoneName}</td><td class="py-2">${labelMap[r.className] || r.className}</td><td class="py-2 font-medium">${r.count}</td>`;
        tbody.appendChild(tr);
    });

    const labels = Object.keys(classTotals).map(c => labelMap[c] || c);
    const values = Object.values(classTotals);

    if (chart) chart.destroy();
    chart = new Chart(document.getElementById('chart'), {
        type: 'bar',
        data: { labels, datasets: [{ label: 'Total', data: values, backgroundColor: '#0f172a' }] },
        options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
    });
}

function formatDuration(sec) {
    const m = Math.floor(sec / 60);
    const s = Math.round(sec % 60);
    return m > 0 ? `${m}m ${s}s` : `${s}s`;
}

function renderSafetyEvents(events) {
    const container = document.getElementById('safety-events');
    const badge = document.getElementById('safety-count-badge');
    badge.textContent = `${events.length} kejadian`;
    badge.className = 'text-xs px-2 py-0.5 rounded-full ' + (events.length > 0 ? 'bg-red-100 text-red-800' : 'bg-slate-100');

    container.innerHTML = '';

    if (events.length === 0) {
        container.innerHTML = '<p class="text-sm text-slate-500">Tidak ada objek yang terdeteksi berhenti/diam di zona bahaya.</p>';
        return;
    }

    events.forEach(ev => {
        const card = document.createElement('div');
        card.className = 'flex items-center gap-3 border border-red-200 bg-red-50 rounded-lg p-3';
        card.innerHTML = `
            ${ev.snapshot_url ? `<img src="${ev.snapshot_url}" class="w-24 h-16 object-cover rounded border" alt="snapshot">` : ''}
            <div class="text-sm flex-1">
                <div class="font-medium text-red-800">${labelMap[ev.class_name] || ev.class_name} di "${ev.zone_name}"</div>
                <div class="text-red-600 text-xs">Mulai menit ke-${formatDuration(ev.video_time_seconds)} · diam selama ${formatDuration(ev.duration_seconds)}</div>
            </div>
        `;
        container.appendChild(card);
    });
}

async function poll() {
    if (!polling) return;
    try {
        const res = await fetch(statusUrl);
        const data = await res.json();
        render(data);
    } catch (e) {
        console.error(e);
    }
    if (polling) setTimeout(poll, 3000);
}

poll();
</script>
@endsection
