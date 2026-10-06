<?php

namespace App\Services\Traffic;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proxy data jalan OpenStreetMap (Overpass API) dengan cache di server.
 *
 * Tanpa cache, tiap gesture zoom memicu request Overpass baru dari browser.
 * Overpass_API publik membalas 429 (rate limit) dengan sangat cepat, lalu
 * front-end jatuh ke titik cadangan acak. Cache di server membuat satu
 * area hanya diminta sekali, lalu dipakai bersama oleh semua pengunjung.
 */
class OverpassRoadService
{
    /** Endpoint cadangan; yang pertama sering paling lambat. */
    protected const ENDPOINTS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
    ];

    /** Types jalan yang relevan untuk titik kemacetan. */
    protected const HIGHWAY_FILTER = '^(primary|secondary|tertiary|residential|unclassified|service|trunk|living_street)$';

    protected int $timeout;

    /** TTL cache hasil yang berisi jalan. Jaringan jalan jarang berubah. */
    protected int $ttlMinutes = 360;

    /**
     * TTL cache hasil KOSONG dari запрос yang BERHASIL. Area tanpa jalan
     * (laut, sawah) tidak boleh di-request ulang setiap gesture zoom.
     */
    protected int $emptyTtlMinutes = 15;

    /**
     * TTL cache setelah Overpass GAGAL. Sengaja sangat pendek supaya area
     * tersebut hanya terkunci sebentar, bukan berjam-jam.
     */
    protected int $failureTtlMinutes = 2;

    protected int $maxWays = 60;

    public function __construct()
    {
        $this->timeout = (int) Setting::getValue('overpass_timeout', 15);
        $this->ttlMinutes = (int) Setting::getValue('overpass_cache_minutes', 360);
        $this->emptyTtlMinutes = (int) Setting::getValue('overpass_empty_cache_minutes', 15);
        $this->maxWays = (int) Setting::getValue('overpass_max_ways', 60);
    }

    /**
     * Jalan bernama di dalam bounds, di-cache per sel grid.
     *
     * Bounds asli disnap ke luar (floor/ceil) ke sel grid supaya viewport yang
     * berdekatan memakai satu entri cache yang sama. Hasil tetap difilter ke
     * bounds asli, jadi respons selalu sesuai permintaan.
     *
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    public function roadsInBounds(float $latMin, float $lngMin, float $latMax, float $lngMax, int $zoom): array
    {
        $step = $this->gridStep($zoom);

        $cellLatMin = floor($latMin / $step) * $step;
        $cellLatMax = ceil($latMax / $step) * $step;
        $cellLngMin = floor($lngMin / $step) * $step;
        $cellLngMax = ceil($lngMax / $step) * $step;

        $key = sprintf(
            'overpass_roads:%.3f:%.3f:%.3f:%.3f:%d',
            $cellLatMin,
            $cellLngMin,
            $cellLatMax,
            $cellLngMax,
            $zoom
        );

        $roads = Cache::get($key);

        if (is_array($roads)) {
            return $this->clipToBounds($roads, $latMin, $lngMin, $latMax, $lngMax);
        }

        $roads = $this->fetchCell($cellLatMin, $cellLngMin, $cellLatMax, $cellLngMax);

        if ($roads === null) {
            // Semua endpoint gagal. Cache sebentar saja supaya tidak menahan
            // area ini terlalu lama, tapi tetap mencegah 请求 bertubi-tubi.
            Cache::put($key, [], now()->addMinutes($this->failureTtlMinutes));

            return [];
        }

        Cache::put(
            $key,
            $roads,
            $roads === [] ? now()->addMinutes($this->emptyTtlMinutes) : now()->addMinutes($this->ttlMinutes)
        );

        return $this->clipToBounds($roads, $latMin, $lngMin, $latMax, $lngMax);
    }

    /**
     * Potong daftar jalan ke bounds asli supaya tidak mengirim jalan di luar
     * viewport yang diminta.
     *
     * @param  array<int, array{name: string, lat: float, lng: float}>  $roads
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    protected function clipToBounds(array $roads, float $latMin, float $lngMin, float $latMax, float $lngMax): array
    {
        return array_values(array_filter($roads, function ($r) use ($latMin, $latMax, $lngMin, $lngMax) {
            return $r['lat'] >= $latMin && $r['lat'] <= $latMax
                && $r['lng'] >= $lngMin && $r['lng'] <= $lngMax;
        }));
    }

    /**
     * Ukuran sel grid dalam derajat. Sel lebih kecil di zoom tinggi supaya
     * tidak mengambil terlalu banyak jalan per respons; sel lebih besar di
     * zoom rendah karena layar yang menutupi area sangat luas.
     */
    protected function gridStep(int $zoom): float
    {
        if ($zoom >= 15) {
            return 0.02;   // ~2,2 km
        }
        if ($zoom >= 12) {
            return 0.05;   // ~5,5 km
        }
        if ($zoom >= 10) {
            return 0.15;   // ~16 km
        }

        return 0.5;       // ~55 km
    }

    /**
     * Ambil satu sel grid dari Overpass.
     *
     * Penting: kembalikan `null` bila semua endpoint GAGAL, dan `[]` bila
     * Overpass berhasil menjawab tapi memang tidak ada jalan bernama di sana.
     * Pemanggil memakai nilai ini untuk memilih TTL cache yang benar.
     *
     * @return array<int, array{name: string, lat: float, lng: float}>|null
     */
    protected function fetchCell(float $latMin, float $lngMin, float $latMax, float $lngMax): ?array
    {
        $bbox = $latMin . ',' . $lngMin . ',' . $latMax . ',' . $lngMax;
        $query = '[out:json][timeout:' . $this->timeout . '];'
            . 'way["highway"~"' . self::HIGHWAY_FILTER . '"](' . $bbox . ');'
            . 'out center tags ' . $this->maxWays . ';';

        foreach (self::ENDPOINTS as $index => $endpoint) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withOptions(['verify' => false])
                    ->withHeaders(['User-Agent' => 'gis-laravel/1.0 (Overpass proxy)'])
                    ->get($endpoint, ['data' => $query]);

                if ($response->status() === 429) {
                    Log::warning("[Overpass] endpoint #{$index} kena rate limit (429).");

                    continue;
                }

                if ($response->status() === 504) {
                    Log::warning("[Overpass] endpoint #{$index} timeout (504), query terlalu berat.");

                    continue;
                }

                if (! $response->successful()) {
                    Log::warning("[Overpass] endpoint #{$index} HTTP {$response->status()}.");

                    continue;
                }

                return $this->parse($response->json());
            } catch (\Throwable $e) {
                Log::warning('[Overpass] endpoint #' . $index . ' exception: ' . $e->getMessage());
            }
        }

        Log::warning('[Overpass] semua endpoint gagal untuk bbox ' . $bbox);

        return null;
    }

    /**
     * Ubah respons Overpass menjadi daftar jalan unik. Satu nama jalan hanya
     * masuk sekali supaya marker tidak menumpuk di persimpangan yang sama.
     *
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array{name: string, lat: float, lng: float}>
     */
    protected function parse(?array $payload): array
    {
        $elements = $payload['elements'] ?? [];

        if (! is_array($elements)) {
            return [];
        }

        $roads = [];
        $seen = [];

        foreach ($elements as $element) {
            if (($element['type'] ?? null) !== 'way' || empty($element['center'])) {
                continue;
            }

            $lat = (float) ($element['center']['lat'] ?? 0);
            $lng = (float) ($element['center']['lon'] ?? 0);

            if (! is_finite($lat) || ! is_finite($lng) || ($lat === 0.0 && $lng === 0.0)) {
                continue;
            }

            $name = trim((string) ($element['tags']['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $key = mb_strtolower($name);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $roads[] = ['name' => $name, 'lat' => $lat, 'lng' => $lng];
        }

        return $roads;
    }
}