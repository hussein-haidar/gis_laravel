<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Kelola Web" milik Super Admin: judul & deskripsi situs, banner, pengumuman.
 *
 * Fokus test:
 * - Hanya Super Admin yang boleh buka & menyimpan.
 * - Checkbox yang tidak dicocentak harus tersimpan sebagai "mati", bukan
 *   "diabaikan" (checkbox tidak pernah dikirim browser saat tidak dicentang).
 * - Field yang tidak terkirim tetap boleh kosong.
 */
class WebSettingTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    private User $admin;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $superRole = Role::create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $userRole = Role::create(['name' => 'user', 'label' => 'User']);

        $this->super = User::factory()->create(['role_id' => $superRole->id]);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
        $this->user = User::factory()->create(['role_id' => $userRole->id]);
    }

    public function test_page_shows_web_fields(): void
    {
        $response = $this->actingAs($this->super)->get(route('super-admin.web'));

        $response->assertOk();
        $response->assertSee('Kelola Web');
        $response->assertSee('Judul Situs');
        $response->assertSee('Deskripsi Situs');
        $response->assertSee('Judul Banner');
        $response->assertSee('Isi Pengumuman');
    }

    public function test_page_is_forbidden_for_admin_and_regular_user(): void
    {
        $this->actingAs($this->admin)->get(route('super-admin.web'))->assertForbidden();
        $this->actingAs($this->user)->get(route('super-admin.web'))->assertForbidden();
        $this->actingAs($this->admin)->put(route('super-admin.web.update'), [])->assertForbidden();
        $this->actingAs($this->user)->put(route('super-admin.web.update'), [])->assertForbidden();
    }

    public function test_page_requires_authentication(): void
    {
        $this->get(route('super-admin.web'))->assertRedirect(route('login'));
        $this->put(route('super-admin.web.update'), [])->assertRedirect(route('login'));
    }

    public function test_super_admin_navbar_has_kelola_web_link(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('Kelola Web');
        $response->assertSee(route('super-admin.web'));
    }

    public function test_it_can_save_site_identity_banner_and_announcement(): void
    {
        $response = $this->actingAs($this->super)->put(route('super-admin.web.update'), [
            'site_title' => 'Wisata Nusantara',
            'site_description' => 'Peta lokasi wisata dan tempat menarik.',
            'banner_enabled' => '1',
            'banner_title' => 'Jelajahi Indonesia',
            'banner_text' => 'Temukan tempat menarik di sekitarmu.',
            'announcement_enabled' => '1',
            'announcement_text' => 'Libur nasional 17 Agustus.',
        ]);

        $response->assertRedirect(route('super-admin.web'));
        $response->assertSessionHas('success');

        $this->assertSame('Wisata Nusantara', Setting::getValue('site_title'));
        $this->assertSame('Peta lokasi wisata dan tempat menarik.', Setting::getValue('site_description'));
        $this->assertTrue((bool) Setting::getValue('banner_enabled'));
        $this->assertSame('Jelajahi Indonesia', Setting::getValue('banner_title'));
        $this->assertTrue((bool) Setting::getValue('announcement_enabled'));
        $this->assertSame('Libur nasional 17 Agustus.', Setting::getValue('announcement_text'));
    }

    public function test_unchecked_switches_are_saved_as_disabled(): void
    {
        // Nyalakan keduanya dulu.
        $this->actingAs($this->super)->put(route('super-admin.web.update'), [
            'site_title' => ' situs',
            'banner_enabled' => '1',
            'announcement_enabled' => '1',
        ])->assertRedirect(route('super-admin.web'));

        $this->assertTrue((bool) Setting::getValue('banner_enabled'));
        $this->assertTrue((bool) Setting::getValue('announcement_enabled'));

        // Kirim ulang tanpa kedua checkbox, seperti browser lakukan.
        $this->actingAs($this->super)->put(route('super-admin.web.update'), [
            'site_title' => 'Situs',
        ])->assertRedirect(route('super-admin.web'));

        $this->assertFalse((bool) Setting::getValue('banner_enabled'));
        $this->assertFalse((bool) Setting::getValue('announcement_enabled'));
    }

    public function test_site_title_is_required(): void
    {
        $response = $this->actingAs($this->super)->put(route('super-admin.web.update'), [
            'site_title' => '',
        ]);

        $response->assertSessionHasErrors('site_title');
    }

    public function test_settings_survive_reopening_the_page(): void
    {
        $this->actingAs($this->super)->put(route('super-admin.web.update'), [
            'site_title' => 'Nama Situs Baru',
            'banner_enabled' => '1',
            'banner_title' => 'Banner Baru',
        ]);

        $response = $this->actingAs($this->super)->get(route('super-admin.web'));

        $response->assertOk();
        $response->assertSee('Nama Situs Baru');
        $response->assertSee('Banner Baru');
        $response->assertSee('checked', false);
    }

    public function test_form_is_prefilled_from_current_site_data(): void
    {
        // Simulasikan instalasi lama: belum ada satu pun baris group 'web'.
        Setting::query()->where('group', 'web')->delete();

        $response = $this->actingAs($this->super)->get(route('super-admin.web'));

        $response->assertOk();
        // Judul mengikuti APP_NAME, bukan kosong.
        $this->assertStringContainsString('value="' . e(config('app.name')) . '"', $response->getContent());
        // Deskripsi disusun dari data GIS nyata yang ada di database.
        $this->assertStringContainsString('lokasi dari', $response->getContent());
    }
}