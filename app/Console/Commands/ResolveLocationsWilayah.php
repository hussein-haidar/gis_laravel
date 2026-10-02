<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\WilayahResolver;
use Illuminate\Console\Command;

class ResolveLocationsWilayah extends Command
{
    protected $signature = 'locations:resolve-wilayah
                            {--nearest-km=50 : Ambang jarak fallback ke kabupaten terdekat untuk titik pesisir (km)}
                            {--dry-run : Hanya tampilkan apa yang akan di-update tanpa menyentuh database}';

    protected $description = 'Isi wilayah_id setiap lokasi tempat dengan kabupaten/kota yang memuat titiknya';

    public function handle(WilayahResolver $resolver): int
    {
        $nearestKm = (float) $this->option('nearest-km');
        $dryRun = $this->option('dry-run');

        $places = Location::query()
            ->with('category')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereHas('category', fn ($q) => $q->whereIn('name', Category::PLACE_TYPES))
            ->orderBy('id')
            ->get();

        if ($places->isEmpty()) {
            $this->info('Tidak ada lokasi tempat untuk diproses.');
            return 0;
        }

        if ($dryRun) {
            $this->info("Dry-run: akan memproses {$places->count()} lokasi tanpa menyentuh database.");
            // Tampilkan ringkasan 7 lokasi "tempat" yang butuh wilayah_id
            $placeLocs = $places->filter(fn ($l) => in_array(optional($l->category)->name, ['Wisata Alam', 'Wisata Budaya', 'Wisata Religi', 'Wisata Sejarah', 'Wisata Kuliner', 'Tempat Umum', 'Tempat Ibadah', 'Transportasi Umum', 'Tempat Pendidikan'], true));
            foreach ($placeLocs as $loc) {
                $this->line("  #{$loc->id} {$loc->name} | {$loc->category->name} | {$loc->latitude}, {$loc->longitude}");
            }
            $this->info("Selesai! (dry-run: tidak ada data yang disimpan)");
            return 0;
        }

        $bar = $this->output->createProgressBar($places->count());
        $bar->start();

        $updated = 0;
        $skipped = 0;

        foreach ($places as $loc) {
            $region = $resolver->resolve((float) $loc->latitude, (float) $loc->longitude);

            // Kalau resolve cuma dapat poligon provinsi utuh (nama == kategori),
            // berarti titik di tepi pantai/laut yang tak masuk poligon kabupaten.
            // Fallback ke kabupaten terdekat (centroid) agar badge lebih spesifik.
            if ($region === null || ($region['name'] === $region['provinsi'] && $nearestKm > 0)) {
                $region = $resolver->resolveNearest((float) $loc->latitude, (float) $loc->longitude, $nearestKm);
            }

            if ($region && (int) $loc->wilayah_id !== $region['id']) {
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
        $this->info("Selesai! Terisi: {$updated}, Terlewati (sudah benar / tak ada wilayah): {$skipped}" . ($dryRun ? ' [dry-run]' : ''));

        return 0;
    }
}