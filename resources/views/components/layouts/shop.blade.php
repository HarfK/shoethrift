@props(['title' => null])

@php
    $nav = [
        ['label' => 'Home', 'href' => route('home'), 'active' => request()->routeIs('home')],
        ['label' => 'Shop', 'href' => route('shop.index'), 'active' => request()->routeIs('shop.*')],
    ];
    $searchValue = request()->routeIs('shop.index') && is_string(request('search')) ? request('search') : '';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="flex min-h-screen flex-col bg-stone-50 font-sans text-stone-900 antialiased">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-white focus:px-3 focus:py-2 focus:shadow">Skip to content</a>

    <header class="sticky top-0 z-40 border-b border-stone-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
            {{-- Mobile menu (no JS: <details>) --}}
            <details class="relative md:hidden">
                <summary class="flex size-9 cursor-pointer list-none items-center justify-center rounded-md hover:bg-stone-100" aria-label="Menu">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
                </summary>
                <nav class="absolute left-0 mt-2 w-48 rounded-lg border border-stone-200 bg-white py-1 shadow-lg" aria-label="Main">
                    @foreach ($nav as $item)
                        <a href="{{ $item['href'] }}" @class(['block px-4 py-2 text-sm hover:bg-stone-50', 'font-semibold' => $item['active']])>{{ $item['label'] }}</a>
                    @endforeach
                </nav>
            </details>

            <a href="{{ route('home') }}" class="text-xl font-bold tracking-tight">
                {{ config('app.name', 'ShoeThrift') }}
            </a>

            <nav class="hidden items-center gap-6 text-sm md:flex" aria-label="Main">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" @if ($item['active']) aria-current="page" @endif
                       @class(['hover:text-emerald-700', 'font-semibold text-stone-900' => $item['active'], 'text-stone-600' => ! $item['active']])>{{ $item['label'] }}</a>
                @endforeach
            </nav>

            <form method="GET" action="{{ route('shop.index') }}" class="ml-auto hidden w-full max-w-xs md:block" role="search">
                <label for="header-search" class="sr-only">Search shoes</label>
                <input id="header-search" name="search" type="search" value="{{ $searchValue }}" placeholder="Search shoes or brands…"
                       class="block w-full rounded-full border border-stone-300 bg-stone-50 px-4 py-2 text-sm focus:border-emerald-600 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-600/30">
            </form>

            <div class="ml-auto flex items-center gap-1 md:ml-0">
                {{-- The cart is built in a later step. --}}
                <span class="flex size-9 cursor-not-allowed items-center justify-center rounded-md text-stone-400" title="Cart opens soon" aria-label="Cart (opens soon)" aria-disabled="true">
                    <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg>
                </span>

                @auth
                    <details class="relative">
                        <summary class="flex cursor-pointer list-none items-center gap-1 rounded-md px-2 py-1.5 text-sm hover:bg-stone-100">
                            <svg viewBox="0 0 24 24" class="size-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 4-6 8-6s8 2 8 6"/></svg>
                            <span class="hidden max-w-32 truncate sm:inline">{{ auth()->user()->name }}</span>
                        </summary>
                        <div class="absolute right-0 mt-2 w-52 rounded-lg border border-stone-200 bg-white py-1 shadow-lg">
                            <p class="truncate border-b border-stone-100 px-4 py-2 text-xs text-stone-500">{{ auth()->user()->email }}</p>
                            @if (auth()->user()->isAdmin())
                                <a href="{{ route('admin.dashboard') }}" class="block px-4 py-2 text-sm hover:bg-stone-50">Admin dashboard</a>
                            @endif
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full px-4 py-2 text-left text-sm hover:bg-stone-50">Log out</button>
                            </form>
                        </div>
                    </details>
                @else
                    <a href="{{ route('login') }}" class="rounded-md px-3 py-1.5 text-sm hover:bg-stone-100">Log in</a>
                    <a href="{{ route('register') }}" class="hidden rounded-md bg-stone-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-stone-700 sm:inline-block">Register</a>
                @endauth
            </div>
        </div>

        {{-- Mobile search --}}
        <form method="GET" action="{{ route('shop.index') }}" class="px-4 pb-3 md:hidden" role="search">
            <label for="mobile-search" class="sr-only">Search shoes</label>
            <input id="mobile-search" name="search" type="search" value="{{ $searchValue }}" placeholder="Search shoes or brands…"
                   class="block w-full rounded-full border border-stone-300 bg-stone-50 px-4 py-2 text-sm focus:border-emerald-600 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-600/30">
        </form>
    </header>

    <main id="main" class="flex-1">
        {{ $slot }}
    </main>

    <footer class="mt-16 border-t border-stone-200 bg-white">
        <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-3 sm:px-6">
            <div>
                <p class="text-lg font-bold">{{ config('app.name', 'ShoeThrift') }}</p>
                <p class="mt-2 max-w-xs text-sm text-stone-600">Your platform for buying pre-loved, authentic shoes in Malaysia.</p>
            </div>
            <nav aria-label="Quick links">
                <h2 class="text-sm font-semibold">Quick links</h2>
                <ul class="mt-3 space-y-2 text-sm text-stone-600">
                    <li><a href="{{ route('home') }}" class="hover:text-emerald-700">Home</a></li>
                    <li><a href="{{ route('shop.index') }}" class="hover:text-emerald-700">Shop all shoes</a></li>
                </ul>
            </nav>
            <nav aria-label="Categories">
                <h2 class="text-sm font-semibold">Categories</h2>
                <ul class="mt-3 grid grid-cols-2 gap-2 text-sm text-stone-600">
                    @foreach ($footerCategories as $category)
                        <li><a href="{{ route('shop.index', ['category' => $category->slug]) }}" class="hover:text-emerald-700">{{ $category->name }}</a></li>
                    @endforeach
                </ul>
            </nav>
        </div>
        <p class="border-t border-stone-100 py-4 text-center text-xs text-stone-500">&copy; {{ now()->year }} {{ config('app.name', 'ShoeThrift') }}. All rights reserved.</p>
    </footer>
</body>
</html>
