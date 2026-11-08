<x-layouts.admin :title="'Points: '.$customer->name">
    <a href="{{ url()->previous() !== url()->current() ? url()->previous() : route('admin.orders.index') }}" class="text-sm text-stone-600 hover:underline">&larr; Back</a>
    <div class="mt-2 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Loyalty points: {{ $customer->name }}</h1>
            <p class="mt-1 text-sm text-stone-600">{{ $customer->email }}</p>
        </div>
    </div>

    <div class="mt-6 grid gap-4 sm:grid-cols-3">
        <dl class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" data-admin-points-balance>
            <dt class="text-sm text-stone-600">Balance</dt>
            <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($balance) }}</dd>
            <dd class="text-sm text-stone-600">worth RM {{ $value }}</dd>
        </dl>
        <dl class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <dt class="text-sm text-stone-600">Expiring in {{ config('loyalty.expiring_soon_days') }} days</dt>
            <dd class="mt-1 text-3xl font-semibold tabular-nums">{{ number_format($expiringSoon) }}</dd>
        </dl>
        <form method="POST" action="{{ route('admin.customers.points.adjust', $customer) }}" class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm"
              onsubmit="return confirm('Adjust this customer\'s points?')">
            @csrf
            <p class="text-sm font-semibold">Adjust points</p>
            <div class="mt-2 grid grid-cols-[6rem_1fr] gap-2">
                <div>
                    <label for="points" class="sr-only">Points (+ or −)</label>
                    <input id="points" name="points" type="number" step="1" value="{{ old('points') }}" placeholder="+100"
                           class="block w-full rounded-md border border-stone-300 px-3 py-2 text-sm" required>
                </div>
                <div>
                    <label for="reason" class="sr-only">Reason</label>
                    <input id="reason" name="reason" type="text" maxlength="200" value="{{ old('reason') }}" placeholder="Reason (required)"
                           class="block w-full rounded-md border border-stone-300 px-3 py-2 text-sm" required>
                </div>
            </div>
            @foreach (['points', 'reason'] as $field)
                @error($field)
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            @endforeach
            <button type="submit" class="mt-3 rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save adjustment</button>
        </form>
    </div>

    <section class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
        <h2 class="border-b border-stone-200 px-5 py-4 text-base font-semibold">History</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-100 text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Date</th>
                        <th scope="col" class="px-5 py-3">Type</th>
                        <th scope="col" class="px-5 py-3">Details</th>
                        <th scope="col" class="px-5 py-3 text-right">Points</th>
                        <th scope="col" class="px-5 py-3 text-right">Balance</th>
                        <th scope="col" class="px-5 py-3">Expires / left</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100" data-admin-points-history>
                    @forelse ($history as $row)
                        <tr>
                            <td class="whitespace-nowrap px-5 py-2.5 text-stone-600">{{ $row->created_at->shopDateTime() }}</td>
                            <td class="px-5 py-2.5 font-medium">{{ $row->type->label() }}</td>
                            <td class="px-5 py-2.5">
                                @if ($row->order)
                                    <a href="{{ route('admin.orders.show', $row->order) }}" class="text-emerald-700 hover:underline">{{ $row->order->order_number }}</a>
                                @endif
                                <span class="text-stone-600">{{ $row->description }}</span>
                                @if ($row->admin)
                                    <span class="block text-xs text-stone-500">by {{ $row->admin->name }} (admin #{{ $row->admin->id }})</span>
                                @endif
                            </td>
                            <td @class(['whitespace-nowrap px-5 py-2.5 text-right font-semibold tabular-nums', 'text-emerald-700' => $row->points > 0, 'text-red-700' => $row->points < 0])>{{ $row->points > 0 ? '+' : '' }}{{ number_format($row->points) }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums">{{ number_format($row->balance_after) }}</td>
                            <td class="whitespace-nowrap px-5 py-2.5 text-stone-600">
                                @if ($row->expires_at)
                                    {{ $row->expires_at->shopDate() }} · {{ number_format($row->remaining) }} left
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-stone-500">No points history.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
    <div class="mt-4">{{ $history->links() }}</div>
</x-layouts.admin>
