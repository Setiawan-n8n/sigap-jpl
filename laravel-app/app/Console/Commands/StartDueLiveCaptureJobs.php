<?php

namespace App\Console\Commands;

use App\Jobs\ProcessLiveCaptureJob;
use App\Models\LiveCaptureJob;
use App\Models\Video;
use Illuminate\Console\Command;
use Throwable;

/**
 * Dijalankan tiap menit lewat Laravel Task Scheduling (lihat routes/console.php
 * dan php artisan schedule:work di docker/entrypoint.sh). Memulai jadwal
 * deteksi live yang waktu mulainya sudah tiba: membuat baris `videos` baru
 * (supaya hasilnya bisa ditampilkan lewat infrastruktur Video yang sudah
 * ada -- status polling, halaman detail, dsb) lalu memicu pemrosesan stream
 * CCTV secara langsung lewat ProcessLiveCaptureJob.
 */
class StartDueLiveCaptureJobs extends Command
{
    protected $signature = 'sigap:start-due-live-jobs';

    protected $description = 'Mulai jadwal deteksi live CCTV yang waktu mulainya sudah tiba, dan tandai gagal jadwal yang terlewat/macet.';

    public function handle(): int
    {
        $due = LiveCaptureJob::query()
            ->where('status', 'scheduled')
            ->where('start_at', '<=', now())
            ->where('finish_at', '>', now())
            ->get();

        foreach ($due as $job) {
            $location = $job->jplLocation;

            if (! $location || ! $location->cctv_url) {
                $job->update([
                    'status' => 'failed',
                    'error_message' => 'Lokasi JPL atau URL CCTV sudah tidak tersedia saat jadwal dimulai.',
                ]);
                $this->warn("Job #{$job->id} dibatalkan: lokasi/CCTV tidak lagi tersedia.");

                continue;
            }

            // PENTING: seluruh proses memulai job (buat Video/zona, tandai job
            // "running", lalu dispatch ke queue) dibungkus try/catch. Tanpa ini,
            // kalau salah satu langkah gagal (mis. tulis ke SQLite bentrok
            // karena container app & queue menulis bersamaan -- "database is
            // locked"), job bisa nyangkut permanen di status "running" dengan
            // video status "pending" selamanya, TANPA pernah ditandai gagal dan
            // tanpa pesan error yang bisa dilihat admin/user di Dashboard Online.
            $video = null;

            try {
                $video = Video::create([
                    'jpl_location_id' => $location->id,
                    'original_name' => "Live: {$location->name} ({$job->start_at->format('d M Y H:i')})",
                    'filename' => "live-{$job->id}",
                    'disk' => 'videos',
                    'recorded_at' => $job->start_at,
                    'status' => 'pending',
                ]);

                foreach ($job->zones as $zone) {
                    $video->zones()->create([
                        'name' => $zone['name'],
                        'type' => in_array($zone['type'] ?? 'direction', ['direction', 'danger'], true) ? $zone['type'] : 'direction',
                        'color' => $zone['color'] ?? '#22c55e',
                        'points' => $zone['points'],
                    ]);
                }

                $job->update(['video_id' => $video->id, 'status' => 'running']);

                ProcessLiveCaptureJob::dispatch($video, $job);

                $this->info("Job #{$job->id} dimulai -> video #{$video->id}.");
            } catch (Throwable $e) {
                report($e);

                $message = 'Gagal memulai proses live: '.$e->getMessage();

                $job->update(['status' => 'failed', 'error_message' => $message]);
                $video?->update(['status' => 'failed', 'error_message' => $message]);

                $this->error("Job #{$job->id} gagal dimulai: {$e->getMessage()}");
            }
        }

        // Jadwal yang sudah lewat waktu selesainya tapi tidak sempat dimulai
        // sama sekali (mis. server/queue mati saat waktu mulai tiba) --
        // tandai gagal daripada dibiarkan menggantung selamanya di status
        // "scheduled".
        $missed = LiveCaptureJob::query()
            ->where('status', 'scheduled')
            ->where('finish_at', '<=', now())
            ->get();

        foreach ($missed as $job) {
            $job->update([
                'status' => 'failed',
                'error_message' => 'Jadwal terlewat tanpa sempat dimulai (kemungkinan server tidak aktif saat waktu mulai).',
            ]);
            $this->warn("Job #{$job->id} ditandai gagal: jadwal terlewat.");
        }

        // Jaring pengaman: job yang statusnya "running" tapi videonya tidak
        // pernah beranjak dari "pending" (proses gagal dimulai di sisi
        // worker/detector-service tanpa sempat tertangkap try/catch di atas --
        // mis. karena versi kode lama sebelum perbaikan ini), atau macet tanpa
        // hasil sampai lama setelah jadwal semestinya selesai. Tanpa ini job
        // seperti itu akan nyangkut selamanya di Dashboard Online dan
        // menghalangi jadwal berikutnya untuk tampil sebagai "job paling
        // relevan" (lihat DashboardController::online).
        $stuckNeverStarted = LiveCaptureJob::query()
            ->where('status', 'running')
            ->where('start_at', '<=', now()->subMinutes(5))
            ->whereHas('video', fn ($q) => $q->where('status', 'pending'))
            ->with('video')
            ->get();

        $stuckAfterFinish = LiveCaptureJob::query()
            ->where('status', 'running')
            ->where('finish_at', '<=', now()->subMinutes(15))
            ->whereHas('video', fn ($q) => $q->whereIn('status', ['pending', 'processing']))
            ->with('video')
            ->get();

        foreach ($stuckNeverStarted->merge($stuckAfterFinish)->unique('id') as $job) {
            $message = 'Proses live macet/tidak pernah selesai (kemungkinan gangguan sistem saat itu). Silakan jadwalkan ulang.';

            $job->update(['status' => 'failed', 'error_message' => $message]);
            $job->video?->update(['status' => 'failed', 'error_message' => $message]);

            $this->warn("Job #{$job->id} ditandai gagal: macet tanpa hasil.");
        }

        return self::SUCCESS;
    }
}