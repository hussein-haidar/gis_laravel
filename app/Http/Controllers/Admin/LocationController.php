<?php

namespace App\Http\Controllers\Admin;

use App\Exports\LocationsExport;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Facades\Excel;
use App\Services\Gis\GisDataSyncService;
use Barryvdh\DomPDF\Facade\Pdf;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->query('search', ''));
        $categoryId = $request->query('category');

        $locations = Location::query()
            ->with('category')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->latest()
            ->paginate(10)
            ->withQueryString();

        $categories = Category::orderBy('name')->get();

        // Dashboard stats
        $stats = [
            'total_locations' => Location::count(),
            'total_categories' => Category::count(),
            'locations_with_photos' => Location::whereNotNull('photo')->where('photo', '!=', '')->count(),
            'locations_with_geometry' => Location::whereNotNull('geometry')->count(),
            'recent_locations' => Location::latest()->take(5)->get(),
            'locations_per_category' => Category::withCount('locations')
                ->orderByDesc('locations_count')
                ->get(),
            'total_navigation_history' => \App\Models\NavigationHistory::count(),
            'total_users' => \App\Models\User::count(),
        ];

        return view('admin-loc.index', compact('locations', 'categories', 'search', 'categoryId', 'stats'));
    }

    public function create(): View
    {
        $categories = Category::orderBy('name')->get();

        return view('admin-loc.create', compact('categories'));
    }

