<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Mulai jadwal deteksi live CCTV yang waktunya sudah tiba. Dijalankan oleh
// `php artisan schedule:work`, yang di-start berdampingan dengan queue
// worker di container "queue" -- lihat docker/entrypoint.sh.
Schedule::command('sigap:start-due-live-jobs')->everyMinute()->withoutOverlapping();
