<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Location extends Model
{
    protected $fillable = [
        'name',
        'description',
        'latitude',
        'longitude',
        'category_id',
        'wilayah_id',
        'photo',
        'geometry',
    ];

    protected $appends = ['photo_url', 'photo_display'];

    protected $casts = [
        'geometry' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function wilayah(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'wilayah_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(LocationPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function primaryPhoto(): HasMany
    {
        return $this->hasMany(LocationPhoto::class)->where('is_primary', true);
    }

    public function activityLogs(): HasMany
    {
        return $this->morphMany(ActivityLog::class, 'subject');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class)->latest();
    }

    public function approvedReviews(): HasMany
    {
        return $this->hasMany(Review::class)->approved()->latest();
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function getAvgRatingAttribute(): float
    {
        return round($this->approvedReviewsAvg ?? $this->approvedReviews()->avg('rating') ?? 0, 1);
    }

    public function getRatingCountAttribute(): int
    {
        return $this->approvedReviews()->count();
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (empty($this->photo)) {
            return null;
        }

        // existence dicek lewat disk, bukan file_exists(), supaya konsisten
        // dengan cleanup dan aman saat disk di-fake pada test.
        return Storage::disk('public')->exists($this->photo)
            ? asset('storage/'.$this->photo)
            : null;
    }

    /**
     * URL foto untuk ditampilkan. String kosong bila tidak ada foto asli —
     * viewer wajib menyembunyikan elemen gambar, bukan menggantinya dengan
     * placeholder, karena placeholder bukan gambar sebenarnya dari tempat itu.
     */
    public function getPhotoDisplayAttribute(): string
    {
        return $this->photo_url ?? '';
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
