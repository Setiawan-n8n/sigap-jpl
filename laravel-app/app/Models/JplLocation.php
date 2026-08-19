<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JplLocation extends Model
{
    protected $fillable = [
        'code',
        'name',
        'km_position',
        'description',
        'cctv_url',
        'cctv_added_at',
    ];

    protected $casts = [
        'cctv_added_at' => 'datetime',
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    public function liveCaptureJobs(): HasMany
    {
        return $this->hasMany(LiveCaptureJob::class);
    }

    /**
     * Lokasi ini muncul di Dashboard Online begitu Administrator mengisi URL CCTV.
     */
    public function hasOnlineCctv(): bool
    {
        return ! empty($this->cctv_url);
    }

    /**
     * Jumlah total safety event di semua video pada lokasi ini (untuk ringkasan dashboard).
     */
    public function safetyEventCount(): int
    {
        return SafetyEvent::whereIn('video_id', $this->videos()->pluck('id'))->count();
    }
}
