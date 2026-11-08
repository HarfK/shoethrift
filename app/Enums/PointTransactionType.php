<?php

namespace App\Enums;

enum PointTransactionType: string
{
    case Earn = 'earn';
    case Redeem = 'redeem';
    case ReverseEarn = 'reverse_earn';
    case ReturnRedeem = 'return_redeem';
    case Expire = 'expire';
    case Adjust = 'adjust';

    public function label(): string
    {
        return match ($this) {
            self::Earn => 'Earned',
            self::Redeem => 'Used at checkout',
            self::ReverseEarn => 'Taken back (refund)',
            self::ReturnRedeem => 'Returned (order cancelled)',
            self::Expire => 'Expired',
            self::Adjust => 'Adjusted by ShoeThrift',
        };
    }
}
