<?php

namespace App\Observers;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Services\Loyalty\PointsService;

/**
 * Loyalty points follow the order flow without changing it. Order statuses are saved inside the callers'
 * transactions (Order::transitionTo, markPaymentFailed, recordGatewayPayment, RecordRefund), so the points
 * change commits or rolls back together with the status:
 *
 *   -> Completed            earn points (once)
 *   -> Cancelled / Failed   return the points used on the order
 *   -> Refunded, or a partial refund on a completed order   take back earned points (in proportion)
 */
class OrderPointsObserver
{
    public function __construct(private readonly PointsService $points) {}

    public function updated(Order $order): void
    {
        if ($order->wasChanged('status')) {
            match ($order->status) {
                OrderStatus::Completed => $this->points->earnFor($order),
                OrderStatus::Cancelled, OrderStatus::Failed, OrderStatus::Refunded => $this->points->reverseFor($order),
                default => null,
            };

            return;
        }

        if ($order->wasChanged('refunded_amount') && (float) $order->refunded_amount > 0) {
            $this->points->reverseFor($order);
        }
    }
}
