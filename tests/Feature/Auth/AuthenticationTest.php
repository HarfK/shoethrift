<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_renders(): void
    {
        $this->get('/login')->assertOk()->assertSee('Log in to your account');
    }

    public function test_customer_is_redirected_home_after_login(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_admin_is_redirected_to_admin_dashboard_after_login(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->post('/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_login_is_locked_out_after_too_many_failed_attempts(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong-password']);
        }

        // Even the correct password is rejected while locked out.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString('Too many login attempts', session('errors')->first('email'));

        $this->assertGuest();
    }

    public function test_signed_in_users_visiting_login_are_sent_to_their_home(): void
    {
        $this->actingAs(User::factory()->create())->get('/login')->assertRedirect(route('home'));
        $this->actingAs(User::factory()->admin()->create())->get('/login')->assertRedirect(route('admin.dashboard'));
    }

    public function test_user_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest();
    }
}
