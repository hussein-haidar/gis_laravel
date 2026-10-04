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
        'photo_source_title',
        'photo_source_url',
        'photo_source_provider',
        'photo_source_lat',
        'photo_source_lon',
        'photo_source_distance_m',
        'photo_fetched_at',
        'photo_review_status',
        'photo_reviewed_at',
        'photo_reviewed_by',
        'photo_review_note',
        'geometry',
    ];

    protected $appends = ['photo_url', 'photo_display', 'photo_raw_url'];

    protected $casts = [
        'geometry' => 'array',
        'photo_reviewed_at' => 'datetime',
    ];

    /** Status persetujuan foto: pending, approved, rejected. */
    public const PHOTO_PENDING = 'pending';

    public const PHOTO_APPROVED = 'approved';

    public const PHOTO_REJECTED = 'rejected';

    /**
     * Lokasi yang boleh tampil publik.
     *
     * Semua foto wajib disetujui lebih dulu, jadi lokasi yang fotonya belum
     * ada atau masih menunggu tidak boleh muncul sama sekali di situs:
     * tidak ada marker, tidak ada koordinat, tidak ada nama di daftar.
     * Lokasi seperti ini hanya terlihat oleh admin di halaman verifikasi.
     */
    public function scopePubliclyVisible($query)
    {
        return $query->whereNotNull('photo')
            ->where('photo_review_status', self::PHOTO_APPROVED);
    }

    /**
     * Foto yang sudah disetujui admin dan belum digantikan sumber baru.
     * Lokasi tanpa foto selalu pending, karena tidak ada yang perlu disetujui.
     */
    public function scopeAwaitingPhotoReview($query)
    {
        return $query->whereNotNull('photo')
            ->where(function ($q) {
                $q->whereNull('photo_review_status')
                    ->orWhere('photo_review_status', self::PHOTO_PENDING);
            });
    }

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

        // Foto hasil fetch yang belum disetujui admin tidak ditampilkan di
        // situs: semua foto wajib disetujui, jadi foto yang belum
        // diperiksa tidak boleh ikut tayang. Admin tetap bisa melihatnya
        // lewat path photo untuk keperluan review.
        if ($this->photo_review_status === self::PHOTO_PENDING) {
            return null;
        }

        // existence dicek lewat disk, bukan file_exists(), supaya konsisten
        // dengan cleanup dan aman saat disk di-fake pada test.
        return Storage::disk('public')->exists($this->photo)
            ? asset('storage/'.$this->photo)
            : null;
    }

    /**
     * URL foto apa adanya, tanpa ikut aturan persetujuan.
     *
     * Dipakai halaman admin: saat review, foto yang belum disetujui justru
     * harus terlihat agar bisa dinilai.
     */
    public function getPhotoRawUrlAttribute(): ?string
    {
        if (empty($this->photo)) {
            return null;
        }

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
