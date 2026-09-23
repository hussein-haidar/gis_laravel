<?php

namespace App\Console\Commands;

use App\Models\Location;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class FetchLocationImages extends Command
{
    protected $signature = 'gis:fetch-images
                            {--limit=0 : Maksimal jumlah lokasi yang diproses (0 = semua)}
                            {--after=0 : Lanjutkan dari id lokasi setelah nilai ini}
                            {--force : Ambil ulang foto meski sudah ada}';

    protected $description = 'Ambil foto tempat asli dari Wikipedia (pageimages) untuk lokasi tanpa foto';

    private const USER_AGENT = 'GisLaravelApp/1.0 (kontak: admin@example.com)';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $force = (bool) $this->option('force');
        $after = (int) $this->option('after');

        $locations = Location::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('id', '>', $after)
            ->when(!$force, fn ($q) => $q->whereNull('photo'))
            ->when($limit > 0, fn ($q) => $q->limit($limit))
            ->orderBy('id')
            ->get();

        if ($locations->isEmpty()) {
            $this->info('Tidak ada lokasi yang perlu diproses.');
            return self::SUCCESS;
        }

        $this->info('Memproses ' . $locations->count() . ' lokasi...');
        $bar = $this->output->createProgressBar($locations->count());
        $bar->start();

        $ok = $saved = $fail = 0;

        foreach ($locations as $location) {
            try {
                $source = $this->fetchThumbnail($location->name);

                if (!$source) {
                    $fail++;
                    $bar->advance();
                    usleep(100_000);
                    continue;
                }

                $ext = $this->extensionFrom($source);
                $relative = 'location_photos/' . $location->id . '_' . substr(md5($location->name), 0, 8) . '.' . $ext;

                $download = Http::timeout(25)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->get($source);

                if (!$download->successful()) {
                    $fail++;
                    $bar->advance();
                    usleep(100_000);
                    continue;
                }

                $body = $download->body();
                // Tolak foto generik/placeholder (hash dikenal)
                if ($this->isGenericImage($body)) {
                    $fail++;
                    $bar->advance();
                    usleep(100_000);
                    continue;
                }

                if (Storage::disk('public')->put($relative, $body)) {
                    $location->update(['photo' => $relative]);
                    $saved++;
                } else {
                    $fail++;
                }
            } catch (\Throwable $e) {
                $fail++;
            }

            $ok++;
            $bar->advance();
            usleep(30_000);
        }

        $bar->finish();
        $this->newLine(2);
        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Diproses', $ok],
                ['Foto tersimpan', $saved],
                ['Tanpa foto / gagal', $fail],
            ]
        );

        return self::SUCCESS;
    }

    protected function fetchThumbnail(string $name): ?string
    {
        $variants = $this->generateSearchVariants($name);
        if (empty($variants)) {
            return null;
        }

        $originalName = $variants[0]; // nama asli untuk Commons search

        // 1) Gambar utama artikel Wikipedia (pakai semua varian)
        foreach ($variants as $variant) {
            for ($attempt = 0; $attempt < 1; $attempt++) {
                $thumb = $this->thumbnailFromPageimages($variant);
                if ($thumb) {
                    return $thumb;
                }
                usleep(50_000);
            }
        }

        // 2) Fallback: Wikimedia Commons search — HANYA nama asli (bukan varian strip)
        $thumb = $this->thumbnailFromCommonsSearch($originalName);
        if ($thumb) {
            return $thumb;
        }

        return null;
    }

    // Daftar hash MD5 foto generik/placeholder yg dikembalikan Wikimedia untuk banyak lokasi beda
    protected function isGenericImage(string $content): bool
    {
        $hash = md5($content);
        $genericHashes = [
            // 63875 bytes - foto "Makam/Pura/Bandar Udara/Universitas/Alun-alun/Taman" generik
            'aa26abdc626d7cdef9defd27f124ce05',
            // 252528 bytes - foto "Air Terjun/Danau/Taman Nasional/Ereveld/Bandar Udara besar" generik
            'd0cef5d696931e3b16542a97a3d33726',
            // 132428 bytes - foto "Air Terjun/Bandar Udara kecil" generik (PNG)
            '4263d250a42db511b33fb5c84ec72991',
            // 207874 bytes
            'df40b62e0ac8f9cdbc5716e0fd3ceebe',
            // 149552 bytes
            '869adce06b678a56b8672392151b3968',
            // 104360 bytes
            '3078424bf37b44895359c1fdc8d90bd0',
            // 659841 bytes
            'b394803f37feccb0815dc4ce39a97f76',
            // 63963 bytes
            '8db2ea71db5e5cf220b3d7f90218b8df',
            // 91334 bytes
            '12d9476c4a3f5c21051cfa371d38c25d',
            // 1005702 bytes
            'e94a58455707146c515315043ae7488b',
            // 196085 bytes
            '450e7e194fd0f726300534528f543926',
            // 178405 bytes
            'e49d7f474e53c3535b8d550a67299c1b',
            // 294274 bytes
            '68144d8ccf622c04cf6de429b2ccddd9',
            // 175080 bytes
            'ff1a8407c53e6089d1b9bb8c044f2783',
            // 128108 bytes
            'b2b7cc4cfc3d0da8e1733dcc68550b0c',
            // 85961 bytes
            '6bdaec2f89da7c8ee41f3a5a711bde67',
            // 192424 bytes
            '1234ac1b593f973c72f1ca8e8bf5b79b',
            // 159016 bytes
            'e503eeb15f7aa56bab8f3f3a303b7eeb',
            // 188383 bytes
            'e70e364e1ebdaf840a8763dde4ab1a51',
            // Baru dari run kedua
            '118ac36ee4a15a1e260b3eb46d9bc678',  // 175197 bytes
            '94bd9bb5e21ecb8e34a49421382506d9',  // 175197 bytes (duplicate entry but diff hash? keep both)
            'd41b5ef65db9093ad92b39fcbef83a52',  // 212611 bytes
            'a4de61fa752649c649549f810d70019d',  // 85961 bytes
            'bc602bda5cbe7414ee41f24ed3070c1d',  // 192485 bytes
            '9073da687bf393a5aaea12602973b7cf',  // 159016 bytes
            '20a585f185591b99e288ed9786440ef1',  // 115861 bytes
            '111d4f4174d28626dda893ff4b481e87',  // 257037 bytes
            '138b4b4580a6f3bbf3365490913e9466',  // 252448 bytes
            '00b15844dd1502a052c89154192c3aba',  // 69239 bytes
            '71f29a9e60ea54c8f0060a030f3f5539',  // 93665 bytes
        ];
            // 132428 bytes - foto "Air Terjun/Bandar Udara kecil" generik (PNG)
            '4263d250a42db511b33fb5c84ec72991',
            // 207874 bytes
            'df40b62e0ac8f9cdbc5716e0fd3ceebe',
            // 149552 bytes
            '869adce06b678a56b8672392151b3968',
            // 104360 bytes
            '3078424bf37b44895359c1fdc8d90bd0',
            // 659841 bytes
            'b394803f37feccb0815dc4ce39a97f76',
            // 63963 bytes
            '8db2ea71db5e5cf220b3d7f90218b8df',
            // 91334 bytes
            '12d9476c4a3f5c21051cfa371d38c25d',
            // 1005702 bytes
            'e94a58455707146c515315043ae7488b',
            // 196085 bytes
            '450e7e194fd0f726300534528f543926',
            // 178405 bytes
            'e49d7f474e53c3535b8d550a67299c1b',
            // 294274 bytes
            '68144d8ccf622c04cf6de429b2ccddd9',
            // 175080 bytes
            'ff1a8407c53e6089d1b9bb8c044f2783',
            // 128108 bytes
            'b2b7cc4cfc3d0da8e1733dcc68550b0c',
            // 85961 bytes
            '6bdaec2f89da7c8ee41f3a5a711bde67',
            // 192424 bytes
            '1234ac1b593f973c72f1ca8e8bf5b79b',
            // 159016 bytes
            'e503eeb15f7aa56bab8f3f3a303b7eeb',
            // 188383 bytes
            'e70e364e1ebdaf840a8763dde4ab1a51',
        ];
        return in_array($hash, $genericHashes, true);
    }

    /**
     * Generate multiple search variants for a location name
     * to maximize chance of finding a photo.
     */
    protected function generateSearchVariants(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return [];
        }

        $variants = [];
        $original = $this->wikiTitle($name);
        $variants[] = $original;

        // Indonesian prefixes to strip for better search
        $prefixes = [
            'Ereveld ', 'Ereveld',
            'Bandar Udara ', 'Bandara ',
            'Stasiun ', 'Stasion ',
            'Terminal ', 'Terminal',
            'Pelabuhan ', 'Pelabuhan',
            'Makam ', 'Makam',
            'Masjid ', 'Masjid',
            'Gereja ', 'Gereja',
            'Pura ', 'Pura',
            'Candi ', 'Candi',
            'Situs ', 'Situs',
            'Prasasti ', 'Prasasti',
            'Monumen ', 'Monumen ',
            'Tugu ', 'Tugu',
            'Bangunan ', 'Bangunan',
            'Gedung ', 'Gedung ',
            'Kantor ', 'Kantor ',
            'Sekolah ', 'Sekolah ',
            'Universitas ', 'Universitas ',
            'Politeknik ', 'Politeknik ',
            'Akademi ', 'Akademi ',
            'Kampus ', 'Kampus ',
            'Rumah ', 'Rumah ',
            'Istana ', 'Istana ',
            'Keraton ', 'Keraton ',
            'Benteng ', 'Benteng ',
            'Kubu ', 'Kubu ',
            'Taman ', 'Taman ',
            'Alun[- ]?alun ', 'Alun[- ]?alun',
            'Lapangan ', 'Lapangan ',
            'Hutan ', 'Hutan ',
            'Kebun ', 'Kebun ',
            'Raya ', 'Raya ',
            'Danau ', 'Danau ',
            'Pulau ', 'Pulau ',
            'Gunung ', 'Gunung ',
            'Pantai ', 'Pantai ',
            'Teluk ', 'Teluk ',
            'Selat ', 'Selat ',
            'Kuala ', 'Kuala ',
            'Muara ', 'Muara ',
            'Sungai ', 'Sungai ',
            'Kali ', 'Kali ',
            'Air ', 'Air ',
            'Bendungan ', 'Bendungan ',
            'Bendung ', 'Bendung ',
            'Waduk ', 'Waduk ',
            'Empang ', 'Empang ',
            'Embung ', 'Embung ',
            'TPU ', 'TPU ',
            'Taman Makam ', 'Taman Makam ',
            'Kompleks ', 'Kompleks ',
            'Komplek ', 'Komplek ',
            'Bukit ', 'Bukit ',
            'Batu ', 'Batu ',
            'Kampung ', 'Kampung ',
            'Desa ', 'Desa ',
            'Kelurahan ', 'Kelurahan ',
            'Kecamatan ', 'Kecamatan ',
            'Kabupaten ', 'Kabupaten ',
            'Kota ', 'Kota ',
            'Provinsi ', 'Provinsi ',
        ];

        // Try stripping each prefix
        foreach ($prefixes as $prefix) {
            if (stripos($name, $prefix) === 0) {
                $rest = trim(substr($name, strlen($prefix)));
                if ($rest !== '' && strlen($rest) > 2) {
                    $variants[] = $this->wikiTitle($rest);
                    // Also try English version for airports/stations
                    if (stripos($prefix, 'Bandar Udara') !== false || stripos($prefix, 'Bandara') !== false) {
                        $variants[] = $this->wikiTitle($rest . ' Airport');
                        $variants[] = $this->wikiTitle($rest . ' airport');
                    }
                    if (stripos($prefix, 'Stasiun') !== false) {
                        $variants[] = $this->wikiTitle($rest . ' railway station');
                        $variants[] = $this->wikiTitle($rest . ' station');
                    }
                    if (stripos($prefix, 'Ereveld') !== false) {
                        $variants[] = $this->wikiTitle($rest . ' War Cemetery');
                        $variants[] = $this->wikiTitle($rest . ' war cemetery');
                    }
                    if (stripos($prefix, 'Terminal') !== false) {
                        $variants[] = $this->wikiTitle($rest . ' bus terminal');
                    }
                    if (stripos($prefix, 'Pelabuhan') !== false) {
                        $variants[] = $this->wikiTitle($rest . ' port');
                    }
                }
            }
        }

        // Also try just the last 2-3 words for long names
        $words = preg_split('/\s+/', $name);
        if (count($words) > 3) {
            $variants[] = $this->wikiTitle(implode(' ', array_slice($words, -3)));
            $variants[] = $this->wikiTitle(implode(' ', array_slice($words, -2)));
        }

        // Deduplicate while preserving order
        return array_values(array_unique($variants));
    }

    protected function thumbnailFromPageimages(string $title): ?string
    {
        $url = 'https://id.wikipedia.org/w/api.php?action=query&format=json&redirects=1'
            . '&titles=' . rawurlencode($title)
            . '&prop=pageimages&piprop=thumbnail|original&pithumbsize=800';

        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get($url);

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json('query.pages') ?? [] as $page) {
            // Prefer thumbnail — convert to thumb.wikimedia.org proxy to avoid 403
            if (!empty($page['thumbnail']['source'])) {
                $clean = explode('?', $page['thumbnail']['source'])[0];
                $thumb = $this->convertToThumbProxy($clean);
                if ($thumb) {
                    return $thumb;
                }
            }
            // Fallback: original
            if (!empty($page['original']['source'])) {
                $original = explode('?', $page['original']['source'])[0];
                $thumb = $this->convertToThumbProxy($original);
                if ($thumb) {
                    return $thumb;
                }
            }
        }

        return null;
    }

    protected function convertToThumbProxy(string $url): ?string
    {
        // Pattern: https://upload.wikimedia.org/wikipedia/commons/3/34/Ancol.jpg
        // → https://thumb.wikimedia.org/wikipedia/commons/3/34/Ancol.jpg/800px-Ancol.jpg
        if (preg_match('#^https?://upload\.wikimedia\.org/wikipedia/([^/]+)/(.+?)\.(jpg|jpeg|png|gif|webp)$#i', $url, $m)) {
            $domain = $m[1];
            $path = $m[2];
            $ext = $m[3];
            $filename = basename($path);
            return "https://thumb.wikimedia.org/wikipedia/{$domain}/{$path}.{$ext}/800px-{$filename}.{$ext}";
        }

        // Already thumb.wikimedia.org
        if (strpos($url, 'thumb.wikimedia.org') !== false) {
            return $url;
        }

        return null;
    }

    protected function thumbnailFromCommonsSearch(string $title): ?string
    {
        $url = 'https://commons.wikimedia.org/w/api.php?action=query&format=json'
            . '&generator=search&gsrnamespace=6&gsrlimit=12&gsrsearch=' . rawurlencode($title)
            . '&prop=imageinfo&iiprop=url&iiurlwidth=800';

        $response = Http::timeout(15)
            ->withHeaders(['User-Agent' => self::USER_AGENT])
            ->get($url);

        if (!$response->successful()) {
            return null;
        }

        foreach ($response->json('query.pages') ?? [] as $page) {
            $fileTitle = strtolower($page['title'] ?? '');
            if (preg_match('/\b(lambang|seal|coat|logo|icon|symbol|flag|bendera|lokasi|peta|map|route)\b/', $fileTitle)) {
                continue;
            }

            $source = $page['imageinfo'][0]['thumburl'] ?? ($page['imageinfo'][0]['url'] ?? null);
            if ($source) {
                $clean = explode('?', $source)[0];
                if ($clean !== '') {
                    return $clean;
                }
            }
        }

        return null;
    }

    protected function extensionFrom(string $url): string
    {
        $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        return in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif'], true) ? $ext : 'jpg';
    }

    protected function wikiTitle(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $name);
        $parts = array_map(function ($word) {
            if ($word === '') {
                return $word;
            }
            if (preg_match('/^(KOTA|KAB\.?|KABUPATEN|PROVINSI|KEC\.?|KECAMATAN|PULAU|KAP\.?)$/i', $word)) {
                return ucfirst(strtolower($word));
            }
            return ucfirst(strtolower($word));
        }, $parts);

        return implode(' ', $parts);
    }
}