<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Location;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FetchMissingPhotos extends Command
{
    protected $signature = 'photos:fetch-missing
                            {--limit=0 : Batas jumlah foto (0 = semua)}
                            {--sleep=2 : Delay antar request (detik)}
                            {--dry-run : Hanya tampilkan yang akan di-fetch tanpa download}';

    protected $description = 'Fetch foto hilang dari Pexels API dan simpan ke storage';

    public function handle()
    {
        $apiKey = env('PEXELS_API_KEY');
        if (!$apiKey) {
            $this->error('PEXELS_API_KEY tidak ditemukan di .env');
            return 1;
        }

        $limit = (int) $this->option('limit');
        $sleep = (int) $this->option('sleep');
        $dryRun = $this->option('dry-run');

        // Cari lokasi yang punya photo di DB tapi file tidak ada
        $locations = Location::whereNotNull('photo')
            ->where('photo', '!=', '')
            ->get()
            ->filter(function ($loc) {
                $path = storage_path('app/public/' . $loc->photo);
                return !file_exists($path);
            });

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
                $this->line("  - {$loc->name} ({$loc->category?->name})");
            }
            return 0;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $success = 0;
        $failed = 0;
        $client = Http::withHeaders(['Authorization' => env('PEXELS_API_KEY')])
            ->timeout(15)
            ->baseUrl('https://api.pexels.com/v1');

        foreach ($locations as $loc) {
            $query = trim(($loc->category?->name ?? '') . ' ' . $loc->name . ' Indonesia');
            $query = preg_replace('/\s+/', ' ', $query);

            try {
                $resp = $client->get('/search', [
                    'query' => $query,
                    'per_page' => 1,
                    'orientation' => 'landscape',
                ]);

                if (!$resp->ok()) {
                    Log::warning("Pexels error for {$loc->name}: " . $resp->status());
                    $failed++;
                    $bar->advance();
                    sleep($sleep);
                    continue;
                }

                $data = $resp->json();
                if (empty($data['photos'])) {
                    $failed++;
                    $bar->advance();
                    sleep($sleep);
                    continue;
                }

                $photoUrl = $data['photos'][0]['src']['large2x'] ?? $data['photos'][0]['src']['large'] ?? null;
                if (!$photoUrl) {
                    $failed++;
                    $bar->advance();
                    sleep($sleep);
                    continue;
                }

                $imgResp = Http::timeout(20)->get($photoUrl);
                if (!$imgResp->ok()) {
                    $failed++;
                    $bar->advance();
                    sleep($sleep);
                    continue;
                }

                $ext = pathinfo(parse_url($photoUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?? 'jpg';
                $filename = "location_photos/{$loc->id}_" . md5($loc->name) . ".{$ext}";
                
                Storage::disk('public')->put($filename, $imgResp->body());
                
                $loc->update(['photo' => $filename]);
                $success++;
                $this->info("\n✓ {$loc->name} → {$filename}");

            } catch (\Exception $e) {
                Log::error("Fetch photo failed for {$loc->name}: " . $e->getMessage());
                $failed++;
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