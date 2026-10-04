<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\PhotoQualityValidator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Membersihkan folder location_photos dan merapikan kolom locations.photo.
 *
 * Operasi yang dilakukan:
 *
 *  1. orphans      - berkas di storage yang tidak dirujuk lokasi mana pun
 *  2. placeholders - kanvas putih bertulis, bidang warna rata, gambar
 *                    hitam/putih penuh (dinilai oleh PhotoQualityValidator)
 *  3. broken       - berkas rusak/tidak bisa dibaca
 *  4. duplicates   - isi identik yang dipakai banyak lokasi berbeda
 *  5. missing      - path di DB yang menunjuk berkas tidak ada
 *
 * Kolom photo untuk lokasi yang fotanya dihapus selalu di-set NULL supaya
 * frontend tidak menampilkan gambar rusak atau gambar milik lokasi lain.
 */
class CleanupPhotos extends Command
{
    protected $signature = 'photos:cleanup
                            {--dry-run : Tampilkan rencana tanpa menghapus/menulis apa pun}
                            {--orphans : Hanya hapus berkas yatim (tak dirujuk)}
                            {--placeholders : Hanya hapus placeholder/bukan-foto}
                            {--duplicates : Hanya lepas foto duplikat lintas lokasi}
                            {--missing : Hanya null-kan path foto yang hilang}
                            {--delete-empty : Hapus record lokasi yang fotonya kosong}
                            {--include-non-place : Sertakan kategori wilayah/provinsi saat --delete-empty}
                            {--quarantine= : Pindahkan berkas ke folder ini alih-alih menghapus}
                            {--limit=0 : Batasi jumlah berkas yang diproses per kategori}';

    protected $description = 'Bersihkan foto lokasi: hapus placeholder/orphan/duplikat dan null-kan referensi rusak';

    public function handle(PhotoQualityValidator $validator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = (int) $this->option('limit');
        $quarantine = $this->option('quarantine');

        $only = array_keys(array_filter([
            'orphans' => (bool) $this->option('orphans'),
            'placeholders' => (bool) $this->option('placeholders'),
            'duplicates' => (bool) $this->option('duplicates'),
            'missing' => (bool) $this->option('missing'),
        ]));

        // Tanpa flag kategori, jalankan semuanya.
        if ($only === []) {
            $only = ['orphans', 'placeholders', 'duplicates', 'missing'];
        }

        $disk = Storage::disk('public');
        $referenced = Location::query()
            ->whereNotNull('photo')
            ->pluck('photo')
            ->map(fn ($p) => basename($p))
            ->flip()
            ->all();

        $stats = [
            'orphans_deleted' => 0,
            'placeholders_deleted' => 0,
            'weak_cleared' => 0,
            'duplicates_cleared' => 0,
            'missing_nulled' => 0,
            'locations_deleted' => 0,
            'bytes_freed' => 0,
        ];

        $this->line('Mode: '.($dryRun ? 'DRY-RUN (tidak ada yang berubah)' : 'LIVE'));
        if ($quarantine) {
            $this->line("Quarantine: {$quarantine}");
        }
        $this->newLine();

        if (in_array('orphans', $only, true)) {
            $this->handleOrphans($disk, $referenced, $dryRun, $quarantine, $limit, $stats);
        }

        if (in_array('placeholders', $only, true)) {
            $this->handlePlaceholders($disk, $referenced, $validator, $dryRun, $quarantine, $limit, $stats);
        }

        if (in_array('duplicates', $only, true)) {
            $this->handleDuplicates($disk, $dryRun, $stats);
        }

        if (in_array('missing', $only, true)) {
            $this->handleMissing($disk, $dryRun, $stats);
        }

        if ($this->option('delete-empty')) {
            $this->handleDeleteEmpty($disk, $dryRun, $stats);
        }

        $this->newLine();
        $this->info('=== Ringkasan ===');
        $this->line("  Orphan dihapus       : {$stats['orphans_deleted']}");
        $this->line("  Placeholder dihapus  : {$stats['placeholders_deleted']}");
        $this->line("  Foto lemah di-refetch: {$stats['weak_cleared']}");
        $this->line("  Duplikat di-null-kan : {$stats['duplicates_cleared']}");
        $this->line("  Path hilang di-null  : {$stats['missing_nulled']}");
        $this->line("  Lokasi dihapus       : {$stats['locations_deleted']}");
        $this->line('  Ruang dibebaskan    : '.number_format($stats['bytes_freed'] / 1024 / 1024, 2).' MB');

        return 0;
    }

