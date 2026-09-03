<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiSanctumTest extends TestCase
{
    use RefreshDatabase;

    private function issueToken(string $roleName = 'admin'): string
    {
        $role = Role::create(['name' => $roleName, 'label' => ucfirst($roleName)]);
        $user = User::factory()->create([
            'email' => "{$roleName}@example.com",
            'role_id' => $role->id,
        ]);

        $response = $this->postJson(route('api.auth.token'), [
            'email' => "{$roleName}@example.com",
            'password' => 'password',
            'device_name' => 'testing',
        ]);

        $response->assertCreated();

        return $response->json('token');
    }

    public function test_token_login_with_invalid_credentials_fails(): void
    {
        Role::create(['name' => 'admin', 'label' => 'Admin']);
        User::factory()->create(['email' => 'admin@example.com', 'role_id' => 1]);

        $this->postJson(route('api.auth.token'), [
            'email' => 'admin@example.com',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    public function test_authenticated_admin_can_create_location_via_api(): void
    {
        $token = $this->issueToken('admin');

        $response = $this->postJson(route('api.locations.store'), [
            'name' => 'Lokasi API',
            'description' => 'Dibuat lewat REST API',
            'latitude' => -7.7956,
            'longitude' => 110.3695,
            'geometry' => [
                'type' => 'Point',
                'coordinates' => [110.3695, -7.7956],
            ],
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Lokasi API')
            ->assertJsonPath('data.geometry.type', 'Point');

        $this->assertDatabaseHas('locations', ['name' => 'Lokasi API']);
    }

    public function test_create_location_requires_authentication(): void
    {
        $this->postJson(route('api.locations.store'), [
            'name' => 'Tanpa Token',
            'latitude' => 0,
            'longitude' => 0,
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_user_role_is_forbidden_to_write(): void
    {
        $token = $this->issueToken('user');

        $this->postJson(route('api.locations.store'), [
            'name' => 'Role Biasa',
            'latitude' => 0,
            'longitude' => 0,
        ], [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ])->assertForbidden();
    }

    public function test_admin_can_manage_categories_via_api(): void
    {
        $token = $this->issueToken('super_admin');

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        $created = $this->postJson(route('api.categories.store'), [
            'name' => 'Kategori API',
            'color' => '#14b8a6',
        ], $headers)->assertCreated()->assertJsonPath('data.name', 'Kategori API');

        $categoryId = $created->json('data.id');

        $this->putJson(route('api.categories.update', $categoryId), [
            'name' => 'Kategori API Baru',
            'color' => '#f43f5e',
        ], $headers)->assertOk();

        // Kategori yang dipakai tidak boleh dihapus
        $location = \App\Models\Location::create([
            'name' => 'Pemakai',
            'latitude' => -2.5,
            'longitude' => 118.0,
            'category_id' => $categoryId,
        ]);

        $this->deleteJson(route('api.categories.destroy', $categoryId), [], $headers)
            ->assertStatus(409);

        $location->delete();

        $this->deleteJson(route('api.categories.destroy', $categoryId), [], $headers)
            ->assertOk();
    }

    public function test_token_can_be_revoked(): void
    {
        $token = $this->issueToken('admin');
        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
        ];

        $this->deleteJson(route('api.auth.revoke'), [], $headers)->assertOk();

        // Reset cache guard karena aplikasi dipakai ulang antar-request di dalam test
        $this->app['auth']->forgetGuards();

        $this->getJson(route('api.locations.index'), $headers);

        $this->postJson(route('api.locations.store'), [
            'name' => 'Setelah Revoke',
            'latitude' => 0,
            'longitude' => 0,
        ], $headers)->assertUnauthorized();
    }
}
