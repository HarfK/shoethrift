<?php

namespace Tests\Feature\Account;

use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }

    public function test_registering_sends_the_verification_email(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'phone' => '014-555 6666',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('home'));

        $user = User::sole();
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);

        $this->get(route('home'))->assertSee('Please verify your email address');
    }

    public function test_unverified_customers_can_shop_but_not_check_out(): void
    {
        $user = User::factory()->unverified()->create();
        $product = Product::factory()->create(['stock' => 1]);

        $this->actingAs($user)->post(route('cart.items.store'), ['product_id' => $product->id])->assertRedirect();
        $this->get(route('cart.index'))->assertOk();

        $this->get(route('checkout.create'))->assertRedirect(route('verification.notice'));
        $this->post(route('checkout.store'), [])->assertRedirect(route('verification.notice'));

        $this->get(route('verification.notice'))->assertOk()->assertSee(['Check your email', $user->email, 'Resend verification link']);
    }

    public function test_the_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrl($user))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('success', 'Thanks, your email address is verified.');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(route('verification.notice'))->assertRedirect(route('account.profile'));
        $this->get(route('home'))->assertDontSee('Please verify your email address');
    }

    public function test_bad_expired_or_someone_elses_links_are_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($user)->get($this->verificationUrl($user).'tampered')->assertForbidden();

        $expired = $this->verificationUrl($user, 1);
        $this->travel(2)->minutes();
        $this->actingAs($user)->get($expired)->assertForbidden();

        $this->actingAs($user)->get($this->verificationUrl($other))->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_the_link_can_be_resent_with_a_limit(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->post(route('verification.send'))
            ->assertSessionHas('success', "A new verification link was sent to {$user->email}.");
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);

        foreach (range(2, 6) as $attempt) {
            $this->actingAs($user)->post(route('verification.send'));
        }
        $this->actingAs($user)->post(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
    }

    public function test_verified_users_are_not_sent_another_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('verification.send'))->assertRedirect(route('account.profile'));

        Notification::assertNothingSent();
    }
}
