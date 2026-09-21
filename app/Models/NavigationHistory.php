<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NavigationHistory extends Model
{
    protected $fillable = [
        'user_id',
        'origin_name',
        'origin_lat',
        'origin_lng',
        'dest_name',
        'dest_lat',
        'dest_lng',
        'vehicle',
        'profile',
        'distance_km',
        'duration_sec',
        'travel_seconds',
        'route_geometry',
        'steps',
        'status',
        'started_at',
        'ended_at',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float',
            'origin_lng' => 'float',
            'dest_lat' => 'float',
            'dest_lng' => 'float',
            'distance_km' => 'float',
            'duration_sec' => 'integer',
            'travel_seconds' => 'integer',
            'route_geometry' => 'array',
            'steps' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isFinished(): bool
    {
        return $this->status === 'finished';
    }
}