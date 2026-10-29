@use('App\Enums\PaymentMethod')
@use('App\Enums\OrderStatus')
@php($transitions = $order->allowedTransitions())

<x-layouts.admin :title="'Order '.$order->order_number">
    <a href="{{ route('admin.orders.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Orders</a>
    <div class="mt-2 mb-6 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Order {{ $order->order_number }}</h1>
        <x-order-status-badge :order="$order" />
    </div>
    <p class="-mt-4 mb-6 text-sm text-stone-500">
        Placed {{ $order->created_at->shopDateTime() }}
        @if ($order->isFailedDelivery())
            · <span class="text-red-700">Delivery failed {{ $order->delivery_failed_at->shopDateTime() }}</span>
        @endif
    </p>

    @if ($order->needsRefund())
        <div class="mb-6 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
            <strong>Payment received after the order was {{ strtolower($order->status->label()) }}, refund needed.</strong>
            HitPay confirmed a payment of RM {{ number_format($order->total, 2) }} on {{ $order->paid_at->shopDateTime() }}
            (payment request {{ $order->gateway_reference }}). Refund it from the HitPay dashboard.
        </div>
    @endif

    @if ($order->returnRequest)
        <div class="mb-6 flex flex-wrap items-center justify-between gap-2 rounded-md border border-violet-200 bg-violet-50 px-4 py-3 text-sm text-violet-900">
            <span>
                <strong>{{ $order->returnRequest->status->label() }}</strong>
                @if ((float) $order->refunded_amount > 0) · RM {{ number_format($order->refunded_amount, 2) }} refunded @endif
            </span>
            <a href="{{ route('admin.returns.show', $order->returnRequest) }}" class="font-semibold underline">View return</a>
        </div>
    @endif

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
                    <tfoot class="text-sm">
                        <tr class="border-t border-stone-200">
                            <th scope="row" colspan="3" class="px-5 pt-4 pb-1 text-right font-normal text-stone-600">Subtotal</th>
                            <td class="whitespace-nowrap px-5 pt-4 pb-1 text-right tabular-nums">RM {{ number_format($order->subtotal, 2) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="3" class="px-5 py-1 text-right font-normal text-stone-600">Shipping</th>
                            <td class="whitespace-nowrap px-5 py-1 text-right tabular-nums">RM {{ number_format($order->shipping_fee, 2) }}</td>
                        </tr>
                        <tr>
                            <th scope="row" colspan="3" class="px-5 pt-1 pb-4 text-right font-semibold">Total</th>
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
                    <p class="text-sm text-stone-600">This order is {{ strtolower($order->statusLabel()) }}, so its status can no longer be changed.</p>
                @else
                    {{-- One button per allowed step; each posts the target status. Cancelling asks first. --}}
                    <div class="flex flex-col gap-2">
                        @foreach ($transitions as $to)
                            {{-- Inline @php only: this template already uses @php(), which can't be mixed with @php blocks. --}}
                            @php($isCancel = $to === OrderStatus::Cancelled)
                            @php($confirm = ! $isCancel ? null : ($order->cancellingMeansFailedDelivery() ? 'Mark this as a failed delivery? The order will be cancelled, its items returned to stock, and it counts against the customer’s Cash on Delivery record.' : 'Cancel this order? Its items will be returned to stock.'))
                            <form method="POST" action="{{ route('admin.orders.status', $order) }}"
                                  @if ($confirm) onsubmit="return confirm(@js($confirm))" @endif>
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="status" value="{{ $to->value }}">
                                <button type="submit" @class([
                                    'w-full rounded-md px-4 py-2 text-sm font-semibold',
                                    'border border-red-300 bg-white text-red-700 hover:bg-red-50' => $isCancel,
                                    'bg-emerald-700 text-white hover:bg-emerald-800' => ! $isCancel,
                                ])>{{ $order->actionLabel($to) }}</button>
                            </form>
                        @endforeach
                    </div>
                    <p class="mt-3 text-xs text-stone-500">
                        @if ($order->payment_method === PaymentMethod::Online)
                            Online orders become <strong>paid</strong> only when HitPay confirms the payment. Then paid → shipped → completed.
                        @elseif ($order->payment_method === PaymentMethod::CashOnDelivery)
                            Cash on Delivery: pending → shipped → delivered → cash collected. "Failed delivery" (after shipping) cancels the order, restocks it and counts against the customer.
                        @else
                            Orders move pending → paid → shipped → completed, and can be cancelled only before shipping.
                        @endif
                    </p>
                @endif
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Payment</h2>
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-stone-500">Method</dt>
                        <dd class="font-medium">{{ $order->payment_method?->label() ?? 'Not recorded (older order)' }}</dd>
                    </div>
                    <div>
                        <dt class="text-stone-500">Paid</dt>
                        <dd>{{ $order->paid_at ? $order->paid_at->shopDateTime() : 'Not paid yet' }}</dd>
                    </div>
                    @if ($order->payment_method === PaymentMethod::Online)
                        <div>
                            <dt class="text-stone-500">HitPay payment request</dt>
                            <dd class="break-all font-mono text-xs">{{ $order->gateway_reference ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-stone-500">HitPay payment ID</dt>
                            <dd class="break-all font-mono text-xs">{{ $order->gateway_payment_id ?? '—' }}</dd>
                            @if ($order->isSimulatedPayment())
                                <dd class="mt-1 inline-block rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-900">Simulated test payment (local only), not real money</dd>
                            @endif
                        </div>
                        @if (! $order->paid_at && $order->payment_expires_at)
                            <div>
                                <dt class="text-stone-500">Payment deadline</dt>
                                <dd>{{ $order->payment_expires_at->shopDateTime() }}</dd>
                            </div>
                        @endif
                    @endif
                </dl>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Customer</h2>
                <dl class="space-y-1 text-sm">
                    <div><dt class="sr-only">Name</dt><dd class="font-medium">{{ $order->user->name }}</dd></div>
                    <div><dt class="sr-only">Email</dt><dd><a href="mailto:{{ $order->user->email }}" class="text-emerald-700 hover:underline">{{ $order->user->email }}</a></dd></div>
                    <div class="pt-1"><dt class="inline text-stone-500">Account phone:</dt> <dd class="inline text-stone-700">{{ $order->user->phone ?: 'Not set' }}@if ($order->user->hasVerifiedPhone()) <span class="text-xs font-medium text-emerald-700">✓ verified</span>@endif</dd></div>
                    @php($failedCod = $order->user->failedCodCount())
                    <div class="pt-1">
                        <dt class="inline text-stone-500">Failed COD orders:</dt>
                        <dd class="inline">
                            <span @class(['font-semibold text-red-700' => $failedCod > 0, 'text-stone-700' => $failedCod === 0])>{{ $failedCod }}</span>
                            @if ($order->user->isCodBlocked())
                                <span class="ml-1 inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-600/20">COD blocked</span>
                            @endif
                        </dd>
                    </div>
                    <div class="pt-1" data-expired-holds>
                        <dt class="inline text-stone-500">Expired cart holds ({{ config('shop.cart.expired_window_days') }} days):</dt>
                        <dd class="inline">
                            <span @class(['font-semibold text-red-700' => $order->user->hasRepeatedExpiredHolds(), 'text-stone-700' => ! $order->user->hasRepeatedExpiredHolds()])>{{ $order->user->expiredHoldCount() }}</span>
                            @if ($order->user->hasRepeatedExpiredHolds())
                                <span class="ml-1 inline-flex rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-600/20">Repeated</span>
                            @endif
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Ship to</h2>
                @if ($order->shipping_name)
                    <p class="text-sm font-medium">{{ $order->shipping_name }}</p>
                @endif
                <p class="text-sm text-stone-600">Recipient phone: {{ $order->shipping_phone ?: 'Not given' }}</p>
                <p class="mt-2 whitespace-pre-line text-sm text-stone-700">{{ $order->shipping_address }}</p>
            </section>
        </div>
    </div>
</x-layouts.admin>
