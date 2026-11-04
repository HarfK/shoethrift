<x-layouts.account title="My profile">
    <h1 class="text-2xl font-bold tracking-tight">My profile</h1>

    {{-- Account status --}}
    <dl class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-stone-200 bg-white p-4 text-sm">
            <dt class="text-stone-500">Member since</dt>
            <dd class="mt-1 font-semibold">{{ $user->created_at->shopDate() }}</dd>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4 text-sm">
            <dt class="text-stone-500">Email</dt>
            <dd class="mt-1 flex flex-wrap items-center gap-2">
                <span class="truncate">{{ $user->email }}</span>
                @if ($user->hasVerifiedEmail())
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">✓ Verified</span>
                @else
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">Not verified</span>
                @endif
            </dd>
            @unless ($user->hasVerifiedEmail())
                <form method="POST" action="{{ route('verification.send') }}" class="mt-2">
                    @csrf
                    <button type="submit" class="text-xs font-medium text-emerald-700 underline">Send verification link</button>
                </form>
            @endunless
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-4 text-sm">
            <dt class="text-stone-500">Phone</dt>
            <dd class="mt-1 flex flex-wrap items-center gap-2">
                <span>{{ $user->phone ?: 'Not set' }}</span>
                @if ($user->hasVerifiedPhone())
                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-800 ring-1 ring-inset ring-emerald-600/20">✓ Verified</span>
                @elseif ($user->phone)
                    <span class="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-inset ring-amber-600/20">Not verified</span>
                @endif
            </dd>
        </div>
    </dl>

    {{-- Demo phone verification --}}
    @if ($user->phone && ! $user->hasVerifiedPhone())
        <section class="mt-6 rounded-xl border border-stone-200 bg-white p-5" aria-labelledby="phone-heading">
            <h2 id="phone-heading" class="text-lg font-semibold">Verify your phone</h2>
            <p class="mt-1 text-sm text-stone-600">We'll create a 6-digit code for {{ $user->phone }}. It's valid for {{ \App\Services\PhoneVerification::CODE_TTL_MINUTES }} minutes.</p>

            <p class="mt-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900">
                🧪 Demo: ShoeThrift has no SMS service, so <strong>no SMS is sent</strong>. Each code is written to the server log
                (<code>storage/logs/laravel.log</code>, line starting "[DEMO SMS, NOT SENT]").
            </p>

            {{-- Convenience for local demos only: show the code on screen too. --}}
            @if (session('demo_otp') && app()->environment('local'))
                <div class="mt-4 rounded-lg border-2 border-dashed border-amber-400 bg-amber-50 p-4 text-sm text-amber-900" role="status" data-demo-otp>
                    <p class="font-semibold">🧪 Demo only (local). No SMS was sent.</p>
                    <p class="mt-1">Your code, also in the server log:
                        <strong class="font-mono text-lg tracking-widest">{{ session('demo_otp') }}</strong></p>
                </div>
            @endif

            <div class="mt-4 flex flex-wrap items-end gap-4">
                <form method="POST" action="{{ route('account.phone.verify') }}" class="flex items-end gap-2">
                    @csrf
                    <div>
                        <label for="code" class="mb-1 block text-sm font-medium text-stone-700">Code</label>
                        <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456"
                               @error('code') aria-invalid="true" aria-describedby="code-error" @enderror
                               class="block w-32 rounded-md border border-stone-300 px-3 py-2 font-mono text-sm tracking-widest">
                    </div>
                    <button type="submit" class="rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Verify</button>
                </form>
                <form method="POST" action="{{ route('account.phone.send') }}">
                    @csrf
                    <button type="submit" class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold hover:bg-stone-50">
                        {{ session('demo_otp') ? 'Send a new code' : 'Send code' }}
                    </button>
                </form>
            </div>
            @error('code')
                <p id="code-error" class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </section>
    @endif

    {{-- Details + avatar --}}
    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-5" aria-labelledby="details-heading">
        <h2 id="details-heading" class="text-lg font-semibold">Personal details</h2>
        <form method="POST" action="{{ route('account.profile.update') }}" enctype="multipart/form-data" class="mt-4">
            @csrf
            @method('PATCH')

            <div class="mb-5 flex flex-wrap items-center gap-4">
                <x-avatar :user="$user" class="size-20 text-2xl" />
                <div class="text-sm">
                    <label for="avatar" class="mb-1 block font-medium text-stone-700">Profile photo</label>
                    <input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp"
                           class="block text-sm file:mr-3 file:rounded-md file:border-0 file:bg-stone-100 file:px-3 file:py-2 file:font-medium hover:file:bg-stone-200">
                    <p class="mt-1 text-xs text-stone-500">JPG, PNG or WebP, up to 2 MB.</p>
                    @error('avatar')
                        <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                    @if ($user->avatar_path)
                        <label class="mt-2 flex items-center gap-2">
                            <input type="checkbox" name="remove_avatar" value="1" class="rounded border-stone-300"> Remove photo
                        </label>
                    @endif
                </div>
            </div>

            <div class="grid gap-x-4 sm:grid-cols-2">
                <x-form.field name="name" label="Name" :value="$user->name" required maxlength="255" autocomplete="name" />
                <x-form.field name="phone" label="Account phone" type="tel" :value="$user->phone" maxlength="30" autocomplete="tel" placeholder="012-345 6789" />
            </div>
            <p class="-mt-2 mb-4 text-xs text-stone-500">Changing your phone number means verifying it again. Email: {{ $user->email }} (can't be changed here).</p>

            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Save changes</button>
        </form>
    </section>

    {{-- Shoe preferences (personal recommendations) --}}
    @php($prefErrors = $errors->preferences)
    @php($savedBrands = old('preferred_brands', $preference?->preferred_brands ?? []))
    @php($savedSize = old('shoe_size', $preference?->shoe_size !== null ? (float) $preference->shoe_size : ''))
    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-5" aria-labelledby="preferences-heading">
        <h2 id="preferences-heading" class="text-lg font-semibold">Shoe preferences</h2>
        <p class="mt-1 text-sm text-stone-600">Used to pick pairs for you. Every field is optional.</p>
        <form method="POST" action="{{ route('account.preferences.update') }}" class="mt-4 max-w-xl" data-preferences-form>
            @csrf
            @method('PATCH')

            <div class="mb-4">
                <label for="shoe_size" class="mb-1 block text-sm font-medium text-stone-700">UK shoe size</label>
                <select id="shoe_size" name="shoe_size" class="block w-40 rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                    <option value="">Not set</option>
                    @foreach (range(1, 15, 0.5) as $size)
                        <option value="{{ $size }}" @selected($savedSize !== '' && (float) $savedSize === (float) $size)>UK {{ \App\Models\Product::formatSize($size) }}</option>
                    @endforeach
                </select>
                @if ($prefErrors->has('shoe_size'))
                    <p class="mt-1 text-sm text-red-600">{{ $prefErrors->first('shoe_size') }}</p>
                @endif
            </div>

            <fieldset class="mb-4">
                <legend class="mb-1 text-sm font-medium text-stone-700">Brands you like (up to {{ \App\Http\Controllers\Account\PreferenceController::MAX_BRANDS }})</legend>
                <div class="flex flex-wrap gap-x-4 gap-y-2">
                    @forelse ($brandOptions as $brand)
                        <label class="inline-flex items-center gap-2 text-sm">
                            <input type="checkbox" name="preferred_brands[]" value="{{ $brand }}" @checked(in_array($brand, (array) $savedBrands, true)) class="rounded border-stone-300">
                            {{ $brand }}
                        </label>
                    @empty
                        <p class="text-sm text-stone-500">No brands in the shop yet.</p>
                    @endforelse
                </div>
                @foreach (['preferred_brands', 'preferred_brands.*'] as $key)
                    @if ($prefErrors->has($key))
                        <p class="mt-1 text-sm text-red-600">{{ $prefErrors->first($key) }}</p>
                    @endif
                @endforeach
            </fieldset>

            <fieldset class="mb-4">
                <legend class="mb-1 text-sm font-medium text-stone-700">Budget (RM)</legend>
                <div class="flex flex-wrap items-center gap-2">
                    <label for="min_price" class="sr-only">Minimum price</label>
                    <input id="min_price" name="min_price" type="number" min="0" step="0.01" placeholder="Min" value="{{ old('min_price', $preference?->min_price) }}" class="w-32 rounded-md border border-stone-300 px-3 py-2 text-sm">
                    <span class="text-stone-500">to</span>
                    <label for="max_price" class="sr-only">Maximum price</label>
                    <input id="max_price" name="max_price" type="number" min="0" step="0.01" placeholder="Max" value="{{ old('max_price', $preference?->max_price) }}" class="w-32 rounded-md border border-stone-300 px-3 py-2 text-sm">
                </div>
                @foreach (['min_price', 'max_price'] as $key)
                    @if ($prefErrors->has($key))
                        <p class="mt-1 text-sm text-red-600">{{ $prefErrors->first($key) }}</p>
                    @endif
                @endforeach
            </fieldset>

            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Save preferences</button>
        </form>
    </section>

    {{-- Password --}}
    <section class="mt-6 rounded-xl border border-stone-200 bg-white p-5" aria-labelledby="password-heading">
        <h2 id="password-heading" class="text-lg font-semibold">Change password</h2>
        <form method="POST" action="{{ route('account.password.update') }}" class="mt-4 max-w-md">
            @csrf
            @method('PUT')
            @foreach (['current_password' => 'Current password', 'password' => 'New password', 'password_confirmation' => 'Confirm new password'] as $field => $label)
                <div class="mb-4">
                    <label for="{{ $field }}" class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</label>
                    <input id="{{ $field }}" name="{{ $field }}" type="password" required
                           autocomplete="{{ $field === 'current_password' ? 'current-password' : 'new-password' }}"
                           @class(['block w-full rounded-md border bg-white px-3 py-2 text-sm', 'border-red-400' => $errors->password->has($field), 'border-stone-300' => ! $errors->password->has($field)])>
                    @if ($errors->password->has($field))
                        <p class="mt-1 text-sm text-red-600">{{ $errors->password->first($field) }}</p>
                    @endif
                </div>
            @endforeach
            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Change password</button>
        </form>
    </section>
</x-layouts.account>
