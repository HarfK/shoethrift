@php
    // Primary image first (ordered in the controller); skip records whose file is missing.
    $images = $product->images->filter(fn ($image) => $image->url)->values();
    $available = $product->isAvailable();
@endphp

<x-layouts.shop :title="$product->name">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-stone-900">Home</a>
            <span aria-hidden="true">›</span>
            <a href="{{ route('shop.index') }}" class="hover:text-stone-900">Shop</a>
            <span aria-hidden="true">›</span>
            <a href="{{ route('shop.index', ['category' => $product->category->slug]) }}" class="hover:text-stone-900">{{ $product->category->name }}</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">{{ $product->name }}</span>
        </nav>

        <div class="mt-6 grid gap-8 lg:grid-cols-2 lg:gap-12">
            {{-- Gallery: swipeable strip with snap; thumbnails jump to each photo. No JavaScript needed. --}}
            <div>
                @if ($images->isEmpty())
                    <x-shoe-placeholder class="aspect-square w-full rounded-2xl" />
                @else
                    <div class="flex snap-x snap-mandatory overflow-x-auto scroll-smooth rounded-2xl bg-stone-100 [scrollbar-width:none]" data-gallery>
                        @foreach ($images as $image)
                            <img id="photo-{{ $loop->iteration }}" src="{{ $image->url }}"
                                 alt="{{ $product->name }} — photo {{ $loop->iteration }} of {{ $images->count() }}"
                                 class="aspect-square w-full shrink-0 snap-center scroll-mt-24 object-cover"
                                 @if (! $loop->first) loading="lazy" @endif>
                        @endforeach
                    </div>

                    @if ($images->count() > 1)
                        <ul class="mt-3 flex gap-2 overflow-x-auto" aria-label="Photos">
                            @foreach ($images as $image)
                                <li class="shrink-0">
                                    <a href="#photo-{{ $loop->iteration }}" class="block overflow-hidden rounded-lg border border-stone-200 hover:border-stone-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                                        <img src="{{ $image->url }}" alt="Show photo {{ $loop->iteration }}" class="size-16 object-cover sm:size-20" loading="lazy">
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            <div>
                <p class="text-sm font-semibold uppercase tracking-wide text-stone-500">{{ $product->brand }}</p>
                <h1 class="mt-1 text-3xl font-bold tracking-tight">{{ $product->name }}</h1>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <p class="text-2xl font-semibold tabular-nums">RM {{ number_format($product->price, 2) }}</p>
                    @unless ($available)
                        <span class="rounded-full bg-stone-900 px-3 py-1 text-xs font-bold uppercase tracking-wider text-white">Sold</span>
                    @endunless
                </div>

                <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-3 rounded-xl border border-stone-200 bg-white p-5 text-sm">
                    <div><dt class="text-stone-500">Size</dt><dd class="font-medium">{{ $product->size_label }}</dd></div>
                    <div><dt class="text-stone-500">Condition</dt><dd class="font-medium">{{ $product->condition->label() }}</dd></div>
                    <div><dt class="text-stone-500">Colour</dt><dd class="font-medium">{{ $product->color ?: '—' }}</dd></div>
                    <div><dt class="text-stone-500">Category</dt><dd class="font-medium">{{ $product->category->name }}</dd></div>
                    <div class="col-span-2">
                        <dt class="text-stone-500">Availability</dt>
                        <dd @class(['font-medium', 'text-emerald-700' => $available, 'text-stone-900' => ! $available])>
                            @if ($available)
                                In stock — {{ $product->stock }} {{ str('pair')->plural($product->stock) }} left
                            @else
                                Sold — this pair has found a new home
                            @endif
                        </dd>
                    </div>
                </dl>

                <div class="mt-6">
                    @if (! $available)
                        <a href="{{ route('shop.index', ['size' => \App\Models\Product::formatSize($product->size)]) }}"
                           class="inline-block rounded-md border border-stone-300 bg-white px-5 py-3 text-sm font-semibold hover:bg-stone-50">
                            Browse other {{ $product->size_label }} shoes
                        </a>
                    @elseif (auth()->check())
                        {{-- The cart is built in a later step. --}}
                        <button type="button" disabled
                                class="w-full cursor-not-allowed rounded-md bg-stone-900 px-6 py-3 font-semibold text-white opacity-60 sm:w-auto">
                            Add to cart
                        </button>
                        <p class="mt-2 text-sm text-stone-500">Cart opens soon.</p>
                    @else
                        <a href="{{ route('login', ['return' => route('shop.show', $product->slug, absolute: false)]) }}"
                           class="inline-block w-full rounded-md bg-stone-900 px-6 py-3 text-center font-semibold text-white hover:bg-stone-700 sm:w-auto">
                            Log in to buy
                        </a>
                        <p class="mt-2 text-sm text-stone-600">
                            New here? <a href="{{ route('register') }}" class="font-medium text-emerald-700 hover:underline">Create an account</a>
                        </p>
                    @endif
                </div>

                @if ($product->description)
                    <div class="mt-8">
                        <h2 class="text-base font-semibold">Description</h2>
                        <p class="mt-2 whitespace-pre-line text-stone-700">{{ $product->description }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-layouts.shop>
