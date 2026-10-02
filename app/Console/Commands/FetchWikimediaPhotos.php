<?php

namespace App\Console\Commands;

use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Console\Command;

class FetchWikimediaPhotos extends Command
{
    protected $signature = 'photos:fetch-wikimedia
                            {--limit=0 : Batas jumlah foto (0 = semua)}
                            {--sleep=1 : Delay antar request (detik)}
                            {--token= : Openverse bearer access token}
                            {--dry-run : Hanya tampilkan yang akan di-fetch tanpa download}';

    protected $description = 'Fetch foto hilang dari Openverse/Wikimedia Commons API dan simpan ke storage';

    public function handle(WikimediaPhotoFetcher $fetcher): int
    {
        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');
        $dryRun = (bool) $this->option('dry-run');
        $token = $this->option('token');

        $locations = $fetcher->locationsMissingPhoto();

        if ($limit > 0) {
            $locations = $locations->take($limit);
        }

        $total = $locations->count();
        if ($total === 0) {
            $this->info('Tidak ada foto yang hilang.');
            return 0;
        }

        $this->info("Ditemukan {$total} lokasi dengan foto hilang.");

        if ($dryRun) {
            foreach ($locations as $loc) {
                $this->line("  - {$loc->id} {$loc->name} ({$loc->category?->name})");
            }
            return 0;
        }

        if ($token) {
            $fetcher = new WikimediaPhotoFetcher($token);
        } else {
            // gunakan token dari konfigurasi atau otomatis
            $fetcher->token();
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($locations as $loc) {
            $filename = $fetcher->fetchForLocation($loc);

            if ($filename) {
                $success++;
                $this->info("\n✓ {$loc->name} -> {$filename}");
            } else {
                $failed++;
                if ($this->option('verbose')) {
                    $this->line("\n  - Gagal: {$loc->name}");
                }
            }

            $bar->advance();
            sleep($sleep);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Selesai! Berhasil: {$success}, Gagal: {$failed}");

        return 0;
    }
}
