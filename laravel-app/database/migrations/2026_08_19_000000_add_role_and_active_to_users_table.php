<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'admin' bisa mengelola lokasi JPL, mengunggah video, dan mengelola
            // pengguna. 'user' hanya bisa melihat menu Dashboard (Online & Offline).
            $table->string('role')->default('user')->after('password');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // Akun yang sudah ada sebelum migrasi ini (mis. akun admin default yang
        // dibuat lewat sigap:ensure-admin) dianggap admin, supaya tidak ada yang
        // tiba-tiba terkunci dari halaman yang sebelumnya bisa mereka akses.
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
