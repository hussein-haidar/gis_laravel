<?php

namespace App\Services\Gis;

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GisDataSyncService
{
    protected array $stats = [
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    protected function getConfig(): array
    {
        return [
            'api_url' => Setting::getValue('gis_api_url'),
            'api_key' => Setting::getValue('gis_api_key'),
            'timeout' => Setting::getValue('gis_api_timeout', 30),
            'category_mapping' => [],
            'default_category' => 'Lainnya',
            'field_mapping' => [
                'name' => 'name',
                'description' => 'description',
                'latitude' => 'latitude',
                'longitude' => 'longitude',
                'category' => 'category',
                'photo' => 'photo',
            ],
            'geometry_field' => 'geometry',
            'identifier_field' => 'name',
            'pagination' => false,
            'page_param' => 'page',
            'per_page_param' => 'per_page',
            'per_page' => 100,
        ];
    }

    public function sync(): array
    {
        $this->resetStats();
        $this->config = $this->getConfig();

        Log::info('Starting GIS data sync', ['url' => $this->config['api_url']]);

        try {
            $features = $this->fetchAllFeatures();

            if (empty($features)) {
                Log::warning('No features found in API response');
                return $this->stats;
            }

            $this->stats['total'] = count($features);

            foreach ($features as $feature) {
                try {
                    $this->processFeature($feature);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    Log::error('Error processing feature', [
                        'feature' => $feature,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->logActivity('gis_sync_completed', null, $this->stats, sprintf(
                'Sinkronisasi GIS selesai: %d dibuat, %d diperbarui, %d dilewati, %d error',
                $this->stats['created'],
                $this->stats['updated'],
                $this->stats['skipped'],
                $this->stats['errors']
            ));

            if ($this->stats['errors'] > 0) {
                \App\Models\User::query()
                    ->whereHas('role', fn ($q) => $q->whereIn('name', ['admin', 'super_admin']))
                    ->get()
                    ->each
                    ->notify(new \App\Notifications\GisSyncStatus($this->stats));
            }

            Log::info('GIS data sync completed', $this->stats);
        } catch (\Throwable $e) {
            Log::error('GIS data sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $this->stats;
    }

    protected function fetchAllFeatures(): array
    {
        $allFeatures = [];

        if ($this->config['pagination']) {
            $page = 1;
            do {
                $response = $this->makeRequest($page);
                $features = $this->extractFeatures($response);

                if (empty($features)) {
                    break;
                }

                $allFeatures = array_merge($allFeatures, $features);
                $page++;

                if (count($features) < $this->config['per_page']) {
                    break;
                }
            } while (true);
        } else {
            $response = $this->makeRequest();
            $allFeatures = $this->extractFeatures($response);
        }

        return $allFeatures;
    }

    protected function makeRequest(int $page = 1): \Illuminate\Http\Client\Response
    {
        $url = $this->config['api_url'];
        $params = [];

        if ($this->config['pagination']) {
            $params[$this->config['page_param']] = $page;
            $params[$this->config['per_page_param']] = $this->config['per_page'];
        }

        $request = Http::timeout($this->config['timeout'])
            ->retry(3, 1000)
            ->acceptJson();

        if ($this->config['api_key']) {
            $request->withHeaders([
                'Authorization' => 'Bearer ' . $this->config['api_key'],
                'X-API-Key' => $this->config['api_key'],
            ]);
        }

        return $request->get($url, $params);
    }

    protected function extractFeatures(\Illuminate\Http\Client\Response $response): array
    {
        if (!$response->successful()) {
            throw new \Exception("API request failed: {$response->status()} - {$response->body()}");
        }

        $data = $response->json();

        if (!$data) {
            throw new \Exception('Invalid JSON response from API');
        }

        if (isset($data['type']) && $data['type'] === 'FeatureCollection') {
            return $data['features'] ?? [];
        }

        if (isset($data['features']) && is_array($data['features'])) {
            return $data['features'];
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        if (is_array($data)) {
            return $data;
        }

        throw new \Exception('Unexpected API response format. Expected FeatureCollection or array of features.');
    }

    protected function processFeature(array $feature): void
    {
        $properties = $feature['properties'] ?? $feature;
        $geometry = $feature['geometry'] ?? null;

        $identifier = $this->getIdentifier($properties);
        if (!$identifier) {
            $this->stats['skipped']++;
            Log::warning('Skipping feature: missing identifier', ['feature' => $feature]);
            return;
        }

        $locationData = $this->mapPropertiesToLocation($properties, $geometry);

        $existingLocation = Location::where('name', $identifier)->first();

        if ($existingLocation) {
            $this->updateLocation($existingLocation, $locationData);
            $this->stats['updated']++;
        } else {
            $this->createLocation($locationData);
            $this->stats['created']++;
        }
    }

    protected function getIdentifier(array $properties): ?string
    {
        $field = $this->config['identifier_field'];

        if (isset($properties[$field])) {
            return trim((string) $properties[$field]);
        }

        foreach (['name', 'nama', 'title', 'id', 'kode', 'code'] as $fallback) {
            if (isset($properties[$fallback])) {
                return trim((string) $properties[$fallback]);
            }
        }

        return null;
    }

    protected function mapPropertiesToLocation(array $properties, ?array $geometry): array
    {
        $mapping = $this->config['field_mapping'];
        $locationData = [];

        foreach ($mapping as $localField => $apiField) {
            if (isset($properties[$apiField])) {
                $value = $properties[$apiField];

                if ($localField === 'latitude' || $localField === 'longitude') {
                    $locationData[$localField] = (float) $value;
                } elseif ($localField === 'category') {
                    $locationData['category_id'] = $this->resolveCategory($value);
                } elseif ($localField === 'photo') {
                    $locationData['photo'] = $this->downloadPhoto($value);
                } else {
                    $locationData[$localField] = is_string($value) ? trim($value) : $value;
                }
            }
        }

        if (empty($locationData['latitude']) || empty($locationData['longitude'])) {
            $coords = $this->extractCoordinates($geometry, $properties);
            if ($coords) {
                $locationData['latitude'] = $coords[1];
                $locationData['longitude'] = $coords[0];
            }
        }

        if ($geometry) {
            $locationData['geometry'] = $this->normalizeGeometry($geometry);
        }

        return $locationData;
    }

    protected function extractCoordinates(?array $geometry, array $properties): ?array
    {
        if ($geometry && isset($geometry['type'], $geometry['coordinates'])) {
            if ($geometry['type'] === 'Point') {
                return $geometry['coordinates'];
            }

            if (in_array($geometry['type'], ['Polygon', 'MultiPolygon'])) {
                $centroid = $this->calculateCentroid($geometry);
                if ($centroid) {
                    return $centroid;
                }
            }
        }

        $latFields = ['lat', 'latitude', 'y'];
        $lngFields = ['lng', 'lon', 'longitude', 'x'];

        foreach ($latFields as $latField) {
            foreach ($lngFields as $lngField) {
                if (isset($properties[$latField]) && isset($properties[$lngField])) {
                    return [(float) $properties[$lngField], (float) $properties[$latField]];
                }
            }
        }

        return null;
    }

    protected function calculateCentroid(array $geometry): ?array
    {
        $type = $geometry['type'];
        $coordinates = $geometry['coordinates'];

        if ($type === 'Polygon') {
            return $this->polygonCentroid($coordinates[0]);
        }

        if ($type === 'MultiPolygon') {
            $largestArea = 0;
            $centroid = null;

            foreach ($coordinates as $polygon) {
                $ring = $polygon[0];
                $area = $this->polygonArea($ring);
                if ($area > $largestArea) {
                    $largestArea = $area;
                    $centroid = $this->polygonCentroid($ring);
                }
            }

            return $centroid;
        }

        return null;
    }

    protected function polygonArea(array $ring): float
    {
        $area = 0;
        $count = count($ring);

        for ($i = 0; $i < $count - 1; $i++) {
            $x1 = $ring[$i][0];
            $y1 = $ring[$i][1];
            $x2 = $ring[$i + 1][0];
            $y2 = $ring[$i + 1][1];
            $area += ($x1 * $y2) - ($x2 * $y1);
        }

        return abs($area) / 2;
    }

    protected function polygonCentroid(array $ring): ?array
    {
        $count = count($ring);
        if ($count < 3) {
            return null;
        }

        $centroidX = 0;
        $centroidY = 0;
        $area = 0;

        for ($i = 0; $i < $count - 1; $i++) {
            $x1 = $ring[$i][0];
            $y1 = $ring[$i][1];
            $x2 = $ring[$i + 1][0];
            $y2 = $ring[$i + 1][1];

            $cross = ($x1 * $y2) - ($x2 * $y1);
            $area += $cross;
            $centroidX += ($x1 + $x2) * $cross;
            $centroidY += ($y1 + $y2) * $cross;
        }

        $area = $area / 2;

        if ($area == 0) {
            return null;
        }

        $centroidX = $centroidX / (6 * $area);
        $centroidY = $centroidY / (6 * $area);

        return [$centroidX, $centroidY];
    }

    protected function normalizeGeometry(array $geometry): array
    {
        if (!isset($geometry['type'], $geometry['coordinates'])) {
            return ['type' => 'Point', 'coordinates' => [0, 0]];
        }

        $allowedTypes = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];

        if (!in_array($geometry['type'], $allowedTypes)) {
            return ['type' => 'Point', 'coordinates' => [0, 0]];
        }

        return [
            'type' => $geometry['type'],
            'coordinates' => $geometry['coordinates'],
        ];
    }

    protected function resolveCategory(mixed $value): ?int
    {
        if (is_numeric($value)) {
            return Category::find((int) $value)?->id;
        }

        $categoryName = trim((string) $value);

        if (isset($this->config['category_mapping'][$categoryName])) {
            $mappedName = $this->config['category_mapping'][$categoryName];
            return Category::firstOrCreate(['name' => $mappedName])->id;
        }

        return Category::firstOrCreate(['name' => $categoryName ?: $this->config['default_category']])->id;
    }

    protected function downloadPhoto(mixed $photoValue): ?string
    {
        $url = trim((string) $photoValue);

        if (empty($url) || !Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        try {
            $response = Http::timeout(10)->maxRedirects(3)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            if (strlen($body) > 5 * 1024 * 1024 || !@getimagesizefromstring($body)) {
                return null;
            }

            $extension = match ($response->header('Content-Type')) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $path = 'photos/sync_' . uniqid('', true) . '.' . $extension;

            return Storage::disk('public')->put($path, $body) ? $path : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function createLocation(array $data): Location
    {
        return Location::create($data);
    }

    protected function updateLocation(Location $location, array $data): void
    {
        $location->update($data);
    }

    protected function resetStats(): void
    {
        $this->stats = [
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    protected function logActivity(string $type, ?array $oldValues, ?array $newValues, ?string $description = null): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'subject_type' => Location::class,
            'subject_id' => null,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description ?? 'Sinkronisasi data GIS',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}