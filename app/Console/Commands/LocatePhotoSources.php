<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Periksa koordinat sumber foto yang sudah terpasang.
 *
 * Tujuannya memangkas antrean persetujuan: foto yang koordinat sumbernya
 * berdekatan dengan lokasi otomatis disetujui, foto yang koordinatnya jauh
 * dilepas supaya di-fetch ulang, sisanya tetap menunggu diperiksa manusia.
 */
class LocatePhotoSources extends Command
{
    protected $signature = 'photos:locate-sources
                            {--limit=0 : Berapa banyak foto diproses per eksekusi (0 = semua)}
                            {--sleep=0 : Delay antar request (detik)}
                            {--dry-run : Hanya laporan, tidak mengubah data}';

    protected $description = 'Cocokkan koordinat sumber foto dengan koordinat lokasi untuk verifikasi otomatis';

    public function handle(WikimediaPhotoFetcher $fetcher): int
    {
        $limit = (int) $this->option('limit');
        $sleep = max(0, (int) $this->option('sleep'));
        $dryRun = (bool) $this->option('dry-run');

        $query = Location::query()
            ->with('category')
            ->whereNotNull('photo')
            ->whereNotNull('photo_source_title')
            ->whereNull('photo_source_distance_m')
            ->orderBy('id');

        $locations = $query->get();

        if ($limit > 0) {
            $locations = $locations->take($limit);
        }

        $total = $locations->count();

        if ($total === 0) {
            $this->info('Semua foto sudah punya data koordinat sumber.');

            return 0;
        }

        $this->line("Memeriksa koordinat sumber {$total} foto...");

        $disk = Storage::disk('public');
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $autoApproved = 0;
        $tooFar = 0;
        $keptPending = 0;

        foreach ($locations as $loc) {
            $evidence = $fetcher->judgeSource(
                $loc->photo_source_title,
                $loc->photo_source_url,
                $loc->latitude !== null ? (float) $loc->latitude : null,
                $loc->longitude !== null ? (float) $loc->longitude : null,
                (string) $loc->name
            );

            $sourceEvidence = $fetcher->sourceEvidence($loc->photo_source_title, $loc->photo_source_url);

            if (! $dryRun) {
                $loc->update([
                    'photo_source_lat' => $sourceEvidence['lat'] ?? null,
                    'photo_source_lon' => $sourceEvidence['lon'] ?? null,
                    'photo_source_distance_m' => $evidence['distance_m'],
                ]);

                if ($evidence['verdict'] === 'auto') {
                    $loc->update([
                        'photo_review_status' => Location::PHOTO_APPROVED,
                        'photo_reviewed_at' => now(),
                        'photo_review_note' => $evidence['note'],
                    ]);

                    $autoApproved++;
                } elseif ($evidence['verdict'] === 'too_far') {
                    // Foto milik tempat lain: lepas supaya tidak dipakai dan
                    // lokasi ikut tidak ada foto valid.
                    $disk->delete($loc->photo);
                    $loc->update([
                        'photo' => null,
                        'photo_source_title' => null,
                        'photo_source_url' => null,
                        'photo_source_provider' => null,
                        'photo_source_lat' => null,
                        'photo_source_lon' => null,
                        'photo_source_distance_m' => null,
                        'photo_review_status' => null,
                        'photo_review_note' => null,
                    ]);

                    $tooFar++;
                } else {
                    $loc->update(['photo_review_note' => $evidence['note']]);
                    $keptPending++;
                }
            } else {
                match ($evidence['verdict']) {
                    'auto' => $autoApproved++,
                    'too_far' => $tooFar++,
                    default => $keptPending++,
                };
            }

            $bar->advance();

            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        $bar->finish();
        $this->newLine();

        $this->info("Auto-disetujui (bukti kuat) : {$autoApproved}");
        $this->info("Dilepas (terlalu jauh)      : {$tooFar}");
        $this->info("Tetap menunggu diperiksa   : {$keptPending}");

        return 0;
    }
}
