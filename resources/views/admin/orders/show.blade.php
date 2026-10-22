@php($transitions = $order->status->allowedTransitions())

<x-layouts.admin :title="'Order '.$order->order_number">
    <a href="{{ route('admin.orders.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Orders</a>
    <div class="mt-2 mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Order {{ $order->order_number }}</h1>
        <x-order-status-badge :status="$order->status" />
    </div>
    <p class="-mt-4 mb-6 text-sm text-stone-500">Placed {{ $order->created_at->format('j M Y, g:i a') }}</p>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="rounded-xl border border-stone-200 bg-white shadow-sm lg:col-span-2">
            <h2 class="border-b border-stone-200 px-5 py-4 text-base font-semibold">Items</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                        <tr>
                            <th scope="col" class="px-5 py-3">Product</th>
                            <th scope="col" class="px-5 py-3 text-right">Qty</th>
                            <th scope="col" class="px-5 py-3 text-right">Unit price</th>
                            <th scope="col" class="px-5 py-3 text-right">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach ($order->items as $item)
                            <tr class="align-middle">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        @if ($url = $item->product->primaryImage?->url)
                                            <img src="{{ $url }}" alt="" class="size-12 rounded-md object-cover">
                                        @else
                                            <div class="flex size-12 shrink-0 items-center justify-center rounded-md bg-stone-100 text-[10px] text-stone-400">No image</div>
                                        @endif
                                        <div>
                                            <a href="{{ route('admin.products.edit', $item->product) }}" class="font-medium hover:underline">{{ $item->product->name }}</a>
                                            <div class="text-xs text-stone-500">{{ $item->product->brand }} · {{ $item->product->size_label }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ $item->quantity }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">RM {{ number_format($item->price, 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums">RM {{ number_format($item->price * $item->quantity, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-stone-200">
                            <th scope="row" colspan="3" class="px-5 py-4 text-right font-semibold">Total</th>
                            <td class="whitespace-nowrap px-5 py-4 text-right text-base font-semibold tabular-nums">RM {{ number_format($order->total, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </section>

        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Status</h2>
                @if ($transitions === [])
                    <p class="text-sm text-stone-600">This order is {{ strtolower($order->status->label()) }}, so its status can no longer be changed.</p>
                @else
                    <form method="POST" action="{{ route('admin.orders.status', $order) }}"
                          onsubmit="return this.status.value !== 'cancelled' || confirm('Cancel this order? Its items will be returned to stock.')">
                        @csrf
                        @method('PATCH')
                        <x-form.select name="status" label="Change status to" :value="$transitions[0]->value"
                                       :options="collect($transitions)->mapWithKeys(fn ($s) => [$s->value => $s->label()])" />
                        <button type="submit" class="w-full rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Update status</button>
                    </form>
                    <p class="mt-3 text-xs text-stone-500">Orders move pending → paid → shipped → completed, and can be cancelled only before shipping.</p>
                @endif
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Customer</h2>
                <dl class="space-y-1 text-sm">
                    <div><dt class="sr-only">Name</dt><dd class="font-medium">{{ $order->user->name }}</dd></div>
                    <div><dt class="sr-only">Email</dt><dd><a href="mailto:{{ $order->user->email }}" class="text-emerald-700 hover:underline">{{ $order->user->email }}</a></dd></div>
                    <div><dt class="sr-only">Phone</dt><dd class="text-stone-600">{{ $order->user->phone ?: 'No phone number' }}</dd></div>
                </dl>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Shipping address</h2>
                <p class="whitespace-pre-line text-sm text-stone-700">{{ $order->shipping_address }}</p>
            </section>
        </div>
    </div>
</x-layouts.admin>
