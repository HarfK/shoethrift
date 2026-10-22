<x-layouts.shop>
    {{-- Hero --}}
    <section class="border-b border-stone-200 bg-gradient-to-br from-stone-900 via-stone-800 to-emerald-900 text-white">
        <div class="mx-auto max-w-7xl px-4 py-16 sm:px-6 sm:py-24">
            <h1 class="max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl">Discover Premium Thrift Shoes in Malaysia</h1>
            <p class="mt-4 max-w-xl text-lg text-stone-200">Buy authentic second-hand shoes with ease. Find your favourite sneakers at affordable prices.</p>

            <form method="GET" action="{{ route('shop.index') }}" class="mt-8 flex max-w-lg gap-2" role="search">
                <label for="hero-search" class="sr-only">Search shoes</label>
                <input id="hero-search" name="search" type="search" placeholder="Brand or model, e.g. Nike"
                       class="min-w-0 flex-1 rounded-full border-0 bg-white px-5 py-3 text-stone-900 placeholder:text-stone-400 focus:outline-none focus:ring-2 focus:ring-emerald-400">
                <button type="submit" class="rounded-full bg-emerald-500 px-6 py-3 font-semibold text-stone-950 hover:bg-emerald-400">Search</button>
            </form>
            <a href="{{ route('shop.index') }}" class="mt-4 inline-block text-sm font-medium text-emerald-300 hover:text-emerald-200">Or shop all shoes &rarr;</a>
        </div>
    </section>

    {{-- Trust row --}}
    <section class="border-b border-stone-200 bg-white">
        <ul class="mx-auto grid max-w-7xl gap-6 px-4 py-6 text-sm sm:grid-cols-3 sm:px-6">
            @foreach ([
                ['Fast Delivery', 'Nationwide delivery throughout Malaysia.', 'M3 7h11v9H3zM14 10h4l3 3v3h-7zM7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z'],
                ['Trusted Sellers', 'Every pair is cleaned and inspected.', 'M12 3l7 3v6c0 4-3 7.5-7 9-4-1.5-7-5-7-9V6zM9 12l2 2 4-4'],
                ['Secure Payment', 'Safe online banking with FPX.', 'M5 11h14v10H5zM8 11V7a4 4 0 0 1 8 0v4'],
            ] as [$heading, $text, $icon])
                <li class="flex items-start gap-3">
                    <svg viewBox="0 0 24 24" class="mt-0.5 size-6 shrink-0 text-emerald-700" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $icon }}"/></svg>
                    <div>
                        <p class="font-semibold">{{ $heading }}</p>
                        <p class="text-stone-600">{{ $text }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>

    {{-- Newly added --}}
    <section class="mx-auto max-w-7xl px-4 pt-12 sm:px-6">
        <div class="flex items-end justify-between gap-4">
            <h2 class="text-2xl font-bold tracking-tight">Newly added</h2>
            <a href="{{ route('shop.index') }}" class="text-sm font-medium text-emerald-700 hover:underline">View all</a>
        </div>

        @if ($newArrivals->isEmpty())
            <p class="mt-6 rounded-xl border border-dashed border-stone-300 p-8 text-center text-stone-600">New shoes are on their way. Check back soon!</p>
        @else
            <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-6 md:grid-cols-3 lg:grid-cols-4">
                @foreach ($newArrivals as $product)
                    <x-product-card :product="$product" />
                @endforeach
            </div>
        @endif
    </section>

    {{-- Shop by category --}}
    <section class="mx-auto max-w-7xl px-4 pt-14 sm:px-6">
        <h2 class="text-2xl font-bold tracking-tight">Shop by category</h2>
        <div class="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 lg:grid-cols-6">
            @foreach ($categories as $category)
                <a href="{{ route('shop.index', ['category' => $category->slug]) }}"
                   class="group rounded-xl border border-stone-200 bg-white p-4 transition hover:border-emerald-600 hover:shadow-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                    <p class="font-semibold group-hover:text-emerald-700">{{ $category->name }}</p>
                    <p class="mt-1 text-sm text-stone-500">{{ $category->available_count }} {{ str('pair')->plural($category->available_count) }}</p>
                </a>
            @endforeach
        </div>
    </section>
</x-layouts.shop>
