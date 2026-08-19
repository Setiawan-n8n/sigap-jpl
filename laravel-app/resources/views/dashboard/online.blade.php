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
    <div class="grid gap-8 lg:grid-cols-2">
        <div class="bg-white rounded-xl shadow p-6">
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

        <div class="bg-white rounded-xl shadow p-6">
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
    </div>
@endif
@endsection