    /**
     * Lokasi tanpa foto dihapus, bukan dibiarkan dengan photo NULL.
     *
     * Aturan ini yang dipakai otomatis setelah sinkronisasi/fetch: lebih baik
     * tidak ada lokasi daripada lokasi yang fotonya bukan gambar tempat.
     * Karena itu jalankan setelah tahap fetch, supaya lokasi yang masih bisa
     * mendapat foto tidak ikut terhapus.
     */
    protected function handleDeleteEmpty($disk, bool $dryRun, array &$stats): void
    {
        // "Tanpa foto" berarti kolom kosong ATAU path-nya menunjuk berkas
        // yang sudah tidak ada di storage.
        $empty = Location::query()
            ->with('category')
            ->get(['id', 'name', 'photo', 'category_id'])
            ->filter(fn (Location $loc) => empty($loc->photo) || ! $disk->exists($loc->photo))
            ->values();

        // Baris wilayah/provinsi adalah dimensi referensi: 1.644 lokasi
        // menunjuk mereka lewat wilayah_id. Menghapusnya hanya karena tidak
        // ada foto akan membongkar peta wilayah, jadi defaultnya dikecualikan.
        $includeNonPlace = (bool) $this->option('include-non-place');

        $empty = $empty
            ->filter(fn (Location $loc) => $includeNonPlace
                || in_array($loc->category?->name, Category::PLACE_TYPES, true))
            ->values();

        if ($empty->isEmpty()) {
            $this->line('Lokasi tanpa foto: 0');

            return;
        }

        $this->info(sprintf('Lokasi tanpa foto yang akan dihapus: %d', $empty->count()));

        $byCategory = [];
        foreach ($empty as $loc) {
            $byCategory[$loc->category?->name ?? '(tanpa kategori)'][] = $loc->name;
        }

        foreach ($byCategory as $cat => $names) {
            $this->line(sprintf('  - %s: %d (%s)', $cat, count($names), mb_strimwidth(implode(', ', $names), 0, 90)));
        }

        if ($dryRun) {
            $stats['locations_deleted'] = $empty->count();

            return;
        }

        // Cascade di level database: location_photos, reviews, dan favorites
        // ikut terhapus karena seluruhnya cascadeOnDelete.
        $stats['locations_deleted'] = Location::query()
            ->whereIn('id', $empty->pluck('id'))
            ->delete();
    }

    /**
     * Berkas di storage yang tidak dirujuk lokasi mana pun.
     */
    protected function handleOrphans(
        $disk,
        array $referenced,
        bool $dryRun,
        ?string $quarantine,
        int $limit,
        array &$stats
    ): void {
        $files = $disk->files('location_photos');
        $orphans = array_values(array_filter(
            $files,
            fn ($f) => ! isset($referenced[basename($f)])
        ));

        if ($limit > 0) {
            $orphans = array_slice($orphans, 0, $limit);
        }

        $this->info(sprintf('Orphan (tak dirujuk lokasi mana pun): %d berkas', count($orphans)));

        foreach ($orphans as $file) {
            $size = $disk->size($file);
            $stats['bytes_freed'] += $size;

            if (! $dryRun) {
                $this->removeOrQuarantine($disk, $file, $quarantine);
            }
            $stats['orphans_deleted']++;
        }
    }

    /**
     * Berkas yang isinya bukan foto: teks di kanvas putih, bidang warna rata,
     * atau gambar hitam/putih penuh.
     */
    protected function handlePlaceholders(
        $disk,
        array $referenced,
        PhotoQualityValidator $validator,
        bool $dryRun,
        ?string $quarantine,
        int $limit,
        array &$stats
    ): void {
        $rejected = [];
        $checked = 0;

        foreach ($disk->files('location_photos') as $file) {
            if ($limit > 0 && $checked >= $limit) {
                break;
            }
            $checked++;

            $result = $validator->inspect((string) $disk->get($file));

            if (! $result['ok']) {
                $rejected[$file] = $result['reason'];
            }
        }

        $this->info(sprintf('Placeholder / bukan-foto: %d dari %d berkas diperiksa', count($rejected), $checked));

        $byReason = [];
        foreach ($rejected as $file => $reason) {
            $byReason[$reason][] = $file;
        }

        foreach ($byReason as $reason => $files) {
            $label = $validator->isSoftFailure($reason) ? 'lemah (refetch)' : 'keras';
            $this->line("  - {$reason} [{$label}]: ".count($files).' berkas');
        }

        foreach ($rejected as $file => $reason) {
            // $file sudah mengandung prefix "location_photos/".
            $full = $file;
            $size = $disk->size($full);
            $stats['bytes_freed'] += $size;

            if (! $dryRun) {
                $this->removeOrQuarantine($disk, $full, $quarantine);
                $this->nullPhotoFor(basename($file));
            }

            if ($validator->isSoftFailure($reason)) {
                $stats['weak_cleared']++;
            } else {
                $stats['placeholders_deleted']++;
            }
        }
    }

