<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Routing\RoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Menjamin `php artisan migrate` di database kosong menghasilkan aplikasi yang
 * berfungsi: seluruh konfigurasi default harus ada, secret harus kosong, dan
 * migration tidak boleh menimpa nilai yang sudah diisi admin.
 */
class DefaultSettingsMigrationTest extends TestCase
{
    use RefreshDatabase;

    /** Key yang dibaca aplikasi di seluruh service. */
    private const EXPECTED_KEYS = [
        // gis
        'gis_api_url', 'gis_api_key', 'gis_api_timeout',
        // routing
        'graphhopper_enabled', 'graphhopper_api_key', 'graphhopper_url', 'graphhopper_timeout',
        'graphhopper_traffic_speed',
        'osrm_local_enabled', 'osrm_local_url', 'osrm_local_timeout', 'osrm_car_url',
        'osrm_bike_url', 'osrm_walk_url',
        'osrm_public_enabled', 'osrm_public_url', 'osrm_public_timeout',
        // traffic
        'tomtom_api_key', 'tomtom_traffic_url', 'tomtom_timeout',
        // ai
        'groq_api_key', 'groq_base_url', 'groq_model',
    ];

    /** Muat objek migration anonymous dari file-nya. */
    private function migration(): object
    {
        $path = database_path('migrations/2026_10_01_000001_seed_default_settings.php');

        $this->assertFileExists($path);

        return require $path;
    }

