<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoZone extends Model
{
    protected $fillable = [
        'video_id',
        'name',
        'type',
        'color',
        'points',
    ];

    protected $casts = [
        'points' => 'array',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
