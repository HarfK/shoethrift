@props(['order', 'reviewLinks' => false])

@php
    // On the customer's order page: "Write a review" per eligible pair, or the review already written.
    $reviewableIds = $reviewLinks ? $order->reviewableItems()->pluck('id')->all() : [];
    $reviewsByProduct = $reviewLinks ? $order->reviews->keyBy('product_id') : collect();
@endphp

{{-- Items with the prices actually paid (order_items.price), plus subtotal, shipping and total. --}}
<section {{ $attributes->class('rounded-xl border border-stone-200 bg-white') }} aria-label="Items">
    <ul class="divide-y divide-stone-100">
        @foreach ($order->items as $item)
            <li class="flex gap-4 p-4">
                @if ($url = $item->product->primaryImage?->url)
                    <img src="{{ $url }}" alt="" class="size-16 shrink-0 rounded-lg object-cover sm:size-20">
                @else
                    <x-shoe-placeholder class="size-16 shrink-0 rounded-lg sm:size-20" />
                @endif
                <div class="flex min-w-0 flex-1 flex-wrap justify-between gap-x-4 gap-y-1">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $item->product->brand }}</p>
                        <p class="font-semibold">{{ $item->product->name }}</p>
                        <p class="text-sm text-stone-600">{{ $item->product->size_label }} · Qty {{ $item->quantity }} × RM {{ number_format($item->price, 2) }}</p>
                        @if ($review = $reviewsByProduct->get($item->product_id))
                            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-stone-600">
                                <span>Your review:</span> <x-star-rating :value="$review->rating" />
                                @if ($review->isHidden())
                                    <span class="text-xs text-stone-500">(hidden by ShoeThrift)</span>
                                @endif
                            </p>
                        @elseif (in_array($item->id, $reviewableIds, true))
                            <a href="{{ route('account.reviews.create', [$order->order_number, $item->id]) }}"
                               class="mt-1 inline-block text-sm font-semibold text-emerald-700 hover:underline">Write a review</a>
                        @endif
                    </div>
                    <p class="font-semibold tabular-nums">RM {{ number_format($item->price * $item->quantity, 2) }}</p>
                </div>
            </li>
        @endforeach
    </ul>
    <dl class="space-y-1.5 border-t border-stone-200 p-4 text-sm">
        <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd class="tabular-nums">RM {{ number_format($order->subtotal, 2) }}</dd></div>
        <div class="flex justify-between"><dt class="text-stone-600">Shipping</dt><dd class="tabular-nums">RM {{ number_format($order->shipping_fee, 2) }}</dd></div>
        <div class="flex justify-between pt-1.5 text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">RM {{ number_format($order->total, 2) }}</dd></div>
    </dl>
</section>
