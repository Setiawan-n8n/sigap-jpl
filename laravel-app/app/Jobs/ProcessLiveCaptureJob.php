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

    // PENTING: properti ini SENGAJA dinamai $liveCaptureJob, BUKAN $job.
    // Trait InteractsWithQueue (dipakai di atas) sudah mendeklarasikan
    // properti publik $job miliknya sendiri (instance queue job internal
    // Laravel). Kalau properti milik class ini juga dinamai $job, PHP akan
    // gagal MENG-COMPOSE class ini sama sekali (fatal error "define the
    // same property ($job) ... definition differs and is considered
    // incompatible") -- class-nya tidak akan pernah bisa dimuat, dan
    // setiap kali di-dispatch ke queue, worker akan crash instan tanpa
    // sempat masuk try/catch mana pun, sehingga job nyangkut permanen di
    // status running/pending. Inilah penyebab sebenarnya deteksi live
    // tidak pernah berjalan sama sekali.
    public function __construct(public Video $video, public LiveCaptureJob $liveCaptureJob)
    {
    }

    public function handle(DetectorClient $detector): void
    {
        $this->video->update(['status' => 'processing']);

        try {
            $detector->dispatchLive($this->video, $this->liveCaptureJob);
        } catch (Throwable $e) {
            $this->video->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            $this->liveCaptureJob->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}