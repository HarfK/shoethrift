@props(['status', 'paymentMethod' => null])
@use('App\Enums\OrderStatus')
@use('App\Enums\PaymentMethod')

@php
    // Cash on Delivery has no separate "paid" step: the cash is collected on delivery (completed).
    $steps = $paymentMethod === PaymentMethod::CashOnDelivery
        ? [OrderStatus::Pending->value => 'Placed', OrderStatus::Shipped->value => 'Shipped', OrderStatus::Completed->value => 'Delivered & paid']
        : [OrderStatus::Pending->value => 'Placed', OrderStatus::Paid->value => 'Paid', OrderStatus::Shipped->value => 'Shipped', OrderStatus::Completed->value => 'Completed'];
    $reached = array_search($status->value, array_keys($steps), true);
@endphp

{{-- Customer-facing order tracking (proposal use case "View Order Tracking"). --}}
@if ($status === OrderStatus::Cancelled || $status === OrderStatus::Failed)
    {{-- The status page explains Cancelled / Failed in its own banner; no progress bar for closed orders. --}}
@else
    <ol {{ $attributes->class(['grid gap-2', 'grid-cols-3' => count($steps) === 3, 'grid-cols-4' => count($steps) === 4]) }} aria-label="Order progress">
        @foreach ($steps as $label)
            @php($done = $reached !== false && $loop->index <= $reached)
            <li class="flex flex-col gap-2" @if ($loop->index === $reached) aria-current="step" @endif>
                <span @class(['h-1.5 rounded-full', 'bg-emerald-600' => $done, 'bg-stone-200' => ! $done])></span>
                <span @class(['text-xs sm:text-sm', 'font-semibold text-stone-900' => $done, 'text-stone-500' => ! $done])>{{ $label }}</span>
            </li>
        @endforeach
    </ol>
@endif
