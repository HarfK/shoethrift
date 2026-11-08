{{--
    Server-rendered SVG line chart (no chart library): actual revenue (last 30 days) + forecast with its 80% range.

    $actual:   [['date', 'revenue', 'partial_day', 'event', 'event_key', 'major']]
    $forecast: [['date', 'forecast', 'lower', 'upper', 'event', 'event_key', 'major', 'uplift']]
    $today:    Y-m-d (Malaysia); defaults to the last actual day.

    - Major events (11.11, 12.12, Black Friday, pre-Raya, Hari Raya) get ONE red pill label per event, in a label lane
      above the plot with a leader line to the event's first day (red dot). Pills that would overlap go to a new row.
    - Payday days (25th–2nd) and the pre-Raya window are light vertical bands behind the lines.
    - Hovering a day shows a tooltip (small vanilla JS); an sr-only table repeats every value.
--}}
@props(['actual' => [], 'forecast' => [], 'today' => null, 'caption' => 'Daily net revenue: actual and forecast'])

@php
    $carbon = fn (string $date) => \Illuminate\Support\Carbon::parse($date, 'UTC');
    $rows = [];
    foreach ($actual as $a) {
        $rows[] = ['date' => $a['date'], 'kind' => 'actual', 'value' => (float) $a['revenue'], 'lower' => null, 'upper' => null,
            'event' => $a['event'] ?? null, 'key' => $a['event_key'] ?? null, 'major' => (bool) ($a['major'] ?? false),
            'uplift' => null, 'partial' => (bool) ($a['partial_day'] ?? false), 'weekday' => $a['weekday'] ?? null];
    }
    foreach ($forecast as $f) {
        $rows[] = ['date' => $f['date'], 'kind' => 'forecast', 'value' => (float) $f['forecast'], 'lower' => (float) $f['lower'], 'upper' => (float) $f['upper'],
            'event' => $f['event'] ?? null, 'key' => $f['event_key'] ?? null, 'major' => (bool) ($f['major'] ?? false),
            'uplift' => $f['uplift'] ?? null, 'partial' => false, 'weekday' => $f['weekday'] ?? null];
    }
    $n = max(count($rows), 1);
    $today = $today ?? (end($actual)['date'] ?? ($rows[0]['date'] ?? now()->toDateString()));
    $todayIndex = collect($rows)->search(fn ($r) => $r['date'] === $today);
    $todayIndex = $todayIndex === false ? max(count($actual) - 1, 0) : $todayIndex;

    // Y axis: a "nice" step (1, 2, 2.5 or 5 x 10^n) for 4-5 gridlines, max just above the highest value shown.
    $highest = max([0, ...array_map(fn ($r) => $r['upper'] ?? $r['value'], $rows)]);
    $magnitude = $highest > 0 ? 10 ** floor(log10($highest / 4)) : 1;
    $step = collect([1, 2, 2.5, 5, 10])->map(fn ($m) => $m * $magnitude)->first(fn ($s) => $highest / $s <= 5) ?? 10 * $magnitude;
    $axisMax = max($step, ceil($highest / $step) * $step);
    if ($axisMax <= $highest) { $axisMax += $step; }
    $rmTick = fn (float $v) => $v >= 1000 ? 'RM '.rtrim(rtrim(number_format($v / 1000, 1), '0'), '.').'k' : 'RM '.number_format($v);

    // Major events: one occurrence per run of consecutive days with the same event.
    $events = [];
    foreach ($rows as $i => $row) {
        if ($row['major'] && $row['key'] && ($i === 0 || $rows[$i - 1]['key'] !== $row['key'])) {
            $events[] = ['index' => $i, 'text' => $row['event'] ?? $row['key']];
        }
    }

    // Layout. Pill rows are assigned before the y scale, because the label lane sits above the plot.
    $width = 760; $left = 58; $right = 16;
    $plotWidth = $width - $left - $right;
    $slot = $plotWidth / $n;
    $x = fn (int $i) => round($left + $slot * ($i + 0.5), 2);
    $pillH = 16; $pillGap = 4; $rowGap = 4;
    $levels = [];
    foreach ($events as $k => $event) {
        $w = round(mb_strlen($event['text']) * 5.6 + 14, 1);
        $pillLeft = min(max($x($event['index']) - $w / 2, $left), $width - $right - $w);
        $level = 0;
        while (isset($levels[$level]) && $levels[$level] + $pillGap > $pillLeft) {
            $level++;
        }
        $levels[$level] = $pillLeft + $w;
        $events[$k] += ['w' => $w, 'pillLeft' => $pillLeft, 'level' => $level];
    }
    $laneHeight = $events ? count($levels) * ($pillH + $rowGap) + 6 : 0;
    $top = 14 + $laneHeight;
    $plotHeight = 210;
    $bottom = $top + $plotHeight;
    $height = $bottom + 34;
    $y = fn (float $v) => round($bottom - $plotHeight * $v / $axisMax, 2);

    // Background bands: payday (25th-2nd) and the pre-Raya window, merged into runs of consecutive days.
    $runs = function (callable $test) use ($rows) {
        $out = []; $start = null;
        foreach ($rows as $i => $row) {
            if ($test($row)) { $start ??= $i; }
            elseif ($start !== null) { $out[] = [$start, $i - 1]; $start = null; }
        }
        if ($start !== null) { $out[] = [$start, count($rows) - 1]; }

        return $out;
    };
    $payday = $runs(fn ($r) => (int) $carbon($r['date'])->day >= 25 || (int) $carbon($r['date'])->day <= 2);
    $preRaya = $runs(fn ($r) => $r['key'] === 'pre_raya');

    $actualPoints = implode(' ', array_map(fn ($i) => $x($i).','.$y($rows[$i]['value']), array_keys(array_filter($rows, fn ($r) => $r['kind'] === 'actual'))));
    $forecastIdx = array_keys(array_filter($rows, fn ($r) => $r['kind'] === 'forecast'));
    $forecastPoints = implode(' ', array_map(fn ($i) => $x($i).','.$y($rows[$i]['value']), $forecastIdx));
    $band = $forecastIdx
        ? implode(' ', array_map(fn ($i) => $x($i).','.$y($rows[$i]['upper']), $forecastIdx)).' '.implode(' ', array_map(fn ($i) => $x($i).','.$y($rows[$i]['lower']), array_reverse($forecastIdx)))
        : '';
    $eventStarts = array_column($events, 'index');

    $tip = function (array $r) use ($carbon) {
        $lines = [($r['weekday'] ? substr($r['weekday'], 0, 3).', ' : '').$carbon($r['date'])->shopDate()];
        if ($r['kind'] === 'actual') {
            $lines[] = 'Actual: RM '.number_format($r['value'], 2).($r['partial'] ? ' (today so far)' : '');
        } else {
            $lines[] = 'Forecast: RM '.number_format($r['value'], 2);
            $lines[] = '80% range: RM '.number_format($r['lower']).' – '.number_format($r['upper']);
        }
        if ($r['event']) {
            $lines[] = $r['event'].($r['uplift'] ? ' (×'.number_format($r['uplift'], 2).')' : '');
        }

        return implode("\n", $lines);
    };
    $chartId = 'forecast-chart-'.\Illuminate\Support\Str::random(6);
