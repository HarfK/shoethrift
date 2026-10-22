@props(['name', 'label', 'options' => [], 'value' => null, 'placeholder' => null])

{{-- $options is a [value => label] map. --}}
@php($selected = (string) old($name, $value))

<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</label>
    <select id="{{ $name }}" name="{{ $name }}"
            @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
            {{ $attributes->class([
                'block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
                'border-red-400' => $errors->has($name),
                'border-stone-300' => ! $errors->has($name),
            ]) }}>
        @if ($placeholder)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected($selected === (string) $optionValue)>{{ $optionLabel }}</option>
        @endforeach
    </select>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
