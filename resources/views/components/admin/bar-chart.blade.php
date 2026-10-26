{{--
    Server-rendered SVG bar chart (no JS library). $data: [key => ['label' => ..., 'net' => '123.45', 'orders' => n]].
    Each bar has a <title> tooltip; a data table follows for screen readers and exact values.
--}}
@props(['data', 'caption' => 'Net revenue per day'])

@php
    $values = array_map(fn ($row) => (float) $row['net'], array_values($data));
    $max = max([...$values, 0]);
    // Round the axis up to a "nice" number: 1, 2 or 5 × 10^n.
    $magnitude = $max > 0 ? 10 ** floor(log10($max)) : 1;
    $axisMax = $max > 0 ? collect([1, 2, 5, 10])->map(fn ($step) => $step * $magnitude)->first(fn ($nice) => $nice >= $max) : 100;

    $width = 640; $height = 220; $left = 56; $bottom = 28; $top = 10;
    $plotWidth = $width - $left - 8; $plotHeight = $height - $bottom - $top;
    $count = max(count($values), 1);
    $slot = $plotWidth / $count;
    $barWidth = max($slot * 0.7, 1);
    $labelEvery = (int) ceil($count / 8);
@endphp

<figure {{ $attributes }}>
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="h-auto w-full" role="img" aria-label="{{ $caption }}: bar chart, details in the table below">
        @foreach ([0, 0.5, 1] as $fraction)
            @php $y = $top + $plotHeight * (1 - $fraction); @endphp
            <line x1="{{ $left }}" x2="{{ $width - 8 }}" y1="{{ $y }}" y2="{{ $y }}" stroke="#e7e5e4" stroke-width="1" />
            <text x="{{ $left - 6 }}" y="{{ $y + 4 }}" text-anchor="end" font-size="11" fill="#78716c">RM {{ number_format($axisMax * $fraction) }}</text>
        @endforeach

        @foreach (array_values($data) as $i => $row)
            @php
                $barHeight = $axisMax > 0 ? $plotHeight * ((float) $row['net'] / $axisMax) : 0;
                $x = $left + $slot * $i + ($slot - $barWidth) / 2;
            @endphp
            <rect x="{{ round($x, 2) }}" y="{{ round($top + $plotHeight - $barHeight, 2) }}" width="{{ round($barWidth, 2) }}" height="{{ round(max($barHeight, 0), 2) }}"
                  rx="2" fill="#047857" data-bar>
                <title>{{ $row['label'] }}: RM {{ number_format((float) $row['net'], 2) }} ({{ $row['orders'] }} {{ str('order')->plural($row['orders']) }})</title>
            </rect>
            @if ($i % $labelEvery === 0)
                <text x="{{ round($x + $barWidth / 2, 2) }}" y="{{ $height - 8 }}" text-anchor="middle" font-size="11" fill="#78716c">{{ $row['label'] }}</text>
            @endif
        @endforeach
    </svg>

    <details class="mt-2 text-sm">
        <summary class="cursor-pointer text-stone-600 hover:text-stone-900">Show data</summary>
        <table class="mt-2 w-full text-left">
            <caption class="sr-only">{{ $caption }}</caption>
            <thead class="text-xs uppercase text-stone-500"><tr><th scope="col" class="py-1">Day</th><th scope="col" class="py-1 text-right">Orders</th><th scope="col" class="py-1 text-right">Net revenue</th></tr></thead>
            <tbody class="divide-y divide-stone-100">
                @foreach ($data as $row)
                    <tr><td class="py-1">{{ $row['label'] }}</td><td class="py-1 text-right tabular-nums">{{ $row['orders'] }}</td><td class="py-1 text-right tabular-nums">RM {{ number_format((float) $row['net'], 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </details>
</figure>
