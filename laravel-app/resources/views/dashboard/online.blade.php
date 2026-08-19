@extends('layouts.app')

@section('title', 'SIGAP-JPL — Dashboard Online')

@section('content')
@include('dashboard._tabs')

<p class="text-sm text-slate-500 mb-6">Pantau lokasi JPL secara langsung. Lokasi akan muncul di sini setelah Administrator mengisi URL CCTV lewat menu Lokasi JPL.</p>

@if ($locations->isEmpty())
    <div class="bg-white rounded-xl shadow p-10 text-center text-slate-500">
        <p class="text-lg mb-1">Belum ada CCTV online</p>
        <p class="text-sm">
            @if ($totalLocations === 0)
                Administrator belum menambahkan lokasi JPL sama sekali.
            @else
                Administrator belum mengisi URL CCTV untuk lokasi manapun.
            @endif
        </p>
    </div>
@else
    <div class="grid gap-8 lg:grid-cols-3">
        <div class="bg-white rounded-xl shadow p-6 lg:col-span-1">
            <h2 class="text-lg font-semibold mb-4">Lokasi Online ({{ $locations->count() }})</h2>
            <div class="space-y-3">
                @foreach ($locations as $location)
                    <div class="rounded-lg border p-3 {{ $selected && $selected->id === $location->id ? 'border-slate-900 ring-1 ring-slate-900' : '' }}">
                        <div class="flex items-center justify-between">
                            <span class="font-medium">{{ $location->code }} — {{ $location->name }}</span>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-800">Live</span>
                        </div>
                        <a href="{{ route('dashboard.online', ['lokasi' => $location->id]) }}"
                           class="inline-block mt-2 text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-3 py-1.5 font-medium">
                            Lihat CCTV
                        </a>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white rounded-xl shadow p-6 lg:col-span-1">
            <h2 class="text-lg font-semibold mb-4">Tayangan CCTV</h2>
            @if (! $selected)
                <p class="text-sm text-slate-500">Pilih salah satu lokasi di sebelah kiri untuk menampilkan tayangan CCTV.</p>
            @else
                <p class="text-sm text-slate-500 mb-3">
                    {{ $selected->name }}
                    @if ($selected->cctv_added_at)
                        · sejak {{ $selected->cctv_added_at->format('d M Y H:i') }}
                    @endif
                </p>

                @if (preg_match('/\.(m3u8|mp4)(\?|$)/i', $selected->cctv_url))
                    <div class="relative w-full bg-black rounded-lg overflow-hidden">
                        <video src="{{ $selected->cctv_url }}" controls autoplay muted playsinline class="w-full block"></video>
                    </div>
                @else
                    <div class="relative w-full bg-black rounded-lg overflow-hidden" style="aspect-ratio: 16 / 9;">
                        <iframe src="{{ $selected->cctv_url }}" class="w-full h-full border-0" allowfullscreen></iframe>
                    </div>
                @endif

                <p class="text-xs text-slate-400 mt-3 break-all">Sumber: {{ $selected->cctv_url }}</p>
                <p class="text-xs text-slate-400 mt-2">
                    Catatan: mendukung tautan streaming HLS/MP4 langsung atau tautan embed (iframe). Stream RTSP mentah
                    perlu di-relay ke HLS/MP4 terlebih dahulu agar dapat diputar di browser.
                </p>
            @endif
        </div>

        <div class="lg:col-span-1">
            @if (! $selected)
                <div class="bg-white rounded-xl shadow p-6 h-full">
                    <h2 class="text-lg font-semibold mb-1">Video Hasil Deteksi &amp; Tracking</h2>
                    <p class="text-sm text-slate-500">Pilih salah satu lokasi di sebelah kiri untuk menampilkan hasil deteksi live.</p>
                </div>
            @elseif ($liveVideo)
                @include('videos._result_panel', ['video' => $liveVideo, 'liveFinishAt' => $liveJob->finish_at])
            @elseif ($liveJob && $liveJob->status === 'scheduled')
                <div class="bg-white rounded-xl shadow p-6 h-full">
                    <h2 class="text-lg font-semibold mb-1">Video Hasil Deteksi &amp; Tracking</h2>
                    <p class="text-sm text-slate-500 mb-3">Deteksi live untuk lokasi ini sudah dijadwalkan, belum dimulai.</p>
                    <div class="rounded-lg border p-3 text-sm">
                        <div class="font-medium mb-1">Terjadwal</div>
                        <div class="text-slate-500 text-xs">
                            {{ $liveJob->start_at->format('d M Y H:i') }} — {{ $liveJob->finish_at->format('d M Y H:i') }}
                        </div>
                    </div>
                    <p class="text-xs text-slate-400 mt-3">
                        Halaman ini akan otomatis diperbarui saat waktu mulai tiba -- tidak perlu refresh manual.
                    </p>
                </div>
                <script>
                    (function () {
                        // PENTING: jangan pakai location.reload() berkala di sini -- itu
                        // ikut me-restart <video> Tayangan CCTV di kolom sebelah tiap kali
                        // dipanggil (video-nya jadi terputus-putus / "hidup mati"). Sebagai
                        // gantinya, cek status lewat endpoint JSON ringan di background
                        // (tidak menyentuh DOM tayangan CCTV sama sekali), dan HANYA
                        // reload sekali, persis saat statusnya benar-benar berubah.
                        const statusUrl = "{{ route('dashboard.online.status') }}?lokasi={{ $selected->id }}";
                        const initialStatus = "{{ $liveJob->status }}";
                        const startAt = new Date("{{ $liveJob->start_at->toIso8601String() }}").getTime();

                        async function check() {
                            try {
                                const res = await fetch(statusUrl);
                                const data = await res.json();
                                if (data.status !== initialStatus) {
                                    location.reload();
                                    return;
                                }
                            } catch (e) {
                                console.error(e);
                            }

                            const msUntilStart = startAt - Date.now();
                            const delay = msUntilStart > 5 * 60000 ? 20000
                                        : msUntilStart > 60000 ? 8000
                                        : 3000;
                            setTimeout(check, delay);
                        }

                        check();
                    })();
                </script>
            @else
                <div class="bg-white rounded-xl shadow p-6 h-full">
                    <h2 class="text-lg font-semibold mb-1">Video Hasil Deteksi &amp; Tracking</h2>
                    <p class="text-sm text-slate-500">
                        Belum ada jadwal deteksi live untuk lokasi ini.
                        @if (auth()->user()->isAdmin())
                            Buka menu <a href="{{ route('locations.index') }}" class="underline">Lokasi JPL</a> untuk menjadwalkannya.
                        @else
                            Hubungi Administrator untuk menjadwalkannya.
                        @endif
                    </p>
                </div>
            @endif
        </div>
    </div>
@endif
@endsection