<x-layouts.guest title="Verify your email" heading="Check your email">
    <p class="text-sm text-stone-600">
        We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
        Click it to confirm your email address. You need a verified email to check out.
    </p>

    @if (session('success'))
        <p class="mt-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800" role="status">{{ session('success') }}</p>
    @endif

    <form method="POST" action="{{ route('verification.send') }}" class="mt-6">
        @csrf
        <x-button>Resend verification link</x-button>
    </form>

    <p class="mt-6 text-center text-sm text-stone-600">
        <a href="{{ route('home') }}" class="font-medium text-emerald-700 hover:underline">Keep shopping</a>
        ·
        <a href="{{ route('account.profile') }}" class="font-medium text-emerald-700 hover:underline">My profile</a>
    </p>
</x-layouts.guest>
