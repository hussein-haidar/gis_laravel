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

        return $query
            ->get()
            ->filter(function (Location $loc) {
                $photo = $loc->photo;

                if (empty($photo)) {
                    return true;
                }

                return ! file_exists(storage_path('app/public/'.$photo));
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

            $loc->update(['photo' => $filename]);
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
                $path = storage_path('app/public/'.$other->photo);

                if (is_file($path)) {
                    $this->usedHashes[md5_file($path)] = $other->id;
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

        // Tidak ada satupun kata kunci nama lokasi yang muncul di judul:
        // kemungkinan besar hasil pencarian ini milik tempat lain.
        if ($score <= 0) {
            Log::debug('Candidate skipped (no keyword match): '.$candidate['title']);

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
        $needles = array_filter(preg_split('/\s+/', mb_strtolower($query)) ?: []);

        // Kata yang tidak spesifik: abaikan agar tidak menaikkan skor.
        $stopWords = ['indonesia', 'indonesian', 'the', 'of', 'dan', 'di', 'ke', 'dari', 'at', 'in'];

        $score = 0;
        foreach ($needles as $needle) {
            if (in_array($needle, $stopWords, true)) {
                continue;
            }
            if (mb_strpos($title, $needle) !== false) {
                $score++;
            }
        }

        return $score;
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
