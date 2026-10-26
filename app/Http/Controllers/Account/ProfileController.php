<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PhoneVerification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Throwable;

/**
 * Profile: name, account phone, avatar, and password. Email can't be changed here
 * (it would need re-verification).
 */
class ProfileController extends Controller
{
    public const MAX_AVATAR_KB = 2048;

    public function edit(Request $request, PhoneVerification $phoneVerification): View
    {
        return view('account.profile', [
            'user' => $request->user(),
            'resendIn' => $phoneVerification->secondsUntilResend($request->user()),
        ]);
    }

    public function update(Request $request, PhoneVerification $phoneVerification): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:'.CheckoutController::PHONE_PATTERN, User::uniquePhoneRule($user->id)],
            'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::MAX_AVATAR_KB],
            'remove_avatar' => ['nullable', 'boolean'],
        ], [
            'phone.regex' => 'Enter a Malaysian phone number, e.g. 012-345 6789.',
            'avatar.image' => 'The photo must be an image.',
            'avatar.mimes' => 'The photo must be JPG, PNG or WebP.',
            'avatar.max' => 'The photo must be 2 MB or smaller.',
        ]);

        $phone = filled($data['phone'] ?? null) ? trim($data['phone']) : null;
        $phoneChanged = $phone !== $user->phone;
        $oldAvatar = $user->avatar_path;
        $newAvatar = $request->hasFile('avatar') ? $request->file('avatar')->store("avatars/{$user->id}", 'public') : null;

        try {
            DB::transaction(function () use ($user, $data, $phone, $phoneChanged, $newAvatar, $request) {
                $user->name = $data['name'];
                $user->phone = $phone;

                if ($phoneChanged) {
                    $user->phone_verified_at = null; // a new number must be verified again
                }

                if ($newAvatar) {
                    $user->avatar_path = $newAvatar;
                } elseif ($request->boolean('remove_avatar')) {
                    $user->avatar_path = null;
                }

                $user->save();
            });
        } catch (Throwable $e) {
            if ($newAvatar) {
                Storage::disk('public')->delete($newAvatar);
            }

            throw $e;
        }

        // Old photo removed only after the change is saved.
        if ($oldAvatar && $oldAvatar !== $user->avatar_path) {
            Storage::disk('public')->delete($oldAvatar);
        }

        if ($phoneChanged) {
            $phoneVerification->cancel($user);
        }

        return redirect()->route('account.profile')->with('success', 'Your profile was updated.'
            .($phoneChanged && $phone ? ' Please verify your new phone number.' : ''));
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ], [
            'current_password.current_password' => 'Your current password is incorrect.',
            'password.different' => 'Choose a new password that is different from your current one.',
        ]);

        $user = $request->user();
        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => Str::random(60), // "remember me" logins elsewhere stop working
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('account.profile')->with('success', 'Your password was changed.');
    }
}
