<x-layouts.guest title="Forgot password" heading="Reset your password">
    <p class="mb-6 text-sm text-stone-600">
        Enter your email address and we will send you a link to choose a new password.
    </p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <x-form.field name="email" label="Email" type="email" required autofocus autocomplete="username" />

        <x-button class="mt-2">Email reset link</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-stone-600">
        <a href="{{ route('login') }}" class="font-medium text-emerald-700 hover:underline">Back to log in</a>
    </p>
</x-layouts.guest>
