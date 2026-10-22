@props(['status'])

@php
    $classes = [
        'pending' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'paid' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'shipped' => 'bg-indigo-50 text-indigo-800 ring-indigo-600/20',
        'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'cancelled' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
    ][$status->value];
@endphp

<span {{ $attributes->class(['inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    {{ $status->label() }}
</span>
