{{-- Printable receipt. "Print / Save as PDF" uses the browser's print dialog; the shop header/footer are print:hidden. --}}
<x-layouts.shop :title="'Receipt '.$order->order_number">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-6 print:max-w-none print:p-0">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3 print:hidden">
            <a href="{{ route('account.orders.show', $order->order_number) }}" class="text-sm text-stone-600 hover:underline">&larr; Back to order</a>
            <button type="button" onclick="window.print()" class="rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">
                Print / Save as PDF
            </button>
        </div>

        <article class="rounded-xl border border-stone-200 bg-white p-6 sm:p-10 print:rounded-none print:border-0 print:p-0" aria-labelledby="receipt-heading">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-stone-200 pb-6">
                <div>
                    <p class="text-2xl font-bold tracking-tight">{{ config('app.name', 'ShoeThrift') }}</p>
                    <p class="text-sm text-stone-600">Pre-loved shoes, Malaysia</p>
                </div>
                <div class="text-right">
                    <h1 id="receipt-heading" class="text-xl font-semibold">Receipt</h1>
                    <p class="mt-1 inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold uppercase tracking-wider text-emerald-800 ring-1 ring-emerald-600/20">Paid</p>
                </div>
            </header>

            <dl class="grid gap-x-8 gap-y-3 border-b border-stone-200 py-6 text-sm sm:grid-cols-2">
                <div><dt class="text-stone-500">Order number</dt><dd class="font-semibold">{{ $order->order_number }}</dd></div>
                <div><dt class="text-stone-500">Order date</dt><dd>{{ $order->created_at->shopDateTime() }}</dd></div>
                <div><dt class="text-stone-500">Paid on</dt><dd>{{ $order->paid_at->shopDateTime() }}</dd></div>
                <div>
                    <dt class="text-stone-500">Payment method</dt>
                    <dd>{{ $order->payment_method?->label() ?? 'Online' }}@if ($order->isSimulatedPayment()) <strong>(Simulated test payment)</strong>@endif</dd>
                </div>
                @if ($order->gateway_reference || $order->gateway_payment_id)
                    <div class="sm:col-span-2">
                        <dt class="text-stone-500">Payment reference</dt>
                        <dd class="break-all font-mono text-xs">
                            @if ($order->gateway_reference) HitPay request {{ $order->gateway_reference }}@endif
                            @if ($order->gateway_payment_id)<br>Payment {{ $order->gateway_payment_id }}@endif
                        </dd>
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <dt class="text-stone-500">Billed and shipped to</dt>
                    <dd>
                        {{ $order->shipping_name ?? $order->user->name }}@if ($order->shipping_phone) · Recipient phone: {{ $order->shipping_phone }}@endif
                        <span class="block whitespace-pre-line text-stone-700">{{ $order->shipping_address }}</span>
                    </dd>
                </div>
            </dl>

            <table class="mt-6 w-full text-sm">
                <thead class="border-b border-stone-200 text-left text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th scope="col" class="pb-2 font-medium">Item</th>
                        <th scope="col" class="pb-2 text-right font-medium">Qty</th>
                        <th scope="col" class="pb-2 text-right font-medium">Unit price</th>
                        <th scope="col" class="pb-2 text-right font-medium">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($order->items as $item)
                        <tr>
                            <td class="py-3">
                                <span class="font-medium">{{ $item->product->name }}</span>
                                <span class="block text-xs text-stone-500">{{ $item->product->brand }} · {{ $item->product->size_label }}</span>
                            </td>
                            <td class="py-3 text-right tabular-nums">{{ $item->quantity }}</td>
                            <td class="py-3 text-right tabular-nums">RM {{ number_format($item->price, 2) }}</td>
                            <td class="py-3 text-right tabular-nums">RM {{ number_format($item->price * $item->quantity, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-stone-200">
                    <tr><th scope="row" colspan="3" class="pt-3 text-right font-normal text-stone-600">Subtotal</th><td class="pt-3 text-right tabular-nums">RM {{ number_format($order->subtotal, 2) }}</td></tr>
                    <tr><th scope="row" colspan="3" class="pt-1 text-right font-normal text-stone-600">Shipping</th><td class="pt-1 text-right tabular-nums">RM {{ number_format($order->shipping_fee, 2) }}</td></tr>
                    <tr><th scope="row" colspan="3" class="pt-2 text-right text-base font-semibold">Total paid</th><td class="pt-2 text-right text-base font-semibold tabular-nums">RM {{ number_format($order->total, 2) }}</td></tr>
                </tfoot>
            </table>

            <p class="mt-10 text-center text-xs text-stone-500">Thank you for shopping pre-loved with {{ config('app.name', 'ShoeThrift') }}.</p>
        </article>
    </div>
</x-layouts.shop>
