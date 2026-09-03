<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_link_is_sent_via_email(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        $user = User::factory()->create(['email' => 'admin@example.com', 'role_id' => $role->id]);

        $response = $this->post(route('password.email'), ['email' => 'admin@example.com']);

        $response->assertRedirect()->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordLink::class);
    }

    public function test_reset_url_is_not_leaked_in_response(): void
    {
        Notification::fake();

        $role = Role::create(['name' => 'admin', 'label' => 'Admin']);
        User::factory()->create(['email' => 'admin@example.com', 'role_id' => $role->id]);

        $this->post(route('password.email'), ['email' => 'admin@example.com'])
            ->assertSessionMissing('reset_url');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertDontSee('Link reset Anda');
    }

    public function test_unknown_email_receives_generic_response(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'ghost@example.com']);

        $response->assertRedirect()->assertSessionHas('status');
        $this->assertStringContainsString('Jika email Anda terdaftar', session('status'));

        Notification::assertNothingSent();
    }
}
