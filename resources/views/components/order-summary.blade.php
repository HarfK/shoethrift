@props(['order'])

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