    private function raw(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    public function test_fresh_migrate_creates_every_setting_the_app_reads(): void
    {
        $missing = array_values(array_filter(
            self::EXPECTED_KEYS,
            fn (string $key) => ! DB::table('settings')->where('key', $key)->exists()
        ));

        $this->assertSame([], $missing, 'Key hilang setelah migrate: '.implode(', ', $missing));

        foreach (self::EXPECTED_KEYS as $key) {
            $this->assertNotSame(
                '__MISSING__',
                Setting::getValue($key, '__MISSING__'),
                "Setting::getValue('{$key}') mengembalikan default, berarti baris tidak ada"
            );
        }
    }

    public function test_default_values_make_the_core_services_work(): void
    {
        $this->assertStringContainsString('geojson', (string) Setting::getValue('gis_api_url'));
        $this->assertSame(30, Setting::getValue('gis_api_timeout'));

        // Mesin yang butuh API key / server lokal default-nya MATI, supaya tidak
        // menampilkan toggle aktif yang Diam-diam tidak pernah terpakai.
        $this->assertFalse(Setting::getValue('graphhopper_enabled'));
        $this->assertFalse(Setting::getValue('osrm_local_enabled'));

        // OSRM publik adalah satu-satunya mesin bebas key, jadi harus nyala.
        $this->assertTrue(Setting::getValue('osrm_public_enabled'));
        $this->assertStringContainsString('project-osrm', (string) Setting::getValue('osrm_public_url'));

        $this->assertStringContainsString('graphhopper.com', (string) Setting::getValue('graphhopper_url'));
        $this->assertStringContainsString('tomtom.com', (string) Setting::getValue('tomtom_traffic_url'));
        $this->assertSame(60, Setting::getValue('tomtom_timeout'));

        $this->assertStringContainsString('groq.com', (string) Setting::getValue('groq_base_url'));
        $this->assertNotSame('', (string) Setting::getValue('groq_model'));
    }

    /**
     * Instalasi baru harus punya minimal satu mesin routing yang benar-benar
     * siap tanpa perlu administering API key lebih dulu.
     */
    public function test_a_fresh_install_has_one_ready_engine_without_any_key(): void
    {
        $ready = collect(app(RoutingService::class)->status())
            ->filter(fn ($s) => $s['state'] === 'ready');

        $this->assertNotEmpty(
            $ready,
            'Instalasi baru tidak punya mesin routing siap. Rute akan gagal total.'
        );

        // Ekor chain harus selalu punya mesin bebas key sebagai jaring pengaman.
        $chain = config('routing.chain');
        $this->assertContains('osrm_public', $chain);
        $this->assertSame('osrm_public', end($chain), 'Mesin bebas key harus berada di ujung chain.');
    }

    public function test_secret_defaults_are_always_empty(): void
    {
        $filled = DB::table('settings')->where('is_secret', 1)->where('value', '!=', '')->pluck('key')->all();

        $this->assertSame([], $filled, 'Secret default tidak boleh terisi: '.implode(', ', $filled));

        // Kunci asli juga tidak boleh ikut tersimpan di repo sebagai nilai default.
        $migrationSource = file_get_contents(
            database_path('migrations/2026_10_01_000001_seed_default_settings.php')
        );
        $this->assertStringNotContainsString('gsk_', $migrationSource);
    }

    public function test_types_are_cast_correctly(): void
    {
        $this->assertIsBool(Setting::getValue('osrm_local_enabled'));
        $this->assertIsInt(Setting::getValue('tomtom_timeout'));
        $this->assertIsString(Setting::getValue('osrm_public_url'));
    }

    public function test_every_setting_has_label_and_group(): void
    {
        $incomplete = DB::table('settings')
            ->where(fn ($q) => $q->whereNull('label')->orWhere('label', '')->orWhereNull('group')->orWhere('group', ''))
            ->pluck('key')
            ->all();

        $this->assertSame([], $incomplete, 'Label/group kosong: '.implode(', ', $incomplete));
    }

    // ── Sifat migration ──────────────────────────────────────────────────────

    public function test_running_the_migration_again_does_not_change_anything(): void
    {
        $before = DB::table('settings')->orderBy('key')->get()
            ->mapWithKeys(fn ($r) => [$r->key => $r->value])->all();

        $this->migration()->up();

        $after = DB::table('settings')->orderBy('key')->get()
            ->mapWithKeys(fn ($r) => [$r->key => $r->value])->all();

        $this->assertSame($before, $after);
    }

    public function test_migration_never_overwrites_an_existing_value(): void
    {
        Setting::where('key', 'gis_api_url')->update(['value' => 'https://example.test/custom.geojson']);
        Setting::where('key', 'osrm_public_timeout')->update(['value' => '99']);

        $this->migration()->up();

        $this->assertSame('https://example.test/custom.geojson', $this->raw('gis_api_url'));
        $this->assertSame('99', $this->raw('osrm_public_timeout'));
    }

    public function test_migration_fills_rows_that_were_deleted(): void
    {
        DB::table('settings')->whereIn('key', ['tomtom_timeout', 'groq_base_url'])->delete();

        $this->assertNull($this->raw('tomtom_timeout'));

        $this->migration()->up();

        $this->assertSame('60', $this->raw('tomtom_timeout'));
        $this->assertSame('https://api.groq.com/openai/v1', $this->raw('groq_base_url'));
    }

    public function test_migration_does_not_touch_an_encrypted_secret(): void
    {
        Setting::where('key', 'tomtom_api_key')->update(['value' => Setting::ENCRYPTED_PREFIX.'xyz']);

        $this->migration()->up();

        $this->assertSame(Setting::ENCRYPTED_PREFIX.'xyz', $this->raw('tomtom_api_key'));
    }

    public function test_rollback_only_removes_untouched_defaults(): void
    {
        // Nilai yang sudah diubah admin harus selamat rollback.
        Setting::where('key', 'gis_api_timeout')->update(['value' => '77']);
        Setting::where('key', 'tomtom_api_key')->update(['value' => Setting::ENCRYPTED_PREFIX.'rahasia']);

        $this->migration()->down();

        // Berubah -> tidak dihapus.
        $this->assertSame('77', $this->raw('gis_api_timeout'));
        $this->assertSame(Setting::ENCRYPTED_PREFIX.'rahasia', $this->raw('tomtom_api_key'));

        // Masih default -> dihapus.
        $this->assertNull($this->raw('osrm_public_url'));
        $this->assertNull($this->raw('groq_model'));
    }

    public function test_rollback_then_migrate_restores_a_working_config(): void
    {
        $this->migration()->down();
        $this->migration()->up();

        foreach (self::EXPECTED_KEYS as $key) {
            $this->assertTrue(
                DB::table('settings')->where('key', $key)->exists(),
                "Key '{$key}' tidak kembali setelah down() lalu up()"
            );
        }
    }
}
