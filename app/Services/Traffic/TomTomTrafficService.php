<?php

namespace App\Services\Traffic;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TomTomTrafficService
{
    protected string $key;
    protected string $baseUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->key = (string) config('services.tomtom.key', '');
        $this->baseUrl = rtrim((string) config('services.tomtom.traffic_url', 'https://api.tomtom.com/traffic/services/4'), '/');
        $this->timeout = (int) config('services.tomtom.timeout', 15);
    }

    public function enabled(): bool
    {
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
                    Log::warning("[TomTom] flow HTTP {$response->status()}: {$response->body()}");

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
        // Nominatim (OSM) khusus segmen yang macet; di-cache 24 jam biar hemat.
        $this->resolveStreetNames($segments, $pending ?? []);

        return $segments;
    }

    protected function resolveStreetNames(array &$segments, array $pending): void
    {
        $named = 0;

        foreach ($pending as $p) {
            if ($named >= 12) {
                break;
            }

            $color = (string) ($p['color'] ?? '');
            if ($color === '#16a34a') {
                continue;
            }

            $idx = (int) $p['idx'];
            if (! isset($segments[$idx]) || ! empty($segments[$idx]['street'])) {
                continue;
            }

            $segments[$idx]['street'] = $this->reverseStreet($p['mid'][0], $p['mid'][1]);
            $named++;
        }
    }

    /**
     * Nama jalan dari koordinat (Nominatim OSM). Dikunci cache 24 jam.
     */
    protected function reverseStreet(float $lat, float $lng): string
    {
        return (string) Cache::remember('tomtom_street:' . round($lat, 5) . ':' . round($lng, 5), now()->addHours(24), function () use ($lat, $lng) {
            try {
                $res = Http::withHeaders(['User-Agent' => 'GIS-Laravel/1.0'])
                    ->timeout(6)
                    ->withOptions(['verify' => false])
                    ->get('https://nominatim.openstreetmap.org/reverse', [
                        'lat' => $lat,
                        'lon' => $lng,
                        'format' => 'jsonv2',
                        'zoom' => 17,
                        'addressdetails' => 1,
                    ]);

                if (! $res->successful()) {
                    return '';
                }

                $addr = (array) ($res->json('address') ?? []);

                return (string) ($addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? $addr['residential'] ?? '');
            } catch (\Throwable) {
                return '';
            }
        });
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

        return [
            'points' => $points,
            'street' => (string) ($seg['street'] ?? ''),
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