<?php

namespace App\Services\Gis;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WikimediaPhotoFetcher
{
    protected ?string $token;

    protected const TOKEN_CACHE_KEY = 'openverse.access_token';

    protected const TOKEN_CACHE_TTL = 60 * 60 * 12;

    public function __construct(?string $token = null)
    {
        $this->token = $token;
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
        if (!empty($configured)) {
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
            Log::error('Openverse token request failed: ' . $e->getMessage());
            return null;
        }

        if (!$resp->ok()) {
            Log::error('Openverse token request HTTP ' . $resp->status());
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
    public function locationsMissingPhoto(?\Illuminate\Support\Collection $locations = null): \Illuminate\Support\Collection
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

                return !file_exists(storage_path('app/public/' . $photo));
            })
            ->sortBy('id')
            ->values();
    }

    /**
     * Coba isi satu lokasi dengan foto dari Openverse/Wikimedia Commons.
     * Mengembalikan path foto tersimpan, atau null bila gagal.
     */
    public function fetchForLocation(Location $loc): ?string
    {
        $urls = $this->searchPhoto($loc->name);

        if (empty($urls)) {
            return null;
        }

        foreach ($urls as $url) {
            try {
                $resp = Http::withHeaders(['User-Agent' => $this->userAgent()])
                    ->timeout(25)
                    ->get($url);
            } catch (\Throwable $e) {
                Log::error("Wikimedia download error for {$loc->name}: " . $e->getMessage());
                continue;
            }

            if (!$resp->ok() || $resp->body() === '') {
                continue;
            }

            $body = $resp->body();
            if (!@getimagesizefromstring($body) && !str_ends_with(strtolower($url), '.svg')) {
                continue;
            }

            $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
                $ext = 'jpg';
            }

            $filename = "location_photos/{$loc->id}_" . md5($loc->name) . ".{$ext}";

            if (!Storage::disk('public')->put($filename, $body)) {
                continue;
            }

            $loc->update(['photo' => $filename]);

            return $filename;
        }

        return null;
    }

    /**
     * Ambil daftar kandidat URL foto. Openverse dipakai lebih dulu karena
     * hasilnya relevan, lalu Wikimedia Commons API sebagai cadangan karena
     * index Openverse kadang basi (URL 404 padahal filenya masih ada).
     */
    public function searchPhoto(string $query): array
    {
        $query = preg_replace('/\s+/', ' ', $query);
        $candidates = [$query];

        // Fallback: buang kata terakhir bertahap ("Pantai Sembukan Indonesia" -> "Pantai Sembukan")
        $words = array_values(array_filter(explode(' ', $query)));
        while (count($words) > 1) {
            array_pop($words);
            $candidates[] = implode(' ', $words);
        }

        $urls = [];

        foreach ($candidates as $q) {
            $url = $this->openverseWikimediaUrl($q);
            if ($url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        foreach ($candidates as $q) {
            $url = $this->commonsApiUrl($q);
            if ($url && !in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    protected function openverseWikimediaUrl(string $query, bool $allowRetry = true): ?string
    {
        $params = [
            'q' => $query,
            'page_size' => 20,
            'license_type' => 'all',
            'aspect_ratio' => 'wide',
            'source' => 'wikimedia',
        ];

        try {
            $request = Http::withHeaders(['Accept' => 'application/json'])->timeout(20);

            if ($token = $this->token()) {
                $request = $request->withHeaders(['Authorization' => 'Bearer ' . $token]);
            }

            $resp = $request->get('https://api.openverse.org/v1/images/', $params);
        } catch (\Throwable $e) {
            Log::error("Openverse request failed for {$query}: " . $e->getMessage());
            return null;
        }

        // Token kedaluwarsa: buang cache lalu coba sekali lagi dengan token baru.
        if ($resp->status() === 401 && $allowRetry && !config('services.openverse.token')) {
            $this->forgetToken();
            return $this->openverseWikimediaUrl($query, false);
        }

        if (!$resp->ok()) {
            return null;
        }

        foreach ($resp->json('results') ?? [] as $r) {
            $u = $r['url'] ?? '';
            if (is_string($u) && str_contains($u, 'upload.wikimedia.org')) {
                return $u;
            }
        }

        return null;
    }

    protected function commonsApiUrl(string $query): ?string
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
                    'gsrlimit' => '6',
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|mime|size',
                    'iiurlwidth' => '1200',
                ]);
        } catch (\Throwable $e) {
            Log::error("Commons API request failed for {$query}: " . $e->getMessage());
            return null;
        }

        if (!$resp->ok()) {
            return null;
        }

        $best = null;
        $bestScore = 0;

        foreach ($resp->json('query.pages') ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            if (!$info || ($info['mime'] ?? '') !== 'image/jpeg') {
                continue;
            }

            $title = mb_strtolower((string) ($page['title'] ?? ''));
            $score = 0;
            foreach (array_filter(preg_split('/\s+/', mb_strtolower($query))) as $needle) {
                if (mb_strpos($title, (string) $needle) !== false) {
                    $score++;
                }
            }

            if ($score > $bestScore && ($info['width'] ?? 0) >= 640) {
                $bestScore = $score;
                $best = $info['thumburl'] ?? $info['url'] ?? null;
            }
        }

        return is_string($best) ? $best : null;
    }

    protected function userAgent(): string
    {
        return config('services.openverse.user_agent')
            ?: 'GISLaravelBot/1.0 (wiki photo fetch; contact: admin@gislocal.test)';
    }
}
