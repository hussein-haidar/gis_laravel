<?php

namespace App\Services\Routing;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RoutingService
{
    public function vehicles(): array
    {
        return config('routing.vehicles', []);
    }

    /**
     * Hitung rute jalan antara dua titik.
     *
     * @param array $origin      [lat, lng]
     * @param array $destination [lat, lng]
     * @param string $vehicle    mobil|motor|sepeda|bis|truk_sedang|truk_besar
     * @param array $options     avoid_toll, avoid_traffic, avoid_low_bridge (bukan default), max_height override
     * @return array
     */
    public function route(array $origin, array $destination, string $vehicle = 'mobil', array $options = []): array
    {
        $origin = $this->normalizePoint($origin);
        $destination = $this->normalizePoint($destination);

        if (! $origin || ! $destination) {
            return $this->error('Koordinat asal/tujuan tidak valid.');
        }

        $vehicle = array_key_exists($vehicle, $this->vehicles()) ? $vehicle : 'mobil';
        $options['vehicle'] = $vehicle;

        foreach (config('routing.chain', []) as $engineKey) {
            if (! $this->engineEnabled($engineKey)) {
                continue;
            }

            if (! $this->engineSupportsVehicle($engineKey, $vehicle)) {
                continue;
            }

            try {
                $result = $this->{'routeVia' . ucfirst($engineKey === 'osrm_local' ? 'Osrm' : ($engineKey === 'graphhopper' ? 'Graphhopper' : 'OsrmPublic'))}(
                    $origin,
                    $destination,
                    $options
                );

                if ($result['status'] === 'ok') {
                    $result['engine'] = $engineKey;
                    $result['vehicle'] = $vehicle;
                    $result['avoid'] = $this->collectAvoid($options);
                    return $result;
                }

                Log::warning("[Routing] {$engineKey} gagal: {$result['message']}");
            } catch (\Throwable $e) {
                Log::warning("[Routing] {$engineKey} exception: {$e->getMessage()}");
            }
        }

        return $this->error('Tidak ada mesin routing yang berhasil menjawab. Periksa koneksi/konfigurasi.');
    }

    /**
     * Deteksi kondisi lalu lintas saat ini (heuristik jam sibuk Jakarta).
     */
    public function detectTraffic(bool $includeWeather = false): array
    {
        $now = now('Asia/Jakarta');
        $hour = (int) $now->format('G');
        $day = (int) $now->format('N'); // 1=Sen..7=Min

        $rushHour = $day <= 5 && (($hour >= 6 && $hour <= 10) || ($hour >= 16 && $hour <= 20));
        $midPeak = $day <= 6 && ($hour >= 11 && $hour <= 15);

        return [
            'level' => $rushHour ? 'rush' : ($midPeak ? 'mid' : 'low'),
            'label' => $rushHour ? 'Jam Sibuk' : ($midPeak ? 'Cukup Padat' : 'Lancar'),
            'rush_hour' => $rushHour,
            'hour' => $hour,
            'day' => $day,
            'iso' => $now->toIso8601String(),
        ];
    }

    protected function engineEnabled(string $key): bool
    {
        $engine = config("routing.engines.{$key}");

        if (! $engine || empty($engine['enabled'])) {
            return false;
        }

        if ($key === 'osrm_public') {
            return true;
        }

        if ($key === 'graphhopper') {
            return ! empty(config('routing.engines.graphhopper.api_key'));
        }

        return true;
    }

    /**
     * Mesin mana yang mendukung kendaraan tertentu.
     * - osrm_local : hanya mobil & motor (server car saja tersedia)
     * - graphhopper: semua kendaraan (car/bike/bus/truck)
     * - osrm_public: mobil, motor, sepeda (driving/cycling); truk/bis tidak akurat
     */
    protected function engineSupportsVehicle(string $engineKey, string $vehicle): bool
    {
        $heavy = in_array($vehicle, ['bis', 'truk_sedang', 'truk_besar'], true);

        return match ($engineKey) {
            'osrm_local' => in_array($vehicle, ['mobil', 'motor'], true),
            'graphhopper' => true,
            'osrm_public' => ! $heavy,
            default => true,
        };
    }

    protected function profile(string $engineKey, string $vehicle): string
    {
        $vehicleCfg = $this->vehicles()[$vehicle] ?? $this->vehicles()['mobil'];

        return $vehicleCfg[$engineKey] ?? ($vehicleCfg['osrm'] ?? 'driving');
    }

    protected function normalizePoint(array $point): ?array
    {
        $lat = (float) ($point[0] ?? $point['lat'] ?? null);
        $lng = (float) ($point[1] ?? $point['lng'] ?? null);

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return null;
        }

