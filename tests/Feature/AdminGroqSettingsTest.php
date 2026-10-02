<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ai\GroqService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cakupan: input API key dari halaman admin, tes validasi ke Groq SEBELUM
 * disimpan, dan enkripsi nilai secret di database.
 *
 * Semua panggilan HTTP ke Groq di-fake, jadi test tidak butuh internet
 * dan tidak menghabiskan kuota.
 */
class AdminGroqSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const VALID_KEY = 'gsk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $role->id]);

        // Jangan sampai key asli dari .env ikut terbawa ke test.
        config(['services.groq.key' => null]);
    }

    /**
     * PalsukanGroq: /models succeeded, chat completion-sukses.
     */
    private function fakeGroqSuccess(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response([
                'data' => [
                    ['id' => 'qwen/qwen3.8-27b'],
                    ['id' => 'meta-llama/llama-3.3-70b-versatile'],
                ],
            ]),
            'api.groq.com/openai/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'pong']]],
            ]),
        ]);
    }

    private function fakeGroqUnauthorized(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/*' => Http::response(['error' => ['message' => 'Invalid API Key']], 401),
        ]);
    }

    /** Nilai mentah di kolom `value`, tanpa dekripsi. */
    private function rawSetting(string $key): ?string
    {
        return DB::table('settings')->where('key', $key)->value('value');
    }

    private function submitSettings(array $settings, string $group = 'ai')
    {
        return $this->actingAs($this->admin)->put(route('admin.settings.update'), [
            'current_group' => $group,
            'settings' => $settings,
        ]);
    }

    /**
     * Baris `settings` sudah di-seed lengkap oleh migration, tapi nilai
     * default-nya masih aman (secret kosong). Helper ini menurunkan nilainya
     * ke plaintext untuk menyimulasikan data sebelum enkripsi diterapkan.
     *
     * Sengaja lewat query builder: Setting::create()/update() akan otomatis
     * mengenkripsi lewat hook saving, jadi tidak bisa dipakai untuk ini.
     */
    private function seedLegacySecret(string $key, string $value, bool $isSecret = true): void
    {
        $row = [
            'value' => $value,
            'type' => 'string',
            'group' => 'traffic',
            'label' => $key,
            'description' => null,
            'is_secret' => $isSecret ? 1 : 0,
            'updated_at' => now(),
        ];

        if (DB::table('settings')->where('key', $key)->exists()) {
            DB::table('settings')->where('key', $key)->update($row);

            return;
        }

        DB::table('settings')->insert($row + ['key' => $key, 'created_at' => now()]);
    }

    // ── Akses halaman ────────────────────────────────────────────────────────

    public function test_guest_cannot_reach_settings_pages(): void
    {
        $this->get(route('admin.settings.index'))->assertRedirect(route('login'));
        $this->postJson(route('admin.settings.test-key'), ['key' => self::VALID_KEY])
            ->assertUnauthorized();
    }

    public function test_non_admin_cannot_test_api_key(): void
    {
        $role = Role::create(['name' => 'user', 'label' => 'User']);
        $user = User::factory()->create(['role_id' => $role->id]);

        $this->fakeGroqSuccess();

        $this->actingAs($user)
            ->postJson(route('admin.settings.test-key'), ['key' => self::VALID_KEY])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_settings_page_shows_ai_group_and_never_leaks_stored_key(): void
    {
        Setting::where('key', 'groq_api_key')->update(['value' => self::VALID_KEY]);

        $response = $this->actingAs($this->admin)->get(route('admin.settings.index', ['group' => 'ai']));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('setting-groq_api_key', $html);
        $this->assertStringContainsString('test-key-btn', $html);
        $this->assertStringNotContainsString(self::VALID_KEY, $html, 'API key tidak boleh muncul di HTML');
    }

    // ── Tes key tanpa menyimpan ──────────────────────────────────────────────

    public function test_valid_key_passes_test_endpoint_without_being_saved(): void
    {
        $this->fakeGroqSuccess();

        $this->actingAs($this->admin)
            ->postJson(route('admin.settings.test-key'), [
                'key' => self::VALID_KEY,
                'model' => 'qwen/qwen3.8-27b',
            ])
            ->assertOk()
            ->assertJson(['valid' => true, 'source' => 'input']);

        // Endpoint tes tidak boleh menyimpan apa pun.
        $this->assertSame('', (string) $this->rawSetting('groq_api_key'));
    }

    public function test_invalid_key_is_rejected_with_422(): void
    {
        $this->fakeGroqUnauthorized();

        $this->actingAs($this->admin)
            ->postJson(route('admin.settings.test-key'), ['key' => 'gsk_'.str_repeat('z', 48)])
            ->assertStatus(422)
            ->assertJson(['valid' => false]);

        $this->assertSame('', (string) $this->rawSetting('groq_api_key'));
    }

    public function test_key_with_wrong_format_is_rejected_without_calling_groq(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->postJson(route('admin.settings.test-key'), ['key' => 'token-abc-123'])
            ->assertStatus(422)
            ->assertJson(['valid' => false]);

        Http::assertNothingSent();
    }

    public function test_unreachable_groq_is_reported_as_invalid(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'));

        $this->actingAs($this->admin)
            ->postJson(route('admin.settings.test-key'), ['key' => self::VALID_KEY])
            ->assertStatus(422)
            ->assertJson(['valid' => false]);
    }

    public function test_test_endpoint_falls_back_to_key_from_env(): void
    {
        config(['services.groq.key' => self::VALID_KEY]);
        $this->fakeGroqSuccess();

        $this->actingAs($this->admin)
            ->postJson(route('admin.settings.test-key'), [])
            ->assertOk()
            ->assertJson(['valid' => true, 'source' => 'env']);
    }

    // Gate penyimpanan: key tidak sah tidak boleh sampai ke database.

    public function test_invalid_key_is_never_persisted(): void
    {
        $this->fakeGroqUnauthorized();

        $this->submitSettings([
            'groq_api_key' => ['key' => 'groq_api_key', 'value' => 'gsk_'.str_repeat('z', 48)],
        ])->assertSessionHas('error');

        $this->assertSame('', (string) $this->rawSetting('groq_api_key'));
    }

    public function test_key_is_not_saved_when_chosen_model_is_unusable(): void
    {
        Http::fake([
            'api.groq.com/openai/v1/models' => Http::response(['data' => [['id' => 'qwen/qwen3.8-27b']]]),
            'api.groq.com/openai/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'The model `model/tidak-ada` does not exist']],
                400
            ),
        ]);

        $this->submitSettings([
            'groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY],
            'groq_model' => ['key' => 'groq_model', 'value' => 'model/tidak-ada'],
        ])->assertSessionHas('error');

        $this->assertSame('', (string) $this->rawSetting('groq_api_key'));
    }

    public function test_valid_key_is_persisted_and_used_by_groq_service(): void
    {
        $this->fakeGroqSuccess();

        $this->submitSettings([
            'groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY],
            'groq_model' => ['key' => 'groq_model', 'value' => 'qwen/qwen3.8-27b'],
        ])->assertSessionHas('success');

        $this->assertSame(self::VALID_KEY, Setting::getValue('groq_api_key'));
        $this->assertSame('qwen/qwen3.8-27b', Setting::getValue('groq_model'));

        $service = app(GroqService::class);
        $this->assertTrue($service->enabled());
        $this->assertSame('database', $service->keySource()['source']);

        // Chat harus memakai key dari database, bukan dari .env.
        $this->assertSame('pong', $service->chat([['role' => 'user', 'content' => 'halo']]));

        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::VALID_KEY));
    }

    public function test_empty_secret_does_not_overwrite_stored_value(): void
    {
        $this->fakeGroqSuccess();
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY]]);

        $before = $this->rawSetting('groq_api_key');

        // Admin membuka halaman lalu langsung simpan tanpa mengisi ulang key.
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => '']])
            ->assertSessionHas('success');

        $this->assertSame($before, $this->rawSetting('groq_api_key'), 'Submit kosong tidak boleh menimpa key');
        $this->assertSame(self::VALID_KEY, Setting::getValue('groq_api_key'));
    }

    public function test_clear_checkbox_empties_stored_key_and_falls_back_to_env(): void
    {
        config(['services.groq.key' => self::VALID_KEY]);
        $this->fakeGroqSuccess();
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY]]);

        $this->submitSettings([
            'groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY, 'clear' => '1'],
        ])->assertSessionHas('success');

        $this->assertSame('', (string) $this->rawSetting('groq_api_key'));

        $service = app(GroqService::class);
        $this->assertSame('env', $service->keySource()['source']);
        $this->assertTrue($service->enabled());
    }

    // ── Enkripsi di database ─────────────────────────────────────────────────

    public function test_stored_api_key_is_encrypted_at_rest(): void
    {
        $this->fakeGroqSuccess();
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY]]);

        $raw = (string) $this->rawSetting('groq_api_key');

        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertStringNotContainsString(self::VALID_KEY, $raw, 'Plaintext tidak boleh ada di database');
        $this->assertStringNotContainsString('gsk_', $raw);

        // Tapi aplikasi tetap membacanya sebagai plaintext.
        $this->assertSame(self::VALID_KEY, Setting::getValue('groq_api_key'));
        $this->assertSame(self::VALID_KEY, Crypt::decryptString(substr($raw, strlen(Setting::ENCRYPTED_PREFIX))));
    }

    public function test_non_secret_settings_are_not_encrypted(): void
    {
        $this->submitSettings([
            'groq_model' => ['key' => 'groq_model', 'value' => 'qwen/qwen3.8-27b'],
        ], 'ai');

        $this->assertSame('qwen/qwen3.8-27b', $this->rawSetting('groq_model'));
    }

    public function test_saving_again_does_not_re_encrypt_or_corrupt_value(): void
    {
        $this->fakeGroqSuccess();
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY]]);

        $before = $this->rawSetting('groq_api_key');

        // Simpan ulang field lain pada baris yang sama.
        $setting = Setting::where('key', 'groq_api_key')->first();
        $setting->label = 'Groq API Key (utama)';
        $setting->save();

        $this->assertSame($before, $this->rawSetting('groq_api_key'), 'Ciphertext harus tetap sama');
        $this->assertSame(self::VALID_KEY, Setting::getValue('groq_api_key'));
    }

    public function test_secret_created_through_model_is_encrypted(): void
    {
        Setting::create([
            'key' => 'token_internal',
            'value' => 'rahasia-123',
            'type' => 'string',
            'group' => 'ai',
            'label' => 'Token',
            'is_secret' => true,
        ]);

        $raw = (string) $this->rawSetting('token_internal');

        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertStringNotContainsString('rahasia-123', $raw);
        $this->assertSame('rahasia-123', Setting::getValue('token_internal'));
    }

    public function test_legacy_plaintext_secret_is_still_readable_and_gets_upgraded_on_save(): void
    {
        // Simulasi data lama sebelum enkripsi diterapkan.
        $this->seedLegacySecret('tomtom_api_key', 'tomtom-plaintext-lama');

        $this->assertSame('tomtom-plaintext-lama', Setting::getValue('tomtom_api_key'));

        Setting::where('key', 'tomtom_api_key')->first()->save();

        $raw = (string) $this->rawSetting('tomtom_api_key');
        $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, $raw);
        $this->assertSame('tomtom-plaintext-lama', Setting::getValue('tomtom_api_key'));
    }

    public function test_undecryptable_value_fails_gracefully_instead_of_crashing(): void
    {
        Log::shouldReceive('error')->atLeast()->once();

        // Ciphertext rusak: prefix benar tapi isi tidak bisa didekripsi.
        DB::table('settings')->where('key', 'groq_api_key')
            ->update(['value' => Setting::ENCRYPTED_PREFIX.'sampah-yang-tidak-valid']);

        $this->assertSame('', Setting::getValue('groq_api_key'));
        $this->assertFalse(app(GroqService::class)->enabled());
    }

    // ── Yang tidak boleh bocor ───────────────────────────────────────────────

    public function test_activity_log_does_not_contain_the_api_key(): void
    {
        $this->fakeGroqSuccess();
        $this->submitSettings(['groq_api_key' => ['key' => 'groq_api_key', 'value' => self::VALID_KEY]]);

        $logs = DB::table('activity_logs')->where('type', 'setting_updated')->get()
            ->map(fn ($row) => json_encode([$row->old_values, $row->new_values]))
            ->implode(' ');

        $this->assertStringNotContainsString(self::VALID_KEY, $logs);
        $this->assertStringNotContainsString('gsk_', $logs);
    }

    public function test_encrypt_command_migrates_legacy_plaintext_secrets(): void
    {
        $this->seedLegacySecret('tomtom_api_key', 'tomtom-lama');
        $this->seedLegacySecret('graphhopper_api_key', 'gh-lama');

        $this->artisan('settings:encrypt-secrets --dry-run')
            ->expectsOutputToContain('2 diubah')
            ->assertSuccessful();

        // Dry-run tidak boleh mengubah apa pun.
        $this->assertSame('tomtom-lama', $this->rawSetting('tomtom_api_key'));

        $this->artisan('settings:encrypt-secrets')->assertSuccessful();

        foreach (['tomtom_api_key', 'graphhopper_api_key'] as $key) {
            $this->assertStringStartsWith(Setting::ENCRYPTED_PREFIX, (string) $this->rawSetting($key));
        }

        $this->assertSame('tomtom-lama', Setting::getValue('tomtom_api_key'));
        $this->assertSame('gh-lama', Setting::getValue('graphhopper_api_key'));

        // Jalankan dua kali: harus idempoten.
        $this->artisan('settings:encrypt-secrets')->assertSuccessful();
        $this->assertSame('tomtom-lama', Setting::getValue('tomtom_api_key'));
    }
}
