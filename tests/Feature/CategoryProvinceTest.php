<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kategori provinsi dibuat otomatis oleh sinkronisasi GIS dan tidak boleh
 * muncul bercampur dengan kategori jenis tempat di halaman "Kelola Kategori".
 */
class CategoryProvinceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $provinsi;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        Category::create(['name' => 'Wisata Alam', 'color' => '#22c55e']);
        Category::create(['name' => 'Wisata Kuliner', 'color' => '#f97316']);
        $this->provinsi = Category::create(['name' => 'JAWA TENGAH', 'color' => '#3b82f6']);
        Category::create(['name' => 'DKI JAKARTA', 'color' => '#3b82f6']);
    }

    public function test_kategori_tempat_tidak_menampilkan_nama_provinsi(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.categories.index'));

        $response->assertOk();
        $response->assertSee('Wisata Alam');
        $response->assertSee('Wisata Kuliner');
        $response->assertDontSee('JAWA TENGAH');
        $response->assertDontSee('DKI JAKARTA');
    }

    public function test_halaman_provinsi_menampilkan_kategori_wilayah_terpisah(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.categories.provinsi'));

        $response->assertOk();
        $response->assertSee('JAWA TENGAH');
        $response->assertSee('DKI JAKARTA');
        $response->assertDontSee('Wisata Kuliner');
    }

    public function test_pencarian_berpisah_untuk_tempat_dan_provinsi(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.categories.index', ['search' => 'Jawa']))
            ->assertOk()
            ->assertDontSee('JAWA TENGAH');

        $this->actingAs($this->admin)
            ->get(route('admin.categories.provinsi', ['search' => 'Jawa']))
            ->assertOk()
            ->assertSee('JAWA TENGAH')
            ->assertDontSee('Wisata Kuliner');
    }

    public function test_provinsi_tidak_ikut_sebagai_kategori_induk(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.categories.create'));

        $response->assertOk();
        $response->assertSee('Wisata Alam');
        $response->assertDontSee('JAWA TENGAH');
    }

    public function test_form_lokasi_tidak_menawarkan_kategori_provinsi(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.locations.create'));

        $response->assertOk();
        $response->assertSee('Wisata Alam');
        $response->assertDontSee('JAWA TENGAH');
    }

    public function test_edit_lokasi_wilayah_menampilkan_kategori_provinsinya_sendiri(): void
    {
        $location = Location::create([
            'name' => 'KABUPATEN SEMARANG',
            'latitude' => -7.01,
            'longitude' => 110.42,
            'category_id' => $this->provinsi->id,
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.locations.edit', $location));

        $response->assertOk();
        $response->assertSee('JAWA TENGAH');
        $response->assertSee('value="'.$this->provinsi->id.'"', false);
    }

    public function test_halaman_navigasi_tidak_bocorkan_kategori_provinsi(): void
    {
        $response = $this->actingAs($this->admin)->get(route('navigasi.index'));

        $response->assertOk();
        $response->assertDontSee('JAWA TENGAH');
        $response->assertDontSee('DKI JAKARTA');
    }

    public function test_scope_kategori_menggunakan_kolom_terkualifikasi(): void
    {
        // Kalau tidak dikualifikasi jadi `name`, query yang melakukan join
        // dengan tabel yang juga punya kolom `name` (mis. dashboard admin)
        // gagal dengan "Column 'name' in where clause is ambiguous".
        foreach (['places', 'provinces', 'forPublicFilter'] as $scope) {
            $sql = str_replace(['`', '"'], '', Category::query()->{$scope}()->toSql());

            $this->assertStringContainsString('categories.name', $sql, "Scope {$scope} harus memakai kolom terkualifikasi.");
        }
    }

    public function test_api_kategori_tidak_membocorkan_lokasi_foto_belum_disetujui(): void
    {
        $approved = Location::create([
            'name' => 'Wisata Terang',
            'latitude' => -6.9,
            'longitude' => 110.4,
            'category_id' => Category::where('name', 'Wisata Alam')->value('id'),
            'photo_review_status' => 'approved',
            'photo' => 'a.jpg',
        ]);

        $pending = Location::create([
            'name' => 'LokasiRahasia',
            'latitude' => -6.91,
            'longitude' => 110.41,
            'category_id' => Category::where('name', 'Wisata Alam')->value('id'),
            'photo_review_status' => 'pending',
            'photo' => 'b.jpg',
        ]);

        $response = $this->getJson(route('api.categories.show', Category::where('name', 'Wisata Alam')->first()));

        $response->assertOk();
        $response->assertJsonPath('data.locations_count', 1);
        $this->assertSame(
            [$approved->id],
            collect($response->json('data.locations'))->pluck('id')->all()
        );
        $this->assertStringNotContainsString('LokasiRahasia', $response->getContent());
        $this->assertNotNull($pending->fresh());
    }

    public function test_kategori_provinsi_yang_masih_dipakai_tidak_bisa_dihapus(): void
    {
        Location::create([
            'name' => 'KOTA SEMARANG',
            'latitude' => -6.97,
            'longitude' => 110.42,
            'category_id' => $this->provinsi->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.categories.destroy', $this->provinsi))
            ->assertRedirect();

        $this->assertDatabaseHas('categories', ['id' => $this->provinsi->id]);
    }
}
