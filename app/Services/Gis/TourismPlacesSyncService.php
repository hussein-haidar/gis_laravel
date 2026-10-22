<?php

namespace App\Services\Gis;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TourismPlacesSyncService
{
    protected array $stats = [
        'total' => 0,
        'created' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    protected array $config = [];
    protected ?array $photoFetchStats = null;

    // Command options
    protected bool $dryRun = false;
    protected bool $skipPhoto = false;
    protected ?int $limit = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->getDefaultConfig(), $config);
    }

    public function setEnabledSources(array $sources): void
    {
        foreach (['overpass', 'datagoi', 'custom_api'] as $source) {
            $this->config[$source]['enabled'] = in_array($source, $sources);
        }
    }

    public function setDefaultCategory(string $category): void
    {
        $this->config['default_category'] = $category;
    }

    public function setSkipPhoto(bool $skip): void
    {
        $this->skipPhoto = $skip;
    }

    public function setLimit(int $limit): void
    {
        $this->limit = $limit;
    }

    public function setDryRun(bool $dryRun): void
    {
        $this->dryRun = $dryRun;
    }

    protected function getDefaultConfig(): array
    {
        return [
            // Overpass API (OSM) settings
            'overpass' => [
                'enabled' => true,
                'endpoints' => [
                    'https://overpass-api.de/api/interpreter',
                    'https://overpass.kumi.systems/api/interpreter',
                    'https://overpass.osm.ch/api/interpreter',
                    'https://overpass.openstreetmap.ru/api/interpreter',
                ],
                'timeout' => 45,
                'per_province' => true,
                'tags' => [
                    'tourism' => ['attraction', 'museum', 'artwork', 'gallery', 'zoo', 'theme_park', 'viewpoint', 'information', 'aquarium'],
                    'historic' => ['monument', 'memorial', 'castle', 'ruins', 'archaeological_site', 'temple', 'church', 'mosque', 'shrine', 'wayside_cross', 'wayside_shrine'],
                    'leisure' => ['park', 'garden', 'nature_reserve', 'beach_resort', 'water_park', 'playground', 'sports_centre', 'stadium', 'golf_course', 'marina'],
                    'natural' => ['beach', 'peak', 'volcano', 'cave_entrance', 'spring', 'waterfall', 'hot_spring', 'geyser', 'cliff', 'rock', 'cave'],
                    'amenity' => ['restaurant', 'cafe', 'fast_food', 'pub', 'bar', 'food_court', 'ice_cream', 'place_of_worship', 'hospital', 'clinic', 'pharmacy', 'marketplace', 'mall'],
                    'shop' => ['souvenir', 'handicraft', 'art', 'gallery'],
                    'building' => ['temple', 'mosque', 'church', 'shrine', 'castle', 'fort'],
                ],
            ],

            // Data.go.id settings
            'datagoi' => [
                'enabled' => true,
                'base_url' => 'https://data.go.id/api/3/action',
                'timeout' => 30,
                'resource_ids' => [
                    // These are example dataset IDs - need to be updated with actual ones
                    'wisata-indonesia' => 'example-resource-id-1',
                    'tempat-wisata' => 'example-resource-id-2',
                ],
            ],

            // Custom API settings
            'custom_api' => [
                'enabled' => true,
                'url' => config('services.custom_api.url'),
                'timeout' => config('services.custom_api.timeout', 30),
                'headers' => config('services.custom_api.headers', []),
                'field_mapping' => config('services.custom_api.field_mapping', [
                    'name' => 'name',
                    'category' => 'category',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'photo' => 'photo',
                    'description' => 'description',
                    'address' => 'address',
                    'phone' => 'phone',
                    'website' => 'website',
                    'opening_hours' => 'opening_hours',
                ]),
            ],

            // General settings
            'default_category' => 'Wisata Alam',
            'category_mapping' => [
                'attraction' => 'Wisata Alam',
                'museum' => 'Wisata Budaya',
                'artwork' => 'Wisata Budaya',
                'gallery' => 'Wisata Budaya',
                'zoo' => 'Wisata Alam',
                'theme_park' => 'Tempat Umum',
                'viewpoint' => 'Wisata Alam',
                'monument' => 'Wisata Sejarah',
                'memorial' => 'Wisata Sejarah',
                'castle' => 'Wisata Sejarah',
                'ruins' => 'Wisata Sejarah',
                'archaeological_site' => 'Wisata Sejarah',
                'temple' => 'Wisata Religi',
                'church' => 'Wisata Religi',
                'mosque' => 'Wisata Religi',
                'shrine' => 'Wisata Religi',
                'park' => 'Wisata Alam',
                'garden' => 'Wisata Alam',
                'nature_reserve' => 'Wisata Alam',
                'beach_resort' => 'Wisata Alam',
                'water_park' => 'Tempat Umum',
                'beach' => 'Wisata Alam',
                'peak' => 'Wisata Alam',
                'volcano' => 'Wisata Alam',
                'waterfall' => 'Wisata Alam',
                'hot_spring' => 'Wisata Alam',
                'cliff' => 'Wisata Alam',
                'restaurant' => 'Wisata Kuliner',
                'cafe' => 'Wisata Kuliner',
                'fast_food' => 'Wisata Kuliner',
                'marketplace' => 'Wisata Kuliner',
                'mall' => 'Tempat Umum',
            ],

            'identifier_field' => 'name',
            'auto_approve_admin_upload' => true,
            'skip_existing_with_photo' => true,
        ];
    }

    public function sync(): array
    {
        $this->resetStats();
        Log::info('Starting Tourism Places sync', ['config_keys' => array_keys($this->config)]);

        try {
            $allPlaces = new Collection();

            // 1. Overpass API (OSM)
            if ($this->config['overpass']['enabled']) {
                $overpassPlaces = $this->fetchFromOverpass();
                $allPlaces = $allPlaces->merge($overpassPlaces);
                Log::info('Overpass API done', ['count' => $overpassPlaces->count()]);
            }

            // 2. Data.go.id
            if ($this->config['datagoi']['enabled']) {
                $datagoiPlaces = $this->fetchFromDataGoi();
                $allPlaces = $allPlaces->merge($datagoiPlaces);
                Log::info('Data.go.id done', ['count' => $datagoiPlaces->count()]);
            }

            // 3. Custom API
            if ($this->config['custom_api']['enabled'] && !empty($this->config['custom_api']['url'])) {
                $customPlaces = $this->fetchFromCustomApi();
                $allPlaces = $allPlaces->merge($customPlaces);
                Log::info('Custom API done', ['count' => $customPlaces->count()]);
            }

            // Deduplicate by name + coordinates (approx)
            $allPlaces = $this->deduplicatePlaces($allPlaces);

            // Apply limit if set
            if ($this->limit !== null && $this->limit > 0) {
                $allPlaces = $allPlaces->take($this->limit);
            }

            $this->stats['total'] = $allPlaces->count();
            Log::info('Total unique places after dedupe', ['count' => $this->stats['total']]);

            foreach ($allPlaces as $place) {
                try {
                    $this->processPlace($place);
                } catch (\Throwable $e) {
                    $this->stats['errors']++;
                    Log::error('Error processing place', [
                        'place' => $place['name'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Trigger photo fetch for new/updated places (unless skipped or dry run)
            if (!$this->skipPhoto && !$this->dryRun) {
                $this->fetchMissingPhotos();
            }

            Log::info('Tourism Places sync completed', $this->stats);
        } catch (\Throwable $e) {
            Log::error('Tourism Places sync failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }

        return $this->stats;
    }

    protected function fetchFromOverpass(): Collection
    {
        $places = new Collection();
        $overpassConfig = $this->config['overpass'];
        $endpoints = $overpassConfig['endpoints'] ?? [$overpassConfig['endpoint'] ?? 'https://overpass-api.de/api/interpreter'];
        $timeout = $overpassConfig['timeout'];

        // Build query for all tourism-related tags
        $tagClauses = [];
        foreach ($overpassConfig['tags'] as $key => $values) {
            foreach ($values as $value) {
                $tagClauses[] = "node[\"{$key}\"=\"{$value}\"]({{bbox}});";
                $tagClauses[] = "way[\"{$key}\"=\"{$value}\"]({{bbox}});";
                $tagClauses[] = "relation[\"{$key}\"=\"{$value}\"]({{bbox}});";
            }
        }

        // If per_province, we'll run for each province bbox
        // For now, use Indonesia bounding box
        $indonesiaBbox = '-11.0,95.0,6.0,141.0'; // south,west,north,east
        
        // Simpler query - only key tourism types as node, limit to 100 results
        $simpleTags = [
            'node["tourism"~"^(attraction|museum|gallery|zoo|theme_park|viewpoint|aquarium)$"]({{bbox}});',
            'node["historic"~"^(monument|memorial|castle|ruins|archaeological_site|temple|church|mosque|shrine)$"]({{bbox}});',
            'node["leisure"~"^(park|garden|nature_reserve|beach_resort|water_park)$"]({{bbox}});',
            'node["natural"~"^(beach|peak|volcano|waterfall|hot_spring|cliff)$"]({{bbox}});',
        ];
        
        $query = "[out:json][timeout:{$timeout}];\n(\n" . implode("\n", $simpleTags) . "\n);\nout center tags 100;";
        $query = str_replace('{{bbox}}', $indonesiaBbox, $query);

        // Try each endpoint until one works
        foreach ($endpoints as $endpoint) {
            $endpointPlaces = $this->queryOverpassEndpoint($endpoint, $query, $timeout);
            if ($endpointPlaces->isNotEmpty()) {
                $places = $places->merge($endpointPlaces);
                break;
            }
        }

        return $places;
    }

    protected function queryOverpassEndpoint(string $endpoint, string $query, int $timeout): Collection
    {
        $places = new Collection();

        // Retry logic for Overpass API (often at capacity)
        $maxRetries = 2;
        $response = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'GIS-Laravel/1.0 (contact: admin@gis-laravel.local)',
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ])
                    ->timeout($timeout)
                    ->withOptions(['verify' => false])
                    ->asForm()
                    ->post($endpoint, ['data' => $query]);
            } catch (\Throwable $e) {
                Log::warning("Overpass API {$endpoint} connection error (attempt {$attempt}/{$maxRetries})", [
                    'error' => $e->getMessage(),
                ]);
                $response = null;
                if ($attempt < $maxRetries) {
                    sleep($attempt * 2);
                    continue;
                }
                break;
            }

            if ($response && $response->successful()) {
                break;
            }

            if ($response->status() === 502 || $response->status() === 503) {
                Log::warning("Overpass API {$endpoint} at capacity (attempt {$attempt}/{$maxRetries})", [
                    'status' => $response->status(),
                ]);
                if ($attempt < $maxRetries) {
                    sleep($attempt * 2);
                    continue;
                }
            } else {
                break;
            }
        }

        if (!$response || !$response->successful()) {
            Log::warning("Overpass API {$endpoint} failed after retries", ['status' => $response?->status() ?? 'no response']);
            return $places;
        }

        $data = $response->json();
        $elements = $data['elements'] ?? [];

        foreach ($elements as $el) {
            if (!is_array($el)) continue;

            $tags = $el['tags'] ?? [];
            $name = $tags['name'] ?? $tags['name:id'] ?? $tags['alt_name'] ?? $tags['official_name'] ?? '';
            if (empty($name)) continue;

            $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
            $lon = $el['lon'] ?? ($el['center']['lon'] ?? null);
            if ($lat === null || $lon === null) continue;

            // Determine category from tags
            $category = $this->determineCategoryFromOsmTags($tags);

            $places->push([
                'name' => $name,
                'latitude' => (float)$lat,
                'longitude' => (float)$lon,
                'category' => $category,
                'description' => $this->buildDescriptionFromTags($tags),
                'address' => $this->buildAddressFromTags($tags),
                'phone' => $tags['phone'] ?? $tags['contact:phone'] ?? null,
                'website' => $tags['website'] ?? $tags['contact:website'] ?? null,
                'opening_hours' => $tags['opening_hours'] ?? null,
                'source' => 'overpass',
                'osm_id' => $el['id'] ?? null,
                'osm_type' => $el['type'] ?? null,
                'tags' => $tags,
            ]);
        }

        return $places;
    }

    protected function determineCategoryFromOsmTags(array $tags): string
    {
        // Priority order for category determination
        $priorityTags = [
            'tourism' => ['attraction', 'museum', 'artwork', 'gallery', 'zoo', 'theme_park', 'viewpoint', 'aquarium'],
            'historic' => ['monument', 'memorial', 'castle', 'ruins', 'archaeological_site', 'temple', 'church', 'mosque', 'shrine'],
            'leisure' => ['park', 'garden', 'nature_reserve', 'beach_resort', 'water_park', 'playground', 'sports_centre', 'stadium', 'golf_course', 'marina'],
            'natural' => ['beach', 'peak', 'volcano', 'cave_entrance', 'spring', 'waterfall', 'hot_spring', 'geyser', 'cliff', 'rock'],
            'amenity' => ['restaurant', 'cafe', 'fast_food', 'pub', 'bar', 'food_court', 'ice_cream', 'place_of_worship'],
            'shop' => ['souvenir', 'handicraft', 'art'],
            'building' => ['temple', 'mosque', 'church', 'shrine', 'castle', 'fort'],
        ];

        foreach ($priorityTags as $key => $values) {
            if (isset($tags[$key]) && in_array($tags[$key], $values)) {
                return $this->config['category_mapping'][$tags[$key]] ?? $this->config['default_category'];
            }
        }

        return $this->config['default_category'];
    }

    protected function buildDescriptionFromTags(array $tags): string
    {
        $parts = [];
        if (!empty($tags['description'])) $parts[] = $tags['description'];
        if (!empty($tags['description:id'])) $parts[] = $tags['description:id'];
        if (!empty($tags['wikipedia'])) $parts[] = "Wikipedia: {$tags['wikipedia']}";
        if (!empty($tags['wikidata'])) $parts[] = "Wikidata: {$tags['wikidata']}";

        return implode(' | ', $parts);
    }

    protected function buildAddressFromTags(array $tags): ?string
    {
        $addressParts = [];
        foreach (['addr:street', 'addr:housenumber', 'addr:city', 'addr:postcode', 'addr:district', 'addr:subdistrict'] as $key) {
            if (!empty($tags[$key])) {
                $addressParts[] = $tags[$key];
            }
        }
        return $addressParts ? implode(', ', $addressParts) : null;
    }

    protected function fetchFromDataGoi(): Collection
    {
        $places = new Collection();
        $datagoiConfig = $this->config['datagoi'];

        // This is a template - actual implementation depends on specific dataset structure
        // Data.go.id uses CKAN API: https://data.go.id/api/3/action/datastore_search?resource_id=XXX&limit=1000

        foreach ($datagoiConfig['resource_ids'] as $datasetName => $resourceId) {
            if (str_starts_with($resourceId, 'example-')) {
                Log::info("Skipping Data.go.id dataset '{$datasetName}' - example resource ID");
                continue;
            }

            try {
                $url = "{$datagoiConfig['base_url']}/datastore_search";
                $response = Http::timeout($datagoiConfig['timeout'])
                    ->get($url, [
                        'resource_id' => $resourceId,
                        'limit' => 1000,
                    ]);

                if (!$response->successful()) continue;

                $data = $response->json();
                $records = $data['result']['records'] ?? [];

                foreach ($records as $record) {
                    // Map Data.go.id fields to our schema - NEEDS CUSTOMIZATION per dataset
                    $place = $this->mapDatagoiRecord($record, $datasetName);
                    if ($place) $places->push($place);
                }
            } catch (\Throwable $e) {
                Log::warning("Data.go.id fetch failed for {$datasetName}", ['error' => $e->getMessage()]);
            }
        }

        return $places;
    }

    protected function mapDatagoiRecord(array $record, string $datasetName): ?array
    {
        // This needs to be customized based on actual Data.go.id dataset schema
        // Example mapping - adjust field names based on actual dataset
        $fieldMap = [
            'name' => ['nama', 'name', 'nama_tempat', 'nama_wisata'],
            'category' => ['kategori', 'category', 'jenis', 'tipe'],
            'latitude' => ['latitude', 'lat', 'x', 'geom_y'],
            'longitude' => ['longitude', 'lon', 'lng', 'y', 'geom_x'],
            'description' => ['deskripsi', 'description', 'keterangan'],
            'address' => ['alamat', 'address', 'lokasi'],
            'phone' => ['telepon', 'phone', 'no_telp'],
            'website' => ['website', 'url', 'situs_web'],
        ];

        $place = ['source' => "datagoi:{$datasetName}"];
        foreach ($fieldMap as $ourField => $possibleFields) {
            foreach ($possibleFields as $field) {
                if (isset($record[$field]) && !empty($record[$field])) {
                    $place[$ourField] = $record[$field];
                    break;
                }
            }
        }

        // Validate required fields
        if (empty($place['name']) || !isset($place['latitude']) || !isset($place['longitude'])) {
            return null;
        }

        // Convert coordinates
        $place['latitude'] = (float)$place['latitude'];
        $place['longitude'] = (float)$place['longitude'];

        // Map category
        if (!empty($place['category'])) {
            $place['category'] = $this->mapCategory($place['category']);
        } else {
            $place['category'] = $this->config['default_category'];
        }

        return $place;
    }

    protected function fetchFromCustomApi(): Collection
    {
        $places = new Collection();
        $customConfig = $this->config['custom_api'];

        try {
            $request = Http::timeout($customConfig['timeout'])
                ->withHeaders($customConfig['headers'])
                ->acceptJson()
                ->get($customConfig['url']);

            if (!$request->successful()) {
                Log::warning('Custom API failed', ['status' => $request->status(), 'url' => $customConfig['url']]);
                return $places;
            }

            $data = $request->json();

            // Handle different response formats
            $items = $this->extractItemsFromResponse($data);
            if (empty($items)) return $places;

            $mapping = $customConfig['field_mapping'];

            foreach ($items as $item) {
                if (!is_array($item)) continue;

                $place = ['source' => 'custom_api'];

                foreach ($mapping as $ourField => $apiField) {
                    if (isset($item[$apiField]) && $item[$apiField] !== '') {
                        $place[$ourField] = $item[$apiField];
                    }
                }

                // Validate required
                if (empty($place['name']) || !isset($place['latitude']) || !isset($place['longitude'])) {
                    continue;
                }

                $place['latitude'] = (float)$place['latitude'];
                $place['longitude'] = (float)$place['longitude'];

                // Map category
                if (!empty($place['category'])) {
                    $place['category'] = $this->mapCategory($place['category']);
                } else {
                    $place['category'] = $this->config['default_category'];
                }

                // Handle photo URL
                if (!empty($place['photo'])) {
                    $place['photo_url'] = $place['photo'];
                    unset($place['photo']);
                }

                $places->push($place);
            }
        } catch (\Throwable $e) {
            Log::warning('Custom API exception', ['error' => $e->getMessage()]);
        }

        return $places;
    }

    protected function extractItemsFromResponse(mixed $data): array
    {
        if (!is_array($data)) return [];

        // FeatureCollection (GeoJSON)
        if (isset($data['type']) && $data['type'] === 'FeatureCollection') {
            return array_map(function ($f) {
                $props = $f['properties'] ?? [];
                if (isset($f['geometry']['coordinates'])) {
                    $props['longitude'] = $f['geometry']['coordinates'][0];
                    $props['latitude'] = $f['geometry']['coordinates'][1];
                }
                return $props;
            }, $data['features'] ?? []);
        }

        // Array of objects
        if (isset($data['features']) && is_array($data['features'])) {
            return $data['features'];
        }

        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        if (isset($data['results']) && is_array($data['results'])) {
            return $data['results'];
        }

        if (is_array($data) && !empty($data) && isset($data[0]) && is_array($data[0])) {
            return $data;
        }

        return [];
    }

    protected function mapCategory(string $rawCategory): string
    {
        $normalized = Str::lower(trim($rawCategory));

        // Direct mapping
        if (isset($this->config['category_mapping'][$normalized])) {
            return $this->config['category_mapping'][$normalized];
        }

        // Partial match
        foreach ($this->config['category_mapping'] as $key => $mapped) {
            if (Str::contains($normalized, Str::lower($key))) {
                return $mapped;
            }
        }

        // Check against PLACE_TYPES
        foreach (Category::PLACE_TYPES as $type) {
            if (Str::contains($normalized, Str::lower($type))) {
                return $type;
            }
        }

        return $this->config['default_category'];
    }

    protected function deduplicatePlaces(Collection $places): Collection
    {
        // Deduplicate by name + approximate coordinates (within ~100m)
        $groups = $places->groupBy(function ($place) {
            $lat = round($place['latitude'], 3);
            $lon = round($place['longitude'], 3);
            return Str::lower($place['name']) . "|{$lat}|{$lon}";
        });

        return $groups->map(function ($group) {
            // Keep the one with most complete data
            return $group->sortByDesc(function ($p) {
                $score = 0;
                $score += !empty($p['description']) ? 10 : 0;
                $score += !empty($p['address']) ? 5 : 0;
                $score += !empty($p['phone']) ? 3 : 0;
                $score += !empty($p['website']) ? 3 : 0;
                $score += !empty($p['photo_url']) ? 20 : 0;
                $score += !empty($p['opening_hours']) ? 2 : 0;
                return $score;
            })->first();
        })->values();
    }

    protected function processPlace(array $place): void
    {
        $identifier = $place['name'];

        $existing = Location::where('name', $identifier)->first();

        // Build location data
        $locationData = [
            'name' => $place['name'],
            'description' => $place['description'] ?? null,
            'latitude' => $place['latitude'],
            'longitude' => $place['longitude'],
            'category_id' => $this->resolveCategory($place['category']),
        ];

        // Additional fields
        if (!empty($place['address'])) $locationData['address'] = $place['address'];
        if (!empty($place['phone'])) $locationData['phone'] = $place['phone'];
        if (!empty($place['website'])) $locationData['website'] = $place['website'];
        if (!empty($place['opening_hours'])) $locationData['opening_hours'] = $place['opening_hours'];

        // Handle photo (unless skipPhoto)
        if (!$this->skipPhoto && !empty($place['photo_url'])) {
            $photoPath = $this->downloadPhoto($place['photo_url']);
            if ($photoPath) {
                $locationData['photo'] = $photoPath;
                $locationData['photo_source_title'] = $place['name'];
                $locationData['photo_source_url'] = $place['photo_url'];
                $locationData['photo_source_provider'] = $place['source'] ?? 'unknown';
                $locationData['photo_fetched_at'] = now();
                $locationData['photo_review_status'] = Location::PHOTO_PENDING;
            }
        }

        // Dry run: don't save, just log what would happen
        if ($this->dryRun) {
            $action = $existing ? 'UPDATE' : 'CREATE';
            Log::info("[DRY-RUN] Would {$action}: {$place['name']} (cat: {$place['category']})");
            if ($existing) {
                $this->stats['updated']++;
            } else {
                $this->stats['created']++;
            }
            return;
        }

        if ($existing) {
            // Skip if already has photo and config says so
            if ($this->config['skip_existing_with_photo'] && $existing->photo) {
                $this->stats['skipped']++;
                return;
            }

            $existing->update($locationData);
            $this->stats['updated']++;
        } else {
            Location::create($locationData);
            $this->stats['created']++;
        }
    }

    protected function resolveCategory(string $categoryName): int
    {
        $mappedName = $this->config['category_mapping'][$categoryName] ?? $categoryName;
        return Category::firstOrCreate(['name' => $mappedName])->id;
    }

    protected function downloadPhoto(string $url): ?string
    {
        $url = trim($url);
        if (empty($url) || !Str::startsWith($url, ['http://', 'https://'])) {
            return null;
        }

        try {
            $response = Http::timeout(15)->maxRedirects(3)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $body = $response->body();

            if (strlen($body) > 10 * 1024 * 1024 || !@getimagesizefromstring($body)) {
                return null;
            }

            // Quality check
            $verdict = app(PhotoQualityValidator::class)->inspect($body);
            if (!$verdict['ok']) {
                Log::warning('Tourism sync photo rejected', $verdict + ['url' => $url]);
                return null;
            }

            $extension = match ($response->header('Content-Type')) {
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                default => 'jpg',
            };

            $filename = 'photos/tourism_' . Str::random(16) . '.' . $extension;

            return Storage::disk('public')->put($filename, $body) ? $filename : null;
        } catch (\Throwable $e) {
            Log::warning('Tourism photo download failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }
    }

    protected function fetchMissingPhotos(): void
    {
        if (!config('services.openverse.auto_fetch_after_sync', true)) {
            return;
        }

        $fetcher = new WikimediaPhotoFetcher;

        // Only fetch for places that have proper categories (PLACE_TYPES)
        $missing = $fetcher->locationsMissingPhoto();
        if ($missing->isEmpty()) {
            Log::info('No missing photos for tourism places');
            return;
        }

        if (config('services.openverse.queue_fetch', true)) {
            \App\Jobs\FetchMissingLocationPhotos::dispatch();
            $this->photoFetchStats = [
                'attempted' => 0,
                'success' => 0,
                'remaining' => $missing->count(),
                'queued' => true,
            ];
            return;
        }

        // Fallback synchronous (limited)
        $limit = (int)config('services.openverse.auto_fetch_limit', 50);
        $batch = $limit > 0 ? $missing->take($limit) : $missing;

        $success = 0;
        foreach ($batch as $loc) {
            try {
                if ($fetcher->fetchForLocation($loc)) $success++;
            } catch (\Throwable $e) {
                Log::error('Tourism auto photo fetch failed', ['location' => $loc->name, 'error' => $e->getMessage()]);
            }
            usleep((int)config('services.openverse.auto_fetch_sleep_ms', 1000) * 1000);
        }

        $this->photoFetchStats = [
            'attempted' => $batch->count(),
            'success' => $success,
            'remaining' => max(0, $missing->count() - $batch->count()),
            'queued' => false,
        ];
    }

    public function getStats(): array
    {
        return $this->stats;
    }

    public function lastPhotoFetchStats(): ?array
    {
        return $this->photoFetchStats;
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
        $this->photoFetchStats = null;
    }
}