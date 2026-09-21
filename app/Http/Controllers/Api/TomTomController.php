<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Traffic\TomTomTrafficService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TomTomController extends Controller
{
    public function __construct(protected TomTomTrafficService $traffic)
    {
    }

    /**
     * Proxy Traffic Flow dari TomTom supaya API key tidak bocor ke browser.
     *
     * Endpoint Flow Segment Data TomTom mengembalikan segmen jalan terdekat
     * dengan titik `point` yang diberikan (bukan tile x/y).
     *
     * URL: /traffic/services/4/flowSegmentData/{style}/{zoom}/{format}?point={lat},{lon}
     *
     * GET /api/v1/traffic/flow?lat=..&lng=..&zoom=..
     */
    public function flow(Request $request)
    {
        $lat = (float) $request->query('lat');
        $lng = (float) $request->query('lng');

        if (! $this->traffic->enabled()) {
            return response()->json([
                'status' => 'error',
                'message' => 'TOMMTOM_API_KEY belum diatur. Daftar gratis di https://my.tomtom.com lalu isi kuncinya di .env',
            ], 503);
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return response()->json(['status' => 'error', 'message' => 'Koordinat tidak valid.'], 422);
        }

        $segment = $this->traffic->flowSegmentAt($lat, $lng);

        return response()->json([
            'status' => 'ok',
            'segments' => $segment === null ? [] : [$segment],
        ]);
    }

    /**
     * Kumpulkan titik kemacetan dinamis di sepanjang rute.
     *
     * POST /api/v1/traffic/flow-along
     * body: { points: [[lat,lng], ...] }
     *
     * Semua segmen berasal dari geometri jalan asli TomTom — tidak pernah
     * menghasilkan titik acak/di tengah laut.
     */
    public function flowAlong(Request $request)
    {
        $validated = $request->validate([
            'points' => ['required', 'array', 'min:2', 'max:2000'],
            'points.*' => ['array', 'size:2'],
            'points.*.0' => ['required', 'numeric', 'between:-90,90'],
            'points.*.1' => ['required', 'numeric', 'between:-180,180'],
        ]);

        try {
            $segments = $this->traffic->flowAlong($validated['points']);
        } catch (\Throwable $e) {
            Log::warning('[TomTom] flow-along exception: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data kemacetan sepanjang rute.',
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'segments' => $segments,
        ]);
    }

    /**
     * Ambil segmen kemacetan di seluruh viewport (bounds).
     * Sample grid 3x3 di area bounds, panggil flowSegmentAt per titik.
     * Hasil dide-dupe by midpoint.
     *
     * POST /api/v1/traffic/flow-bounds
     * body: { lat_min, lng_min, lat_max, lng_max, zoom }
     */
    public function flowBounds(Request $request)
    {
        $validated = $request->validate([
            'lat_min' => ['required', 'numeric', 'between:-90,90'],
            'lng_min' => ['required', 'numeric', 'between:-180,180'],
            'lat_max' => ['required', 'numeric', 'between:-90,90'],
            'lng_max' => ['required', 'numeric', 'between:-180,180'],
            'zoom'    => ['required', 'integer', 'between:1,20'],
        ]);

        if (! $this->traffic->enabled()) {
            return response()->json([
                'status' => 'error',
                'message' => 'TOMMTOM_API_KEY belum diatur.',
            ], 503);
        }

        $latMin = (float) $validated['lat_min'];
        $lngMin = (float) $validated['lng_min'];
        $latMax = (float) $validated['lat_max'];
        $lngMax = (float) $validated['lng_max'];
        $zoom   = (int) $validated['zoom'];

        // Grid 2x2 di dalam bounds (4 titik, hemat kuota & waktu)
        $lats = [$latMin, $latMax];
        $lngs = [$lngMin, $lngMax];

        $segments = [];
        $seen = [];

        foreach ($lats as $lat) {
            foreach ($lngs as $lng) {
                $seg = $this->traffic->flowSegmentAt($lat, $lng);
                if (! $seg) continue;

                $mid = $seg['midpoint'];
                $key = round($mid[0], 4) . ',' . round($mid[1], 4);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $segments[] = $seg;

                if (count($segments) >= 20) break 2;
            }
        }

        return response()->json([
            'status' => 'ok',
            'segments' => $segments,
        ]);
    }
}