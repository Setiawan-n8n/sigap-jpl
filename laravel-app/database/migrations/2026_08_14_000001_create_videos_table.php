<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jpl_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('original_name');
            $table->string('filename');
            $table->string('disk')->default('videos');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('recorded_at')->nullable(); // waktu awal rekaman, untuk overlay timestamp & laporan historis
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->string('annotated_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('videos');
    }
};
