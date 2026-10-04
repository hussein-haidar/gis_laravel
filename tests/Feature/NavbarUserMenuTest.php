<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Menu di navbar harus sama untuk semua role: avatar + nama, lalu dropdown
 * berisi Profil, Ganti Password, dan Logout. Dropdown role (Admin / Super
 * Admin) tidak boleh lagi memuat Profil karena sudah ada di dropdown user.
 */
class NavbarUserMenuTest extends TestCase
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

    public static function roleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'super admin' => ['super'],
            'user' => ['user'],
        ];
    }

    /**
     * @dataProvider roleProvider
     */
    public function test_navbar_shows_name_with_profile_and_logout_menu(string $property): void
    {
        $response = $this->actingAs($this->{$property})->get(route('map.index'));

        $response->assertOk();
        $response->assertSee($this->{$property}->name);

        // Dropdown user: profil, ganti password, dan logout.
        $response->assertSee(route('profile'), false);
        $response->assertSee(route('password.change'), false);
        $response->assertSee(route('logout'), false);

        // Tidak lagi ada tombol logout terpisah di luar dropdown.
        $response->assertDontSee('— '.__('messages.nav_logout'), false);
    }

    /**
     * @dataProvider roleProvider
     */
    public function test_navbar_shows_the_uploaded_profile_photo(string $property): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/andi.png', 'img');

        $this->{$property}->update(['avatar' => 'avatars/andi.png']);

        $response = $this->actingAs($this->{$property})->get(route('map.index'));

        $response->assertOk();
        $response->assertSee('storage/avatars/andi.png', false);
    }

    /**
     * @dataProvider roleProvider
     */
    public function test_navbar_falls_back_to_initials_when_there_is_no_photo(string $property): void
    {
        $response = $this->actingAs($this->{$property})->get(route('map.index'));

        $response->assertOk();
        $response->assertSee($this->initialOf($this->{$property}->name), false);
    }

    public function test_admin_menu_drops_tambah_lokasi_and_profile(): void
    {
        $response = $this->actingAs($this->admin)->get(route('map.index'));

        $response->assertOk();
        $response->assertDontSee('Tambah Lokasi');
        $response->assertDontSee(route('admin.locations.create'), false);
        // Profil tetap ada, tapi hanya di dropdown user.
        $response->assertSee(route('profile'), false);
    }

    public function test_super_admin_menu_drops_profile_and_password(): void
    {
        $response = $this->actingAs($this->super)->get(route('map.index'));

        $response->assertOk();
        $response->assertDontSee(route('super-admin.profile'), false);
        $response->assertDontSee(route('admin.password.form'), false);
        // Modification menu user tetap ada.
        $response->assertSee(route('profile'), false);
    }

    public function test_user_role_can_open_and_update_its_profile(): void
    {
        $this->actingAs($this->user)->get(route('profile'))->assertOk();

        $this->actingAs($this->user)
            ->post(route('profile.update'), [
                'name' => 'Andi Wijaya Jr',
                'email' => 'andi.wijaya@example.com',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'name' => 'Andi Wijaya Jr',
            'email' => 'andi.wijaya@example.com',
        ]);
    }

    public function test_user_role_can_change_its_password(): void
    {
        $this->user->update(['password' => Hash::make('Password123')]);

        $this->actingAs($this->user)
            ->post(route('password.change.update'), [
                'current_password' => 'Password123',
                'password' => 'Password456',
                'password_confirmation' => 'Password456',
            ])
            ->assertRedirect(route('password.change'));

        $this->assertTrue(Hash::check('Password456', $this->user->fresh()->password));
    }

    public function test_profile_avatar_upload_stores_the_file(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)
            ->post(route('profile.update'), [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'avatar' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertRedirect();

        $avatar = $this->user->fresh()->avatar;
        $this->assertNotNull($avatar);
        Storage::disk('public')->assertExists($avatar);
    }

    private function initialOf(string $name): string
    {
        $parts = array_slice(array_values(array_filter(explode(' ', trim($name)))), 0, 2);

        return mb_strtoupper(mb_substr($parts[0] ?? '?', 0, 1).(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    }
}
