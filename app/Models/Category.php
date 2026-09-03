<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = [
        'name',
        'color',
        'description',
    ];

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
