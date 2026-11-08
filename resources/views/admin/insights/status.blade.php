@use('App\Http\Controllers\Admin\InsightsController')

@php
    $modelTitles = ['revenue' => 'Revenue forecast', 'restock' => 'Restock prediction', 'price' => 'Price recommendation'];
    $retrain = $result->ok() ? $result->get('retrain', []) : [];
    $online = $result->ok();
@endphp

<x-layouts.admin title="ML status">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-semibold">ML status</h1>
            <p class="mt-1 text-sm text-stone-600">The ML service (ml-service, FastAPI) that powers the forecast, restock report and price suggestions.</p>
        </div>
        <span @class(['inline-flex items-center gap-2 rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset',
                      'bg-emerald-50 text-emerald-800 ring-emerald-600/20' => $online, 'bg-red-50 text-red-800 ring-red-600/20' => ! $online]) data-api-status>
            <span @class(['size-2 rounded-full', 'bg-emerald-600' => $online, 'bg-red-600' => ! $online])></span>
            API {{ $online ? 'online' : 'offline' }}
        </span>
    </div>

    @if (! $online)
        <x-admin.ml-offline :result="$result" class="mt-6" />
        <p class="mt-4 text-sm text-stone-600">
            To start it: run <code class="rounded bg-stone-100 px-1">ml-service\run_api.bat</code> (MySQL must be running).
            @unless ($configured) Also set <code class="rounded bg-stone-100 px-1">ML_API_URL</code> and <code class="rounded bg-stone-100 px-1">ML_API_KEY</code> in .env. @endunless
        </p>
    @else
        <div class="mt-6 grid gap-4 lg:grid-cols-3">
            @foreach ($result->get('models', []) as $key => $model)
                <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm" data-model="{{ $key }}">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="text-base font-semibold">{{ $modelTitles[$key] ?? $key }}</h2>
                        <span @class(['rounded-full px-2 py-0.5 text-xs font-medium', 'bg-emerald-50 text-emerald-800' => $model['available'], 'bg-red-50 text-red-800' => ! $model['available']])>
                            {{ $model['available'] ? 'Loaded' : 'Missing' }}
                        </span>
                    </div>
                    @if ($model['available'])
                        <dl class="mt-3 space-y-2 text-sm">
                            <div><dt class="text-stone-500">Model</dt><dd class="font-medium">{{ $model['name'] }}</dd></div>
                            <div><dt class="text-stone-500">Trained until</dt><dd>{{ InsightsController::day($model['trained_until']) }}</dd></div>
                            @if ($model['key_metric'])
                                <div><dt class="text-stone-500">{{ ucfirst($model['key_metric']['name']) }}</dt><dd class="text-lg font-semibold tabular-nums">{{ is_numeric($model['key_metric']['value']) && $model['key_metric']['value'] < 1 ? number_format($model['key_metric']['value'], 3) : number_format((float) $model['key_metric']['value'], 2) }}</dd></div>
                            @endif
                        </dl>
                    @else
                        <p class="mt-3 text-sm text-red-700">{{ $model['error'] }}</p>
                    @endif
                </section>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-2">
            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">Retrain</h2>
                <p class="mt-1 text-sm text-stone-600">Refits all three models on the latest sales (about 1 minute, in the background). It also runs automatically every Sunday at 3:00 am.</p>
                <p class="mt-3 text-sm" data-retrain-status>
                    Last retrain: <span class="font-medium">{{ $retrain['status'] ?? 'idle' }}</span>
                    @if (! empty($retrain['finished_at']))
                        · finished {{ \Illuminate\Support\Carbon::parse($retrain['finished_at'])->shopDateTime() }}
                    @endif
                    @if (! empty($retrain['error']))
                        <span class="block text-red-700">{{ $retrain['error'] }}</span>
                    @endif
                </p>
                <form method="POST" action="{{ route('admin.insights.retrain') }}" class="mt-4"
                      onsubmit="return confirm('Retrain all ML models now? It takes about a minute; the pages keep working meanwhile.')">
                    @csrf
                    <button type="submit" @disabled(($retrain['status'] ?? null) === 'running')
                            class="rounded-md bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">Retrain now</button>
                </form>
            </section>

            <section class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
                <h2 class="text-base font-semibold">Cache</h2>
                <p class="mt-1 text-sm text-stone-600">The forecast and restock report are kept for 1 hour, then recalculated from the database.</p>
                <ul class="mt-3 divide-y divide-stone-100 text-sm">
                    @forelse ($result->get('cache', []) as $entry)
                        <li class="flex justify-between py-2"><span class="font-mono text-xs">{{ $entry['key'] }}</span><span class="text-stone-600">{{ round($entry['age_seconds'] / 60) }} min old</span></li>
                    @empty
                        <li class="py-2 text-stone-500">Nothing cached yet.</li>
                    @endforelse
                </ul>
            </section>
        </div>
    @endif
</x-layouts.admin>
