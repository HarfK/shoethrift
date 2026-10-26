<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * DEMO phone verification. A real shop would send the code by SMS; ShoeThrift has no SMS
 * provider, so issue() writes the code to the application log ("[DEMO SMS, NOT SENT] …") and
 * returns it; with APP_ENV=local the profile page also shows it in a box labelled as a demo.
 * Nothing ever claims an SMS was sent. Everything else is real: the code is random, stored
 * only as a hash, tied to the phone number it was issued for, expires, and allows limited attempts.
 */
class PhoneVerification
{
    public const CODE_TTL_MINUTES = 10;

    public const MAX_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    /**
     * Create a new code for the user's current phone number.
     *
     * @return string|null the 6-digit code, or null if asked again within RESEND_SECONDS
     */
    public function issue(User $user): ?string
    {
        if (! RateLimiter::attempt($this->resendKey($user), 1, fn () => true, self::RESEND_SECONDS)) {
            return null;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $expiresAt = now()->addMinutes(self::CODE_TTL_MINUTES);

        Cache::put($this->cacheKey($user), [
            'hash' => Hash::make($code),
            'phone' => $user->phone,
            'attempts' => 0,
            'expires_at' => $expiresAt->getTimestamp(),
        ], $expiresAt);

        Log::info("[DEMO SMS, NOT SENT] Phone verification code for {$user->phone} (user #{$user->id}): {$code}, expires "
            .$expiresAt->shopTime().' (Malaysia time). ShoeThrift has no SMS service; this log line is the only delivery.');

        return $code;
    }

    public function secondsUntilResend(User $user): int
    {
        return RateLimiter::availableIn($this->resendKey($user));
    }

    /**
     * Check a code and, if right, mark the phone verified.
     *
     * @return 'verified'|'wrong'|'expired'|'too_many'
     */
    public function verify(User $user, string $code): string
    {
        $pending = Cache::get($this->cacheKey($user));

        // No code, or it was issued for a number the user has since changed.
        if (! $pending || $pending['phone'] !== $user->phone || now()->getTimestamp() >= $pending['expires_at']) {
            return 'expired';
        }

        if (! Hash::check($code, $pending['hash'])) {
            $pending['attempts']++;

            if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
                Cache::forget($this->cacheKey($user));

                return 'too_many';
            }

            // Keep the original expiry: wrong guesses must not extend the code's life.
            Cache::put($this->cacheKey($user), $pending, now()->setTimestamp($pending['expires_at']));

            return 'wrong';
        }

        Cache::forget($this->cacheKey($user));
        $user->forceFill(['phone_verified_at' => now()])->save();

        return 'verified';
    }

    /**
     * Forget any pending code (e.g. after the phone number changes).
     */
    public function cancel(User $user): void
    {
        Cache::forget($this->cacheKey($user));
        RateLimiter::clear($this->resendKey($user));
    }

    private function cacheKey(User $user): string
    {
        return "phone-otp:{$user->id}";
    }

    private function resendKey(User $user): string
    {
        return "phone-otp-resend:{$user->id}";
    }
}
