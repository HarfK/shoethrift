<x-layouts.shop title="Your cart">
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6">
        <h1 class="text-3xl font-bold tracking-tight">Your cart</h1>

        @if ($lines->isEmpty())
            <div class="mt-8 rounded-xl border border-dashed border-stone-300 bg-white p-10 text-center">
                <svg viewBox="0 0 24 24" class="mx-auto size-10 text-stone-300" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6.2"/><circle cx="10" cy="20" r="1"/><circle cx="17" cy="20" r="1"/></svg>
                <p class="mt-3 font-semibold">Your cart is empty.</p>
                <p class="mt-1 text-sm text-stone-600">Pre-loved pairs go fast. Find yours before someone else does.</p>
                <a href="{{ route('shop.index') }}" class="mt-5 inline-block rounded-md bg-stone-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Shop now</a>
            </div>
        @else
            <div class="mt-6 grid gap-8 lg:grid-cols-[1fr_22rem]">
                <section aria-label="Items in your cart">
                    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
                        @foreach ($lines as $line)
                            @php($product = $line->product)
                            <li class="flex gap-4 p-4 sm:p-5">
                                <a href="{{ route('shop.show', $product->slug) }}" class="shrink-0">
                                    @if ($url = $product->primaryImage?->url)
                                        <img src="{{ $url }}" alt="{{ $product->name }}" class="size-24 rounded-lg object-cover sm:size-28">
                                    @else
                                        <x-shoe-placeholder class="size-24 rounded-lg sm:size-28" />
                                    @endif
                                </a>

                                <div class="flex min-w-0 flex-1 flex-col">
                                    <div class="flex flex-wrap justify-between gap-x-4 gap-y-1">
                                        <div class="min-w-0">
                                            <p class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $product->brand }}</p>
                                            <a href="{{ route('shop.show', $product->slug) }}" class="font-semibold hover:underline">{{ $product->name }}</a>
                                            <p class="mt-0.5 text-sm text-stone-600">{{ $product->size_label }} · {{ $product->condition->label() }}</p>
                                            @if ($line->reservedUntil)
                                                {{-- Server time first; the script below turns it into a live countdown. --}}
                                                <p class="mt-1 inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-900"
                                                   data-hold-countdown data-expires-at="{{ $line->reservedUntil->toIso8601String() }}">
                                                    <svg viewBox="0 0 24 24" class="size-3.5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                                                    <span data-countdown-text>Reserved for you until {{ $line->reservedUntil->shopTime() }}</span>
                                                </p>
                                            @endif
                                        </div>
                                        <p class="font-semibold tabular-nums">RM {{ number_format($line->total(), 2) }}</p>
                                    </div>

                                    <div class="mt-auto flex flex-wrap items-center justify-between gap-3 pt-3 text-sm">
                                        @if ($product->stock > 1)
                                            <form method="POST" action="{{ route('cart.items.update', $product->id) }}" class="flex items-center gap-2">
                                                @csrf
                                                @method('PATCH')
                                                <label for="qty-{{ $product->id }}" class="text-stone-600">Qty</label>
                                                <select id="qty-{{ $product->id }}" name="quantity" onchange="this.form.submit()"
                                                        class="rounded-md border border-stone-300 bg-white px-2 py-1.5">
                                                    @for ($i = 1; $i <= min($product->stock, 10); $i++)
                                                        <option value="{{ $i }}" @selected($i === $line->quantity)>{{ $i }}</option>
                                                    @endfor
                                                </select>
                                                <noscript><button type="submit" class="rounded-md border border-stone-300 px-2 py-1.5">Update</button></noscript>
                                                <span class="text-stone-500">× RM {{ number_format($product->price, 2) }}</span>
                                            </form>
                                        @else
                                            <p class="text-stone-600">Qty 1 <span class="text-stone-400">·</span> One of a kind</p>
                                        @endif

                                        <form method="POST" action="{{ route('cart.items.destroy', $product->id) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-medium text-red-700 hover:underline">
                                                Remove<span class="sr-only"> {{ $product->name }}</span>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('shop.index') }}" class="mt-4 inline-block text-sm font-medium text-emerald-700 hover:underline">&larr; Continue shopping</a>
                </section>

                <aside class="h-fit rounded-xl border border-stone-200 bg-white p-5 lg:sticky lg:top-24" aria-label="Order summary">
                    <h2 class="text-lg font-semibold">Order summary</h2>
                    <dl class="mt-4 space-y-2 text-sm">
                        <div class="flex justify-between">
                            <dt class="text-stone-600">Subtotal ({{ $lines->sum('quantity') }} {{ str('pair')->plural($lines->sum('quantity')) }})</dt>
                            <dd class="font-semibold tabular-nums">RM {{ number_format($subtotal, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-stone-600">Shipping</dt>
                            <dd class="tabular-nums">RM {{ number_format(config('shop.shipping_fee'), 2) }}</dd>
                        </div>
                        <div class="flex justify-between border-t border-stone-200 pt-2 text-base font-semibold">
                            <dt>Total</dt>
                            <dd class="tabular-nums">RM {{ number_format($subtotal + config('shop.shipping_fee'), 2) }}</dd>
                        </div>
                    </dl>

                    <div class="mt-5">
                        <a href="{{ route('checkout.create') }}" class="block w-full rounded-md bg-stone-900 px-4 py-3 text-center font-semibold text-white hover:bg-stone-700">
                            Proceed to checkout
                        </a>
                    </div>

                    <p class="mt-4 text-xs text-stone-500">
                        Pairs in your cart are reserved for you for {{ \App\Services\Cart\Reservations::holdMinutes() }} minutes from when you added them,
                        and the time isn't extended. Your cart holds up to {{ $maxItems }} pairs.
                    </p>
                </aside>
            </div>
        @endif
    </div>

    {{-- Live countdown for each hold. Without JS the "Reserved until" time above still shows. --}}
    <script>
        (() => {
            const badges = document.querySelectorAll('[data-hold-countdown]');
            if (!badges.length) return;
            const tick = () => {
                let expired = false;
                badges.forEach((badge) => {
                    const left = Math.max(0, Math.floor((new Date(badge.dataset.expiresAt) - Date.now()) / 1000));
                    const text = badge.querySelector('[data-countdown-text]');
                    if (left === 0) {
                        text.textContent = 'Reservation expired';
                        expired = true;
                    } else {
                        text.textContent = 'Reserved for ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0') + ' more';
                    }
                });
                if (expired) setTimeout(() => location.reload(), 1500); // the server then explains what happened
                else setTimeout(tick, 1000);
            };
            tick();
        })();
    </script>
</x-layouts.shop>
