<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Services\Gis\WilayahResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ResolveLocationsWilayahTest extends TestCase
{
    use RefreshDatabase;

    private Category $provinsi;

    private Category $tempat;

    protected function setUp(): void
    {
        parent::setUp();

        // Pada data GIS asli, kategori sebuah baris kabupaten adalah NAMA
        // provinsi induknya (mis. "JEPARA" -> kategori "JAWA TENGAH"), dan baris
        // provinsi sendiri punya nama == kategori sehingga ditandai "provinsi utuh".
        $this->provinsi = Category::create(['name' => 'JAWA TENGAH']);
        $this->tempat = Category::create(['name' => 'Wisata Alam']);

        // Poligon persegi di sekitar Karimunjawa (0.1 derajat).
        $this->wilayah('JEPARA', -5.90, 110.40, 0.10);
    }

    private function wilayah(string $name, float $lat, float $lng, float $span = 1.0): Location
    {
        return Location::create([
            'name' => $name,
            'category_id' => $this->provinsi->id,
            'latitude' => $lat,
            'longitude' => $lng,
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$lng - $span, $lat - $span],
                    [$lng + $span, $lat - $span],
                    [$lng + $span, $lat + $span],
                    [$lng - $span, $lat + $span],
                    [$lng - $span, $lat - $span],
                ]],
            ],
        ]);
    }

    private function place(string $name, float $lat, float $lng): Location
    {
        return Location::create([
            'name' => $name,
            'category_id' => $this->tempat->id,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    public function test_resolve_returns_region_when_point_is_inside_polygon(): void
    {
        $region = app(WilayahResolver::class)->resolve(-5.90, 110.40);

        $this->assertNotNull($region);
        $this->assertSame('JEPARA', $region['name']);
        $this->assertSame('JAWA TENGAH', $region['provinsi']);
    }

    public function test_proximity_resolves_offshore_point_outside_every_polygon(): void
    {
        // 0.2 derajat di utara poligon => sekitar 22 km, di luar semua poligon.
        $resolver = app(WilayahResolver::class);

        $this->assertNull($resolver->resolve(-5.70, 110.40), 'Titik jauh dari poligon tidak boleh resolve via poligon.');

        $near = $resolver->resolveByProximity(-5.70, 110.40, 30.0);

        $this->assertNotNull($near);
        $this->assertSame('JEPARA', $near['name']);
        $this->assertGreaterThan(0, $near['jarak_km']);
        $this->assertLessThanOrEqual(30.0, $near['jarak_km']);
    }

    public function test_proximity_respects_max_km_threshold(): void
    {
        $this->assertNull(app(WilayahResolver::class)->resolveByProximity(-5.70, 110.40, 5.0));
    }

    public function test_proximity_never_returns_a_province_wide_polygon(): void
    {
        $this->wilayah('JAWA TENGAH', -5.70, 110.40, 0.01);

        $near = app(WilayahResolver::class)->resolveByProximity(-5.70, 110.40, 30.0);

        $this->assertNotNull($near);
        $this->assertSame('JEPARA', $near['name']);
    }

    public function test_command_fills_wilayah_id_for_offshore_place(): void
    {
        $place = $this->place('Taman Nasional Karimunjawa', -5.70, 110.40);
        $wilayah = Location::where('name', 'JEPARA')->first();

        $this->artisan('locations:resolve-wilayah')->assertSuccessful();

        $this->assertSame($wilayah->id, $place->fresh()->wilayah_id);
    }

    public function test_command_does_not_overwrite_existing_wilayah_by_default(): void
    {
        // Nilai lama yang sah secara FK tapi sengaja tidak sesuai titik.
        $demak = $this->wilayah('DEMAK', -6.90, 110.40, 0.10);

        $place = $this->place('Tanah Lot', -5.70, 110.40);
        $place->wilayah_id = $demak->id;
        $place->save();

        $this->artisan('locations:resolve-wilayah')->assertSuccessful();

        $this->assertSame(
            $demak->id,
            $place->fresh()->wilayah_id,
            'Nilai wilayah yang sudah ada tidak boleh ditimpa tanpa --recheck.'
        );
    }

    public function test_recheck_option_re_resolves_existing_wilayah(): void
    {
        $kabupaten = Location::where('name', 'JEPARA')->first();
        $demak = $this->wilayah('DEMAK', -6.90, 110.40, 0.10);

        $place = $this->place('Tanah Lot', -5.70, 110.40);
        $place->wilayah_id = $demak->id;
        $place->save();

        $this->artisan('locations:resolve-wilayah', ['--recheck' => true])->assertSuccessful();

        $this->assertSame($kabupaten->id, $place->fresh()->wilayah_id);
    }

    public function test_dry_run_reports_the_plan_without_writing(): void
    {
        $place = $this->place('Taman Nasional Karimunjawa', -5.70, 110.40);

        $exitCode = Artisan::call('locations:resolve-wilayah', ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Taman Nasional Karimunjawa', $output);
        $this->assertStringContainsString('JEPARA', $output);
        $this->assertStringContainsString('dry-run', $output);
        $this->assertNull($place->fresh()->wilayah_id, 'Dry-run tidak boleh menulis ke database.');
    }
}
