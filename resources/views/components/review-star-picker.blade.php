{{--
    Half-star picker (0.5 to 5.0). The radios are in natural order in the HTML, so the value under
    the cursor is what gets submitted even if the stylesheet is missing or stale (the cause of the
    old "only 1 star" bug: that picker reversed the order and relied on CSS to flip it back).
    Each star is two radios: its left half (x.5) and right half (x.0). Highlighting is plain CSS
    in resources/css/app.css (.star-picker); the "3.5 / 5" readout is updated by one oninput.
--}}
@props(['value' => null, 'name' => 'rating'])

@php
    $current = $value !== null && $value !== '' ? (float) $value : null;
    $star = 'M10 1.6l2.6 5.3 5.8.8-4.2 4.1 1 5.8L10 14.9l-5.2 2.7 1-5.8L1.6 7.7l5.8-.8z';
    $format = fn (float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');
@endphp

<fieldset {{ $attributes }} oninput="this.querySelector('output').value = event.target.value ? event.target.value.replace(/\.0$/, '') + ' / 5' : ''">
    <legend class="text-sm font-medium text-stone-700">Your rating</legend>
    <div class="star-picker mt-2 inline-flex items-center">
        @for ($step = 1; $step <= 10; $step++)
            @php
                $rating = $step / 2;
                $isLeftHalf = $step % 2 === 1;
                $text = $format($rating).' '.str('star')->plural($rating == 1 ? 1 : 2);
            @endphp
            <input type="radio" id="{{ $name }}-{{ $step }}" name="{{ $name }}" value="{{ number_format($rating, 1) }}"
                   @checked($current !== null && abs($current - $rating) < 0.01) required>
            <label for="{{ $name }}-{{ $step }}" title="{{ $text }}" data-rating="{{ number_format($rating, 1) }}">
                <span class="sr-only">{{ $text }}</span>
                <svg viewBox="{{ $isLeftHalf ? '0 0 10 20' : '10 0 10 20' }}" width="18" height="36" fill="currentColor" aria-hidden="true"><path d="{{ $star }}"/></svg>
            </label>
        @endfor
        <output class="ml-3 text-sm font-semibold tabular-nums text-stone-700" aria-live="polite">{{ $current !== null ? $format($current).' / 5' : '' }}</output>
    </div>
</fieldset>
