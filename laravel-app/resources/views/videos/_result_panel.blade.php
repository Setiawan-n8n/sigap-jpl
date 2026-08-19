{{-- Panel "Video Hasil Deteksi & Tracking" -- dipakai di halaman detail video
     (videos/show.blade.php) maupun di Dashboard Online (dashboard/online.blade.php)
     untuk video hasil deteksi live. Membutuhkan variabel $video. --}}
<div class="bg-white rounded-xl shadow p-6">
    <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
        <h2 class="font-medium">Video Hasil Deteksi &amp; Tracking</h2>
        <span id="status-badge-{{ $video->id }}" class="text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $video->status }}</span>
    </div>

    <div id="processing-msg-{{ $video->id }}" class="mb-4 {{ in_array($video->status, ['completed', 'failed']) ? 'hidden' : '' }}">
        @if (isset($liveFinishAt) && $liveFinishAt)
            <p class="text-sm text-slate-500 mb-2">
                Rekaman &amp; deteksi live sedang berjalan sampai <strong>{{ $liveFinishAt->format('d M Y H:i') }}</strong>.
                Video hasil deteksi baru bisa diputar setelah sesi ini selesai (sama seperti unggah video biasa,
                bukan siaran langsung) -- panel ini akan berpindah otomatis begitu selesai.
            </p>
        @else
            <p class="text-sm text-slate-500 mb-2">
                Video sedang diproses oleh model deteksi (YOLOv8)... panel ini akan diperbarui otomatis.
            </p>
        @endif
        <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
            <div id="progress-bar-{{ $video->id }}" class="bg-slate-900 h-3 rounded-full transition-all duration-500" style="width: {{ $video->progress }}%"></div>
        </div>
        <p class="text-xs text-slate-500 mt-1"><span id="progress-text-{{ $video->id }}">{{ $video->progress }}</span>%</p>
    </div>

    <div id="error-msg-{{ $video->id }}" class="text-sm text-red-600 mb-4 {{ $video->status === 'failed' ? '' : 'hidden' }}">
        {{ $video->error_message }}
    </div>

    <div id="result-section-{{ $video->id }}" class="{{ $video->status === 'completed' ? '' : 'hidden' }}">
        <div class="grid gap-6 md:grid-cols-2 mb-6">
            <div>
                <video id="annotated-video-{{ $video->id }}" controls class="w-full rounded-lg bg-black"
                       src="{{ $video->annotated_path ? route('videos.annotated', $video) : '' }}"></video>
            </div>
            <div>
                <h3 class="text-sm font-medium mb-2">Total per Kategori</h3>
                <canvas id="chart-{{ $video->id }}"></canvas>
            </div>
        </div>

        <h3 class="text-sm font-medium mb-2">Rincian per Zona</h3>
        <table class="w-full text-sm border-collapse mb-6" id="results-table-{{ $video->id }}">
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
            <h3 class="text-sm font-medium">Safety Event (Objek di Zona Bahaya)</h3>
            <span id="safety-count-badge-{{ $video->id }}" class="text-xs px-2 py-0.5 rounded-full bg-slate-100">0 kejadian</span>
        </div>
        <div id="safety-events-{{ $video->id }}" class="space-y-2"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const videoId = {{ $video->id }};
    const statusUrl = "{{ route('videos.status', $video) }}";
    let chart = null;
    let polling = {{ in_array($video->status, ['completed', 'failed']) ? 'false' : 'true' }};

    const labelMap = { car: 'Mobil', motorcycle: 'Motor', bicycle: 'Sepeda', person: 'Orang', bus: 'Bus', truck: 'Truk' };

    function el(id) { return document.getElementById(id + '-' + videoId); }

    function render(data) {
        el('status-badge').textContent = data.status;

        if (data.status === 'failed') {
            el('processing-msg').classList.add('hidden');
            el('error-msg').classList.remove('hidden');
            el('error-msg').textContent = data.error_message || 'Terjadi kesalahan.';
            polling = false;
            return;
        }

        if (data.status !== 'completed') {
            const pct = data.progress || 0;
            el('progress-bar').style.width = pct + '%';
            el('progress-text').textContent = pct;
            return;
        }

        el('progress-bar').style.width = '100%';
        el('progress-text').textContent = 100;
        polling = false;
        el('processing-msg').classList.add('hidden');
        el('result-section').classList.remove('hidden');

        if (data.annotated_url) {
            el('annotated-video').src = data.annotated_url;
        }

        renderTotals(data.totals || {});
        renderSafetyEvents(data.safety_events || []);
    }

    function renderTotals(totals) {
        const tbody = el('results-table').querySelector('tbody');
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
        chart = new Chart(el('chart'), {
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
        const container = el('safety-events');
        const badge = el('safety-count-badge');
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
                    <div class="text-red-600 text-xs">Mulai menit ke-${formatDuration(ev.video_time_seconds)} - diam selama ${formatDuration(ev.duration_seconds)}</div>
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
})();
</script>