@endphp

<figure {{ $attributes->class('relative') }} id="{{ $chartId }}">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full select-none" role="img" aria-label="{{ $caption }}: line chart, all values are in the table below" data-forecast-chart>
        {{-- background bands --}}
        @foreach ($payday as [$from, $to])
            <rect x="{{ round($left + $slot * $from, 2) }}" y="{{ $top }}" width="{{ round($slot * ($to - $from + 1), 2) }}" height="{{ $plotHeight }}" fill="#78716c" fill-opacity="0.07" data-payday-band />
        @endforeach
        @foreach ($preRaya as [$from, $to])
            <rect x="{{ round($left + $slot * $from, 2) }}" y="{{ $top }}" width="{{ round($slot * ($to - $from + 1), 2) }}" height="{{ $plotHeight }}" fill="#f59e0b" fill-opacity="0.10" data-preraya-band />
        @endforeach

        {{-- y gridlines --}}
        @for ($v = 0; $v <= $axisMax + 0.001; $v += $step)
            <line x1="{{ $left }}" x2="{{ $width - $right }}" y1="{{ $y($v) }}" y2="{{ $y($v) }}" stroke="{{ $v == 0 ? '#d6d3d1' : '#efedeb' }}" stroke-width="1" />
            <text x="{{ $left - 8 }}" y="{{ $y($v) + 4 }}" text-anchor="end" font-size="11" fill="#78716c" data-y-tick>{{ $rmTick($v) }}</text>
        @endfor

        {{-- x ticks every 7 days, counted from today --}}
        @foreach ($rows as $i => $row)
            @if (($i - $todayIndex) % 7 === 0)
                <line x1="{{ $x($i) }}" x2="{{ $x($i) }}" y1="{{ $bottom }}" y2="{{ $bottom + 4 }}" stroke="#a8a29e" />
                <text x="{{ $x($i) }}" y="{{ $bottom + 17 }}" text-anchor="middle" font-size="10.5" fill="#78716c" data-x-tick>{{ \Illuminate\Support\Str::beforeLast($carbon($row['date'])->shopDate(), ' ') }}</text>
            @endif
        @endforeach

        {{-- today --}}
        <line x1="{{ $x($todayIndex) }}" x2="{{ $x($todayIndex) }}" y1="{{ $top - 2 }}" y2="{{ $bottom }}" stroke="#57534e" stroke-width="1" stroke-dasharray="4 3" data-today-line />
        <text x="{{ $x($todayIndex) + 4 }}" y="{{ $top + 9 }}" font-size="10" font-weight="600" fill="#57534e">Today</text>

        {{-- 80% band, actual line, forecast line --}}
        @if ($band)
            <polygon points="{{ $band }}" fill="#047857" fill-opacity="0.10" data-forecast-band />
        @endif
        @if ($actualPoints)
            <polyline points="{{ $actualPoints }}" fill="none" stroke="#44403c" stroke-opacity="0.85" stroke-width="1.5" stroke-linejoin="round" data-actual-line />
        @endif
        @if ($forecastPoints)
            <polyline points="{{ $forecastPoints }}" fill="none" stroke="#047857" stroke-width="2.75" stroke-linejoin="round" data-forecast-line />
        @endif

        {{-- points (zero days sit on the baseline but stay visible: white ring) --}}
        @foreach ($rows as $i => $row)
            @if ($row['kind'] === 'actual')
                <circle cx="{{ $x($i) }}" cy="{{ $y($row['value']) }}" r="2.4" fill="{{ $row['partial'] ? '#fff' : '#44403c' }}" stroke="{{ $row['partial'] ? '#44403c' : '#fff' }}" stroke-width="1" />
            @elseif (in_array($i, $eventStarts, true))
                <circle cx="{{ $x($i) }}" cy="{{ $y($row['value']) }}" r="4.5" fill="#b91c1c" stroke="#fff" stroke-width="1.5" data-event-dot />
            @else
                <circle cx="{{ $x($i) }}" cy="{{ $y($row['value']) }}" r="2.8" fill="#047857" stroke="#fff" stroke-width="1" />
            @endif
        @endforeach
        @foreach ($events as $event)
            @if ($rows[$event['index']]['kind'] === 'actual')
                <circle cx="{{ $x($event['index']) }}" cy="{{ $y($rows[$event['index']]['value']) }}" r="4.5" fill="#b91c1c" stroke="#fff" stroke-width="1.5" data-event-dot />
            @endif
        @endforeach

        {{-- event pills in the label lane: all leader lines first, then the pills, so pills always sit on top --}}
        @foreach ($events as $event)
            @php
                $pillY = 8 + $event['level'] * ($pillH + $rowGap);
                $pointY = $y($rows[$event['index']]['value']);
            @endphp
            <line x1="{{ $x($event['index']) }}" x2="{{ $x($event['index']) }}" y1="{{ $pillY + $pillH }}" y2="{{ max($pointY - 6, $pillY + $pillH) }}" stroke="#b91c1c" stroke-opacity="0.45" stroke-dasharray="2 2" />
        @endforeach
        @foreach ($events as $event)
            @php
                $pillY = 8 + $event['level'] * ($pillH + $rowGap);
            @endphp
            <g data-event-label>
                <rect x="{{ $event['pillLeft'] }}" y="{{ $pillY }}" width="{{ $event['w'] }}" height="{{ $pillH }}" rx="8" fill="#fef2f2" stroke="#fca5a5" />
                <text x="{{ round($event['pillLeft'] + $event['w'] / 2, 2) }}" y="{{ $pillY + 11.5 }}" text-anchor="middle" font-size="10" font-weight="600" fill="#991b1b">{{ $event['text'] }}</text>
            </g>
        @endforeach

        {{-- invisible hover targets (one per day, full height of its slot); the sr-only table below has every value --}}
        @foreach ($rows as $i => $row)
            <rect x="{{ round($left + $slot * $i, 2) }}" y="{{ $top }}" width="{{ round($slot, 2) }}" height="{{ $plotHeight }}" fill="transparent"
                  data-tip="{{ $tip($row) }}" data-tip-x="{{ $x($i) }}" data-tip-y="{{ $y($row['upper'] ?? $row['value']) }}" />
        @endforeach
    </svg>

    <div class="pointer-events-none absolute z-10 hidden whitespace-pre rounded-md bg-stone-900 px-2.5 py-1.5 text-xs leading-5 text-white shadow-lg" data-chart-tooltip role="tooltip"></div>

    <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-stone-600" data-chart-legend>
        <span><span class="mr-1 inline-block h-0.5 w-4 bg-stone-700 align-middle"></span>Actual</span>
        <span><span class="mr-1 inline-block h-1 w-4 rounded bg-emerald-700 align-middle"></span>Forecast</span>
        <span><span class="mr-1 inline-block h-2.5 w-4 bg-emerald-700/10 align-middle"></span>80% range</span>
        @if ($events)
            <span><span class="mr-1 inline-block size-2 rounded-full bg-red-700 align-middle"></span>Major event</span>
        @endif
        @if ($payday)
            <span><span class="mr-1 inline-block h-2.5 w-4 bg-stone-500/10 align-middle"></span>Payday period</span>
        @endif
        @if ($preRaya)
            <span><span class="mr-1 inline-block h-2.5 w-4 bg-amber-500/15 align-middle"></span>Pre-Raya period</span>
        @endif
    </p>

    <table class="sr-only">
        <caption>{{ $caption }}</caption>
        <thead><tr><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Net revenue</th><th scope="col">80% range</th><th scope="col">Event</th></tr></thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>{{ $carbon($row['date'])->shopDate() }}</td>
                    <td>{{ $row['kind'] === 'actual' ? ($row['partial'] ? 'Actual (today so far)' : 'Actual') : 'Forecast' }}</td>
                    <td>RM {{ number_format($row['value'], 2) }}</td>
                    <td>{{ $row['kind'] === 'forecast' ? 'RM '.number_format($row['lower']).' – '.number_format($row['upper']) : '' }}</td>
                    <td>{{ $row['event'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <script>
        (() => {
            const fig = document.getElementById(@js($chartId));
            const svg = fig.querySelector('svg');
            const tip = fig.querySelector('[data-chart-tooltip]');
            const show = (target) => {
                tip.textContent = target.dataset.tip;
                tip.classList.remove('hidden');
                const scale = svg.getBoundingClientRect().width / svg.viewBox.baseVal.width;
                const px = Number(target.dataset.tipX) * scale, py = Number(target.dataset.tipY) * scale;
                const left = Math.min(Math.max(px - tip.offsetWidth / 2, 0), fig.clientWidth - tip.offsetWidth);
                tip.style.left = left + 'px';
                tip.style.top = Math.max(py - tip.offsetHeight - 10, 0) + 'px';
            };
            const hide = () => tip.classList.add('hidden');
            svg.querySelectorAll('[data-tip]').forEach((el) => {
                el.addEventListener('mouseenter', () => show(el));
                el.addEventListener('mouseleave', hide);
            });
        })();
    </script>
</figure>
