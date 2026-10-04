<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /**
     * Kategori jenis tempat yang dipakai untuk filter publik.
     * Nama provinsi/wilayah hasil sinkronisasi GIS tidak ikut
     * karena membingungkan ketika bercampur dengan jenis tempat.
     */
    public const PLACE_TYPES = [
        'Wisata Alam',
        'Wisata Budaya',
        'Wisata Religi',
        'Wisata Sejarah',
        'Wisata Kuliner',
        'Tempat Umum',
        'Tempat Ibadah',
        'Transportasi Umum',
        'Tempat Pendidikan',
    ];

    protected $fillable = [
        'name',
        'color',
        'description',
        'sort_order',
        'icon',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function scopeRoot($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeForPublicFilter($query)
    {
        return $query->whereIn($query->qualifyColumn('name'), self::PLACE_TYPES);
    }

    /**
     * Kategori jenis tempat yang dikelola manual di menu "Kelola Kategori".
     * Nama wilayah/provinsi hasil sinkronisasi GIS tidak ikut.
     */
    public function scopePlaces($query)
    {
        return $query->whereIn($query->qualifyColumn('name'), self::PLACE_TYPES);
    }

    /**
     * Kategori wilayah/provinsi. Baris ini dibuat otomatis oleh sinkronisasi
     * GIS (GisDataSyncService::resolveCategory) dan hanya boleh dihapus bila
     * tidak dipakai lokasi, karena label provinsi di peta ikut memakainya.
     */
    public function scopeProvinces($query)
    {
        return $query->whereNotIn($query->qualifyColumn('name'), self::PLACE_TYPES);
    }

    public function scopeSearch($query, ?string $search)
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    public static function placeTypes(): array
    {
        return self::PLACE_TYPES;
    }
}
