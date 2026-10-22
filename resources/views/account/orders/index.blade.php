<x-layouts.shop title="My orders">
    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <h1 class="text-3xl font-bold tracking-tight">My orders</h1>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
                <p class="font-semibold">You haven't placed any orders yet.</p>
                <p class="mt-1 text-sm text-stone-600">When you do, you can track them here.</p>
                <a href="{{ route('shop.index') }}" class="mt-5 inline-block rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Start shopping</a>
            </div>
        @else
            <ul class="mt-6 space-y-3">
                @foreach ($orders as $order)
                    <li>
                        <a href="{{ route('account.orders.show', $order->order_number) }}"
                           class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4 hover:border-stone-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 sm:p-5">
                            <div>
                                <p class="font-semibold">{{ $order->order_number }}</p>
                                <p class="text-sm text-stone-600">
                                    {{ $order->created_at->format('j M Y') }} · {{ $order->items_count }} {{ str('item')->plural($order->items_count) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-4">
                                <p class="font-semibold tabular-nums">RM {{ number_format($order->total, 2) }}</p>
                                <x-order-status-badge :status="$order->status" />
                            </div>
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.shop>
