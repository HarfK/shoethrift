<x-layouts.guest title="Log in" heading="Log in to your account">
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <x-form.field name="email" label="Email" type="email" required autofocus autocomplete="username" />
        <x-form.field name="password" label="Password" type="password" required autocomplete="current-password" />

        <div class="mb-6 flex items-center justify-between text-sm">
            <label class="inline-flex items-center gap-2">
                <input type="checkbox" name="remember" class="rounded border-stone-300 text-emerald-700" @checked(old('remember'))>
                Remember me
            </label>
            <a href="{{ route('password.request') }}" class="text-emerald-700 hover:underline">Forgot password?</a>
        </div>

        <x-button>Log in</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-stone-600">
        New here? <a href="{{ route('register') }}" class="font-medium text-emerald-700 hover:underline">Create an account</a>
    </p>
</x-layouts.guest>
