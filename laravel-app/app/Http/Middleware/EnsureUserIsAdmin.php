<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membatasi halaman Unggah Video, Lokasi JPL, dan Kelola Pengguna hanya untuk
 * role 'admin'. Dipasang SETELAH middleware 'auth', jadi saat middleware ini
 * jalan, user pasti sudah login.
 *
 * Kalau user biasa (role 'user') mencoba membuka halaman admin, kita arahkan
 * balik ke Dashboard mereka (bukan tampilkan halaman 403 polos) supaya
 * pengalamannya tetap mulus -- ini juga otomatis menangani kasus user biasa
 * membuka "/" (yang sebenarnya adalah halaman Unggah Video khusus admin).
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->isAdmin()) {
            return redirect()
                ->route('dashboard.online')
                ->with('error', 'Anda tidak memiliki akses ke halaman tersebut. Hubungi Administrator jika diperlukan.');
        }

        return $next($request);
    }
}
