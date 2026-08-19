@extends('layouts.app')

@section('title', 'SIGAP-JPL — Dashboard Offline')

@section('content')
@include('dashboard._tabs')

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow p-4">
        <div class="text-xs text-slate-500">Total Video</div>
        <div class="text-2xl font-semibold">{{ $summary['total_videos'] }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="text-xs text-slate-500">Selesai Diproses</div>
        <div class="text-2xl font-semibold">{{ $summary['total_completed'] }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-4">
        <div class="text-xs text-slate-500">Total Objek Terdeteksi</div>
        <div class="text-2xl font-semibold">{{ $summary['total_objects'] }}</div>
    </div>
    <div class="bg-white rounded-xl shadow p-4 {{ $summary['total_safety_events'] > 0 ? 'ring-2 ring-red-300' : '' }}">
        <div class="text-xs text-slate-500">Safety Event</div>
        <div class="text-2xl font-semibold {{ $summary['total_safety_events'] > 0 ? 'text-red-600' : '' }}">{{ $summary['total_safety_events'] }}</div>
    </div>
</div>

<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h2 class="font-semibold mb-3">Ringkasan per Lokasi JPL</h2>
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-2">Lokasi</th>
                    <th class="py-2">Total Video</th>
                    <th class="py-2">Safety Event</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($locations as $location)
                    <tr class="border-b">
                        <td class="py-2">{{ $location->code }} — {{ $location->name }}</td>
                        <td class="py-2">{{ $location->videos_count }}</td>
                        <td class="py-2">
                            <span class="{{ $location->safety_event_count > 0 ? 'text-red-600 font-medium' : '' }}">
                                {{ $location->safety_event_count }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-500">Belum ada lokasi JPL terdaftar.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="bg-white rounded-xl shadow p-6">
    <div class="flex items-center justify-between mb-3 flex-wrap gap-2">
        <h2 class="font-semibold">Riwayat Video</h2>
        <a href="{{ route('dashboard.export', request()->query()) }}"
           class="text-sm bg-slate-900 text-white px-3 py-1.5 rounded-lg">Export CSV</a>
    </div>

    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-4 text-sm">
        <select name="jpl_location_id" class="border rounded-lg p-2">
            <option value="">Semua lokasi</option>
            @foreach ($locations as $location)
                <option value="{{ $location->id }}" @selected(request('jpl_location_id') == $location->id)>{{ $location->code }} — {{ $location->name }}</option>
            @endforeach
        </select>
        <input type="date" name="from" value="{{ request('from') }}" class="border rounded-lg p-2">
        <input type="date" name="to" value="{{ request('to') }}" class="border rounded-lg p-2">
        <button type="submit" class="bg-slate-200 rounded-lg p-2">Filter</button>
    </form>

    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="text-left border-b">
                    <th class="py-2">Video</th>
                    <th class="py-2">Lokasi</th>
                    <th class="py-2">Tanggal Rekam</th>
                    <th class="py-2">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($videos as $video)
                    <tr class="border-b hover:bg-slate-50">
                        <td class="py-2"><a href="{{ route('videos.show', $video) }}" class="text-blue-600 hover:underline">{{ $video->original_name }}</a></td>
                        <td class="py-2">{{ $video->jplLocation?->name ?? '-' }}</td>
                        <td class="py-2">{{ optional($video->recorded_at)->format('d M Y H:i') ?? '-' }}</td>
                        <td class="py-2">
                            <span @class([
                                'text-xs px-2 py-0.5 rounded-full',
                                'bg-yellow-100 text-yellow-800' => $video->status === 'pending',
                                'bg-blue-100 text-blue-800' => $video->status === 'processing',
                                'bg-green-100 text-green-800' => $video->status === 'completed',
                                'bg-red-100 text-red-800' => $video->status === 'failed',
                            ])>{{ $video->status }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-3 text-slate-500">Tidak ada video yang cocok dengan filter.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $videos->links() }}</div>
</div>
@endsection