        return [$lat, $lng];
    }

    protected function collectAvoid(array $options): array
    {
        return [
            'toll' => (bool) ($options['avoid_toll'] ?? false),
            'traffic' => (bool) ($options['avoid_traffic'] ?? false),
            'low_bridge' => (bool) ($options['avoid_low_bridge'] ?? false),
        ];
    }

    protected function vehicleMaxHeight(string $vehicle, array $options): ?float
    {
        if (isset($options['max_height']) && is_numeric($options['max_height'])) {
            return (float) $options['max_height'];
        }

        $cfg = $this->vehicles()[$vehicle] ?? null;

        return $cfg['max_height'] ?? null;
    }

    protected function error(string $message): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'engine' => null,
            'distance_m' => 0,
            'duration_s' => 0,
            'geometry' => null,
            'instructions' => [],
        ];
    }

    #region Engine: OSRM (local & public)

    protected function routeViaOsrm(array $origin, array $destination, array $options): array
    {
        return $this->routeWithOsrm($origin, $destination, $options, 'osrm_local');
    }

    protected function routeViaOsrmPublic(array $origin, array $destination, array $options): array
    {
        return $this->routeWithOsrm($origin, $destination, $options, 'osrm_public');
    }

    protected function routeWithOsrm(array $origin, array $destination, array $options, string $key): array
    {
        $engine = config("routing.engines.{$key}", []);
        $profile = $this->profile($key === 'osrm_local' ? 'osrm' : 'osrm', $options['vehicle']);

        // Pilih URL: untuk OSRM lokal gunakan server per-profil (car/bike beda port).
        $baseUrl = $engine['url'] ?? '';
        if ($key === 'osrm_local') {
            $baseUrl = config("routing.osrm_servers.{$profile}", $engine['url'] ?? null);
        }

        $coords = "{$origin[1]},{$origin[0]};{$destination[1]},{$destination[0]}";

        $query = [
            'overview' => 'full',
            'geometries' => 'geojson',
            'steps' => $this->shouldProvideSteps($options) ? 'true' : 'false',
        ];

        if (! empty($options['avoid_toll'])) {
            $query['exclude'] = 'toll';
        }

        $url = "{$baseUrl}/route/v1/{$profile}/{$coords}";

        $response = Http::timeout($engine['timeout'] ?? 15)
            ->withOptions(['verify' => false])
            ->get($url, $query);

        if (! $response->successful()) {
            return $this->error("OSRM ({$key}) HTTP {$response->status()}.");
        }

        $data = $response->json();

        if (($data['code'] ?? '') !== 'Ok' || empty($data['routes'][0])) {
            return $this->error('Rute tidak ditemukan oleh OSRM.');
        }

        $route = $data['routes'][0];

        $geometry = null;
        if (! empty($route['geometry']['coordinates'])) {
            $geometry = array_map(fn ($c) => [(float) $c[1], (float) $c[0]], $route['geometry']['coordinates']);
        }

        $warnings = [];
        $vehicleCapacity = $this->vehicleMaxHeight($options['vehicle'], $options) ?? false;
        if ($vehicleCapacity && ! empty($options['avoid_low_bridge'])) {
            $warnings[] = 'OSRM tidak menyimpan data tinggi jembatan. Hindari jembatan rendah hanya aktif penuh jika memakai GraphHopper.';
        }

        if (in_array($options['vehicle'], ['bis', 'truk_sedang', 'truk_besar'], true)) {
            $warnings[] = 'Profil truk/bis offline menggunakan rute mobil tanpa batasan tinggi/berat. Untuk akurasi penuh, gunakan GraphHopper.';
        }

        return [
            'status' => 'ok',
            'message' => 'OK',
            'distance_m' => (float) $route['distance'],
            'duration_s' => (float) $route['duration'],
            'geometry' => $geometry,
            'instructions' => $this->extractOsrmSteps($route),
            'warnings' => $warnings,
        ];
    }

    protected function shouldProvideSteps(array $options): bool
    {
        return ! empty($options['instructions']);
    }

    protected function extractOsrmSteps(array $route): array
    {
        $steps = [];

        foreach ($route['legs'] ?? [] as $leg) {
            foreach ($leg['steps'] ?? [] as $step) {
                $steps[] = [
                    'maneuver' => $step['maneuver']['type'] ?? '',
                    'instruction' => $step['name'] ?? '',
                    'distance_m' => (float) ($step['distance'] ?? 0),
                    'duration_s' => (float) ($step['duration'] ?? 0),
                ];
            }
        }

        return $steps;
    }

    #endregion

    #region Engine: GraphHopper

    protected function routeViaGraphhopper(array $origin, array $destination, array $options): array
    {
        $engine = config('routing.engines.graphhopper', []);
        $apiKey = $engine['api_key'] ?? null;

        if (! $apiKey) {
            return $this->error('GRAPHHOPPER_API_KEY belum diatur.');
        }

        $profile = $this->profile('graphhopper', $options['vehicle']);
        $maxHeight = $this->vehicleMaxHeight($options['vehicle'], $options);

        $params = [
            'key' => $apiKey,
            'profile' => $profile,
            'points_encoded' => 'false',
            'instructions' => $this->shouldProvideSteps($options) ? 'true' : 'false',
            'locale' => 'id',
        ];

        $warnings = [];

        // Hindari alternatif area tol/jalan-berbayar lewat custom_model.
        $custom = $this->buildCustomModel($options, $maxHeight, $warnings);

        if (! empty($custom)) {
            $params['custom_model'] = json_encode($custom);
        }

        // GraphHopper live traffic (premium). Jika aktif dan tersedia.
        $trafficSpeed = $engine['traffic_speed'] ?? null;
        if (! empty($options['avoid_traffic']) && ! empty($trafficSpeed)) {
            $params['traffic_speed'] = $trafficSpeed;
        }

        // GraphHopper butuh parameter "point" diulang (point=..&point=..),
        // bukan array berindeks. Begitu juga "details". Bangun URL secara manual.
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $query .= '&point=' . rawurlencode("{$origin[0]},{$origin[1]}")
                . '&point=' . rawurlencode("{$destination[0]},{$destination[1]}")
                . '&details=' . urlencode('road_class')
                . '&details=' . urlencode('toll')
                . '&details=' . urlencode('max_height');

        $url = "{$engine['url']}/route?{$query}";

        $response = Http::timeout($engine['timeout'] ?? 30)
            ->withOptions(['verify' => false])
            ->get($url);

        if (! $response->successful()) {
            $body = $response->body();
            Log::warning("[Routing] GraphHopper HTTP {$response->status()}: {$body}");
            return $this->error("GraphHopper HTTP {$response->status()}.");
        }

        $data = $response->json();

        if (empty($data['paths'][0])) {
            return $this->error('Rute tidak ditemukan oleh GraphHopper.');
        }

        $path = $data['paths'][0];

        $geometry = null;
        if (! empty($path['points']['coordinates'])) {
            $geometry = array_map(fn ($c) => [(float) $c[1], (float) $c[0]], $path['points']['coordinates']);
        }

        // Deteksi jembatan rendah pada trace (jika detail tersedia).
        if (! empty($options['avoid_low_bridge']) && $maxHeight) {
            $lowBridgeHits = $this->scanLowBridges($path, $maxHeight);
            if (! empty($lowBridgeHits)) {
                $warnings[] = 'Rute mungkin melewati jembatan/area dengan tinggi terbatas: ' . $lowBridgeHits;
            }
        }

        if (! empty($options['avoid_traffic']) && empty($trafficSpeed)) {
            $warnings[] = 'Data kemacetan real-time tidak aktif (butuh GraphHopper premium). Berlaku heuristik jam sibuk.';
        }

        return [
            'status' => 'ok',
            'message' => 'OK',
            'distance_m' => (float) ($path['distance'] ?? 0),
            'duration_s' => (float) ($path['time'] ?? 0) / 1000,
            'geometry' => $geometry,
            'instructions' => $this->extractGraphhopperSteps($path),
            'warnings' => $warnings,
        ];
    }

    protected function buildCustomModel(array $options, ?float $maxHeight, array &$warnings): array
    {
        $model = ['priority' => []];

        // Hindari tol.
        if (! empty($options['avoid_toll'])) {
            $model['priority'][] = [
                'if' => 'get("toll") == "yes" || get("toll") == 1',
                'multiply_by' => '0.2',
            ];
        }

        // Hindari jembatan rendah: kurangi priority jalan dengan max_height rendah.
        if (! empty($options['avoid_low_bridge']) && $maxHeight) {
            $threshold = $maxHeight + (float) config('routing.low_bridge_margin', 0.3);
            $model['priority'][] = [
                'if' => sprintf('max_height > 0 && max_height < %.2f', $threshold),
                'multiply_by' => '0.05',
            ];
        }

        // Hindari kemacetan (heuristik): kurangi priority arteri/tol saat jam sibuk.
        if (! empty($options['avoid_traffic'])) {
            $traffic = $this->detectTraffic();
            if ($traffic['rush_hour']) {
                $model['priority'][] = ['if' => 'road_class == PRIMARY', 'multiply_by' => '0.7'];
                $model['priority'][] = ['if' => 'road_class == SECONDARY', 'multiply_by' => '0.85'];
                $model['priority'][] = ['if' => 'road_class == MOTORWAY', 'multiply_by' => '0.6'];
            }
        }

        if (empty($model['priority'])) {
            return [];
        }

        return $model;
    }

    protected function scanLowBridges(array $path, float $maxHeight): string
    {
        $details = $path['details']['max_height'] ?? null;
        if (! $details) {
            return '';
        }

        $threshold = $maxHeight + (float) config('routing.low_bridge_margin', 0.3);
        $labels = [];

        foreach ($details as $seg) {
            [$from, $to, $value] = $seg;
            if (is_numeric($value) && (float) $value > 0 && (float) $value < $threshold) {
                $labels[] = number_format((float) $value, 1, ',', '.') . ' m';
            }
        }

        if (empty($labels)) {
            return '';
        }

        return implode(', ', array_slice(array_unique($labels), 0, 5));
    }

    protected function extractGraphhopperSteps(array $path): array
    {
        $steps = [];

        foreach ($path['instructions'] ?? [] as $instr) {
            $steps[] = [
                'maneuver' => $instr['sign'] ?? '',
                'instruction' => $instr['text'] ?? '',
                'distance_m' => (float) ($instr['distance'] ?? 0),
                'duration_s' => (float) ($instr['time'] ?? 0) / 1000,
            ];
        }

        return $steps;
    }

    #endregion
}
