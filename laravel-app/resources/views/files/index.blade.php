@extends('layouts.app')

@section('title', 'SIGAP-JPL — File Video')

@section('content')
<div class="space-y-8">
    <div class="bg-white rounded-xl shadow p-6">
        <h1 class="text-xl font-semibold mb-1">Kelola File Video</h1>
        <p class="text-sm text-slate-500 mb-4">
            Daftar file video yang tersimpan di server (hasil deteksi &amp; tracking, serta file mentah hasil unggahan),
            lengkap dengan ukuran masing-masing dan sisa ruang penyimpanan server.
        </p>

        <div class="grid gap-4 sm:grid-cols-3 mb-4">
            <div class="rounded-lg border p-4">
                <div class="text-xs text-slate-500 mb-1">Total dipakai video SIGAP-JPL</div>
                <div class="text-lg font-semibold">{{ $usedByVideosHuman }}</div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-xs text-slate-500 mb-1">Sisa ruang server</div>
                <div class="text-lg font-semibold">{{ $diskFreeHuman ?? 'Tidak diketahui' }}</div>
            </div>
            <div class="rounded-lg border p-4">
                <div class="text-xs text-slate-500 mb-1">Total kapasitas server</div>
                <div class="text-lg font-semibold">{{ $diskTotalHuman ?? 'Tidak diketahui' }}</div>
            </div>
        </div>

        @if (! is_null($diskUsedPercent))
            <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                <div class="h-3 rounded-full {{ $diskUsedPercent >= 90 ? 'bg-red-600' : ($diskUsedPercent >= 75 ? 'bg-amber-500' : 'bg-slate-900') }}"
                     style="width: {{ min(100, $diskUsedPercent) }}%"></div>
            </div>
            <p class="text-xs text-slate-500 mt-1">{{ $diskUsedPercent }}% ruang server terpakai (seluruh isi server, bukan cuma video).</p>
        @endif

        <p class="text-xs text-slate-400 mt-3">
            Catatan: angka sisa/kapasitas ruang di atas adalah ruang disk server tempat volume video ini tersimpan
            secara keseluruhan (dipakai juga oleh aplikasi &amp; komponen lain di server), bukan hanya jatah untuk video.
        </p>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h2 class="text-lg font-semibold">Video Hasil Deteksi &amp; Tracking</h2>
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $annotated->count() }} file</span>
        </div>
        <p class="text-sm text-slate-500 mb-4">Video akhir yang sudah dianotasi (kotak &amp; label deteksi), dari unggahan maupun sesi live CCTV.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left border-b text-xs text-slate-500 uppercase">
                        <th class="py-2">Lokasi</th>
                        <th class="py-2">Sumber</th>
                        <th class="py-2">Direkam</th>
                        <th class="py-2">Ukuran</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($annotated as $row)
                        <tr class="border-b align-top">
                            <td class="py-2">{{ $row['video']->jplLocation->name ?? '—' }}</td>
                            <td class="py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full {{ $row['is_live'] ? 'bg-green-100 text-green-800' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $row['is_live'] ? 'Sesi Live' : 'Unggahan' }}
                                </span>
                            </td>
                            <td class="py-2 text-slate-500">{{ optional($row['video']->recorded_at)->format('d M Y H:i') ?? '—' }}</td>
                            <td class="py-2 font-medium">{{ $row['size_human'] }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('files.download', [$row['video'], 'annotated']) }}"
                                       class="text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1.5 font-medium">Unduh</a>
                                    <form action="{{ route('files.destroy', [$row['video'], 'annotated']) }}" method="POST"
                                          onsubmit="return confirm('Hapus video hasil deteksi ini dari server? Tindakan ini tidak bisa dibatalkan.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs bg-red-100 hover:bg-red-200 text-red-800 rounded-lg px-2.5 py-1.5 font-medium">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-sm text-slate-500">Belum ada video hasil deteksi &amp; tracking tersimpan di server.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="bg-white rounded-xl shadow p-6">
        <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
            <h2 class="text-lg font-semibold">Video Hasil Upload (File Mentah)</h2>
            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $uploaded->count() }} file</span>
        </div>
        <p class="text-sm text-slate-500 mb-4">
            File asli yang diunggah lewat menu "Unggah Video", sebelum diproses. File ini hanya dipakai sekali saat
            pemrosesan deteksi &mdash; aman dihapus kapan saja untuk membebaskan ruang, terutama untuk video yang statusnya
            sudah "completed" atau "failed".
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="text-left border-b text-xs text-slate-500 uppercase">
                        <th class="py-2">Lokasi</th>
                        <th class="py-2">Nama Asli</th>
                        <th class="py-2">Status Deteksi</th>
                        <th class="py-2">Ukuran</th>
                        <th class="py-2">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($uploaded as $row)
                        <tr class="border-b align-top">
                            <td class="py-2">{{ $row['video']->jplLocation->name ?? '—' }}</td>
                            <td class="py-2 break-all">{{ $row['video']->original_name }}</td>
                            <td class="py-2">
                                <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100">{{ $row['video']->status }}</span>
                            </td>
                            <td class="py-2 font-medium">{{ $row['size_human'] }}</td>
                            <td class="py-2">
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('files.download', [$row['video'], 'raw']) }}"
                                       class="text-xs bg-slate-100 hover:bg-slate-200 rounded-lg px-2.5 py-1.5 font-medium">Unduh</a>
                                    <form action="{{ route('files.destroy', [$row['video'], 'raw']) }}" method="POST"
                                          onsubmit="return confirm('Hapus file upload mentah ini dari server? Tindakan ini tidak bisa dibatalkan.');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs bg-red-100 hover:bg-red-200 text-red-800 rounded-lg px-2.5 py-1.5 font-medium">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-sm text-slate-500">Belum ada file upload mentah tersimpan di server.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection