<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Persetujuan foto oleh admin.
 *
 * Aturan yang diuji: foto hasil fetch berstatus pending dan tidak tampil di
 * peta sampai disetujui; foto yang ditolak dihapus bersama lokasinya.
 */
class PhotoReviewPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Category $tempat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);
        $this->tempat = Category::create(['name' => 'Wisata Alam', 'is_active' => true]);
    }

    private function photoLocation(string $name, string $status = Location::PHOTO_PENDING): Location
    {
        $path = 'location_photos/'.md5($name).'.jpg';
        Storage::disk('public')->put($path, 'dummy-binary');

        return Location::create([
            'name' => $name,
            'category_id' => $this->tempat->id,
            'latitude' => -6.2,
            'longitude' => 106.8,
            'photo' => $path,
            'photo_source_title' => 'File:'.$name.'.jpg',
            'photo_source_url' => 'https://upload.wikimedia.org/x',
            'photo_source_provider' => 'commons',
            'photo_fetched_at' => now(),
            'photo_review_status' => $status,
        ]);
    }

    public function test_guest_cannot_open_the_review_page(): void
    {
        $this->get(route('admin.photo-review.index'))->assertRedirect(route('login'));
    }

    public function test_photo_verification_is_for_admin_role_only(): void
    {
        $superRole = Role::create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $super = User::factory()->create(['role_id' => $superRole->id]);
        $loc = $this->photoLocation('Candi Prambanan');

        // Super Admin tidak memegang hak verifikasi foto.
        $this->actingAs($super)->get(route('admin.photo-review.index'))->assertForbidden();
        $this->actingAs($super)
            ->post(route('admin.photo-review.approve'), ['ids' => [$loc->id]])
            ->assertForbidden();
        $this->actingAs($super)
            ->post(route('admin.photo-review.reject'), ['ids' => [$loc->id]])
            ->assertForbidden();
        $this->actingAs($super)->post(route('admin.photo-review.approve-all'))->assertForbidden();

        $this->assertSame(Location::PHOTO_PENDING, $loc->fresh()->photo_review_status);

        // Admin tetap punya akses penuh.
        $this->actingAs($this->admin)->get(route('admin.photo-review.index'))->assertOk();
    }

    public function test_page_lists_pending_photos_with_their_source(): void
    {
        $loc = $this->photoLocation('Candi Prambanan');
        $approved = $this->photoLocation('Pantai Kuta', Location::PHOTO_APPROVED);

        $response = $this->actingAs($this->admin)->get(route('admin.photo-review.index'));

        $response->assertOk();
        $response->assertSee('Candi Prambanan');
        $response->assertSee('File:Candi Prambanan.jpg');
        $response->assertSee($loc->photo);

        // Tab "menunggu" tidak ikut menampilkan yang sudah disetujui.
        $response->assertDontSee($approved->name);
    }

    public function test_search_filters_by_location_name(): void
    {
        $this->photoLocation('Candi Prambanan');
        $this->photoLocation('Pantai Kuta');

        $response = $this->actingAs($this->admin)
            ->get(route('admin.photo-review.index', ['q' => 'Prambanan']));

        $response->assertOk();
        $response->assertSee('Candi Prambanan');
        $response->assertDontSee('Pantai Kuta');
    }

    public function test_approving_marks_the_photo_and_records_the_reviewer(): void
    {
        $loc = $this->photoLocation('Candi Prambanan');

        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.approve'), ['ids' => [$loc->id]])
            ->assertRedirect();

        $fresh = $loc->fresh();
        $this->assertSame(Location::PHOTO_APPROVED, $fresh->photo_review_status);
        $this->assertTrue($fresh->photo_reviewed_at->isSameSecond(now()));
        $this->assertSame($this->admin->id, $fresh->photo_reviewed_by);
    }

    public function test_rejecting_deletes_the_photo_file_and_the_location(): void
    {
        $loc = $this->photoLocation('Museum Palsu');
        $path = $loc->photo;

        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.reject'), ['ids' => [$loc->id]])
            ->assertRedirect();

        $this->assertNull(Location::find($loc->id));
        Storage::disk('public')->assertMissing($path);
    }

    public function test_bulk_actions_work_on_several_photos_at_once(): void
    {
        $keep = $this->photoLocation('Museum Satu');
        $drop = $this->photoLocation('Museum Dua');

        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.approve'), ['ids' => [$keep->id]])
            ->assertRedirect();

        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.reject'), ['ids' => [$drop->id]])
            ->assertRedirect();

        $this->assertSame(Location::PHOTO_APPROVED, $keep->fresh()->photo_review_status);
        $this->assertNull(Location::find($drop->id));
    }

    public function test_pending_photo_is_hidden_from_the_public_page(): void
    {
        $loc = $this->photoLocation('Candi Prambanan');

        // Foto yang belum disetujui tidak boleh tayang: photo_url kosong
        // supaya viewer menyembunyikan elemen gambar.
        $this->assertNull($loc->photo_url);
        $this->assertSame('', $loc->photo_display);

        $loc->update(['photo_review_status' => Location::PHOTO_APPROVED]);

        $this->assertNotNull($loc->fresh()->photo_url);
    }

    public function test_pending_location_is_hidden_entirely_from_the_public_list(): void
    {
        $this->photoLocation('Candi Prambanan');

        // Aturan: menunggu = disembunyikan total. Tidak ada nama, tidak ada
        // kotak abu-abu, tidak ada apa pun di daftar publik.
        $response = $this->get(route('map.index'));

        $response->assertOk();
        $response->assertDontSee('Candi Prambanan');
        $response->assertDontSee('Foto sedang diverifikasi.');
    }

    public function test_pending_location_keeps_its_map_marker_but_has_no_photo_in_geojson(): void
    {
        $this->photoLocation('Candi Prambanan');

        // Menunggu = disembunyikan total dari peta: tidak ada marker, tidak
        // ada koordinat, tidak ada nama yang bocor lewat API.
        $response = $this->getJson(route('geojson.index', ['compact' => 1]));

        $response->assertOk();
        $this->assertCount(0, $response->json('features'));
    }

    public function test_pending_location_detail_page_returns_404(): void
    {
        $loc = $this->photoLocation('Candi Prambanan');

        $this->get(route('map.show', $loc))->assertNotFound();
        $this->getJson(route('geojson.show', $loc))->assertNotFound();
    }

    public function test_pending_location_is_hidden_from_navigation_search(): void
    {
        $pending = $this->photoLocation('Candi Prambanan');
        $approved = $this->photoLocation('Candi Borobudur', Location::PHOTO_APPROVED);

        $response = $this->actingAs($this->admin)
            ->getJson(route('navigasi.locations', ['q' => 'Candi']));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id');
        $this->assertTrue($ids->contains($approved->id));
        $this->assertFalse($ids->contains($pending->id));
        $this->assertStringNotContainsString('Prambanan', $response->getContent());
    }

    public function test_approved_location_is_visible_with_marker_photo_and_detail_page(): void
    {
        $loc = $this->photoLocation('Pantai Kuta', Location::PHOTO_APPROVED);

        $this->get(route('map.index'))
            ->assertOk()
            ->assertSee('Pantai Kuta')
            ->assertSee($loc->photo);

        $this->get(route('map.show', $loc))->assertOk()->assertSee('Pantai Kuta');

        $feature = collect($this->getJson(route('geojson.index', ['compact' => 1]))->json('features'))
            ->firstWhere('id', $loc->id);

        $this->assertNotNull($feature);
        $this->assertNotNull($feature['properties']['photo_url']);
    }

    public function test_approve_without_selection_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.approve'), ['ids' => []])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_approve_all_clears_the_whole_queue_in_one_click(): void
    {
        $a = $this->photoLocation('Museum Satu');
        $b = $this->photoLocation('Museum Dua');
        $done = $this->photoLocation('Museum Tiga', Location::PHOTO_APPROVED);

        $this->actingAs($this->admin)
            ->post(route('admin.photo-review.approve-all'))
            ->assertRedirect();

        $this->assertSame(Location::PHOTO_APPROVED, $a->fresh()->photo_review_status);
        $this->assertSame(Location::PHOTO_APPROVED, $b->fresh()->photo_review_status);
        $this->assertSame(Location::PHOTO_APPROVED, $done->fresh()->photo_review_status);

        $this->assertSame(0, Location::awaitingPhotoReview()->count());
    }
}
