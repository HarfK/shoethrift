@props(['status'])

@php
    $classes = [
        'requested' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'approved' => 'bg-violet-50 text-violet-800 ring-violet-600/20',
        'rejected' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
        'refunded' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
    ][$status->value];
@endphp

<span {{ $attributes->class(['inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    {{ $status->label() }}
</span>
