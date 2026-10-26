<x-layouts.guest title="Register" heading="Create an account">
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <x-form.field name="name" label="Name" required autofocus autocomplete="name" />
        <x-form.field name="email" label="Email" type="email" required autocomplete="username" />
        <x-form.field name="phone" label="Phone" type="tel" required maxlength="30" autocomplete="tel" placeholder="012-345 6789" />
        <p class="-mt-2 mb-4 text-xs text-stone-500">Malaysian number. One account per phone number. You can add delivery addresses later in your address book.</p>
        <x-form.field name="password" label="Password" type="password" required autocomplete="new-password" />
        <x-form.field name="password_confirmation" label="Confirm password" type="password" required autocomplete="new-password" />

        <x-button class="mt-2">Register</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-stone-600">
        Already have an account? <a href="{{ route('login') }}" class="font-medium text-emerald-700 hover:underline">Log in</a>
    </p>
</x-layouts.guest>
