<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $locations = [
            [
                'name' => 'Monumen Nasional (Monas)',
                'description' => 'Menara ikonik di pusat Kota Jakarta.',
                'latitude' => -6.1753924,
                'longitude' => 106.8271528,
                'category' => 'Tempat Umum',
            ],
            [
                'name' => 'Borobudur',
                'description' => 'Candi Buddha terbesar di dunia, Magelang.',
                'latitude' => -7.6079393,
                'longitude' => 110.2037168,
                'category' => 'Wisata Budaya',
            ],
            [
                'name' => 'Prambanan',
                'description' => 'Kompleks candi Hindu di Yogyakarta.',
                'latitude' => -7.7520166,
                'longitude' => 110.4914635,
                'category' => 'Wisata Budaya',
            ],
            [
                'name' => 'Pantai Kuta',
                'description' => 'Pantai populer di Bali.',
                'latitude' => -8.7184636,
                'longitude' => 115.1688576,
                'category' => 'Wisata Alam',
            ],
            [
                'name' => 'Danau Toba',
                'description' => 'Danau vulkanik terbesar di Sumatera Utara.',
                'latitude' => 2.6789986,
                'longitude' => 98.8812882,
                'category' => 'Wisata Alam',
            ],
            [
                'name' => 'Raja Ampat',
                'description' => 'Kepulauan dengan keindahan bawah laut di Papua Barat.',
                'latitude' => -0.6330368,
                'longitude' => 130.4951326,
                'category' => 'Wisata Alam',
            ],
            [
                'name' => 'Masjid Istiqlal',
                'description' => 'Masjid terbesar di Asia Tenggara, Jakarta.',
                'latitude' => -6.170049,
                'longitude' => 106.831073,
                'category' => 'Wisata Religi',
            ],
        ];

        foreach ($locations as $location) {
            $category = Category::where('name', $location['category'])->first();

            Location::updateOrCreate(
                ['name' => $location['name']],
                [
                    'description' => $location['description'],
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                    'category_id' => $category?->id,
                ]
            );
        }
    }
}