public function store(Request $request)
    {
        $data = $this->validated($request);

        // Handle main photo (backward compatibility)
        if ($request->hasFile('photo')) {
            $data['photo'] = $request->file('photo')->store('photos', 'public');
        }

        $data['geometry'] = $this->buildGeometry($request);

        $location = Location::create($data);

        // Handle multiple photos
        if ($request->hasFile('photos')) {
            $this->storePhotos($location, $request->file('photos'));
        }

        $this->logActivity('location_created', $location, null, $location->toArray());

        return redirect()
            ->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil ditambahkan.');
    }

    public function edit(Location $location): View
    {
        $categories = Category::orderBy('name')->get();
        $location->load('photos');

        return view('admin-loc.edit', compact('location', 'categories'));
    }

    public function update(Request $request, Location $location)
    {
        $oldValues = $location->toArray();

        $data = $this->validated($request);

        // Handle main photo (backward compatibility)
        if ($request->hasFile('photo')) {
            if ($location->photo) {
                Storage::disk('public')->delete($location->photo);
            }
            $data['photo'] = $request->file('photo')->store('photos', 'public');
        }

        // Handle multiple photos
        if ($request->hasFile('photos')) {
            $this->storePhotos($location, $request->file('photos'));
        }

        // Handle photo deletions
        if ($request->filled('delete_photos')) {
            $this->deletePhotos($location, $request->input('delete_photos'));
        }

        // Handle photo reordering/captions
        if ($request->filled('photo_data')) {
            $this->updatePhotoData($location, $request->input('photo_data'));
        }

        $data['geometry'] = $this->buildGeometry($request);

        $location->update($data);

        $this->logActivity('location_updated', $location, $oldValues, $location->fresh()->toArray());

        return redirect()
            ->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil diperbarui.');
    }

    protected function storePhotos(Location $location, array $files): void
    {
        $maxSort = $location->photos()->max('sort_order') ?? 0;
        $hasPrimary = $location->photos()->where('is_primary', true)->exists();

        foreach ($files as $index => $file) {
            $path = $file->store('photos', 'public');
            $isPrimary = !$hasPrimary && $index === 0;

            $location->photos()->create([
                'path' => $path,
                'sort_order' => $maxSort + $index + 1,
                'is_primary' => $isPrimary,
            ]);

            if ($isPrimary) {
                $hasPrimary = true;
            }
        }
    }

    protected function deletePhotos(Location $location, array $photoIds): void
    {
        $photos = $location->photos()->whereIn('id', $photoIds)->get();
        foreach ($photos as $photo) {
            if ($photo->path) {
                Storage::disk('public')->delete($photo->path);
            }
            $photo->delete();
        }
    }

    protected function updatePhotoData(Location $location, array $photoData): void
    {
        foreach ($photoData as $id => $data) {
            $photo = $location->photos()->find($id);
            if ($photo) {
                $photo->update([
                    'caption' => $data['caption'] ?? null,
                    'sort_order' => $data['sort_order'] ?? $photo->sort_order,
                    'is_primary' => $data['is_primary'] ?? false,
                ]);
            }
        }
    }

    public function destroy(Location $location)
    {
        $oldValues = $location->toArray();

        // Delete main photo
        if ($location->photo) {
            Storage::disk('public')->delete($location->photo);
        }

        // Delete additional photos
        $location->photos()->each(function ($photo) {
            if ($photo->path) {
                Storage::disk('public')->delete($photo->path);
            }
            $photo->delete();
        });

        $location->delete();

        $this->logActivity('location_deleted', null, $oldValues, null);

        return redirect()
            ->route('admin.locations.index')
            ->with('success', 'Lokasi berhasil dihapus.');
    }

    public function bulkDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:locations,id',
        ]);

        $ids = $request->ids;
        $locations = Location::whereIn('id', $ids)->with('photos')->get();

        foreach ($locations as $location) {
            if ($location->photo) {
                Storage::disk('public')->delete($location->photo);
            }
            $location->photos()->each(function ($photo) {
                if ($photo->path) {
                    Storage::disk('public')->delete($photo->path);
                }
                $photo->delete();
            });
            $this->logActivity('location_deleted', null, $location->toArray(), null);
        }

        Location::whereIn('id', $ids)->delete();

        return redirect()
            ->route('admin.locations.index')
            ->with('success', count($ids) . ' lokasi berhasil dihapus.');
    }

    public function radiusForm(): View
    {
        return view('admin-loc.radius', [
            'locations' => collect(),
            'lat' => -2.5489,
            'lng' => 118.0149,
            'radius' => 100,
        ]);
    }

    public function radiusSearch(Request $request): View
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'required|numeric|min:0.1|max:5000',
        ]);

        $lat = (float) $request->latitude;
        $lng = (float) $request->longitude;
        $radius = (float) $request->radius;

        $locations = Location::with('category')
            ->withinRadius($lat, $lng, $radius)
            ->get();

        return view('admin-loc.radius', compact('locations', 'lat', 'lng', 'radius'));
    }

    public function distance(Request $request): View
    {
        $locations = Location::with('category')->orderBy('name')->get();
        $fromId = $request->query('from');
        $toId = $request->query('to');

        $route = null;
        $distance = null;
        $fromLat = $request->query('from_lat');
        $fromLng = $request->query('from_lng');
        $isGps = ($fromId === 'gps' && $fromLat && $fromLng);

        if ($isGps && $toId) {
            $to = Location::with('category')->find($toId);
            if ($to) {
                $gpsLocation = new Location([
                    'name' => 'Lokasi Saya (GPS)',
                    'latitude' => $fromLat,
                    'longitude' => $fromLng,
                ]);
                $distance = $gpsLocation->distanceTo($to);
                $route = ['from' => $gpsLocation, 'to' => $to];
            }
        } elseif ($fromId && $toId && $fromId !== $toId && !$isGps) {
            $from = Location::with('category')->find($fromId);
            $to = Location::with('category')->find($toId);

            if ($from && $to) {
                $distance = $from->distanceTo($to);
                $route = compact('from', 'to');
            }
        }

        return view('admin-loc.distance', compact('locations', 'fromId', 'toId', 'route', 'distance', 'isGps', 'fromLat', 'fromLng'));
    }

    public function export(Request $request)
    {
        $format = strtolower($request->query('format', 'csv'));
        $search = trim($request->query('search', ''));
        $categoryId = $request->query('category');

        $locations = Location::query()
            ->with('category')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($categoryId, function ($query) use ($categoryId) {
                $query->where('category_id', $categoryId);
            })
->orderBy('name')
            ->get();

        if (! in_array($format, ['csv', 'json', 'xlsx', 'pdf'])) {
            $format = 'csv';
        }

        $filename = 'lokasi_' . now()->format('Y-m-d_His') . '.' . $format;

        // Log export activity
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'location_export',
            'subject_type' => Location::class,
            'subject_id' => null,
            'old_values' => null,
            'new_values' => [
                'format' => $format,
                'count' => $locations->count(),
                'filename' => $filename,
            ],
            'description' => "Ekspor {$locations->count()} lokasi ke format {$format}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        if ($format === 'xlsx') {
            return Excel::download(new LocationsExport($locations), $filename);
        }

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('admin-loc.pdf', ['locations' => $locations]);
            return $pdf->download($filename);
        }

        if ($format === 'json') {
            return response()->streamDownload(function () use ($locations) {
                echo $locations
                    ->map(fn (Location $location) => [
                        'name' => $location->name,
                        'description' => $location->description,
                        'latitude' => (float) $location->latitude,
                        'longitude' => (float) $location->longitude,
                        'category' => $location->category?->name,
                        'geometry' => $location->geometry,
                        'photo' => $location->photo,
                    ])
                    ->values()
                    ->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, $filename, ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($locations) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['name', 'description', 'latitude', 'longitude', 'category', 'geometry', 'photo']);
            foreach ($locations as $location) {
                fputcsv($stream, [
                    $location->name,
                    $location->description,
                    $location->latitude,
                    $location->longitude,
                    $location->category?->name,
                    $location->geometry ? json_encode($location->geometry, JSON_UNESCAPED_UNICODE) : '',
                    $location->photo ?? '',
                ]);
            }
            fclose($stream);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function importForm(): View
    {
        return view('admin-loc.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,json,xlsx', 'max:4096'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        $rows = match ($extension) {
            'json' => $this->parseJsonImport($file),
            'xlsx' => $this->parseXlsxImport($file),
            default => $this->parseCsvImport($file),
        };

        $imported = 0;
        $errors = [];
        $warnings = [];

        foreach ($rows as $index => $row) {
            $row = (array) $row;
            $row = collect($row)
                ->mapWithKeys(fn ($value, $key) => [strtolower(trim((string) $key)) => is_string($value) ? trim($value) : $value])
                ->all();
            $line = 'Baris ' . ($index + 2);

            $geometry = null;
            if (!empty($row['geometry'])) {
                $geometry = $this->parseGeometry(is_string($row['geometry']) ? $row['geometry'] : json_encode($row['geometry']));
                if (!$geometry) {
                    $errors[] = "{$line}: kolom geometry bukan GeoJSON yang valid.";
                    continue;
                }
            }

            $latitude = $row['latitude'] ?? null;
            $longitude = $row['longitude'] ?? null;

            if (($latitude === null || $latitude === '') && $geometry !== null && ($geometry['type'] ?? null) === 'Point') {
                [$longitude, $latitude] = $geometry['coordinates'];
            }

            $validator = Validator::make([
                'name' => trim((string) ($row['name'] ?? '')),
                'description' => $row['description'] ?? null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ], [
                'name' => ['required', 'string', 'max:255'],
                'description' => ['nullable', 'string'],
                'latitude' => ['required', 'numeric', 'between:-90,90'],
                'longitude' => ['required', 'numeric', 'between:-180,180'],
            ]);

            if ($validator->fails()) {
                $errors[] = "{$line}: " . collect($validator->errors()->all())->implode('; ');
                continue;
            }

            $attributes = [
                'description' => $row['description'] ?? null,
                'latitude' => (float) $latitude,
                'longitude' => (float) $longitude,
            ];

            if (array_key_exists('category', $row) && !empty($row['category'])) {
                $attributes['category_id'] = Category::firstOrCreate(['name' => trim((string) $row['category'])])->id;
            }

            if (array_key_exists('geometry', $row)) {
                $attributes['geometry'] = $geometry;
            }

            if (array_key_exists('photo', $row)) {
                $photoValue = trim((string) ($row['photo'] ?? ''));
                if ($photoValue === '') {
                    $attributes['photo'] = null;
                } else {
                    $resolved = $this->resolvePhoto($photoValue);
                    if ($resolved) {
                        $attributes['photo'] = $resolved;
                    } else {
                        $warnings[] = "{$line}: foto \"{$photoValue}\" tidak dapat diambil (URL tidak valid atau file tidak ditemukan).";
                    }
                }
            }

            $location = Location::updateOrCreate(
                ['name' => trim((string) $row['name'])],
                $attributes
            );
            $imported++;
        }

        if (count($rows) === 0) {
            return back()->with('error', 'Tidak ada data valid dalam file.');
        }

        $message = "{$imported} lokasi berhasil diimpor.";
        $problems = array_merge($errors, $warnings);
        if (count($problems) > 0) {
            $message .= ' ' . count($problems) . " catatan. Detail: " . implode(' | ', array_slice($problems, 0, 5));
        }

        // Log import activity
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => 'location_import',
            'subject_type' => Location::class,
            'subject_id' => null,
            'old_values' => null,
            'new_values' => [
                'imported' => $imported,
                'errors' => count($errors),
                'warnings' => count($warnings),
            ],
            'description' => "Impor {$imported} lokasi dari file {$extension}",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        return redirect()
            ->route('admin.locations.index')
            ->with(count($warnings) > 0 ? 'warning' : 'success', $message);
    }

    private function parseCsvImport(\Illuminate\Http\UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        $header = fgetcsv($handle) ?: [];
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '');
        $header = array_map(fn ($item) => strtolower(trim($item ?? '')), $header);

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $row = array_map(fn ($item) => trim($item ?? ''), $row);
            if (count($header) === count($row)) {
                $rows[] = array_combine($header, $row);
                continue;
            }
            $keys = $header;
            while (count($keys) < count($row)) {
                $keys[] = 'kolom_' . (count($keys) + 1);
            }
            $rows[] = array_combine(array_slice($keys, 0, count($row)), array_slice($row, 0, count($keys)));
        }
        fclose($handle);

        return $rows;
    }

    private function parseJsonImport(\Illuminate\Http\UploadedFile $file): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $file->get() ?? '');
        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return [];
        }

        return array_map(fn ($row) => is_array($row) ? (object) $row : $row, $decoded);
    }

    private function parseXlsxImport(\Illuminate\Http\UploadedFile $file): array
    {
        $sheets = Excel::toArray(new class implements WithHeadingRow {}, $file->getRealPath());

        return collect($sheets)
            ->flatten(1)
            ->filter(fn ($row) => is_array($row))
            ->filter(function ($row) {
                return collect($row)->contains(fn ($value) => $value !== null && trim((string) $value) !== '');
            })
            ->values()
            ->all();
    }

    /**
     * Mengubah string GeoJSON menjadi array geometry yang tervalidasi.
     */
    private function parseGeometry(?string $raw): ?array
    {
        $raw = trim((string) $raw);

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['type'], $decoded['coordinates'])) {
            return null;
        }

        $allowed = ['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'];

        if (!in_array($decoded['type'], $allowed) || !is_array($decoded['coordinates'])) {
            return null;
        }

        return ['type' => $decoded['type'], 'coordinates' => $decoded['coordinates']];
    }

    /**
     * Mengambil foto dari URL eksternal atau memakai path yang sudah ada di storage.
     */
    private function resolvePhoto(string $value): ?string
    {
        if (preg_match('#^https?://#i', $value)) {
            try {
                $response = Http::timeout(10)->maxRedirects(3)->get($value);
            } catch (\Throwable) {
                return null;
            }

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

            $path = 'photos/' . uniqid('import_', true) . '.' . $extension;

            return Storage::disk('public')->put($path, $body) ? $path : null;
        }

        if (str_contains($value, '..')) {
            return null;
        }

        return Storage::disk('public')->exists($value) ? $value : null;
    }

    public function template()
    {
        $polygon = '{"type":"Polygon","coordinates":[[[106.8270,-6.1751],[106.8280,-6.1751],[106.8280,-6.1760],[106.8270,-6.1760],[106.8270,-6.1751]]]}';

        return response()->streamDownload(function () use ($polygon) {
            $stream = fopen('php://output', 'w');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['name', 'description', 'latitude', 'longitude', 'category', 'geometry', 'photo']);
            fputcsv($stream, ['Monas', 'Menara ikonik di Jakarta', -6.1753924, 106.8271528, 'Tempat Umum', '', '']);
            fputcsv($stream, ['Alun-Alun Kota', 'Area terbuka di pusat kota', -6.2138, 106.8155, 'Tempat Umum', $polygon, '']);
            fclose($stream);
        }, 'template_lokasi.csv', ['Content-Type' => 'text/csv']);
    }

    private function buildGeometry(Request $request): ?array
    {
        $type = $request->input('geometry_type');
        $coordsRaw = $request->input('geometry_coords');

        if (!$type || !$coordsRaw) {
            return null;
        }

        $coords = json_decode($coordsRaw, true);
        if (!is_array($coords)) {
            return null;
        }

        return ['type' => $type, 'coordinates' => $coords];
    }

    private function logActivity(string $type, ?Location $subject, ?array $oldValues, ?array $newValues): void
    {
        ActivityLog::create([
            'user_id' => Auth::id(),
            'type' => $type,
            'subject_type' => Location::class,
            'subject_id' => $subject?->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => match ($type) {
                'location_created' => "Menambahkan lokasi: " . ($subject?->name ?? $oldValues['name'] ?? ''),
                'location_updated' => "Memperbarui lokasi: " . ($subject?->name ?? $oldValues['name'] ?? ''),
                'location_deleted' => "Menghapus lokasi: " . ($oldValues['name'] ?? ''),
                default => "Aksi pada lokasi",
            },
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function sync(GisDataSyncService $syncService)
    {
        try {
            $stats = $syncService->sync();

            $message = "Sinkronisasi selesai: {$stats['created']} dibuat, {$stats['updated']} diperbarui, {$stats['skipped']} dilewati";
            if ($stats['errors'] > 0) {
                $message .= ", {$stats['errors']} error";
            }

            return redirect()
                ->route('admin.locations.index')
                ->with($stats['errors'] > 0 ? 'warning' : 'success', $message);
        } catch (\Throwable $e) {
            return redirect()
                ->route('admin.locations.index')
                ->with('error', 'Sinkronisasi gagal: ' . $e->getMessage());
        }
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'category_id' => ['nullable', 'exists:categories,id'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'photos.*' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,webp', 'max:5120'],
            'delete_photos' => ['nullable', 'array'],
            'delete_photos.*' => ['integer', 'exists:location_photos,id'],
            'photo_data' => ['nullable', 'array'],
            'geometry_type' => ['nullable', 'string', 'in:Point,LineString,Polygon,MultiPoint,MultiLineString,MultiPolygon'],
            'geometry_coords' => ['nullable', 'string'],
        ]);
    }
}
