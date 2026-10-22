<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    // The online payment didn't go through. Set only by the system (Order::markPaymentFailed()).
    case Failed = 'failed';

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
            self::Completed, self::Cancelled, self::Failed => [],
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
