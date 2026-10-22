@props(['product'])

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
        <p class="mt-auto pt-2 font-semibold tabular-nums text-stone-900">RM {{ number_format($product->price, 2) }}</p>
    </div>
</a>
