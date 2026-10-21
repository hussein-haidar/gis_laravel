<?php

namespace App\Services\Traffic;

use App\Models\Setting;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TomTomTrafficService
{
    /**
     * API key Geoapify (free tier) untuk fallback reverse geocoding.
     */
    protected const GEOAPIFY_API_KEY = '131b2d306a254ed8b9cef2377f6bdc82';

    /**
     * Batas jumlah request TomTom flow yang jalan bersamaan.
     *
     * Guzzle memakai ulang koneksi (HTTP/2) sehingga semua probe terkirim
     * paralel tanpa memicu rate-limit TomTom. Dibenchmark: 7 probe paralel
     * ≈ 0.6 detik (dibatasi 5 → 0.8 detik dan sesekali 2.8 detik).
     */
    protected const FLOW_CONCURRENCY = 7;

    /**
     * Batas request reverse-geocoding yang jalan bersamaan.
     */
    protected const GEO_CONCURRENCY = 6;

    /**
     * Uji validitas API key TomTom tanpa menyimpan.
     */
    public function validateKey(?string $key): array
    {
        $key = trim((string) $key);

        if ($key === '') {
            // Coba pakai key yang sedang aktif (database dulu, lalu .env)
            $key = Setting::credentialValue('tomtom_api_key');
        }

        if ($key === '') {
            return ['valid' => false, 'message' => 'API key TomTom kosong. Isi key terlebih dahulu.'];
        }

        try {
            // Endpoint ringan dan umum: Search API
            $response = Http::timeout(10)
                ->withOptions(['verify' => false])
                ->get('https://api.tomtom.com/search/2/search/Jakarta.json', [
                    'key' => $key,
                    'limit' => 1,
                    'countrySet' => 'IDN',
                ]);

            if ($response->successful()) {
                return ['valid' => true, 'message' => 'API key TomTom valid.'];
            }

            if ($response->status() === 401 || $response->status() === 403) {
                return ['valid' => false, 'message' => 'API key TomTom tidak valid atau tidak diizinkan untuk produk yang digunakan.'];
            }

            if ($response->status() === 429) {
                return ['valid' => false, 'message' => 'Terlalu banyak permintaan ke TomTom. Coba lagi beberapa saat.'];
            }

            $body = $response->json();
            $message = is_array($body) && isset($body['errorText']) ? (string) $body['errorText'] : (is_array($body) && isset($body['detailedError']['message']) ? (string) $body['detailedError']['message'] : ('Gagal menguji TomTom (HTTP '.$response->status().').'));

            return ['valid' => false, 'message' => $message];
        } catch (\Throwable $e) {
            Log::warning('TomTom key test failed: '.$e->getMessage());

            return ['valid' => false, 'message' => 'Tidak dapat terhubung ke TomTom untuk menguji API key.'];
        }
    }
    protected string $key;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->refreshConfig();
    }

    protected function refreshConfig(): void
    {
        // Key: database dulu, lalu .env (Setting::credential()).
        $this->key = Setting::credentialValue('tomtom_api_key');
        $this->baseUrl = rtrim((string) Setting::getValue('tomtom_traffic_url', 'https://api.tomtom.com/traffic/services/4'), '/');
        $this->timeout = (int) Setting::getValue('tomtom_timeout', 15);
    }

    /**
     * Asal credential aktif, untuk ditampilkan di panel status. Nilai
     * rahasianya sendiri tidak pernah ikut dikembalikan.
     */
    public function keySource(): string
    {
        return Setting::credentialSource('tomtom_api_key');
    }

    public function enabled(): bool
    {
        $this->refreshConfig();
        return $this->key !== '';
    }

    /**
     * Ambil segmen jalan beserta kondisi traffic dari satu titik probe.
     * Koordinat yang dikembalikan SELALU dari geometri jalan asli TomTom
     * (bukan titik acak / di tengah laut). Dikunci zoom 16 agar hanya jalan
     * penting yang muncul & kuota request hemat.
     */
    public function flowSegmentAt(float $lat, float $lng): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $cacheKey = 'tomtom_flow:' . round($lat, 3) . ':' . round($lng, 3);

        return Cache::remember($cacheKey, now()->addMinutes(3), function () use ($lat, $lng) {
            $url = "{$this->baseUrl}/flowSegmentData/absolute/16/json";

            try {
                $response = Http::timeout($this->timeout)
                    ->withOptions(['verify' => false])
                    ->get($url, [
                        'key' => $this->key,
                        'point' => "{$lat},{$lng}",
                    ]);

                if (! $response->successful()) {
                    // Hindari spam log untuk error umum (400/422/429) yang bisa
                    // muncul saat titik jauh dari segmen jalan
                    if ($response->status() >= 500 || $response->status() === 401 || $response->status() === 403) {
                        Log::warning("[TomTom] flow HTTP {$response->status()}: {$response->body()}");
                    }

                    return null;
                }

                return $this->extractFlow($response->json()['flowSegmentData'] ?? []);
            } catch (\Throwable $e) {
                Log::warning('[TomTom] flow exception: ' . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * Ambil segmen kemacetan untuk BANYAK titik sekaligus (paralel).
     *
     * Versi lama memanggil TomTom satu per satu (serial) sehingga 7 probe
     * butuh 3-10 detik. Di sini semua probe dikirim bersamaan lewat
     * Http::pool, jadi total waktu ≈ waktu request terlama, bukan jumlah
     * request. Hasil per titik tetap di-cache 3 menit.
     *
     * @param  array  $points daftar [lat, lng]
     * @return array daftar segmen (null untuk titik yang gagal)
     */
    public function flowSegmentsAt(array $points): array
    {
        if (! $this->enabled() || empty($points)) {
            return [];
        }

        $results = [];
        $todo = [];

        foreach (array_values($points) as $i => $p) {
            if (! is_array($p) || ! isset($p[0], $p[1])) {
                $results[$i] = null;
                continue;
            }

            $lat = (float) $p[0];
            $lng = (float) $p[1];
            $cacheKey = 'tomtom_flow:' . round($lat, 3) . ':' . round($lng, 3);

            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                $results[$i] = $cached;
                continue;
            }

            $todo[] = ['i' => $i, 'lat' => $lat, 'lng' => $lng, 'key' => $cacheKey];
        }

        if (! empty($todo)) {
            try {
                // Semua probe dikirim KONKUREN (Http::pool) sehingga total
                // waktu ≈ request terlama, bukan jumlah request.
                //
                // Batas konkurensi 7: Guzzle memakai ulang koneksi HTTP/2 ke
                // TomTom, jadi semua probe benar-benar paralel tanpa kena
                // rate-limit (dibenchmark: 7 probe ≈ 0.6 detik).
                $responses = Http::pool(function (Pool $pool) use ($todo) {
                    $out = [];
                    foreach ($todo as $t) {
                        $out[] = $pool
                            ->withOptions(['verify' => false])
                            ->timeout($this->timeout)
                            ->get("{$this->baseUrl}/flowSegmentData/absolute/16/json", [
                                'key' => $this->key,
                                'point' => "{$t['lat']},{$t['lng']}",
                            ]);
                    }

                    return $out;
                }, self::FLOW_CONCURRENCY);
            } catch (\Throwable $e) {
                Log::warning('[TomTom] flow pool exception: ' . $e->getMessage());
                $responses = [];
            }

            foreach ($todo as $n => $t) {
                $seg = null;
                $response = $responses[$n] ?? null;

                if ($response instanceof \Illuminate\Http\Client\Response) {
                    if ($response->successful()) {
                        $seg = $this->extractFlow($response->json()['flowSegmentData'] ?? []);
                    } elseif ($response->status() >= 500 || $response->status() === 401 || $response->status() === 403) {
                        Log::warning("[TomTom] flow HTTP {$response->status()}: {$response->body()}");
                    }
                }

                Cache::put($t['key'], $seg, now()->addMinutes(3));
                $results[$t['i']] = $seg;
            }
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * Kumpulkan segmen kemacetan di sepanjang titik-titik rute.
     *
     * @param array $points        daftar [lat, lng] mengikuti rute
     * @param int   $maxProbes     jumlah probe maksimum (hemat kuota)
     * @param float $maxDistanceKm jarak maksimum segmen ke probe (filter aneh)
     * @param int   $maxSegments   jumlah segmen maksimum yang dikembalikan
     */
    public function flowAlong(array $points, int $maxProbes = 30, float $maxDistanceKm = 0.5, int $maxSegments = 60): array
    {
        if (! $this->enabled() || count($points) < 2) {
            return [];
        }

        $segments = [];
        $seen = [];

        foreach ($this->samplePoints($points, $maxProbes) as $probe) {
            [$lat, $lng] = $probe;
            $seg = $this->flowSegmentAt((float) $lat, (float) $lng);

            if (! $seg) {
                continue;
            }

            $mid = $seg['midpoint'];
            $dedupeKey = round($mid[0], 3) . ',' . round($mid[1], 3);

            // Segmen yang sama dari beberapa probe hanya dihitung sekali.
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            // Keamanan: buang segmen yang jauh dari probe (kalau API mengembalikan
            // koordinat aneh/tidak wajar). TomTom selalu mengembalikan segmen
            // terdekat, jadi ini hanya jaring pengaman.
            if ($this->haversineKm($mid[0], $mid[1], $lat, $lng) > $maxDistanceKm) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $pending[] = ['mid' => $mid, 'color' => $seg['color'], 'idx' => count($segments)];
            unset($seg['midpoint']);
            $segments[] = $seg;

            if (count($segments) >= $maxSegments) {
                break;
            }
        }

        // Nama jalan tidak disediakan API Flow TomTom → reverse-geocode ke
        // Overpass/Nominatim/Geoapify khusus segmen yang macet; di-cache 24 jam biar hemat.
        $this->resolveStreetNames($segments, $pending ?? []);

        // Jangan skip reverse geocoding meski warna hijau (lancar), karena
        // daftar kemacetan perlu menampilkan nama jalan buat semua segmen.
        foreach ($segments as $idx => $seg) {
            if (is_array($seg) && empty($seg['street']) && isset($seg['midpoint'])) {
                $mid = $seg['midpoint'];
                if (is_array($mid) && isset($mid[0], $mid[1])) {
                    $segments[$idx]['street'] = $this->reverseStreet((float) $mid[0], (float) $mid[1]);
                    $segments[$idx]['roadName'] = $segments[$idx]['street'];
                }
            }
        }

        // Ruas jalan yang sama cukup tampil sekali.
        $segments = $this->dedupeByStreet($segments);

        return $segments;
    }

    /**
     * Buang segmen duplikat berdasarkan nama jalan.
     *
     * Beberapa probe sering mengembalikan ruas jalan yang sama (hanya berbeda
     * titik acuan), sehingga daftar "Titik macet" menampilkan ruas yang sama
     * beberapa kali. Segmen dengan nama jalan identik digabung menjadi satu,
     * dan yang dipertahankan adalah yang kondisi lalu lintasnya paling parah.
     * Segmen tanpa nama jalan (tidak ada data) tetap dipertahankan semua.
     */
    public function dedupeByStreet(array $segments): array
    {
        if (count($segments) < 2) {
            return array_values($segments);
        }

        $out = [];
        $indexByName = [];

        foreach ($segments as $seg) {
            if (! is_array($seg)) {
                continue;
            }

            $name = $this->normalizeStreetName((string) ($seg['street'] ?? $seg['roadName'] ?? ''));

            // Tanpa nama jalan: biarkan apa adanya (bukan duplikat yang bisa
            // dibedakan, dan biasanya memang ruas berbeda).
            if ($name === '') {
                $out[] = $seg;
                continue;
            }

            if (! isset($indexByName[$name])) {
                $indexByName[$name] = count($out);
                $out[] = $seg;
                continue;
            }

            $idx = $indexByName[$name];
            $current = $out[$idx];

            // Ganti kalau segmen baru lebih parah (warna lebih merah) atau
            // sama parah tapi jaraknya lebih pendek (lebih relevan ke viewport).
            $newSev = $this->trafficSeverity((string) ($seg['color'] ?? ''));
            $curSev = $this->trafficSeverity((string) ($current['color'] ?? ''));

            if ($newSev > $curSev) {
                $out[$idx] = $seg;
                continue;
            }

            if ($newSev === $curSev) {
                $newLen = $this->segmentLengthKm($seg);
                $curLen = $this->segmentLengthKm($current);
                if ($newLen > $curLen) {
                    $out[$idx] = $seg;
                }
            }
        }

        return $out;
    }

    /**
     * Normalisasi nama jalan untuk perbandingan: huruf kecil, spasi rapi,
     * tanpa tanda baca di ujung.
     */
    protected function normalizeStreetName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        $name = (string) preg_replace('/\s+/u', ' ', $name);
        $name = rtrim($name, " \t\n\r\0\x0B.,;:-–—");

        return mb_strtolower($name, 'UTF-8');
    }

    /**
     * Tingkat keparahan kondisi lalu lintas. Makin besar makin parah.
     * 0 = lancar, 3 = macet parah.
     */
    protected function trafficSeverity(string $color): int
    {
        $color = strtolower(trim($color));

        return match ($color) {
            '#e60000' => 3,
            '#e6b800', '#f59e0b' => 2,
            '#60a5fa', '#3b82f6' => 1,
            default => 0,
        };
    }

    /**
     * Panjang geometri segmen (km) untuk membandingkan dua segmen bernama sama.
     */
    protected function segmentLengthKm(array $seg): float
    {
        $points = $seg['points'] ?? null;
        if (! is_array($points) || count($points) < 2) {
            return 0.0;
        }

        $total = 0.0;
        $prev = null;

        foreach ($points as $p) {
            if (! is_array($p) || ! isset($p[0], $p[1])) {
                continue;
            }
            $cur = [(float) $p[0], (float) $p[1]];
            if ($prev !== null) {
                $total += $this->haversineKm($prev[0], $prev[1], $cur[0], $cur[1]);
            }
            $prev = $cur;
        }

        return $total;
    }

    /**
     * Isi nama jalan HANYA dari cache (tanpa HTTP).
     *
     * Dipakai oleh flow-bounds supaya respons tetap CEPAT: daftar kemacetan
     * langsung tampil begitu data TomTom sampai, sedangkan nama jalan yang
     * belum ada diisi lewat endpoint background (street-names).
     *
     * @param  array $segments daftar segmen (referensi)
     */
    public function applyCachedStreetNames(array &$segments): void
    {
        foreach ($segments as $idx => &$seg) {
            if (! is_array($seg) || ! empty($seg['street'])) {
                continue;
            }
            if (empty($seg['midpoint']) || ! is_array($seg['midpoint'])) {
                continue;
            }
            $mid = $seg['midpoint'];
            if (! isset($mid[0], $mid[1])) {
                continue;
            }

            $cached = Cache::get($this->streetCacheKey((float) $mid[0], (float) $mid[1]));
            if (is_string($cached) && $cached !== '') {
                $seg['street'] = $cached;
                $seg['roadName'] = $cached;
            }
        }
        unset($seg);
    }

    /**
     * Resolve nama jalan untuk segmen hasil flow-bounds/flow lainnya.
     */
    public function resolveStreets(array &$segments): void
    {
        if (empty($segments)) {
            return;
        }

        $pending = [];
        foreach ($segments as $idx => $seg) {
            if (! is_array($seg)) {
                continue;
            }
            if (! empty($seg['street'])) {
                continue;
            }
            if (empty($seg['midpoint']) || ! is_array($seg['midpoint'])) {
                continue;
            }
            $mid = $seg['midpoint'];
            if (! isset($mid[0]) || ! isset($mid[1])) {
                continue;
            }
            $pending[] = [
                'idx' => $idx,
                'mid' => [(float) $mid[0], (float) $mid[1]],
                'color' => (string) ($seg['color'] ?? ''),
            ];
        }

        if (! empty($pending)) {
            $this->resolveStreetNames($segments, $pending);
        }

        // Pastikan konsistensi field street/roadName
        foreach ($segments as $idx => &$seg) {
            if (! is_array($seg)) {
                continue;
            }
            $street = (string) ($seg['street'] ?? '');
            if ($street === '' && isset($seg['roadName'])) {
                $street = (string) $seg['roadName'];
            }
            if ($street === '') {
                $street = (string) ($seg['name'] ?? $seg['road'] ?? '');
            }
            $seg['street'] = $street;
            $seg['roadName'] = $street;
        }
        unset($seg);
    }

    /**
     * Kumpulkan segmen kemacetan unik untuk daftar titik probe.
     *
     * Dipakai baik oleh respons sinkron (flow-bounds) maupun oleh refresh
     * latar belakang, supaya keduanya memakai aturan yang sama:
     *   1. probe paralel   → flowSegmentsAt()
     *   2. buang ruas kembar → dedupe by midpoint
     *   3. isi nama dari cache saja (nama live diambil endpoint terpisah)
     *   4. gabung ruas dengan nama jalan identik
     *
     * @param  array $probes daftar [lat, lng]
     * @return array daftar segmen
     */
    public function collectFlowSegments(array $probes): array
    {
        $segments = [];
        $seen = [];

        foreach ($this->flowSegmentsAt($probes) as $seg) {
            if (! $seg || ! is_array($seg) || empty($seg['midpoint'])) {
                continue;
            }

            $mid = $seg['midpoint'];
            if (! isset($mid[0], $mid[1])) {
                continue;
            }

            $key = round($mid[0], 4) . ',' . round($mid[1], 4);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $segments[] = $seg;

            if (count($segments) >= 20) {
                break;
            }
        }

        $this->applyCachedStreetNames($segments);

        return $this->dedupeByStreet($segments);
    }

    /**
     * Resolve nama jalan untuk banyak titik sekaligus (batch + paralel).
     *
     * Versi lama resolve satu per satu (Overpass → Nominatim → Geoapify →
     * Overpass wide) sehingga satu segmen bisa 3-10 detik dan 7 segmen
     * berarti puluhan detik. Di sini:
     *   1. Cache dicek dulu (tanpa HTTP sama sekali)
     *   2. SEMUA titik dikirim KONKUREN ke Nominatim + Photon + Geoapify
     *      (satu round trip, ~0.5-1.5 detik untuk 7 titik)
     *   3. Overpass (1 query batch) hanya untuk sisa yang belum bernama
     *   4. Semua hasil di-cache 24 jam per titik
     */
    protected function resolveStreetNames(array &$segments, array $pending): void
    {
        if (empty($pending)) {
            return;
        }

        // 0) Cache dulu — paling cepat, tanpa HTTP.
        $todo = [];
        foreach ($pending as $p) {
            $idx = (int) $p['idx'];
            if (! isset($segments[$idx]) || ! empty($segments[$idx]['street'])) {
                continue;
            }
            $lat = (float) $p['mid'][0];
            $lng = (float) $p['mid'][1];
            $cacheKey = $this->streetCacheKey($lat, $lng);

            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $segments[$idx]['street'] = $cached;
                continue;
            }

            $todo[] = ['idx' => $idx, 'lat' => $lat, 'lng' => $lng, 'key' => $cacheKey];
        }

        if (empty($todo)) {
            return;
        }

        // 1) Sumber cepat saja (Nominatim ~0.1-0.9 dtk untuk 7 titik).
        //    Sumber lambat (Photon/Geoapify/Overpass) ditangani endpoint
        //    background (streetNamesForPoints) supaya daftar di halaman peta
        //    TIDAK tertahan render.
        $found = $this->reverseStreetBatchTiered($todo, [['nominatim', 'geoapify']]);
        $this->applyStreetNames($segments, $todo, $found);
    }

    /**
     * Public: ambil nama jalan untuk daftar koordinat (dipakai endpoint
     * background untuk mengisi nama yang belum ada).
     *
     * @param  array $points daftar [lat, lng]
     * @return array daftar nama (indeks sama dengan $points)
     */
    public function streetNamesForPoints(array $points, bool $deep = false): array
    {
        $todo = [];
        foreach (array_values($points) as $i => $p) {
            if (! is_array($p) || ! isset($p[0], $p[1])) {
                continue;
            }
            $lat = (float) $p[0];
            $lng = (float) $p[1];
            $todo[] = [
                'idx' => $i,
                'lat' => $lat,
                'lng' => $lng,
                'key' => $this->streetCacheKey($lat, $lng),
            ];
        }

        if (empty($todo)) {
            // Tetap kembalikan array sepanjang input (isi kosong) supaya
            // indeks respons tidak bergeser di sisi klien.
            return array_fill(0, count(array_values($points)), '');
        }

        $out = [];
        foreach ($todo as $t) {
            $cached = Cache::get($t['key']);
            $out[$t['idx']] = is_string($cached) ? $cached : '';
        }

        $need = [];
        foreach ($todo as $t) {
            if (($out[$t['idx']] ?? '') === '') {
                $need[] = $t;
            }
        }

        // Tahap 1 (default): sumber TERCEPAT. Nominatim + Geoapify jalan
        // BERSAMAAN (saling cadangan kalau salah satu kena rate-limit),
        // jadi balasan ~0.5-1.5 detik dan nama jalan muncul hampir
        // bersamaan dengan daftarnya.
        if (! empty($need)) {
            $found = $this->reverseStreetBatchTiered($need, [['nominatim', 'geoapify']]);
            foreach ($need as $i => $t) {
                $name = trim((string) ($found[$t['idx']] ?? ''));
                if ($name === '') {
                    continue;
                }
                $out[$t['idx']] = $name;
                Cache::put($t['key'], $name, now()->addHours(24));
            }
        }

        // Tahap 2 (deep): sumber cadangan (Photon/Geoapify/Overpass) hanya
        // untuk sisa yang masih belum bernama. Dipanggil terpisah supaya
        // respons tahap 1 tidak tertahan.
        if ($deep) {
            $rest = [];
            foreach ($need as $t) {
                if (($out[$t['idx']] ?? '') === '') {
                    $rest[] = $t;
                }
            }

            if (! empty($rest)) {
                $found = $this->reverseStreetBatchTiered($rest, [['photon']]);
                foreach ($rest as $t) {
                    $name = trim((string) ($found[$t['idx']] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $out[$t['idx']] = $name;
                    Cache::put($t['key'], $name, now()->addHours(24));
                }
            }

            // Cadangan Overpass (1 query batch) untuk sisa yang belum bernama.
            $rest = [];
            foreach ($need as $t) {
                if (($out[$t['idx']] ?? '') === '') {
                    $rest[] = $t;
                }
            }
            if (! empty($rest)) {
                $names = $this->overpassBatchNames($rest, 120, false);
                foreach ($rest as $i => $t) {
                    $name = trim((string) ($names[$i] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $out[$t['idx']] = $name;
                    Cache::put($t['key'], $name, now()->addHours(24));
                }
            }
        }

        // PENTING: indeks hasil harus PERSIS sama dengan indeks input.
        // Kalau ada titik yang dilewati (mis. koordinat tidak valid), memakai
        // array_values() akan menggeser semua nama dan menyebabkan nama jalan
        // menempel ke ruas yang salah.
        $total = count(array_values($points));
        $result = [];
        for ($i = 0; $i < $total; $i++) {
            $result[$i] = (string) ($out[$i] ?? '');
        }

        return $result;
    }

    protected function streetCacheKey(float $lat, float $lng): string
    {
        return 'tomtom_street:' . round($lat, 5) . ':' . round($lng, 5);
    }

    /**
     * Terapkan nama jalan hasil batch ke segmen + simpan ke cache.
     *
     * @param array $segments daftar segmen (referensi)
     * @param array $todo     daftar titik ['idx','lat','lng','key']
     * @param array $names    nama keyed by idx ASLI
     */
    protected function applyStreetNames(array &$segments, array $todo, array $names): void
    {
        foreach ($todo as $t) {
            $name = trim((string) ($names[$t['idx']] ?? ''));
            if ($name === '') {
                continue;
            }
            $idx = (int) $t['idx'];
            if (! isset($segments[$idx])) {
                continue;
            }
            $segments[$idx]['street'] = $name;
            Cache::put($t['key'], $name, now()->addHours(24));
        }
    }

    /**
     * Circuit breaker: sumber nama jalan yang sedang mati (timeout / 5xx)
     * dilewati selama 10 menit supaya tidak menahan respons.
     *
     * @param  string $source 'nominatim' | 'photon' | 'geoapify' | 'overpass'
     */
    protected function sourceUp(string $source): bool
    {
        return ! Cache::has('traffic_src_down:' . $source);
    }

    /**
     * Tandai sumber sebagai mati sementara (10 menit).
     */
    protected function markSourceDown(string $source): void
    {
        Cache::put('traffic_src_down:' . $source, 1, now()->addMinutes(10));
    }

    /**
     * SATU query Overpass untuk banyak titik sekaligus.
     *
     * @param  array  $points daftar ['idx','lat','lng','key']
     * @param  int    $radius radius pencarian (meter)
     * @param  bool   $wide   true = mode luas (cari way berlabel nama apa pun)
     * @return array nama per index (0..n-1)
     */
    protected function overpassBatchNames(array $points, int $radius = 120, bool $wide = false): array
    {
        $points = array_values($points);
        if (empty($points)) {
            return [];
        }

        // Overpass sering overload (bisa 30+ detik) → lewati dulu kalau
        // baru saja terdeteksi mati.
        if (! $this->sourceUp('overpass')) {
            return [];
        }

        $clauses = [];
        foreach ($points as $p) {
            $lat = (float) $p['lat'];
            $lng = (float) $p['lng'];
            if ($wide) {
                $clauses[] = "way(around:{$radius},{$lat},{$lng})[\"highway\"][\"name\"];";
                $clauses[] = "way(around:{$radius},{$lat},{$lng})[\"name\"];";
            } else {
                $clauses[] = "way(around:{$radius},{$lat},{$lng})[\"name\"];";
                $clauses[] = "way(around:{$radius},{$lat},{$lng})[\"alt_name\"];";
                $clauses[] = "way(around:{$radius},{$lat},{$lng})[\"ref\"];";
            }
        }

        $query = "[out:json][timeout:10];\n(\n" . implode("\n", $clauses) . "\n);\nout tags center;";

        try {
            $res = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                    'Accept' => 'application/json',
                ])
                ->timeout(6)
                ->withOptions(['verify' => false])
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', ['data' => $query]);
        } catch (\Throwable $e) {
            Log::warning('[TomTom] overpass batch gagal: ' . $e->getMessage());
            $this->markSourceDown('overpass');

            return [];
        }

        if (! $res->successful()) {
            if ($res->status() >= 500 || $res->status() === 429) {
                $this->markSourceDown('overpass');
            }

            return [];
        }

        $json = $res->json();
        $elements = is_array($json) && isset($json['elements']) ? $json['elements'] : [];
        if (empty($elements)) {
            return [];
        }

        // Kumpulkan kandidat bernama beserta koordinat.
        $cands = [];
        foreach ($elements as $el) {
            if (! is_array($el)) {
                continue;
            }
            $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
            $name = trim((string) ($tags['name'] ?? $tags['alt_name'] ?? $tags['ref'] ?? ''));
            if ($name === '') {
                continue;
            }

            $clat = null;
            $clng = null;
            if (isset($el['center']) && is_array($el['center'])) {
                $clat = isset($el['center']['lat']) ? (float) $el['center']['lat'] : null;
                $clng = isset($el['center']['lon']) ? (float) $el['center']['lon'] : null;
            } elseif (isset($el['lat']) && isset($el['lon'])) {
                $clat = (float) $el['lat'];
                $clng = (float) $el['lon'];
            }

            $cands[] = ['name' => $name, 'lat' => $clat, 'lng' => $clng];
        }

        if (empty($cands)) {
            return [];
        }

        // Untuk tiap titik ambil kandidat terdekat (dibatasi radius).
        $maxDist = $radius * 1.25;
        $out = [];
        foreach ($points as $i => $p) {
            $lat = (float) $p['lat'];
            $lng = (float) $p['lng'];
            $best = '';
            $bestDist = PHP_INT_MAX;

            foreach ($cands as $c) {
                if ($c['lat'] === null || $c['lng'] === null) {
                    if ($best === '') {
                        $best = $c['name'];
                    }
                    continue;
                }
                $d = $this->haversineKm($lat, $lng, $c['lat'], $c['lng']) * 1000;
                if ($d > $maxDist) {
                    continue;
                }
                if ($d < $bestDist) {
                    $bestDist = $d;
                    $best = $c['name'];
                    if ($bestDist <= 5) {
                        break;
                    }
                }
            }

            $out[$i] = $best;
        }

        return $out;
    }

    /**
     * Lookup nama jalan BERTINGKAT.
     *
     * Setiap "group" berisi beberapa sumber yang dijalankan KONKUREN; group
     * berikutnya hanya dijalankan untuk nama yang masih kosong. Jadi:
     *   group 1 (cepat) : Nominatim + Geoapify  → ~0.5-1.5 detik
     *   group 2 (lambat) : Photon                 → hanya untuk sisa
     *   group 3 (lambat) : Overpass (1 query)     → hanya untuk sisa
     *
     * @param  array $points daftar ['idx','lat','lng','key']
     * @param  array $groups daftar group sumber (tiap group = array sumber)
     * @return array nama keyed by idx ASLI
     */
    protected function reverseStreetBatchTiered(array $points, array $groups = [['nominatim']]): array
    {
        $result = [];
        $todo = array_values($points);

        foreach ($groups as $group) {
            if (empty($todo)) {
                break;
            }

            $group = is_array($group) ? array_values($group) : [$group];

            // PAtual dalam blok 20 supaya SEMUA titik tetap diproses.
            // Sebelumnya array_slice(0, 20) membuat titik ke-21+ dilewati
            // tanpa pernah searched (nama jalannya kosong selamanya).
            $rest = [];
            foreach (array_chunk($todo, 20) as $chunk) {
                $names = $this->requestStreetSourcesParallel($chunk, $group);

                foreach ($chunk as $i => $t) {
                    $name = trim((string) ($names[$i] ?? ''));
                    if ($name !== '') {
                        $result[$t['idx']] = $name;
                    } else {
                        $rest[] = $t;
                    }
                }
            }
            $todo = $rest;
        }

        return $result;
    }

    /**
     * Kirim request ke BEBERAPA sumber sekaligus (satu pool) lalu ambil nama
     * pertama yang tidak kosong sesuai urutan prioritas.
     *
     * Semua sumber jalan KONKUREN sehingga total waktu ≈ request terlama,
     * bukan jumlah request. Ini dipakai untuk sumber cadangan agar nama
     * jalan tidak tertahan lama.
     *
     * @param  array $points  daftar ['idx','lat','lng','key']
     * @param  array $sources urutan sumber (prioritas)
     * @return array nama per index (0..n-1)
     */
    protected function requestStreetSourcesParallel(array $points, array $sources): array
    {
        $points = array_slice(array_values($points), 0, 50);
        if (empty($points) || empty($sources)) {
            return [];
        }

        $requests = [];
        $meta = [];
        foreach ($points as $i => $p) {
            $lat = (float) $p['lat'];
            $lng = (float) $p['lng'];

            foreach ($sources as $source) {
                if (! $this->sourceUp($source)) {
                    continue;
                }

                if ($source === 'nominatim') {
                    $requests[] = [
                        'https://nominatim.openstreetmap.org/reverse',
                        ['lat' => $lat, 'lon' => $lng, 'format' => 'jsonv2', 'zoom' => 19, 'addressdetails' => 1],
                    ];
                } elseif ($source === 'photon') {
                    $requests[] = [
                        'https://photon.komoot.io/reverse',
                        ['lat' => $lat, 'lon' => $lng, 'limit' => 2, 'osm_tag' => 'highway'],
                    ];
                } else {
                    $requests[] = [
                        'https://api.geoapify.com/v1/geocode/reverse',
                        ['lat' => $lat, 'lon' => $lng, 'format' => 'json', 'apiKey' => self::GEOAPIFY_API_KEY, 'lang' => 'id'],
                    ];
                }
                $meta[] = ['i' => $i, 'src' => $source];
            }
        }

        if (empty($requests)) {
            return [];
        }

        try {
            $responses = Http::pool(function (Pool $pool) use ($requests) {
                $out = [];
                foreach ($requests as $r) {
                    $out[] = $pool
                        ->withOptions(['verify' => false])
                        ->timeout(3)
                        ->withHeaders(['User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)'])
                        ->get($r[0], $r[1]);
                }

                return $out;
            }, self::GEO_CONCURRENCY);
        } catch (\Throwable $e) {
            Log::warning('[TomTom] pool sumber nama gagal: ' . $e->getMessage());
            foreach (array_unique(array_column($meta, 'src')) as $src) {
                $this->markSourceDown($src);
            }

            return [];
        }

        // Semua request gagal → sumber sedang mati, tandai 10 menit.
        $okPerSource = [];
        foreach ($meta as $n => $m) {
            $res = $responses[$n] ?? null;
            if ($res instanceof \Illuminate\Http\Client\Response && $res->successful()) {
                $okPerSource[$m['src']] = ($okPerSource[$m['src']] ?? 0) + 1;
            }
        }
        foreach (array_unique(array_column($meta, 'src')) as $src) {
            if (empty($okPerSource[$src])) {
                $this->markSourceDown($src);
            }
        }

        // Nama pertama (urutan prioritas sumber) yang tidak kosong dipakai.
        $names = [];
        foreach ($points as $i => $p) {
            $names[$i] = '';
        }

        foreach ($meta as $n => $m) {
            $i = $m['i'];
            if ($names[$i] !== '') {
                continue;
            }

            $res = $responses[$n] ?? null;
            if (! $res instanceof \Illuminate\Http\Client\Response || ! $res->successful()) {
                continue;
            }

            $names[$i] = $this->extractStreetName((array) ($res->json() ?? []), $m['src']);
        }

        return $names;
    }

    /**
     * Ambil nama jalan dari respons JSON sebuah sumber.
     */
    protected function extractStreetName(array $json, string $source): string
    {
        if ($source === 'nominatim') {
            $addr = (array) ($json['address'] ?? []);
            $name = (string) ($addr['road'] ?? $addr['residential'] ?? $addr['living_street'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['path'] ?? $addr['service'] ?? $addr['track'] ?? $addr['cycleway'] ?? '');
            if ($name === '') {
                // Fallback halus: nama area (desa/kelurahan) lebih baik
                // daripada "Jalan (nama tidak tersedia)".
                $name = (string) ($addr['neighbourhood'] ?? $addr['hamlet'] ?? $addr['quarter'] ?? $addr['village'] ?? $addr['suburb'] ?? $addr['town'] ?? $addr['city_block'] ?? '');
            }

            return trim($name);
        }

        if ($source === 'photon') {
            return $this->extractPhotonName($json);
        }

        return $this->extractGeoapifyName($json);
    }

    /**
     * Ambil nama jalan dari respons Geoapify reverse.
     */
    protected function extractGeoapifyName(array $json): string
    {
        $results = isset($json['results']) && is_array($json['results']) ? $json['results'] : [];
        if (empty($results) || ! is_array($results[0])) {
            return '';
        }

        $r = $results[0];
        foreach (['street', 'road'] as $key) {
            if (! empty($r[$key])) {
                $s = trim((string) $r[$key]);
                if ($s !== '') {
                    return $s;
                }
            }
        }

        $name = trim((string) ($r['name'] ?? $r['address_line1'] ?? $r['formatted'] ?? $r['properties']['name'] ?? ''));
        if ($name !== '') {
            if (strpos($name, ',') !== false) {
                $parts = array_map('trim', explode(',', $name));
                if (! empty($parts[0]) && strlen($parts[0]) > 2) {
                    return $parts[0];
                }
            }

            return $name;
        }

        return trim((string) ($r['neighbourhood'] ?? $r['suburb'] ?? $r['quarter'] ?? $r['hamlet'] ?? $r['district'] ?? $r['village'] ?? ''));
    }

    /**
     * Ambil nama jalan dari respons Photon reverse (free, tanpa API key).
     *
     * Hanya fitur OSM ber-"highway" yang dipakai supaya hasilnya benar-benar
     * nama jalan — bukan nama desa/gedung.
     */
    protected function extractPhotonName(array $json): string
    {
        $features = isset($json['features']) && is_array($json['features']) ? $json['features'] : [];
        if (empty($features)) {
            return '';
        }

        foreach ($features as $feature) {
            if (! is_array($feature)) {
                continue;
            }
            $props = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

            $osmKey = (string) ($props['osm_key'] ?? '');
            $type = (string) ($props['type'] ?? '');
            if ($osmKey !== 'highway' && $type !== 'street') {
                continue;
            }

            $name = trim((string) ($props['name'] ?? ''));
            if ($name === '') {
                $name = trim((string) ($props['street'] ?? ''));
            }
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Nama jalan dari koordinat. Prioritaskan Overpass (way terdekat radius 30-50m),
     * fallback ke Nominatim, fallback ke Geoapify. Dikunci cache 24 jam.
     */
    protected function reverseStreet(float $lat, float $lng): string
    {
        return (string) Cache::remember($this->streetCacheKey($lat, $lng), now()->addHours(24), function () use ($lat, $lng) {
            // 1) Prioritaskan Overpass API untuk mencari way terdekat yang punya nama
            $overpassName = $this->reverseStreetOverpass($lat, $lng);
            if ($overpassName !== '') {
                return $overpassName;
            }

            // 2) Fallback ke Nominatim
            $nominatimName = $this->reverseStreetNominatim($lat, $lng);
            if ($nominatimName !== '') {
                return $nominatimName;
            }

            // 3) Fallback ke Geoapify
            $geoapifyName = $this->reverseStreetGeoapify($lat, $lng);
            if ($geoapifyName !== '') {
                return $geoapifyName;
            }

            // 4) Fallback ekstra: coba Overpass lebih luas tanpa batasan tag (cari way terdekat apapun yg punya name)
            $overpassName2 = $this->reverseStreetOverpassWide($lat, $lng);
            if ($overpassName2 !== '') {
                return $overpassName2;
            }

            return '';
        });
    }

    protected function reverseStreetOverpass(float $lat, float $lng): string
    {
        try {
            // Cari way terdekat dalam radius 120m yang punya tag name/alt_name/ref
            // Lebih agresif untuk jalan desa/lorong (highway=track/path/service/residential)
            $query = <<<QGL
[out:json][timeout:20];
(
  way(around:120,{$lat},{$lng})["name"];
  way(around:120,{$lat},{$lng})["alt_name"];
  way(around:120,{$lat},{$lng})["ref"];
  way(around:100,{$lat},{$lng})["name"];
  way(around:100,{$lat},{$lng})["alt_name"];
  way(around:80,{$lat},{$lng})["name"];
  way(around:80,{$lat},{$lng})["alt_name"];
  way(around:60,{$lat},{$lng})["name"];
  way(around:60,{$lat},{$lng})["alt_name"];
);
out tags center;
QGL;

            $res = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                    'Accept' => 'application/json',
                ])
                ->timeout(10)
                ->withOptions(['verify' => false])
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', [
                    'data' => $query,
                ]);

            if (! $res->successful()) {
                return '';
            }

            $json = $res->json();
            $elements = is_array($json) && isset($json['elements']) ? $json['elements'] : [];
            if (empty($elements)) {
                return '';
            }

            $bestName = '';
            $bestDist = PHP_INT_MAX;

            foreach ($elements as $el) {
                if (! is_array($el)) {
                    continue;
                }
                $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
                $name = trim((string) ($tags['name'] ?? $tags['alt_name'] ?? $tags['ref'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $clat = null;
                $clng = null;
                if (isset($el['center']) && is_array($el['center'])) {
                    $clat = isset($el['center']['lat']) ? (float) $el['center']['lat'] : null;
                    $clng = isset($el['center']['lon']) ? (float) $el['center']['lon'] : null;
                } elseif (isset($el['lat']) && isset($el['lon'])) {
                    $clat = (float) $el['lat'];
                    $clng = (float) $el['lon'];
                }

                if ($clat === null || $clng === null) {
                    if ($bestName === '') {
                        $bestName = $name;
                    }
                    continue;
                }

                $dist = $this->haversineKm($lat, $lng, $clat, $clng) * 1000; // meter
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestName = $name;
                    if ($bestDist <= 5) {
                        break;
                    }
                }
            }

            if ($bestName !== '') {
                return $bestName;
            }

            // fallback: pakai nama pertama yang ditemukan
            foreach ($elements as $el) {
                if (! is_array($el)) continue;
                $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
                $name = trim((string) ($tags['name'] ?? $tags['alt_name'] ?? $tags['ref'] ?? ''));
                if ($name !== '') {
                    return $name;
                }
            }

            return '';
        } catch (\Throwable) {
            return '';
        }
    }

    protected function reverseStreetOverpassWide(float $lat, float $lng): string
    {
        try {
            $query = <<<QGL
[out:json][timeout:20];
(
  way(around:150,{$lat},{$lng})["highway"]["name"];
  way(around:150,{$lat},{$lng})["name"];
  way(around:120,{$lat},{$lng})["name"];
);
out tags center;
QGL;

            $res = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                    'Accept' => 'application/json',
                ])
                ->timeout(15)
                ->withOptions(['verify' => false])
                ->asForm()
                ->post('https://overpass-api.de/api/interpreter', [
                    'data' => $query,
                ]);

            if (! $res->successful()) {
                return '';
            }

            $json = $res->json();
            $elements = is_array($json) && isset($json['elements']) ? $json['elements'] : [];
            if (empty($elements)) {
                return '';
            }

            $bestName = '';
            $bestDist = PHP_INT_MAX;

            foreach ($elements as $el) {
                if (! is_array($el)) {
                    continue;
                }
                $tags = is_array($el['tags'] ?? null) ? $el['tags'] : [];
                $name = trim((string) ($tags['name'] ?? $tags['alt_name'] ?? $tags['ref'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $clat = null;
                $clng = null;
                if (isset($el['center']) && is_array($el['center'])) {
                    $clat = isset($el['center']['lat']) ? (float) $el['center']['lat'] : null;
                    $clng = isset($el['center']['lon']) ? (float) $el['center']['lon'] : null;
                } elseif (isset($el['lat']) && isset($el['lon'])) {
                    $clat = (float) $el['lat'];
                    $clng = (float) $el['lon'];
                }

                if ($clat === null || $clng === null) {
                    if ($bestName === '') {
                        $bestName = $name;
                    }
                    continue;
                }

                $dist = $this->haversineKm($lat, $lng, $clat, $clng) * 1000;
                if ($dist < $bestDist) {
                    $bestDist = $dist;
                    $bestName = $name;
                    if ($bestDist <= 10) {
                        break;
                    }
                }
            }

            return $bestName;
        } catch (\Throwable) {
            return '';
        }
    }

    protected function reverseStreetNominatim(float $lat, float $lng): string
    {
        try {
            $res = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                ])
                ->timeout(8)
                ->withOptions(['verify' => false])
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'jsonv2',
                    'zoom' => 19,
                    'addressdetails' => 1,
                ]);

            if (! $res->successful()) {
                return '';
            }

            $addr = (array) ($res->json('address') ?? []);

            return (string) ($addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['residential'] ?? $addr['path'] ?? $addr['service'] ?? $addr['track'] ?? $addr['living_street'] ?? $addr['neighbourhood'] ?? $addr['hamlet'] ?? $addr['quarter'] ?? $addr['suburb'] ?? $addr['village'] ?? $addr['city_block'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    protected function reverseStreetGeoapify(float $lat, float $lng): string
    {
        try {
            $res = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                ])
                ->timeout(8)
                ->withOptions(['verify' => false])
                ->get('https://api.geoapify.com/v1/geocode/reverse', [
                    'lat' => $lat,
                    'lon' => $lng,
                    'format' => 'json',
                    'apiKey' => self::GEOAPIFY_API_KEY,
                    'lang' => 'id',
                ]);

            if (! $res->successful()) {
                return '';
            }

            return $this->extractGeoapifyName((array) ($res->json() ?? []));
        } catch (\Throwable) {
            return '';
        }
    }

    protected function samplePoints(array $points, int $n): array
    {
        $count = count($points);

        if ($count <= $n) {
            return array_values($points);
        }

        $step = ($count - 1) / max(1, $n - 1);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[] = $points[(int) round($i * $step)];
        }

        return $out;
    }

    protected function extractFlow(array $seg): ?array
    {
        $coords = $seg['coordinates']['coordinate'] ?? [];

        if (count($coords) < 2) {
            return null;
        }

        $points = array_map(
            fn ($c) => [(float) $c['latitude'], (float) $c['longitude']],
            $coords
        );

        $currentSpeed = (float) ($seg['currentSpeed'] ?? 0);
        $freeFlowSpeed = (float) ($seg['freeFlowSpeed'] ?? 0);

        $street = (string) ($seg['street'] ?? '');
        if ($street === '' && isset($seg['roadName'])) {
            $street = (string) $seg['roadName'];
        }

        return [
            'points' => $points,
            'street' => $street,
            'roadName' => $street,
            'color' => $this->flowToColor($currentSpeed, $freeFlowSpeed),
            'currentSpeed' => $currentSpeed,
            'freeFlowSpeed' => $freeFlowSpeed,
            'frc' => $seg['frc'] ?? '',
            'midpoint' => $points[(int) floor(count($points) / 2)],
        ];
    }

    protected function flowToColor(float $current, float $free): string
    {
        if ($free <= 0) {
            return '#16a34a';
        }

        $ratio = $current / $free;

        if ($ratio < 0.4) {
            return '#e60000'; // macet total / parah
        }
        if ($ratio < 0.7) {
            return '#e6b800'; // padat
        }
        if ($ratio < 0.9) {
            return '#60a5fa'; // ramai
        }

        return '#16a34a'; // lancar
    }

    protected function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}