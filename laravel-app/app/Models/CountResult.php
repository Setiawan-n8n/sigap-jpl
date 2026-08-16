<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CountResult extends Model
{
    protected $fillable = [
        'video_id',
        'class_name',
        'zone_name',
        'count',
    ];

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }
}
