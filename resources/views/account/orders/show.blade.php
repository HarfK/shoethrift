@use('App\Enums\PaymentMethod')
@use('App\Enums\OrderStatus')

{{--
    Order status page, also HitPay's return page. While an online payment is pending it refreshes every
    few seconds (each load asks HitPay's API, throttled), then stops and offers "Pay now" / "Check again".
--}}
<x-layouts.shop :title="'Order '.$order->order_number">
    @if ($autoRefresh)
        @push('head')
            <meta http-equiv="refresh" content="{{ \App\Http\Controllers\AccountOrderController::REFRESH_SECONDS }};url={{ $nextCheckUrl }}">
        @endpush
    @endif

    <div class="mx-auto max-w-4xl px-4 py-8 sm:px-6">
        <a href="{{ route('account.orders.index') }}" class="text-sm text-stone-600 hover:underline">&larr; My orders</a>

        <div class="mt-2 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight sm:text-3xl">Order {{ $order->order_number }}</h1>
            <x-order-status-badge :order="$order" />
        </div>
        <p class="mt-1 text-sm text-stone-600">Placed {{ $order->created_at->shopDateTime() }}</p>

        {{-- Payment / order state: Pending, Paid, Failed or Cancelled --}}
        @php($canPayOnline = $order->isAwaitingOnlinePayment() && $order->payment_expires_at?->isFuture())

        @if ($order->needsRefund())
            <div class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900" role="status">
                <p class="font-semibold">Refund on its way</p>
                <p class="mt-1">We received your payment after this order was closed. We'll refund it to you. No action is needed.</p>
            </div>
        @elseif ($order->status === OrderStatus::Failed)
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="status">
                <p class="text-base font-semibold">Payment failed</p>
                <p class="mt-1">Your payment didn't go through, so this order was closed and the pairs went back to the shop. You haven't been charged.</p>
                <a href="{{ route('shop.index') }}" class="mt-3 inline-block rounded-md bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">Shop again</a>
            </div>
        @elseif ($order->status === OrderStatus::Refunded)
            <div class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
                <p class="text-base font-semibold">Refunded</p>
                <p class="mt-1">This order was refunded in full (RM {{ number_format($order->refunded_amount, 2) }}).</p>
            </div>
        @elseif ($order->isFailedDelivery())
            <div class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900" role="status">
                <p class="text-base font-semibold">Failed delivery</p>
                <p class="mt-1">Delivery was unsuccessful, so this order was cancelled and the pairs went back to the shop. You haven't been charged.</p>
            </div>
        @elseif ($order->status === OrderStatus::Cancelled)
            <div class="mt-6 rounded-xl border border-stone-300 bg-stone-100 p-4 text-sm text-stone-800" role="status">
                <p class="text-base font-semibold">Cancelled</p>
                <p class="mt-1">This order was cancelled and its pairs were returned to the shop.</p>
            </div>
        @elseif ($order->paid_at)
            <div class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" role="status">
                <div>
                    <p class="text-base font-semibold">{{ $order->isCashOnDelivery() ? 'Cash collected' : 'Paid' }}</p>
                    <p class="mt-1">Payment received on {{ $order->paid_at->shopDateTime() }}.@if ($order->isSimulatedPayment()) <strong>(Simulated test payment)</strong>@endif</p>
                </div>
                @if ($order->hasReceipt())
                    <a href="{{ route('account.orders.receipt', $order->order_number) }}" class="rounded-md bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">View receipt</a>
                @endif
            </div>
        @elseif ($order->payment_method === PaymentMethod::CashOnDelivery)
            <div class="mt-6 rounded-xl border border-stone-200 bg-white p-4 text-sm text-stone-700" role="status">
                <p class="text-base font-semibold">{{ $order->statusLabel() }}, Cash on Delivery</p>
                <p class="mt-1">
                    @if ($order->status === OrderStatus::Delivered)
                        Your shoes were delivered. We're confirming your cash payment of RM {{ number_format($order->total, 2) }}.
                    @elseif ($order->status === OrderStatus::Shipped)
                        Your shoes are on the way. Please have RM {{ number_format($order->total, 2) }} ready when they arrive.
                    @else
                        Please have RM {{ number_format($order->total, 2) }} ready when your shoes arrive.
                    @endif
                </p>
            </div>
        @elseif ($order->isAwaitingOnlinePayment())
            <div class="mt-6 rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950" role="status">
                @if ($stillWaiting)
                    <p class="text-base font-semibold">Pending, still waiting for confirmation</p>
                    <p class="mt-1">We haven't received confirmation of your payment yet. If you've paid, check again in a moment. If not, you can pay now.</p>
                @else
                    <p class="flex items-center gap-2 text-base font-semibold">
                        <svg class="size-4 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-opacity=".25" stroke-width="3"/><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>
                        Pending, waiting for your payment
                    </p>
                    <p class="mt-1">This page checks with HitPay automatically. If you're still paying, finish in the HitPay tab.</p>
                @endif

                @if ($checkUnavailable)
                    <p class="mt-2 text-amber-800">We couldn't reach HitPay to check just now. We'll keep trying.</p>
                @endif

                @if ($order->payment_expires_at)
                    <p class="mt-2 text-sky-800">Pay by <strong>{{ $order->payment_expires_at->shopTime() }}</strong> to keep your pairs.</p>
                @endif

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($canPayOnline)
                        <form method="POST" action="{{ route('account.orders.pay', $order->order_number) }}">
                            @csrf
                            <button type="submit" class="rounded-md bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">
                                {{ $order->payment_url ? 'Pay now' : 'Try again' }}
                            </button>
                        </form>
                    @endif
                    @if ($stillWaiting)
                        <form method="POST" action="{{ route('account.orders.check', $order->order_number) }}">
                            @csrf
                            <button type="submit" class="rounded-md border border-stone-300 bg-white px-5 py-2.5 font-semibold text-stone-900 hover:bg-stone-50">Check again</button>
                        </form>
                    @endif
                </div>
            </div>

            @if (app()->environment('local'))
                <div class="mt-4 rounded-xl border-2 border-dashed border-amber-400 bg-amber-50 p-4 text-sm text-amber-900">
                    <p class="font-semibold">🧪 Test tool (local only). Not a real payment.</p>
                    <p class="mt-1">Marks this order as paid without HitPay, for testing on your machine. Hidden and disabled unless <code>APP_ENV=local</code>.</p>
                    <form method="POST" action="{{ route('account.orders.simulate', $order->order_number) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="rounded-md border border-amber-500 bg-white px-4 py-2 font-semibold text-amber-900 hover:bg-amber-100">Simulate payment success</button>
                    </form>
                </div>
            @endif
        @endif

        <x-order-progress :status="$order->status" :payment-method="$order->payment_method" class="mt-6" />

        {{-- Returns: status of an existing request, or the button while the return window is open. --}}
        @if ($return = $order->returnRequest)
            @php($returnTone = match ($return->status) {
                \App\Enums\ReturnStatus::Rejected => 'border-stone-300 bg-stone-100 text-stone-800',
                \App\Enums\ReturnStatus::Refunded => 'border-emerald-200 bg-emerald-50 text-emerald-900',
                default => 'border-violet-200 bg-violet-50 text-violet-950',
            })
            <section class="mt-6 rounded-xl border p-4 text-sm {{ $returnTone }}" aria-labelledby="return-heading">
                <h2 id="return-heading" class="text-base font-semibold">{{ $return->status->label() }}</h2>
                <p class="mt-1">Requested {{ $return->created_at->shopDateTime() }} · {{ $return->reason->label() }}</p>
                @switch($return->status)
                    @case(\App\Enums\ReturnStatus::Requested)
                        <p class="mt-2">We're reviewing your request and will reply within {{ config('shop.support.reply_within') }}.</p>
                        @break
                    @case(\App\Enums\ReturnStatus::Approved)
                        <p class="mt-2">Please send the pairs back to:</p>
                        <p class="mt-1 whitespace-pre-line rounded-md bg-white/70 p-3 font-medium">{{ config('shop.returns.address') }}</p>
                        <p class="mt-2">Write your order number <strong>{{ $order->order_number }}</strong> on the parcel. We'll refund you once they arrive.</p>
                        @break
                    @case(\App\Enums\ReturnStatus::Rejected)
                        <p class="mt-2">We couldn't accept this return.</p>
                        @break
                    @case(\App\Enums\ReturnStatus::Refunded)
                        <p class="mt-2">RM {{ number_format($return->refund_amount, 2) }} was refunded on {{ $return->refunded_at->shopDateTime() }}.
                            @if ($return->refund_method === \App\Enums\RefundMethod::HitPay) It goes back to your original payment method; your bank may take 5 to 10 working days to show it. @endif</p>
                        @break
                @endswitch
                @if ($return->admin_note)
                    <p class="mt-2"><span class="font-semibold">Note from ShoeThrift:</span> {{ $return->admin_note }}</p>
                @endif
            </section>
        @elseif ($order->canRequestReturn())
            <section class="mt-6 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-stone-200 bg-white p-4 text-sm">
                <p>Not right? You can request a return until <strong>{{ $order->returnDeadline()->shopDateTime() }}</strong>.</p>
                <a href="{{ route('account.returns.create', $order->order_number) }}" class="rounded-md border border-stone-300 px-4 py-2 font-semibold hover:bg-stone-50">Request a return</a>
            </section>
        @endif

        <div class="mt-6 grid gap-6 md:grid-cols-[1fr_16rem]">
            <x-order-summary :order="$order" review-links />

            <div class="space-y-4">
                <section class="h-fit rounded-xl border border-stone-200 bg-white p-4 text-sm">
                    <h2 class="font-semibold">Shipping to</h2>
                    <p class="mt-2">{{ $order->shipping_name ?? $order->user->name }}</p>
                    @if ($order->shipping_phone)
                        <p class="text-stone-600">Recipient phone: {{ $order->shipping_phone }}</p>
                    @endif
                    <p class="mt-2 whitespace-pre-line text-stone-700">{{ $order->shipping_address }}</p>
                </section>

                @if ($order->payment_method)
                    <section class="h-fit rounded-xl border border-stone-200 bg-white p-4 text-sm">
                        <h2 class="font-semibold">Payment</h2>
                        <p class="mt-2">{{ $order->payment_method->label() }}</p>
                        @if ($order->paid_at)
                            <p class="text-emerald-700">Paid {{ $order->paid_at->shopDateTime() }}</p>
                        @else
                            <p class="text-stone-600">Not paid</p>
                        @endif
                        @if ($order->gateway_payment_id)
                            <p class="mt-1 break-all font-mono text-xs text-stone-500">Ref: {{ $order->gateway_payment_id }}</p>
                        @endif
                    </section>
                @endif
            </div>
        </div>
    </div>
</x-layouts.shop>
