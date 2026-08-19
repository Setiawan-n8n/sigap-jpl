<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jpl_locations', function (Blueprint $table) {
            // Diisi Administrator lewat menu Lokasi JPL. Begitu terisi, lokasi ini
            // otomatis muncul di Dashboard Online untuk role 'user'. Tautan yang
            // didukung: HLS (.m3u8), MP4 langsung, atau URL embed/iframe. Stream
            // RTSP mentah perlu di-relay ke HLS/MP4 dulu (browser tidak bisa
            // memutar RTSP langsung).
            $table->string('cctv_url')->nullable()->after('description');
            $table->timestamp('cctv_added_at')->nullable()->after('cctv_url');
        });
    }

    public function down(): void
    {
        Schema::table('jpl_locations', function (Blueprint $table) {
            $table->dropColumn(['cctv_url', 'cctv_added_at']);
        });
    }
};
