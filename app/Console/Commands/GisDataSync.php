<?php

namespace App\Console\Commands;

use App\Services\Gis\GisDataSyncService;
use Illuminate\Console\Command;

class GisDataSync extends Command
{
    protected $signature = 'gis:sync
                            {--url= : Override API URL from config}
                            {--dry-run : Run without saving changes}
                            {--force : Force sync even if recently synced}
                            {--limit= : Limit number of features to process}
                            {--category= : Override default category}';

    protected $description = 'Sinkronisasi data GIS dari endpoint API eksternal (GeoJSON)';

    public function handle(GisDataSyncService $syncService): int
    {
        $this->info('Memulai sinkronisasi data GIS...');

        if ($this->option('url')) {
            config(['gis.api_url' => $this->option('url')]);
            $this->line("Menggunakan URL kustom: {$this->option('url')}");
        }

        if ($this->option('category')) {
            config(['gis.default_category' => $this->option('category')]);
            $this->line("Menggunakan kategori default: {$this->option('category')}");
        }

        if ($this->option('dry-run')) {
            $this->warn('MODE DRY-RUN: Tidak ada data yang akan disimpan');
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
                ['Total Fitur', $stats['total']],
                ['Dibuat Baru', $stats['created']],
                ['Diperbarui', $stats['updated']],
                ['Dilewati', $stats['skipped']],
                ['Error', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $this->warn("Terdapat {$stats['errors']} error saat memproses. Cek log untuk detail.");
        }

        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN: Perubahan tidak disimpan ke database');
        }

        return self::SUCCESS;
    }
}