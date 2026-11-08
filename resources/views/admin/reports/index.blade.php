<x-layouts.admin title="Sales report">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Sales report</h1>
            <p class="mt-1 text-sm text-stone-600"><span class="font-medium text-stone-900" data-report-range>{{ $report->period->label }}</span> · paid orders only (COD once the cash is collected), dated by payment, Malaysia time.</p>
        </div>
        <div class="flex flex-wrap gap-2 print:hidden">
            <button type="button" onclick="window.print()" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Print</button>
            <a href="{{ route('admin.reports.pdf', $filters['query']) }}" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Download PDF</a>
            <a href="{{ route('admin.reports.csv', $filters['query']) }}" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Export CSV</a>
        </div>
    </div>

    {{-- Period filter: ONE picker that changes with the period (resources/js/report-picker.js, Flatpickr).
         It writes the hidden inputs; "Show report" submits a plain GET form. --}}
    <form method="GET" action="{{ route('admin.reports.index') }}" data-report-picker
          data-today="{{ $filters['today'] }}" data-max-days="{{ $filters['max_days'] }}"
          class="mt-6 rounded-xl border border-stone-200 bg-white p-4 print:hidden">
        <div class="flex flex-wrap items-end gap-3">
            <div>
                <label for="period" class="mb-1 block text-sm font-medium text-stone-700">Period</label>
                <select id="period" name="period" data-period class="block rounded-md border border-stone-300 bg-white px-3 py-2 text-sm">
                    @foreach (['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'custom' => 'Custom range'] as $value => $label)
                        <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="report-picker" class="mb-1 block text-sm font-medium text-stone-700" data-picker-label>Date</label>
                <div class="relative">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-stone-400"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2Zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75Z" clip-rule="evenodd"/></svg>
                    <input id="report-picker" type="text" readonly data-picker-input placeholder="Choose…" value="{{ $report->period->label }}"
                           class="block w-64 cursor-pointer rounded-md border border-stone-300 bg-white py-2 pl-9 pr-3 text-sm">
                </div>
            </div>
            {{-- The values the server reads; the picker keeps them in sync and disables the ones the period doesn't use. --}}
            <input type="hidden" name="date" value="{{ $filters['date'] ?? '' }}" data-field="date">
            <input type="hidden" name="month" value="{{ $filters['month'] ?? '' }}" data-field="month">
            <input type="hidden" name="from" value="{{ $filters['from'] ?? '' }}" data-field="from">
            <input type="hidden" name="to" value="{{ $filters['to'] ?? '' }}" data-field="to">
            <button type="submit" data-submit class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900 disabled:cursor-not-allowed disabled:opacity-40">Show report</button>
            <p class="self-center text-sm text-stone-600" aria-live="polite">
                <span data-picker-summary></span>
                <a href="#" data-picker-clear class="ml-2 hidden font-medium text-stone-900 underline hover:no-underline">Clear</a>
            </p>
        </div>

        {{-- Quick presets: plain links, so they work without JavaScript too. --}}
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm" data-presets>
            <span class="text-stone-500">Quick:</span>
            @foreach ($filters['presets'] as $label => $preset)
                <a href="{{ route('admin.reports.index', $preset['query']) }}" @if ($preset['active']) aria-current="true" @endif
                   class="rounded-full border px-3 py-1 font-medium {{ $preset['active'] ? 'border-stone-800 bg-stone-800 text-white' : 'border-stone-300 bg-white text-stone-700 hover:bg-stone-50' }}">{{ $label }}</a>
            @endforeach
        </div>

        @if ($errors->any())
            <p class="mt-3 text-sm text-red-600" role="alert">{{ $errors->first() }}</p>
        @endif
        <noscript><p class="mt-3 text-sm text-stone-500">The calendar needs JavaScript; the quick links above still work.</p></noscript>
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
            // Not tied to the period: loyalty points customers can still use, and their RM value.
            'Points outstanding (now)' => number_format($pointsOutstanding).' pts · RM '.number_format((float) $pointsOutstandingValue, 2),
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
