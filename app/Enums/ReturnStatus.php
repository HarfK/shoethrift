<?php

namespace App\Enums;

/**
 * requested → approved → refunded, or requested → rejected.
 */
enum ReturnStatus: string
{
    case Requested = 'requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Return requested',
            self::Approved => 'Return approved',
            self::Rejected => 'Return rejected',
            self::Refunded => 'Refunded',
        };
    }

    /**
     * Still needs an admin's attention.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Requested, self::Approved], true);
    }
}
