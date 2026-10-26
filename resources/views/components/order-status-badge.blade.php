{{-- Pass :order to get order-aware labels ("Cash collected", "Failed delivery"); :status for a bare status. --}}
@props(['status' => null, 'order' => null])

@php
    $status = $order?->status ?? $status;
    $label = $order?->statusLabel() ?? $status->label();
    $tone = match (true) {
        (bool) $order?->isFailedDelivery() => 'failed',
        (bool) $order?->isPartiallyRefunded() => 'partially_refunded',
        default => $status->value,
    };

    $classes = [
        'pending' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'paid' => 'bg-sky-50 text-sky-800 ring-sky-600/20',
        'shipped' => 'bg-indigo-50 text-indigo-800 ring-indigo-600/20',
        'delivered' => 'bg-violet-50 text-violet-800 ring-violet-600/20',
        'completed' => 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
        'cancelled' => 'bg-stone-100 text-stone-600 ring-stone-500/20',
        'failed' => 'bg-red-50 text-red-800 ring-red-600/20',
        'refunded' => 'bg-stone-100 text-stone-700 ring-stone-500/20',
        'partially_refunded' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
    ][$tone];
@endphp

<span {{ $attributes->class(['inline-flex rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>
    {{ $label }}
</span>
