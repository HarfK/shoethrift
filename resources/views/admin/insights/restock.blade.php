@use('App\Http\Controllers\Admin\InsightsController')

@php
    $riskStyle = [
        'HIGH' => 'bg-red-50 text-red-800 ring-red-600/20',
        'MEDIUM' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'LOW' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'OVERSTOCK' => 'bg-violet-50 text-violet-800 ring-violet-600/20',
    ];
    $inStockRateHelp = 'This group had fewer than 2 pairs, so its recent sales understate demand. The demand shown is its sales rate on the days it actually had stock (last 180 days), because that was higher than the model\'s estimate.';
    $cover = fn ($days) => $days === null ? 'No demand' : ($days == 0 ? 'Out of stock' : number_format($days, 1).' days');
    // Links keep the other choice: the risk filter keeps the view, the view toggle keeps the risk.
    $url = fn (array $params) => route('admin.insights.restock', array_filter([
        'view' => $view === 'list' ? 'list' : null, 'risk' => $risk, ...$params,
    ]));
    $chipTitle = fn (array $size) => $size['share'] !== null
        ? $size['share'].'% of this band\'s recent sales (last 180 days)'.($size['priority'] ? ' · priority' : '')
        : ($size['priority'] ? 'Priority size' : 'Size to look for');
    $chipClass = fn (array $size) => $size['priority']
        ? 'bg-emerald-700 text-white ring-emerald-700 print:bg-white print:font-bold print:text-stone-900 print:ring-stone-900'
        : 'bg-white text-stone-700 ring-stone-300';
    $isList = $view === 'list';
@endphp

