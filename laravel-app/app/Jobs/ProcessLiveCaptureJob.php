<?php

namespace App\Jobs;

use App\Models\LiveCaptureJob;
use App\Models\Video;
use App\Services\DetectorClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sama seperti ProcessVideoJob (fire-and-forget ke detector-service, hasil
 * datang belakangan lewat callback), tapi memicu endpoint /process-live
 * untuk memproses stream CCTV secara langsung selama rentang waktu $job.
 */
class ProcessLiveCaptureJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public Video $video, public LiveCaptureJob $job)
    {
    }

    public function handle(DetectorClient $detector): void
    {
        $this->video->update(['status' => 'processing']);

        try {
            $detector->dispatchLive($this->video, $this->job);
        } catch (Throwable $e) {
            $this->video->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $this->job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
