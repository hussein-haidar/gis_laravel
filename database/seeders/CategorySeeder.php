<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Wisata Alam', 'color' => '#22c55e', 'description' => 'Tempat wisata berbasis alam.'],
            ['name' => 'Wisata Budaya', 'color' => '#eab308', 'description' => 'Situs budaya, candi, dan sejarah.'],
            ['name' => 'Wisata Kuliner', 'color' => '#f97316', 'description' => 'Pusat kuliner dan makanan.'],
            ['name' => 'Wisata Religi', 'color' => '#8b5cf6', 'description' => 'Tempat ibadah dan wisata religi.'],
            ['name' => 'Tempat Umum', 'color' => '#3b82f6', 'description' => 'Fasilitas dan tempat umum.'],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['name' => $category['name']],
                $category
            );
        }
    }
}
