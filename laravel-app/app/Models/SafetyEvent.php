<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SafetyEvent extends Model
{
    protected $fillable = [
        'video_id',
        'track_id',
        'class_name',
        'zone_name',
        'video_time_seconds',
        'duration_seconds',
        'snapshot_path',
    ];

    protected $casts = [
        'video_time_seconds' => 'float',
        'duration_seconds' => 'float',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
