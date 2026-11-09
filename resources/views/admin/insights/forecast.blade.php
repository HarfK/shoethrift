@use('App\Http\Controllers\Admin\InsightsController')

<x-layouts.admin title="Revenue forecast">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">Revenue forecast</h1>
            <p class="mt-1 text-sm text-stone-600">Daily net revenue (paid orders, Malaysia time, net of refunds) for the coming days, with an 80% range.</p>
        </div>
        <nav class="inline-flex rounded-md border border-stone-300 bg-white p-0.5 text-sm" aria-label="Forecast length">
            @foreach ([7, 30] as $option)
                <a href="{{ route('admin.insights.forecast', ['days' => $option]) }}" @if ($days === $option) aria-current="page" @endif
                   @class(['rounded px-3 py-1.5 font-medium', 'bg-stone-900 text-white' => $days === $option, 'text-stone-700 hover:bg-stone-100' => $days !== $option])>Next {{ $option }} days</a>
            @endforeach
        </nav>
    </div>

    @if (! $result->ok())
        <x-admin.ml-offline :result="$result" class="mt-6" />
    @else
        <dl class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" data-card="next-7">
                <dt class="text-sm text-stone-600">Next 7 days (forecast)</dt>
                <dd class="mt-2 text-3xl font-semibold tabular-nums">RM {{ number_format((float) $next7['total'], 2) }}</dd>
                <dd class="mt-1 text-sm text-stone-600">
                    {{ InsightsController::day($next7['from']) }} – {{ InsightsController::day($next7['to']) }}
                    @if ($next7['lower'] !== null)
                        · 80% range RM {{ number_format((float) $next7['lower']) }}–{{ number_format((float) $next7['upper']) }}
                    @endif
                </dd>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" data-card="last-7">
                <dt class="text-sm text-stone-600">Last 7 days (actual)</dt>
                <dd class="mt-2 text-3xl font-semibold tabular-nums">RM {{ number_format((float) $last7['total'], 2) }}</dd>
                <dd class="mt-1 text-sm text-stone-600">
                    {{ InsightsController::day($last7['from']) }} – {{ InsightsController::day($last7['to']) }}
                    @if ($last7['total'] > 0)
                        @php($change = (int) round(($next7['total'] / $last7['total'] - 1) * 100))
                        · <span @class(['font-medium', 'text-emerald-700' => $change >= 0, 'text-red-700' => $change < 0])>{{ $change >= 0 ? '+' : '' }}{{ $change }}% expected</span>
                    @endif
                </dd>
            </div>
            <div class="rounded-xl border border-stone-200 bg-white p-5 text-sm shadow-sm sm:col-span-2 lg:col-span-1">
                <dt class="text-stone-600">How to read it</dt>
                <dd class="mt-2 text-stone-700">Single days swing a lot; plan with the weekly total. Major shopping days (11.11, 12.12, Black Friday, Raya) are labelled in red; payday periods are lightly shaded.</dd>
            </div>
        </dl>

        <section class="mt-6 rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <h2 class="text-base font-semibold">Last 30 days and the next {{ $days }} days</h2>
            <x-admin.forecast-chart :actual="$actual" :forecast="$forecast" :today="$today" class="mt-4" />
            <p class="mt-3 text-xs text-stone-500" data-trained-until>Model trained until {{ InsightsController::day($trainedUntil) }}. Retrain from <a href="{{ route('admin.insights.status') }}" class="underline">ML status</a> to include newer sales.</p>
        </section>

        <section class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm">
            <h2 class="border-b border-stone-200 px-5 py-4 text-base font-semibold">Forecast by day</h2>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-100 text-sm">
                    <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                        <tr>
                            <th scope="col" class="px-5 py-3">Date</th>
                            <th scope="col" class="px-5 py-3">Day</th>
                            <th scope="col" class="px-5 py-3">Event</th>
                            <th scope="col" class="px-5 py-3 text-right">Forecast</th>
                            <th scope="col" class="px-5 py-3 text-right">80% range</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100" data-forecast-table>
                        @foreach ($forecast as $row)
                            @php($major = $row['major'] ?? in_array($row['event_key'] ?? null, InsightsController::MAJOR_EVENTS, true))
                            <tr @class(['bg-red-50/50' => $row['event'] && $major])>
                                <td class="whitespace-nowrap px-5 py-2.5">{{ InsightsController::day($row['date']) }}</td>
                                <td class="px-5 py-2.5 text-stone-600">{{ $row['weekday'] }}</td>
                                <td class="px-5 py-2.5">
                                    @if ($row['event'])
                                        <span @class(['inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                                                      'bg-red-50 text-red-800 ring-red-600/20' => $major, 'bg-stone-50 text-stone-600 ring-stone-500/20' => ! $major])>{{ $row['event'] }}</span>
                                        @if ($row['uplift'])
                                            <span class="ml-1 text-xs text-stone-500">×{{ number_format($row['uplift'], 2) }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right font-medium tabular-nums">RM {{ number_format((float) $row['forecast'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums text-stone-600">RM {{ number_format((float) $row['lower']) }} – {{ number_format((float) $row['upper']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Forecast vs actual comes from the database, so it shows even when the ML API is offline. --}}
    <section class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white shadow-sm" data-forecast-accuracy>
        <div class="border-b border-stone-200 px-5 py-4">
            <h2 class="text-base font-semibold">Forecast vs actual</h2>
            <p class="mt-1 text-sm text-stone-600">Last {{ count($accuracy['rows']) }} complete days, {{ InsightsController::day($accuracy['from']) }} – {{ InsightsController::day($accuracy['to']) }}. Actual = paid orders, Malaysia time, net of refunds.</p>
        </div>

        <dl class="grid gap-4 border-b border-stone-200 p-5 sm:grid-cols-2">
            @foreach ([
                'saved' => ['Saved forecasts', 'Saved before the day happened. This is the real accuracy.'],
                'backfilled' => ['Backfilled (reference only)', 'Re-created later, using only sales from before those days. Not counted above.'],
            ] as $key => [$label, $help])
                <div @class(['rounded-lg border p-4', 'border-stone-300' => $key === 'saved', 'border-dashed border-stone-300 bg-stone-50' => $key === 'backfilled']) data-accuracy-summary="{{ $key }}">
                    <dt class="text-sm font-medium text-stone-800">{{ $label }} <span class="font-normal text-stone-500">({{ $accuracy[$key]['days'] }} {{ str('day')->plural($accuracy[$key]['days']) }})</span></dt>
                    @if ($accuracy[$key]['days'] > 0)
                        <dd class="mt-2 flex flex-wrap gap-x-6 gap-y-1">
                            <span title="Average size of the daily difference"><span class="text-xl font-semibold tabular-nums">RM {{ number_format((float) $accuracy[$key]['mae'], 2) }}</span> <span class="text-sm text-stone-600">MAE</span></span>
                            <span title="Total of the daily differences, as a share of total actual revenue"><span class="text-xl font-semibold tabular-nums">{{ $accuracy[$key]['wape'] !== null ? number_format($accuracy[$key]['wape'], 1).'%' : '–' }}</span> <span class="text-sm text-stone-600">WAPE</span></span>
                        </dd>
                    @else
                        <dd class="mt-2 text-sm text-stone-600">No days to compare yet.</dd>
                    @endif
                    <dd class="mt-1 text-xs text-stone-500">{{ $help }}</dd>
                </div>
            @endforeach
        </dl>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-100 text-sm">
                <thead class="text-left text-xs font-medium uppercase tracking-wide text-stone-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Date</th>
                        <th scope="col" class="px-5 py-3">Day</th>
                        <th scope="col" class="px-5 py-3 text-right">Forecast</th>
                        <th scope="col" class="px-5 py-3 text-right">Actual</th>
                        <th scope="col" class="px-5 py-3 text-right">Difference (RM)</th>
                        <th scope="col" class="px-5 py-3 text-right">Difference (%)</th>
                        <th scope="col" class="px-5 py-3">Source</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100" data-accuracy-table>
                    @foreach ($accuracy['rows'] as $row)
                        <tr data-accuracy-row="{{ $row['date'] }}">
                            <td class="whitespace-nowrap px-5 py-2.5">{{ InsightsController::day($row['date']) }}</td>
                            <td class="px-5 py-2.5 text-stone-600">{{ $row['weekday'] }}</td>
                            @if ($row['forecast'] !== null)
                                <td class="whitespace-nowrap px-5 py-2.5 text-right tabular-nums">RM {{ number_format((float) $row['forecast'], 2) }}</td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right font-medium tabular-nums">RM {{ number_format((float) $row['actual'], 2) }}</td>
                                <td @class(['whitespace-nowrap px-5 py-2.5 text-right tabular-nums', 'text-emerald-700' => (float) $row['difference'] > 0, 'text-red-700' => (float) $row['difference'] < 0])>{{ (float) $row['difference'] > 0 ? '+' : ((float) $row['difference'] < 0 ? '−' : '') }}RM {{ number_format(abs((float) $row['difference']), 2) }}</td>
                                <td @class(['whitespace-nowrap px-5 py-2.5 text-right tabular-nums', 'text-emerald-700' => $row['percent'] > 0, 'text-red-700' => $row['percent'] < 0])>{{ $row['percent'] === null ? '–' : ($row['percent'] > 0 ? '+' : ($row['percent'] < 0 ? '−' : '')).number_format(abs($row['percent']), 1).'%' }}</td>
                                <td class="px-5 py-2.5">
                                    @if ($row['backfilled'])
                                        <span class="inline-flex cursor-help rounded-full bg-stone-100 px-2 py-0.5 text-xs font-medium text-stone-600 ring-1 ring-inset ring-stone-300" title="Re-created later using only sales up to {{ InsightsController::day($row['made_on']) }}. It was not saved at the time." data-backfilled>Backfilled</span>
                                    @else
                                        <span class="text-xs text-stone-600" title="Saved on {{ InsightsController::day($row['made_on']) }}">Saved</span>
                                    @endif
                                </td>
                            @else
                                <td class="px-5 py-2.5 text-right text-stone-400">–</td>
                                <td class="whitespace-nowrap px-5 py-2.5 text-right font-medium tabular-nums">RM {{ number_format((float) $row['actual'], 2) }}</td>
                                <td class="px-5 py-2.5 text-right text-stone-400">–</td>
                                <td class="px-5 py-2.5 text-right text-stone-400">–</td>
                                <td class="px-5 py-2.5 text-xs text-stone-500">No forecast saved</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-stone-100 px-5 py-2 text-xs text-stone-500">Difference = actual − forecast, so + means sales beat the forecast. The % is of actual revenue, and shows – on days with no sales.</p>
    </section>
</x-layouts.admin>
