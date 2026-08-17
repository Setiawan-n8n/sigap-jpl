<?php

namespace App\Services;

use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Klien HTTP tipis untuk menghubungi detector-service (FastAPI + YOLOv8).
 */
class DetectorClient
{
    protected string $baseUrl;

    protected string $callbackSecret;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('services.detector.url'), '/');
        $this->callbackSecret = (string) config('services.detector.secret');
    }

    /**
     * Minta detector-service memproses video secara asynchronous.
     * Detector akan mengirim hasilnya kembali via callback HTTP setelah selesai.
     */
    public function dispatch(Video $video): void
    {
        $response = Http::timeout(15)->post("{$this->baseUrl}/process", [
            'job_id' => $video->id,
            'video_path' => "/data/videos/{$video->filename}",
            'location_name' => $video->jplLocation?->name ?? 'JPL',
            'recorded_at' => optional($video->recorded_at)->toIso8601String(),
            'zones' => $video->zones->map(fn ($zone) => [
                'name' => $zone->name,
                'type' => $zone->type,
                'points' => $zone->points,
                'color' => $zone->color,
            ])->values()->all(),
            'classes' => ['person', 'bicycle', 'car', 'motorcycle', 'bus', 'truck'],
            'danger_dwell_seconds' => (float) config('services.detector.danger_dwell_seconds', 5),
            'callback_url' => URL::to("/api/videos/{$video->id}/callback"),
            'progress_url' => URL::to("/api/videos/{$video->id}/progress"),
            'callback_secret' => $this->callbackSecret,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Gagal menghubungi detector service: '.$response->status().' '.$response->body()
            );
        }
    }
}
