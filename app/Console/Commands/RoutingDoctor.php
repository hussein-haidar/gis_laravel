<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\Routing\RoutingService;
use App\Services\Traffic\TomTomTrafficService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Diagnosa "¿API-nya sudah benar-benar kepakai?".
 *
 * Awalnya halaman settings bisa menampilkan semua kolom terisi, tetapi mesin
 * routing tetap membaca .env sehingga perubahan dari antarmuka tidak
 * berdampak. Command ini memanggil setiap engine sungguhan supaya masalah
 * konfigurasi ketahuan sebelum user menjelajah peta.
 */
class RoutingDoctor extends Command
{
    protected $signature = 'routing:doctor
                            {--json : Keluarkan hasil sebagai JSON}
                            {--skip-probe : Hanya periksa konfigurasi, tanpa memanggil API}';

    protected $description = 'Periksa mesin routing & credential: konfigurasi, sumber key, dan uji koneksi nyata';

    /** Titik uji: Jakarta -> Bandung, rute jalan yang normal. */
    private const PROBE_ORIGIN = [-6.1751, 106.8650];

    private const PROBE_DEST = [-6.9147, 107.6098];

    public function handle(): int
    {
        $engines = $this->inspect();
        $healthy = array_filter($engines, fn ($e) => $e['ready']);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'engines' => $engines,
                'ready' => count($healthy),
                'total' => count($engines),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $healthy === [] ? self::FAILURE : self::SUCCESS;
        }

        $this->newLine();
        $this->line('  Status mesin routing & credential');
        $this->line('  '.str_repeat('-', 74));

        foreach ($engines as $key => $e) {
            $icon = $e['ready'] ? '<fg=green>SIAP </>' : ($e['enabled'] ? '<fg=red>GAGAL</>' : '<fg=gray>MATI </>');
            $this->line(sprintf('  [%s] <options=bold>%s</>', $icon, $key));
            $this->line('        '.$e['reason']);

            foreach ($e['detail'] as $label => $value) {
                $this->line(sprintf('        <fg=gray>%-14s</> %s', $label, $value));
            }
        }

        $this->line('  '.str_repeat('-', 74));

