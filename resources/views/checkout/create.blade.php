@php($inputClass = 'block w-full rounded-md border bg-white px-3 py-2.5 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40')

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

                <div class="mt-5 grid gap-x-4 sm:grid-cols-2">
                    <x-form.field name="shipping_name" label="Full name" :value="$user->name" required maxlength="100" autocomplete="name" />
                    <x-form.field name="shipping_phone" label="Phone number" type="tel" :value="$user->phone" required maxlength="30"
                                  autocomplete="tel" placeholder="012-345 6789" />
                </div>

                <div class="mb-4">
                    <label for="shipping_address" class="mb-1 block text-sm font-medium text-stone-700">Shipping address</label>
                    <textarea id="shipping_address" name="shipping_address" rows="4" required minlength="10" maxlength="500" autocomplete="street-address"
                              placeholder="No. 12, Jalan Bunga Raya&#10;Taman Melati&#10;40100 Shah Alam, Selangor"
                              @error('shipping_address') aria-invalid="true" aria-describedby="shipping_address-error" @enderror
                              @class([$inputClass, 'border-red-400' => $errors->has('shipping_address'), 'border-stone-300' => ! $errors->has('shipping_address')])>{{ old('shipping_address', $user->address) }}</textarea>
                    @error('shipping_address')
                        <p id="shipping_address-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
                    @enderror
                </div>

                <label class="flex items-start gap-2 text-sm">
                    <input type="hidden" name="save_details" value="0">
                    <input type="checkbox" name="save_details" value="1" class="mt-0.5 rounded border-stone-300 text-emerald-700"
                           @checked(old('save_details', blank($user->address)))>
                    <span>Save this phone number and address to my profile for next time</span>
                </label>

                <div class="mt-6 rounded-lg bg-stone-50 p-4 text-sm text-stone-600">
                    <p class="font-medium text-stone-900">Payment</p>
                    <p class="mt-1">Your order is placed as <strong>Pending</strong>. Online payment (FPX) is coming soon, and we'll hold your order until it's paid.</p>
                </div>
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
