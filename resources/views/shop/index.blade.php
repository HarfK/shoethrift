@php
    $selectedConditions = array_map(fn ($c) => $c->value, $filters['conditions']);
    $activeFilterCount = collect([
        $filters['category'], $filters['brand'], $filters['size'],
        $filters['conditions'] ?: null, $filters['material'], $filters['box'] ?: null, $filters['min_price'], $filters['max_price'],
    ])->filter(fn ($value) => $value !== null)->count();
    $hasCriteria = $activeFilterCount > 0 || $filters['search'] !== '';
    $priceValue = fn (?float $price) => $price === null ? '' : rtrim(rtrim(number_format($price, 2, '.', ''), '0'), '.');
@endphp

<x-layouts.shop :title="$filters['category']?->name ?? 'Shop'">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-stone-900">Home</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">Shop</span>
        </nav>

        <div class="mt-2 flex flex-wrap items-end justify-between gap-2">
            <h1 class="text-3xl font-bold tracking-tight">
                {{ $filters['category']?->name ?? 'All shoes' }}
            </h1>
            @if ($filters['search'] !== '')
                <p class="text-sm text-stone-600">Results for “<span class="font-medium text-stone-900">{{ $filters['search'] }}</span>”</p>
            @endif
        </div>

        <div class="mt-6 lg:grid lg:grid-cols-[16rem_1fr] lg:gap-8">
            {{-- Filters: a toggleable panel on small screens (checkbox hack, no JS), always visible on large screens. --}}
            <div>
                <input type="checkbox" id="filters-toggle" class="peer sr-only">
                <label for="filters-toggle"
                       class="flex w-full cursor-pointer items-center justify-between rounded-lg border border-stone-300 bg-white px-4 py-2.5 text-sm font-medium peer-focus-visible:ring-2 peer-focus-visible:ring-emerald-600 lg:hidden">
                    <span>Filters{{ $activeFilterCount ? " ({$activeFilterCount})" : '' }}</span>
                    <svg viewBox="0 0 24 24" class="size-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"/></svg>
                </label>

                <form id="shop-filters" method="GET" action="{{ route('shop.index') }}"
                      class="mt-3 hidden space-y-6 rounded-xl border border-stone-200 bg-white p-5 peer-checked:block lg:mt-0 lg:block">
                    @if ($filters['search'] !== '')
                        <input type="hidden" name="search" value="{{ $filters['search'] }}">
                    @endif

                    <div>
                        <label for="filter-category" class="mb-1.5 block text-sm font-semibold">Category</label>
                        <select id="filter-category" name="category" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                            <option value="">All categories</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->slug }}" @selected($filters['category']?->is($category))>{{ $category->name }} ({{ $category->available_count }})</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="filter-brand" class="mb-1.5 block text-sm font-semibold">Brand</label>
                        <select id="filter-brand" name="brand" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                            <option value="">All brands</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand }}" @selected($filters['brand'] === $brand)>{{ $brand }}</option>
                            @endforeach
                        </select>
                    </div>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-semibold">Size (UK)</legend>
                        <div class="grid grid-cols-5 gap-1.5">
                            <label class="flex cursor-pointer items-center justify-center rounded-md border border-stone-300 py-1.5 text-sm has-checked:border-stone-900 has-checked:bg-stone-900 has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-emerald-600">
                                <input type="radio" name="size" value="" class="sr-only" @checked($filters['size'] === null)>Any
                            </label>
                            @foreach ($sizes as $size)
                                <label class="flex cursor-pointer items-center justify-center rounded-md border border-stone-300 py-1.5 text-sm tabular-nums has-checked:border-stone-900 has-checked:bg-stone-900 has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-emerald-600">
                                    <input type="radio" name="size" value="{{ $size }}" class="sr-only" @checked($filters['size'] === $size)>{{ $size }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-semibold">Condition</legend>
                        <div class="space-y-1.5">
                            @foreach ($conditions as $condition)
                                <label class="flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="condition[]" value="{{ $condition->value }}" class="rounded border-stone-300 text-emerald-700"
                                           @checked(in_array($condition->value, $selectedConditions, true))>
                                    {{ $condition->label() }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($materials)
                        <div>
                            <label for="filter-material" class="mb-1.5 block text-sm font-semibold">Material</label>
                            <select id="filter-material" name="material" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                                <option value="">All materials</option>
                                @foreach ($materials as $material)
                                    <option value="{{ $material }}" @selected($filters['material'] === $material)>{{ $material }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <label class="flex items-center gap-2 text-sm font-semibold">
                        <input type="checkbox" name="box" value="1" class="rounded border-stone-300 text-emerald-700" @checked($filters['box'])>
                        With original box
                    </label>

                    <fieldset>
                        <legend class="mb-1.5 text-sm font-semibold">Price (RM)</legend>
                        <div class="flex items-center gap-2">
                            <label for="min_price" class="sr-only">Minimum price</label>
                            <input id="min_price" name="min_price" type="number" min="0" step="1" inputmode="numeric" placeholder="Min" value="{{ $priceValue($filters['min_price']) }}"
                                   class="block w-full rounded-md border border-stone-300 px-3 py-2 text-sm">
                            <span class="text-stone-400" aria-hidden="true">–</span>
                            <label for="max_price" class="sr-only">Maximum price</label>
                            <input id="max_price" name="max_price" type="number" min="0" step="1" inputmode="numeric" placeholder="Max" value="{{ $priceValue($filters['max_price']) }}"
                                   class="block w-full rounded-md border border-stone-300 px-3 py-2 text-sm">
                        </div>
                    </fieldset>

                    <div class="space-y-2">
                        <button type="submit" class="w-full rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Apply filters</button>
                        @if ($hasCriteria)
                            <a href="{{ route('shop.index') }}" class="block text-center text-sm text-stone-600 hover:underline">Clear all</a>
                        @endif
                    </div>
                </form>
            </div>

            <section class="mt-6 lg:mt-0" aria-label="Results">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-stone-600" role="status">
                        @if ($products->total() > 0)
                            Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }} {{ str('shoe')->plural($products->total()) }}
                        @else
                            No shoes found
                        @endif
                    </p>
                    <div class="flex items-center gap-2">
                        <label for="sort" class="text-sm text-stone-600">Sort by</label>
                        <select id="sort" name="sort" form="shop-filters" onchange="this.form.submit()"
                                class="rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                            @foreach ($sorts as $value => $label)
                                <option value="{{ $value }}" @selected($filters['sort'] === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <noscript><button type="submit" form="shop-filters" class="rounded-md border border-stone-300 px-3 py-2 text-sm">Sort</button></noscript>
                    </div>
                </div>

                @if ($products->isEmpty())
                    <div class="mt-6 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
                        <p class="font-semibold">No shoes match your filters.</p>
                        <p class="mt-1 text-sm text-stone-600">Try a different size or brand, or widen the price range.</p>
                        <a href="{{ route('shop.index') }}" class="mt-4 inline-block rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Clear filters</a>
                    </div>
                @else
                    <div class="mt-4 grid grid-cols-2 gap-3 sm:gap-6 md:grid-cols-3 xl:grid-cols-4">
                        @foreach ($products as $product)
                            <x-product-card :product="$product" />
                        @endforeach
                    </div>

                    <div class="mt-8">
                        {{ $products->links() }}
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-layouts.shop>
