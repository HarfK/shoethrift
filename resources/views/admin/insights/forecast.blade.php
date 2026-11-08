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
</x-layouts.admin>
