<?php

namespace App\Services\Gis;

use App\Models\Category;
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
            if (!$this->inBounds($region, $latitude, $longitude)) {
                continue;
            }
            if ($this->contains($region, $latitude, $longitude)) {
                $matches[] = $region;
            }
        }

        if (!$matches) {
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
        if (!$b) {
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
            $names = Category::PLACE_TYPES;

            $regions = Location::query()
                ->with('category')
                ->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereNotNull('geometry')
                ->whereHas('category', fn ($q) => $q->whereNotIn('name', $names))
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
        if (!is_array($geometry) || !isset($geometry['coordinates'])) {
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
                    if ($lat < $minLat) $minLat = $lat;
                    if ($lat > $maxLat) $maxLat = $lat;
                    if ($lng < $minLng) $minLng = $lng;
                    if ($lng > $maxLng) $maxLng = $lng;
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
        if (!$b) {
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
        if (!is_array($geometry) || !isset($geometry['type'])) {
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
        if (!$rings) {
            return false;
        }

        $outer = $rings[0];
        if (!$this->rayCast($outer, $lat, $lng)) {
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
                $inside = !$inside;
            }
        }

        return $inside;
    }
}