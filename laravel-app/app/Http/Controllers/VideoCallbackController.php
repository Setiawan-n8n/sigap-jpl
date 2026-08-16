<?php

namespace App\Http\Controllers;

use App\Models\CountResult;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Menerima hasil deteksi dari detector-service (Python) setelah video selesai diproses.
 * Diamankan dengan shared secret di header X-Callback-Secret (bukan sesi/CSRF,
 * karena dipanggil server-to-server dari container lain).
 */
class VideoCallbackController extends Controller
{
    public function store(Request $request, Video $video)
    {
        if (! hash_equals((string) config('services.detector.secret'), (string) $request->header('X-Callback-Secret'))) {
            abort(403, 'Invalid callback secret.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:completed,failed'],
            'error_message' => ['nullable', 'string'],
            'annotated_path' => ['nullable', 'string'],
            'counts' => ['nullable', 'array'],
            'counts.*.class_name' => ['required_with:counts', 'string'],
            'counts.*.zone_name' => ['required_with:counts', 'string'],
            'counts.*.count' => ['required_with:counts', 'integer', 'min:0'],
            'safety_events' => ['nullable', 'array'],
            'safety_events.*.track_id' => ['required_with:safety_events', 'integer'],
            'safety_events.*.class_name' => ['required_with:safety_events', 'string'],
            'safety_events.*.zone_name' => ['required_with:safety_events', 'string'],
            'safety_events.*.video_time_seconds' => ['required_with:safety_events', 'numeric'],
            'safety_events.*.duration_seconds' => ['required_with:safety_events', 'numeric'],
            'safety_events.*.snapshot_path' => ['nullable', 'string'],
        ]);

        if ($validated['status'] === 'failed') {
            $video->update([
                'status' => 'failed',
                'error_message' => $validated['error_message'] ?? 'Terjadi kesalahan yang tidak diketahui.',
            ]);

            Log::warning("Video #{$video->id} gagal diproses", $validated);

            return response()->json(['ok' => true]);
        }

        foreach ($validated['counts'] ?? [] as $row) {
            CountResult::updateOrCreate(
                [
                    'video_id' => $video->id,
                    'class_name' => $row['class_name'],
                    'zone_name' => $row['zone_name'],
                ],
                ['count' => $row['count']]
            );
        }

        foreach ($validated['safety_events'] ?? [] as $row) {
            $video->safetyEvents()->create([
                'track_id' => $row['track_id'],
                'class_name' => $row['class_name'],
                'zone_name' => $row['zone_name'],
                'video_time_seconds' => $row['video_time_seconds'],
                'duration_seconds' => $row['duration_seconds'],
                'snapshot_path' => $row['snapshot_path'] ?? null,
            ]);
        }

        $video->update([
            'status' => 'completed',
            'annotated_path' => $validated['annotated_path'] ?? null,
            'processed_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
