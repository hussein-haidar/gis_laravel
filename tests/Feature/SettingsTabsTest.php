<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman /admin/pengaturan memakai tab Bootstrap (Traffic, Routing, GIS, AI,
 * Semua). Tabnya dipindah di sisi klien, jadi semua group harus ikut dirender
 * di respons; kalau hanya group aktif yang diambil, tab lain tampil kosong.
 */
class SettingsTabsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        foreach ([
            'traffic' => ['tomtom_api_key', 'traffic_radius_m', 'traffic_refresh_min'],
            'routing' => ['osrm_public_url', 'routing_engine', 'routing_profile_car'],
            'gis' => ['gis_api_source_url', 'gis_sync_enabled', 'gis_wilayah_level'],
            'ai' => ['groq_api_key', 'groq_model', 'ai_context_limit'],
        ] as $group => $keys) {
            // Seed bawaan mungkin sudah punya kunci ini, jadi pakai
            // updateOrCreate supaya tidak bentrok unique settings.key.
            foreach ($keys as $index => $key) {
                Setting::updateOrCreate(
                    ['key' => $key],
                    [
                        'group' => $group,
                        'label' => ucfirst(str_replace('_', ' ', $key)),
                        'value' => $index === 1 ? '' : ($index === 2 ? '30' : '1'),
                        'type' => $index === 1 ? 'boolean' : ($index === 2 ? 'integer' : 'string'),
                    ]
                );
            }
        }
    }

    public function test_default_page_renders_every_group_tab_not_only_the_default_one(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.settings.index'));

        $response->assertOk();

        foreach (['traffic', 'routing', 'gis', 'ai'] as $group) {
            $response->assertSee('id="panel-'.$group.'"', false);
            $response->assertSee('name="settings[tomtom_api_key][key]"', false);
        }
    }

    public function test_each_tab_can_be_opened_directly_and_still_shows_all_tabs(): void
    {
        foreach (['traffic', 'routing', 'gis', 'ai', 'all'] as $group) {
            $response = $this->actingAs($this->admin)->get(route('admin.settings.index', ['group' => $group]));

            $response->assertOk();

            foreach (['traffic', 'routing', 'gis', 'ai', 'all'] as $panel) {
                $response->assertSee('id="panel-'.$panel.'"', false);
            }
        }
    }

    public function test_unknown_group_falls_back_to_an_existing_tab(): void
    {
        // Tidak ada group "general" di seed, jadi halaman lama tampil kosong.
        $response = $this->actingAs($this->admin)->get(route('admin.settings.index', ['group' => 'general']));

        $response->assertOk();
        $response->assertSee('id="panel-ai"', false);
        $this->assertStringContainsString('panel-ai" role="tabpanel"', str_replace(
            ['fade ', 'show '],
            '',
            (string) $response->getContent()
        ));
    }

    public function test_all_tab_lists_settings_from_every_group(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.settings.index', ['group' => 'all']));

        $response->assertOk();

        foreach (['tomtom_api_key', 'osrm_public_url', 'gis_api_source_url', 'groq_api_key'] as $key) {
            $response->assertSee($key, false);
        }
    }
}
