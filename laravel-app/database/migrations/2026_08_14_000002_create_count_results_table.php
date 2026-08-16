<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('class_name'); // car, motorcycle, bicycle, person, bus, truck
            $table->string('zone_name'); // nama zona arah, mis. "Rel Kiri", "Rel Kanan", atau nama bebas dari user
            $table->unsignedInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['video_id', 'class_name', 'zone_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_results');
    }
};
