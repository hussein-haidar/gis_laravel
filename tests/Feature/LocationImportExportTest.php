<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LocationImportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        Storage::fake('public');
    }

    public function test_csv_export_includes_geometry_and_photo_columns(): void
    {
        $location = Location::create([
            'name' => 'Gedung Sate',
            'description' => 'Ikon Bandung',
            'latitude' => -6.9025,
            'longitude' => 107.6187,
            'photo' => 'photos/gedung-sate.jpg',
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[[107.61, -6.90], [107.62, -6.90], [107.62, -6.91], [107.61, -6.91], [107.61, -6.90]]],
            ],
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.locations.export', ['format' => 'csv']));

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('name,description,latitude,longitude,category,geometry,photo', $content);
        $this->assertStringContainsString('Polygon', $content);
        $this->assertStringContainsString('photos/gedung-sate.jpg', $content);
        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_xlsx_export_downloads_valid_file(): void
    {
        Location::create([
            'name' => 'Monas',
            'latitude' => -6.175,
            'longitude' => 106.827,
        ]);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.locations.export', ['format' => 'xlsx']));

        $response->assertOk();
        $this->assertSame('PK', substr($response->streamedContent(), 0, 2));
    }

    public function test_import_csv_round_trips_geometry_and_storage_photo(): void
    {
        Storage::disk('public')->put('photos/existing.jpg', 'fake-image-bytes');

        $csv = implode("\n", [
            'name,description,latitude,longitude,category,geometry,photo',
            'Alun-Alun Kota,Taman kota,-6.2138,106.8155,Tempat Umum,"{""type"":""Polygon"",""coordinates"":[[[106.8270,-6.1751],[106.8280,-6.1751],[106.8280,-6.1760],[106.8270,-6.1751]]]}",photos/existing.jpg',
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.locations.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('lokasi.csv', $csv),
        ]);

        $response->assertRedirect(route('admin.locations.index'))->assertSessionHas('success');

        $location = Location::where('name', 'Alun-Alun Kota')->first();

        $this->assertNotNull($location);
        $this->assertSame('Polygon', $location->geometry['type']);
        $this->assertCount(1, $location->geometry['coordinates']);
        $this->assertSame('photos/existing.jpg', $location->photo);
        $this->assertTrue(Storage::disk('public')->exists('photos/existing.jpg'));
    }

    public function test_import_derives_point_coordinates_from_geometry(): void
    {
        $csv = implode("\n", [
            'name,geometry',
            'Titik Nol,"{""type"":""Point"",""coordinates"":[110.4203,-7.7956]}"',
        ]);

        $this->actingAs($this->admin)->post(route('admin.locations.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('titik.csv', $csv),
        ])->assertRedirect();

        $location = Location::where('name', 'Titik Nol')->first();

        $this->assertNotNull($location);
        $this->assertEqualsWithDelta(-7.7956, (float) $location->latitude, 0.0001);
        $this->assertEqualsWithDelta(110.4203, (float) $location->longitude, 0.0001);
    }

    public function test_import_xlsx_file(): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['name', 'description', 'latitude', 'longitude', 'category', 'geometry', 'photo'],
            ['Monas dari Excel', 'Dari file xlsx', -6.1753924, 106.8271528, 'Sejarah', '', ''],
        ], null, 'A1');

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($tmp);

        $response = $this->actingAs($this->admin)->post(route('admin.locations.import.store'), [
            'file' => UploadedFile::fake()->createWithContent('lokasi.xlsx', file_get_contents($tmp)),
        ]);

        unlink($tmp);

        $response->assertRedirect(route('admin.locations.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('locations', [
            'name' => 'Monas dari Excel',
            'description' => 'Dari file xlsx',
        ]);
    }
}
