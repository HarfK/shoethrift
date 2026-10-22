<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_renders(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create an account');
    }

    public function test_new_users_can_register_as_customers(): void
    {
        $this->post('/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('home'));

        $user = User::where('email', 'jane@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame(UserRole::Customer, $user->role);
    }

    public function test_registration_cannot_set_admin_role(): void
    {
        $this->post('/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'admin',
        ]);

        $this->assertSame(UserRole::Customer, User::where('email', 'sneaky@example.com')->firstOrFail()->role);
    }

    public function test_registration_requires_unique_email_and_matching_passwords(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->post('/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }
}
