<?php

namespace Tests\Feature;

use App\Models\NavigationHistory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Perubahan menu & halaman:
 *
 * 1. "Kalkulator Jarak" dan "Cari Radius" hanya untuk role Admin, jadi tidak
 *    boleh muncul di dropdown Super Admin.
 * 2. Menu navbar "Navigasi" tidak untuk Admin maupun Super Admin.
 * 3. Teks kosong halaman Favorit dan Riwayat.
 * 4. Halaman "Riwayat Kunjungan" menampilkan data seluruh pengguna.
 */
class AdminMenuAndVisitHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $super;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $superRole = Role::create(['name' => 'super_admin', 'label' => 'Super Admin']);
        $userRole = Role::create(['name' => 'user', 'label' => 'User']);

        $this->admin = User::factory()->create(['name' => 'Budi Santoso', 'role_id' => $adminRole->id]);
        $this->super = User::factory()->create(['name' => 'Siti Aminah', 'role_id' => $superRole->id]);
        $this->user = User::factory()->create(['name' => 'Andi Wijaya', 'role_id' => $userRole->id]);
    }

    public function test_super_admin_navbar_has_no_distance_and_radius_menu(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        $response->assertDontSee('Kalkulator Jarak');
        $response->assertDontSee('Cari Radius');

        // Yang boleh hilang hanya dua tool itu; menu inti Super Admin tetap ada.
        $response->assertSee('Kelola User');
        $response->assertSee('Log Aktivitas');
    }

    public function test_admin_navbar_still_has_distance_and_radius_menu(): void
    {
        $response = $this->actingAs($this->admin)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('Kalkulator Jarak');
        $response->assertSee('Cari Radius');
    }

    public function test_navigation_menu_hidden_for_admin_and_super_admin(): void
    {
        $adminResponse = $this->actingAs($this->admin)->get(route('map.index'));
        $superResponse = $this->actingAs($this->super)->get(route('map.index'));

        // route('navigasi.index') tidak boleh muncul di navbar mereka.
        $adminResponse->assertDontSee(route('navigasi.index'));
        $superResponse->assertDontSee(route('navigasi.index'));
    }

    public function test_navigation_menu_visible_for_regular_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee(route('navigasi.index'));
    }

    public function test_navigation_menu_hidden_for_guest(): void
    {
        // Route navigasi berada di middleware auth, jadi tamu tidak boleh
        // melihat tautan yang hanya akan melempar ke halaman login.
        $response = $this->get(route('map.index'));

        $response->assertOk();
        $response->assertDontSee(route('navigasi.index'));
    }

    public function test_favorites_empty_text(): void
    {
        $response = $this->actingAs($this->user)->get(route('favorites.index'));

        $response->assertOk();
        $response->assertSee('Belum memiliki lokasi favorit pengguna dan ubah fungsi jelajahi peta untuk tampilkan halaman favorit user');
    }

    public function test_history_empty_text(): void
    {
        $response = $this->actingAs($this->user)->get(route('history.index'));

        $response->assertOk();
        $response->assertSee('Belum ada riwayat.');
    }

    public function test_visit_history_page_lists_history_from_all_users(): void
    {
        NavigationHistory::create([
            'user_id' => $this->user->id,
            'dest_name' => 'Candi Borobudur',
            'dest_lat' => -7.6079,
            'dest_lng' => 110.2038,
            'vehicle' => 'car',
            'status' => 'finished',
            'started_at' => now()->subDay(),
        ]);

        NavigationHistory::create([
            'user_id' => $this->admin->id,
            'dest_name' => 'Pantai Kuta',
            'dest_lat' => -8.7900,
            'dest_lng' => 115.1685,
            'vehicle' => 'motorcycle',
            'status' => 'ongoing',
            'started_at' => now(),
        ]);

        $response = $this->actingAs($this->super)->get(route('admin.visit-history.index'));

        $response->assertOk();
        // Data milik user lain harus terlihat oleh admin.
        $response->assertSee('Candi Borobudur');
        $response->assertSee('Pantai Kuta');
        $response->assertSee('Andi Wijaya');
        $response->assertSee('Budi Santoso');
    }

    public function test_visit_history_page_available_for_admin_too(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.visit-history.index'));

        $response->assertOk();
    }

    public function test_visit_history_page_forbidden_for_regular_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.visit-history.index'));

        $response->assertForbidden();
    }

    public function test_visit_history_page_requires_authentication(): void
    {
        $this->get(route('admin.visit-history.index'))->assertRedirect(route('login'));
    }

    public function test_visit_history_can_filter_by_user(): void
    {
        NavigationHistory::create([
            'user_id' => $this->user->id,
            'dest_name' => 'Candi Borobudur',
            'dest_lat' => -7.6079,
            'dest_lng' => 110.2038,
            'status' => 'finished',
        ]);

        NavigationHistory::create([
            'user_id' => $this->admin->id,
            'dest_name' => 'Pantai Kuta',
            'dest_lat' => -8.79,
            'dest_lng' => 115.1685,
            'status' => 'finished',
        ]);

        $response = $this->actingAs($this->super)->get(route('admin.visit-history.index', ['user' => $this->user->id]));

        $response->assertOk();
        $response->assertSee('Candi Borobudur');
        $response->assertDontSee('Pantai Kuta');
    }

    // ── Kalkulator Jarak & Cari Radius ──────────────────────────────────────

    public function test_distance_and_radius_are_forbidden_for_super_admin(): void
    {
        // Menu-nya dihapus, dan route-nya juga harus tertutup (bukan hanya
        // disembunyikan) supaya Super Admin tidak bisa lewat URL.
        $this->actingAs($this->super)->get(route('locations.distance'))->assertForbidden();
        $this->actingAs($this->super)->get(route('locations.radius'))->assertForbidden();
        $this->actingAs($this->super)->post(route('locations.radius.search'))->assertForbidden();
    }

    public function test_distance_and_radius_allowlist_no_longer_exists_for_super_admin(): void
    {
        // Duplikat lama di prefix super-admin harus dihapus. Kalau tidak,
        // Super Admin masih bisa sneak in lewat /super-admin/locations/jarak.
        $names = collect(Route::getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->values();

        $this->assertNotContains('super-admin.locations.distance', $names);
        $this->assertNotContains('super-admin.locations.radius', $names);
        $this->assertNotContains('super-admin.locations.radius.search', $names);
    }

    public function test_distance_and_radius_available_for_admin_and_regular_user(): void
    {
        $this->actingAs($this->admin)->get(route('locations.distance'))->assertOk();
        $this->actingAs($this->user)->get(route('pengunjung.locations.distance'))->assertOk();
        $this->actingAs($this->user)->get(route('pengunjung.locations.radius'))->assertOk();
    }

    public function test_regular_user_navbar_has_tools_menu_with_distance_and_radius(): void
    {
        $response = $this->actingAs($this->user)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('Kalkulator Jarak');
        $response->assertSee('Cari Radius');
        $response->assertSee(route('pengunjung.locations.distance'));
        $response->assertSee(route('pengunjung.locations.radius'));
        // Menu Punjabung: Navigasi, Favorit Tempat, Riwayat Perjalanan.
        $response->assertSee(route('navigasi.index'));
        $response->assertSee(route('favorites.index'));
        $response->assertSee(route('history.index'));
        $response->assertSee('Favorit Tempat');
        $response->assertSee('Riwayat Perjalanan');
    }

    public function test_super_admin_navbar_has_no_tools_menu(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertDontSee('Kalkulator Jarak');
        $response->assertDontSee('Cari Radius');
    }

    // ── Pemisahan menu Super Admin vs Admin ───────────────────────────────

    public function test_super_admin_navbar_excludes_lokasi_history_and_pengaturan(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        // "Kelola Lokasi" sekarang tugas role Admin saja. Diuji dari teks
        // labelnya, bukan dari URL, karena /admin/locations tetap muncul
        // sebagai prefiks pada Impor/Ekspor Data.
        $response->assertDontSee('Kelola Lokasi');
        // "Riwayat Perjalanan" adalah data milik milik pengunjung.
        $response->assertDontSee(route('history.index'));
        // "Pengaturan" dipindahkan ke dropdown role Admin.
        $response->assertDontSee(route('admin.settings.index'));
    }

    public function test_admin_navbar_has_kelola_lokasi_and_pengaturan(): void
    {
        $response = $this->actingAs($this->admin)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('Kelola Lokasi');
        $response->assertSee(route('admin.locations.index'));
        $response->assertSee('Pengaturan');
        $response->assertSee(route('admin.settings.index'));
        // Admin tetap punya Riwayat Perjalanan.
        $response->assertSee(route('history.index'));
    }

    public function test_super_admin_navbar_keeps_web_and_activity_log(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        $response->assertSee(route('super-admin.web'));
        $response->assertSee(route('super-admin.activity-log'));
        $response->assertSee(route('super-admin.users.index'));
        $response->assertSee(route('super-admin.dashboard'));
        $response->assertSee(route('admin.visit-history.index'));
    }

    public function test_super_admin_navbar_has_no_operational_menu(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        // Semua fitur operasional harus hilang dari navbar Super Admin.
        foreach ([
            'Kelola Lokasi',
            'Kelola Kategori',
            'Impor Data',
            'Ekspor Data',
            'Moderasi Review',
            'Verifikasi Foto',
            'Pengaturan',
            'Dashboard Statistik',
        ] as $label) {
            $response->assertDontSee($label, false, "Super Admin masih melihat menu \"$label\".");
        }
    }

    /**
     * Menu disembunyikan saja tidak cukup: Super Admin harus benar-benar
     * mendapat 403 saat mencoba operation lewat URL.
     */
    public function test_super_admin_is_forbidden_from_operational_routes(): void
    {
        $getRoutes = [
            'admin.locations.index',
            'admin.locations.create',
            'admin.locations.import',
            'admin.locations.export',
            'admin.locations.template',
            'admin.categories.index',
            'admin.reviews.index',
            'admin.photo-review.index',
            'admin.settings.index',
        ];

        foreach ($getRoutes as $name) {
            $this->actingAs($this->super)
                ->get(route($name))
                ->assertForbidden("Super Admin seharusnya dapat {$name}.");
        }
    }

    public function test_super_admin_post_routes_are_forbidden(): void
    {
        $this->actingAs($this->super)
            ->post(route('admin.locations.bulk-delete'), [])
            ->assertForbidden();

        $this->actingAs($this->super)
            ->post(route('admin.locations.import.store'), [])
            ->assertForbidden();

        $this->actingAs($this->super)
            ->post(route('admin.photo-review.approve-all'), [])
            ->assertForbidden();

        $this->actingAs($this->super)
            ->put(route('admin.settings.update'), [])
            ->assertForbidden();
    }

    public function test_super_admin_cannot_reach_removed_super_admin_location_routes(): void
    {
        $names = collect(Route::getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->values();

        foreach ([
            'super-admin.locations.index',
            'super-admin.locations.create',
            'super-admin.locations.store',
            'super-admin.locations.edit',
            'super-admin.locations.update',
            'super-admin.locations.destroy',
            'super-admin.locations.bulk-delete',
            'super-admin.locations.import',
            'super-admin.locations.export',
            'super-admin.locations.template',
        ] as $name) {
            $this->assertNotContains($name, $names, "Route {$name} masih ada.");
        }
    }

    public function test_admin_can_still_reach_operational_routes(): void
    {
        foreach ([
            'admin.locations.index',
            'admin.locations.import',
            'admin.locations.export',
            'admin.categories.index',
            'admin.reviews.index',
            'admin.photo-review.index',
            'admin.settings.index',
        ] as $name) {
            $this->actingAs($this->admin)
                ->get(route($name))
                ->assertOk("Admin seharusnya dapat {$name}.");
        }
    }

    // ── Halaman navigasi: daftar titik macet ───────────────────────────────

    public function test_navigation_page_shows_congestion_points_list(): void
    {
        $response = $this->actingAs($this->user)->get(route('navigasi.index'));

        $response->assertOk();
        // Panel daftar titik macet untuk seluruh area peta.
        $response->assertSee('traffic-list', false);
        $response->assertSee('traffic-list-count', false);
        $response->assertSee('Titik macet di area ini');
    }

    public function test_navigation_page_has_no_jam_sibus_badge(): void
    {
        $response = $this->actingAs($this->user)->get(route('navigasi.index'));

        $response->assertOk();
        // Badge ringkasan "Lalu lintas: ..." sudah dihapus.
        $response->assertDontSee('Lalu lintas:');
        $response->assertDontSee('traffic-badge"', false);
    }

    public function test_navigation_page_is_reachable_for_every_authenticated_role(): void
    {
        // Menu "Navigasi" disembunyikan untuk Admin & Super Admin, tapi route-nya
        // sengaja tetap terbuka (hanya middleware auth). Kalau nanti mau ditutup
        // juga, cukup ubah ke role:user.
        $this->actingAs($this->user)->get(route('navigasi.index'))->assertOk();
        $this->actingAs($this->admin)->get(route('navigasi.index'))->assertOk();
        $this->actingAs($this->super)->get(route('navigasi.index'))->assertOk();
    }

    public function test_navigation_page_requires_authentication(): void
    {
        $this->get(route('navigasi.index'))->assertRedirect(route('login'));
    }
}
