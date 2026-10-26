<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    // Cash on Delivery only: the parcel arrived, cash not yet collected.
    case Delivered = 'delivered';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    // The online payment didn't go through. Set only by the system (Order::markPaymentFailed()).
    case Failed = 'failed';
    // The whole paid amount was refunded through a return request (see App\Models\ReturnRequest).
    case Refunded = 'refunded';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Forward-only flow: pending → paid → shipped → completed. An order can be cancelled
     * only before it ships. Completed, cancelled and failed are final.
     *
     * Failed is never offered here: only the payment sync can set it.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid, self::Cancelled],
            self::Paid => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Completed],
            self::Delivered => [self::Completed],
            self::Completed, self::Cancelled, self::Failed, self::Refunded => [],
        };
    }

    public function canTransitionTo(self $status): bool
    {
        return in_array($status, $this->allowedTransitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedTransitions() === [];
    }
}
