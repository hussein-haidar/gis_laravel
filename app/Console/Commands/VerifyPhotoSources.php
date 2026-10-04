<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Services\Gis\WikimediaPhotoFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Audit sumber foto: apakah gambar yang terpasang memang milik nama tempat.
 *
 * Aturan yang dipegang: satu foto harus berasal dari satu nama tempat. Foto
 * bisa dinilai dari metadata sumbernya (judul berkas di Commons/Openverse):
 *
 *  - ok        - judul sumber memuat seluruh kata bermakna nama lokasi
 *  - mismatch  - judul sumber tidak menyebut nama lokasi, jadi gambar itu
 *                milik tempat lain
 *  - unverified- foto lama tanpa metadata sumber, asal-usulnya tidak bisa
 *                dibuktikan
 *
 * Status mismatch dan unverified bisa dilepas dengan --fix supaya lokasi
 * di-fetch ulang dengan kandidat yang namanya benar. Lokasi yang tetap tidak
 * mendapat foto dihapus oleh photos:cleanup --delete-empty.
 */
class VerifyPhotoSources extends Command
{
    protected $signature = 'photos:verify-sources
                            {--fix : Lepas foto yang sumbernya tidak cocok agar di-fetch ulang}
                            {--limit=0 : Batasi jumlah lokasi yang diproses}';

    protected $description = 'Periksa apakah sumber foto sesuai dengan nama tempat';

    public function handle(WikimediaPhotoFetcher $fetcher): int
    {
        $limit = (int) $this->option('limit');
        $fix = (bool) $this->option('fix');

        $locations = Location::query()
            ->with('category')
            ->whereNotNull('photo')
            ->where('photo', '!=', '')
            ->orderBy('id');

        if ($limit > 0) {
            $locations->limit($limit);
        }

        $disk = Storage::disk('public');
        $stats = ['ok' => 0, 'mismatch' => 0, 'unverified' => 0, 'missing_file' => 0];
        $flagged = [];

        foreach ($locations->get() as $loc) {
            if (! $disk->exists($loc->photo)) {
                $stats['missing_file']++;
                $flagged[] = [$loc, 'missing_file'];

                continue;
            }

            $title = (string) ($loc->photo_source_title ?? '');

            if ($title === '') {
                $stats['unverified']++;
                $flagged[] = [$loc, 'unverified'];

                continue;
            }

            if ($fetcher->titleMatchesLocation($title, $loc->name)) {
                $stats['ok']++;
            } else {
                $stats['mismatch']++;
                $flagged[] = [$loc, 'mismatch'];
            }
        }

        $this->newLine();
        $this->info('=== Ringkasan sumber foto ===');
        $this->line("  Sesuai nama tempat  : {$stats['ok']}");
        $this->line("  Nama tidak cocok   : {$stats['mismatch']}");
        $this->line("  Tanpa metadata     : {$stats['unverified']}");
        $this->line("  File hilang        : {$stats['missing_file']}");

        $suspect = array_values(array_filter(
            $flagged,
            fn ($row) => $row[1] !== 'ok'
        ));

        if ($suspect !== []) {
            $this->newLine();
            $this->line('Contoh yang perlu diperbaiki:');
            foreach (array_slice($suspect, 0, 25) as [$loc, $status]) {
                $this->line(sprintf(
                    '  [%s] #%d %s — sumber: %s',
                    $status,
                    $loc->id,
                    mb_strimwidth($loc->name, 0, 40),
                    mb_strimwidth((string) ($loc->photo_source_title ?? '(tidak ada)'), 0, 50)
                ));
            }
        }

        if (! $fix) {
            $this->newLine();
            $this->comment('Jalankan dengan --fix untuk melepas foto bermasalah dan mengisinya ulang.');

            return 0;
        }

        $cleared = 0;

        foreach ($suspect as [$loc, $status]) {
            if ($status === 'missing_file') {
                $loc->update(['photo' => null]);

                $cleared++;

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

            $cleared++;
        }

        $this->newLine();
        $this->info("Foto dilepas agar di-fetch ulang: {$cleared}");

        return 0;
    }
}
