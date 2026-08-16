<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jpl_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // mis. JPL-013, sesuai kode resmi perlintasan
            $table->string('name'); // mis. "Putri Hijau - Perintis, Medan"
            $table->string('km_position')->nullable(); // posisi KM jalur rel, opsional
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jpl_locations');
    }
};
