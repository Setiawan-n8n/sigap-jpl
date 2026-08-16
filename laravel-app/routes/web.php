<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JplLocationController;
use App\Http\Controllers\VideoCallbackController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('videos.index');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');
    Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
    Route::get('/videos/{video}/annotated', [VideoController::class, 'annotated'])->name('videos.annotated');
    Route::get('/safety-events/{safetyEvent}/snapshot', [VideoController::class, 'snapshot'])->name('videos.snapshot');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.index');
    Route::get('/dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');

    Route::get('/locations', [JplLocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [JplLocationController::class, 'store'])->name('locations.store');
});

// Dipanggil oleh detector-service (Python) -- diamankan dengan header rahasia
// sendiri (X-Callback-Secret), BUKAN sesi login, karena dipanggil server-to-server.
Route::post('/api/videos/{video}/callback', [VideoCallbackController::class, 'store'])
    ->name('videos.callback');
