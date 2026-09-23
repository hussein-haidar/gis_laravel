<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Tambahkan kategori jenis tempat untuk filter publik dan bedakan
     * warna "Tempat Umum" agar tidak sama dengan warna wilayah (provinsi).
     */
    public function up(): void
    {
        DB::table('categories')->insertOrIgnore([
            ['name' => 'Wisata Sejarah', 'color' => '#78716c'],
            ['name' => 'Tempat Ibadah', 'color' => '#0ea5e9'],
            ['name' => 'Transportasi Umum', 'color' => '#f59e0b'],
            ['name' => 'Tempat Pendidikan', 'color' => '#14b8a6'],
        ]);

        DB::table('categories')
            ->where('name', 'Tempat Umum')
            ->update(['color' => '#e11d48']);
    }

    public function down(): void
    {
        DB::table('categories')
            ->whereIn('name', ['Wisata Sejarah', 'Tempat Ibadah', 'Transportasi Umum', 'Tempat Pendidikan'])
            ->delete();

        DB::table('categories')
            ->where('name', 'Tempat Umum')
            ->update(['color' => '#3b82f6']);
    }
};