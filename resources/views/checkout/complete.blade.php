<x-layouts.shop :title="'Order '.$order->order_number">
    <div class="mx-auto max-w-3xl px-4 py-10 sm:px-6">
        <div class="text-center">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                <svg viewBox="0 0 24 24" class="size-7" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M5 12l5 5L20 7"/></svg>
            </div>
            <h1 class="mt-4 text-3xl font-bold tracking-tight">Thank you! Your order has been placed.</h1>
            <p class="mt-2 text-stone-600">Order number <span class="font-semibold text-stone-900">{{ $order->order_number }}</span></p>
        </div>

        @if ($order->payment_method === \App\Enums\PaymentMethod::Online && ! $order->paid_at)
            <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                <p><strong>Awaiting payment.</strong> <a href="{{ route('account.orders.show', $order->order_number) }}" class="font-semibold underline">Complete your payment</a> within {{ config('services.hitpay.payment_window_minutes') }} minutes to keep these pairs.</p>
            </div>
        @else
            <div class="mt-8 rounded-xl border border-stone-200 bg-white p-4 text-sm text-stone-700">
                <p><strong>Cash on Delivery.</strong> Please have RM {{ number_format($order->total, 2) }} ready when your shoes arrive.</p>
            </div>
        @endif

        <x-order-summary :order="$order" class="mt-6" />

        <section class="mt-6 rounded-xl border border-stone-200 bg-white p-4 text-sm">
            <h2 class="font-semibold">Shipping to</h2>
            <p class="mt-2">{{ $order->shipping_name }} · {{ $order->shipping_phone }}</p>
            <p class="mt-1 whitespace-pre-line text-stone-700">{{ $order->shipping_address }}</p>
        </section>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('account.orders.show', $order->order_number) }}" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">View my order</a>
            <a href="{{ route('shop.index') }}" class="rounded-md border border-stone-300 bg-white px-5 py-2.5 text-sm font-semibold hover:bg-stone-50">Continue shopping</a>
        </div>
    </div>
</x-layouts.shop>
