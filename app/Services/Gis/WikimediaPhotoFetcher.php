<?php

namespace App\Services\Gis;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WikimediaPhotoFetcher
{
    protected ?string $token;

    protected const TOKEN_CACHE_KEY = 'openverse.access_token';

    protected const TOKEN_CACHE_TTL = 60 * 60 * 12;

    /** Berapa banyak hasil yang diambil per query dari tiap sumber. */
    protected const OPENVERSE_PAGE_SIZE = 20;

    protected const COMMONS_PAGE_SIZE = 6;

    /**
     * Kata kunci judul yang menandai gambar non-foto.
     *
     * Dipakai untuk membuang kandidat seperti "Peta Kota Bandung.png" atau
     * "Logo Universitas X.png" yang secara teknis lolos sebagai jpeg tetapi
     * sama sekali bukan foto tempatnya.
     *
     * @var array<int, string>
     */
    protected const NON_PHOTO_TITLE_WORDS = [
        'peta', 'map of', 'locator map', 'denah', 'diagram', 'sketsa', 'sketch',
        'logo', 'lambang', 'coat of arms', 'seal of', 'flag of', 'bendera',
        'chart', 'grafik', 'graph', 'poster', 'plakat', 'plaque', 'signboard',
        'screenshot', 'cover', 'collage', 'montage', 'panorama',
        // Dokumen kegiatan, bukan foto tempatnya.
        'wikipedia', 'wikimedia', 'wikilatih', 'seminar', 'workshop', 'rapat',
        'kunjungan', 'grand opening', 'skenario',
    ];

    /**
     * Rasio kata bermakna nama lokasi yang harus ada di judul sumber foto.
     *
     * Tanpa ambang ini, pencarian dengan nama yang dipotong ("Museum
     * Sasmitaloka Jenderal Besar DR. A.H. Nasution" -> "Museum Sasmitaloka")
     * ikut melewati foto "Museum Sasmitaloka Panglima Besar Jenderal
     * Soedirman": museum yang berbeda, karena hanya satu kata yang cocok.
     * 0,6 artinya judul sumber harus menyebut sebagian besar nama lokasi.
     */
    protected const MIN_TITLE_MATCH_RATIO = 0.6;

    /**
     * Jarak maksimal koordinat sumber agar foto dianggap benar-benar foto
     * lokasi ini, dan jarak untuk menyetujuinya tanpa diperiksa manusia.
     */
    protected const MAX_SOURCE_DISTANCE_M = 5000;

    protected const AUTO_APPROVE_DISTANCE_M = 800;

    /**
     * Kata umum pada nama lokasi yang tidak bisa membedakan tempat.
     *
     * @var array<int, string>
     */
    protected const STOP_WORDS = [
        'indonesia', 'indonesian', 'the', 'of', 'dan', 'di', 'ke', 'dari', 'at', 'in',
        'kota', 'kabupaten', 'kecamatan', 'desa',
    ];

    /**
     * Kata umum jenis tempat, bukan penanda lokasi tertentu.
     *
     * Kata-kata ini sering muncul di banyak nama lokasi sehingga cocoknya
     * tidak berarti gambar itu milik tempat yang dimaksud: "Kebun Binatang
     * Ragunan" tidak boleh dilayani foto "Kebun Binatang Jurug" hanya karena
     * kata "kebun" dan "binatang" sama. Kata khas ("ragunan") wajib ada.
     *
     * @var array<int, string>
     */
    protected const GENERIC_WORDS = [
        'museum', 'kebun', 'binatang', 'zoo', 'taman', 'pantai', 'danau', 'sungai',
        'gunung', 'air', 'terjun', 'candi', 'temple', 'pura', 'masjid', 'gereja',
        'katedral', 'kelenteng', 'temple', 'hotel', 'restoran', 'cafe', 'warung',
        'sekolah', 'universitas', 'kampus', 'stasiun', 'bandar', 'udara', 'terminal',
        'pelabuhan', 'pasar', 'mall', 'tower', 'gedung', 'rumah', 'villa', 'bebas',
    ];

