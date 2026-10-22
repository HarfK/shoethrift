@props(['status'])
@use('App\Enums\OrderStatus')

@php
    $steps = [
        OrderStatus::Pending->value => 'Placed',
        OrderStatus::Paid->value => 'Paid',
        OrderStatus::Shipped->value => 'Shipped',
        OrderStatus::Completed->value => 'Completed',
    ];
    $reached = array_search($status->value, array_keys($steps), true);
@endphp

{{-- Customer-facing order tracking (proposal use case "View Order Tracking"). --}}
@if ($status === OrderStatus::Cancelled)
    <div {{ $attributes->class('rounded-xl border border-stone-200 bg-stone-100 px-4 py-3 text-sm text-stone-700') }} role="status">
        This order was cancelled. Any items were returned to the shop.
    </div>
@else
    <ol {{ $attributes->class('grid grid-cols-4 gap-2') }} aria-label="Order progress">
        @foreach ($steps as $label)
            @php($done = $loop->index <= $reached)
            <li class="flex flex-col gap-2" @if ($loop->index === $reached) aria-current="step" @endif>
                <span @class(['h-1.5 rounded-full', 'bg-emerald-600' => $done, 'bg-stone-200' => ! $done])></span>
                <span @class(['text-xs sm:text-sm', 'font-semibold text-stone-900' => $done, 'text-stone-500' => ! $done])>{{ $label }}</span>
            </li>
        @endforeach
    </ol>
@endif
