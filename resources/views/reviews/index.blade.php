<x-layouts.shop title="Customer reviews">
    <div class="mx-auto max-w-5xl px-4 py-10 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('home') }}" class="hover:text-stone-900">Home</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">Reviews</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">Customer reviews</h1>
        <p class="mt-2 text-stone-600">Every review comes from a customer who bought the pair from {{ config('app.name') }}.</p>

        @if ($summary['count'] > 0)
            <div class="mt-8 grid gap-8 md:grid-cols-[16rem_1fr]">
                <aside class="h-fit rounded-xl border border-stone-200 bg-white p-5">
                    <p class="text-4xl font-bold tabular-nums" data-store-average>{{ number_format($summary['average'], 1) }}<span class="text-lg font-normal text-stone-500"> / 5</span></p>
                    <x-star-rating :value="$summary['average']" size="size-5" class="mt-1" round />
                    <p class="mt-1 text-sm text-stone-600" data-store-count>{{ $summary['count'] }} {{ str('review')->plural($summary['count']) }}</p>
                    @if ($summary['condition'] !== null)
                        <p class="mt-3 rounded-md bg-emerald-50 px-3 py-2 text-sm text-emerald-900" data-condition-accuracy>
                            Condition accuracy <strong>{{ number_format($summary['condition'], 1) }}/5</strong>
                            <span class="block text-xs text-emerald-800">How well pairs matched their description</span>
                        </p>
                    @endif
                    <dl class="mt-4 space-y-1.5 text-sm" data-rating-breakdown>
                        @foreach ($breakdown as $stars => $count)
                            <div class="flex items-center gap-2">
                                <dt class="w-10 shrink-0 text-stone-600">{{ $stars }} ★</dt>
                                <dd class="h-2 flex-1 overflow-hidden rounded-full bg-stone-200" aria-hidden="true">
                                    <span class="block h-full bg-amber-500" style="width: {{ round($count / $summary['count'] * 100) }}%"></span>
                                </dd>
                                <dd class="w-8 text-right tabular-nums text-stone-600">{{ $count }}<span class="sr-only"> {{ str('review')->plural($count) }} with {{ $stars }} {{ str('star')->plural($stars) }}</span></dd>
                            </div>
                        @endforeach
                    </dl>
                    <p class="mt-3 text-xs text-stone-500">4★ includes 4.0 and 4.5, and so on.</p>
                </aside>

                <div>
                    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
                        @foreach ($reviews as $review)
                            <li id="review-{{ $review->id }}" class="scroll-mt-24 p-5" data-review="{{ $review->id }}">
                                <a href="{{ route('shop.show', $review->product->slug) }}#reviews" class="flex items-center gap-3 hover:underline">
                                    @if ($url = $review->product->primaryImage?->url)
                                        <img src="{{ $url }}" alt="" class="size-10 rounded-md object-cover">
                                    @else
                                        <x-shoe-placeholder class="size-10 rounded-md" />
                                    @endif
                                    <span class="text-sm font-semibold">{{ $review->product->name }} <span class="font-normal text-stone-500">{{ $review->product->size_label }}</span></span>
                                </a>
                                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
                                    <x-star-rating :value="$review->rating" />
                                    <span class="text-sm font-semibold">{{ $review->reviewerName() }}</span>
                                    <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Verified purchase</span>
                                </div>
                                <p class="mt-1 text-xs text-stone-500">
                                    {{ $review->created_at->shopDate() }}
                                    @if ($review->edited_at) · Edited {{ $review->edited_at->shopDate() }}@endif
                                    @if ($review->condition_rating) · Condition matched: {{ $review->condition_rating }}/5 @endif
                                </p>
                                <p class="mt-2 whitespace-pre-line text-sm text-stone-800">{{ $review->comment }}</p>
                                <x-review-media :review="$review" />
                            </li>
                        @endforeach
                    </ul>
                    <div class="mt-4">{{ $reviews->links() }}</div>
                </div>
            </div>
        @else
            <p class="mt-8 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center text-stone-600">No reviews yet. They appear here once customers review their pairs.</p>
        @endif
    </div>
</x-layouts.shop>