    public function __construct(
        ?string $token = null,
        protected ?PhotoQualityValidator $validator = null,
    ) {
        $this->token = $token;
        $this->validator ??= new PhotoQualityValidator;
    }

    /**
     * Ambil bearer token. Kalau env/.env sudah diisi dipakai langsung, kalau
     * tidak token diambil otomatis dari client_credentials lalu disimpan di
     * cache sehingga tidak perlu diperbarui manual setelah kedaluwarsa.
     */
    public function token(): ?string
    {
        if ($this->token) {
            return $this->token;
        }

        $configured = config('services.openverse.token');
        if (! empty($configured)) {
            return $this->token = $configured;
        }

        return $this->token = Cache::remember(
            self::TOKEN_CACHE_KEY,
            self::TOKEN_CACHE_TTL,
            fn () => $this->requestNewToken()
        );
    }

    protected function requestNewToken(): ?string
    {
        $clientId = config('services.openverse.client_id');
        $clientSecret = config('services.openverse.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            return null;
        }

        try {
            $resp = Http::asForm()
                ->timeout(20)
                ->post('https://api.openverse.org/v1/auth_tokens/token/', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                ]);
        } catch (\Throwable $e) {
            Log::error('Openverse token request failed: '.$e->getMessage());

            return null;
        }

        if (! $resp->ok()) {
            Log::error('Openverse token request HTTP '.$resp->status());

            return null;
        }

