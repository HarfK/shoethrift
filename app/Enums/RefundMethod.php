<?php

namespace App\Enums;

enum RefundMethod: string
{
    // POST /v1/refund on the original HitPay payment.
    case HitPay = 'hitpay';
    // Paid back outside the system (cash, bank transfer, or from the HitPay dashboard).
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::HitPay => 'Refunded through HitPay',
            self::Manual => 'Refunded manually',
        };
    }
}
