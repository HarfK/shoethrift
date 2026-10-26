{{-- $reasons: optional "why recommended" labels (SimilarProducts), shown as a line under the name. --}}
@props(['product', 'reasons' => []])

<a href="{{ route('shop.show', $product->slug) }}"
   {{ $attributes->class('group flex flex-col overflow-hidden rounded-xl border border-stone-200 bg-white transition hover:-translate-y-0.5 hover:shadow-md focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600') }}>
    @if ($url = $product->primaryImage?->url)
        <img src="{{ $url }}" alt="{{ $product->name }}" class="aspect-square w-full object-cover" loading="lazy">
    @else
        <x-shoe-placeholder class="aspect-square w-full" />
    @endif

    <div class="flex flex-1 flex-col p-3 sm:p-4">
        <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $product->brand }}</p>
        <h3 class="mt-0.5 line-clamp-2 text-sm font-semibold text-stone-900 group-hover:underline sm:text-base">{{ $product->name }}</h3>
        <p class="mt-1 text-xs text-stone-600 sm:text-sm">{{ $product->size_label }} · {{ $product->condition->label() }}</p>
        @if ($reasons)
            <p class="mt-1 text-xs font-medium text-emerald-700" data-reasons>{{ implode(' · ', $reasons) }}</p>
        @endif
        {{-- rating_avg / reviews_count come from Product::withRatingSummary() --}}
        @if ($product->reviews_count)
            <x-star-rating :value="$product->rating_avg" :count="$product->reviews_count" size="size-3.5" class="mt-1" />
        @endif
        <p class="mt-auto pt-2 font-semibold tabular-nums text-stone-900">RM {{ number_format($product->price, 2) }}</p>
    </div>
</a>
