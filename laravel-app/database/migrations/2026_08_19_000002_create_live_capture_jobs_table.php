<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal deteksi live: Administrator menentukan rentang waktu mulai/selesai
 * di masa depan untuk sebuah lokasi JPL, beserta zona yang digambar ulang
 * dari snapshot CCTV saat penjadwalan dibuat. Saat waktu mulai tiba,
 * Artisan command sigap:start-due-live-jobs (dijadwalkan tiap menit lewat
 * routes/console.php) membuat baris `videos` baru untuk job ini dan memicu
 * detector-service untuk memproses stream CCTV SECARA LANGSUNG (bukan
 * merekam ke file dulu) lewat endpoint /process-live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_capture_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jpl_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cctv_url');
            $table->json('zones');
            $table->timestamp('start_at');
            $table->timestamp('finish_at');
            // scheduled -> running -> completed|failed, atau dibatalkan -> canceled
            $table->string('status')->default('scheduled');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_capture_jobs');
    }
};
