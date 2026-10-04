<?php

namespace App\Services\Ai;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Support\Collection;

class PetaContextService
{
    /**
     * Kata umum Indonesia/Inggris yang tidak membantu sebagai kata kunci pencarian.
     */
    protected array $stopwords = [
        'ada', 'adalah', 'apa', 'apakah', 'atau', 'bagaimana', 'bagaimanakah', 'bisa',
        'boleh', 'buat', 'bantu', 'carikan', 'cari', 'dalam', 'dan', 'dari', 'dengan',
        'di', 'dia', 'dua', 'goto', 'hanya', 'hari', 'hingga', 'how', 'i', 'ingin',
        'ini', 'itu', 'juga', 'kalau', 'kapan', 'kata', 'ke', 'kemale', 'kenapa',
        'kocate', 'kulian', 'lagi', 'lain', 'lokasi', 'maka', 'mampu', 'mau',
        'mereka', 'namun', 'near', 'nearer', 'oke', 'orang', 'pada', 'para', 'please',
        'punya', 'saja', 'satu', 'saya', 'sebuah', 'seperti', 'serta', 'sudah',
        'supaya', 'tadi', 'tahu', 'tanya', 'tentang', 'terdekat', 'tersebut', 'tolong',
        'untuk', 'yang',
        'the', 'is', 'are', 'a', 'an', 'of', 'to', 'in', 'on', 'at', 'for', 'and',
        'or', 'can', 'you', 'me', 'my', 'find', 'show', 'please', 'what', 'where',
        'nearby', 'closest', 'tell', 'about', 'with', 'do', 'does', 'have', 'has',
    ];

