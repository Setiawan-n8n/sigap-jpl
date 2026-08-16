<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('safety_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('track_id');
            $table->string('class_name');
            $table->string('zone_name'); // nama zona bahaya (video_zones.name) tempat kejadian
            $table->float('video_time_seconds'); // detik ke berapa dalam video saat objek mulai dianggap berbahaya
            $table->float('duration_seconds'); // berapa lama objek diam/berada di zona bahaya
            $table->string('snapshot_path')->nullable(); // frame bukti (jpg) di disk 'videos'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('safety_events');
    }
};
