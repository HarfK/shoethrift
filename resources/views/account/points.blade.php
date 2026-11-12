<x-layouts.account title="My points">
    <h1 class="text-2xl font-bold tracking-tight">My points</h1>
    <p class="mt-1 text-sm text-stone-600">
        Earn {{ config('loyalty.earn_per_ringgit') }} point per RM1 you spend on shoes (shipping not included), once your order is completed.
        Use them at checkout: {{ $rule }}
        Points expire {{ config('loyalty.expiry_months') }} months after you earn them.
    </p>

    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-5" data-points-balance>
            <dt class="text-sm text-emerald-800">Your balance</dt>
            <dd class="mt-1 text-3xl font-bold tabular-nums text-emerald-900">{{ number_format($balance) }} <span class="text-base font-medium">points</span></dd>
            <dd class="mt-1 text-sm text-emerald-800">Worth RM {{ $value }} at checkout</dd>
            @if ($balance > 0 && $balance < $minPoints)
                <dd class="mt-1 text-xs text-emerald-800/80" data-points-below-min>You can use points from {{ number_format($minPoints) }} points.</dd>
            @endif
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5" data-points-expiring>
            <dt class="text-sm text-stone-600">Expiring in the next {{ config('loyalty.expiring_soon_days') }} days</dt>
            <dd class="mt-1 text-3xl font-bold tabular-nums">{{ number_format($expiringSoon) }} <span class="text-base font-medium">points</span></dd>
            <dd class="mt-1 text-sm text-stone-600">
                @if ($expiringSoon > 0)
                    Use them before {{ $nextExpiry?->shopDate() }}.
                @elseif ($nextExpiry)
                    Your next points expire on {{ $nextExpiry->shopDate() }}.
                @else
                    Nothing to expire.
                @endif
            </dd>
        </div>
    </dl>

    <section class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white" aria-labelledby="history-heading">
        <h2 id="history-heading" class="border-b border-stone-200 px-5 py-4 text-base font-semibold">History</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-100 text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Date</th>
                        <th scope="col" class="px-5 py-3">What</th>
                        <th scope="col" class="px-5 py-3 text-right">Points</th>
                        <th scope="col" class="px-5 py-3 text-right">Balance</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100" data-points-history>
                    @forelse ($history as $row)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-3 text-stone-600">{{ $row->created_at->shopDate() }}</td>
                            <td class="px-5 py-3">
                                <span class="font-medium">{{ $row->type->label() }}</span>
                                <span class="text-stone-600">·
                                    @if ($row->order)
                                        <a href="{{ route('account.orders.show', $row->order->order_number) }}" class="text-emerald-700 hover:underline">{{ $row->order->order_number }}</a>
                                    @else
                                        {{ $row->type === \App\Enums\PointTransactionType::Adjust ? 'Adjusted by ShoeThrift' : $row->description }}
                                    @endif
                                </span>
                                @if ($row->expires_at && $row->points > 0)
                                    <span class="block text-xs text-stone-500">Expires {{ $row->expires_at->shopDate() }}</span>
                                @endif
                            </td>
                            <td @class(['whitespace-nowrap px-5 py-3 text-right font-semibold tabular-nums', 'text-emerald-700' => $row->points > 0, 'text-red-700' => $row->points < 0])>{{ $row->points > 0 ? '+' : '' }}{{ number_format($row->points) }}</td>
                            <td class="whitespace-nowrap px-5 py-3 text-right tabular-nums text-stone-600">{{ number_format($row->balance_after) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-8 text-center text-stone-500">No points yet. You'll earn points when your first order is completed.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $history->links() }}</div>
</x-layouts.account>
