<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return $query->whereIn('name', self::PLACE_TYPES);
    }

    public static function placeTypes(): array
    {
        return self::PLACE_TYPES;
    }
}
