<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\PhoneVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * DEMO phone OTP (see App\Services\PhoneVerification). The code goes to the log; it is also
 * flashed as `demo_otp` and shown on the profile page only when APP_ENV=local. No SMS is sent.
 */
class PhoneVerificationController extends Controller
{
    public function send(Request $request, PhoneVerification $phoneVerification): RedirectResponse
    {
        $user = $request->user();

        if (blank($user->phone)) {
            return back()->with('error', 'Add a phone number to your profile first.');
        }
        if ($user->hasVerifiedPhone()) {
            return back()->with('success', 'Your phone number is already verified.');
        }

        $code = $phoneVerification->issue($user);

        if ($code === null) {
            return back()->with('error', 'Please wait '.$phoneVerification->secondsUntilResend($user).' seconds before asking for another code.');
        }

        return redirect()->route('account.profile')
            ->with('demo_otp', app()->environment('local') ? $code : null)
            ->with('success', "Demo: a code for {$user->phone} was written to the server log (no SMS is sent). Enter it below within ".PhoneVerification::CODE_TTL_MINUTES.' minutes.');
    }

    public function verify(Request $request, PhoneVerification $phoneVerification): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ], [
            'code.digits' => 'The code is 6 digits.',
        ]);

        return match ($phoneVerification->verify($request->user(), $data['code'])) {
            'verified' => redirect()->route('account.profile')->with('success', 'Your phone number is verified.'),
            'wrong' => back()->withErrors(['code' => "That code isn't right. Please try again."]),
            'too_many' => back()->withErrors(['code' => 'Too many wrong codes. Ask for a new code.']),
            'expired' => back()->withErrors(['code' => 'That code has expired. Ask for a new code.']),
        };
    }
}