        return $resp->json('access_token');
    }

    /** Buang token cache supaya request berikutnya mengambil yang baru. */
    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
        $this->token = null;
    }

    /**
     * Lokasi (kategori tempat) yang fotonya kosong: null, string kosong,
     * atau path yang menunjuk file yang sudah hilang di storage.
     */
    public function locationsMissingPhoto(?Collection $locations = null): Collection
    {
        $query = $locations
            ? $locations->filter(fn ($loc) => $loc instanceof Location)
            : Location::query()->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES));

        $disk = Storage::disk('public');

        return $query
            ->get()
            ->filter(function (Location $loc) use ($disk) {
                $photo = $loc->photo;

                if (empty($photo)) {
                    return true;
                }

                // Cek lewat disk, bukan file_exists: rottenya nama folder
                // disk "public" adalah urusan konfigurasi, bukan path
                // yang ditulis manual di sini.
                return ! $disk->exists($photo);
            })
            ->sortBy('id')
            ->values();
    }

    /**
     * Coba isi satu lokasi dengan foto dari Openverse/Wikimedia Commons.
     * Mengembalikan path foto tersimpan, atau null bila gagal.
     *
     * Setiap kandidat divalidasi berdasarkan isinya (bukan sekadar "bisa
     * dibaca") dan kandidat yang isinya sudah dipakai lokasi lain ditolak.
     * Berkas yang ditolak tidak pernah ditulis ke storage, sehingga folder
     * location_photos tidak lagi menumpuk file yatim.
     */
    public function fetchForLocation(Location $loc): ?string
    {
        $candidates = $this->searchPhotos($loc->name);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            $url = $candidate['url'];

            $sourceEvidence = $this->sourceEvidence($candidate['title'] ?? null, $url);

            // Bukti dari berkas Commons: koordinat (foto diambil di lokasi
            // ini) atau kategori (berkas memang milik tempat ini). Kalau
            // koordinatnya jauh, kandidat dibuang karena itu tempat lain.
            $evidence = $this->judgeSource(
                $candidate['title'] ?? null,
                $url,
                $loc->latitude !== null ? (float) $loc->latitude : null,
                $loc->longitude !== null ? (float) $loc->longitude : null,
                (string) $loc->name
            );

            if ($evidence['verdict'] === 'too_far') {
                Log::info(sprintf(
                    'Photo skipped (%s): %s <= %s',
                    $evidence['note'],
                    $loc->name,
                    $candidate['title']
                ));

                continue;
            }

            try {
                $resp = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->timeout(25)
                    ->get($url);
            } catch (\Throwable $e) {
                Log::error("Wikimedia download error for {$loc->name}: ".$e->getMessage());

                continue;
            }

            if (! $resp->ok() || $resp->body() === '') {
                continue;
            }

            $body = $resp->body();

            if (! @getimagesizefromstring($body) && ! str_ends_with(strtolower($url), '.svg')) {
                continue;
            }

            // Duplikat: isi yang sama sudah terpasang di lokasi lain. Tanpa
            // cek ini dozens "Bandar Udara ..."-search berakhir memakai satu
            // gambar generik yang sama persis.
            if ($this->isAlreadyUsedElsewhere($loc, $body)) {
                Log::info("Photo skipped (duplicate) for {$loc->name}: $url");

                continue;
            }

            // Buang kanvas putih bertulis, bidang warna rata, gambar
            // hitam/putih penuh, logo 32x32, dan banner/petapanoramik
            // sebelum berkas menyentuh disk.
            $verdict = $this->validator->inspect($body);

            if (! $verdict['ok']) {
                $this->validator->logRejected($loc->name, $verdict);

                continue;
            }

            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                $ext = 'jpg';
            }

            $previous = $loc->photo;
            $filename = "location_photos/{$loc->id}_".md5($loc->name).".{$ext}";

            if (! Storage::disk('public')->put($filename, $body)) {
                continue;
            }

            $loc->update([
                'photo' => $filename,
                'photo_source_title' => $candidate['title'] ?: null,
                'photo_source_url' => $url,
                'photo_source_provider' => $candidate['source'],
                'photo_fetched_at' => now(),
                'photo_source_lat' => $sourceEvidence['lat'] ?? null,
                'photo_source_lon' => $sourceEvidence['lon'] ?? null,
                'photo_source_distance_m' => $evidence['distance_m'],
                // Foto yang buktinya kuat (koordinat dekat atau kategori
                // sumber yang menyebut nama tempat) tidak perlu antrean
                // manual. Sisanya menunggu diperiksa manusia.
                'photo_review_status' => $evidence['verdict'] === 'auto'
                    ? Location::PHOTO_APPROVED
                    : Location::PHOTO_PENDING,
                'photo_reviewed_at' => $evidence['verdict'] === 'auto' ? now() : null,
                'photo_reviewed_by' => null,
                'photo_review_note' => $evidence['note'],
            ]);
            $this->rememberOwnHash($loc, $body);

            // Hapus versi lama supaya tidak tertinggal sebagai file yatim.
            if ($previous && $previous !== $filename) {
                Storage::disk('public')->delete($previous);
            }

            return $filename;
        }

        return null;
    }

    /**
     * True bila isi berkas identik dengan foto yang sudah dipakai lokasi lain.
     *
     * Peta md5 -> lokasi dibangun lazily lalu disimpan di memori. Tanpa
     * cache, setiap kandidat akan menghitung md5 untuk ribuan berkas dan
     * proses fetch menjadi O(n^2).
     *
     * @var array<string, int>|null
     */
    protected ?array $usedHashes = null;

    protected function isAlreadyUsedElsewhere(Location $loc, string $binary): bool
    {
        $hash = md5($binary);

        if ($this->usedHashes === null) {
            $this->usedHashes = [];

            foreach (Location::query()
                ->whereNotNull('photo')
                ->get(['id', 'photo']) as $other) {
                if (! Storage::disk('public')->exists($other->photo)) {
                    continue;
                }

                $hash = md5(Storage::disk('public')->get($other->photo));

                if ($hash !== false && $hash !== '') {
                    $this->usedHashes[$hash] = $other->id;
                }
            }
        }

        if (! isset($this->usedHashes[$hash])) {
            return false;
        }

        // Hash yang sama dengan lokasi sendiri bukan duplikat.
        return $this->usedHashes[$hash] !== $loc->id;
    }

    /** Catat hash milik lokasi sendiri setelah foto terpasang. */
    protected function rememberOwnHash(Location $loc, string $binary): void
    {
        if ($this->usedHashes !== null) {
            $this->usedHashes[md5($binary)] = $loc->id;
        }
    }

    /**
     * Daftar kandidat URL foto, diurutkan dari yang paling relevan.
     *
     * Openverse dipakai lebih dulu karena hasilnya relevan, lalu Wikimedia
     * Commons API sebagai cadangan karena index Openverse kadang basi
     * (URL 404 padahal filenya masih ada).
     *
     * Setiap sumber mengembalikan beberapa kandidat, bukan hanya yang
     * pertama: versi lama langsung memakai hasil pertama sehingga semua
     * lokasi dengan nama mirip ("Bandar Udara ...") mendapat gambar generik
     * yang sama. Kandidat diurutkan berdasarkan skor relevansi judul.
     *
     * @return array<int, array{url: string, title: string, source: string, score: int}>
     */
    public function searchPhotos(string $query): array
    {
        $query = preg_replace('/\s+/', ' ', trim($query));
        $queries = [$query];

        // Fallback: buang kata terakhir bertahap
        // ("Pantai Sembukan Indonesia" -> "Pantai Sembukan").
        $words = array_values(array_filter(explode(' ', $query)));
        while (count($words) > 1) {
            array_pop($words);
            $queries[] = implode(' ', $words);
        }

        $scored = [];

        foreach ($queries as $q) {
            foreach ($this->openverseCandidates($q) as $c) {
                $this->collectCandidate($scored, $c, $query);
            }
        }

        foreach ($queries as $q) {
            foreach ($this->commonsCandidates($q) as $c) {
                $this->collectCandidate($scored, $c, $query);
            }
        }

        uasort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_values($scored);
    }

    /**
     * Backwards-compatible wrapper yang hanya mengembalikan URL.
     *
     * @return array<int, string>
     */
    public function searchPhoto(string $query): array
    {
        return array_column($this->searchPhotos($query), 'url');
    }

    /**
     * @param  array<int, array{url: string, title: string, source: string, score: int}>  $scored
     * @param  array{url: string, title: string, source: string}  $candidate
     */
    protected function collectCandidate(array &$scored, array $candidate, string $query): void
    {
        if (isset($scored[$candidate['url']])) {
            return;
        }

        // Judul berniat "peta", "logo", "denah", atau "diagram" menandakan
        // gambar yang bukan foto tempat, meskipun Commons menyajikan-nya
        // sebagai jpeg.
        if ($this->looksLikeNonPhoto($candidate['title'])) {
            Log::debug('Candidate skipped (non-photo title): '.$candidate['title']);

            return;
        }

        $score = $this->relevanceScore($candidate['title'], $query);

        // Kandidat harus menyebut sebagian besar nama lokasi. Tanpa ini,
        // pencarian dengan nama yang dipotong dapat mengambil foto milik
        // tempat lain yang kebetulan berbagi satu kata.
        if (! $this->titleMatchesLocation($candidate['title'], $query)) {
            Log::debug('Candidate skipped (title does not match location): '.$candidate['title']);

            return;
        }

        $candidate['score'] = $score;
        $scored[$candidate['url']] = $candidate;
    }

    /**
     * True bila judul berkas menandakan gambar non-foto: peta, logo, denah,
     * diagram, plakat, dan sejenisnya.
     */
    protected function looksLikeNonPhoto(string $title): bool
    {
        $title = mb_strtolower($title);

        foreach (self::NON_PHOTO_TITLE_WORDS as $word) {
            if (str_contains($title, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Skor relevansi judul kandidat terhadap nama lokasi.
     *
     * Judul yang memuat seluruh kata kunci lokasi bernilai paling tinggi;
     * kata umum seperti "Indonesia" tidak dihitung karena hampir semua judul
     * memuatnya dan tidak membedakan lokasi.
     */
    protected function relevanceScore(string $title, string $query): int
    {
        $title = mb_strtolower($title);
        $score = 0;

        foreach ($this->needles($query) as $needle) {
            if (mb_strpos($title, $needle) !== false) {
                $score++;
            }
        }

        return $score;
    }

    /**
     * True bila judul sumber foto benar-benar menyebut nama lokasi.
     *
     * Hanya bagian tertentu dari nama yang perlu cocok (lihat
     * MIN_TITLE_MATCH_RATIO): judul "Prambanan Temple" tetap ditolak untuk
     * "Candi Prambanan" karena kata "candi" tidak ada, sedangkan judul
     * berprefik panjang seperti "Museum Kereta Api Ambarawa" tetap diterima
     * untuk nama yang memuat banyak kata tambahan.
     */
    public function titleMatchesLocation(string $title, string $locationName): bool
    {
        $lowerTitle = mb_strtolower($title);

        // Semua kata khas lokasi harus ada. Ini yang menolak foto
        // "Kebun Binatang Jurug" untuk lokasi "Kebun Binatang Ragunan".
        foreach ($this->distinctiveWords($locationName) as $word) {
            if (! str_contains($lowerTitle, $word)) {
                return false;
            }
        }

        // Dan judul harus tetap menyebut sebagian besar nama keseluruhan.
        return $this->titleMatchRatio($title, $locationName) >= self::MIN_TITLE_MATCH_RATIO;
    }

    /** @var array<string, array{lat: ?float, lon: ?float, categories: array<int, string>}|null> */
    protected array $evidenceCache = [];

    /**
     * Jarak dua titik koordinat dalam meter (haversine).
     */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    protected function isAutoApprovable(?float $distance): bool
    {
        return $distance !== null && $distance <= self::AUTO_APPROVE_DISTANCE_M;
    }

    /**
     * Bukti dari berkas Commons: koordinat dan kategori.
     *
     * Koordinat = bukti kuat foto diambil di lokasi itu. Kategori (mis.
     * "Category:Museum Bank Indonesia") = bukti kuat berkas memang milik
     * tempat tersebut, dipakai saat koordinat tidak tersedia.
     *
     * @return array{lat: ?float, lon: ?float, categories: array<int, string>}|null
     */
    public function sourceEvidence(?string $title, ?string $url = null): ?array
    {
        $file = self::commonsFileTitle($title, $url);

        if ($file === null) {
            return null;
        }

        if (! array_key_exists($file, $this->evidenceCache)) {
            $this->evidenceCache[$file] = $this->fetchSourceEvidence($file);
        }

        return $this->evidenceCache[$file];
    }

    /**
     * Putuskan worthy/tidaknya foto tanpa pemeriksaan manusia.
     *
     * @return array{verdict: string, distance_m: ?int, note: string}
     */
    public function judgeSource(
        ?string $title,
        ?string $url,
        ?float $lat,
        ?float $lon,
        string $locationName
    ): array {
        $evidence = $this->sourceEvidence($title, $url);

        if ($evidence === null) {
            return [
                'verdict' => 'pending',
                'distance_m' => null,
                'note' => 'Sumber foto tidak punya data lokasi',
            ];
        }

        $distance = null;

        if ($evidence['lat'] !== null && $lat !== null && $lat !== 0.0) {
            $distance = self::distanceMeters($lat, $lon ?? 0.0, $evidence['lat'], $evidence['lon'] ?? 0.0);

            if ($distance > self::MAX_SOURCE_DISTANCE_M) {
                return [
                    'verdict' => 'too_far',
                    'distance_m' => (int) round($distance),
                    'note' => sprintf('Koordinat sumber %.0f m dari lokasi (terlalu jauh)', $distance),
                ];
            }
        }

        if ($distance !== null && $distance <= self::AUTO_APPROVE_DISTANCE_M) {
            return [
                'verdict' => 'auto',
                'distance_m' => (int) round($distance),
                'note' => sprintf('Auto: koordinat sumber %.0f m dari lokasi', $distance),
            ];
        }

        $matchedCategory = $this->matchingCategory($evidence['categories'], $locationName);

        if ($matchedCategory !== null) {
            return [
                'verdict' => 'auto',
                'distance_m' => $distance === null ? null : (int) round($distance),
                'note' => 'Auto: kategori sumber "'.$matchedCategory.'"',
            ];
        }

        return [
            'verdict' => 'pending',
            'distance_m' => $distance === null ? null : (int) round($distance),
            'note' => $distance === null
                ? 'Sumber tidak punya koordinat maupun kategori yang cocok'
                : sprintf('Koordinat sumber %.0f m dari lokasi (perlu diperiksa)', $distance),
        ];
    }

    /**
     * Kategori Commons yang menyebut nama lokasi, atau null.
     *
     * Semua kata bermakna nama lokasi harus ada, bukan hanya kata khas:
     * kategori "Bank Indonesia" tidak boleh dianggap sebagai bukti untuk
     * "Museum Bank Indonesia" karena kata "museum" tidak disebut.
     */
    public function matchingCategory(array $categories, string $locationName): ?string
    {
        $needles = $this->needles($locationName);

        if ($needles === []) {
            return null;
        }

        foreach ($categories as $category) {
            $haystack = mb_strtolower(preg_replace('/^Category\s*:/i', '', (string) $category));
            $found = true;

            foreach ($needles as $word) {
                if (! str_contains($haystack, $word)) {
                    $found = false;

                    break;
                }
            }

            if ($found) {
                return $category;
            }
        }

        return null;
    }

    /**
     * Nama berkas Commons ("File:xxx.jpg").
     *
     * Openverse mengembalikan judul tampilan tanpa prefik "File:", sedangkan
     * URL unduhan Wikimedia memuat nama berkasnya. Jadi kalau judul tidak
     * bisa dipakai, nama berkas diambil dari URL.
     */
    public static function commonsFileTitle(?string $title, ?string $url = null): ?string
    {
        $title = trim((string) $title);

        if (preg_match('/^File\s*:/i', $title)) {
            return $title;
        }

        if ($url !== null && preg_match('#/commons/[^/]+/[^/]+/(.+)$#', $url, $m)) {
            return 'File:'.rawurldecode($m[1]);
        }

        return null;
    }

    /**
     * @return array{lat: ?float, lon: ?float, categories: array<int, string>}|null
     */
    protected function fetchSourceEvidence(string $file): ?array
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout(15)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'format' => 'json',
                    'prop' => 'coordinates|categories',
                    'cllimit' => 'max',
                    'titles' => $file,
                ]);
        } catch (\Throwable $e) {
            Log::debug('Source evidence lookup failed for '.$file.': '.$e->getMessage());

            return null;
        }

        foreach ($resp->json('query.pages') ?? [] as $page) {
            if (($page['missing'] ?? null) !== null) {
                return null;
            }

            $coord = $page['coordinates'][0] ?? null;
            $categories = [];

            foreach ($page['categories'] ?? [] as $category) {
                if (isset($category['title'])) {
                    $categories[] = (string) $category['title'];
                }
            }

            return [
                'lat' => is_array($coord) && isset($coord['lat']) ? (float) $coord['lat'] : null,
                'lon' => is_array($coord) && isset($coord['lon']) ? (float) $coord['lon'] : null,
                'categories' => $categories,
            ];
        }

        return null;
    }

    /** 0.0 - 1.0: berapa bagian nama lokasi yang disebut judul sumber. */
    public function titleMatchRatio(string $title, string $locationName): float
    {
        $needles = $this->needles($locationName);

        if ($needles === []) {
            return 0.0;
        }

        return $this->relevanceScore($title, $locationName) / count($needles);
    }

    /**
     * Kata penanda lokasi tertentu, yaitu kata yang bukan kata umum
     * maupun kata jenis tempat.
     *
     * @return array<int, string>
     */
    public function distinctiveWords(string $locationName): array
    {
        return array_values(array_filter(
            $this->needles($locationName),
            fn (string $word) => ! in_array($word, self::GENERIC_WORDS, true)
        ));
    }

    /** @return array<int, string> */
    protected function needles(string $query): array
    {
        $words = preg_split('/\s+/', mb_strtolower($query)) ?: [];

        // Kata umum diabaikan: hampir semua judul memuatnya sehingga tidak
        // bisa membedakan lokasi.
        return array_values(array_filter($words, fn ($w) => $w !== '' && ! in_array($w, self::STOP_WORDS, true)));
    }

    /**
     * Kandidat dari Openverse, dibatasi per query.
     *
     * @return array<int, array{url: string, title: string, source: string}>
     */
    protected function openverseCandidates(string $query, bool $allowRetry = true): array
    {
        $params = [
            'q' => $query,
            'page_size' => self::OPENVERSE_PAGE_SIZE,
            'license_type' => 'all',
            'source' => 'wikimedia',
        ];

        try {
            $request = Http::withHeaders(['Accept' => 'application/json'])->timeout(20);

            if ($token = $this->token()) {
                $request = $request->withHeaders(['Authorization' => 'Bearer '.$token]);
            }

            $resp = $request->get('https://api.openverse.org/v1/images/', $params);
        } catch (\Throwable $e) {
            Log::error("Openverse request failed for {$query}: ".$e->getMessage());

            return [];
        }

        // Token kedaluwarsa: buang cache lalu coba sekali lagi dengan token baru.
        if ($resp->status() === 401 && $allowRetry && ! config('services.openverse.token')) {
            $this->forgetToken();

            return $this->openverseCandidates($query, false);
        }

        if (! $resp->ok()) {
            return [];
        }

        $out = [];
        foreach ($resp->json('results') ?? [] as $r) {
            $url = $r['url'] ?? '';

            if (is_string($url) && str_contains($url, 'upload.wikimedia.org')) {
                $out[] = [
                    'url' => $url,
                    'title' => (string) ($r['title'] ?? ''),
                    'source' => 'openverse',
                ];
            }
        }

        return $out;
    }

    /**
     * Kandidat dari Wikimedia Commons API, diurutkan dari skor tertinggi.
     *
     * @return array<int, array{url: string, title: string, source: string, score: int}>
     */
    protected function commonsCandidates(string $query): array
    {
        try {
            $resp = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->timeout(25)
                ->get('https://commons.wikimedia.org/w/api.php', [
                    'action' => 'query',
                    'format' => 'json',
                    'generator' => 'search',
                    'gsrsearch' => "filetype:bitmap {$query}",
                    'gsrnamespace' => '6',
                    'gsrlimit' => (string) self::COMMONS_PAGE_SIZE,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|mime|size',
                    'iiurlwidth' => '1200',
                ]);
        } catch (\Throwable $e) {
            Log::error("Commons API request failed for {$query}: ".$e->getMessage());

            return [];
        }

        if (! $resp->ok()) {
            return [];
        }

        $out = [];

        foreach ($resp->json('query.pages') ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;

            if (! $info || ($info['mime'] ?? '') !== 'image/jpeg') {
                continue;
            }

            // Terlalu kecil tidak berguna sebagai foto lokasi.
            if (($info['width'] ?? 0) < 640) {
                continue;
            }

            $url = $info['thumburl'] ?? $info['url'] ?? null;

            if (! is_string($url) || $url === '') {
                continue;
            }

            $title = (string) ($page['title'] ?? '');

            $out[] = [
                'url' => $url,
                'title' => $title,
                'source' => 'commons',
                'score' => $this->relevanceScore($title, $query),
            ];
        }

        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($out, 0, self::COMMONS_PAGE_SIZE);
    }

    protected function userAgent(): string
    {
        return config('services.openverse.user_agent')
            ?: 'GISLaravelBot/1.0 (wiki photo fetch; contact: admin@gislocal.test)';
    }
}