    /**
     * Ringkasan dataset peta: jumlah lokasi per kategori dan lokasi terpopuler.
     * Selalu disertakan supaya AI tahu skala dan cakupan datanya.
     */
    public function datasetSummary(): string
    {
        $perKategori = Location::query()
            ->publiclyVisible()
            ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES))
            ->selectRaw('category_id, COUNT(*) AS jml')
            ->groupBy('category_id')
            ->pluck('jml', 'category_id');

        $namaKategori = Category::whereIn('id', $perKategori->keys())->pluck('name', 'id');

        $baris = [];
        foreach ($perKategori as $id => $jml) {
            $baris[] = ($namaKategori[$id] ?? 'Lainnya').': '.$jml;
        }
        rsort($baris);

        return 'Dataset peta: total '.array_sum($perKategori->toArray())
            .' titik lokasi. Sebaran per kategori - '.implode(', ', $baris).'.';
    }

    /**
     * Kumpulkan potongan data peta yang relevan dengan pertanyaan pengguna.
     * Sengaja dibatasi supaya konteks tidak memakan kuota token.
     */
    public function buildContext(string $message, ?float $lat = null, ?float $lng = null): string
    {
        $bagian = [];

        $ringkasan = $this->datasetSummary();
        if ($ringkasan !== '') {
            $bagian[] = $ringkasan;
        }

        // Lokasi terdekat dari posisi user (bila geolokasi tersedia di browser).
        if ($lat !== null && $lng !== null) {
            $terdekat = $this->nearbyLocations($lat, $lng);

            if ($terdekat->isNotEmpty()) {
                $baris = $terdekat->map(function (Location $loc) use ($lat, $lng) {
                    $d = $this->jarak($loc, $lat, $lng);

                    return sprintf(
                        '%s (%s) di %s, %.1f km dari user',
                        $loc->name,
                        $loc->category?->name ?? '-',
                        $this->labelWilayah($loc),
                        $d
                    );
                })->all();

                $bagian[] = "Lokasi terdekat dari posisi user:\n- ".implode("\n- ", $baris);
            } else {
                $bagian[] = 'Tidak ada lokasi dari database dalam radius 100 km dari posisi user.';
            }
        }

        // Pencarian berdasarkan kata kunci dari pertanyaan.
        $kataKunci = $this->keywords($message);

        if (! empty($kataKunci)) {
            $cocok = Location::query()
                ->publiclyVisible()
                ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES))
                ->where(function ($q) use ($kataKunci) {
                    foreach ($kataKunci as $kata) {
                        $q->orWhere('name', 'like', "%{$kata}%");
                    }
                })
                ->with(['category'])
                ->limit(25)
                ->get();

            if ($cocok->isNotEmpty()) {
                $baris = $cocok->map(function (Location $loc) {
                    $rating = $loc->rating_count > 0 ? $loc->avg_rating.'/5 ('.$loc->rating_count.' ulasan)' : 'belum ada ulasan';

                    return sprintf(
                        '%s | %s | %s | koordinat %.4f, %.4f | %s',
                        $loc->name,
                        $loc->category?->name ?? '-',
                        $this->labelWilayah($loc),
                        (float) $loc->latitude,
                        (float) $loc->longitude,
                        $rating
                    );
                })->all();

                $bagian[] = 'Lokasi yang cocok dengan kata kunci ('
                    .implode(', ', $kataKunci)."):\n- ".implode("\n- ", $baris);
            } else {
                $bagian[] = 'Tidak ada lokasi di database yang cocok dengan kata kunci '
                    .implode(', ', $kataKunci).'.';
            }
        }

        return implode("\n\n", array_filter($bagian));
    }

    /**
     * Ambil kata kunci dari pesan, buang kata umum dan kata yang terlalu pendek.
     */
    public function keywords(string $message): array
    {
        $teks = mb_strtolower($message);

        // Buang nama wilayah agar tidak polluting pencarian nama lokasi.
        foreach ($this->daftarWilayahSingkat() as $wilayah) {
            $teks = str_replace(mb_strtolower($wilayah), ' ', $teks);
        }

        $kata = preg_split('/[^\p{L}\p{N}]+/u', $teks, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $hasil = [];
        foreach ($kata as $k) {
            if (mb_strlen($k) < 3) {
                continue;
            }
            if (in_array($k, $this->stopwords, true)) {
                continue;
            }
            $hasil[$k] = true;
        }

        return array_slice(array_keys($hasil), 0, 8);
    }

    public function nearbyLocations(float $lat, float $lng, float $radiusKm = 100, int $limit = 10): Collection
    {
        return Location::query()
            ->publiclyVisible()
            ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES))
            ->with(['category'])
            ->get()
            ->map(function (Location $loc) use ($lat, $lng) {
                $loc->setAttribute('jarak_km', $this->jarak($loc, $lat, $lng));

                return $loc;
            })
            ->filter(fn (Location $loc) => $loc->jarak_km <= $radiusKm)
            ->sortBy('jarak_km')
            ->take($limit)
            ->values();
    }

    protected function labelWilayah(Location $loc): string
    {
        $nama = $loc->wilayah?->name;

        if (empty($nama)) {
            return 'wilayah tidak diketahui';
        }

        // Buang sufiks singkatan supaya lebih ringkas.
        $nama = preg_replace('/^(Kab\.|Kota|Kabupaten|Prov\.|Provinsi)\s*/u', '', $nama);

        return trim($nama);
    }

    protected function jarak(Location $loc, float $lat, float $lng): float
    {
        $earthRadius = 6371.0;

        $lat1 = deg2rad($lat);
        $lat2 = deg2rad((float) $loc->latitude);
        $dLat = $lat2 - $lat1;
        $dLng = deg2rad((float) $loc->longitude - $lng);

        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLng / 2) ** 2;

        return round($earthRadius * 2 * asin(sqrt($a)), 1);
    }

    /**
     * Singkat nama wilayah yang sering disebut orang ("bandung", "jakarta")
     * supaya tidak ikut dihitung sebagai kata kunci pencarian lokasi.
     */
    protected function daftarWilayahSingkat(): array
    {
        return [
            'jakarta', 'bandung', 'surabaya', 'medan', 'semarang', 'makassar',
            'yogyakarta', 'yogyakarta', 'solo', 'surakarta', 'malang', 'bogor',
            'bekasi', 'depok', 'tangerang', 'batam', 'pekanbaru', 'padang', 'denpasar',
            'banjarmasin', 'samarinda', 'manado', 'palembang', 'pekanbaru', 'balikpapan',
            'pontianak', 'tarakan', 'jayapura', 'ambon', 'kupang', 'mataram', 'lombok',
            'bali', 'lampung', 'bengkulu', 'jambi', 'palangkaraya', 'banjarbaru',
            'sukabumi', 'cirebon', 'garut', 'tasikmalaya', 'salatiga', 'semarang',
            'probolinggo', 'jember', 'kediri', 'madiun', 'sidoarjo', 'gresik',
        ];
    }
}
