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

        // Boleh memaksa mesin tertentu (mis. "osrm_public" untuk rute alternatif).
        $explicitEngine = $options['engine'] ?? null;
        $chain = $explicitEngine !== null && array_key_exists($explicitEngine, config('routing.engines', []))
            ? [$explicitEngine]
            : config('routing.chain', []);

// "Hindari Kemacetan Parah" → pakai TomTom (satu-satunya mesin dengan data
// kemacetan real-time di paket gratis) di urutan pertama. Bila TomTom gagal,
// mesin berikutnya di chain tetap jadi fallback. GraphHopper gratis tanpa traffic.
        if (! empty($options['avoid_traffic']) && $explicitEngine === null
            && $this->engineEnabled('tomtom') && $this->engineSupportsVehicle('tomtom', $vehicle)) {
            $chain = array_values(array_unique(array_merge(['tomtom'], $chain)));
        }

        foreach ($chain as $engineKey) {
            if (! $this->engineEnabled($engineKey)) {
                continue;
            }

            if (! $this->engineSupportsVehicle($engineKey, $vehicle)) {
                continue;
            }

            try {
                $method = match ($engineKey) {
                    'osrm_local' => 'Osrm',
                    'graphhopper' => 'Graphhopper',
                    'osrm_public' => 'OsrmPublic',
                    'tomtom' => 'Tomtom',
                    default => 'Osrm',
                };

                $result = $this->{'routeVia' . $method}(
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
            // TomTom: semua kendaraan jalan (sepeda tetap pakai mesin lain).
            'tomtom' => ! in_array($vehicle, ['sepeda'], true),
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

        // Minta hingga beberapa rute alternatif (untuk "alihkan rute" saat macet).
        if (! empty($options['alternatives'])) {
            $query['alternatives'] = 'true';
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

        $warnings = [];
        $vehicleCapacity = $this->vehicleMaxHeight($options['vehicle'], $options) ?? false;
        if ($vehicleCapacity && ! empty($options['avoid_low_bridge'])) {
            $warnings[] = 'OSRM tidak menyimpan data tinggi jembatan. Hindari jembatan rendah hanya aktif penuh jika memakai GraphHopper.';
        }

        if (in_array($options['vehicle'], ['bis', 'truk_sedang', 'truk_besar'], true)) {
            $warnings[] = 'Profil truk/bis offline menggunakan rute mobil tanpa batasan tinggi/berat. Untuk akurasi penuh, gunakan GraphHopper.';
        }

        $toSummary = function (array $r) use ($warnings): array {
            $geometry = null;
            if (! empty($r['geometry']['coordinates'])) {
                $geometry = array_map(fn ($c) => [(float) $c[1], (float) $c[0]], $r['geometry']['coordinates']);
            }

            return [
                'status' => 'ok',
                'message' => 'OK',
                'distance_m' => (float) ($r['distance'] ?? 0),
                'duration_s' => (float) ($r['duration'] ?? 0),
                'geometry' => $geometry,
                'instructions' => $this->extractOsrmSteps($r),
                'warnings' => $warnings,
            ];
        };

        $summaries = array_map($toSummary, array_slice($data['routes'] ?? [$data['routes'][0]], 0, 4));
        $result = $summaries[0];

        // Serahkan daftar rute (utama + alternatif) ke frontend untuk dipilih
        // saat kemacetan parah terdeteksi di rute utama.
        if (! empty($options['alternatives'])) {
            $result['routes'] = $summaries;
        }

        return $result;
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
                $location = $step['maneuver']['location'] ?? null;
                $steps[] = [
                    'maneuver' => $step['maneuver']['type'] ?? '',
                    'instruction' => $step['name'] ?? '',
                    'distance_m' => (float) ($step['distance'] ?? 0),
                    'duration_s' => (float) ($step['duration'] ?? 0),
                    'location' => is_array($location) && count($location) >= 2
                        ? [(float) $location[1], (float) $location[0]]
                        : null,
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

    #region Engine: TomTom (routing dengan kemacetan real-time)

    /**
     * Rute sadar-kemacetan: traffic=true (data real-time TomTom di paket gratis).
     * Truk/bis memakai mode komersial (vehicleHeight/vehicleWeight) sehingga
     * jembatan rendah & batas berat ikut dihindari mesin.
     */
    protected function routeViaTomtom(array $origin, array $destination, array $options): array
    {
        $engine = config('routing.engines.tomtom', []);
        $apiKey = $engine['api_key'] ?? config('services.tomtom.key') ?? '';

        if (empty($apiKey)) {
            return $this->error('TomTom API key kosong.');
        }

        $vehicleMap = [
            'mobil' => 'car',
            'motor' => 'car',
            'bis' => 'bus',
            'truk_sedang' => 'truck',
            'truk_besar' => 'truck',
        ];
        $ttVehicle = $vehicleMap[$options['vehicle']] ?? 'car';

        $maxHeight = $this->vehicleMaxHeight($options['vehicle'], $options);
        $maxWeight = config("routing.vehicles.{$options['vehicle']}.max_weight");

        $avoid = ['unpavedRoads'];
        if (! empty($options['avoid_toll'])) {
            $avoid[] = 'tollRoads';
        }

        $query = [
            'key' => $apiKey,
            'traffic' => 'true',
            'routeType' => 'fastest',
            'computeTravelTimeFor' => 'all',
            'language' => 'id-ID',
            'instructionsType' => 'tagged',
            'avoid' => array_unique($avoid),
            'travelMode' => $ttVehicle,
        ];

        if (in_array($ttVehicle, ['truck', 'bus'], true)) {
            if ($maxHeight) {
                $query['vehicleHeight'] = (float) $maxHeight;
            }
            if ($maxWeight) {
                $query['vehicleWeight'] = (float) $maxWeight;
            }
        }

        $via = "{$origin[0]},{$origin[1]}:{$destination[0]},{$destination[1]}";
        $url = "{$engine['url']}/calculateRoute/{$via}/json";

        // Parameter berulang (mis. avoid=a&avoid=b) harus dibangun manual,
        // karena Guzzle meng-encode array menjadi avoid[0]=a&avoid[1]=b (ditolak TomTom).
        $parts = [];
        foreach ($query as $k => $v) {
            foreach ((array) $v as $val) {
                $parts[] = $k . '=' . urlencode((string) $val);
            }
        }
        $url .= '?' . implode('&', $parts);

        $response = Http::timeout($engine['timeout'] ?? 20)
            ->withOptions(['verify' => false])
            ->get($url);

        if (! $response->successful()) {
            return $this->error("TomTom HTTP {$response->status()}.");
        }

        $route = $response->json('routes.0');

        if (empty($route)) {
            return $this->error('Rute tidak ditemukan oleh TomTom.');
        }

        $geometry = array_map(
            fn ($p) => [(float) $p['latitude'], (float) $p['longitude']],
            $route['geometry']['points'] ?? []
        );

        $summary = $route['summary'] ?? [];

        return [
            'status' => 'ok',
            'message' => 'OK',
            'distance_m' => (float) ($summary['lengthInMeters'] ?? 0),
            'duration_s' => (float) ($summary['travelTimeInSeconds'] ?? 0),
            'geometry' => $geometry,
            'instructions' => $this->tomtomInstructions($route['guidance']['instructions'] ?? []),
            'warnings' => [],
        ];
    }

    protected function tomtomInstructions(array $raw): array
    {
        $out = [];

        foreach ($raw as $idx => $step) {
            $offset = (float) ($step['routeOffsetInMeters'] ?? 0);
            $nextOffset = isset($raw[$idx + 1]) ? (float) ($raw[$idx + 1]['routeOffsetInMeters'] ?? $offset) : null;
            $dist = $nextOffset !== null ? max(0, $nextOffset - $offset) : 0;
            $loc = $step['maneuverPoint']['location'] ?? null;
            $maneuver = $this->tomtomManeuver((string) ($step['instructionType'] ?? 'continue'));

            $out[] = [
                'type' => $maneuver,
                'maneuver' => $maneuver,
                'name' => (string) ($step['street'] ?? ''),
                'instruction' => (string) ($step['message'] ?? ''),
                'distance' => $dist,
                'distance_m' => $dist,
                'duration' => 0,
                'location' => $loc ? [(float) $loc['latitude'], (float) $loc['longitude']] : null,
            ];
        }

        return $out;
    }

    protected function tomtomManeuver(string $type): string
    {
        return (string) ($this->tomtomManeuvers[$type] ?? 'continue');
    }

    protected array $tomtomManeuvers = [
        'DEPART' => 'depart',
        'ARRIVE' => 'arrive',
        'CONTINUE' => 'continue',
        'TURN_LEFT' => 'turn left',
        'TURN_RIGHT' => 'turn right',
        'TURN_SLIGHT_LEFT' => 'turn slight left',
        'TURN_SLIGHT_RIGHT' => 'turn slight right',
        'TURN_SHARP_LEFT' => 'turn sharp left',
        'TURN_SHARP_RIGHT' => 'turn sharp right',
        'UTURN' => 'uturn',
        'MERGE' => 'merge',
        'KEEP_LEFT' => 'turn slight left',
        'KEEP_RIGHT' => 'turn slight right',
        'ROUNDABOUT_LEFT' => 'roundabout',
        'ROUNDABOUT_RIGHT' => 'roundabout',
        'ROUNDABOUT_EXIT_LEFT' => 'roundabout',
        'ROUNDABOUT_EXIT_RIGHT' => 'roundabout',
        'ROUNDABOUT_UTURN' => 'roundabout',
        'FORK_LEFT' => 'fork',
        'FORK_RIGHT' => 'fork',
        'END_OF_ROAD_LEFT' => 'end of road',
        'END_OF_ROAD_RIGHT' => 'end of road',
        'END_OF_ROAD' => 'end of road',
        'EXIT_LEFT' => 'turn left',
        'EXIT_RIGHT' => 'turn right',
        'ON_RAMP_LEFT' => 'on ramp',
        'ON_RAMP_RIGHT' => 'on ramp',
        'OFF_RAMP_LEFT' => 'off ramp',
        'OFF_RAMP_RIGHT' => 'off ramp',
    ];

    #endregion

    protected function buildCustomModel(array $options, ?float $maxHeight, array &$warnings): array
    {
        $model = ['priority' => []];
        $vehicle = $options['vehicle'] ?? 'mobil';

        // Hindari tol (hanya saat pengguna meminta).
        if (! empty($options['avoid_toll'])) {
            $model['priority'][] = [
                'if' => 'get("toll") == "yes" || get("toll") == 1',
                'multiply_by' => '0.2',
            ];
        }

        // Kendaraan berat (bis/truk): jauhkan dari jalan kecil yang hanya layak
        // dilalui mobil/motor (gang, jalan lingkungan, jalan khusus pejalan kaki).
        $cfg = $this->vehicles()[$vehicle] ?? [];
        if (! empty($cfg['heavy'])) {
            $model['priority'][] = [
                'if' => 'road_class == RESIDENTIAL || road_class == SERVICE || road_class == TRACK || road_class == LIVING_STREET || road_class == PATH',
                'multiply_by' => '0.02',
            ];
            $warnings[] = 'Rute ' . ($cfg['label'] ?? 'kendaraan') . ' dijauhkan dari jalan lingkungan/gang (hanya jalan utama).';
        }

        // Hindari jembatan rendah: hanya kendaraan dengan max_height (> truk & bis).
        if (! empty($options['avoid_low_bridge']) && $maxHeight) {
            $threshold = $maxHeight + (float) config('routing.low_bridge_margin', 0.3);
            $model['priority'][] = [
                'if' => sprintf('max_height > 0 && max_height < %.2f', $threshold),
                'multiply_by' => '0.05',
            ];
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

        $coords = array_map(
            fn ($c) => [(float) $c[1], (float) $c[0]],
            $path['points']['coordinates'] ?? []
        );

        foreach ($path['instructions'] ?? [] as $instr) {
            $idx = $instr['interval'][0] ?? null;
            $steps[] = [
                'maneuver' => $instr['sign'] ?? '',
                'instruction' => $instr['text'] ?? '',
                'distance_m' => (float) ($instr['distance'] ?? 0),
                'duration_s' => (float) ($instr['time'] ?? 0) / 1000,
                'location' => ($idx !== null && isset($coords[$idx])) ? $coords[$idx] : null,
            ];
        }

        return $steps;
    }

    #endregion
}
