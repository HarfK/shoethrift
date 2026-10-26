<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Laravel's built-in email verification flow (User implements MustVerifyEmail). The email is
 * Laravel's VerifyEmail notification, which links to the signed `verification.verify` route.
 * With MAIL_MAILER=log the link is written to storage/logs/laravel.log.
 */
class EmailVerificationController extends Controller
{
    public function notice(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->intended(route('account.profile'))
            : view('auth.verify-email');
    }

    /**
     * EmailVerificationRequest checks the link belongs to the logged-in user (403 otherwise);
     * the `signed` middleware rejects tampered or expired links.
     */
    public function verify(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        return redirect()->intended(route('account.profile'))->with('success', 'Thanks, your email address is verified.');
    }

    public function send(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('account.profile');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('success', 'A new verification link was sent to '.$request->user()->email.'.');
    }
}
