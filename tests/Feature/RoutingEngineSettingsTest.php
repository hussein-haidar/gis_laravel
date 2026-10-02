<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Routing\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Rekaman regresi untuk bug "API-nya kosong": halaman Pengaturan menampilkan
 * URL/key yang tersimpan, tetapi mesin routing tetap membaca .env sehingga
 * semua yang diisi admin tidak berdampak sama sekali.
 *
 * Setiap test di sini MEMASTIKAN request sungguhan memakai nilai dari
 * database, bukan dari config.
 */
class RoutingEngineSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = [-6.1751, 106.8650];

    private const DEST = [-6.9147, 107.6098];

    protected function setUp(): void
    {
        parent::setUp();

        // Hilangkan sisa .env developer supaya test benar-benar isolation.
        config()->set('routing.engines.graphhopper.api_key', null);
        config()->set('services.tomtom.key', null);
        config()->set('gis.api_key', null);
    }

    /** @return array<string,string> URL yang benar-benar dipanggil. */
    private function requestedUrls(): array
    {
        $urls = [];

        Http::assertSent(function ($request) use (&$urls) {
            $urls[] = (string) $request->url();

            return true;
        });

        return $urls;
    }

    private function fakeOsrmOk(): void
    {
        Http::fake([
            '*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 147900,
                    'duration' => 8100,
                    'geometry' => ['type' => 'LineString', 'coordinates' => [[106.8, -6.2], [107.6, -6.9]]],
                    'legs' => [['steps' => []]],
                ]],
            ]),
        ]);
    }

    private function fakeGraphhopperOk(): void
    {
        Http::fake([
            '*' => Http::response([
                'paths' => [[
                    'distance' => 147900,
                    'time' => 8100000,
                    'points' => ['type' => 'LineString', 'coordinates' => [[106.8, -6.2], [107.6, -6.9]]],
                    'instructions' => [],
                    'details' => [],
                ]],
            ]),
        ]);
    }

    // ── URL dari database harus dipakai ─────────────────────────────────────

    public function test_graphhopper_uses_the_url_and_key_saved_in_the_database(): void
    {
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'key-dari-database']);
        Setting::where('key', 'graphhopper_url')->update(['value' => 'https://graphhopper.contoh.test/api/1']);

        $this->fakeGraphhopperOk();

        $result = app(RoutingService::class)->route(
            self::ORIGIN, self::DEST, 'mobil', ['engine' => 'graphhopper']
        );

        $this->assertSame('ok', $result['status'], $result['message']);

        $urls = $this->requestedUrls();
        $this->assertCount(1, $urls);
        $this->assertStringStartsWith('https://graphhopper.contoh.test/api/1/route?', $urls[0]);
        $this->assertStringContainsString('key=key-dari-database', $urls[0]);
    }

    public function test_graphhopper_url_from_config_is_only_a_fallback(): void
    {
        config()->set('routing.engines.graphhopper.url', 'https://dari-config.test/api/1');

        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'k']);
        Setting::where('key', 'graphhopper_url')->update(['value' => 'https://dari-database.test/api/1']);

        $this->fakeGraphhopperOk();

        app(RoutingService::class)->route(self::ORIGIN, self::DEST, 'mobil', ['engine' => 'graphhopper']);

        $this->assertStringStartsWith(
            'https://dari-database.test/api/1/route?',
            $this->requestedUrls()[0]
        );
    }

    public function test_osrm_public_uses_the_url_saved_in_the_database(): void
    {
        config()->set('routing.engines.osrm_public.url', 'https://dari-config.test');

        Setting::where('key', 'osrm_public_enabled')->update(['value' => '1']);
        Setting::where('key', 'osrm_public_url')->update(['value' => 'https://dari-database.test']);

        $this->fakeOsrmOk();

        $result = app(RoutingService::class)->route(
            self::ORIGIN, self::DEST, 'mobil', ['engine' => 'osrm_public']
        );

        $this->assertSame('ok', $result['status'], $result['message']);
        $this->assertStringStartsWith('https://dari-database.test/route/v1/', $this->requestedUrls()[0]);
    }

    public function test_local_osrm_uses_the_car_url_saved_in_the_database(): void
    {
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '1']);
        Setting::where('key', 'osrm_car_url')->update(['value' => 'http://127.0.0.1:5000']);
        Setting::where('key', 'osrm_bike_url')->update(['value' => 'http://127.0.0.1:5001']);

        $this->fakeOsrmOk();

        app(RoutingService::class)->route(self::ORIGIN, self::DEST, 'mobil', ['engine' => 'osrm_local']);

        $this->assertStringStartsWith('http://127.0.0.1:5000/route/v1/driving/', $this->requestedUrls()[0]);
    }

    /**
     * engineSupportsVehicle() hanya mengizinkan mobil & motor untuk osrm_local,
     * jadi URL bike/walk lokal tidak pernah dipakai meski tersimpan. Test ini
     * mengunci perilaku tersebut supaya tidak berubah diam-diam; kalau nanti
     * server bike lokal ingin dipakai, tes ini yang harus diperbarui.
     */
    public function test_bicycle_skips_the_local_engine_even_though_a_bike_url_is_configured(): void
    {
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '1']);
        Setting::where('key', 'osrm_car_url')->update(['value' => 'http://127.0.0.1:5000']);
        Setting::where('key', 'osrm_bike_url')->update(['value' => 'http://127.0.0.1:5001']);

        Http::fake();

        $result = app(RoutingService::class)->route(
            self::ORIGIN, self::DEST, 'sepeda', ['engine' => 'osrm_local']
        );

        $this->assertSame('error', $result['status']);
        Http::assertNothingSent();
    }

    public function test_local_osrm_url_from_database_overrides_the_config_port(): void
    {
        config()->set('routing.osrm_servers.driving', 'http://127.0.0.1:9999');

        Setting::where('key', 'osrm_local_enabled')->update(['value' => '1']);
        Setting::where('key', 'osrm_car_url')->update(['value' => 'http://10.0.0.5:5000']);

        $this->fakeOsrmOk();

        app(RoutingService::class)->route(self::ORIGIN, self::DEST, 'mobil', ['engine' => 'osrm_local']);

        $this->assertStringStartsWith('http://10.0.0.5:5000/route/v1/driving/', $this->requestedUrls()[0]);
    }

    /**
     * engineSetting() adalah inti perbaikan ini: nilai database harus menang,
     * string kosong harus jatuh ke fallback.
     */
    public function test_engine_setting_resolution_order(): void
    {
        $routing = app(RoutingService::class);
        $resolve = new \ReflectionMethod($routing, 'engineSetting');
        $resolve->setAccessible(true);

        // Database menang atas fallback.
        Setting::where('key', 'osrm_public_timeout')->update(['value' => '42']);
        $this->assertSame('42', (string) $resolve->invoke($routing, 'osrm_public_timeout', 15));

        // Kosong di database -> pakai fallback config. Setting integer yang
        // dikosongkan tidak boleh berubah jadi 0 (timeout 0 = tanpa batas).
        Setting::where('key', 'osrm_public_timeout')->update(['value' => '']);
        $this->assertSame(15, $resolve->invoke($routing, 'osrm_public_timeout', 15));

        // Baris tidak ada -> fallback.
        Setting::where('key', 'osrm_public_timeout')->delete();
        $this->assertSame(15, $resolve->invoke($routing, 'osrm_public_timeout', 15));

        // Nilai 0 yang disengaja untuk boolean tetap dihormati.
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '0', 'type' => 'boolean']);
        $this->assertFalse($resolve->invoke($routing, 'graphhopper_enabled', true));
    }

    public function test_engine_url_trims_trailing_slash(): void
    {
        Setting::where('key', 'osrm_public_url')->update(['value' => 'https://contoh.test/']);

        $routing = app(RoutingService::class);
        $url = new \ReflectionMethod($routing, 'engineUrl');
        $url->setAccessible(true);

        $this->assertSame(
            'https://contoh.test',
            $url->invoke($routing, 'osrm_public_url', 'routing.engines.osrm_public.url')
        );
    }

    // ── Credential: database dulu, lalu .env ───────────────────────────────

    public function test_credential_prefers_the_database_over_env(): void
    {
        config()->set('routing.engines.graphhopper.api_key', 'key-dari-env');

        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'key-dari-db']);

        $this->assertSame(['value' => 'key-dari-db', 'source' => 'database'], Setting::credential('graphhopper_api_key'));
    }

    public function test_credential_falls_back_to_env_when_database_is_empty(): void
    {
        config()->set('services.tomtom.key', 'tomtom-dari-env');

        // Baris secret kosong = "belum diisi", bukan "sengaja dikosongkan".
        $this->assertSame(['value' => 'tomtom-dari-env', 'source' => 'env'], Setting::credential('tomtom_api_key'));
    }

    public function test_credential_reports_none_when_nothing_is_configured(): void
    {
        $this->assertSame(['value' => '', 'source' => 'none'], Setting::credential('gis_api_key'));
    }

    public function test_env_only_key_still_enables_the_engine(): void
    {
        config()->set('routing.engines.graphhopper.api_key', 'key-dari-env');

        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);

        $this->fakeGraphhopperOk();

        $result = app(RoutingService::class)->route(
            self::ORIGIN, self::DEST, 'mobil', ['engine' => 'graphhopper']
        );

        $this->assertSame('ok', $result['status'], $result['message']);
        $this->assertStringContainsString('key=key-dari-env', $this->requestedUrls()[0]);
    }

    public function test_engine_is_skipped_when_graphhopper_is_off_even_with_a_key(): void
    {
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'k']);
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '0']);
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '0']);
        Setting::where('key', 'osrm_public_enabled')->update(['value' => '1']);

        $this->fakeOsrmOk();

        $result = app(RoutingService::class)->route(self::ORIGIN, self::DEST, 'mobil');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('osrm_public', $result['engine']);

        $urls = $this->requestedUrls();
        $this->assertCount(1, $urls, 'GraphHopper yang nonaktif tetap boleh dipanggil.');
        $this->assertStringNotContainsString('graphhopper', $urls[0]);
    }

    public function test_chain_falls_through_to_the_next_engine_when_one_fails(): void
    {
        Setting::where('key', 'osrm_local_enabled')->update(['value' => '0']);
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'k']);
        Setting::where('key', 'osrm_public_enabled')->update(['value' => '1']);

        // GraphHopper 500, OSRM publik sehat.
        Http::fake([
            'graphhopper.com/*' => Http::response(['message' => 'boom'], 500),
            '*' => Http::response([
                'code' => 'Ok',
                'routes' => [[
                    'distance' => 147900,
                    'duration' => 8100,
                    'geometry' => ['type' => 'LineString', 'coordinates' => []],
                    'legs' => [['steps' => []]],
                ]],
            ]),
        ]);

        $result = app(RoutingService::class)->route(self::ORIGIN, self::DEST, 'mobil');

        $this->assertSame('ok', $result['status']);
        $this->assertSame('osrm_public', $result['engine']);
    }

    // ── Panel status ───────────────────────────────────────────────────────

    public function test_status_marks_osrm_public_ready_on_a_fresh_install(): void
    {
        $status = app(RoutingService::class)->status();

        $this->assertSame('ready', $status['osrm_public']['state']);
        $this->assertSame('off', $status['graphhopper']['state']);
        $this->assertSame('off', $status['osrm_local']['state']);
        $this->assertSame('needs_key', $status['tomtom']['state']);
    }

    public function test_status_reports_needs_key_when_switch_is_on_but_key_is_missing(): void
    {
        Setting::where('key', 'graphhopper_enabled')->update(['value' => '1']);

        $status = app(RoutingService::class)->status();

        $this->assertSame('needs_key', $status['graphhopper']['state']);
        $this->assertStringContainsString('API key belum diisi', $status['graphhopper']['note']);
    }

    public function test_status_reports_off_when_a_key_exists_but_the_switch_is_off(): void
    {
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'k']);

        $status = app(RoutingService::class)->status();

        $this->assertSame('off', $status['graphhopper']['state']);
        $this->assertStringContainsString('dari Pengaturan', $status['graphhopper']['note']);
    }

    public function test_status_never_leaks_the_secret_value(): void
    {
        Setting::where('key', 'graphhopper_api_key')->update(['value' => 'rahasia-super-panjang']);
        Setting::where('key', 'tomtom_api_key')->update(['value' => 'rahasia-tomtom']);

        $encoded = json_encode(app(RoutingService::class)->status());

        $this->assertStringNotContainsString('rahasia-super-panjang', $encoded);
        $this->assertStringNotContainsString('rahasia-tomtom', $encoded);
    }
}
