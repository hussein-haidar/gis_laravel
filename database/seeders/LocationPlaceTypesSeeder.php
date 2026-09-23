<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Mengisi kategori jenis tempat dengan tempat-tempat nyata yang tepat,
 * agar dropdown filter tidak pernah mengarah ke daftar kosong.
 */
class LocationPlaceTypesSeeder extends Seeder
{
    public function run(): void
    {
        // Kategorikan ulang objek wisata ikonik agar masuk kategori yang tepat
        $this->setCategory('Monumen Nasional (Monas)', 'Wisata Sejarah');
        $this->setCategory('Prambanan', 'Wisata Sejarah');

        $places = [
            [
                'name' => 'Taman Mini Indonesia Indah',
                'category' => 'Wisata Budaya',
                'latitude' => -6.3025,
                'longitude' => 106.8940,
                'description' => 'Taman budaya yang menampilkan miniatur rumah adat dan budaya seluruh provinsi Indonesia di Jakarta Timur.',
            ],
            [
                'name' => 'Museum Nasional Indonesia',
                'category' => 'Wisata Budaya',
                'latitude' => -6.1766,
                'longitude' => 106.8220,
                'description' => 'Museum nasional di Jakarta Pusat yang menyimpan koleksi budaya dan sejarah Nusantara, dikenal juga sebagai Museum Gajah.',
            ],
            [
                'name' => 'Kota Tua Jakarta',
                'category' => 'Wisata Sejarah',
                'latitude' => -6.1347,
                'longitude' => 106.8189,
                'description' => 'Kawasan bersejarah Jakarta dengan gedung-gedung kolonial Belanda, museum, dan plaza batavia yang ikonik.',
            ],
            [
                'name' => 'Jalan Malioboro',
                'category' => 'Wisata Kuliner',
                'latitude' => -7.7930,
                'longitude' => 110.3655,
                'description' => 'Jalan legendaris di jantung Yogyakarta yang ramai oleh kuliner khas, angkringan, dan pusat oleh-oleh.',
            ],
            [
                'name' => 'Pasar Beringharjo',
                'category' => 'Wisata Kuliner',
                'latitude' => -7.7983,
                'longitude' => 110.3665,
                'description' => 'Pasar tradisional terbesar di Yogyakarta yang terkenal dengan aneka kuliner, batik, dan jajanan khas.',
            ],
            [
                'name' => 'Lapangan Banteng Jakarta',
                'category' => 'Tempat Umum',
                'latitude' => -6.1752,
                'longitude' => 106.8357,
                'description' => 'Lapangan terbuka hijau ikonik di Jakarta Pusat dengan Monumen Pembebasan Irian Barat dan air mancur.',
            ],
            [
                'name' => 'Alun-Alun Utara Yogyakarta',
                'category' => 'Tempat Umum',
                'latitude' => -7.7977,
                'longitude' => 110.3665,
                'description' => 'Alun-alun bersejarah di depan Keraton Yogyakarta, tempat berkumpul warga dengan pohon beringin kembar.',
            ],
            [
                'name' => 'Pura Besakih',
                'category' => 'Tempat Ibadah',
                'latitude' => -8.3769,
                'longitude' => 115.4518,
                'description' => 'Pura terbesar dan termegah di Bali yang terletak di lereng Gunung Agung, pusat ibadah umat Hindu.',
            ],
            [
                'name' => 'Gereja Katedral Jakarta',
                'category' => 'Tempat Ibadah',
                'latitude' => -6.1690,
                'longitude' => 106.8336,
                'description' => 'Katedral bersejarah bergaya neo-gotik di Jakarta Pusat, salah satu tempat ibadah umat Katolik tertua di Jakarta.',
            ],
            [
                'name' => 'Bandara Internasional Soekarno-Hatta',
                'category' => 'Transportasi Umum',
                'latitude' => -6.1256,
                'longitude' => 106.6559,
                'description' => 'Bandar udara utama Indonesia di Tangerang yang melayani penerbangan domestik dan internasional.',
            ],
            [
                'name' => 'Stasiun Gambir Jakarta',
                'category' => 'Transportasi Umum',
                'latitude' => -6.1760,
                'longitude' => 106.8300,
                'description' => 'Stasiun kereta api utama di Jakarta Pusat, pintu gerbang perjalanan kereta api ke berbagai kota di Jawa.',
            ],
            [
                'name' => 'Universitas Gadjah Mada',
                'category' => 'Tempat Pendidikan',
                'latitude' => -7.7715,
                'longitude' => 110.3775,
                'description' => 'Perguruan tinggi tertua dan terkemuka di Indonesia yang terletak di Yogyakarta.',
            ],
            [
                'name' => 'Institut Teknologi Bandung',
                'category' => 'Tempat Pendidikan',
                'latitude' => -6.8913,
                'longitude' => 107.6103,
                'description' => 'Perguruan tinggi teknologi terbaik di Indonesia yang terletak di Bandung, Jawa Barat.',
            ],
        ];

        foreach ($places as $place) {
            $category = Category::where('name', $place['category'])->first();
            if (!$category) {
                continue;
            }

            Location::updateOrCreate(
                ['name' => $place['name']],
                [
                    'description' => $place['description'],
                    'latitude' => $place['latitude'],
                    'longitude' => $place['longitude'],
                    'category_id' => $category->id,
                ]
            );
        }

        $this->command?->info('Seeder kategori jenis tempat selesai.');
    }

    protected function setCategory(string $name, string $categoryName): void
    {
        $category = Category::where('name', $categoryName)->first();
        $location = Location::where('name', $name)->first();

        if ($category && $location) {
            $location->update(['category_id' => $category->id]);
        }
    }
}