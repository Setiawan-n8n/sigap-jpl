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

                    <form action="{{ route('locations.destroy', $location) }}" method="POST"
                          class="mt-2"
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
@endsection
