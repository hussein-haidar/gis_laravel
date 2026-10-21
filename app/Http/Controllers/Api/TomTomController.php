<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Traffic\TomTomTrafficService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        // Cukup probe titik pusat viewport. Sudut-sudut kerap mengembalikan 400
        // "Point too far from nearest existing segment" dan menciptakan banyak
        // error 4xx tanpa menambah segmen bermakna.
        $centerLat = ($latMin + $latMax) / 2;
        $centerLng = ($lngMin + $lngMax) / 2;
        $probes = [
            [$centerLat, $centerLng],
            [$centerLat + 0.005, $centerLng],
            [$centerLat - 0.005, $centerLng],
            [$centerLat, $centerLng + 0.005],
            [$centerLat, $centerLng - 0.005],
            [$centerLat + 0.003, $centerLng + 0.003],
            [$centerLat - 0.003, $centerLng - 0.003],
        ];

        // Cache respons per grid (~1.1 km) supaya pan/zoom kecil atau kembali
        // ke area yang sama TIDAK memanggil API lagi — panel langsung tampil.
        //
        // Dua lapis cache:
        //   - "traffic_bounds"        : data segar  (TTL 90 detik)
        //   - "traffic_bounds_stale"  : data cadangan (TTL 15 menit)
        //
        // Kalau data sudah "basi" (>90 detik) tapi masih ada di cache
        // cadangan, response LANGSUNG dikirim (≈0.05 detik) lalu data
        // disegarkan di latar belakang setelah response terkirim. Jadi
        // area yang pernah dibuka selalu tampil instan.
        $cacheKey = 'traffic_bounds:' . round($centerLat, 2) . ':' . round($centerLng, 2) . ':' . $zoom;
        $staleKey = $cacheKey . ':stale';
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            // Nama jalan yang sudah pernah di-cache tetap ikut supaya daftar
            // tampil lengkap tanpa perlu request tambahan.
            if (method_exists($this->traffic, 'applyCachedStreetNames')) {
                $this->traffic->applyCachedStreetNames($cached);
            }

            return response()->json([
                'status' => 'ok',
                'segments' => $cached,
                'cached' => true,
            ]);
        }

        $stale = Cache::get($staleKey);
        if (is_array($stale) && ! empty($stale)) {
            // Kirim data lama SEKARANG, segarkan di belakang layar.
            if (method_exists($this->traffic, 'applyCachedStreetNames')) {
                $this->traffic->applyCachedStreetNames($stale);
            }

            $this->refreshTrafficInBackground($probes, $cacheKey, $staleKey);

            return response()->json([
                'status' => 'ok',
                'segments' => $stale,
                'cached' => true,
                'stale' => true,
            ]);
        }

        // Semua probe dikirim KONKUREN (bukan satu per satu) sehingga total
        // waktu ≈ request terlama.
        $segments = [];
        $seen = [];

        foreach ($this->traffic->flowSegmentsAt($probes) as $seg) {
            if (! $seg) {
                continue;
            }

            $mid = $seg['midpoint'];
            $key = round($mid[0], 4) . ',' . round($mid[1], 4);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $segments[] = $seg;

            if (count($segments) >= 20) {
                break;
            }
        }

        // Nama jalan diisi HANYA dari cache di sini supaya respons cepat
        // (daftar langsung tampil begitu data TomTom sampai). Nama yang belum
        // ada diisi terpisah lewat endpoint background /traffic/street-names.
        if (method_exists($this->traffic, 'applyCachedStreetNames')) {
            $this->traffic->applyCachedStreetNames($segments);
        }

        // Ruas jalan yang sama (hasil probe berbeda) cukup dikembalikan sekali.
        if (method_exists($this->traffic, 'dedupeByStreet')) {
            $segments = $this->traffic->dedupeByStreet($segments);
        }

        if (! empty($segments)) {
            Cache::put($cacheKey, $segments, now()->addSeconds(90));
            Cache::put($staleKey, $segments, now()->addMinutes(15));
        }

        return response()->json([
            'status' => 'ok',
            'segments' => $segments,
        ]);
    }

    /**
     * Segarkan data kemacetan SETELAH response terkirim ke browser.
     *
     * Dipakai pola stale-while-revalidate: pengguna langsung melihat data
     * lama, lalu cache diperbarui diam-diam supaya request berikutnya
     * selalu memakai data segar.
     */
    protected function refreshTrafficInBackground(array $probes, string $cacheKey, string $staleKey): void
    {
        app()->terminating(function () use ($probes, $cacheKey, $staleKey) {
            try {
                $segments = [];
                $seen = [];

                foreach ($this->traffic->flowSegmentsAt($probes) as $seg) {
                    if (! $seg) {
                        continue;
                    }

                    $mid = $seg['midpoint'];
                    $key = round($mid[0], 4) . ',' . round($mid[1], 4);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $seen[$key] = true;
                    $segments[] = $seg;

                    if (count($segments) >= 20) {
                        break;
                    }
                }

                if (empty($segments)) {
                    return;
                }

                if (method_exists($this->traffic, 'applyCachedStreetNames')) {
                    $this->traffic->applyCachedStreetNames($segments);
                }
                if (method_exists($this->traffic, 'dedupeByStreet')) {
                    $segments = $this->traffic->dedupeByStreet($segments);
                }

                Cache::put($cacheKey, $segments, now()->addSeconds(90));
                Cache::put($staleKey, $segments, now()->addMinutes(15));
            } catch (\Throwable $e) {
                Log::warning('[TomTom] refresh latar gagal: ' . $e->getMessage());
            }
        });
    }

    /**
     * Isi nama jalan untuk titik-titik yang belum punya nama.
     *
     * Dipanggil di background oleh halaman peta agar daftar kemacetan bisa
     * tampil cepat (warna/level dari TomTom) tanpa menunggu reverse
     * geocoding yang lambat.
     *
     * POST /api/v1/traffic/street-names
     * body: { points: [[lat,lng], ...], deep: bool }
     *
     * - deep = false (default): hanya sumber tercepat, balas ~0.2-0.5 detik
     * - deep = true          : lanjut ke sumber cadangan (Photon/Geoapify/
     *   Overpass) untuk titik yang masih belum bernama.
     */
    public function streetNames(Request $request)
    {
        $validated = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:50'],
            'points.*' => ['array', 'size:2'],
            'points.*.0' => ['required', 'numeric', 'between:-90,90'],
            'points.*.1' => ['required', 'numeric', 'between:-180,180'],
            'deep' => ['sometimes', 'boolean'],
        ]);

        try {
            $names = $this->traffic->streetNamesForPoints(
                $validated['points'],
                (bool) ($validated['deep'] ?? false)
            );
        } catch (\Throwable $e) {
            Log::warning('[TomTom] street-names exception: ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Gagal mengambil nama jalan.',
            ], 500);
        }

        return response()->json([
            'status' => 'ok',
            'names' => $names,
        ]);
    }
}