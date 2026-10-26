<x-layouts.account title="My orders">
    <div>
        <h1 class="text-2xl font-bold tracking-tight">My orders</h1>

        @if ($orders->isEmpty())
            <div class="mt-8 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
                <p class="font-semibold">You haven't placed any orders yet.</p>
                <p class="mt-1 text-sm text-stone-600">When you do, you can track them here.</p>
                <a href="{{ route('shop.index') }}" class="mt-5 inline-block rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Start shopping</a>
            </div>
        @else
            <ul class="mt-6 space-y-3">
                @foreach ($orders as $order)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4 hover:border-stone-400 sm:p-5">
                        <div>
                            <a href="{{ route('account.orders.show', $order->order_number) }}"
                               class="font-semibold hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">{{ $order->order_number }}</a>
                            <p class="text-sm text-stone-600">
                                {{ $order->created_at->shopDate() }} · {{ $order->items_count }} {{ str('item')->plural($order->items_count) }}
                            </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-4">
                            <p class="font-semibold tabular-nums">RM {{ number_format($order->total, 2) }}</p>
                            <x-order-status-badge :order="$order" />
                            <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm font-medium text-emerald-700 hover:underline">View</a>
                            @if ($order->hasReceipt())
                                <a href="{{ route('account.orders.receipt', $order->order_number) }}" class="text-sm font-medium text-emerald-700 hover:underline">Receipt</a>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">{{ $orders->links() }}</div>
        @endif
    </div>
</x-layouts.account>
