<?php

namespace App\Jobs;

use App\Services\Gis\PhotoMaintenance;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchMissingLocationPhotos implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public ?int $chunkSize = null,
        public int $chunk = 0
    ) {}

    /**
     * Isi foto lokasi yang masih kosong. Job dipanggil dari sync service lalu
     * self-dispatch ulang bila masih ada sisa, jadi admin tidak perlu
     * menjalankan command.
     */
    public function handle(WikimediaPhotoFetcher $fetcher): void
    {
        $chunkSize = $this->chunkSize
            ?: (int) config('services.openverse.auto_fetch_limit', 50);

        $missing = $fetcher->locationsMissingPhoto();
        if ($missing->isEmpty()) {
            Log::info('FetchMissingLocationPhotos: semua lokasi sudah punya foto');
            $this->deleteStillEmpty();

            return;
        }

        $batch = $missing->take($chunkSize);
        $sleepMs = (int) config('services.openverse.auto_fetch_sleep_ms', 1000);

        $success = 0;
        $done = 0;

        foreach ($batch as $loc) {
            try {
                if ($fetcher->fetchForLocation($loc)) {
                    $success++;
                }
            } catch (\Throwable $e) {
                Log::error('Auto photo fetch failed for '.$loc->name.': '.$e->getMessage());
            }

            $done++;
            usleep($sleepMs * 1000);
        }

        $remaining = $missing->count() - $batch->count();

        Log::info('FetchMissingLocationPhotos batch', [
            'chunk' => $this->chunk,
            'attempted' => $done,
            'success' => $success,
            'remaining' => $remaining,
        ]);

        if ($remaining > 0) {
            self::dispatch($this->chunkSize, $this->chunk + 1)
                ->delay(now()->addSeconds(10));

            return;
        }

        $this->deleteStillEmpty();
    }

    /**
     * Setelah tidak ada lagi lokasi kosong, jalankan pembersihan otomatis.
     *
     * Lokasi yang tetap tanpa foto (tidak ada kandidat di Commons) dihapus,
     * sesuai aturan: lebih baik tidak ada lokasi daripada lokasi yang
     * fotonya bukan gambar tempat. Lokasi tanpa foto tidak pernah muncul di
     * frontend karena tidak ada fallback placeholder.
     */
    protected function deleteStillEmpty(): void
    {
        if (! config('services.openverse.auto_cleanup_after_fetch', true)) {
            return;
        }

        try {
            app(PhotoMaintenance::class)->run();
        } catch (\Throwable $e) {
            Log::error('Photo maintenance after fetch failed: '.$e->getMessage());
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchMissingLocationPhotos gagal: '.$e->getMessage());
    }
}
