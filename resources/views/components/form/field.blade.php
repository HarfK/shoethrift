@props(['name', 'label', 'type' => 'text', 'value' => null])

<div class="mb-4">
    <label for="{{ $name }}" class="mb-1 block text-sm font-medium text-stone-700">{{ $label }}</label>
    <input id="{{ $name }}" name="{{ $name }}" type="{{ $type }}"
           @if ($type !== 'password') value="{{ old($name, $value) }}" @endif
           @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror
           {{ $attributes->class([
               'block w-full rounded-md border bg-white px-3 py-2 text-sm shadow-xs focus:outline-none focus:ring-2 focus:ring-emerald-600/40',
               'border-red-400' => $errors->has($name),
               'border-stone-300' => ! $errors->has($name),
           ]) }}>
    @error($name)
        <p id="{{ $name }}-error" class="mt-1 text-sm text-red-600">{{ $message }}</p>
    @enderror
</div>
