<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeoJsonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $compact = $request->query('compact') === '1';

        $locations = Location::with(['category', 'wilayah.category'])
            ->publiclyVisible()
            ->whereHas('category', function ($q) {
                $q->where('is_active', true);
            })
            ->when($request->query('category'), function ($query, $categoryId) {
                $query->where('category_id', $categoryId);
            })
            ->when($request->query('search'), function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->get();

        $features = $locations->map(function (Location $location) use ($compact) {
            $isPlaceType = in_array($location->category?->name, Category::PLACE_TYPES, true);

            $propertiesBase = [
                'id' => $location->id,
                'name' => $location->name,
                'description' => $location->description,
                'category' => $location->category?->name,
                'category_color' => $location->category?->color,
                'photo_url' => $location->photo_url,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'is_wilayah' => ! $isPlaceType,
                'wilayah' => $location->wilayah?->name,
                'provinsi' => $location->wilayah?->category?->name,
            ];

            $properties = $compact
                ? $propertiesBase
                : $propertiesBase + ['created_at' => $location->created_at->toIso8601String()];

            // Compact (untuk marker peta): cukup titik, tanpa geometry raksasa
            // (polygon/linestring) yang membuat payload besar.
            $geometryData = $compact ? [
                'type' => 'Point',
                'coordinates' => [
                    (float) $location->longitude,
                    (float) $location->latitude,
                ],
            ] : ($location->geometry ?? [
                'type' => 'Point',
                'coordinates' => [
                    (float) $location->longitude,
                    (float) $location->latitude,
                ],
            ]);

            return [
                'type' => 'Feature',
                'id' => $location->id,
                'geometry' => $geometryData,
                'properties' => $properties,
            ];
        });

        return response()->json([
            'type' => 'FeatureCollection',
            'features' => $features->toArray(),
        ]);
    }

    public function show(Location $location): JsonResponse
    {
        abort_unless($location->photo && $location->photo_review_status === Location::PHOTO_APPROVED, 404);

        $location->load(['category', 'wilayah.category']);

        $geometry = $location->geometry;
        if (! $geometry) {
            $geometry = [
                'type' => 'Point',
                'coordinates' => [
                    (float) $location->longitude,
                    (float) $location->latitude,
                ],
            ];
        }

        $isPlaceType = in_array($location->category?->name, Category::PLACE_TYPES, true);

        return response()->json([
            'type' => 'Feature',
            'id' => $location->id,
            'geometry' => $geometry,
            'properties' => [
                'id' => $location->id,
                'name' => $location->name,
                'description' => $location->description,
                'category' => $location->category?->name,
                'category_color' => $location->category?->color,
                'photo_url' => $location->photo_url,
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'is_wilayah' => ! $isPlaceType,
                'wilayah' => $location->wilayah?->name,
                'provinsi' => $location->wilayah?->category?->name,
                'created_at' => $location->created_at->toIso8601String(),
            ],
        ]);
    }
}
