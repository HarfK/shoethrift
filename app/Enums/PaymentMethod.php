<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Online = 'online';
    case CashOnDelivery = 'cod';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online payment (HitPay)',
            self::CashOnDelivery => 'Cash on Delivery',
        };
    }
}
