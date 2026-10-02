<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class EncryptSettingsSecrets extends Command
{
    protected $signature = 'settings:encrypt-secrets
                            {--dry-run : Tampilkan perubahan tanpa menyimpan}
                            {--decrypt : Kebalikan: kembalikan ke plaintext}';

    protected $description = 'Enkripsi (atau dekripsi) nilai setting secret yang tersimpan plaintext di database';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $decrypt = (bool) $this->option('decrypt');

        // Sengaja lewat query builder: model akan otomatisenkripsi di hook
        // saving, sedangkan command ini butuh menulis nilai mentah.
        $rows = DB::table('settings')->where('is_secret', 1)->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada setting secret.');

            return self::SUCCESS;
        }

        $changed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row) {
            $raw = (string) ($row->value ?? '');

            if ($raw === '') {
                continue;
            }

            $isEncrypted = Setting::looksEncrypted($raw);

            // Yang perlu diubah: mode enkripsi -> yang masih plaintext,
            // mode dekripsi -> yang sudah terenkripsi.
            if ($isEncrypted !== $decrypt) {
                $skipped++;
                continue;
            }

            try {
                $new = $decrypt
                    ? Crypt::decryptString(substr($raw, strlen(Setting::ENCRYPTED_PREFIX)))
                    : Setting::ENCRYPTED_PREFIX . Crypt::encryptString($raw);
            } catch (Throwable $e) {
                $failed++;
                $this->error("Gagal memproses \"{$row->key}\": " . $e->getMessage());
                continue;
            }

            if (! $dryRun) {
                DB::table('settings')->where('id', $row->id)->update([
                    'value' => $new,
                    'updated_at' => now(),
                ]);
            }

            $changed++;
            $arah = $decrypt ? 'didekripsi' : 'dienkripsi';
            $this->line(sprintf('  %-24s %s%s', $row->key, $arah, $dryRun ? ' (dry-run)' : ''));
        }

        $this->newLine();

        if ($failed > 0) {
            $this->error("Selesai dengan {$failed} kegagalan.");
        }

        $this->info(sprintf(
            '%s: %d diubah, %d dilewati, %d gagal.',
            $dryRun ? 'Dry-run' : 'Selesai',
            $changed,
            $skipped,
            $failed
        ));

        if ($dryRun && $changed > 0) {
            $this->comment('Jalankan tanpa --dry-run untuk menerapkan.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
