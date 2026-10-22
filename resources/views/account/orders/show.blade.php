<x-layouts.shop :title="'Order '.$order->order_number">
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <a href="{{ route('account.orders.index') }}" class="text-sm text-stone-600 hover:underline">&larr; My orders</a>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Order {{ $order->order_number }}</h1>
            <x-order-status-badge :status="$order->status" />
        </div>
        <p class="mt-1 text-sm text-stone-600">Placed {{ $order->created_at->format('j M Y, g:i a') }}</p>

        <x-order-progress :status="$order->status" class="mt-6" />

        <div class="mt-6 grid gap-6 md:grid-cols-[1fr_16rem]">
            <x-order-summary :order="$order" />

            <section class="h-fit rounded-xl border border-stone-200 bg-white p-4 text-sm">
                <h2 class="font-semibold">Shipping to</h2>
                <p class="mt-2">{{ $order->shipping_name ?? $order->user->name }}</p>
                @if ($order->shipping_phone)
                    <p class="text-stone-600">{{ $order->shipping_phone }}</p>
                @endif
                <p class="mt-2 whitespace-pre-line text-stone-700">{{ $order->shipping_address }}</p>
            </section>
        </div>
    </div>
</x-layouts.shop>