<x-layouts.admin title="Restock">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Restock{{ $isList ? ' shopping list' : '' }}{{ $isList && $risk ? ' · '.ucfirst(strtolower($risk)) : '' }}</h1>
            <p class="mt-1 text-sm text-stone-600">Demand for the next 14 days per shoe model and size band, and what to look for in the next bale.</p>
        </div>
        @if ($isList && $result->ok())
            <div class="flex flex-wrap gap-2 print:hidden" data-list-actions>
                <button type="button" onclick="window.print()" class="rounded-md border border-stone-300 bg-white px-4 py-2 text-sm font-medium hover:bg-stone-50">Print</button>
                <a href="{{ route('admin.insights.restock.csv', array_filter(['risk' => $risk])) }}" class="rounded-md bg-stone-800 px-4 py-2 text-sm font-medium text-white hover:bg-stone-900">Export CSV</a>
            </div>
        @endif
    </div>

    @if (! $result->ok())
        <x-admin.ml-offline :result="$result" class="mt-6" />
    @else
        <p class="mt-2 text-xs text-stone-500">As of {{ InsightsController::day($asOf) }}, using sales up to {{ InsightsController::day($dataUntil) }}.</p>

        <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5 print:hidden">
            @foreach (InsightsController::RISKS as $level)
                <a href="{{ $url(['risk' => $level]) }}" class="block rounded-xl border border-stone-200 bg-white p-4 shadow-sm hover:border-stone-300" data-risk-card="{{ $level }}">
                    <dt><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $riskStyle[$level] }}">{{ $level }}</span></dt>
                    <dd class="mt-2 text-2xl font-semibold tabular-nums">{{ $summary['by_risk'][$level] ?? 0 }}</dd>
                    <dd class="text-xs text-stone-500">{{ str('group')->plural($summary['by_risk'][$level] ?? 0) }}</dd>
                </a>
            @endforeach
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm" data-card="total-restock">
                <dt class="text-xs font-medium text-emerald-800">Pairs to restock</dt>
                <dd class="mt-2 text-2xl font-semibold tabular-nums text-emerald-900">{{ $summary['total_pairs_suggested'] }}</dd>
                <dd class="text-xs text-emerald-800">across {{ $summary['groups_to_restock'] }} {{ str('group')->plural($summary['groups_to_restock']) }}</dd>
            </div>
        </dl>

        <section class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm print:overflow-visible print:border-0 print:shadow-none">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-5 py-4 print:hidden">
                <div class="flex flex-wrap items-center gap-3">
                    {{-- View toggle --}}
                    <nav class="inline-flex rounded-lg bg-stone-100 p-0.5 text-sm" aria-label="View" data-view-toggle>
                        @foreach (['groups' => 'By group', 'list' => 'Shopping list'] as $value => $label)
                            <a href="{{ route('admin.insights.restock', array_filter(['view' => $value === 'list' ? 'list' : null, 'risk' => $risk])) }}"
                               @if ($view === $value) aria-current="page" @endif
                               @class(['rounded-md px-3 py-1 font-medium', 'bg-white text-stone-900 shadow-sm' => $view === $value, 'text-stone-600 hover:text-stone-900' => $view !== $value])>{{ $label }}</a>
                        @endforeach
                    </nav>
                    <h2 class="text-base font-semibold">
                        @if ($isList)
                            {{ count($shoppingList) }} {{ str('model')->plural(count($shoppingList)) }}
                            <span class="font-normal text-stone-500">· {{ collect($shoppingList)->sum('total') }} pairs to find</span>
                        @else
                            Groups{{ $risk ? ' · '.$risk : '' }} <span class="font-normal text-stone-500">({{ count($groups) }})</span>
                        @endif
                    </h2>
                </div>
                <nav class="flex flex-wrap gap-1 text-sm" aria-label="Filter by risk">
                    <a href="{{ $url(['risk' => null]) }}" @class(['rounded-md px-3 py-1', 'bg-stone-900 text-white' => ! $risk, 'text-stone-700 hover:bg-stone-100' => $risk])>All</a>
                    @foreach (InsightsController::RISKS as $level)
                        <a href="{{ $url(['risk' => $level]) }}" @if ($risk === $level) aria-current="page" @endif
                           @class(['rounded-md px-3 py-1', 'bg-stone-900 text-white' => $risk === $level, 'text-stone-700 hover:bg-stone-100' => $risk !== $level])>{{ ucfirst(strtolower($level)) }}</a>
                    @endforeach
                </nav>
            </div>

            @if ($isList)
                {{-- Shopping list: per model, sizes grouped by band. Checkboxes are for ticking off while sourcing (not saved). --}}
                <p class="hidden px-1 pb-3 text-sm text-stone-600 print:block">{{ collect($shoppingList)->sum('total') }} pairs across {{ count($shoppingList) }} {{ str('model')->plural(count($shoppingList)) }}. Bold sizes are the priorities.</p>
                <ul class="divide-y divide-stone-100 print:divide-stone-300" data-shopping-list>
                    @forelse ($shoppingList as $model)
                        <li class="break-inside-avoid px-5 py-4 print:px-1" data-model="{{ $model['name'] }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-2">
                                <div>
                                    <span class="font-semibold">{{ $model['name'] }}</span>
                                    <span class="ml-1 inline-flex rounded-full px-2 py-0.5 align-middle text-xs font-medium ring-1 ring-inset {{ $riskStyle[$model['risk']] ?? '' }}">{{ $model['risk'] }}</span>
                                    <div class="text-xs text-stone-500">{{ $model['brand'] }} · {{ $model['category'] }}</div>
                                </div>
                                <div class="text-sm"><span class="text-lg font-semibold tabular-nums" data-model-total>{{ $model['total'] }}</span> <span class="text-stone-500">{{ str('pair')->plural($model['total']) }} to find</span></div>
                            </div>
                            <div class="mt-3 space-y-2">
                                @foreach ($model['bands'] as $band)
                                    <div class="flex flex-wrap items-center gap-2" data-band="{{ $band['band'] }}">
                                        <span class="w-36 shrink-0 text-xs font-medium text-stone-600">{{ $band['band_label'] }} <span class="font-normal text-stone-400">({{ $band['qty'] }})</span></span>
                                        <div class="flex flex-wrap gap-1.5">
                                            @forelse ($band['sizes'] as $size)
                                                <label title="{{ $chipTitle($size) }}" data-size-chip="{{ $size['size_label'] }}" @if ($size['priority']) data-priority @endif
                                                       class="inline-flex cursor-pointer select-none items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset has-[:checked]:bg-stone-100 has-[:checked]:font-normal has-[:checked]:text-stone-400 has-[:checked]:line-through has-[:checked]:ring-stone-200 {{ $chipClass($size) }}">
                                                    <input type="checkbox" class="size-3.5 accent-stone-700">
                                                    {{ $size['label'] }} ×{{ $size['qty'] }}
                                                </label>
                                            @empty
                                                <span class="text-xs text-stone-500">{{ $band['qty'] }} {{ str('pair')->plural($band['qty']) }}, any size in the band</span>
                                            @endforelse
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-stone-500">Nothing to restock{{ $risk ? ' at this risk level' : '' }}.</li>
                    @endforelse
                </ul>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-100 text-sm">
                        <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Group</th>
                                <th scope="col" class="px-4 py-3">Risk</th>
                                <th scope="col" class="px-4 py-3 text-right">Stock</th>
                                <th scope="col" class="px-4 py-3 text-right">Demand (14 days)</th>
                                <th scope="col" class="px-4 py-3 text-right">Days of cover</th>
                                <th scope="col" class="px-4 py-3 text-right">Restock</th>
                                <th scope="col" class="px-4 py-3">Sizes to look for</th>
                                <th scope="col" class="px-4 py-3">Demand source</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100" data-restock-table>
                            @forelse ($groups as $group)
                                <tr class="align-top">
                                    <td class="px-4 py-3">
                                        <div class="font-medium">{{ $group['name'] }}</div>
                                        <div class="text-xs text-stone-500">{{ $group['brand'] }} · {{ $group['category'] }}</div>
                                        <span class="mt-1 inline-flex whitespace-nowrap rounded-md bg-stone-100 px-1.5 py-0.5 text-xs font-medium text-stone-700 ring-1 ring-inset ring-stone-200" data-band-badge>{{ $group['band_label'] }}</span>
                                    </td>
                                    <td class="px-4 py-3"><span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $riskStyle[$group['risk']] ?? '' }}">{{ $group['risk'] }}</span></td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ $group['stock'] }}</td>
                                    <td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $group['predicted_demand_14d'], 1) }}</td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ $cover($group['days_of_cover']) }}</td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ $group['suggested_restock_qty'] ?: '–' }}</td>
                                    <td class="px-4 py-3">
                                        @if ($group['sizes'])
                                            <div class="flex max-w-xs flex-wrap gap-1" data-sizes>
                                                @foreach ($group['sizes'] as $size)
                                                    <span title="{{ $chipTitle($size) }}" data-size-chip="{{ $size['size_label'] }}" @if ($size['priority']) data-priority @endif
                                                          class="inline-flex cursor-help whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $chipClass($size) }}">{{ $size['label'] }} ×{{ $size['qty'] }}</span>
                                                @endforeach
                                            </div>
                                        @else
                                            <span class="text-stone-400">–</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        @if ($group['demand_source'] === 'in-stock rate')
                                            <span class="inline-flex items-center gap-1 text-amber-800" title="{{ $inStockRateHelp }}" data-in-stock-rate>
                                                In-stock rate
                                                <span aria-hidden="true" class="cursor-help rounded-full border border-amber-400 px-1 text-[10px] leading-4">?</span>
                                                <span class="sr-only">: {{ $inStockRateHelp }}</span>
                                            </span>
                                        @else
                                            <span class="text-stone-600">Model</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="8" class="px-4 py-6 text-center text-stone-500">No groups at this risk level.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-stone-100 px-5 py-2 text-xs text-stone-500">Sizes are sorted small to large. <span class="inline-block size-2.5 rounded-full bg-emerald-700 align-middle"></span> = priority (2+ pairs, or the most popular size); hover a size for its share of recent sales.</p>
            @endif
        </section>

        <div class="mt-6 grid gap-6 xl:grid-cols-2 print:hidden">
            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm" data-overstock>
                <div class="border-b border-stone-200 px-5 py-4">
                    <h2 class="text-base font-semibold">Overstock</h2>
                    <p class="mt-1 text-sm text-stone-600">More than 90 days of stock and at least 3 pairs. Consider a {{ InsightsController::OVERSTOCK_MARKDOWN_PERCENT }}% markdown, a bundle deal, or pausing these models in the next bale.</p>
                </div>
                <ul class="divide-y divide-stone-100 text-sm">
                    @forelse ($overstock as $group)
                        <li class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                            <div>
                                <a href="{{ $group['products_url'] }}" class="font-medium text-emerald-700 hover:underline">{{ $group['name'] }}</a>
                                <span class="ml-1 inline-flex whitespace-nowrap rounded-md bg-stone-100 px-1.5 py-0.5 text-xs font-medium text-stone-700 ring-1 ring-inset ring-stone-200">{{ $group['band_label'] }}</span>
                                <div class="text-xs text-stone-500">{{ $group['stock'] }} pairs · demand {{ number_format((float) $group['predicted_demand_14d'], 1) }} in 14 days · {{ $cover($group['days_of_cover']) }}</div>
                            </div>
                            <span class="text-xs font-medium text-violet-800">Suggest −{{ InsightsController::OVERSTOCK_MARKDOWN_PERCENT }}%</span>
                        </li>
                    @empty
                        <li class="px-5 py-6 text-center text-stone-500">No overstocked groups.</li>
                    @endforelse
                </ul>
            </section>

            <section class="overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
                <h2 class="border-b border-stone-200 px-5 py-4 text-base font-semibold">By brand</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-100 text-sm" data-brand-table>
                        <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Brand</th>
                                <th scope="col" class="px-4 py-3 text-right">Stock</th>
                                <th scope="col" class="px-4 py-3 text-right">Demand (14 days)</th>
                                <th scope="col" class="px-4 py-3 text-right">Cover</th>
                                <th scope="col" class="px-4 py-3 text-right">Restock</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($brands as $brand)
                                <tr>
                                    <td class="px-4 py-2.5 font-medium">{{ $brand['brand'] }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $brand['stock'] }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $brand['predicted_demand_14d'], 1) }}</td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-right tabular-nums">{{ $cover($brand['days_of_cover']) }}</td>
                                    <td class="px-4 py-2.5 text-right font-semibold tabular-nums">{{ $brand['suggested_restock_qty'] ?: '–' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif
</x-layouts.admin>
