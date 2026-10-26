{{--
    Five stars filled to $value (0 to 5, decimals allowed via a width clip). $count (optional) is
    shown as "(3)" and read out as "from 3 reviews". Size via the `size` prop (Tailwind size-* class).
--}}
@props(['value', 'count' => null, 'size' => 'size-4'])

@php
    $value = max(0, min(5, (float) $value));
    $label = 'Rated '.rtrim(rtrim(number_format($value, 1), '0'), '.').' out of 5'
        .($count !== null ? ' from '.$count.' '.str('review')->plural($count) : '');
    $star = 'M10 1.6l2.6 5.3 5.8.8-4.2 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.6 7.7l5.8-.8z';
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1') }}>
    <span class="relative inline-flex" role="img" aria-label="{{ $label }}">
        <span class="flex text-stone-300" aria-hidden="true">
            @for ($i = 0; $i < 5; $i++)
                <svg viewBox="0 0 20 20" class="{{ $size }}" fill="currentColor"><path d="{{ $star }}"/></svg>
            @endfor
        </span>
        <span class="absolute inset-y-0 left-0 flex overflow-hidden text-amber-500" style="width: {{ $value / 5 * 100 }}%" aria-hidden="true">
            @for ($i = 0; $i < 5; $i++)
                <svg viewBox="0 0 20 20" class="{{ $size }} shrink-0" fill="currentColor"><path d="{{ $star }}"/></svg>
            @endfor
        </span>
    </span>
    @if ($count !== null)
        <span class="text-xs text-stone-600" aria-hidden="true">({{ $count }})</span>
    @endif
</span>
