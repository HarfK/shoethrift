@php($hasFilters = $filters['search'] !== '' || $filters['status'] || $filters['payment'])

<x-layouts.admin title="Orders">
    <div>
        <h1 class="text-2xl font-semibold">Orders</h1>
        <p class="mt-1 text-sm text-stone-600">{{ number_format($orders->total()) }} {{ str('order')->plural($orders->total()) }}{{ $hasFilters ? ' match your filters' : '' }}</p>
    </div>

    <form method="GET" action="{{ route('admin.orders.index') }}" class="mt-6 flex flex-wrap items-end gap-3" role="search">
        <div class="w-full sm:w-72">
            <label for="search" class="mb-1 block text-sm font-medium text-stone-700">Search</label>
            <input id="search" name="search" type="search" value="{{ $filters['search'] }}" placeholder="Order number, customer name or email"
                   class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-emerald-600/40">
        </div>
        <div class="w-full sm:w-44">
            <label for="status" class="mb-1 block text-sm font-medium text-stone-700">Status</label>
            <select id="status" name="status" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All statuses</option>
                @foreach ($statuses as $status)
                    <option value="{{ $status->value }}" @selected($filters['status'] === $status->value)>{{ $status->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="w-full sm:w-52">
            <label for="payment" class="mb-1 block text-sm font-medium text-stone-700">Payment</label>
            <select id="payment" name="payment" class="block w-full rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                <option value="">All payment methods</option>
                @foreach ($paymentMethods as $method)
                    <option value="{{ $method->value }}" @selected($filters['payment'] === $method->value)>{{ $method->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex gap-2">
            <button type="submit" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Filter</button>
            @if ($hasFilters)
                <a href="{{ route('admin.orders.index') }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Reset</a>
            @endif
        </div>
    </form>

    <div class="mt-6 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Order</th>
                    <th scope="col" class="px-4 py-3">Customer</th>
                    <th scope="col" class="px-4 py-3">Date</th>
                    <th scope="col" class="px-4 py-3 text-right">Items</th>
                    <th scope="col" class="px-4 py-3 text-right">Total</th>
                    <th scope="col" class="px-4 py-3">Payment</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($orders as $order)
                    <tr class="align-middle">
                        <td class="whitespace-nowrap px-4 py-3">
                            <a href="{{ route('admin.orders.show', $order) }}" class="font-medium text-emerald-700 hover:underline">{{ $order->order_number }}</a>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium">{{ $order->user->name }}</div>
                            <div class="text-xs text-stone-500">{{ $order->user->email }}</div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ $order->created_at->format('j M Y, g:i a') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $order->items_count }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">RM {{ number_format($order->total, 2) }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-stone-600">
                            {{ match ($order->payment_method?->value) { 'online' => 'Online', 'cod' => 'COD', default => '—' } }}
                            @if ($order->needsRefund())<span class="ml-1 rounded bg-red-100 px-1.5 py-0.5 text-xs font-semibold text-red-800">Refund</span>@endif
                        </td>
                        <td class="px-4 py-3"><x-order-status-badge :status="$order->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center text-stone-500">
                            {{ $hasFilters ? 'No orders match your filters.' : 'No orders yet.' }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">
        {{ $orders->links() }}
    </div>
</x-layouts.admin>
