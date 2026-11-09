@php
    $badge = [
        'available' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'sold' => 'bg-stone-100 text-stone-700 ring-stone-500/20',
        'hidden' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    ];
    // Sortable headers: first click ascending, then toggles; the page resets, filters stay.
    $sortUrl = fn (string $column) => route('admin.products.index', array_merge(request()->except(['page', 'sort', 'direction']), [
        'sort' => $column, 'direction' => $sort === $column && $direction === 'asc' ? 'desc' : 'asc',
    ]));
    $columns = [
        'name' => ['Name', ''], 'category' => ['Category', ''], 'brand' => ['Brand', ''], 'price' => ['Price', 'justify-end'],
        'condition' => ['Condition', ''], 'stock' => ['Stock', 'justify-end'], 'status' => ['Status', ''], 'created_at' => ['Date added', ''],
    ];
    $hasFilters = $filters['search'] !== '' || $filters['category'] || $filters['brand'] !== '' || $filters['status']
        || $filters['size_min'] !== null || $filters['size_max'] !== null || $filters['photos'];
@endphp

<x-layouts.admin title="Products">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Products</h1>
            <p class="mt-1 text-sm text-stone-600">{{ number_format($products->total()) }} {{ str('product')->plural($products->total()) }}{{ $hasFilters ? ' match your filters' : '' }}</p>
        </div>
        <a href="{{ route('admin.products.create') }}" class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
            Add product
        </a>
    </div>

    <form method="GET" action="{{ route('admin.products.index') }}" class="mt-6 flex flex-wrap items-end gap-3" role="search">
        <div class="w-full sm:w-64">
            <label for="search" class="mb-1 block text-sm font-medium text-stone-700">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Name or brand"
                   class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-600/40">
        </div>
        <div class="w-full sm:w-48">
            <label for="category" class="mb-1 block text-sm font-medium text-stone-700">Category</label>
            <select id="category" name="category" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-44">
            <label for="brand" class="mb-1 block text-sm font-medium text-stone-700">Brand</label>
            <select id="brand" name="brand" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All brands</option>
                @foreach ($brands as $brand)
                    <option value="{{ $brand }}" @selected($filters['brand'] === $brand)>{{ $brand }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-40">
            <label for="status" class="mb-1 block text-sm font-medium text-stone-700">Status</label>
            <select id="status" name="status" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-40">
            <label for="photos" class="mb-1 block text-sm font-medium text-stone-700">Photos</label>
            <select id="photos" name="photos" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All</option>
                <option value="stock" @selected($filters['photos'] === 'stock')>Stock photos</option>
                <option value="own" @selected($filters['photos'] === 'own')>Own photos</option>
            </select>
        </div>
        @if ($sort !== \App\Http\Controllers\Admin\ProductController::DEFAULT_SORT || $direction !== \App\Http\Controllers\Admin\ProductController::DEFAULT_DIRECTION)
            {{-- Keep the current sort when filtering. --}}
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
        @endif
        @if ($filters['size_min'] !== null || $filters['size_max'] !== null)
            {{-- Size range (set from the Restock page); kept when filtering, removed by Reset. --}}
            <input type="hidden" name="size_min" value="{{ $filters['size_min'] }}">
            <input type="hidden" name="size_max" value="{{ $filters['size_max'] }}">
            <span class="inline-flex items-center rounded-full bg-violet-50 px-3 py-2 text-sm font-medium text-violet-800 ring-1 ring-inset ring-violet-600/20" data-size-filter>
                UK {{ $filters['size_min'] !== null ? rtrim(rtrim(number_format($filters['size_min'], 1), '0'), '.') : '…' }}–{{ $filters['size_max'] !== null ? rtrim(rtrim(number_format($filters['size_max'], 1), '0'), '.') : '…' }}
            </span>
        @endif
        <div class="flex gap-2">
            <button type="submit" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Filter</button>
            @if ($hasFilters)
                <a href="{{ route('admin.products.index') }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Reset</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Image</span></th>
                    @foreach ($columns as $column => [$label, $align])
                        <th scope="col" class="px-4 py-3" aria-sort="{{ $sort === $column ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <a href="{{ $sortUrl($column) }}" data-sort="{{ $column }}"
                               class="group inline-flex w-full items-center gap-1 whitespace-nowrap uppercase hover:text-stone-900 {{ $align }} {{ $sort === $column ? 'text-stone-900' : '' }}">
                                {{ $label }}
                                @if ($sort === $column)
                                    <svg viewBox="0 0 20 20" class="size-3.5" fill="currentColor" aria-hidden="true" data-sort-active="{{ $direction }}">
                                        @if ($direction === 'asc')
                                            <path d="M10 5l5 7H5z"/>
                                        @else
                                            <path d="M10 15l-5-7h10z"/>
                                        @endif
                                    </svg>
                                    <span class="sr-only">(sorted {{ $direction === 'asc' ? 'ascending' : 'descending' }})</span>
                                @else
                                    <svg viewBox="0 0 20 20" class="size-3.5 text-stone-300 group-hover:text-stone-400" fill="currentColor" aria-hidden="true"><path d="M10 3l4 5H6zM10 17l-4-5h8z"/></svg>
                                @endif
                            </a>
                        </th>
                    @endforeach
                    <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($products as $product)
                    <tr class="align-middle">
                        <td class="px-4 py-3">
                            @if ($url = $product->primaryImage?->url)
                                <img src="{{ $url }}" alt="" class="size-12 rounded-md object-cover" loading="lazy">
                            @else
                                <div class="flex size-12 items-center justify-center rounded-md bg-stone-100 text-[10px] text-stone-400">No image</div>
                            @endif
                            @if ($product->uses_stock_photos)
                                <span class="mt-1 block whitespace-nowrap text-center text-[10px] font-medium text-amber-800" data-stock-photo>Stock photo</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.products.edit', $product) }}" class="font-medium hover:underline">{{ $product->name }}</a>
                            <div class="text-xs text-stone-500">{{ $product->size_label }}{{ $product->color ? ' · '.$product->color : '' }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $product->category->name }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $product->brand }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">RM {{ number_format($product->price, 2) }}</td>
                        <td class="whitespace-nowrap px-4 py-3">{{ $product->condition->label() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $product->stock }}</td>
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge[$product->status->value] }}">
                                {{ $product->status->label() }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ $product->created_at->shopDate() }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('admin.products.edit', $product) }}" class="font-medium text-emerald-700 hover:underline">Edit</a>
                            <form method="POST" action="{{ route('admin.products.destroy', $product) }}" class="ml-3 inline"
                                  onsubmit="return confirm(@js('Delete "'.$product->name.'"? This cannot be undone.'))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="font-medium text-red-700 hover:underline">Delete</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="px-4 py-12 text-center text-stone-500">
                            {{ $hasFilters ? 'No products match your filters.' : 'No products yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $products->links() }}
    </div>
</x-layouts.admin>
