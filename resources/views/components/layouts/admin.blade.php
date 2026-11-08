@props(['title' => null])

@php
    $links = [
        ['label' => 'Dashboard', 'href' => route('admin.dashboard'), 'active' => request()->routeIs('admin.dashboard')],
        ['label' => 'Products', 'href' => route('admin.products.index'), 'active' => request()->routeIs('admin.products.*')],
        ['label' => 'Categories', 'href' => route('admin.categories.index'), 'active' => request()->routeIs('admin.categories.*')],
        ['label' => 'Orders', 'href' => route('admin.orders.index'), 'active' => request()->routeIs('admin.orders.*')],
        ['label' => 'Reports', 'href' => route('admin.reports.index'), 'active' => request()->routeIs('admin.reports.*')],
        ['label' => 'Reservations', 'href' => route('admin.reservations.index'), 'active' => request()->routeIs('admin.reservations.*')],
        ['label' => 'Returns', 'href' => route('admin.returns.index'), 'active' => request()->routeIs('admin.returns.*'),
            'count' => \App\Models\ReturnRequest::whereIn('status', [\App\Enums\ReturnStatus::Requested, \App\Enums\ReturnStatus::Approved])->count()],
        ['label' => 'Reviews', 'href' => route('admin.reviews.index'), 'active' => request()->routeIs('admin.reviews.*'),
            'count' => \App\Models\Review::whereNotNull('flagged_at')->count()],
        ['label' => 'Messages', 'href' => route('admin.messages.index'), 'active' => request()->routeIs('admin.messages.*'),
            'count' => \App\Models\ContactMessage::whereNull('read_at')->count()],
    ];
    // ML insights (ml-service API, see App\Services\Ml\MlService).
    $insightLinks = [
        ['label' => 'Revenue forecast', 'href' => route('admin.insights.forecast'), 'active' => request()->routeIs('admin.insights.forecast')],
        ['label' => 'Restock', 'href' => route('admin.insights.restock'), 'active' => request()->routeIs('admin.insights.restock')],
        ['label' => 'ML status', 'href' => route('admin.insights.status'), 'active' => request()->routeIs('admin.insights.status', 'admin.insights.retrain')],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => $title])
</head>
<body class="min-h-screen bg-stone-50 font-sans text-stone-900 antialiased print:bg-white">
    <div class="flex min-h-screen flex-col md:flex-row">
        {{-- Phones: a bar on top with a horizontally scrolling menu. md+: a full-height column that stays put
             while the page scrolls (sticky, h-screen); only the menu scrolls if the window is very short,
             and the account block is always pinned at the bottom. --}}
        <aside class="print:hidden flex shrink-0 flex-col border-b border-stone-800 bg-stone-900 text-stone-300 md:sticky md:top-0 md:h-screen md:w-60 md:border-b-0 md:border-r" data-admin-sidebar>
            <div class="flex shrink-0 items-center justify-between px-5 py-4 md:block">
                <a href="{{ route('admin.dashboard') }}" class="text-lg font-semibold text-white">
                    {{ config('app.name', 'ShoeThrift') }}
                </a>
                <p class="text-xs uppercase tracking-wider text-stone-500 md:mt-1">Admin</p>
            </div>

            <nav class="flex gap-1 overflow-x-auto px-3 pb-3 md:min-h-0 md:flex-1 md:flex-col md:overflow-x-hidden md:overflow-y-auto md:pb-3" aria-label="Admin">
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}"
                       @if ($link['active']) aria-current="page" @endif
                       class="flex items-center justify-between gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium {{ $link['active'] ? 'bg-stone-800 text-white' : 'hover:bg-stone-800/60 hover:text-white' }}">
                        {{ $link['label'] }}
                        @if (($link['count'] ?? 0) > 0)
                            <span class="rounded-full bg-emerald-600 px-1.5 text-xs font-semibold text-white" aria-label="{{ $link['count'] }} need attention">{{ $link['count'] }}</span>
                        @endif
                    </a>
                @endforeach

                <p class="mt-3 hidden px-3 pb-1 text-xs font-semibold uppercase tracking-wider text-stone-500 md:block" id="insights-heading">Insights</p>
                @foreach ($insightLinks as $link)
                    <a href="{{ $link['href'] }}" data-insights-link
                       @if ($link['active']) aria-current="page" @endif
                       class="flex items-center justify-between gap-2 whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium {{ $link['active'] ? 'bg-stone-800 text-white' : 'hover:bg-stone-800/60 hover:text-white' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="hidden shrink-0 border-t border-stone-800 px-5 py-4 text-sm md:mt-auto md:block" data-sidebar-account>
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

        <main class="min-w-0 flex-1 px-4 py-6 sm:px-8 sm:py-8 print:p-0">
            <div class="-mt-2 mb-4 flex items-center justify-end gap-3 print:hidden">
                <x-admin.notification-bell />
            </div>

            @if (session('success'))
                <div class="print:hidden mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800" role="status">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                    {{ session('error') }}
                </div>
            @endif

            {{ $slot }}

            {{-- On small screens the sidebar footer is hidden, so offer the same links here. --}}
            <div class="mt-10 flex items-center gap-4 text-sm text-stone-600 md:hidden print:hidden">
                <a href="{{ route('home') }}" class="underline">View store</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="underline">Log out</button>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
