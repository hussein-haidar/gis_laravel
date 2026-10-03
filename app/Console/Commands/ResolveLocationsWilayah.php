<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\WilayahResolver;
use Illuminate\Console\Command;

class ResolveLocationsWilayah extends Command
{
    protected $signature = 'locations:resolve-wilayah
                            {--nearest-km=50 : Ambang jarak fallback ke centroid kabupaten terdekat (km)}
                            {--proximity-km=30 : Ambang jarak fallback ke sisi poligon kabupaten (km)}
                            {--recheck : Nilai ulang lokasi yang SUDAH punya wilayah_id (default: hanya yang kosong)}
                            {--dry-run : Tampilkan rencana perubahan tanpa menyentuh database}';

    protected $description = 'Isi wilayah_id setiap lokasi tempat dengan kabupaten/kota yang memuat titiknya';

    public function handle(WilayahResolver $resolver): int
    {
        $nearestKm = (float) $this->option('nearest-km');
        $proximityKm = (float) $this->option('proximity-km');
        $recheck = (bool) $this->option('recheck');
        $dryRun = (bool) $this->option('dry-run');

        $query = Location::query()
            ->with('category')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES));

        // Backfill default aman: hanya mengisi yang kosong. Menimpa nilai yang
        // sudah ada hanya dilakukan bila operator benar-benar meminta --recheck,
        // karena beberapa wilayah bertetangga bisa tertukar secara sah.
        if (! $recheck) {
            $query->whereNull('wilayah_id');
        }

        $places = $query->orderBy('id')->get();

        if ($places->isEmpty()) {
            $this->info($recheck
                ? 'Tidak ada lokasi tempat untuk diproses.'
                : 'Semua lokasi tempat sudah punya wilayah_id (gunakan --recheck untuk nilai ulang).');

            return 0;
        }

        $bar = $this->output->createProgressBar($places->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;
        $changes = [];

        foreach ($places as $loc) {
            [$region, $method] = $this->resolveRegion($resolver, $loc, $proximityKm, $nearestKm);

            if ($region && (int) $loc->wilayah_id !== $region['id']) {
                $changes[] = [
                    'id' => $loc->id,
                    'name' => $loc->name,
                    'from' => $loc->wilayah_id,
                    'to' => $region,
                    'method' => $method,
                ];

                if (! $dryRun) {
                    $loc->wilayah_id = $region['id'];
                    $loc->saveQuietly();
                }
                $updated++;
            } else {
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($changes !== []) {
            $wilayahNames = Location::whereIn('id', array_filter(array_column($changes, 'from')))
                ->pluck('name', 'id');

            $rows = [];
            foreach ($changes as $c) {
                $from = $c['from']
                    ? $wilayahNames[$c['from']]." (#{$c['from']})"
                    : '-';
                $jarak = isset($c['to']['jarak_km']) ? number_format($c['to']['jarak_km'], 2).' km' : '-';
                $rows[] = [
                    $c['id'],
                    $c['name'],
                    $from,
                    "{$c['to']['name']} ({$c['to']['provinsi']})",
                    $c['method'],
                    $jarak,
                ];
            }

            $this->table(['ID', 'Nama', 'Dari', 'Menjadi', 'Metode', 'Jarak'], $rows);
        }

        $suffix = $dryRun ? ' [dry-run: tidak ada data yang disimpan]' : '';
        $this->info("Selesai! Terisi: {$updated}, Terlewati (sudah benar / tak ada wilayah): {$skipped}{$suffix}");

        return 0;
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    private function resolveRegion(WilayahResolver $resolver, Location $loc, float $proximityKm, float $nearestKm): array
    {
        $lat = (float) $loc->latitude;
        $lng = (float) $loc->longitude;

        $region = $resolver->resolve($lat, $lng);
        if ($region && $region['name'] !== $region['provinsi']) {
            return [$region, 'poligon'];
        }

        // Titik kepulauan / titik di laut: ukur jarak ke sisi poligon, bukan centroid.
        if ($proximityKm > 0) {
            $near = $resolver->resolveByProximity($lat, $lng, $proximityKm);
            if ($near) {
                return [$near, 'proksimitas'];
            }
        }

        // Poligon provinsi utuh yang tertangkap resolve() tetap dipakai sebagai
        // jawaban terakhir agar lokasi tidak kehilangan label sama sekali.
        if ($region) {
            return [$region, 'provinsi'];
        }

        if ($nearestKm > 0) {
            $near = $resolver->resolveNearest($lat, $lng, $nearestKm);
            if ($near) {
                return [$near, 'centroid'];
            }
        }

        return [null, 'tidak ditemukan'];
    }
}
