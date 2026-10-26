{{-- Customer account area: side menu (Profile, Addresses, My orders) beside the page content. --}}
@props(['title'])

@php
    $user = auth()->user();
    $links = [
        ['label' => 'Profile', 'href' => route('account.profile'), 'active' => request()->routeIs('account.profile')],
        ['label' => 'Addresses', 'href' => route('account.addresses.index'), 'active' => request()->routeIs('account.addresses.*')],
        ['label' => 'My orders', 'href' => route('account.orders.index'), 'active' => request()->routeIs('account.orders.*', 'account.returns.*')],
    ];
@endphp

<x-layouts.shop :title="$title">
    <div class="mx-auto grid max-w-6xl gap-6 px-4 py-8 sm:px-6 md:grid-cols-[14rem_1fr]">
        <aside class="h-fit rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex items-center gap-3 md:flex-col md:items-start">
                <x-avatar :user="$user" class="size-12 text-base" />
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $user->name }}</p>
                    <p class="truncate text-xs text-stone-500">{{ $user->email }}</p>
                </div>
            </div>
            <nav class="mt-4 flex gap-1 overflow-x-auto md:flex-col" aria-label="Account">
                @foreach ($links as $link)
                    <a href="{{ $link['href'] }}" @if ($link['active']) aria-current="page" @endif
                       @class(['whitespace-nowrap rounded-md px-3 py-2 text-sm font-medium', 'bg-stone-900 text-white' => $link['active'], 'hover:bg-stone-100' => ! $link['active']])>{{ $link['label'] }}</a>
                @endforeach
            </nav>
        </aside>

        <div class="min-w-0">
            {{ $slot }}
        </div>
    </div>
</x-layouts.shop>