        if ($healthy === []) {
            $this->newLine();
            $this->error('  Tidak ada mesin routing yang siap. Navigasi tidak akan berfungsi.');
            $this->line('  Nyalakan "OSRM Publik" di halaman Pengaturan, atau isi API key GraphHopper.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf('  %d dari %d mesin siap dipakai.', count($healthy), count($engines)));
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * @return array<string, array{enabled:bool, ready:bool, reason:string, detail:array<string,string>}>
     */
    private function inspect(): array
    {
        $routing = app(RoutingService::class);

        return [
            'osrm_local' => $this->inspectOsrmLocal($routing),
            'graphhopper' => $this->inspectGraphhopper($routing),
            'osrm_public' => $this->inspectOsrmPublic($routing),
            'tomtom' => $this->inspectTomtom(),
        ];
    }

    private function inspectOsrmLocal(RoutingService $routing): array
    {
        $enabled = (bool) Setting::getValue('osrm_local_enabled', false);
        $urls = [
            'car' => (string) Setting::getValue('osrm_car_url', ''),
            'bike' => (string) Setting::getValue('osrm_bike_url', ''),
            'walk' => (string) Setting::getValue('osrm_walk_url', ''),
        ];

        $detail = ['URL car' => $urls['car'] ?: '-', 'URL bike' => $urls['bike'] ?: '-', 'URL walk' => $urls['walk'] ?: '-'];

        if (! $enabled) {
            return $this->result(false, false, 'Nonaktif di Pengaturan.', $detail);
        }

        $probe = $this->probeOsrm($urls['car']);

        return $this->result(
            true,
            $probe['ok'],
            $this->verdict($probe, 'Server lokal menjawab.', 'Aktif tetapi server tidak menjawab: '),
            $detail + ['Uji' => $probe['message']],
        );
    }

    private function inspectOsrmPublic(RoutingService $routing): array
    {
        $enabled = (bool) Setting::getValue('osrm_public_enabled', true);
        $url = (string) Setting::getValue('osrm_public_url', '');
        $detail = ['URL' => $url ?: '-'];

        if (! $enabled) {
            return $this->result(false, false, 'Nonaktif di Pengaturan.', $detail);
        }

        $probe = $this->probeOsrm($url);

        return $this->result(
            true,
            $probe['ok'],
            $this->verdict($probe, 'OSRM publik menjawab (tidak butuh API key).', 'Tidak menjawab: '),
            $detail + ['Uji' => $probe['message']],
        );
    }

    private function inspectGraphhopper(RoutingService $routing): array
    {
        $enabled = (bool) Setting::getValue('graphhopper_enabled', false);
        $credential = Setting::credential('graphhopper_api_key');
        $url = (string) Setting::getValue('graphhopper_url', '');

        $detail = [
            'URL' => $url ?: '-',
            'Key dari' => $this->sourceLabel($credential['source']),
            'Panjang key' => $credential['value'] === '' ? '-' : strlen($credential['value']).' karakter',
            'Timeout' => (string) Setting::getValue('graphhopper_timeout', 30).' detik',
        ];

        if (! $enabled && $credential['value'] === '') {
            return $this->result(false, false, 'Nonaktif dan belum ada API key.', $detail);
        }

        if ($credential['value'] === '') {
            return $this->result(
                $enabled,
                false,
                'Aktif tetapi API key kosong, jadi mesin tidak akan dipakai.',
                $detail
            );
        }

        if (! $enabled) {
            return $this->result(false, false, 'API key tersedia, tapi belum dinyalakan di Pengaturan.', $detail);
        }

        $probe = $this->probeRoutingChain('graphhopper');

        return $this->result(
            true,
            $probe['ok'],
            $this->verdict($probe, 'GraphHopper menjawab.', 'Gagal: '),
            $detail + ['Uji' => $probe['message']]
        );
    }

    private function inspectTomtom(): array
    {
        $service = app(TomTomTrafficService::class);
        $credential = Setting::credential('tomtom_api_key');

        $detail = [
            'Key dari' => $this->sourceLabel($credential['source']),
            'Panjang key' => $credential['value'] === '' ? '-' : strlen($credential['value']).' karakter',
            'URL traffic' => (string) Setting::getValue('tomtom_traffic_url', '-'),
        ];

        if ($credential['value'] === '') {
            return $this->result(false, false, 'Belum ada API key (butuh untuk data kemacetan real-time).', $detail);
        }

        if ($this->option('skip-probe')) {
            return $this->result(true, true, 'API key tersedia. Koneksi belum diuji (--skip-probe).', $detail);
        }

        $probe = $this->probeRoutingChain('tomtom');

        return $this->result(
            $probe['ok'],
            $probe['ok'],
            $this->verdict($probe, 'TomTom menjawab.', 'Gagal: '),
            $detail + ['Uji' => $probe['message']]
        );
    }

    /** @return array{ok:bool, message:string, skipped:bool} */
    private function probeOsrm(string $baseUrl): array
    {
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'URL kosong.', 'skipped' => false];
        }

        if ($this->option('skip-probe')) {
            return ['ok' => true, 'message' => 'dilewati (--skip-probe)', 'skipped' => true];
        }

        $url = sprintf(
            '%s/route/v1/driving/%f,%f;%f,%f?overview=false',
            rtrim($baseUrl, '/'),
            self::PROBE_ORIGIN[1],
            self::PROBE_ORIGIN[0],
            self::PROBE_DEST[1],
            self::PROBE_DEST[0]
        );

        try {
            $response = Http::timeout(15)->withOptions(['verify' => false])->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->shortError($e->getMessage()), 'skipped' => false];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'HTTP '.$response->status(), 'skipped' => false];
        }

        $data = $response->json();

        if (($data['code'] ?? '') !== 'Ok') {
            return [
                'ok' => false,
                'message' => 'respons: '.substr((string) ($data['code'] ?? 'tidak dikenal'), 0, 40),
                'skipped' => false,
            ];
        }

        $km = round(($data['routes'][0]['distance'] ?? 0) / 1000, 1);

        return ['ok' => true, 'message' => "HTTP 200, {$km} km", 'skipped' => false];
    }

    /** @return array{ok:bool, message:string, skipped:bool} */
    private function probeRoutingChain(string $engine): array
    {
        if ($this->option('skip-probe')) {
            return ['ok' => true, 'message' => 'dilewati (--skip-probe)', 'skipped' => true];
        }

        try {
            $result = app(RoutingService::class)->route(
                self::PROBE_ORIGIN,
                self::PROBE_DEST,
                'mobil',
                ['engine' => $engine]
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $this->shortError($e->getMessage()), 'skipped' => false];
        }

        if (($result['status'] ?? '') === 'ok') {
            return [
                'ok' => true,
                'message' => round($result['distance_m'] / 1000, 1).' km, '.round($result['duration_s'] / 60).' mnt',
                'skipped' => false,
            ];
        }

        return ['ok' => false, 'message' => (string) ($result['message'] ?? 'tidak diketahui'), 'skipped' => false];
    }

    /**
     * Raison yang jujur: kalau probe dilewati, jangan mengklaim server menjawab.
     *
     * @param  array{ok:bool, message:string, skipped:bool}  $probe
     */
    private function verdict(array $probe, string $whenOk, string $whenFail): string
    {
        if ($probe['skipped']) {
            return 'Aktif. Belum diuji koneksi (--skip-probe), jadi belum ada jaminan server menjawab.';
        }

        return $probe['ok'] ? $whenOk : $whenFail.$probe['message'];
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'database' => 'database (Pengaturan)',
            'env' => '.env',
            default => 'belum diisi',
        };
    }

    /**
     * Guzzle menempelkan URL lengkap ke pesan error, jadi baris "Uji" dan
     * "URL" jadi sama-sama panjang dan membingungkan. Yang penting bagian
     * penyebab saja (kode cURL + alasan + host:port).
     */
    private function shortError(string $message): string
    {
        $message = preg_replace('/ for https?:\/\/\S+$/', '', $message) ?? $message;

        return trim(preg_replace('/\s*\(see https:\/\/curl\.se\S*\)/', '', $message) ?? $message);
    }

    /**
     * @param  array<string,string>  $detail
     * @return array{enabled:bool, ready:bool, reason:string, detail:array<string,string>}
     */
    private function result(bool $enabled, bool $ready, string $reason, array $detail): array
    {
        return compact('enabled', 'ready', 'reason', 'detail');
    }
}