    /**
     * Isi identik yang dipakai lebih dari satu lokasi.
     *
     * Untuk setiap kelompok md5, lokasi paling lama dipertahankan; sisanya
     * di-null-kan supaya bisa di-fetch ulang dengan gambar yang benar.
     */
    protected function handleDuplicates($disk, bool $dryRun, array &$stats): void
    {
        $groups = [];

        $locations = Location::query()
            ->whereNotNull('photo')
            ->orderBy('id')
            ->get(['id', 'name', 'photo']);

        foreach ($locations as $loc) {
            if (! $disk->exists($loc->photo)) {
                continue;
            }

            $groups[md5((string) $disk->get($loc->photo))][] = $loc;
        }

        $duplicatedGroups = array_filter($groups, fn ($g) => count($g) > 1);
        $totalToClear = array_sum(array_map(fn ($g) => count($g) - 1, $duplicatedGroups));

        $this->info(sprintf(
            'Duplikat: %d kelompok, %d berkas akan dilepas (lokasi paling lama dipertahankan)',
            count($duplicatedGroups),
            $totalToClear
        ));

        foreach ($duplicatedGroups as $hash => $group) {
            // Pertahankan yang pertama (lokasi paling lama / id terkecil).
            $keep = array_shift($group);

            $this->line(sprintf(
                '  %s: %d lokasi, pertahankan #%d, lepas %d',
                substr($hash, 0, 10),
                count($group) + 1,
                $keep->id,
                count($group)
            ));

            foreach ($group as $loc) {
                if (! $dryRun) {
                    $loc->update(['photo' => null]);
                }
                $stats['duplicates_cleared']++;
            }
        }
    }

    /**
     * Path di DB yang menunjuk berkas sudah tidak ada.
     */
    protected function handleMissing($disk, bool $dryRun, array &$stats): void
    {
        $missing = [];

        // Eager load kategori: lazy loading dimatikan di app ini, jadi tanpa
        // with('category') relasi selalu null dan kategori ikut salah klasifikasi.
        foreach (Location::query()
            ->with('category')
            ->whereNotNull('photo')
            ->get(['id', 'name', 'photo', 'category_id']) as $loc) {
            if (! $disk->exists($loc->photo)) {
                $missing[] = $loc;
            }
        }

        $this->info(sprintf('Path foto hilang di DB: %d lokasi', count($missing)));

        $byCategory = [];
        foreach ($missing as $loc) {
            $byCategory[$loc->category?->name ?? '(tanpa kategori)'][] = $loc;
        }

        foreach ($byCategory as $cat => $locs) {
            $isPlace = in_array($cat, Category::PLACE_TYPES, true);
            $this->line(sprintf('  - %s: %d lokasi (%s)', $cat, count($locs), $isPlace ? 'bisa di-fetch ulang' : 'tanpa sumber foto -> NULL'));

            if ($isPlace || $dryRun) {
                // Kategori tempat akan ditangani photos:fetch-wikimedia.
                continue;
            }

            foreach ($locs as $loc) {
                if (! $dryRun) {
                    $loc->update(['photo' => null]);
                }
                $stats['missing_nulled']++;
            }
        }
    }

    /** Set photo = NULL untuk semua lokasi yang memakai basename ini. */
    protected function nullPhotoFor(string $basename): int
    {
        return Location::query()
            ->where('photo', 'like', '%/'.$basename)
            ->orWhere('photo', $basename)
            ->update(['photo' => null]);
    }

    /**
     * Hapus berkas, atau simpan salinannya ke folder quarantine lebih dulu.
     *
     * Quarantine ditulis ke disk 'local' (bukan 'public') supaya tidak ikut
     * tervalidasi lewat web dan tetap bisa dipulihkan bila klasifikasi
     * ternyata keliru.
     */
    protected function removeOrQuarantine($disk, string $file, ?string $quarantine): void
    {
        if ($quarantine) {
            $target = trim($quarantine, '/').'/'.basename($file);
            Storage::disk('local')->put($target, $disk->get($file));
        }

        $disk->delete($file);
    }
}
