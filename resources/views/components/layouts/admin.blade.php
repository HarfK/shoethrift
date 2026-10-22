@props(['title' => null])

@php
    // Products, Categories and Orders are placeholders until their admin pages exist.
    $links = [
        ['label' => 'Dashboard', 'href' => route('admin.dashboard'), 'active' => request()->routeIs('admin.dashboard')],
        ['label' => 'Products', 'href' => '#', 'active' => false],
        ['label' => 'Categories', 'href' => '#', 'active' => false],
        ['label' => 'Orders', 'href' => '#', 'active' => false],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased">
    <div class="flex min-h-screen flex-col md:flex-row">
        <aside class="flex shrink-0 flex-col border-b border-stone-800 bg-stone-900 text-stone-300 md:w-60 md:border-b-0 md:border-r">
            <div class="flex items-center justify-between px-5 py-4 md:block">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-semibold text-white">
                    {{ config('app.name', 'Thrift Store') }}
                </a>
                <p class="text-xs uppercase tracking-wider text-stone-500 md:mt-1">Admin</p>
            </div>

            <nav class="flex gap-1 overflow-x-auto px-3 pb-3 md:flex-1 md:flex-col md:pb-0" aria-label="Admin">
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}"
                       @if ($link['active']) aria-current="page" @endif
                       class="whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium {{ $link['active'] ? 'bg-stone-800 text-white' : 'hover:bg-stone-800/60 hover:text-white' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="hidden border-t border-stone-800 px-5 py-4 text-sm md:block">
                <p class="truncate text-white">{{ auth()->user()->name }}</p>
                <div class="mt-2 flex items-center gap-4">
                    <a href="{{ route('home') }}" class="hover:text-white">View store</a>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="hover:text-white">Log out</button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="flex-1 px-4 py-6 sm:px-8 sm:py-8">
            {{ $slot }}

            {{-- On small screens the sidebar footer is hidden, so offer logout here. --}}
            <form method="POST" action="{{ route('logout') }}" class="mt-10 md:hidden">
                @csrf
                <button type="submit" class="text-sm text-stone-600 underline">Log out</button>
            </form>
        </main>
    </div>
</body>
</html>
