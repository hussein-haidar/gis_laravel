<?php

namespace App\Services\Gis;

use App\Jobs\FetchMissingLocationPhotos;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Menjalankan pembersihan foto tanpa perlu perintah manual.
 *
 * Dipanggil dari tiga tempat:
 *  1. setelah sinkronisasi data admin (GisDataSyncService)
 *  2. setelah job fetch foto selesai tidak ada lagi lokasi kosong
 *  3. jadwal harian di bootstrap/app.php
 *
 * Urutannya penting: bersihkan dulu (lepas placeholder/foto lemah), lalu
 * fetch ulang lokasi yang fotonya baru dilepas, baru terakhir hapus lokasi
 * yang masih kosong. Kalau dihapus lebih dulu, lokasi yang sebenarnya masih
 * bisa mendapat foto ikut hilang.
 */
class PhotoMaintenance
{
    /**
     * Bersihkan, refill, lalu hapus lokasi yang tetap tanpa foto.
     *
     * @return array<string, int>
     */
    public function run(bool $deleteEmpty = true): array
    {
        $args = $deleteEmpty ? ['--delete-empty' => true] : [];

        Artisan::call('photos:cleanup', $args);

        $output = Artisan::output();
        $stats = $this->parseStats($output);

        Log::info('PhotoMaintenance selesai', $stats);

        return $stats;
    }

    /**
     * Bersihkan tanpa menghapus lokasi, lalu refill foto yang baru dilepas.
     *
     * Dipakai setelah sinkronisasi supaya lokasi dengan placeholder/null
     * segera mendapat kandidat baru dan tidak langsung terhapus.
     *
     * @return array<string, int>
     */
    public function refresh(): array
    {
        $stats = $this->run(deleteEmpty: false);

        $this->dispatchRefill();

        return $stats;
    }

    protected function dispatchRefill(): void
    {
        $fetcher = new WikimediaPhotoFetcher;

        if ($fetcher->locationsMissingPhoto()->isEmpty()) {
            return;
        }

        if (config('services.openverse.queue_fetch', true)) {
            FetchMissingLocationPhotos::dispatch();

            return;
        }

        FetchMissingLocationPhotos::dispatchSync();
    }

    /** @return array<string, int> */
    protected function parseStats(string $output): array
    {
        $map = [
            'Orphan dihapus' => 'orphans_deleted',
            'Placeholder dihapus' => 'placeholders_deleted',
            'Foto lemah di-refetch' => 'weak_cleared',
            'Duplikat di-null-kan' => 'duplicates_cleared',
            'Path hilang di-null' => 'missing_nulled',
            'Lokasi dihapus' => 'locations_deleted',
        ];

        $stats = array_fill_keys($map, 0);

        foreach (explode("\n", $output) as $line) {
            foreach ($map as $label => $key) {
                if (preg_match('/'.preg_quote($label, '/').'\s*:\s*(\d+)/', $line, $m)) {
                    $stats[$key] = (int) $m[1];
                }
            }
        }

        return $stats;
    }
}
