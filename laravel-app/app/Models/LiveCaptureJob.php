<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LiveCaptureJob extends Model
{
    protected $fillable = [
        'jpl_location_id',
        'video_id',
        'cctv_url',
        'zones',
        'start_at',
        'finish_at',
        'status',
        'error_message',
    ];

    protected $casts = [
        'zones' => 'array',
        'start_at' => 'datetime',
        'finish_at' => 'datetime',
    ];

    public function jplLocation(): BelongsTo
    {
        return $this->belongsTo(JplLocation::class);
    }

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    public function isCancelable(): bool
    {
        return $this->status === 'scheduled';
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'scheduled' => 'Terjadwal',
            'running' => 'Berjalan',
            'completed' => 'Selesai',
            'failed' => 'Gagal',
            'canceled' => 'Dibatalkan',
            default => $this->status,
        };
    }
}
