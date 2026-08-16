<?php

namespace App\Jobs;

use App\Models\Video;
use App\Services\DetectorClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public Video $video)
    {
    }

    /**
     * Job ini hanya memicu detector-service (fire-and-forget via HTTP).
     * Hasil deteksi sebenarnya dikirim balik secara async lewat callback,
     * jadi job ini selesai dengan cepat dan tidak memblokir worker lama-lama.
     */
    public function handle(DetectorClient $detector): void
    {
        $this->video->update(['status' => 'processing']);

        try {
            $detector->dispatch($this->video);
        } catch (Throwable $e) {
            $this->video->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
