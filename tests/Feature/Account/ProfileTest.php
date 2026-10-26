<?php

namespace Tests\Feature\Account;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->customer = User::factory()->create([
            'name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            'phone_verified_at' => now(),
            'created_at' => Carbon::parse('2026-03-09 17:00:00', 'UTC'), // 10 Mar 2026 in KL
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function updateProfile(array $overrides = [])
    {
        return $this->actingAs($this->customer)->patch(route('account.profile.update'), [
            'name' => 'Aisyah Rahman',
            'phone' => '012-345 6789',
            ...$overrides,
        ]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('account.profile'))->assertRedirect(route('login'));
        $this->actingAs($this->customer)->get('/account')->assertRedirectContains('/account/profile');
    }

    public function test_profile_shows_member_since_and_verification_status(): void
    {
        $this->actingAs($this->customer)->get(route('account.profile'))
            ->assertOk()
            ->assertSee(['Member since', '10 Mar 2026', $this->customer->email, '✓ Verified', 'Account phone'])
            ->assertSee(['Profile', 'Addresses', 'My orders']); // account menu

        $unverified = User::factory()->unverified()->create(['phone' => '011-111 1111', 'phone_verified_at' => null]);
        $this->actingAs($unverified)->get(route('account.profile'))
            ->assertSee(['Not verified', 'Send verification link', 'Verify your phone']);
    }

    public function test_name_and_phone_can_be_updated_and_a_new_phone_needs_verifying(): void
    {
        $this->updateProfile(['name' => 'Aisyah binti Rahman', 'phone' => '019-888 7777'])
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('success', 'Your profile was updated. Please verify your new phone number.');

        $this->customer->refresh();
        $this->assertSame('Aisyah binti Rahman', $this->customer->name);
        $this->assertSame('019-888 7777', $this->customer->phone);
        $this->assertNull($this->customer->phone_verified_at);
        $this->assertFalse($this->customer->hasVerifiedPhone());
    }

    public function test_keeping_the_same_phone_keeps_it_verified(): void
    {
        $this->updateProfile(['name' => 'New Name'])->assertSessionHas('success', 'Your profile was updated.');

        $this->assertTrue($this->customer->fresh()->hasVerifiedPhone());
    }

    public function test_profile_is_validated(): void
    {
        $this->updateProfile(['name' => '', 'phone' => '12345'])
            ->assertSessionHasErrors(['name', 'phone' => 'Enter a Malaysian phone number, e.g. 012-345 6789.']);
    }

    public function test_email_cannot_be_changed_here(): void
    {
        $email = $this->customer->email;

        $this->updateProfile(['email' => 'hacker@example.com']);

        $this->assertSame($email, $this->customer->fresh()->email);
    }

    // --- Avatar -----------------------------------------------------------

    public function test_avatar_upload_replace_and_remove(): void
    {
        $this->updateProfile(['avatar' => UploadedFile::fake()->create('me.jpg', 300, 'image/jpeg')])->assertSessionHasNoErrors();
        $first = $this->customer->fresh()->avatar_path;
        $this->assertStringStartsWith("avatars/{$this->customer->id}/", $first);
        Storage::disk('public')->assertExists($first);
        $this->actingAs($this->customer)->get(route('account.profile'))->assertSee('storage/'.$first, false);

        $this->updateProfile(['avatar' => UploadedFile::fake()->create('new.png', 300, 'image/png')]);
        $second = $this->customer->fresh()->avatar_path;
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->updateProfile(['remove_avatar' => '1']);
        $this->assertNull($this->customer->fresh()->avatar_path);
        Storage::disk('public')->assertMissing($second);
        $this->actingAs($this->customer)->get(route('account.profile'))->assertSee('>AR</span>', false); // initials
    }

    public function test_avatar_is_validated(): void
    {
        $this->updateProfile(['avatar' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
            ->assertSessionHasErrors(['avatar']);
        $this->updateProfile(['avatar' => UploadedFile::fake()->create('huge.jpg', 3000, 'image/jpeg')])
            ->assertSessionHasErrors(['avatar' => 'The photo must be 2 MB or smaller.']);

        $this->assertNull($this->customer->fresh()->avatar_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // --- Password ---------------------------------------------------------

    /**
     * @param  array<string, string>  $overrides
     */
    private function changePassword(array $overrides = [])
    {
        return $this->actingAs($this->customer)->put(route('account.password.update'), [
            'current_password' => 'password',
            'password' => 'new-secret-123',
            'password_confirmation' => 'new-secret-123',
            ...$overrides,
        ]);
    }

    public function test_password_change_needs_the_current_password(): void
    {
        $this->changePassword(['current_password' => 'wrong'])
            ->assertSessionHasErrorsIn('password', ['current_password' => 'Your current password is incorrect.']);

        $this->changePassword(['password_confirmation' => 'different-123'])
            ->assertSessionHasErrorsIn('password', ['password']);

        $this->changePassword(['password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrorsIn('password', ['password' => 'Choose a new password that is different from your current one.']);

        $this->assertTrue(Hash::check('password', $this->customer->fresh()->password));
    }

    public function test_password_change_works_and_rotates_the_remember_token(): void
    {
        $oldToken = $this->customer->remember_token;

        $this->changePassword()
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('success', 'Your password was changed.');

        $this->customer->refresh();
        $this->assertTrue(Hash::check('new-secret-123', $this->customer->password));
        $this->assertNotSame($oldToken, $this->customer->remember_token);
        $this->assertAuthenticatedAs($this->customer);

        auth()->logout();
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'password'])->assertSessionHasErrors();
        $this->post('/login', ['email' => $this->customer->email, 'password' => 'new-secret-123'])->assertRedirect();
        $this->assertAuthenticatedAs($this->customer);
    }

    public function test_header_menu_links_to_the_account_pages(): void
    {
        $this->actingAs($this->customer)->get(route('home'))
            ->assertSee([route('account.profile'), route('account.addresses.index'), route('account.orders.index')], false);
    }
}
