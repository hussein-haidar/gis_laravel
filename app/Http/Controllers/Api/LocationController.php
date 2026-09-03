<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Location::with('category');

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->lat && $request->lng && $request->radius) {
            $lat = $request->lat;
            $lng = $request->lng;
            $radius = $request->radius;

            $query->selectRaw('*, (
                6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )
            ) AS distance', [$lat, $lng, $lat])
                ->having('distance', '<=', $radius)
                ->orderBy('distance');
        }

        $locations = $query->orderBy('name')->paginate($request->per_page ?? 20);

        return response()->json($locations);
    }

    public function show(Location $location): JsonResponse
    {
        $location->load('category');

        return response()->json([
            'data' => [
                'id' => $location->id,
                'name' => $location->name,
                'description' => $location->description,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'category' => $location->category,
                'photo_url' => $location->photo_url,
                'geometry' => $location->geometry,
                'created_at' => $location->created_at->toIso8601String(),
                'updated_at' => $location->updated_at->toIso8601String(),
            ],
        ]);
    }

    public function radius(Request $request): JsonResponse
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'required|numeric|min:0.1|max:5000',
        ]);

        $lat = $request->latitude;
        $lng = $request->longitude;
        $radius = $request->radius;

        $locations = Location::with('category')
            ->selectRaw('*, (
                6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )
            ) AS distance', [$lat, $lng, $lat])
            ->having('distance', '<=', $radius)
            ->orderBy('distance')
            ->get();

        return response()->json([
            'data' => $locations->map(function ($loc) {
                return [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'description' => $loc->description,
                    'latitude' => (float) $loc->latitude,
                    'longitude' => (float) $loc->longitude,
                    'distance_km' => round($loc->distance, 2),
                    'category' => $loc->category,
                    'photo_url' => $loc->photo_url,
                ];
            }),
            'meta' => [
                'center' => ['lat' => $lat, 'lng' => $lng],
                'radius_km' => $radius,
                'total' => $locations->count(),
            ],
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate([
            'q' => 'required|string|min:2',
        ]);

        $query = $request->q;

        $locations = Location::with('category')
            ->where('name', 'like', "%{$query}%")
            ->orWhere('description', 'like', "%{$query}%")
            ->limit(20)
            ->get();

        return response()->json(['data' => $locations]);
    }

    public function store(Request $request): JsonResponse
    {
        $location = Location::create($this->validated($request));

        if ($request->hasFile('photo')) {
            $location->update(['photo' => $request->file('photo')->store('photos', 'public')]);
        }

        return response()->json([
            'message' => 'Lokasi berhasil dibuat.',
            'data' => $location->fresh()->load('category'),
        ], 201);
    }

    public function update(Request $request, Location $location): JsonResponse
    {
        $data = $this->validated($request);

        if ($request->hasFile('photo')) {
            if ($location->photo) {
                Storage::disk('public')->delete($location->photo);
            }
            $data['photo'] = $request->file('photo')->store('photos', 'public');
        }

        $location->update($data);

        return response()->json([
            'message' => 'Lokasi berhasil diperbarui.',
            'data' => $location->fresh()->load('category'),
        ]);
    }

    public function destroy(Location $location): JsonResponse
    {
        if ($location->photo) {
            Storage::disk('public')->delete($location->photo);
        }

        $location->delete();

        return response()->json(['message' => 'Lokasi berhasil dihapus.']);
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
            'geometry' => ['nullable', 'array'],
            'geometry.type' => ['nullable', Rule::in(['Point', 'LineString', 'Polygon', 'MultiPoint', 'MultiLineString', 'MultiPolygon'])],
            'geometry.coordinates' => ['nullable', 'array'],
        ]);
    }
}
