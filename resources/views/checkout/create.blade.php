<x-layouts.shop title="Checkout">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <nav class="text-sm text-stone-500" aria-label="Breadcrumb">
            <a href="{{ route('cart.index') }}" class="hover:text-stone-900">Cart</a>
            <span aria-hidden="true">›</span>
            <span class="text-stone-900">Checkout</span>
        </nav>
        <h1 class="mt-2 text-3xl font-bold tracking-tight">Checkout</h1>

        <form method="POST" action="{{ route('checkout.store') }}" class="mt-6 grid gap-8 lg:grid-cols-[1fr_24rem]"
              onsubmit="this.querySelector('[data-place-order]').disabled = true">
            @csrf
            <input type="hidden" name="expected_total" value="{{ number_format($total, 2, '.', '') }}">

            <section class="rounded-xl border border-stone-200 bg-white p-5 sm:p-6" aria-labelledby="shipping-heading">
                <h2 id="shipping-heading" class="text-lg font-semibold">Shipping details</h2>
                <p class="mt-1 text-sm text-stone-600">Who should we send your shoes to?</p>

                {{--
                    Saved addresses as radio cards, plus "Use a different address". The manual fields show only when
                    that option is chosen (CSS :has, no JS); they skip HTML `required` then, the server validates them.
                --}}
                @php($selectedAddress = (string) old('address_id', $addresses->first()?->id ?? 'new'))
                <div class="group/address mt-5">
                    @if ($addresses->isNotEmpty())
                        <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Ship to">
                            @foreach ($addresses as $address)
                                <label class="flex cursor-pointer gap-3 rounded-lg border border-stone-300 p-4 text-sm has-checked:border-stone-900 has-checked:ring-1 has-checked:ring-stone-900 has-focus-visible:ring-2 has-focus-visible:ring-emerald-600">
                                    <input type="radio" name="address_id" value="{{ $address->id }}" class="mt-1 text-stone-900" @checked($selectedAddress === (string) $address->id)>
                                    <span>
                                        <span class="flex flex-wrap items-center gap-2 font-semibold">
                                            {{ $address->recipient_name }}
                                            @if ($address->label)<span class="rounded bg-stone-100 px-1.5 text-xs font-medium text-stone-600">{{ $address->label }}</span>@endif
                                            @if ($address->is_default)<span class="rounded bg-emerald-50 px-1.5 text-xs font-medium text-emerald-800">Default</span>@endif
                                        </span>
                                        <span class="mt-1 block text-stone-600">{{ $address->phone }}</span>
                                        <span class="mt-1 block whitespace-pre-line text-stone-700">{{ implode("\n", $address->lines()) }}</span>
                                    </span>
                                </label>
                            @endforeach
                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-stone-300 p-4 text-sm has-checked:border-stone-900 has-checked:ring-1 has-checked:ring-stone-900">
                                <input type="radio" id="address-new" name="address_id" value="new" class="text-stone-900" @checked($selectedAddress === 'new')>
                                <span class="font-semibold">Use a different address</span>
                            </label>
                        </div>
                        @error('address_id')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                        <p class="mt-2 text-xs text-stone-500"><a href="{{ route('account.addresses.index') }}" class="underline">Manage your addresses</a></p>
                    @else
                        <input type="hidden" name="address_id" value="new">
                    @endif

                    <fieldset @class(['mt-5', 'hidden group-has-[#address-new:checked]/address:block' => $addresses->isNotEmpty()]) data-manual-address>
                        <legend class="sr-only">New address</legend>
                        @include('partials.address-fields', ['address' => new \App\Models\Address(['recipient_name' => $user->name, 'phone' => $user->phone]), 'required' => $addresses->isEmpty()])
                        <label class="flex items-start gap-2 text-sm">
                            <input type="hidden" name="save_address" value="0">
                            <input type="checkbox" name="save_address" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-700" @checked(old('save_address', true))>
                            <span>Save this address to my address book</span>
                        </label>
                    </fieldset>
                </div>

                <fieldset class="mt-6">
                    <legend class="text-lg font-semibold">Payment</legend>
                    @php($selectedMethod = $codUnavailableReason ? 'online' : old('payment_method', 'online'))
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer gap-3 rounded-lg border border-stone-300 p-4 has-checked:border-stone-900 has-checked:ring-1 has-checked:ring-stone-900 has-focus-visible:ring-2 has-focus-visible:ring-emerald-600">
                            <input type="radio" name="payment_method" value="online" class="mt-1 text-stone-900" @checked($selectedMethod === 'online')>
                            <span class="text-sm">
                                <span class="block font-semibold">Pay online</span>
                                <span class="mt-0.5 block text-stone-600">Card or e-wallet through HitPay's secure checkout. Pay within {{ config('services.hitpay.payment_window_minutes') }} minutes to keep your pairs.</span>
                            </span>
                        </label>
                        <label @class([
                            'flex gap-3 rounded-lg border border-stone-300 p-4',
                            'cursor-pointer has-checked:border-stone-900 has-checked:ring-1 has-checked:ring-stone-900 has-focus-visible:ring-2 has-focus-visible:ring-emerald-600' => ! $codUnavailableReason,
                            'cursor-not-allowed bg-stone-50 text-stone-500' => $codUnavailableReason,
                        ])>
                            <input type="radio" name="payment_method" value="cod" class="mt-1 text-stone-900" @checked($selectedMethod === 'cod')
                                   @disabled($codUnavailableReason) @if ($codUnavailableReason) aria-describedby="cod-unavailable" @endif>
                            <span class="text-sm">
                                <span class="block font-semibold">Cash on Delivery</span>
                                @if ($codUnavailableReason)
                                    <span id="cod-unavailable" class="mt-0.5 block text-amber-800">{{ $codUnavailableReason }}</span>
                                @else
                                    <span class="mt-0.5 block text-stone-600">Pay in cash when your shoes arrive.</span>
                                @endif
                            </span>
                        </label>
                    </div>
                    @error('payment_method')
                        <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </fieldset>
            </section>

            <aside class="h-fit rounded-xl border border-stone-200 bg-white p-5 lg:sticky lg:top-24" aria-label="Order summary">
                <h2 class="text-lg font-semibold">Order summary</h2>
                <ul class="mt-4 divide-y divide-stone-100">
                    @foreach ($lines as $line)
                        <li class="flex gap-3 py-3 first:pt-0">
                            @if ($url = $line->product->primaryImage?->url)
                                <img src="{{ $url }}" alt="" class="size-14 shrink-0 rounded-md object-cover">
                            @else
                                <x-shoe-placeholder class="size-14 shrink-0 rounded-md" />
                            @endif
                            <div class="min-w-0 flex-1 text-sm">
                                <p class="font-medium">{{ $line->product->name }}</p>
                                <p class="text-stone-600">{{ $line->product->size_label }} · Qty {{ $line->quantity }}</p>
                            </div>
                            <p class="text-sm font-medium tabular-nums">RM {{ number_format($line->total(), 2) }}</p>
                        </li>
                    @endforeach
                </ul>
                <dl class="mt-3 space-y-1.5 border-t border-stone-200 pt-3 text-sm">
                    <div class="flex justify-between"><dt class="text-stone-600">Subtotal</dt><dd class="tabular-nums">RM {{ number_format($subtotal, 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-stone-600">Shipping</dt><dd class="tabular-nums">RM {{ number_format($shippingFee, 2) }}</dd></div>
                    <div class="flex justify-between pt-1.5 text-base font-semibold"><dt>Total</dt><dd class="tabular-nums">RM {{ number_format($total, 2) }}</dd></div>
                </dl>

                <button type="submit" data-place-order
                        class="mt-5 w-full rounded-md bg-stone-900 px-4 py-3 font-semibold text-white hover:bg-stone-700 disabled:cursor-wait disabled:opacity-60">
                    Place order
                </button>
                <a href="{{ route('cart.index') }}" class="mt-3 block text-center text-sm text-stone-600 hover:underline">Back to cart</a>
            </aside>
        </form>
    </div>
</x-layouts.shop>
