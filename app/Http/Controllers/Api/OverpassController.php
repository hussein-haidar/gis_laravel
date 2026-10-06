<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Traffic\OverpassRoadService;
use Illuminate\Http\Request;

/**
 * Proxy jalan OpenStreetMap dari Overpass API.
 *
 * Tujuannya: browser tidak pernah contacting Overpass secara langsung.
 * Semua permintaan dilewatkan server agar (a) respons di-cache sekali per
 * area dan dipakai bersama, (b) rate limit Overpass tidak habis oleh satu
 * pengguna yang menggeser peta, (c) frontend tidak perlu fallback acak
 * tiap kali Overpass membalas 429.
 */
class OverpassController extends Controller
{
    public function __construct(protected OverpassRoadService $roads)
    {
    }

    /**
     * POST /api/v1/traffic/roads-bounds
     * body: { lat_min, lng_min, lat_max, lng_max, zoom }
     *
     * `roads` hanya berisi jalan yang punya tag name, karena nama jalan itulah
     * satu-satunya informasi yang ditampilkan di popup titik kemacetan.
     */
    public function bounds(Request $request)
    {
        $validated = $request->validate([
            'lat_min' => ['required', 'numeric', 'between:-90,90'],
            'lng_min' => ['required', 'numeric', 'between:-180,180'],
            'lat_max' => ['required', 'numeric', 'between:-90,90'],
            'lng_max' => ['required', 'numeric', 'between:-180,180'],
            'zoom' => ['required', 'integer', 'between:1,20'],
        ]);

        $latMin = min((float) $validated['lat_min'], (float) $validated['lat_max']);
        $latMax = max((float) $validated['lat_min'], (float) $validated['lat_max']);
        $lngMin = min((float) $validated['lng_min'], (float) $validated['lng_max']);
        $lngMax = max((float) $validated['lng_min'], (float) $validated['lng_max']);

        if ($latMin === $latMax && $lngMin === $lngMax) {
            return response()->json(['status' => 'error', 'message' => 'Bounds tidak valid.'], 422);
        }

        $roads = $this->roads->roadsInBounds(
            $latMin,
            $lngMin,
            $latMax,
            $lngMax,
            (int) $validated['zoom']
        );

        return response()->json([
            'status' => 'ok',
            'roads' => $roads,
        ]);
    }
}