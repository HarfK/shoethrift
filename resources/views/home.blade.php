<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => null])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    <header class="border-b border-stone-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4">
            <a href="{{ route('home') }}" class="text-lg font-semibold tracking-tight">
                {{ config('app.name', 'Thrift Store') }}
            </a>

            <nav class="flex items-center gap-4 text-sm">
                @auth
                    <span class="hidden text-stone-600 sm:inline">{{ auth()->user()->name }}</span>
                    @if (auth()->user()->isAdmin())
                        <a href="{{ route('admin.dashboard') }}" class="font-medium text-emerald-700 hover:underline">Admin</a>
                    @endif
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="hover:underline">Log out</button>
                    </form>
                @else
                    <a href="{{ route('login') }}" class="hover:underline">Log in</a>
                    <a href="{{ route('register') }}" class="rounded-md bg-emerald-700 px-3 py-1.5 font-medium text-white hover:bg-emerald-800">Register</a>
                @endauth
            </nav>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-16">
        <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">Pre-loved finds, fair prices.</h1>
        <p class="mt-3 max-w-xl text-stone-600">The storefront is coming soon.</p>
    </main>
</body>
</html>
