<x-layouts.admin title="Reviews">
    <h1 class="text-2xl font-semibold">Reviews</h1>
    <p class="mt-1 text-sm text-stone-600">
        Hide a review to take it out of the shop (and out of the product's rating) while keeping a record.
        Delete only removes it for good, and the customer could then review that pair again, so prefer hiding for abuse.
    </p>

    <a href="{{ route('admin.reviews.index', ['visibility' => 'flagged']) }}"
       @class(['mt-4 inline-flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium', 'bg-amber-600 text-white' => $filters['visibility'] === 'flagged', 'border border-amber-300 bg-amber-50 text-amber-900 hover:bg-amber-100' => $filters['visibility'] !== 'flagged'])>
        Flagged for review <span class="rounded-full bg-white/80 px-1.5 text-xs font-semibold text-amber-900">{{ $flaggedCount }}</span>
    </a>

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
                <option value="flagged" @selected($filters['visibility'] === 'flagged')>Flagged</option>
            </select>
        </div>
        <div>
            <label for="rating" class="mb-1 block text-sm font-medium text-stone-700">Rating</label>
            <select id="rating" name="rating" class="block rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">Any</option>
                @foreach (range(5, 1) as $stars)
                    <option value="{{ $stars }}" @selected($filters['rating'] === $stars)>{{ $stars === 1 ? '0.5 to 1.5' : $stars.($stars < 5 ? ' to '.$stars.'.5' : '') }} ★</option>
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
                            <span class="text-sm tabular-nums text-stone-600">{{ rtrim(rtrim((string) $review->rating, '0'), '.') }}</span>
                            @if ($review->isHidden())
                                <span class="rounded-full bg-stone-200 px-2 py-0.5 text-xs font-medium text-stone-700">Hidden</span>
                            @endif
                            @if ($review->edited_at)
                                <span class="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-800">Edited {{ $review->edited_at->shopDate() }}</span>
                            @endif
                        </div>
                        <p class="mt-1 text-xs text-stone-500">
                            {{ $review->user->name }} ({{ $review->user->email }}) ·
                            <a href="{{ route('admin.orders.show', $review->order) }}" class="text-emerald-700 hover:underline">{{ $review->order->order_number }}</a> ·
                            {{ $review->created_at->shopDateTime() }}
                        </p>
                        <p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ $review->comment }}</p>

                        @if ($review->isFlagged())
                            <div class="mt-3 flex flex-wrap items-center gap-2 rounded-md bg-amber-50 px-3 py-2 text-xs text-amber-900" data-flag-reasons>
                                <span class="font-semibold">Flagged:</span>
                                @foreach ($review->flag_reasons ?? [] as $reason)
                                    <span class="rounded bg-amber-100 px-1.5 py-0.5">{{ \App\Models\Review::FLAG_LABELS[$reason] ?? $reason }}</span>
                                @endforeach
                                <form method="POST" action="{{ route('admin.reviews.checked', $review) }}" class="ml-auto">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="rounded-md bg-white px-2 py-1 font-semibold text-amber-900 ring-1 ring-amber-300 hover:bg-amber-100">Mark as checked</button>
                                </form>
                            </div>
                        @endif

                        @if ($review->media->isNotEmpty())
                            <ul class="mt-3 flex flex-wrap gap-3" aria-label="Photos and video">
                                @foreach ($review->media as $media)
                                    <li @class(['w-32 rounded-md border p-1.5 text-xs', 'border-stone-200' => ! $media->isHidden(), 'border-dashed border-stone-300 bg-stone-50' => $media->isHidden()]) data-media="{{ $media->id }}">
                                        @if ($media->isHidden())
                                            <div class="flex aspect-square items-center justify-center rounded bg-stone-100 text-center text-stone-500">{{ $media->isVideo() ? 'Video' : 'Photo' }} hidden</div>
                                        @elseif ($media->isVideo())
                                            <video src="{{ $media->url() }}" controls preload="metadata" class="aspect-square w-full rounded bg-black object-cover"></video>
                                        @else
                                            <a href="{{ $media->url() }}" target="_blank" rel="noopener"><img src="{{ $media->url() }}" alt="Customer photo" class="aspect-square w-full rounded object-cover"></a>
                                        @endif
                                        <p class="mt-1 text-stone-500">
                                            {{ $media->isVideo() ? 'Video' : 'Photo' }} · {{ number_format($media->size / 1048576, 1) }} MB
                                            @if ($media->isVideo()) · {{ $media->duration_seconds !== null ? round((float) $media->duration_seconds).'s' : 'length unknown' }}@endif
                                        </p>
                                        <div class="mt-1 flex gap-1">
                                            <form method="POST" action="{{ route('admin.review-media.toggle', $media) }}">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="rounded border border-stone-300 bg-white px-1.5 py-0.5 hover:bg-stone-50">{{ $media->isHidden() ? 'Unhide' : 'Hide' }}</button>
                                            </form>
                                            <form method="POST" action="{{ route('admin.review-media.destroy', $media) }}" onsubmit="return confirm('Delete this file permanently?')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="rounded border border-red-300 bg-white px-1.5 py-0.5 text-red-700 hover:bg-red-50">Delete</button>
                                            </form>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
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
