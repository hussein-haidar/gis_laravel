<?php

namespace App\Console\Commands;

use App\Services\Gis\TourismPlacesSyncService;
use Illuminate\Console\Command;

class TourismPlacesSync extends Command
{
    protected $signature = 'wisata:sync
                            {--source=* : Pilih sumber data (overpass,datagoi,custom_api). Default: semua}
                            {--province= : Filter provinsi (untuk Overpass - belum diimplementasikan)}
                            {--dry-run : Jalankan tanpa menyimpan ke database}
                            {--skip-photo : Lewati download foto}
                            {--limit= : Batas jumlah tempat yang diproses}
                            {--category= : Kategori default jika tidak terdeteksi}';

    protected $description = 'Sinkronisasi tempat wisata dari Overpass API (OSM), Data.go.id, dan Custom API';

    public function handle(TourismPlacesSyncService $syncService): int
    {
        $this->info('Memulai sinkronisasi tempat wisata...');

        $sources = $this->option('source');
        if (!empty($sources)) {
            $this->line('Sumber aktif: ' . implode(', ', $sources));
            $syncService->setEnabledSources($sources);
        }

        if ($this->option('category')) {
            $syncService->setDefaultCategory($this->option('category'));
            $this->line("Kategori default: {$this->option('category')}");
        }

        if ($this->option('skip-photo')) {
            $syncService->setSkipPhoto(true);
            $this->warn('Download foto DILEWATI');
        }

        if ($limit = $this->option('limit')) {
            $syncService->setLimit((int)$limit);
            $this->line("Batas proses: {$limit}");
        }

        if ($this->option('dry-run')) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang akan disimpan');
            $syncService->setDryRun(true);
        }

        $startTime = microtime(true);

        try {
            $stats = $syncService->sync();
        } catch (\Throwable $e) {
            $this->error("Sinkronisasi gagal: {$e->getMessage()}");
            $this->newLine();
            $this->error($e->getTraceAsString());
            return self::FAILURE;
        }

        $duration = round(microtime(true) - $startTime, 2);

        $this->newLine();
        $this->info("Sinkronisasi selesai dalam {$duration} detik");
        $this->newLine();

        $this->table(
            ['Metrik', 'Jumlah'],
            [
                ['Total Unik', $stats['total']],
                ['Dibuat Baru', $stats['created']],
                ['Diperbarui', $stats['updated']],
                ['Dilewati', $stats['skipped']],
                ['Error', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("Terdapat {$stats['errors']} error. Cek log untuk detail.");
        }

        // Photo fetch stats
        $photoStats = $syncService->lastPhotoFetchStats();
        if ($photoStats) {
            $this->newLine();
            $this->info('Statistik Foto (otomatis):');
            $this->table(
                ['Metrik', 'Jumlah'],
                [
                    ['Diproses', $photoStats['attempted'] ?? 0],
                    ['Berhasil', $photoStats['success'] ?? 0],
                    ['Sisa Antrean', $photoStats['remaining'] ?? 0],
                    ['Mode Queue', $photoStats['queued'] ? 'Ya' : 'Tidak'],
                ]
            );
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN: Perubahan tidak disimpan ke database');
        }

        return self::SUCCESS;
    }
}