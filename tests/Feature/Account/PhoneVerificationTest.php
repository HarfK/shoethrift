<?php

namespace Tests\Feature\Account;

use App\Models\User;
use App\Services\PhoneVerification;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The demo phone OTP: the code is written to the log (and shown on screen only with APP_ENV=local);
 * no SMS is ever sent or claimed. Everything else behaves like a real OTP.
 */
class PhoneVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create(['phone' => '012-345 6789', 'phone_verified_at' => null]);
    }

    /**
     * Ask for a code and read it from the log line, the demo's only delivery channel.
     */
    private function requestCode(): string
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event->message;
        });

        $this->actingAs($this->customer)->post(route('account.phone.send'))
            ->assertRedirect(route('account.profile'))
            ->assertSessionHas('success');

        $line = collect($logged)->last(fn (string $message) => str_starts_with($message, '[DEMO SMS, NOT SENT]'));
        $this->assertNotNull($line, 'The code is written to the log.');
        preg_match('/: (\d{6}), expires/', $line, $match);

        return $match[1];
    }

    private function submit(string $code)
    {
        return $this->actingAs($this->customer)->from(route('account.profile'))->post(route('account.phone.verify'), ['code' => $code]);
    }

    public function test_code_is_logged_as_a_demo_and_verifies_the_phone(): void
    {
        $code = $this->requestCode();
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        // Outside APP_ENV=local the code is only in the log, never on the page or in the session.
        $this->actingAs($this->customer)->get(route('account.profile'))
            ->assertSee(['no SMS is sent', '[DEMO SMS, NOT SENT]'], false)
            ->assertDontSee($code)
            ->assertSessionMissing('demo_otp');

        $this->submit($code)->assertSessionHas('success', 'Your phone number is verified.');
        $this->assertTrue($this->customer->fresh()->hasVerifiedPhone());

        $this->actingAs($this->customer)->get(route('account.profile'))->assertDontSee('Verify your phone');
    }

    public function test_log_line_is_clearly_a_demo_and_never_claims_an_sms(): void
    {
        Log::spy();

        $this->actingAs($this->customer)->post(route('account.phone.send'));

        Log::shouldHaveReceived('info')->once()->withArgs(fn (string $message) => str_starts_with($message, '[DEMO SMS, NOT SENT] Phone verification code for 012-345 6789')
            && str_contains($message, 'this log line is the only delivery'));

        $page = $this->actingAs($this->customer)->get(route('account.profile'))->getContent();
        $this->assertStringNotContainsStringIgnoringCase('SMS was sent to', $page);
        $this->assertStringNotContainsStringIgnoringCase('we sent you an SMS', $page);
    }

    public function test_code_is_also_shown_on_screen_in_local(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $response = $this->actingAs($this->customer)->post(route('account.phone.send'))->assertSessionHas('demo_otp');
        $code = $response->getSession()->get('demo_otp');

        $this->actingAs($this->customer)->get(route('account.profile'))
            ->assertSee(['🧪 Demo only (local). No SMS was sent.', $code]);
        $this->actingAs($this->customer)->get(route('account.profile'))->assertDontSee('Demo only (local)'); // flashed once
    }

    public function test_wrong_codes_are_limited(): void
    {
        $code = $this->requestCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        foreach (range(1, PhoneVerification::MAX_ATTEMPTS - 1) as $attempt) {
            $this->submit($wrong)->assertSessionHasErrors(['code' => "That code isn't right. Please try again."]);
        }
        $this->submit($wrong)->assertSessionHasErrors(['code' => 'Too many wrong codes. Ask for a new code.']);

        // The real code no longer works either.
        $this->submit($code)->assertSessionHasErrors(['code' => 'That code has expired. Ask for a new code.']);
        $this->assertFalse($this->customer->fresh()->hasVerifiedPhone());
    }

    public function test_codes_expire_and_wrong_guesses_do_not_extend_them(): void
    {
        $code = $this->requestCode();

        $this->travel(9)->minutes();
        $this->submit($code === '999999' ? '888888' : '999999');
        $this->travel(2)->minutes(); // 11 minutes after issue

        $this->submit($code)->assertSessionHasErrors(['code' => 'That code has expired. Ask for a new code.']);
        $this->assertFalse($this->customer->fresh()->hasVerifiedPhone());
    }

    public function test_a_code_for_an_old_number_is_useless_after_changing_it(): void
    {
        $code = $this->requestCode();

        $this->actingAs($this->customer)->patch(route('account.profile.update'), ['name' => $this->customer->name, 'phone' => '019-888 7777']);

        $this->submit($code)->assertSessionHasErrors('code');
        $this->assertNull($this->customer->fresh()->phone_verified_at);
    }

    public function test_resend_is_limited_to_once_a_minute(): void
    {
        $this->requestCode();

        $this->actingAs($this->customer)->from(route('account.profile'))->post(route('account.phone.send'))
            ->assertSessionMissing('demo_otp')
            ->assertSessionHas('error');

        $this->travel(61)->seconds();
        $this->requestCode();
    }

    public function test_code_format_is_validated_and_a_phone_is_needed(): void
    {
        $this->submit('12ab')->assertSessionHasErrors(['code' => 'The code is 6 digits.']);

        $noPhone = User::factory()->create(['phone' => null]);
        $this->actingAs($noPhone)->from(route('account.profile'))->post(route('account.phone.send'))
            ->assertSessionHas('error', 'Add a phone number to your profile first.');
    }
}
