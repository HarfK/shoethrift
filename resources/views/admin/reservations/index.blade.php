<x-layouts.admin title="Reservations">
    <h1 class="text-2xl font-semibold">Cart reservations</h1>
    <p class="mt-1 text-sm text-stone-600">
        Adding a pair to a cart holds it for {{ \App\Services\Cart\Reservations::holdMinutes() }} minutes (never extended).
        Expired holds are released every minute and on shop page views.
    </p>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm" aria-labelledby="active-heading">
        <h2 id="active-heading" class="border-b border-stone-200 px-5 py-3 text-base font-semibold">Held right now ({{ $active->count() }})</h2>
        <table class="min-w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">Pair</th><th scope="col" class="px-5 py-2">Customer</th><th scope="col" class="px-5 py-2 text-right">Qty</th><th scope="col" class="px-5 py-2">Held until</th></tr></thead>
            <tbody class="divide-y divide-stone-100" data-active-holds>
                @forelse ($active as $hold)
                    <tr>
                        <td class="px-5 py-2"><a href="{{ route('shop.show', $hold->product->slug) }}" class="hover:underline">{{ $hold->product->name }}</a> <span class="text-stone-500">{{ $hold->product->size_label }}</span></td>
                        <td class="px-5 py-2">{{ $hold->cart->user->name }} <span class="text-xs text-stone-500">{{ $hold->cart->user->email }}</span></td>
                        <td class="px-5 py-2 text-right tabular-nums">{{ $hold->quantity }}</td>
                        <td class="whitespace-nowrap px-5 py-2">{{ $hold->reserved_until->shopTime() }} <span class="text-xs text-stone-500">(in {{ max(1, (int) ceil(now()->diffInMinutes($hold->reserved_until))) }} min)</span></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-5 py-6 text-center text-stone-500">No pairs are held in carts right now.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm" aria-labelledby="repeat-heading">
        <h2 id="repeat-heading" class="border-b border-stone-200 px-5 py-3 text-base font-semibold">Expired holds per customer (last {{ $windowDays }} days)</h2>
        <p class="px-5 pt-3 text-xs text-stone-500">Customers whose holds ran out {{ $flagAfter }} or more times are marked. This is for awareness only; nothing is blocked.</p>
        <table class="mt-2 min-w-full text-sm">
            <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">Customer</th><th scope="col" class="px-5 py-2 text-right">Expired holds</th></tr></thead>
            <tbody class="divide-y divide-stone-100" data-repeat-expiries>
                @forelse ($repeat as $customer)
                    <tr>
                        <td class="px-5 py-2">{{ $customer->name }} <span class="text-xs text-stone-500">{{ $customer->email }}</span></td>
                        <td class="px-5 py-2 text-right">
                            <span @class(['font-semibold tabular-nums', 'text-red-700' => $customer->expired_holds >= $flagAfter])>{{ $customer->expired_holds }}</span>
                            @if ($customer->expired_holds >= $flagAfter)
                                <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-medium text-red-800 ring-1 ring-inset ring-red-600/20">Repeated</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="2" class="px-5 py-6 text-center text-stone-500">No expired holds in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.admin>
