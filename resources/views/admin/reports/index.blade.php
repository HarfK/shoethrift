<x-layouts.admin title="Sales report">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Sales report</h1>
            <p class="mt-1 text-sm text-stone-600"><span class="font-medium text-stone-900">{{ $report->period->label }}</span> · paid orders only (COD once the cash is collected), dated by payment, Malaysia time.</p>
        </div>
        <div class="flex flex-wrap gap-2 print:hidden">
            <button type="button" onclick="window.print()" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Print</button>
            <a href="{{ route('admin.reports.pdf', request()->query()) }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Download PDF</a>
            <a href="{{ route('admin.reports.csv', request()->query()) }}" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Export CSV</a>
        </div>
    </div>

    {{-- Period filter. Each period type has its own date input; only the chosen one matters. --}}
    <form method="GET" action="{{ route('admin.reports.index') }}" class="mt-6 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 print:hidden">
        <div>
            <label for="period" class="mb-1 block text-sm font-medium text-stone-700">Period</label>
            <select id="period" name="period" class="block rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'custom' => 'Custom range'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="date" class="mb-1 block text-sm font-medium text-stone-700">Day / week of</label>
            <input id="date" name="date" type="date" value="{{ $filters['date'] }}" class="block rounded-md border border-stone-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label for="month" class="mb-1 block text-sm font-medium text-stone-700">Month</label>
            <input id="month" name="month" type="month" value="{{ $filters['month'] }}" class="block rounded-md border border-stone-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label for="from" class="mb-1 block text-sm font-medium text-stone-700">From</label>
            <input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="block rounded-md border border-stone-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label for="to" class="mb-1 block text-sm font-medium text-stone-700">To</label>
            <input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="block rounded-md border border-stone-300 px-3 py-2 text-sm">
        </div>
        <button type="submit" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Show report</button>
        @if ($errors->any())
            <p class="w-full text-sm text-red-600" role="alert">{{ $errors->first() }}</p>
        @endif
    </form>

    {{-- Summary --}}
    <dl class="mt-6 grid grid-cols-2 gap-4 lg:grid-cols-4" data-summary>
        @foreach ([
            'Net revenue' => 'RM '.number_format((float) $summary['net'], 2),
            'Paid orders' => number_format($summary['orders']),
            'Average order (net)' => 'RM '.number_format((float) $summary['average'], 2),
            'Refunds' => 'RM '.number_format((float) $summary['refunds'], 2),
            'Gross sales' => 'RM '.number_format((float) $summary['gross'], 2),
            'Item sales' => 'RM '.number_format((float) $summary['items'], 2),
            'Shipping fees' => 'RM '.number_format((float) $summary['shipping'], 2),
        ] as $label => $value)
            <div class="break-inside-avoid rounded-xl border border-stone-200 bg-white p-4 shadow-sm print:shadow-none">
                <dt class="text-sm text-stone-600">{{ $label }}</dt>
                <dd class="mt-1 text-xl font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <section class="break-inside-avoid rounded-xl border border-stone-200 bg-white shadow-sm print:shadow-none">
            <h2 class="border-b border-stone-200 px-5 py-3 text-base font-semibold">By payment method</h2>
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">Method</th><th scope="col" class="px-5 py-2 text-right">Orders</th><th scope="col" class="px-5 py-2 text-right">Gross</th><th scope="col" class="px-5 py-2 text-right">Net</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @foreach ($byPaymentMethod as $row)
                        <tr><td class="px-5 py-2">{{ $row['name'] }}</td><td class="px-5 py-2 text-right tabular-nums">{{ $row['orders'] }}</td><td class="px-5 py-2 text-right tabular-nums">RM {{ number_format((float) $row['gross'], 2) }}</td><td class="px-5 py-2 text-right tabular-nums">RM {{ number_format((float) $row['net'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="break-inside-avoid rounded-xl border border-stone-200 bg-white shadow-sm print:shadow-none">
            <h2 class="border-b border-stone-200 px-5 py-3 text-base font-semibold">By category</h2>
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">Category</th><th scope="col" class="px-5 py-2 text-right">Pairs</th><th scope="col" class="px-5 py-2 text-right">Sales</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($byCategory as $row)
                        <tr><td class="px-5 py-2">{{ $row['name'] }}</td><td class="px-5 py-2 text-right tabular-nums">{{ $row['units'] }}</td><td class="px-5 py-2 text-right tabular-nums">RM {{ number_format((float) $row['sales'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-5 py-4 text-stone-500">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm print:shadow-none">
        <h2 class="border-b border-stone-200 px-5 py-3 text-base font-semibold">By product</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">Product</th><th scope="col" class="px-5 py-2">Brand</th><th scope="col" class="px-5 py-2 text-right">Pairs</th><th scope="col" class="px-5 py-2 text-right">Sales</th></tr></thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($byProduct as $row)
                        <tr class="break-inside-avoid"><td class="px-5 py-2">{{ $row['name'] }} <span class="text-stone-500">{{ $row['size'] }}</span></td><td class="px-5 py-2">{{ $row['brand'] }}</td><td class="px-5 py-2 text-right tabular-nums">{{ $row['units'] }}</td><td class="px-5 py-2 text-right tabular-nums">RM {{ number_format((float) $row['sales'], 2) }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="px-5 py-4 text-stone-500">No sales in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-6 rounded-xl border border-stone-200 bg-white shadow-sm print:shadow-none">
        <h2 class="border-b border-stone-200 px-5 py-3 text-base font-semibold">Sales by {{ $report->period->grouping() }}</h2>
        @if (count($overTime) > 1)
            <x-admin.bar-chart :data="$overTime" :caption="'Net revenue per '.$report->period->grouping()" class="px-5 pt-4 print:hidden" />
        @endif
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="text-left text-xs uppercase tracking-wide text-stone-500"><tr><th scope="col" class="px-5 py-2">{{ ucfirst($report->period->grouping()) }}</th><th scope="col" class="px-5 py-2 text-right">Orders</th><th scope="col" class="px-5 py-2 text-right">Net revenue</th></tr></thead>
                <tbody class="divide-y divide-stone-100" data-over-time>
                    @foreach ($overTime as $row)
                        <tr class="break-inside-avoid"><td class="px-5 py-1.5">{{ $row['label'] }}</td><td class="px-5 py-1.5 text-right tabular-nums">{{ $row['orders'] }}</td><td class="px-5 py-1.5 text-right tabular-nums">RM {{ number_format((float) $row['net'], 2) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <p class="mt-6 text-xs text-stone-500">Generated {{ \App\Services\Reports\ReportPeriod::now()->format('j M Y, g:i a') }} (Malaysia time). Refunds are counted against the order's payment date.</p>
</x-layouts.admin>
