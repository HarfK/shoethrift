@use('App\Enums\ReturnStatus')
@use('App\Enums\RefundMethod')

<x-layouts.admin :title="'Return for '.$return->order->order_number">
    <a href="{{ route('admin.returns.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Returns</a>
    <div class="mt-2 flex flex-wrap items-center gap-3">
        <h1 class="text-2xl font-semibold">Return for order {{ $return->order->order_number }}</h1>
        <x-return-status-badge :status="$return->status" />
    </div>
    <p class="mt-1 text-sm text-stone-500">
        Requested {{ $return->created_at->shopDateTime() }} ·
        delivered {{ $return->order->delivered_at?->shopDate() ?? 'unknown' }} ·
        <a href="{{ route('admin.orders.show', $return->order) }}" class="text-emerald-700 hover:underline">View order</a>
    </p>

    <div class="mt-6 grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">{{ $return->reason->label() }}</h2>
                <p class="mt-2 whitespace-pre-line text-sm text-stone-700">{{ $return->details }}</p>

                @if ($return->photos->isNotEmpty())
                    <h3 class="mt-5 text-sm font-semibold">Photos</h3>
                    <ul class="mt-2 flex flex-wrap gap-3">
                        @foreach ($return->photos as $photo)
                            <li>
                                <a href="{{ route('returns.photo', $photo) }}" target="_blank" rel="noopener">
                                    <img src="{{ route('returns.photo', $photo) }}" alt="Customer photo {{ $loop->iteration }}" class="size-32 rounded-md border border-stone-200 object-cover">
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="rounded-xl border border-stone-200 bg-white shadow-sm">
                <h2 class="border-b border-stone-200 px-5 py-4 text-base font-semibold">Pairs being returned</h2>
                <ul class="divide-y divide-stone-100">
                    @foreach ($return->items as $item)
                        <li class="flex items-center gap-3 px-5 py-3 text-sm">
                            <div class="flex-1">
                                <a href="{{ route('admin.products.edit', $item->orderItem->product) }}" class="font-medium hover:underline">{{ $item->orderItem->product->name }}</a>
                                <div class="text-xs text-stone-500">{{ $item->orderItem->product->size_label }} · Qty {{ $item->quantity }}</div>
                            </div>
                            @if ($item->restocked)
                                <span class="text-xs font-medium text-emerald-700">Back in stock</span>
                            @endif
                            <span class="tabular-nums">RM {{ number_format($item->orderItem->price * $item->quantity, 2) }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="border-t border-stone-200 px-5 py-3 text-right text-sm">
                    Pairs total <strong class="tabular-nums">RM {{ number_format($return->itemsTotal(), 2) }}</strong>
                    · order paid <strong class="tabular-nums">RM {{ number_format($return->order->total, 2) }}</strong>
                    @if ((float) $return->order->refunded_amount > 0)
                        · already refunded <strong class="tabular-nums">RM {{ number_format($return->order->refunded_amount, 2) }}</strong>
                    @endif
                </p>
            </section>
        </div>

        <div class="space-y-6">
            <section class="rounded-xl border border-stone-200 bg-white p-5 text-sm shadow-sm">
                <h2 class="mb-3 text-base font-semibold">Customer</h2>
                <p class="font-medium">{{ $return->order->user->name }}</p>
                <p><a href="mailto:{{ $return->order->user->email }}" class="text-emerald-700 hover:underline">{{ $return->order->user->email }}</a></p>
                <p class="mt-1 text-stone-600">Paid by {{ $return->order->payment_method?->label() ?? 'unknown' }}</p>
            </section>

            @if ($return->status === ReturnStatus::Requested)
                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                    <h2 class="mb-3 text-base font-semibold">Decision</h2>
                    <form method="POST" action="{{ route('admin.returns.decide', $return) }}" class="space-y-3">
                        @csrf
                        @method('PATCH')
                        <div>
                            <label for="admin_note" class="mb-1 block text-sm font-medium text-stone-700">Note to the customer</label>
                            <textarea id="admin_note" name="admin_note" rows="3" maxlength="1000"
                                      class="block w-full rounded-md border border-stone-300 px-3 py-2 text-sm"
                                      placeholder="Required when rejecting">{{ old('admin_note') }}</textarea>
                            @error('admin_note')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" name="decision" value="approve" class="flex-1 rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Approve</button>
                            <button type="submit" name="decision" value="reject" class="flex-1 rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-50">Reject</button>
                        </div>
                    </form>
                </section>
            @elseif ($return->status === ReturnStatus::Approved)
                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                    <h2 class="text-base font-semibold">Record refund</h2>
                    <p class="mt-1 text-xs text-stone-500">Do this once the pairs are back and checked.</p>

                    @if ($return->refund_attempted_at)
                        <div class="mt-3 rounded-md border border-amber-300 bg-amber-50 p-3 text-xs text-amber-900" role="alert">
                            A HitPay refund was attempted {{ $return->refund_attempted_at->shopDateTime() }} and its result is unknown.
                            <strong>Check the HitPay dashboard before trying again.</strong> If the refund is there, record it manually with its reference.
                        </div>
                    @endif

                    <form method="POST" action="{{ route('admin.returns.refund', $return) }}" class="mt-4 space-y-4"
                          onsubmit="this.querySelector('[type=submit]').disabled = true">
                        @csrf
                        <x-form.field name="amount" label="Refund amount (RM)" type="number" step="0.01" min="0.01"
                                      :max="$return->order->refundableAmount()" :value="$return->itemsTotal()" required />
                        <p class="-mt-3 text-xs text-stone-500">Up to RM {{ $return->order->refundableAmount() }}. Less than the order total = partial refund.</p>

                        <fieldset>
                            <legend class="text-sm font-medium text-stone-700">Put back on sale</legend>
                            @foreach ($return->items as $item)
                                <label class="mt-2 flex items-center gap-2 text-sm">
                                    <input type="checkbox" name="restock[]" value="{{ $item->id }}" class="rounded border-stone-300"
                                           @checked(in_array($item->id, array_map('intval', old('restock', $return->items->pluck('id')->all()))))>
                                    {{ $item->orderItem->product->name }} ({{ $item->orderItem->product->size_label }})
                                </label>
                            @endforeach
                            <p class="mt-1 text-xs text-stone-500">Untick a pair that came back damaged.</p>
                        </fieldset>

                        <fieldset>
                            <legend class="text-sm font-medium text-stone-700">How</legend>
                            <label @class(['mt-2 flex items-start gap-2 text-sm', 'text-stone-400' => ! $canUseHitPay])>
                                <input type="radio" name="method" value="{{ RefundMethod::HitPay->value }}" class="mt-0.5"
                                       @checked($canUseHitPay && old('method', 'hitpay') === 'hitpay') @disabled(! $canUseHitPay)>
                                <span>Refund through HitPay
                                    <span class="block text-xs text-stone-500">{{ $canUseHitPay ? 'Sends the money back to the original payment method now.' : 'Not available: this order has no real HitPay payment (COD or simulated).' }}</span>
                                </span>
                            </label>
                            <label class="mt-2 flex items-start gap-2 text-sm">
                                <input type="radio" name="method" value="{{ RefundMethod::Manual->value }}" class="mt-0.5"
                                       @checked(! $canUseHitPay || old('method') === 'manual')>
                                <span>Already refunded manually
                                    <span class="block text-xs text-stone-500">Bank transfer, cash, or from the HitPay dashboard.</span>
                                </span>
                            </label>
                        </fieldset>

                        <x-form.field name="reference" label="Manual refund reference" maxlength="255" placeholder="e.g. Maybank transfer ref 1234" />

                        @if ($return->refund_attempted_at)
                            <label class="flex items-start gap-2 text-sm">
                                <input type="checkbox" name="retry_confirmed" value="1" class="mt-0.5 rounded border-stone-300">
                                I checked HitPay: the earlier refund did not go through.
                            </label>
                        @endif

                        <button type="submit" class="w-full rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:opacity-60">Record refund</button>
                    </form>
                </section>
            @endif

            @if ($return->decided_at)
                <section class="rounded-xl border border-stone-200 bg-white p-5 text-sm shadow-sm">
                    <h2 class="mb-2 text-base font-semibold">History</h2>
                    <p>{{ $return->status === ReturnStatus::Rejected ? 'Rejected' : 'Approved' }} {{ $return->decided_at->shopDateTime() }}</p>
                    @if ($return->admin_note)
                        <p class="mt-1 text-stone-600">Note: {{ $return->admin_note }}</p>
                    @endif
                    @if ($return->refunded_at)
                        <p class="mt-2">Refunded RM {{ number_format($return->refund_amount, 2) }} on {{ $return->refunded_at->shopDateTime() }}</p>
                        <p class="text-stone-600">{{ $return->refund_method->label() }} · ref <span class="font-mono text-xs">{{ $return->refund_reference }}</span></p>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-layouts.admin>
