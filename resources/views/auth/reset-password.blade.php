<x-layouts.guest title="Choose a new password" heading="Choose a new password">
    <form method="POST" action="{{ route('password.update') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <x-form.field name="email" label="Email" type="email" :value="$email" required autocomplete="username" />
        <x-form.field name="password" label="New password" type="password" required autofocus autocomplete="new-password" />
        <x-form.field name="password_confirmation" label="Confirm new password" type="password" required autocomplete="new-password" />

        <x-button class="mt-2">Reset password</x-button>
    </form>
</x-layouts.guest>
