<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    protected $fillable = [
        'name',
        'description',
        'latitude',
        'longitude',
        'category_id',
        'photo',
        'geometry',
    ];

    protected $appends = ['photo_url'];

    protected $casts = [
        'geometry' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo ? asset('storage/' . $this->photo) : null;
    }

    public function getGeoJsonGeometryAttribute(): array
    {
        if ($this->geometry && isset($this->geometry['type'])) {
            return $this->geometry;
        }

        return [
            'type' => 'Point',
            'coordinates' => [
                (float) $this->longitude,
                (float) $this->latitude,
            ],
        ];
    }

    public function distanceTo(self $other): float
    {
        $earthRadiusKm = 6371.0;

        $latFrom = deg2rad((float) $this->latitude);
        $lngFrom = deg2rad((float) $this->longitude);
        $latTo = deg2rad((float) $other->latitude);
        $lngTo = deg2rad((float) $other->longitude);

        $latDelta = $latTo - $latFrom;
        $lngDelta = $lngTo - $lngFrom;

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return $earthRadiusKm * 2 * asin(sqrt($a));
    }

    public function scopeWithinRadius($query, float $lat, float $lng, float $radiusKm)
    {
        return $query->selectRaw('*, (
            6371 * acos(
                cos(radians(?)) * cos(radians(latitude)) *
                cos(radians(longitude) - radians(?)) +
                sin(radians(?)) * sin(radians(latitude))
            )
        ) AS distance', [$lat, $lng, $lat])
            ->having('distance', '<=', $radiusKm)
            ->orderBy('distance');
    }
}
