<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\JplLocationController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VideoCallbackController;
use App\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
});

Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

// Rute khusus Administrator: Unggah Video, Lokasi JPL, Kelola Pengguna.
// User biasa yang mencoba membuka ini otomatis diarahkan ke Dashboard mereka
// (lihat App\Http\Middleware\EnsureUserIsAdmin).
Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/', [VideoController::class, 'index'])->name('videos.index');
    Route::post('/videos', [VideoController::class, 'store'])->name('videos.store');

    Route::get('/locations', [JplLocationController::class, 'index'])->name('locations.index');
    Route::post('/locations', [JplLocationController::class, 'store'])->name('locations.store');
    Route::post('/locations/{location}/cctv', [JplLocationController::class, 'updateCctv'])->name('locations.cctv');
    Route::delete('/locations/{location}', [JplLocationController::class, 'destroy'])->name('locations.destroy');

    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::post('/users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
    Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Rute yang bisa diakses admin maupun user biasa: melihat detail & status
// video (dipakai dari Dashboard Offline), dan kedua halaman Dashboard.
Route::middleware('auth')->group(function () {
    Route::get('/videos/{video}', [VideoController::class, 'show'])->name('videos.show');
    Route::get('/videos/{video}/status', [VideoController::class, 'status'])->name('videos.status');
    Route::get('/videos/{video}/annotated', [VideoController::class, 'annotated'])->name('videos.annotated');
    Route::get('/safety-events/{safetyEvent}/snapshot', [VideoController::class, 'snapshot'])->name('videos.snapshot');

    Route::get('/dashboard', function () {
        return redirect()->route('dashboard.online');
    })->name('dashboard.index');
    Route::get('/dashboard/online', [DashboardController::class, 'online'])->name('dashboard.online');
    Route::get('/dashboard/offline', [DashboardController::class, 'index'])->name('dashboard.offline');
    Route::get('/dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');
});

// Dipanggil oleh detector-service (Python) -- diamankan dengan header rahasia
// sendiri (X-Callback-Secret), BUKAN sesi login, karena dipanggil server-to-server.
Route::post('/api/videos/{video}/callback', [VideoCallbackController::class, 'store'])
    ->name('videos.callback');

Route::post('/api/videos/{video}/progress', [VideoCallbackController::class, 'progress'])
    ->name('videos.progress');
