@extends('layouts.app')

@section('title', $video->original_name)

@section('content')
<div class="bg-white rounded-xl shadow p-6 mb-6">
    <h1 class="text-xl font-semibold mb-1">{{ $video->original_name }}</h1>
    <p class="text-sm text-slate-500">
        {{ $video->jplLocation?->name ?? 'Tanpa lokasi' }}
        @if ($video->recorded_at) · {{ $video->recorded_at->format('d M Y H:i') }} @endif
        @if ($video->isFromLiveCapture()) · <span class="text-xs px-2 py-0.5 rounded-full bg-blue-100 text-blue-800">Deteksi Live</span> @endif
    </p>
</div>

@include('videos._result_panel', ['video' => $video])
@endsection
