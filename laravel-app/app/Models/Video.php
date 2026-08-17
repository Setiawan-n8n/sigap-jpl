<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Video extends Model
{
    use HasFactory;

    protected $fillable = [
        'jpl_location_id',
        'original_name',
        'filename',
        'disk',
        'width',
        'height',
        'recorded_at',
        'status',
        'progress',
        'annotated_path',
        'error_message',
        'processed_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function jplLocation(): BelongsTo
    {
        return $this->belongsTo(JplLocation::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(VideoZone::class);
    }

    public function countResults(): HasMany
    {
        return $this->hasMany(CountResult::class);
    }

    public function safetyEvents(): HasMany
    {
        return $this->hasMany(SafetyEvent::class);
    }

    /**
     * Kelompokkan hasil hitung per kelas objek & zona: ['car' => ['Rel Kiri' => 3, 'Rel Kanan' => 1], ...]
     */
    public function totalsByClass(): array
    {
        $totals = [];

        foreach ($this->countResults as $result) {
            $totals[$result->class_name][$result->zone_name] = $result->count;
        }

        return $totals;
    }

    /**
     * Nama-nama zona unik (dari video_zones, dipakai untuk header tabel hasil).
     */
    public function zoneNames(): array
    {
        return $this->zones->pluck('name')->unique()->values()->all();
    }
}
