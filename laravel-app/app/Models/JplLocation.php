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
    ];

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
    }

    /**
     * Jumlah total safety event di semua video pada lokasi ini (untuk ringkasan dashboard).
     */
    public function safetyEventCount(): int
    {
        return SafetyEvent::whereIn('video_id', $this->videos()->pluck('id'))->count();
    }
}
