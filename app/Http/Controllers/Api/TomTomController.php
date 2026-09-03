<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TomTomController extends Controller
{
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
        $zoom = (int) $request->query('zoom', 14);

        $key = config('services.tomtom.key');

        if (! $key) {
            return response()->json([
                'status' => 'error',
                'message' => 'TOMMTOM_API_KEY belum diatur. Daftar gratis di https://my.tomtom.com lalu isi kuncinya di .env',
            ], 503);
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            return response()->json(['status' => 'error', 'message' => 'Koordinat tidak valid.'], 422);
        }

        // Cari segmen terdekat dengan `point`. Zoom dikunci 16 (tinggi) agar
        // hanya jalan penting yang muncul dan kuota request tetap hemat.
        $style = 'absolute';
        $segZoom = 16;

        $url = rtrim(config('services.tomtom.traffic_url'), '/')
            . "/flowSegmentData/{$style}/{$segZoom}/json";

        try {
            $response = Http::timeout(config('services.tomtom.timeout', 15))
                ->withOptions(['verify' => false])
                ->get($url, [
                    'key' => $key,
                    'point' => "{$lat},{$lng}",
                ]);

            if (! $response->successful()) {
                Log::warning("[TomTom] HTTP {$response->status()}: {$response->body()}");
                return response()->json([
                    'status' => 'error',
                    'message' => "TomTom HTTP {$response->status()}.",
                    'detail'   => $response->json(),
                ], $response->status());
            }

            $data = $response->json();

            // Bentuk ringkas untuk frontend (selalu array, meski hanya 1 segmen).
            $segment = $this->extractFlow($data['flowSegmentData'] ?? []);

            return response()->json([
                'status'   => 'ok',
                'segments' => $segment === null ? [] : [$segment],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[TomTom] exception: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil data traffic TomTom.',
            ], 500);
        }
    }

    /**
     * Ubah satu objek flowSegmentData menjadi bentuk ringkas untuk Leaflet.
     */
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

        $currentSpeed  = (float) ($seg['currentSpeed'] ?? 0);
        $freeFlowSpeed = (float) ($seg['freeFlowSpeed'] ?? 0);
        $frc           = $seg['frc'] ?? '';

        return [
            'points'         => $points,
            'color'          => $this->flowToColor($currentSpeed, $freeFlowSpeed),
            'currentSpeed'   => $currentSpeed,
            'freeFlowSpeed'  => $freeFlowSpeed,
            'frc'            => $frc,
        ];
    }

    /**
     * Warna berdasarkan perbandingan kecepatan aktual vs free-flow (kemacetan).
     */
    protected function flowToColor(float $current, float $free): string
    {
        if ($free <= 0) {
            return '#16a34a';
        }

        $ratio = $current / $free;

        if ($ratio < 0.4) {
            return '#e60000'; // macet total
        }
        if ($ratio < 0.7) {
            return '#e6b800'; // padat
        }
        if ($ratio < 0.9) {
            return '#60a5fa'; // ramai
        }

        return '#16a34a'; // lancar
    }
}
