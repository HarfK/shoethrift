<x-layouts.admin title="Reviews">
    <h1 class="text-2xl font-semibold">Reviews</h1>
    <p class="mt-1 text-sm text-stone-600">
        Hide a review to take it out of the shop (and out of the product's rating) while keeping a record.
        Delete only removes it for good, and the customer could then review that pair again, so prefer hiding for abuse.
    </p>

    <form method="GET" action="{{ route('admin.reviews.index') }}" class="mt-6 flex flex-wrap items-end gap-3" role="search">
        <div class="w-full sm:w-72">
            <label for="search" class="mb-1 block text-sm font-medium text-stone-700">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Product, brand or customer"
                   class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
        </div>
        <div>
            <label for="visibility" class="mb-1 block text-sm font-medium text-stone-700">Show</label>
            <select id="visibility" name="visibility" class="block rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All reviews</option>
                <option value="visible" @selected($filters['visibility'] === 'visible')>Visible</option>
                <option value="hidden" @selected($filters['visibility'] === 'hidden')>Hidden</option>
            </select>
        </div>
        <div>
            <label for="rating" class="mb-1 block text-sm font-medium text-stone-700">Rating</label>
            <select id="rating" name="rating" class="block rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Any</option>
                @foreach (range(5, 1) as $stars)
                    <option value="{{ $stars }}" @selected($filters['rating'] === $stars)>{{ $stars }} ★</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Filter</button>
        @if ($filters['search'] !== '' || $filters['visibility'] || $filters['rating'])
            <a href="{{ route('admin.reviews.index') }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Reset</a>
        @endif
    </form>

    <ul class="mt-6 space-y-3">
        @forelse ($reviews as $review)
            <li @class(['rounded-xl border bg-white p-4 shadow-sm', 'border-stone-200' => ! $review->isHidden(), 'border-dashed border-stone-300 bg-stone-50' => $review->isHidden()]) data-review="{{ $review->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-star-rating :value="$review->rating" />
                            <a href="{{ route('shop.show', $review->product->slug) }}#reviews" class="font-semibold hover:underline">{{ $review->product->name }}</a>
                            <span class="text-sm text-stone-500">{{ $review->product->size_label }}</span>
                            @if ($review->isHidden())
                                <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700">Hidden</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-stone-500">
                            {{ $review->user->name }} ({{ $review->user->email }}) ·
                            <a href="{{ route('admin.orders.show', $review->order) }}" class="text-emerald-700 hover:underline">{{ $review->order->order_number }}</a> ·
                            {{ $review->created_at->shopDateTime() }}
                        </p>
                        <p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ $review->comment }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <form method="POST" action="{{ route('admin.reviews.toggle', $review) }}">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-stone-50">{{ $review->isHidden() ? 'Unhide' : 'Hide' }}</button>
                        </form>
                        <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}" onsubmit="return confirm('Delete this review permanently? Hiding keeps a record instead.')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="rounded-md border border-red-300 bg-white px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">Delete</button>
                        </form>
                    </div>
                </div>
            </li>
        @empty
            <li class="rounded-xl border border-stone-200 bg-white px-4 py-12 text-center text-sm text-stone-500">No reviews match.</li>
        @endforelse
    </ul>

    <div class="mt-6">{{ $reviews->links() }}</div>
</x-layouts.admin>
