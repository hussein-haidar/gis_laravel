<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RoutingDoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('routing.engines.graphhopper.api_key', null);
        config()->set('services.tomtom.key', null);
    }

    /**
     * Jalankan command dan ambil output-nya. PendingCommand dari $this->artisan()
     * tidak menyisakan output, jadi dipakai Artisan::call() + Artisan::output().
     */
    private function doctor(array $parameters = []): array
    {
        $exitCode = Artisan::call('routing:doctor', $parameters);

        return ['exit' => $exitCode, 'output' => Artisan::output()];
    }

    private function fakeOsrmOk(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 'Ok',
            'routes' => [['distance' => 147900, 'duration' => 8100, 'geometry' => [], 'legs' => [['steps' => []]]]],
        ])]);
    }

    public function test_it_reports_success_when_a_keyless_engine_works(): void
    {
        $this->fakeOsrmOk();

        $this->assertSame(0, $this->doctor()['exit']);
    }

    public function test_it_fails_when_every_engine_is_disabled(): void
    {
        Setting::where('key', 'osrm_public_enabled')->update(['value' => '0']);
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '0']);

        Http::fake();

        $result = $this->doctor(['--skip-probe' => true]);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Tidak ada mesin routing yang siap', $result['output']);
    }

    public function test_json_output_never_contains_the_secret_value(): void
    {
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'rahasia-graphhopper']);
        Setting::where('key', 'tomtom_api_key')->update(['value' => 'rahasia-tomtom']);
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);

        $this->fakeOsrmOk();

        $result = $this->doctor(['--skip-probe' => true, '--json' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('rahasia-graphhopper', $result['output']);
        $this->assertStringNotContainsString('rahasia-tomtom', $result['output']);

        // Asal-usul key tetap dilaporkan supaya admin paham.
        $this->assertStringContainsString('database', $result['output']);
    }

    public function test_skip_probe_does_not_claim_the_server_answered(): void
    {
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '1']);

        Http::fake();

        $result = $this->doctor(['--skip-probe' => true]);

        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('Server lokal menjawab', $result['output']);
        $this->assertStringContainsString('Belum diuji koneksi', $result['output']);
    }

    public function test_it_reports_the_source_of_each_credential(): void
    {
        Setting::where('key', 'tomtom_api_key')->update(['value' => 'k']);
        config()->set('services.tomtom.key', null);

        $this->fakeOsrmOk();

        $result = $this->doctor(['--skip-probe' => true]);

        $this->assertStringContainsString('database (Pengaturan)', $result['output']);
    }

    public function test_it_falls_back_to_env_label_when_the_key_comes_from_env(): void
    {
        Setting::where('key', 'tomtom_api_key')->update(['value' => '']);
        config()->set('services.tomtom.key', 'k-dari-env');

        $this->fakeOsrmOk();

        $result = $this->doctor(['--skip-probe' => true]);

        $this->assertStringContainsString('.env', $result['output']);
    }

    public function test_it_reports_unreachable_local_server_but_still_succeeds_overall(): void
    {
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '1']);

        // Port salah -> hanya engine lokal yang gagal; OSRM publik tetap sehat.
        Setting::where('key', 'osrm_car_url')->update(['value' => 'http://127.0.0.1:59999']);

        Http::fake([
            '127.0.0.1*' => Http::response([], 500),
            '*' => Http::response([
                'code' => 'Ok',
                'routes' => [['distance' => 147900, 'duration' => 8100, 'geometry' => [], 'legs' => [['steps' => []]]]],
            ]),
        ]);

        $result = $this->doctor();

        $this->assertSame(0, $result['exit'], 'OSRM publik masih hidup, jadi exit code harus sukses.');
        $this->assertStringContainsString('osrm_local', $result['output']);
        $this->assertStringContainsString('GAGAL', $result['output']);
        $this->assertStringContainsString('SIAP', $result['output']);
    }

    public function test_it_flags_an_enabled_engine_that_has_no_api_key(): void
    {
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);

        $this->fakeOsrmOk();

        $result = $this->doctor(['--skip-probe' => true]);

        $this->assertStringContainsString('Aktif tetapi API key kosong', $result['output']);
    }
}
