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
            'phone' => '012-345 6789',
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
            'phone' => '012-345 6789',
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
            'phone' => '012-345 6789',
            'password' => 'password',
            'password_confirmation' => 'different',
        ])->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest();
    }

    // --- Phone ----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function register(array $overrides = [])
    {
        return $this->post('/register', [
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'phone' => '012-345 6789',
            'password' => 'password',
            'password_confirmation' => 'password',
            ...$overrides,
        ]);
    }

    public function test_register_form_asks_for_phone_but_not_an_address(): void
    {
        $this->get('/register')
            ->assertSee(['Name', 'Email', 'Phone', 'Password', 'Confirm password', 'address book'])
            ->assertDontSee('name="address"', false)
            ->assertDontSee('name="line1"', false);
    }

    public function test_phone_is_saved_unverified_and_no_address_is_needed(): void
    {
        $this->register()->assertRedirect(route('home'));

        $user = User::sole();
        $this->assertSame('012-345 6789', $user->phone);
        $this->assertSame('60123456789', $user->phone_normalized);
        $this->assertNull($user->phone_verified_at);
        $this->assertSame(0, $user->addresses()->count());
        $this->assertFalse($user->hasVerifiedEmail());
    }

    public function test_phone_is_required_and_must_be_malaysian(): void
    {
        $this->register(['phone' => ''])->assertSessionHasErrors('phone');
        $this->register(['phone' => '+44 20 7946 0958'])->assertSessionHasErrors(['phone' => 'Enter a Malaysian phone number, e.g. 012-345 6789.']);

        $this->assertGuest();
    }

    public function test_one_account_per_phone_number_whatever_the_formatting(): void
    {
        User::factory()->create(['phone' => '012-345 6789']);

        foreach (['012-345 6789', '0123456789', '+60 12-345 6789', '+60123456789'] as $i => $sameNumber) {
            $this->register(['email' => "dup{$i}@example.com", 'phone' => $sameNumber])
                ->assertSessionHasErrors(['phone' => 'This phone number is already registered to another account.']);
        }

        $this->assertSame(1, User::count());
        $this->register(['phone' => '012-345 6788'])->assertSessionHasNoErrors();
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('60123456789', User::normalizePhone('012-345 6789'));
        $this->assertSame('60123456789', User::normalizePhone('+60 12-345 6789'));
        $this->assertSame('601112345678', User::normalizePhone('011-1234 5678'));
        $this->assertSame('60312345678', User::normalizePhone('03-1234 5678'));
        $this->assertSame('60312345678', User::normalizePhone('+60 3-1234 5678'));
        $this->assertNull(User::normalizePhone(null));
        $this->assertNull(User::normalizePhone(''));
    }
}
