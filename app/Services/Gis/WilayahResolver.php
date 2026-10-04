<?php

namespace App\Services\Gis;

use App\Models\Location;
use Illuminate\Support\Collection;

class WilayahResolver
{
    /**
     * Tentukan kabupaten/kota (lokasi wilayah) yang memuat sebuah titik.
     * Menggunakan ray-casting terhadap geometry MultiPolygon (58.3).
     *
     * @return array{id:int,name:string,provinsi:string}|null
     */
    public function resolve(float $latitude, float $longitude): ?array
    {
        $matches = [];

        foreach ($this->wilayahRegions() as $region) {
            if (! $this->inBounds($region, $latitude, $longitude)) {
                continue;
            }
            if ($this->contains($region, $latitude, $longitude)) {
                $matches[] = $region;
            }
        }

        if (! $matches) {
            return null;
        }

        // Beberapa data memuat poligon provinsi utuh DI SAMPING kabupaten/kota.
        // Pilih wilayah dengan kotak pembatas TERKECIL agar badge memakai
        // kabupaten/kota spesifik, bukan provinsi.
        usort($matches, fn ($a, $b) => $this->boundArea($a) <=> $this->boundArea($b));

        return $matches[0];
    }

    private function boundArea(array $region): float
    {
        $b = $region['bounds'] ?? null;
        if (! $b) {
            return PHP_FLOAT_MAX;
        }

        return max(0.0, ($b[2] - $b[0])) * max(0.0, ($b[3] - $b[1]));
    }

    /**
     * Cari wilayah terdekat dari titik (untuk titik pesisir/laut yang tidak
     * jatuh persis di dalam poligon darat). $maxKm sebagai ambang keamanan
     * agar koordinat yang jelas salah tidak diberi label sembarangan.
     */
    public function resolveNearest(float $latitude, float $longitude, float $maxKm = 50.0): ?array
    {
        $nearest = null;
        $nearestKm = PHP_FLOAT_MAX;

        foreach ($this->wilayahRegions() as $region) {
            // Jangan pernah pilih poligon provinsi utuh (name == provinsi)
            // sebagai "wilayah terdekat" - titik pantai harus jatuh ke
            // kabupaten/kota terdekat agar badge tetap spesifik.
            if (mb_strtoupper($region['name']) === mb_strtoupper($region['provinsi'])) {
                continue;
            }
            $km = $this->haversineKm($latitude, $longitude, $region['latitude'], $region['longitude']);
            if ($km < $nearestKm) {
                $nearestKm = $km;
                $nearest = $region;
            }
        }

        if ($nearest === null || $nearestKm > $maxKm) {
            return null;
        }

        return $nearest;
    }

    /**
     * Fallback berbasis jarak ke PERMUKAAN poligon, bukan ke titik pusat.
     *
     * Wilayah kepulauan (Banda, Karimunjawa, Moyo, Wakatobi) dan titik yang
     * berada di laut hampir selalu gagal pada ray-casting karena poligon oblast
     * disederhanakan dan tidak memuat gugusan pulau kecil. Mengukur jarak ke
     * sisi poligon membuat titik tersebut tetap diarahkan ke kabupaten yang benar.
     */
    public function resolveByProximity(float $latitude, float $longitude, float $maxKm = 30.0): ?array
    {
        $best = null;
        $bestKm = PHP_FLOAT_MAX;

        foreach ($this->wilayahRegions() as $region) {
            // Poligon provinsi utuh tidak pernah jadi jawaban "terdekat".
            if (mb_strtoupper($region['name']) === mb_strtoupper((string) $region['provinsi'])) {
                continue;
            }

            $bounds = $region['bounds'] ?? null;
            if ($bounds && ! $this->nearBounds($bounds, $latitude, $longitude, $maxKm)) {
                continue;
            }

            $km = $this->polygonDistanceKm($region, $latitude, $longitude);
            if ($km === null || $km > $maxKm || $km >= $bestKm) {
                continue;
            }

            $bestKm = $km;
            $best = $region;
            $best['jarak_km'] = $km;
        }

        return $best;
    }

    /**
     * Prefilter cepat: apakah kotak pembatas poligon masih mungkin berada
     * dalam $maxKm dari titik? Menghemat perhitungan jarak titik-ke-sisi.
     */
    private function nearBounds(array $b, float $lat, float $lng, float $maxKm): bool
    {
        $dLat = $maxKm / 110.574;
        $dLng = $maxKm / max(1e-6, 111.320 * cos(deg2rad($lat)));

        return $lng >= $b[0] - $dLng && $lat >= $b[1] - $dLat
            && $lng <= $b[2] + $dLng && $lat <= $b[3] + $dLat;
    }

