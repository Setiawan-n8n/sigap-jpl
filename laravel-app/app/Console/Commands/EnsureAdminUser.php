<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Membuat atau memperbarui satu akun admin dari environment variable, supaya
 * setelah deploy (mis. lewat Coolify) langsung ada akun untuk login tanpa
 * perlu registrasi publik. Idempotent -- aman dijalankan setiap container start.
 */
class EnsureAdminUser extends Command
{
    protected $signature = 'sigap:ensure-admin';

    protected $description = 'Buat/perbarui akun admin dari ADMIN_EMAIL & ADMIN_PASSWORD';

    public function handle(): int
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            $this->warn('ADMIN_EMAIL / ADMIN_PASSWORD belum diset -- melewati pembuatan akun admin otomatis.');

            return self::SUCCESS;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => $password,
                'role' => 'admin',
                'is_active' => true,
            ]
        );

        $this->info("Akun admin siap: {$user->email}");

        return self::SUCCESS;
    }
}
