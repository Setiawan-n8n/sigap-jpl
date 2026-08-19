<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Aplikasi berjalan di belakang reverse proxy Traefik (Coolify) yang
        // menangani TLS -- koneksi Traefik ke container ini sendiri berupa
        // HTTP biasa di jaringan Docker internal. Tanpa trustProxies, Laravel
        // tidak percaya header X-Forwarded-Proto dari Traefik, sehingga
        // mengira semua request HTTP biasa -- akibatnya semua URL yang
        // di-generate (form action, redirect, asset) memakai skema "http://"
        // walau pengunjung sebenarnya mengakses lewat HTTPS. Ini yang
        // menyebabkan peringatan "not secure" saat submit form dan koneksi
        // reset (domain ini hanya dikonfigurasi untuk HTTPS di Traefik).
        // Trust "*" aman di sini karena Traefik adalah satu-satunya proxy di
        // depan container, semuanya di jaringan privat VPS yang sama.
        $middleware->trustProxies(at: '*');

        // Endpoint callback dari Python detector service dikecualikan dari CSRF
        // karena dipanggil server-to-server, bukan dari form browser.
        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Alias 'admin' -- membatasi rute khusus Administrator (Unggah Video,
        // Lokasi JPL, Kelola Pengguna). Lihat App\Http\Middleware\EnsureUserIsAdmin.
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
