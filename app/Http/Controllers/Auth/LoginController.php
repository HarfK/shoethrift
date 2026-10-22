<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request): View
    {
        // "Log in to buy" links pass ?return=/shop/{slug} so the shopper comes back afterwards.
        // Only same-site relative paths are accepted, to avoid an open redirect.
        $return = $request->query('return');

        if (is_string($return) && str_starts_with($return, '/') && ! str_starts_with($return, '//') && ! str_contains($return, '\\')) {
            $request->session()->put('url.intended', url($return));
        }

        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended(self::homeFor($request->user()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Proposal test plan #22: logout returns to the login page.
        return redirect()->route('login');
    }

    /**
     * Where a user lands after authenticating: admins go to the admin area, everyone else home.
     */
    public static function homeFor(?User $user): string
    {
        return $user?->isAdmin() ? route('admin.dashboard') : route('home');
    }
}
