<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $adminRole = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $this->admin = User::factory()->create(['role_id' => $adminRole->id]);
    }

    public function test_guest_cannot_access_category_pages(): void
    {
        $this->get(route('admin.categories.index'))->assertRedirect(route('login'));
        $this->post(route('admin.categories.store'), [])->assertRedirect(route('login'));
    }

    public function test_admin_can_create_category(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'Wisata Alam',
            'color' => '#22c55e',
            'description' => 'Lokasi wisata alam terbuka',
        ]);

        $response->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', [
            'name' => 'Wisata Alam',
            'color' => '#22c55e',
        ]);
    }

    public function test_create_requires_unique_name_and_valid_color(): void
    {
        Category::create(['name' => 'Kuliner', 'color' => '#ef4444']);

        $response = $this->actingAs($this->admin)->post(route('admin.categories.store'), [
            'name' => 'Kuliner',
            'color' => 'merah',
        ]);

        $response->assertSessionHasErrors(['name', 'color']);
    }

    public function test_admin_can_update_category(): void
    {
        $category = Category::create(['name' => 'Lama', 'color' => '#3b82f6']);

        $this->actingAs($this->admin)
            ->put(route('admin.categories.update', $category), [
                'name' => 'Baru',
                'color' => '#f97316',
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Baru', 'color' => '#f97316']);
    }

    public function test_cannot_delete_category_still_in_use(): void
    {
        $category = Category::create(['name' => 'Dipakai', 'color' => '#0ea5e9']);
        Location::create([
            'name' => 'Monas',
            'latitude' => -6.175,
            'longitude' => 106.827,
            'category_id' => $category->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
    }

    public function test_admin_can_delete_unused_category(): void
    {
        $category = Category::create(['name' => 'Terpakai Ulang', 'color' => '#8b5cf6']);

        $this->actingAs($this->admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }
}
