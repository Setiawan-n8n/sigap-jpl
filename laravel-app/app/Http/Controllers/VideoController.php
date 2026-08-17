<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessVideoJob;
use App\Models\JplLocation;
use App\Models\SafetyEvent;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VideoController extends Controller
{
    public function index()
    {
        $videos = Video::with('jplLocation')->latest()->paginate(10);
        $locations = JplLocation::orderBy('name')->get();

        return view('videos.index', compact('videos', 'locations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime', 'max:512000'],
            'jpl_location_id' => ['nullable', 'exists:jpl_locations,id'],
            'new_location_code' => ['nullable', 'string', 'max:50', 'required_without:jpl_location_id'],
            'new_location_name' => ['nullable', 'string', 'max:255', 'required_without:jpl_location_id'],
            'recorded_at' => ['nullable', 'date'],
            'video_width' => ['nullable', 'integer'],
            'video_height' => ['nullable', 'integer'],
            'zones_json' => ['required', 'string'],
        ], [
            'video.required' => 'Silakan pilih file video terlebih dahulu.',
            'video.mimetypes' => 'File harus berformat MP4 atau MOV.',
            'video.max' => 'Ukuran video maksimal 500MB.',
            'new_location_code.required_without' => 'Pilih lokasi JPL yang sudah ada, atau isi kode lokasi baru.',
            'new_location_name.required_without' => 'Pilih lokasi JPL yang sudah ada, atau isi nama lokasi baru.',
            'zones_json.required' => 'Silakan gambar minimal satu zona pada video.',
        ]);

        $zones = json_decode($validated['zones_json'], true);

        if (! is_array($zones) || count($zones) < 1) {
            return back()->withErrors(['zones_json' => 'Silakan gambar minimal satu zona pada video.'])->withInput();
        }

        foreach ($zones as $zone) {
            if (empty($zone['name']) || empty($zone['points']) || count($zone['points']) < 3) {
                return back()->withErrors(['zones_json' => 'Setiap zona harus memiliki nama dan minimal 3 titik.'])->withInput();
            }
        }

        $location = null;

        if (! empty($validated['jpl_location_id'])) {
            $location = JplLocation::find($validated['jpl_location_id']);
        } elseif (! empty($validated['new_location_code'])) {
            $location = JplLocation::firstOrCreate(
                ['code' => $validated['new_location_code']],
                ['name' => $validated['new_location_name']]
            );
        }

        $file = $request->file('video');
        $filename = Str::uuid()->toString().'.'.$file->getClientOriginalExtension();
        $file->storeAs('', $filename, 'videos');

        $video = Video::create([
            'jpl_location_id' => $location?->id,
            'original_name' => $file->getClientOriginalName(),
            'filename' => $filename,
            'disk' => 'videos',
            'width' => $validated['video_width'] ?? null,
            'height' => $validated['video_height'] ?? null,
            'recorded_at' => $validated['recorded_at'] ?? now(),
            'status' => 'pending',
        ]);

        foreach ($zones as $zone) {
            $video->zones()->create([
                'name' => $zone['name'],
                'type' => in_array($zone['type'] ?? 'direction', ['direction', 'danger'], true) ? $zone['type'] : 'direction',
                'color' => $zone['color'] ?? '#22c55e',
                'points' => $zone['points'],
            ]);
        }

        ProcessVideoJob::dispatch($video);

        return redirect()->route('videos.show', $video)
            ->with('status', 'Video berhasil diunggah dan sedang diproses.');
    }

    public function show(Video $video)
    {
        $video->load('jplLocation', 'zones', 'countResults', 'safetyEvents');

        return view('videos.show', compact('video'));
    }

    public function status(Video $video)
    {
        $video->load('zones', 'countResults', 'safetyEvents');

        return response()->json([
            'status' => $video->status,
            'progress' => $video->progress,
            'error_message' => $video->error_message,
            'zones' => $video->zones->map(fn ($z) => [
                'name' => $z->name,
                'type' => $z->type,
                'color' => $z->color,
            ]),
            'totals' => $video->totalsByClass(),
            'safety_events' => $video->safetyEvents->map(fn ($e) => [
                'track_id' => $e->track_id,
                'class_name' => $e->class_name,
                'zone_name' => $e->zone_name,
                'video_time_seconds' => round($e->video_time_seconds, 1),
                'duration_seconds' => round($e->duration_seconds, 1),
                'snapshot_url' => $e->snapshot_path ? route('videos.snapshot', $e) : null,
            ]),
            'annotated_url' => $video->annotated_path
                ? route('videos.annotated', $video)
                : null,
        ]);
    }

    public function annotated(Video $video)
    {
        abort_unless($video->annotated_path, 404);

        $path = storage_path('app/videos/'.$video->annotated_path);

        abort_unless(file_exists($path), 404);

        return response()->file($path);
    }

    public function snapshot(SafetyEvent $safetyEvent)
    {
        abort_unless($safetyEvent->snapshot_path, 404);

        $path = storage_path('app/videos/'.$safetyEvent->snapshot_path);

        abort_unless(file_exists($path), 404);

        return response()->file($path);
    }
}
