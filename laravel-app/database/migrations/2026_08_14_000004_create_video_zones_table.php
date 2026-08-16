<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // nama bebas dari user, mis. "Rel Kiri", "Area Bahaya Perlintasan"
            $table->enum('type', ['direction', 'danger'])->default('direction');
            $table->string('color', 7)->default('#22c55e'); // hex warna untuk digambar di canvas & video hasil
            $table->json('points'); // array titik poligon ternormalisasi [[x,y], ...] (0..1 relatif thd frame)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_zones');
    }
};
