<?php

namespace App\Enums;

enum ContactTopic: string
{
    case Order = 'order';
    case Return = 'return';
    case Payment = 'payment';
    case Product = 'product';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'My order',
            self::Return => 'Return & refund',
            self::Payment => 'Payment',
            self::Product => 'A product',
            self::Other => 'Something else',
        };
    }
}
