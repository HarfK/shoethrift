<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
    }

    public function test_customers_are_forbidden(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin')->assertForbidden();
    }

    public function test_admins_can_view_the_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Products')
            ->assertSee('Categories')
            ->assertSee('Orders');
    }

    public function test_guest_sent_to_login_from_admin_returns_there_after_logging_in(): void
    {
        $admin = User::factory()->admin()->create();

        $this->get('/admin');

        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }
}