    /**
     * Jarak terdekat (km) dari titik ke salah satu sisi poligon wilayah.
     */
    private function polygonDistanceKm(array $region, float $lat, float $lng): ?float
    {
        $geometry = $region['geometry'] ?? null;
        if (! is_array($geometry) || ! isset($geometry['type'])) {
            return null;
        }

        $type = $geometry['type'];
        $polys = match ($type) {
            'Polygon' => [$geometry['coordinates']],
            'MultiPolygon' => $geometry['coordinates'] ?? [],
            default => [],
        };

        $best = null;
        foreach ($polys as $rings) {
            foreach ($rings as $ring) {
                $count = count($ring);
                if ($count < 2) {
                    continue;
                }
                for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
                    $km = $this->segmentKm(
                        $lat,
                        $lng,
                        (float) $ring[$j][1],
                        (float) $ring[$j][0],
                        (float) $ring[$i][1],
                        (float) $ring[$i][0]
                    );
                    if ($best === null || $km < $best) {
                        $best = $km;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Jarak titik ke satu segmen garis (km), aproksimasi equirectangular.
     */
    private function segmentKm(float $lat, float $lng, float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $kx = 111.320 * cos(deg2rad(($lat + $lat1 + $lat2) / 3));
        $ky = 110.574;

        $ax = ($lng1 - $lng) * $kx;
        $ay = ($lat1 - $lat) * $ky;
        $bx = ($lng2 - $lng) * $kx;
        $by = ($lat2 - $lat) * $ky;

        $dx = $bx - $ax;
        $dy = $by - $ay;
        if ($dx == 0.0 && $dy == 0.0) {
            return sqrt($ax * $ax + $ay * $ay);
        }

        $t = -(($ax * $dx) + ($ay * $dy)) / (($dx * $dx) + ($dy * $dy));
        $t = max(0.0, min(1.0, $t));

        $cx = $ax + $t * $dx;
        $cy = $ay + $t * $dy;

        return sqrt($cx * $cx + $cy * $cy);
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadiusKm * 2 * asin(sqrt($a));
    }

    private function wilayahRegions(): Collection
    {
        static $regions = null;

        if ($regions === null) {
            $regions = Location::query()
                ->with('category')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereNotNull('geometry')
                ->whereHas('category', fn ($q) => $q->provinces())
                ->get()
                ->map(fn (Location $loc) => [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'latitude' => (float) $loc->latitude,
                    'longitude' => (float) $loc->longitude,
                    'provinsi' => $loc->category?->name,
                    'geometry' => $loc->geometry,
                    'bounds' => $this->computeBounds($loc->geometry),
                ]);
        }

        return $regions;
    }

    /**
     * Hitung kotak pembatas (min/max lat-lng) geometry untuk prefilter cepat.
     */
    private function computeBounds(?array $geometry): ?array
    {
        if (! is_array($geometry) || ! isset($geometry['coordinates'])) {
            return null;
        }

        $minLat = 90.0;
        $maxLat = -90.0;
        $minLng = 180.0;
        $maxLng = -180.0;

        $type = $geometry['type'] ?? '';
        $coords = $geometry['coordinates'];
        $polygons = $type === 'Polygon' ? [$coords] : ($type === 'MultiPolygon' ? $coords : []);

        foreach ($polygons as $rings) {
            foreach ($rings as $ring) {
                foreach ($ring as $point) {
                    [$lng, $lat] = [(float) $point[0], (float) $point[1]];
                    if ($lat < $minLat) {
                        $minLat = $lat;
                    }
                    if ($lat > $maxLat) {
                        $maxLat = $lat;
                    }
                    if ($lng < $minLng) {
                        $minLng = $lng;
                    }
                    if ($lng > $maxLng) {
                        $maxLng = $lng;
                    }
                }
            }
        }

        if ($minLat > $maxLat) {
            return null;
        }

        return [$minLng, $minLat, $maxLng, $maxLat];
    }

    private function inBounds(array $region, float $lat, float $lng): bool
    {
        $b = $region['bounds'] ?? null;
        if (! $b) {
            return true; // tanpa bounds, tes penuh
        }

        return $lng >= $b[0] && $lat >= $b[1] && $lng <= $b[2] && $lat <= $b[3];
    }

    /**
     * Apakah titik berada di dalam geometry MultiPolygon/Polygon wilayah.
     */
    private function contains(array $region, float $lat, float $lng): bool
    {
        $geometry = $region['geometry'] ?? null;
        if (! is_array($geometry) || ! isset($geometry['type'])) {
            return false;
        }

        $type = $geometry['type'];
        $coords = $geometry['coordinates'] ?? [];

        if ($type === 'Polygon') {
            return $this->pointInPolygon($coords, $lat, $lng);
        }

        if ($type === 'MultiPolygon') {
            foreach ($coords as $polygon) {
                if ($this->pointInPolygon($polygon, $lat, $lng)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Point-in-polygon (ray casting). $polygon = [[ [lng,lat], ... ], ...rings].
     * Cek ring luar; lubang (hole) bersifat eksklusi.
     */
    private function pointInPolygon(array $polygon, float $lat, float $lng): bool
    {
        $rings = array_values($polygon);
        if (! $rings) {
            return false;
        }

        $outer = $rings[0];
        if (! $this->rayCast($outer, $lat, $lng)) {
            return false;
        }

        for ($i = 1, $n = count($rings); $i < $n; $i++) {
            if ($this->rayCast($rings[$i], $lat, $lng)) {
                return false;
            }
        }

        return true;
    }

    private function rayCast(array $ring, float $lat, float $lng): bool
    {
        $inside = false;
        $count = count($ring);

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) $ring[$i][0];
            $yi = (float) $ring[$i][1];
            $xj = (float) $ring[$j][0];
            $yj = (float) $ring[$j][1];

            $intersects = (($yi > $lat) !== ($yj > $lat))
                && ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
