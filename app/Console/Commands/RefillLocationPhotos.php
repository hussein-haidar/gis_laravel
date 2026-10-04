<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Isi ulang foto lokasi sampai setiap foto benar-benar milik nama tempatnya.
 *
 * Satu putaran:
 *  1. fetch foto untuk lokasi kategori tempat yang fotonya kosong
 *  2. hapus lokasi yang tetap tidak mendapat foto
 *  3. audit sumber foto; lepas yang judul sumbernya tidak cocok
 *
 * Putaran diulang sampai tidak ada lagi foto yang sumbernya tidak cocok, atau
 * --rounds habis. Command ini yang dipakai untuk menjalankan perbaikan massal
 * tanpa perlu Izin manual per lokasi.
 */
class RefillLocationPhotos extends Command
{
    protected $signature = 'photos:refill-all
                            {--rounds=2 : Berapa kali putaran fetch + audit}
                            {--sleep=1 : Delay antar request (detik)}
                            {--limit=0 : Batasi jumlah lokasi per putaran (0 = semua)}';

    protected $description = 'Fetch ulang foto lokasi sampai sumbernya cocok dengan nama tempat';

    public function handle(WikimediaPhotoFetcher $fetcher): int
    {
        $rounds = max(1, (int) $this->option('rounds'));
        $sleep = max(0, (int) $this->option('sleep'));
        $limit = (int) $this->option('limit');

        for ($round = 1; $round <= $rounds; $round++) {
            $this->info("=== Putaran {$round}/{$rounds} ===");

            $missing = $fetcher->locationsMissingPhoto()->count();
            $processed = $this->fetchRound($fetcher, $sleep, $limit);
            $this->line("  Foto terpasang: {$processed}");

            // Lepas dulu foto yang sumbernya milik tempat lain. Ini harus
            // sebelum hapus lokasi supaya foto salah sumber diperlakukan
            // sama dengan tidak ada foto: lokasinya dihapus di putaran ini
            // juga, bukan menunggu putaran berikutnya.
            $released = $this->releaseMismatchedSources($fetcher);

            // Hapus lokasi yang tetap tanpa foto HANYA kalau semua lokasi
            // tanpa foto sudah sempat dicoba. Kalau --limit membatasi,
            // lokasi yang belum dicoba akan ikut terhapus.
            $complete = $limit <= 0 || $missing <= $limit;

            if ($complete) {
                $this->call('photos:cleanup', ['--delete-empty' => true]);
                $this->line('  Lokasi tanpa foto tersisa: '.$this->emptyPhotoCount());
            } else {
                $this->line("  --limit {$limit}: lewati hapus lokasi (masih ada {$missing} tanpa foto).");
            }

            if ($processed === 0 && $released === 0) {
                $this->info('Tidak ada perubahan lagi. Selesai.');

                break;
            }
        }

        $this->newLine();
        $this->call('photos:verify-sources');

        $summary = [
            'lokasi' => Location::count(),
            'dengan_foto' => Location::whereNotNull('photo')->count(),
            'tanpa_foto' => Location::whereNull('photo')->orWhere('photo', '')->count(),
        ];

        Log::info('photos:refill-all selesai', $summary);
        $this->newLine();
        $this->info('Total lokasi: '.$summary['lokasi'].' | dengan foto: '.$summary['dengan_foto'].' | tanpa foto: '.$summary['tanpa_foto']);

        return 0;
    }

    protected function fetchRound(WikimediaPhotoFetcher $fetcher, int $sleep, int $limit): int
    {
        $locations = $fetcher->locationsMissingPhoto();

        if ($limit > 0) {
            $locations = $locations->take($limit);
        }

        $total = $locations->count();

        if ($total === 0) {
            return 0;
        }

        $this->line("  Lokasi tanpa foto: {$total}");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;

        foreach ($locations as $loc) {
            try {
                if ($fetcher->fetchForLocation($loc)) {
                    $success++;
                }
            } catch (\Throwable $e) {
                Log::error('Refill failed for '.$loc->name.': '.$e->getMessage());
            }

            $bar->advance();

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $bar->finish();
        $this->newLine();

        return $success;
    }

    /**
     * Lepas foto yang sumbernya tidak cocok dengan nama tempat supaya
     * di-fetch ulang pada putaran berikutnya.
     */
    protected function releaseMismatchedSources(WikimediaPhotoFetcher $fetcher): int
    {
        $disk = Storage::disk('public');

        $released = 0;

        foreach (Location::query()->whereNotNull('photo')->get() as $loc) {
            if (! $loc->photo_source_title) {
                continue;
            }

            if ($fetcher->titleMatchesLocation($loc->photo_source_title, $loc->name)) {
                continue;
            }

            $disk->delete($loc->photo);
            $loc->update([
                'photo' => null,
                'photo_source_title' => null,
                'photo_source_url' => null,
                'photo_source_provider' => null,
                'photo_fetched_at' => null,
            ]);

            $released++;
        }

        $this->line("  Foto dilepas (sumber tidak cocok): {$released}");

        return $released;
    }

    protected function emptyPhotoCount(): int
    {
        return Location::query()
            ->where(fn ($q) => $q->whereNull('photo')->orWhere('photo', ''))
            ->count();
    }
}
