{{--
    For product pages without reviews of their own: the store rating (→ /reviews) and
    "What buyers say about <brand or category>" (ReviewStats::relatedFor()). Reviewers show as
    "First L." with "Verified purchase"; only id + name of the reviewer is ever loaded.
--}}
@props(['store', 'related'])

<div {{ $attributes->class('space-y-6') }}>
    @if ($store && $store['count'] > 0)
        <a href="{{ route('reviews.index') }}" class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-xl border border-stone-200 bg-white px-4 py-3 text-sm hover:border-stone-400" data-store-rating>
            <span class="font-semibold">{{ config('app.name') }} store rating</span>
            <x-star-rating :value="$store['average']" round />
            <span><strong>{{ number_format($store['average'], 1) }}</strong> from {{ $store['count'] }} {{ str('review')->plural($store['count']) }}</span>
            @if ($store['condition'] !== null)
                <span class="text-stone-600">· Condition accuracy {{ number_format($store['condition'], 1) }}/5</span>
            @endif
            <span class="ml-auto font-medium text-emerald-700">Read all reviews &rarr;</span>
        </a>
    @endif

    @if ($related)
        <section class="rounded-xl border border-stone-200 bg-white p-5" aria-labelledby="related-reviews-heading" data-related-reviews="{{ $related['scope'] }}">
            <h3 id="related-reviews-heading" class="text-lg font-semibold">What buyers say about {{ $related['label'] }}</h3>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-stone-600">
                <x-star-rating :value="$related['stats']['average']" round />
                <span><strong class="text-stone-900">{{ number_format($related['stats']['average'], 1) }}</strong> from {{ $related['stats']['count'] }} {{ str('review')->plural($related['stats']['count']) }}
                    of other {{ $related['scope'] === 'brand' ? $related['label'] : strtolower($related['label']) }} pairs</span>
                @if ($related['stats']['condition'] !== null)
                    <span>· Condition accuracy {{ number_format($related['stats']['condition'], 1) }}/5</span>
                @endif
            </p>

            <ul class="mt-4 divide-y divide-stone-100">
                @foreach ($related['reviews'] as $review)
                    <li class="py-3 first:pt-0" data-related-review="{{ $review->id }}">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                            <x-star-rating :value="$review->rating" />
                            <span class="text-sm font-semibold">{{ $review->reviewerName() }}</span>
                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 text-xs font-medium text-emerald-800">Verified purchase</span>
                        </div>
                        <p class="mt-1 text-xs text-stone-500">
                            {{ $review->created_at->shopDate() }} · on
                            <a href="{{ route('shop.show', $review->product->slug) }}#reviews" class="font-medium text-emerald-700 hover:underline">{{ $review->product->name }} ({{ $review->product->size_label }})</a>
                            @if ($review->condition_rating) · Condition matched: {{ $review->condition_rating }}/5 @endif
                        </p>
                        <p class="mt-1 line-clamp-3 text-sm text-stone-800">{{ $review->comment }}</p>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif
</div>
