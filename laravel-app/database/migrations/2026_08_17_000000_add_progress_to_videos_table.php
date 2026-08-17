<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Persentase 0-100, diperbarui berkala oleh detector-service selama
            // pemrosesan supaya UI bisa menampilkan progress bar, bukan cuma
            // teks statis "processing".
            $table->unsignedTinyInteger('progress')->default(0)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('progress');
        });
    }
};
