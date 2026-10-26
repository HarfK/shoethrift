<x-layouts.admin title="Returns">
    <h1 class="text-2xl font-semibold">Returns</h1>
    <p class="mt-1 text-sm text-stone-600">Review return requests, then record the refund once the pairs are back.</p>

    <nav class="mt-6 flex flex-wrap gap-2 text-sm" aria-label="Filter returns">
        <a href="{{ route('admin.returns.index') }}" @class(['rounded-md px-3 py-1.5 font-medium', 'bg-stone-800 text-white' => ! $status, 'border border-stone-300 bg-white hover:bg-stone-50' => $status])>All</a>
        @foreach ($statuses as $option)
            <a href="{{ route('admin.returns.index', ['status' => $option->value]) }}"
               @if ($status === $option) aria-current="page" @endif
               @class(['rounded-md px-3 py-1.5 font-medium', 'bg-stone-800 text-white' => $status === $option, 'border border-stone-300 bg-white hover:bg-stone-50' => $status !== $option])>{{ $option->label() }}</a>
        @endforeach
    </nav>

    <div class="mt-4 overflow-x-auto rounded-xl border border-stone-200 bg-white shadow-sm">
        <table class="min-w-full divide-y divide-stone-200 text-sm">
            <thead class="bg-stone-50 text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                <tr>
                    <th scope="col" class="px-4 py-3">Order</th>
                    <th scope="col" class="px-4 py-3">Customer</th>
                    <th scope="col" class="px-4 py-3">Reason</th>
                    <th scope="col" class="px-4 py-3 text-right">Pairs</th>
                    <th scope="col" class="px-4 py-3">Requested</th>
                    <th scope="col" class="px-4 py-3">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse ($returns as $return)
                    <tr>
                        <td class="whitespace-nowrap px-4 py-3">
                            <a href="{{ route('admin.returns.show', $return) }}" class="font-medium text-emerald-700 hover:underline">{{ $return->order->order_number }}</a>
                        </td>
                        <td class="px-4 py-3">{{ $return->user->name }}<div class="text-xs text-stone-500">{{ $return->user->email }}</div></td>
                        <td class="px-4 py-3">{{ $return->reason->label() }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $return->items_count }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-stone-600">{{ $return->created_at->shopDateTime() }}</td>
                        <td class="whitespace-nowrap px-4 py-3"><x-return-status-badge :status="$return->status" /></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-12 text-center text-stone-500">No return requests here.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $returns->links() }}</div>
</x-layouts.admin>
